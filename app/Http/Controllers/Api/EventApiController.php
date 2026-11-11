<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Jobs\RefreshSitemapJob;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventTranslation;
use App\Models\Location;
use App\Models\LocationTranslation;
use App\Services\EventImageStorageService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        $updatedSince = $request->query('updated_since') ?: $request->query('since');
        $type = $request->query('type') ?: $request->query('entertainment_type');
        $price = $request->query('price');
        $isFree = $request->query('is_free');
        $sourceSlug = $request->query('source');
        $isFeatured = $request->query('is_featured');
        $isPromoted = $request->query('is_promoted');
        $sort = $request->query('sort', 'date_asc');
        $perPage = min(max((int) ($request->query('per_page') ?: $request->query('limit', 20)), 1), 250);

        $query = Event::with(['categories.translations', 'location.translations', 'source', 'translations'])
            ->published();

        // Updated since filter
        if (!empty($updatedSince)) {
            try {
                $sinceDate = is_numeric($updatedSince)
                    ? Carbon::createFromTimestamp((int) $updatedSince)
                    : Carbon::parse($updatedSince);
                $query->where('updated_at', '>=', $sinceDate);
            } catch (\Throwable $e) {
                // Ignore parse errors
            }
        }

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
        } elseif (empty($updatedSince)) {
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
            'updated_desc' => $query->reorder('is_promoted', 'desc')->orderBy('updated_at', 'desc')->orderBy('id', 'desc'),
            'updated_asc' => $query->reorder('updated_at', 'asc')->orderBy('id', 'asc'),
            'price_asc' => $query->reorder('is_promoted', 'desc')->orderBy('is_free', 'desc')->orderBy('price_min', 'asc')->orderBy('start_at', 'asc'),
            'price_desc' => $query->reorder('is_promoted', 'desc')->orderBy('price_max', 'desc')->orderBy('start_at', 'asc'),
            default => null, // Default ordering from forDateFilter/upcoming already places is_promoted DESC first
        };

        $events = $query->paginate($perPage);

        return EventResource::collection($events);
    }

    /**
     * Incremental synchronization endpoint for third-party platforms (e.g. piedzivo.lv).
     * Endpoint: GET /api/v1/events/sync
     *
     * Returns:
     * - sync_timestamp: Current server ISO 8601 timestamp (client stores this for next call)
     * - data: Array of created or updated events matching filters
     * - deleted_ids: Array of event IDs deleted or unpublished since "since"
     * - meta: Pagination and totals metadata
     */
    public function sync(Request $request): JsonResponse
    {
        $since = $request->query('since') ?: $request->query('updated_since');
        $city = $request->query('city');
        $categorySlug = $request->query('category');
        $perPage = min(max((int) ($request->query('per_page') ?: $request->query('limit', 100)), 1), 250);
        $page = max((int) $request->query('page', 1), 1);

        $sinceDate = null;
        if (!empty($since)) {
            try {
                $sinceDate = is_numeric($since)
                    ? Carbon::createFromTimestamp((int) $since)
                    : Carbon::parse($since);
            } catch (\Throwable $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid timestamp format for "since". Please use ISO 8601 (e.g. 2026-10-27T14:00:00Z) or a UNIX timestamp.',
                ], 422);
            }
        }

        // Determine upcoming_only
        $upcomingOnly = $request->has('upcoming_only')
            ? filter_var($request->query('upcoming_only'), FILTER_VALIDATE_BOOLEAN)
            : ($sinceDate === null); // Default true for initial sync, false for incremental sync

        // Base query for modified / created events
        $query = Event::with(['categories.translations', 'location.translations', 'source', 'translations'])
            ->published();

        if ($sinceDate !== null) {
            $query->where('updated_at', '>=', $sinceDate);
        }

        if ($upcomingOnly) {
            $todayStart = now()->startOfDay();
            $query->where(function ($q) use ($todayStart) {
                $q->where('start_at', '>=', $todayStart)
                  ->orWhere(function ($sub) {
                      $sub->whereNotNull('end_at')->where('end_at', '>=', now());
                  });
            });
        }

        if (!empty($city) && $city !== 'all') {
            $query->filterByCity($city);
        }

        if (!empty($categorySlug) && $categorySlug !== 'all') {
            if (is_numeric($categorySlug)) {
                $query->whereHas('categories', function ($q) use ($categorySlug) {
                    $q->where('categories.id', (int) $categorySlug);
                });
            } else {
                $query->filterByCategory($categorySlug);
            }
        }

        // Deterministic ordering by updated_at ASC, id ASC
        $query->orderBy('updated_at', 'asc')->orderBy('id', 'asc');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        // Gather deleted / unpublished event IDs if "since" timestamp is provided
        $deletedIds = [];
        if ($sinceDate !== null) {
            $trashedQuery = Event::onlyTrashed()->where('deleted_at', '>=', $sinceDate);
            if (!empty($city) && $city !== 'all') {
                $trashedQuery->filterByCity($city);
            }
            $trashedIds = $trashedQuery->pluck('id')->all();

            $unpublishedQuery = Event::withoutTrashed()
                ->where(function ($q) {
                    $q->where('status', '!=', 'published')
                      ->orWhereNull('published_at');
                })
                ->where('updated_at', '>=', $sinceDate);
            if (!empty($city) && $city !== 'all') {
                $unpublishedQuery->filterByCity($city);
            }
            $unpublishedIds = $unpublishedQuery->pluck('id')->all();

            $deletedIds = array_values(array_unique(array_merge($trashedIds, $unpublishedIds)));
        }

        $serverTime = now()->toIso8601String();

        return response()->json([
            'success' => true,
            'sync_timestamp' => $serverTime,
            'data' => EventResource::collection($paginator->items()),
            'deleted_ids' => $deletedIds,
            'meta' => [
                'total_updated' => $paginator->total(),
                'total_deleted' => count($deletedIds),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
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

    /**
     * Create and publish a new event via API.
     * Supports multipart/form-data for image file upload or image_url string.
     * Endpoint: POST /api/v1/events
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'description' => ['required', 'string', 'min:10'],
            'short_description' => ['nullable', 'string', 'max:1000'],
            'seo_description' => ['nullable', 'string', 'max:1000'],
            'start_at' => ['required', 'date'],
            'end_at' => ['nullable', 'date'],
            'all_day' => ['nullable', 'boolean'],
            'is_free' => ['nullable', 'boolean'],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'ticket_url' => ['nullable', 'url', 'max:2000'],
            'source_url' => ['nullable', 'url', 'max:2000'],
            'source_slug' => ['nullable', 'string', 'max:50'],
            'entertainment_type' => ['nullable', 'string', 'max:50'],
            'categories' => ['nullable', 'array'],
            'category_slugs' => ['nullable', 'array'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'city' => ['nullable', 'string', 'max:100'],
            'venue_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'image' => ['nullable', 'file', 'image', 'max:15360'],
            'image_url' => ['nullable', 'url', 'max:2000'],
            'translations' => ['nullable', 'array'],
            'translations.*.title' => ['nullable', 'string', 'max:255'],
            'translations.*.short_description' => ['nullable', 'string', 'max:1000'],
            'translations.*.description' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($request, $validated) {
            // 1. Resolve Location
            $locationId = null;
            $locationName = null;
            if (!empty($validated['location_id'])) {
                $loc = Location::find($validated['location_id']);
                if ($loc) {
                    $locationId = $loc->id;
                    $locationName = $loc->name;
                }
            } elseif (!empty($validated['venue_name']) && !empty($validated['city'])) {
                $vName = trim($validated['venue_name']);
                $vCity = trim($validated['city']);
                $vAddress = trim((string) ($validated['address'] ?? ''));
                $vLat = !empty($validated['latitude']) ? (float) $validated['latitude'] : null;
                $vLng = !empty($validated['longitude']) ? (float) $validated['longitude'] : null;

                $targetSlug = Str::slug($vName . '-' . $vCity);
                $loc = Location::where('slug', $targetSlug)
                    ->orWhere(function ($q) use ($vName, $vCity) {
                        $q->where('name', $vName)->where('city', $vCity);
                    })
                    ->orWhereHas('translations', function ($tq) use ($vName, $vCity) {
                        $tq->where('name', $vName)->where('city', $vCity);
                    })
                    ->first();

                if (!$loc) {
                    $loc = Location::create([
                        'name' => $vName,
                        'city' => $vCity,
                        'address' => $vAddress ?: null,
                        'region' => $vCity,
                        'latitude' => $vLat,
                        'longitude' => $vLng,
                        'place_type' => 'venue',
                    ]);

                    LocationTranslation::create([
                        'location_id' => $loc->id,
                        'locale' => 'lv',
                        'name' => $vName,
                        'city' => $vCity,
                        'address' => $vAddress ?: null,
                    ]);
                } else {
                    $locUpdates = [];
                    if (!empty($vAddress) && empty($loc->address)) {
                        $locUpdates['address'] = $vAddress;
                    }
                    if ($vLat !== null && empty($loc->latitude)) {
                        $locUpdates['latitude'] = $vLat;
                    }
                    if ($vLng !== null && empty($loc->longitude)) {
                        $locUpdates['longitude'] = $vLng;
                    }
                    if (!empty($locUpdates)) {
                        $loc->update($locUpdates);
                    }
                }

                $locationId = $loc->id;
                $locationName = $loc->name;
            }

            // 2. Resolve Image URL (Direct S3 upload with WebP optimization)
            $imageUrl = null;
            $s3Service = app(EventImageStorageService::class);
            if ($request->hasFile('image')) {
                $imageUrl = $s3Service->uploadFile($request->file('image'));
            } elseif (!empty($validated['image_url'])) {
                $rawUrl = trim($validated['image_url']);
                $s3Url = $s3Service->uploadLocalOrRemote($rawUrl);
                $imageUrl = $s3Url ?: $rawUrl;
            }

            // 3. Resolve Pricing & Dates
            $isFree = (bool) ($validated['is_free'] ?? false);
            $priceMin = $isFree ? 0 : (!empty($validated['price_min']) ? (float) $validated['price_min'] : null);
            $priceMax = $isFree ? 0 : (!empty($validated['price_max']) ? (float) $validated['price_max'] : null);

            $startAt = Carbon::parse($validated['start_at']);
            $endAt = !empty($validated['end_at']) ? Carbon::parse($validated['end_at']) : null;
            $allDay = (bool) ($validated['all_day'] ?? false);

            if ($allDay) {
                $startAt = $startAt->startOfDay();
                if ($endAt) {
                    $endAt = $endAt->endOfDay();
                }
            }

            $description = trim($validated['description']);
            $shortDescription = !empty($validated['short_description'])
                ? trim($validated['short_description'])
                : (!empty($validated['seo_description'])
                    ? trim($validated['seo_description'])
                    : Str::limit(strip_tags($description), 280));

            // 4. Resolve Categories
            $categoryIds = [];
            if (!empty($validated['categories'])) {
                foreach ((array) $validated['categories'] as $c) {
                    if (is_numeric($c)) {
                        $categoryIds[] = (int) $c;
                    } elseif (is_string($c)) {
                        $foundCat = Category::where('slug', $c)->first();
                        if ($foundCat) {
                            $categoryIds[] = $foundCat->id;
                        }
                    }
                }
            }
            if (!empty($validated['category_slugs'])) {
                foreach ((array) $validated['category_slugs'] as $slug) {
                    $foundCat = Category::where('slug', $slug)->first();
                    if ($foundCat) {
                        $categoryIds[] = $foundCat->id;
                    }
                }
            }
            $categoryIds = $this->applyDomainCategorizationRules($categoryIds, $validated['title'], $locationName);

            $sourceSlug = !empty($validated['source_slug']) ? trim($validated['source_slug']) : 'piedzivo';

            // 5. Create Event
            $event = Event::create([
                'location_id' => $locationId,
                'title' => trim($validated['title']),
                'description' => $description,
                'short_description' => $shortDescription,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'all_day' => $allDay,
                'is_free' => $isFree,
                'price_min' => $priceMin,
                'price_max' => $priceMax,
                'currency' => $validated['currency'] ?? 'EUR',
                'ticket_url' => $validated['ticket_url'] ?? null,
                'source_url' => $validated['source_url'] ?? null,
                'image_url' => $imageUrl,
                'internal_image_url' => $imageUrl,
                'entertainment_type' => $validated['entertainment_type'] ?? null,
                'status' => 'published',
                'published_at' => now(),
                'source_slug' => $sourceSlug,
            ]);

            // Sync categories
            $event->categories()->sync($categoryIds);

            // 6. Save Translations (LV primary, plus optional EN and RU)
            EventTranslation::updateOrCreate(
                ['event_id' => $event->id, 'locale' => 'lv'],
                [
                    'title' => $event->title,
                    'slug' => $event->slug,
                    'description' => $event->description,
                    'short_description' => $event->short_description,
                ]
            );

            if (!empty($validated['translations']['en'])) {
                $en = $validated['translations']['en'];
                $enTitle = trim((string) ($en['title'] ?? '')) ?: $event->title;
                EventTranslation::updateOrCreate(
                    ['event_id' => $event->id, 'locale' => 'en'],
                    [
                        'title' => $enTitle,
                        'slug' => Str::slug($enTitle) . '-' . Str::random(6),
                        'description' => trim((string) ($en['description'] ?? '')) ?: $event->description,
                        'short_description' => trim((string) ($en['short_description'] ?? '')) ?: $event->short_description,
                    ]
                );
            }

            if (!empty($validated['translations']['ru'])) {
                $ru = $validated['translations']['ru'];
                $ruTitle = trim((string) ($ru['title'] ?? '')) ?: $event->title;
                EventTranslation::updateOrCreate(
                    ['event_id' => $event->id, 'locale' => 'ru'],
                    [
                        'title' => $ruTitle,
                        'slug' => Str::slug($ruTitle) . '-' . Str::random(6),
                        'description' => trim((string) ($ru['description'] ?? '')) ?: $event->description,
                        'short_description' => trim((string) ($ru['short_description'] ?? '')) ?: $event->short_description,
                    ]
                );
            }

            Cache::forget('cat_counts_lv_all_none');
            Cache::forget('locations_distinct_cities');
            RefreshSitemapJob::dispatch();

            return response()->json([
                'success' => true,
                'message' => 'Event created successfully.',
                'event_id' => $event->id,
                'location_id' => $locationId,
                'image_url' => $event->display_image_url,
                'data' => new EventResource($event->fresh()),
            ], 201);
        });
    }

    /**
     * Update an existing event via API.
     * Supports multipart/form-data for updating image file or image_url.
     * Endpoint: PUT/PATCH /api/v1/events/{id}
     */
    public function update(Request $request, int|string $id): JsonResponse
    {
        $event = Event::where(function ($query) use ($id) {
            if (is_numeric($id)) {
                $query->where('id', (int) $id);
            } else {
                $query->where('slug', $id)
                    ->orWhereHas('translations', function ($tq) use ($id) {
                        $tq->where('slug', $id);
                    });
            }
        })->first();

        if (!$event) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found.',
            ], 404);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'min:3', 'max:255'],
            'description' => ['sometimes', 'required', 'string', 'min:10'],
            'short_description' => ['nullable', 'string', 'max:1000'],
            'seo_description' => ['nullable', 'string', 'max:1000'],
            'start_at' => ['sometimes', 'required', 'date'],
            'end_at' => ['nullable', 'date'],
            'all_day' => ['nullable', 'boolean'],
            'is_free' => ['nullable', 'boolean'],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'ticket_url' => ['nullable', 'url', 'max:2000'],
            'source_url' => ['nullable', 'url', 'max:2000'],
            'source_slug' => ['nullable', 'string', 'max:50'],
            'entertainment_type' => ['nullable', 'string', 'max:50'],
            'categories' => ['nullable', 'array'],
            'category_slugs' => ['nullable', 'array'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'city' => ['nullable', 'string', 'max:100'],
            'venue_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'image' => ['nullable', 'file', 'image', 'max:15360'],
            'image_url' => ['nullable', 'url', 'max:2000'],
            'translations' => ['nullable', 'array'],
            'translations.*.title' => ['nullable', 'string', 'max:255'],
            'translations.*.short_description' => ['nullable', 'string', 'max:1000'],
            'translations.*.description' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($request, $validated, $event) {
            // Location resolution
            $locationId = $event->location_id;
            $locationName = $event->location?->name;

            if (!empty($validated['location_id'])) {
                $loc = Location::find($validated['location_id']);
                if ($loc) {
                    $locationId = $loc->id;
                    $locationName = $loc->name;
                }
            } elseif (!empty($validated['venue_name']) && !empty($validated['city'])) {
                $vName = trim($validated['venue_name']);
                $vCity = trim($validated['city']);
                $vAddress = trim((string) ($validated['address'] ?? ''));
                $vLat = !empty($validated['latitude']) ? (float) $validated['latitude'] : null;
                $vLng = !empty($validated['longitude']) ? (float) $validated['longitude'] : null;

                $targetSlug = Str::slug($vName . '-' . $vCity);
                $loc = Location::where('slug', $targetSlug)
                    ->orWhere(function ($q) use ($vName, $vCity) {
                        $q->where('name', $vName)->where('city', $vCity);
                    })
                    ->orWhereHas('translations', function ($tq) use ($vName, $vCity) {
                        $tq->where('name', $vName)->where('city', $vCity);
                    })
                    ->first();

                if (!$loc) {
                    $loc = Location::create([
                        'name' => $vName,
                        'city' => $vCity,
                        'address' => $vAddress ?: null,
                        'region' => $vCity,
                        'latitude' => $vLat,
                        'longitude' => $vLng,
                        'place_type' => 'venue',
                    ]);
                }
                if ($vLat !== null && empty($loc->latitude)) {
                    $loc->update(['latitude' => $vLat, 'longitude' => $vLng]);
                }
                $locationId = $loc->id;
                $locationName = $loc->name;
            }

            // Image handling (Direct S3 upload with WebP optimization)
            $imageUrl = $event->image_url;
            $internalImageUrl = $event->internal_image_url;
            $s3Service = app(EventImageStorageService::class);

            if ($request->hasFile('image')) {
                $uploadedUrl = $s3Service->uploadFile($request->file('image'), $event);
                if ($uploadedUrl) {
                    $imageUrl = $uploadedUrl;
                    $internalImageUrl = $uploadedUrl;
                }
            } elseif (!empty($validated['image_url']) && $validated['image_url'] !== $event->image_url) {
                $rawUrl = trim($validated['image_url']);
                $s3Url = $s3Service->uploadLocalOrRemote($rawUrl);
                $imageUrl = $s3Url ?: $rawUrl;
                $internalImageUrl = $imageUrl;
            }

            // Updates array
            $updates = [
                'location_id' => $locationId,
                'image_url' => $imageUrl,
                'internal_image_url' => $internalImageUrl,
            ];

            if (isset($validated['title'])) {
                $updates['title'] = trim($validated['title']);
            }
            if (isset($validated['description'])) {
                $updates['description'] = trim($validated['description']);
                if (empty($validated['short_description'])) {
                    $updates['short_description'] = Str::limit(strip_tags($updates['description']), 280);
                }
            }
            if (isset($validated['short_description'])) {
                $updates['short_description'] = trim($validated['short_description']);
            } elseif (isset($validated['seo_description']) && empty($updates['short_description'])) {
                $updates['short_description'] = trim($validated['seo_description']);
            }
            if (isset($validated['start_at'])) {
                $startAt = Carbon::parse($validated['start_at']);
                $endAt = !empty($validated['end_at']) ? Carbon::parse($validated['end_at']) : $event->end_at;
                $allDay = isset($validated['all_day']) ? (bool) $validated['all_day'] : (bool) $event->all_day;

                if ($allDay) {
                    $startAt = $startAt->startOfDay();
                    if ($endAt) {
                        $endAt = $endAt->endOfDay();
                    }
                }
                $updates['start_at'] = $startAt;
                $updates['end_at'] = $endAt;
                $updates['all_day'] = $allDay;
            } elseif (isset($validated['end_at'])) {
                $updates['end_at'] = Carbon::parse($validated['end_at']);
            }

            if (isset($validated['is_free'])) {
                $updates['is_free'] = (bool) $validated['is_free'];
                if ($updates['is_free']) {
                    $updates['price_min'] = 0;
                    $updates['price_max'] = 0;
                }
            }
            if (isset($validated['price_min']) && empty($updates['is_free'])) {
                $updates['price_min'] = (float) $validated['price_min'];
            }
            if (isset($validated['price_max']) && empty($updates['is_free'])) {
                $updates['price_max'] = (float) $validated['price_max'];
            }
            if (isset($validated['currency'])) {
                $updates['currency'] = $validated['currency'];
            }
            if (array_key_exists('ticket_url', $validated)) {
                $updates['ticket_url'] = $validated['ticket_url'];
            }
            if (array_key_exists('source_url', $validated)) {
                $updates['source_url'] = $validated['source_url'];
            }
            if (isset($validated['entertainment_type'])) {
                $updates['entertainment_type'] = $validated['entertainment_type'];
            }
            if (!empty($validated['source_slug'])) {
                $updates['source_slug'] = trim($validated['source_slug']);
            }

            $event->update($updates);

            // Categories
            if (isset($validated['categories']) || isset($validated['category_slugs'])) {
                $categoryIds = [];
                if (!empty($validated['categories'])) {
                    foreach ((array) $validated['categories'] as $c) {
                        if (is_numeric($c)) {
                            $categoryIds[] = (int) $c;
                        } elseif (is_string($c)) {
                            $foundCat = Category::where('slug', $c)->first();
                            if ($foundCat) $categoryIds[] = $foundCat->id;
                        }
                    }
                }
                if (!empty($validated['category_slugs'])) {
                    foreach ((array) $validated['category_slugs'] as $slug) {
                        $foundCat = Category::where('slug', $slug)->first();
                        if ($foundCat) $categoryIds[] = $foundCat->id;
                    }
                }
                $categoryIds = $this->applyDomainCategorizationRules($categoryIds, $event->title, $locationName);
                $event->categories()->sync($categoryIds);
            }

            // Translations
            EventTranslation::updateOrCreate(
                ['event_id' => $event->id, 'locale' => 'lv'],
                [
                    'title' => $event->title,
                    'slug' => $event->slug,
                    'description' => $event->description,
                    'short_description' => $event->short_description,
                ]
            );

            if (isset($validated['translations']['en'])) {
                $en = $validated['translations']['en'];
                $enTitle = trim((string) ($en['title'] ?? '')) ?: $event->title;
                EventTranslation::updateOrCreate(
                    ['event_id' => $event->id, 'locale' => 'en'],
                    [
                        'title' => $enTitle,
                        'slug' => Str::slug($enTitle) . '-' . Str::random(6),
                        'description' => trim((string) ($en['description'] ?? '')) ?: $event->description,
                        'short_description' => trim((string) ($en['short_description'] ?? '')) ?: $event->short_description,
                    ]
                );
            }

            if (isset($validated['translations']['ru'])) {
                $ru = $validated['translations']['ru'];
                $ruTitle = trim((string) ($ru['title'] ?? '')) ?: $event->title;
                EventTranslation::updateOrCreate(
                    ['event_id' => $event->id, 'locale' => 'ru'],
                    [
                        'title' => $ruTitle,
                        'slug' => Str::slug($ruTitle) . '-' . Str::random(6),
                        'description' => trim((string) ($ru['description'] ?? '')) ?: $event->description,
                        'short_description' => trim((string) ($ru['short_description'] ?? '')) ?: $event->short_description,
                    ]
                );
            }

            Cache::forget('cat_counts_lv_all_none');
            RefreshSitemapJob::dispatch();

            return response()->json([
                'success' => true,
                'message' => 'Event updated successfully.',
                'event_id' => $event->id,
                'location_id' => $locationId,
                'image_url' => $event->fresh()->display_image_url,
                'data' => new EventResource($event->fresh()),
            ]);
        });
    }

    /**
     * Delete an event via API (SoftDelete).
     * Endpoint: DELETE /api/v1/events/{id}
     */
    public function destroy(Request $request, int|string $id): JsonResponse
    {
        $event = Event::where(function ($query) use ($id) {
            if (is_numeric($id)) {
                $query->where('id', (int) $id);
            } else {
                $query->where('slug', $id)
                    ->orWhereHas('translations', function ($tq) use ($id) {
                        $tq->where('slug', $id);
                    });
            }
        })->first();

        if (!$event) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found.',
            ], 404);
        }

        $deletedId = $event->id;
        $event->delete();
        RefreshSitemapJob::dispatch();

        return response()->json([
            'success' => true,
            'message' => 'Event successfully deleted.',
            'deleted_id' => $deletedId,
        ]);
    }

    /**
     * Apply strict cinema / theatre domain rules per AGENTS.md.
     */
    protected function applyDomainCategorizationRules(array $categoryIds, string $title, ?string $venueName): array
    {
        $kinoCategory = Category::where('slug', 'kino')->first();
        $teatrisCategory = Category::where('slug', 'teatris')->first();
        $citiCategory = Category::where('slug', 'citi')->first();

        $text = mb_strtolower($title . ' ' . ($venueName ?? ''), 'UTF-8');

        $isCinemaVenue = false;
        $cinemaVenues = [
            'k. suns', 'k.suns', 'k suns', 'kino rio', 'forum cinemas',
            'apollo kino', 'cinamon', 'splendid palace', 'kino bize',
            'kino lora', 'kino gaisma',
        ];
        foreach ($cinemaVenues as $v) {
            if (str_contains($text, $v)) {
                $isCinemaVenue = true;
                break;
            }
        }

        $isCinemaContent = (bool) preg_match('/(film[a-zūāēī]*|kinoseans[a-z]*|kinofestiv[a-z]*|spēlfilm[a-z]*|animācij[a-z]* film[a-z]*|kinoteātr[a-z]*)/ui', $text);

        if (($isCinemaVenue || $isCinemaContent) && $kinoCategory) {
            if (!in_array($kinoCategory->id, $categoryIds, true)) {
                $categoryIds[] = $kinoCategory->id;
            }
            if ($teatrisCategory) {
                $categoryIds = array_values(array_filter($categoryIds, fn ($id) => (int) $id !== (int) $teatrisCategory->id));
            }
        }

        if (empty($categoryIds) && $citiCategory) {
            $categoryIds[] = $citiCategory->id;
        }

        return array_values(array_unique(array_map('intval', $categoryIds)));
    }
}
