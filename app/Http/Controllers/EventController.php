<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Event;
use App\Models\Location;
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
    public function show(string $slug): View
    {
        $query = Event::with(['categories.translations', 'location.translations', 'source', 'translations'])
            ->where(function ($q) use ($slug) {
                $q->where('slug', $slug)
                  ->orWhereHas('translations', function ($tq) use ($slug) {
                      $tq->where('slug', $slug);
                  });
            });

        // Non-admins can only see published events
        if (!auth()->check() || !auth()->user()->isAdmin()) {
            $query->published();
        }

        $event = $query->firstOrFail();

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

        return view('events.show', compact('event', 'relatedEvents', 'allCategories'));
    }

    /**
     * Source Scraper Status Dashboard
     */
    public function sources(): View
    {
        $sources = Source::with(['scrapeLogs' => function ($q) {
            $q->take(5);
        }])->withCount('events')->get();

        return view('events.sources', compact('sources'));
    }

    /**
     * Trigger immediate scraper execution via HTMX
     */
    public function triggerScrape(Source $source, EventIngestionService $service, Request $request)
    {
        $log = $service->ingest($source);

        if ($request->header('HX-Request')) {
            return view('events.partials.source-card', ['source' => $source->fresh(['scrapeLogs'])]);
        }

        return redirect()->back()->with('status', "Avota {$source->name} parsēšana pabeigta!");
    }
}
