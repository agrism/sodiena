<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EventApiController extends Controller
{
    /**
     * Get a paginated list of events filtered by various parameters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = $request->query('search') ?: $request->query('q');
        $categorySlug = $request->query('category');
        $city = $request->query('city');
        $period = $request->query('period', 'all');
        $date = $request->query('date');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $type = $request->query('type') ?: $request->query('entertainment_type');
        $price = $request->query('price');
        $isFree = $request->query('is_free');
        $sourceSlug = $request->query('source');
        $isFeatured = $request->query('is_featured');
        $isPromoted = $request->query('is_promoted');
        $sort = $request->query('sort', 'date_asc');
        $perPage = min(max((int) ($request->query('per_page') ?: $request->query('limit', 20)), 1), 100);

        $query = Event::with(['categories.translations', 'location.translations', 'source', 'translations'])
            ->published();

        // Custom date range filtering if provided
        if (!empty($dateFrom) || !empty($dateTo)) {
            $query->where(function ($q) use ($dateFrom, $dateTo) {
                if (!empty($dateFrom)) {
                    $from = Carbon::parse($dateFrom)->startOfDay();
                    $q->where(function ($sub) use ($from) {
                        $sub->where('start_at', '>=', $from)
                            ->orWhere('end_at', '>=', $from);
                    });
                }
                if (!empty($dateTo)) {
                    $to = Carbon::parse($dateTo)->endOfDay();
                    $q->where('start_at', '<=', $to);
                }
            });
        } else {
            $query->forDateFilter($period, $date);
        }

        // Category filter (slug or ID)
        if (!empty($categorySlug) && $categorySlug !== 'all') {
            if (is_numeric($categorySlug)) {
                $query->whereHas('categories', function ($q) use ($categorySlug) {
                    $q->where('categories.id', (int) $categorySlug);
                });
            } else {
                $query->filterByCategory($categorySlug);
            }
        }

        // City filter
        if (!empty($city) && $city !== 'all') {
            $query->filterByCity($city);
        }

        // Entertainment type filter
        if (!empty($type) && $type !== 'all') {
            $query->filterByEntertainmentType($type);
        }

        // Price filter
        if ($isFree !== null && $isFree !== '') {
            $query->where('is_free', filter_var($isFree, FILTER_VALIDATE_BOOLEAN));
        } elseif (!empty($price) && $price !== 'all') {
            $query->filterByPrice($price);
        }

        // Source filter
        if (!empty($sourceSlug) && $sourceSlug !== 'all') {
            $query->filterBySource($sourceSlug);
        }

        // Featured filter
        if ($isFeatured !== null && $isFeatured !== '') {
            $query->where('is_featured', filter_var($isFeatured, FILTER_VALIDATE_BOOLEAN));
        }

        // Promoted filter
        if ($isPromoted !== null && $isPromoted !== '') {
            $query->where('is_promoted', filter_var($isPromoted, FILTER_VALIDATE_BOOLEAN));
        }

        // Search filter (keyword in title, description, categories, location)
        if (!empty($search)) {
            $query->search($search);
        }

        // Sorting options - promoted events remain at the top
        match ($sort) {
            'date_desc' => $query->reorder('is_promoted', 'desc')->orderBy('start_at', 'desc')->orderBy('id', 'desc'),
            'views_desc', 'popular' => $query->reorder('is_promoted', 'desc')->orderBy('views_count', 'desc')->orderBy('start_at', 'asc'),
            'newest', 'created_desc' => $query->reorder('is_promoted', 'desc')->orderBy('created_at', 'desc'),
            'price_asc' => $query->reorder('is_promoted', 'desc')->orderBy('is_free', 'desc')->orderBy('price_min', 'asc')->orderBy('start_at', 'asc'),
            'price_desc' => $query->reorder('is_promoted', 'desc')->orderBy('price_max', 'desc')->orderBy('start_at', 'asc'),
            default => null, // Default ordering from forDateFilter/upcoming already places is_promoted DESC first
        };

        $events = $query->paginate($perPage);

        return EventResource::collection($events);
    }

    /**
     * Get a single event details by its ID or slug.
     */
    public function show(Request $request, int|string $id): EventResource|JsonResponse
    {
        $event = Event::with(['categories.translations', 'location.translations', 'source', 'translations'])
            ->published()
            ->where(function ($query) use ($id) {
                if (is_numeric($id)) {
                    $query->where('id', (int) $id);
                } else {
                    $query->where('slug', $id)
                        ->orWhereHas('translations', function ($tq) use ($id) {
                            $tq->where('slug', $id);
                        });
                }
            })
            ->first();

        if (!$event) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found or not published.',
            ], 404);
        }

        return new EventResource($event);
    }

    /**
     * Get 7 promoted events for homepage / featured spotlights.
     * Prioritizes promoted and featured events and fills remainder with top upcoming popular events.
     */
    public function promoted(Request $request): AnonymousResourceCollection
    {
        $limit = min(max((int) $request->query('limit', 7), 1), 20);

        // 1. Fetch explicitly marked promoted or featured upcoming events
        $promotedEvents = Event::with(['categories.translations', 'location.translations', 'source', 'translations'])
            ->upcoming()
            ->where(function ($q) {
                $q->where('is_promoted', true)
                  ->orWhere('is_featured', true);
            })
            ->take($limit)
            ->get();

        $promotedCount = $promotedEvents->count();
        $promotedIds = $promotedEvents->pluck('id')->all();

        // 2. If fewer than requested limit, fill the rest with top upcoming popular events
        if ($promotedCount < $limit) {
            $needed = $limit - $promotedCount;

            $popularUpcoming = Event::with(['categories.translations', 'location.translations', 'source', 'translations'])
                ->upcoming()
                ->when(!empty($promotedIds), fn ($q) => $q->whereNotIn('id', $promotedIds))
                ->reorder('views_count', 'desc')
                ->orderBy('start_at', 'asc')
                ->take($needed)
                ->get();

            $allPromoted = $promotedEvents->concat($popularUpcoming);
        } else {
            $allPromoted = $promotedEvents;
        }

        return EventResource::collection($allPromoted);
    }

    /**
     * Set the promoted status of an event by its ID or slug.
     * Endpoint: POST/PATCH /api/v1/events/{id}/promote
     * Body (optional): {"is_promoted": true/false} (default: true)
     */
    public function setPromoted(Request $request, int|string $id): JsonResponse
    {
        $event = Event::with(['categories.translations', 'location.translations', 'source', 'translations'])
            ->where(function ($query) use ($id) {
                if (is_numeric($id)) {
                    $query->where('id', (int) $id);
                } else {
                    $query->where('slug', $id)
                        ->orWhereHas('translations', function ($tq) use ($id) {
                            $tq->where('slug', $id);
                        });
                }
            })
            ->first();

        if (!$event) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found.',
            ], 404);
        }

        $isPromoted = $request->has('is_promoted')
            ? filter_var($request->input('is_promoted'), FILTER_VALIDATE_BOOLEAN)
            : true;

        $event->update([
            'is_promoted' => $isPromoted,
        ]);

        return response()->json([
            'success' => true,
            'message' => $isPromoted ? 'Event marked as promoted.' : 'Event promotion removed.',
            'is_promoted' => $isPromoted,
            'data' => new EventResource($event->fresh()),
        ]);
    }

    /**
     * Remove the promoted status of an event by its ID or slug.
     * Endpoint: POST/PATCH /api/v1/events/{id}/unpromote
     */
    public function unpromote(Request $request, int|string $id): JsonResponse
    {
        $request->merge(['is_promoted' => false]);
        return $this->setPromoted($request, $id);
    }
}
