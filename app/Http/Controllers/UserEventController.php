<?php

namespace App\Http\Controllers;

use App\Jobs\RefreshSitemapJob;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventTranslation;
use App\Models\Location;
use App\Models\LocationTranslation;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class UserEventController extends Controller
{
    /**
     * Show the form for creating a new event.
     */
    public function create(): View
    {
        $categories = Category::with('translations')->orderBy('order')->get();

        $cities = Location::select('city')
            ->distinct()
            ->whereNotNull('city')
            ->orderBy('city')
            ->pluck('city');

        $popularLocations = Location::select('id', 'name', 'city', 'address')
            ->whereNotNull('name')
            ->orderBy('city')
            ->orderBy('name')
            ->take(200)
            ->get();

        return view('events.create', compact('categories', 'cities', 'popularLocations'));
    }

    /**
     * Store a newly created event in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'categories' => ['required', 'array', 'min:1'],
            'categories.*' => ['required', 'integer', 'exists:categories,id'],
            'start_at' => ['required', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'all_day' => ['nullable', 'boolean'],
            'is_free' => ['nullable', 'boolean'],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0'],
            'ticket_url' => ['nullable', 'url', 'max:2000'],
            'source_url' => ['nullable', 'url', 'max:2000'],
            'description' => ['required', 'string', 'min:10'],
            'short_description' => ['nullable', 'string', 'max:1000'],
            'entertainment_type' => ['nullable', 'string', 'max:50'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'city' => ['required_without:location_id', 'nullable', 'string', 'max:100'],
            'venue_name' => ['required_without:location_id', 'nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:5120'],
            'image_url' => ['nullable', 'url', 'max:2000'],
        ], [
            'title.required' => __('Please enter event title.'),
            'title.min' => __('Event title must be at least 3 characters long.'),
            'categories.required' => __('Please select at least one category.'),
            'categories.min' => __('Please select at least one category.'),
            'start_at.required' => __('Please specify event start date and time.'),
            'end_at.after_or_equal' => __('End date and time must be after or equal to start date and time.'),
            'description.required' => __('Please enter event description.'),
            'description.min' => __('Description must be at least 10 characters long.'),
            'city.required_without' => __('Please specify a city or choose a venue from the list.'),
            'venue_name.required_without' => __('Please specify a venue name or choose from the list.'),
            'image.image' => __('The uploaded file must be an image (JPEG, PNG, WEBP, GIF).'),
            'image.max' => __('Image size must not exceed 5MB.'),
        ]);

        return DB::transaction(function () use ($request, $validated) {
            // 1. Resolve Location
            $locationId = null;
            $locationName = null;
            if (! empty($validated['location_id'])) {
                $location = Location::find($validated['location_id']);
                if ($location) {
                    $locationId = $location->id;
                    $locationName = $location->name;
                }
            } elseif (! empty($validated['venue_name']) && ! empty($validated['city'])) {
                $vName = trim($validated['venue_name']);
                $vCity = trim($validated['city']);
                $vAddress = trim((string) ($validated['address'] ?? ''));

                $location = Location::where('name', $vName)->where('city', $vCity)->first();
                if (! $location) {
                    $location = Location::create([
                        'name' => $vName,
                        'city' => $vCity,
                        'address' => $vAddress ?: null,
                        'region' => $vCity,
                        'place_type' => 'venue',
                    ]);

                    LocationTranslation::create([
                        'location_id' => $location->id,
                        'locale' => 'lv',
                        'name' => $vName,
                        'city' => $vCity,
                        'address' => $vAddress ?: null,
                    ]);
                } elseif (! empty($vAddress) && empty($location->address)) {
                    $location->update(['address' => $vAddress]);
                }

                $locationId = $location->id;
                $locationName = $location->name;
            }

            // 2. Resolve Image URL
            $imageUrl = null;
            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('events', 'public');
                $imageUrl = '/storage/'.$path;
            } elseif (! empty($validated['image_url'])) {
                $imageUrl = trim($validated['image_url']);
            }

            // 3. Resolve Pricing & Dates
            $isFree = (bool) ($validated['is_free'] ?? false);
            $priceMin = $isFree ? 0 : (! empty($validated['price_min']) ? (float) $validated['price_min'] : null);
            $priceMax = $isFree ? 0 : (! empty($validated['price_max']) ? (float) $validated['price_max'] : null);

            $startAt = Carbon::parse($validated['start_at']);
            $endAt = ! empty($validated['end_at']) ? Carbon::parse($validated['end_at']) : null;
            $allDay = (bool) ($validated['all_day'] ?? false);

            if ($allDay) {
                $startAt = $startAt->startOfDay();
                if ($endAt) {
                    $endAt = $endAt->endOfDay();
                }
            }

            // Short description fallback
            $description = trim($validated['description']);
            $shortDescription = ! empty($validated['short_description'])
                ? trim($validated['short_description'])
                : Str::limit(strip_tags($description), 280);

            // 4. Categorization Rules & AGENTS.md Business Logic
            $categoryIds = $this->applyDomainCategorizationRules(
                $validated['categories'] ?? [],
                $validated['title'],
                $locationName
            );

            // 5. Create Event
            $event = Event::create([
                'user_id' => Auth::id(),
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
                'currency' => 'EUR',
                'ticket_url' => $validated['ticket_url'] ?? null,
                'source_url' => $validated['source_url'] ?? null,
                'image_url' => $imageUrl,
                'entertainment_type' => $validated['entertainment_type'] ?? null,
                'status' => 'published',
                'published_at' => now(),
                'source_slug' => 'user',
            ]);

            // Sync categories
            $event->categories()->sync($categoryIds);

            // 6. Create primary LV translation record (and current locale if different)
            $currentLocale = app()->getLocale();
            EventTranslation::create([
                'event_id' => $event->id,
                'locale' => 'lv',
                'title' => $event->title,
                'slug' => $event->slug,
                'description' => $event->description,
                'short_description' => $event->short_description,
            ]);

            if ($currentLocale !== 'lv') {
                EventTranslation::create([
                    'event_id' => $event->id,
                    'locale' => $currentLocale,
                    'title' => $event->title,
                    'slug' => $event->slug,
                    'description' => $event->description,
                    'short_description' => $event->short_description,
                ]);
            }

            // Clear cache & refresh sitemap
            Cache::forget('cat_counts_lv_all_none');
            Cache::forget('locations_distinct_cities');
            RefreshSitemapJob::dispatch();

            return redirect()->to($event->localized_url)
                ->with('success', __('Event successfully added and published!'));
        });
    }

    /**
     * Display a listing of events added by the authenticated user.
     */
    public function myEvents(): View
    {
        $events = Auth::user()->events()
            ->with(['location', 'categories'])
            ->orderBy('start_at', 'desc')
            ->paginate(15);

        return view('events.my-events', compact('events'));
    }

    /**
     * Show the form for editing an existing event owned by user.
     */
    public function edit(Event $event): View
    {
        $this->authorizeEventOwner($event);

        $categories = Category::with('translations')->orderBy('order')->get();

        $cities = Location::select('city')
            ->distinct()
            ->whereNotNull('city')
            ->orderBy('city')
            ->pluck('city');

        $popularLocations = Location::select('id', 'name', 'city', 'address')
            ->whereNotNull('name')
            ->orderBy('city')
            ->orderBy('name')
            ->take(200)
            ->get();

        return view('events.edit', compact('event', 'categories', 'cities', 'popularLocations'));
    }

    /**
     * Update the specified event.
     */
    public function update(Request $request, Event $event): RedirectResponse
    {
        $this->authorizeEventOwner($event);

        $validated = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'categories' => ['required', 'array', 'min:1'],
            'categories.*' => ['required', 'integer', 'exists:categories,id'],
            'start_at' => ['required', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'all_day' => ['nullable', 'boolean'],
            'is_free' => ['nullable', 'boolean'],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0'],
            'ticket_url' => ['nullable', 'url', 'max:2000'],
            'source_url' => ['nullable', 'url', 'max:2000'],
            'description' => ['required', 'string', 'min:10'],
            'short_description' => ['nullable', 'string', 'max:1000'],
            'entertainment_type' => ['nullable', 'string', 'max:50'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'city' => ['nullable', 'string', 'max:100'],
            'venue_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:5120'],
            'image_url' => ['nullable', 'url', 'max:2000'],
        ], [
            'title.required' => __('Please enter event title.'),
            'categories.required' => __('Please select at least one category.'),
            'start_at.required' => __('Please specify event start date and time.'),
            'end_at.after_or_equal' => __('End date and time must be after or equal to start date and time.'),
            'description.required' => __('Please enter event description.'),
        ]);

        return DB::transaction(function () use ($request, $validated, $event) {
            // Location resolution
            $locationId = $event->location_id;
            $locationName = $event->location?->name;

            if (! empty($validated['location_id'])) {
                $location = Location::find($validated['location_id']);
                if ($location) {
                    $locationId = $location->id;
                    $locationName = $location->name;
                }
            } elseif (! empty($validated['venue_name']) && ! empty($validated['city'])) {
                $vName = trim($validated['venue_name']);
                $vCity = trim($validated['city']);
                $vAddress = trim((string) ($validated['address'] ?? ''));

                $location = Location::firstOrCreate(
                    ['name' => $vName, 'city' => $vCity],
                    ['address' => $vAddress ?: null, 'region' => $vCity, 'place_type' => 'venue']
                );
                $locationId = $location->id;
                $locationName = $location->name;
            }

            // Image handling
            $imageUrl = $event->image_url;
            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('events', 'public');
                $imageUrl = '/storage/'.$path;
            } elseif (! empty($validated['image_url'])) {
                $imageUrl = trim($validated['image_url']);
            }

            $isFree = (bool) ($validated['is_free'] ?? false);
            $startAt = Carbon::parse($validated['start_at']);
            $endAt = ! empty($validated['end_at']) ? Carbon::parse($validated['end_at']) : null;
            $allDay = (bool) ($validated['all_day'] ?? false);

            if ($allDay) {
                $startAt = $startAt->startOfDay();
                if ($endAt) {
                    $endAt = $endAt->endOfDay();
                }
            }

            $shortDescription = ! empty($validated['short_description'])
                ? trim($validated['short_description'])
                : Str::limit(strip_tags(trim($validated['description'])), 280);

            $categoryIds = $this->applyDomainCategorizationRules(
                $validated['categories'] ?? [],
                $validated['title'],
                $locationName
            );

            $event->update([
                'location_id' => $locationId,
                'title' => trim($validated['title']),
                'description' => trim($validated['description']),
                'short_description' => $shortDescription,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'all_day' => $allDay,
                'is_free' => $isFree,
                'price_min' => $isFree ? 0 : (! empty($validated['price_min']) ? (float) $validated['price_min'] : null),
                'price_max' => $isFree ? 0 : (! empty($validated['price_max']) ? (float) $validated['price_max'] : null),
                'ticket_url' => $validated['ticket_url'] ?? null,
                'source_url' => $validated['source_url'] ?? null,
                'image_url' => $imageUrl,
                'entertainment_type' => $validated['entertainment_type'] ?? null,
            ]);

            $event->categories()->sync($categoryIds);

            // Update primary LV translation (and current locale if different)
            $currentLocale = app()->getLocale();
            EventTranslation::updateOrCreate(
                ['event_id' => $event->id, 'locale' => 'lv'],
                [
                    'title' => $event->title,
                    'slug' => $event->slug,
                    'description' => $event->description,
                    'short_description' => $event->short_description,
                ]
            );

            if ($currentLocale !== 'lv') {
                EventTranslation::updateOrCreate(
                    ['event_id' => $event->id, 'locale' => $currentLocale],
                    [
                        'title' => $event->title,
                        'slug' => $event->slug,
                        'description' => $event->description,
                        'short_description' => $event->short_description,
                    ]
                );
            }

            RefreshSitemapJob::dispatch();

            return redirect()->to($event->localized_url)
                ->with('success', __('Event successfully updated!'));
        });
    }

    /**
     * Delete the specified event.
     */
    public function destroy(Event $event): RedirectResponse
    {
        $this->authorizeEventOwner($event);

        $event->delete();
        RefreshSitemapJob::dispatch();

        return redirect()->route('events.my')
            ->with('success', __('Event successfully deleted.'));
    }

    /**
     * Check authorization: user must own event or be admin.
     */
    protected function authorizeEventOwner(Event $event): void
    {
        if ($event->user_id !== Auth::id() && ! Auth::user()->isAdmin()) {
            abort(403, __('You do not have permission to perform this action.'));
        }
    }

    /**
     * Apply strict cinema / theatre domain rules per AGENTS.md.
     */
    protected function applyDomainCategorizationRules(array $categoryIds, string $title, ?string $venueName): array
    {
        $kinoCategory = Category::where('slug', 'kino')->first();
        $teatrisCategory = Category::where('slug', 'teatris')->first();
        $citiCategory = Category::where('slug', 'citi')->first();

        $text = mb_strtolower($title.' '.($venueName ?? ''), 'UTF-8');

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
            if (! in_array($kinoCategory->id, $categoryIds, true)) {
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
