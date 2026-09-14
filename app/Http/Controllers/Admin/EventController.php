<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Event;
use App\Models\Location;
use App\Models\Source;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EventController extends Controller
{
    /**
     * Display a spreadsheet-like data grid of all events for administrative review.
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->input('search', ''));
        $sourceSlug = $request->input('source', 'all');
        $originHost = trim((string) $request->input('origin_host', 'all'));
        $categorySlug = $request->input('category', 'all');
        $city = $request->input('city', 'all');
        $locationId = $request->input('location_id', 'all');
        $published = $request->input('published', 'all');
        $timeframe = $request->input('timeframe', 'upcoming');
        $sortBy = $request->input('sort_by', 'start_at');
        $sortDir = strtolower($request->input('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $perPage = (int) $request->input('per_page', 50);

        if (!in_array($perPage, [25, 50, 100, 200, 300, 400, 500, 600, 700, 1000], true)) {
            $perPage = 50;
        }

        $query = Event::query()->with(['location', 'categories', 'source']);

        // Search
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%")
                  ->orWhere('source_external_id', 'like', "%{$search}%")
                  ->orWhere('source_url', 'like', "%{$search}%")
                  ->orWhere('ticket_url', 'like', "%{$search}%")
                  ->orWhereHas('location', function ($locQ) use ($search) {
                      $locQ->where('name', 'like', "%{$search}%")
                           ->orWhere('city', 'like', "%{$search}%");
                  });
            });
        }

        // Filter by Publication Status
        $now = now();
        if ($published === 'published') {
            $query->whereNotNull('published_at')->where('published_at', '<=', $now)->where('status', 'published');
        } elseif ($published === 'unpublished') {
            $query->where(function ($q) use ($now) {
                $q->whereNull('published_at')
                  ->orWhere('published_at', '>', $now)
                  ->orWhere('status', '!=', 'published');
            });
        }

        // Filter by Technical Robot / Scraper Source
        if (!empty($sourceSlug) && $sourceSlug !== 'all') {
            $query->where('source_slug', $sourceSlug);
        }

        // Filter by Real Origin Website / Domain
        if ($originHost === 'missing' || $originHost === 'none') {
            $query->whereNull('source_url')->whereNull('ticket_url');
        } elseif (!empty($originHost) && $originHost !== 'all') {
            $query->where(function ($q) use ($originHost) {
                $q->where('source_url', 'like', "%{$originHost}%")
                  ->orWhere('ticket_url', 'like', "%{$originHost}%");
            });
        }

        // Filter by Category
        if (!empty($categorySlug) && $categorySlug !== 'all') {
            $query->whereHas('categories', function ($catQ) use ($categorySlug) {
                $catQ->where('slug', $categorySlug);
            });
        }

        // Filter by City
        if (!empty($city) && $city !== 'all') {
            $query->whereHas('location', function ($locQ) use ($city) {
                $locQ->where('city', $city);
            });
        }

        // Filter by Location / Vieta
        if ($locationId === 'missing' || $locationId === 'none') {
            $query->whereNull('location_id');
        } elseif (!empty($locationId) && $locationId !== 'all') {
            $query->where('location_id', $locationId);
        }

        // Filter by Timeframe
        match ($timeframe) {
            'all' => null, // No time restriction
            'upcoming' => $query->where(function ($q) use ($now) {
                $q->where('start_at', '>=', $now)
                  ->orWhere(function ($sub) use ($now) {
                      $sub->whereNotNull('end_at')->where('end_at', '>=', $now);
                  });
            }),
            'today' => $query->whereDate('start_at', '<=', $now->toDateString())
                             ->where(function ($q) use ($now) {
                                 $q->whereDate('end_at', '>=', $now->toDateString())
                                   ->orWhereNull('end_at');
                             }),
            'this_week' => $query->whereBetween('start_at', [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()]),
            'this_month' => $query->whereBetween('start_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()]),
            'past' => $query->where(function ($q) use ($now) {
                $q->where(function ($sub) use ($now) {
                    $sub->whereNotNull('end_at')->where('end_at', '<', $now);
                })->orWhere(function ($sub2) use ($now) {
                    $sub2->whereNull('end_at')->where('start_at', '<', $now);
                });
            }),
            default => null,
        };

        // Sorting
        $allowedSorts = ['id', 'start_at', 'title', 'price_min', 'views_count', 'created_at', 'published_at', 'location', 'venue', 'city'];
        if ($sortBy === 'location' || $sortBy === 'venue') {
            $query->leftJoin('locations', 'events.location_id', '=', 'locations.id')
                  ->select('events.*')
                  ->orderBy('locations.name', $sortDir)
                  ->orderBy('locations.city', $sortDir)
                  ->orderBy('events.start_at', 'asc');
        } elseif ($sortBy === 'city') {
            $query->leftJoin('locations', 'events.location_id', '=', 'locations.id')
                  ->select('events.*')
                  ->orderBy('locations.city', $sortDir)
                  ->orderBy('locations.name', $sortDir)
                  ->orderBy('events.start_at', 'asc');
        } elseif (in_array($sortBy, $allowedSorts, true)) {
            $query->orderBy('events.' . $sortBy, $sortDir);
        } else {
            $query->orderBy('events.start_at', 'asc');
        }

        $events = $query->paginate($perPage)->withQueryString();

        // Data for filter options
        $sources = Source::all();
        $categories = Category::orderBy('name')->get();
        $cities = Location::whereNotNull('city')
            ->select('city')
            ->distinct()
            ->orderBy('city')
            ->pluck('city');

        // Extract list of distinct locations with event counts
        $locations = \Illuminate\Support\Facades\Cache::remember('admin_locations_list_' . ($city !== 'all' ? md5($city) : 'all'), 60, function () use ($city) {
            $locQuery = Location::has('events')->withCount('events');
            if (!empty($city) && $city !== 'all') {
                $locQuery->where('city', $city);
            }
            return $locQuery->orderBy('name')->get(['id', 'name', 'city']);
        });

        $missingLocationCount = Event::whereNull('location_id')->count();

        // Extract list of distinct origin websites with counts
        $originHosts = \Illuminate\Support\Facades\Cache::remember('admin_origin_hosts_list', 120, function () {
            return Event::whereNotNull('source_url')
                ->orWhereNotNull('ticket_url')
                ->get(['source_url', 'ticket_url'])
                ->flatMap(fn ($e) => [$e->source_url, $e->ticket_url])
                ->filter()
                ->map(function ($url) {
                    $host = parse_url($url, PHP_URL_HOST);
                    return $host ? preg_replace('/^www\./i', '', strtolower($host)) : null;
                })
                ->filter()
                ->countBy()
                ->sortDesc();
        });

        $missingOriginCount = Event::whereNull('source_url')->whereNull('ticket_url')->count();

        $stats = [
            'total' => Event::count(),
            'published' => Event::published()->count(),
            'unpublished' => Event::where(function ($q) use ($now) {
                $q->whereNull('published_at')->orWhere('published_at', '>', $now)->orWhere('status', '!=', 'published');
            })->count(),
            'upcoming' => Event::upcoming()->count(),
            'today' => Event::forDateFilter('today')->count(),
            'filtered' => $events->total(),
        ];

        return view('admin.events.index', compact(
            'events',
            'sources',
            'originHosts',
            'missingOriginCount',
            'categories',
            'cities',
            'locations',
            'locationId',
            'missingLocationCount',
            'stats',
            'search',
            'sourceSlug',
            'originHost',
            'categorySlug',
            'city',
            'published',
            'timeframe',
            'sortBy',
            'sortDir',
            'perPage'
        ));
    }

    /**
     * Toggle event publication status (admin click / checkbox)
     */
    public function togglePublish(Event $event, Request $request)
    {
        if ($event->isPublished()) {
            $event->unpublish();
            $isPublished = false;
        } else {
            $event->publish();
            $isPublished = true;
        }

        if ($request->wantsJson() || $request->header('HX-Request') || $request->ajax()) {
            return response()->json([
                'success' => true,
                'id' => $event->id,
                'is_published' => $isPublished,
                'published_at' => $event->published_at ? $event->published_at->format('d.m.Y H:i') : null,
                'message' => $isPublished ? 'Pasākums nopublicēts!' : 'Pasākums noņemts no publikācijas (melnraksts).',
            ]);
        }

        return redirect()->back()->with('status', $isPublished ? 'Pasākums nopublicēts!' : 'Pasākums noņemts no publikācijas.');
    }

    /**
     * Update event category and entertainment type from admin view
     */
    public function updateCategory(Event $event, Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'nullable|exists:categories,id',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'exists:categories,id',
            'entertainment_type' => 'nullable|string|max:50',
        ]);

        if ($request->has('category_id') && !empty($validated['category_id'])) {
            $event->categories()->sync([$validated['category_id']]);
        } elseif ($request->has('category_ids')) {
            $event->categories()->sync($validated['category_ids'] ?? []);
        }

        if ($request->has('entertainment_type')) {
            $event->update([
                'entertainment_type' => !empty($validated['entertainment_type']) ? $validated['entertainment_type'] : null,
            ]);
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Kategorija un izklaides veids veiksmīgi atjaunināti!',
                'categories' => $event->fresh()->categories->pluck('name'),
                'entertainment_type' => $event->fresh()->localized_entertainment_type,
            ]);
        }

        return redirect()->back()->with('status', 'Kategorija un izklaides veids veiksmīgi atjaunināti!');
    }

    /**
     * Bulk publish or unpublish selected events
     */
    public function bulkPublish(Request $request)
    {
        $action = $request->input('action', 'publish');
        $eventIds = $request->input('event_ids', []);

        if (empty($eventIds) || !is_array($eventIds)) {
            return redirect()->back()->with('error', 'Lūdzu, izvēlieties vismaz vienu pasākumu.');
        }

        if ($action === 'publish') {
            Event::whereIn('id', $eventIds)->update([
                'published_at' => now(),
                'status' => 'published',
            ]);
            $msg = count($eventIds) . ' pasākumi veiksmīgi nopublicēti!';
        } else {
            Event::whereIn('id', $eventIds)->update([
                'published_at' => null,
                'status' => 'draft',
            ]);
            $msg = count($eventIds) . ' pasākumi noņemti no publikācijas!';
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => $msg]);
        }

        return redirect()->back()->with('status', $msg);
    }
}
