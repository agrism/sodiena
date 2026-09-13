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
        $categorySlug = $request->input('category', 'all');
        $city = $request->input('city', 'all');
        $timeframe = $request->input('timeframe', 'upcoming');
        $sortBy = $request->input('sort_by', 'start_at');
        $sortDir = strtolower($request->input('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $perPage = (int) $request->input('per_page', 50);

        if (!in_array($perPage, [25, 50, 100, 200], true)) {
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
                  ->orWhereHas('location', function ($locQ) use ($search) {
                      $locQ->where('name', 'like', "%{$search}%")
                           ->orWhere('city', 'like', "%{$search}%");
                  });
            });
        }

        // Filter by Source
        if (!empty($sourceSlug) && $sourceSlug !== 'all') {
            $query->where('source_slug', $sourceSlug);
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

        // Filter by Timeframe
        $now = now();
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
        $allowedSorts = ['id', 'start_at', 'title', 'price_min', 'views_count', 'created_at'];
        if (in_array($sortBy, $allowedSorts, true)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->orderBy('start_at', 'asc');
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

        $stats = [
            'total' => Event::count(),
            'upcoming' => Event::upcoming()->count(),
            'past' => Event::where('start_at', '<', $now)->where(function ($q) use ($now) {
                $q->whereNull('end_at')->orWhere('end_at', '<', $now);
            })->count(),
            'today' => Event::forDateFilter('today')->count(),
            'filtered' => $events->total(),
        ];

        return view('admin.events.index', compact(
            'events',
            'sources',
            'categories',
            'cities',
            'stats',
            'search',
            'sourceSlug',
            'categorySlug',
            'city',
            'timeframe',
            'sortBy',
            'sortDir',
            'perPage'
        ));
    }
}
