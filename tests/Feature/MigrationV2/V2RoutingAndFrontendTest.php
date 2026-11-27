<?php

namespace Tests\Feature\MigrationV2;

use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRedirect;
use App\Models\Location;
use App\Models\Source;
use App\Services\SitemapService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V2RoutingAndFrontendTest extends TestCase
{
    use RefreshDatabase;

    protected Location $venue;
    protected Source $source;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->venue = Location::create([
            'name' => 'Kino Citadele',
            'city' => 'Rīga',
            'region' => 'Rīga un apkārtne',
            'address' => '13. janvāra iela 8',
            'place_type' => 'cinema',
        ]);

        $this->source = Source::create([
            'name' => 'Forum Cinemas',
            'slug' => 'forum-cinemas',
            'url' => 'https://forumcinemas.lv',
            'type' => 'venue',
            'scraper_class' => 'App\\Services\\Scrapers\\Sources\\TestScraper',
            'is_active' => true,
        ]);

        $this->category = Category::create([
            'name' => 'Kino',
            'slug' => 'kino',
            'icon' => 'film',
            'color' => '#e11d48',
            'order' => 1,
        ]);
    }

    /**
     * Test 1: Merged event returns HTTP 301 Moved Permanently and preserves query params and locale.
     */
    public function test_merged_event_redirects_301_to_winner_and_preserves_locale_and_query(): void
    {
        $winner = Event::create([
            'title' => 'Kāpa: Otrā daļa',
            'slug' => 'kapa-otra-dala',
            'location_id' => $this->venue->id,
            'start_at' => now()->addDays(5)->setTime(18, 0),
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        $merged = Event::create([
            'title' => 'Kāpa 2 (IMAX)',
            'slug' => 'kapa-2-imax',
            'location_id' => $this->venue->id,
            'start_at' => now()->addDays(5)->setTime(18, 0),
            'status' => 'merged',
            'merged_into_id' => $winner->id,
            'published_at' => now()->subDay(),
        ]);

        // 1. LV locale
        $responseLv = $this->get('/events/kapa-2-imax?ref=newsletter&utm=autumn');
        $responseLv->assertStatus(301);
        $this->assertTrue(str_contains($responseLv->headers->get('Location'), '/events/kapa-otra-dala?ref=newsletter&utm=autumn'));

        // 2. EN locale
        $responseEn = $this->get('/en/events/kapa-2-imax?partner=cinemas');
        $responseEn->assertStatus(301);
        $this->assertTrue(str_contains($responseEn->headers->get('Location'), '/en/events/kapa-otra-dala?partner=cinemas'));

        // 3. RU locale
        $responseRu = $this->get('/ru/events/kapa-2-imax');
        $responseRu->assertStatus(301);
        $this->assertTrue(str_contains($responseRu->headers->get('Location'), '/ru/events/kapa-otra-dala'));
    }

    /**
     * Test 2: Chained redirects follow hops up to winner.
     */
    public function test_chained_redirects_resolve_to_ultimate_winner(): void
    {
        $winner = Event::create([
            'title' => 'Fināla pasākums',
            'slug' => 'finala-pasakums',
            'location_id' => $this->venue->id,
            'start_at' => now()->addDays(2),
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        $stepB = Event::create([
            'title' => 'Starpposms B',
            'slug' => 'starpposms-b',
            'location_id' => $this->venue->id,
            'start_at' => now()->addDays(2),
            'status' => 'merged',
            'merged_into_id' => $winner->id,
            'published_at' => now()->subDay(),
        ]);

        $stepA = Event::create([
            'title' => 'Sākotnējais A',
            'slug' => 'sakotnejais-a',
            'location_id' => $this->venue->id,
            'start_at' => now()->addDays(2),
            'status' => 'merged',
            'merged_into_id' => $stepB->id,
            'published_at' => now()->subDay(),
        ]);

        $response = $this->get('/events/sakotnejais-a');
        $response->assertStatus(301);
        $this->assertTrue(str_contains($response->headers->get('Location'), '/events/finala-pasakums'));
    }

    /**
     * Test 3: Old slug lookup in event_redirects table returns 301.
     */
    public function test_redirect_via_event_redirects_table(): void
    {
        $winner = Event::create([
            'title' => 'Prāta Vētra Arēnā',
            'slug' => 'prata-vetra-arena',
            'location_id' => $this->venue->id,
            'start_at' => now()->addDays(10),
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        EventRedirect::create([
            'locale' => 'lv',
            'old_slug' => 'pv-arena-vecais-slug',
            'target_event_id' => $winner->id,
            'reason' => 'migrated',
        ]);

        $response = $this->get('/events/pv-arena-vecais-slug?ref=fb');
        $response->assertStatus(301);
        $this->assertTrue(str_contains($response->headers->get('Location'), '/events/prata-vetra-arena?ref=fb'));
    }

    /**
     * Test 4: Deleted or trashed events return HTTP 410 Gone.
     */
    public function test_deleted_event_returns_410_gone(): void
    {
        $deletedEvent = Event::create([
            'title' => 'Dzēsts pasākums',
            'slug' => 'dzests-pasakums',
            'location_id' => $this->venue->id,
            'start_at' => now()->addDays(2),
            'status' => 'deleted',
            'published_at' => now()->subDay(),
        ]);

        $response = $this->get('/events/dzests-pasakums');
        $response->assertStatus(410);
        $response->assertViewIs('errors.410');

        // Merged event whose target is deleted also returns 410
        $mergedToDeleted = Event::create([
            'title' => 'Merged to Deleted',
            'slug' => 'merged-to-deleted',
            'location_id' => $this->venue->id,
            'start_at' => now()->addDays(2),
            'status' => 'merged',
            'merged_into_id' => $deletedEvent->id,
            'published_at' => now()->subDay(),
        ]);

        $responseMerged = $this->get('/events/merged-to-deleted');
        $responseMerged->assertStatus(410);
    }

    /**
     * Test 5: Cancelled event returns HTTP 200 OK with noindex, follow and warning banner.
     */
    public function test_cancelled_event_returns_200_with_noindex_and_warning_banner(): void
    {
        $cancelledEvent = Event::create([
            'title' => 'Atcelts rokfestivāls',
            'slug' => 'atcelts-rokfestivals',
            'location_id' => $this->venue->id,
            'start_at' => now()->addDays(3)->setTime(20, 0),
            'status' => 'cancelled',
            'published_at' => now()->subDay(),
            'ticket_url' => 'https://bilesuserviss.lv/tickets/cancelled',
        ]);

        $response = $this->get('/events/atcelts-rokfestivals');
        $response->assertStatus(200);

        // SEO check: must have robots noindex, follow
        $response->assertSee('<meta name="robots" content="noindex, follow">', false);

        // Warning banner check
        $response->assertSee('Pasākums ir atcelts');

        // Disabled ticket state check
        $response->assertSee('Biļešu iegāde nav pieejama');
        $response->assertDontSee('https://bilesuserviss.lv/tickets/cancelled');
    }

    /**
     * Test 6: Multi-occurrence winner page renders structured schedule block.
     */
    public function test_winner_page_with_multiple_occurrences_renders_schedule_block(): void
    {
        $winner = Event::create([
            'title' => 'Gladiators II',
            'slug' => 'gladiators-ii',
            'location_id' => $this->venue->id,
            'start_at' => now()->addDays(1)->setTime(15, 0),
            'status' => 'published',
            'published_at' => now()->subDay(),
            'occurrences_count' => 3,
            'ticket_url' => 'https://forumcinemas.lv/tickets/general',
            'raw_data' => [
                'ticket_links' => [
                    ['url' => 'https://forumcinemas.lv/tickets/general', 'title' => 'Forum Cinemas', 'label' => 'Forum Cinemas seansi un biļetes', 'platform' => 'forum'],
                ],
            ],
        ]);

        // Occurrence 1: Tomorrow 15:00 IMAX
        $occ1 = EventOccurrence::create([
            'event_id' => $winner->id,
            'start_at' => now()->addDays(1)->setTime(15, 0),
            'status' => 'scheduled',
            'presentation_format' => 'IMAX 2D',
            'auditorium' => 'Zāle 1 (ISENSE)',
            'price_min' => 9.50,
            'price_max' => 12.00,
            'ticket_url' => 'https://forumcinemas.lv/tickets/occ1',
            'occurrence_fingerprint' => 'fp_occ1',
        ]);

        // Occurrence 2: Tomorrow 19:30 LUX
        $occ2 = EventOccurrence::create([
            'event_id' => $winner->id,
            'start_at' => now()->addDays(1)->setTime(19, 30),
            'status' => 'scheduled',
            'presentation_format' => 'LUX',
            'auditorium' => 'LUX Suite',
            'price_min' => 15.00,
            'ticket_url' => 'https://forumcinemas.lv/tickets/occ2',
            'occurrence_fingerprint' => 'fp_occ2',
        ]);

        // Occurrence 3: Day after tomorrow 17:00 3D
        $occ3 = EventOccurrence::create([
            'event_id' => $winner->id,
            'start_at' => now()->addDays(2)->setTime(17, 0),
            'status' => 'scheduled',
            'presentation_format' => '3D',
            'auditorium' => 'Zāle 2',
            'price_min' => 8.00,
            'ticket_url' => 'https://forumcinemas.lv/tickets/occ3',
            'occurrence_fingerprint' => 'fp_occ3',
        ]);

        $response = $this->get('/events/gladiators-ii');
        $response->assertStatus(200);

        // Schedule section rendered
        $response->assertSee('Seansu un norišu grafiks');
        $response->assertSee('IMAX 2D');
        $response->assertSee('Zāle 1 (ISENSE)');
        $response->assertSee('LUX');
        $response->assertSee('3D');
        $response->assertSee('data-occurrence-id="' . $occ1->id . '"', false);
        $response->assertSee('data-occurrence-id="' . $occ2->id . '"', false);
        $response->assertSee('data-occurrence-id="' . $occ3->id . '"', false);

        // Sidebar must NOT show the general "<place> seansi un biļetes" buttons for multi-occurrence events
        $response->assertDontSee('Forum Cinemas seansi un biļetes');
        $response->assertDontSee('Seansi un biļetes kinoteātros');

        // Single occurrence event must still render ticket button in sidebar
        $singleEvent = Event::create([
            'title' => 'Vienas norises pasākums',
            'slug' => 'vienas-norises-pasakums',
            'location_id' => $this->venue->id,
            'start_at' => now()->addDays(3)->setTime(19, 0),
            'status' => 'published',
            'published_at' => now()->subDay(),
            'ticket_url' => 'https://bilesuserviss.lv/tickets/single-123',
            'occurrences_count' => 1,
        ]);
        EventOccurrence::create([
            'event_id' => $singleEvent->id,
            'start_at' => now()->addDays(3)->setTime(19, 0),
            'status' => 'scheduled',
            'occurrence_fingerprint' => 'fp_single',
        ]);

        $singleResponse = $this->get('/events/vienas-norises-pasakums');
        $singleResponse->assertStatus(200);
        $singleResponse->assertDontSee('Seansu un norišu grafiks');
        $singleResponse->assertSee('https://bilesuserviss.lv/tickets/single-123');
    }

    /**
     * Test 7: Catalog date filtering using scopeForDateFilter with event_occurrences.
     */
    public function test_catalog_date_filtering_with_occurrences(): void
    {
        $day1 = Carbon::today()->addDays(4);
        $day2 = Carbon::today()->addDays(5);
        $day3 = Carbon::today()->addDays(6);

        // Multi-day event on Day 1 and Day 2
        $multiEvent = Event::create([
            'title' => 'Multi-dienu Festivāls',
            'slug' => 'multi-dienu-festivals',
            'location_id' => $this->venue->id,
            'start_at' => $day1->copy()->setTime(14, 0),
            'status' => 'published',
            'published_at' => now()->subDay(),
            'occurrences_count' => 3,
        ]);

        // 2 sessions on Day 1
        EventOccurrence::create([
            'event_id' => $multiEvent->id,
            'start_at' => $day1->copy()->setTime(14, 0),
            'status' => 'scheduled',
            'occurrence_fingerprint' => 'fp_d1_1',
        ]);
        EventOccurrence::create([
            'event_id' => $multiEvent->id,
            'start_at' => $day1->copy()->setTime(20, 0),
            'status' => 'scheduled',
            'occurrence_fingerprint' => 'fp_d1_2',
        ]);

        // 1 session on Day 2
        EventOccurrence::create([
            'event_id' => $multiEvent->id,
            'start_at' => $day2->copy()->setTime(17, 0),
            'status' => 'scheduled',
            'occurrence_fingerprint' => 'fp_d2_1',
        ]);

        // Cancelled event on Day 1 (should be excluded)
        $cancelled = Event::create([
            'title' => 'Atcelts pasākums',
            'slug' => 'atcelts-d1',
            'location_id' => $this->venue->id,
            'start_at' => $day1->copy()->setTime(18, 0),
            'status' => 'cancelled',
            'published_at' => now()->subDay(),
        ]);
        EventOccurrence::create([
            'event_id' => $cancelled->id,
            'start_at' => $day1->copy()->setTime(18, 0),
            'status' => 'cancelled',
            'occurrence_fingerprint' => 'fp_canc',
        ]);

        // Merged event on Day 1 (should be excluded)
        $merged = Event::create([
            'title' => 'Merged pasākums',
            'slug' => 'merged-d1',
            'location_id' => $this->venue->id,
            'start_at' => $day1->copy()->setTime(14, 0),
            'status' => 'merged',
            'merged_into_id' => $multiEvent->id,
            'published_at' => now()->subDay(),
        ]);

        // Query catalog for Day 1
        $day1Events = Event::forDateFilter($day1->toDateString())->get();
        $this->assertEquals(1, $day1Events->where('id', $multiEvent->id)->count(), 'Multi-session event should appear exactly once on Day 1');
        $this->assertFalse($day1Events->contains('id', $cancelled->id), 'Cancelled event must be excluded');
        $this->assertFalse($day1Events->contains('id', $merged->id), 'Merged event must be excluded');

        // Query catalog for Day 2
        $day2Events = Event::forDateFilter($day2->toDateString())->get();
        $this->assertEquals(1, $day2Events->where('id', $multiEvent->id)->count(), 'Multi-session event should appear on Day 2 as well');

        // Query catalog for Day 3
        $day3Events = Event::forDateFilter($day3->toDateString())->get();
        $this->assertFalse($day3Events->contains('id', $multiEvent->id), 'Multi-session event does not run on Day 3');
    }

    /**
     * Test 8: Sitemap service excludes merged, deleted, and cancelled events.
     */
    public function test_sitemap_excludes_merged_deleted_and_cancelled_events(): void
    {
        $published = Event::create([
            'title' => 'Kanonisks Publiskots',
            'slug' => 'kanonisks-publiskots',
            'location_id' => $this->venue->id,
            'start_at' => now()->addDays(2),
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        $merged = Event::create([
            'title' => 'Sapludināts Nepublikāts',
            'slug' => 'sapludinats-nepublikats',
            'location_id' => $this->venue->id,
            'start_at' => now()->addDays(2),
            'status' => 'merged',
            'merged_into_id' => $published->id,
            'published_at' => now()->subDay(),
        ]);

        $cancelled = Event::create([
            'title' => 'Atcelts Pasākums',
            'slug' => 'atcelts-pasakums-sitemap',
            'location_id' => $this->venue->id,
            'start_at' => now()->addDays(2),
            'status' => 'cancelled',
            'published_at' => now()->subDay(),
        ]);

        $deleted = Event::create([
            'title' => 'Dzēsts Pasākums',
            'slug' => 'dzests-pasakums-sitemap',
            'location_id' => $this->venue->id,
            'start_at' => now()->addDays(2),
            'status' => 'deleted',
            'published_at' => now()->subDay(),
        ]);

        $sitemapService = app(SitemapService::class);
        $xml = $sitemapService->generate();

        $this->assertStringContainsString('kanonisks-publiskots', $xml);
        $this->assertStringNotContainsString('sapludinats-nepublikats', $xml);
        $this->assertStringNotContainsString('atcelts-pasakums-sitemap', $xml);
        $this->assertStringNotContainsString('dzests-pasakums-sitemap', $xml);
    }
}
