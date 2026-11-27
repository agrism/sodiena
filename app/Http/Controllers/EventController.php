<?php

namespace App\Http\Controllers;

use App\Enums\SourceType;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRedirect;
use App\Models\Location;
use App\Models\ScrapeLog;
use App\Models\Source;
use App\Services\Scrapers\EventIngestionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EventController extends Controller
{
    /**
     * Display a listing of the events with rich HTMX filtering.
     */
    public function index(Request $request): View
    {
        $search = $request->query('search');
        $categorySlug = $request->query('category');
        $city = $request->query('city');
        $period = $request->query('period', 'all');
        $date = $request->query('date');
        $type = $request->query('type');
        $price = $request->query('price');
        $sourceSlug = $request->query('source');

        $query = Event::with(['categories.translations', 'location.translations', 'source', 'translations'])
            ->forDateFilter($period, $date)
            ->filterByCategory($categorySlug)
            ->filterByCity($city)
            ->filterByEntertainmentType($type)
            ->filterByPrice($price)
            ->filterBySource($sourceSlug)
            ->search($search);

        $events = $query->paginate(16)->withQueryString();

        // Cache category counts and total count based on active date filter and locale for 5 minutes (300s)
        $cacheKey = 'cat_counts_' . app()->getLocale() . '_' . md5(($period ?: 'all') . '_' . ($date ?: 'none'));
        $categoryData = Cache::remember($cacheKey, 300, function () use ($period, $date) {
            $categories = Category::with(['translations'])
                ->withCount(['events' => function ($q) use ($period, $date) {
                    $q->forDateFilter($period, $date);
                }])
                ->orderBy('order')
                ->get();

            $totalUpcoming = Event::query()->forDateFilter($period, $date)->count();

            return compact('categories', 'totalUpcoming');
        });

        $categories = $categoryData['categories'];
        $totalUpcoming = $categoryData['totalUpcoming'];

        // If this is an HTMX partial request, return only the list fragment (with OOB category updates)
        if ($request->header('HX-Request')) {
            return view('events.partials.events-list', compact(
                'events',
                'categories',
                'totalUpcoming',
                'categorySlug',
                'period',
                'date'
            ));
        }

        $cities = Location::select('city')
            ->distinct()
            ->whereNotNull('city')
            ->orderBy('city')
            ->pluck('city');

        $sources = Source::where('is_active', true)->withCount('events')->get();

        $featuredEvents = Event::with(['categories.translations', 'location.translations', 'translations'])
            ->upcoming()
            ->take(3)
            ->get();

        return view('events.index', compact(
            'events',
            'categories',
            'cities',
            'sources',
            'featuredEvents',
            'totalUpcoming',
            'search',
            'categorySlug',
            'city',
            'period',
            'date',
            'type',
            'price',
            'sourceSlug'
        ));
    }

    /**
     * Display the specified event.
     */
    public function show(Request $request, ?string $slug = null)
    {
        $slug = $request->route('slug') ?? $slug;
        $locale = app()->getLocale();

        // 1. Look for Event by slug in events or event_translations (including soft-deleted)
        $event = Event::withTrashed()
            ->with(['categories.translations', 'location.translations', 'source', 'translations'])
            ->where(function ($q) use ($slug) {
                $q->where('slug', $slug)
                  ->orWhereHas('translations', function ($tq) use ($slug) {
                      $tq->where('slug', $slug);
                  });
            })
            ->first();

        // 2. If Event is found
        if ($event) {
            // A. If status is 'merged': follow chain
            if ($event->status === 'merged') {
                $target = $event;
                $hops = 0;
                while ($target->merged_into_id && $hops < 5) {
                    $parent = Event::withTrashed()->find($target->merged_into_id);
                    if (!$parent || $parent->id === $target->id) {
                        break;
                    }
                    $target = $parent;
                    $hops++;
                }

                if ($target && $target->id !== $event->id && $target->status !== 'deleted' && !$target->trashed()) {
                    $targetUrl = \App\Services\LocaleService::url($locale, route('events.show', $target->slug));
                    if ($request->getQueryString()) {
                        $targetUrl .= (str_contains($targetUrl, '?') ? '&' : '?') . $request->getQueryString();
                    }
                    return redirect()->to($targetUrl, 301);
                }

                return response()->view('errors.410', [], 410);
            }

            // B. If status is 'deleted' or trashed -> 410 Gone
            if ($event->status === 'deleted' || $event->trashed()) {
                return response()->view('errors.410', [], 410);
            }

            // Non-admins can only see published, cancelled or archived events (drafts are 404 for guests)
            if (!auth()->check() || !auth()->user()->isAdmin()) {
                if ($event->status === 'draft') {
                    abort(404);
                }
            }
        } else {
            // 3. Event not found by slug -> check EventRedirect
            $redirect = EventRedirect::where('locale', $locale)->where('old_slug', $slug)->first()
                ?? EventRedirect::where('locale', 'lv')->where('old_slug', $slug)->first();

            if ($redirect) {
                $target = Event::withTrashed()->find($redirect->target_event_id);
                if ($target && $target->status !== 'deleted' && !$target->trashed()) {
                    $targetUrl = \App\Services\LocaleService::url($locale, route('events.show', $target->slug));
                    if ($request->getQueryString()) {
                        $targetUrl .= (str_contains($targetUrl, '?') ? '&' : '?') . $request->getQueryString();
                    }
                    return redirect()->to($targetUrl, 301);
                }
                return response()->view('errors.410', [], 410);
            }

            abort(404);
        }

        // Active occurrences for v2 view
        $activeOccurrences = $event->occurrences()
            ->whereIn('status', ['scheduled', 'postponed'])
            ->where(function ($q) {
                $q->where('start_at', '>=', now()->subHours(2))
                  ->orWhere('end_at', '>=', now());
            })
            ->orderBy('start_at')
            ->get();

        $event->increment('views_count');

        $relatedEvents = Event::with(['categories.translations', 'location.translations', 'translations'])
            ->upcoming()
            ->where('id', '!=', $event->id)
            ->where(function ($q) use ($event) {
                if ($event->location_id) {
                    $q->where('location_id', $event->location_id);
                }
                if ($event->categories->isNotEmpty()) {
                    $q->orWhereHas('categories', function ($catQ) use ($event) {
                        $catQ->whereIn('categories.id', $event->categories->pluck('id'));
                    });
                }
            })
            ->take(3)
            ->get();

        $allCategories = Category::orderBy('name')->get();

        $isCancelled = $event->status === 'cancelled';

        return view('events.show', compact('event', 'relatedEvents', 'allCategories', 'activeOccurrences', 'isCancelled'));
    }

    /**
     * Source Scraper Status Dashboard & Logs by Day (Delegated to Admin\SourceController)
     */
    public function sources(Request $request): View
    {
        return app(\App\Http\Controllers\Admin\SourceController::class)->index($request);
    }

    /**
     * Trigger immediate scraper execution via HTMX or standard POST (Delegated to Admin\SourceController)
     */
    public function triggerScrape(Source $source, EventIngestionService $service, Request $request)
    {
        return app(\App\Http\Controllers\Admin\SourceController::class)->scrape($source, $service, $request);
    }
}
