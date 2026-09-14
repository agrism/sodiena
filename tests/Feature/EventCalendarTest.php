<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Event;
use App\Models\Location;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\KulturasDatiScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_loads_successfully(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('Šodiena');
    }

    public function test_htmx_partial_filter_response(): void
    {
        $category = Category::create([
            'name' => 'Sports & Pārgājieni',
            'slug' => 'sports-pargajieni',
        ]);

        $event = Event::create([
            'title' => 'Cēsu Velobrauciens',
            'slug' => 'cesu-velobrauciens',
            'start_at' => now()->addDays(2),
            'fingerprint' => 'test-fingerprint-1',
            'status' => 'published',
            'published_at' => now(),
        ]);
        $event->categories()->attach($category);

        $response = $this->withHeaders(['HX-Request' => 'true'])
            ->get('/?category=sports-pargajieni');

        $response->assertStatus(200);
        $response->assertSee('Cēsu Velobrauciens');
        $response->assertDontSee('<!DOCTYPE html>'); // Ensures only partial is returned
    }

    public function test_event_show_page_displays_details(): void
    {
        $location = Location::create([
            'name' => 'Arēna Rīga',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'slug' => 'arena-riga-riga',
        ]);

        $event = Event::create([
            'location_id' => $location->id,
            'title' => 'Lielais Čempionāts',
            'slug' => 'lielais-cempionats',
            'description' => 'Aizraujošs čempionāts visai ģimenei.',
            'start_at' => now()->addDays(5),
            'fingerprint' => 'test-fingerprint-2',
            'status' => 'published',
            'published_at' => now(),
            'price_min' => 10,
            'price_max' => 25,
        ]);

        $response = $this->get('/events/' . $event->slug);

        $response->assertStatus(200);
        $response->assertSee('Lielais Čempionāts');
        $response->assertSee('Arēna Rīga');
        $response->assertSee('€10 - €25');
    }

    public function test_scraper_ingestion_and_deduplication(): void
    {
        $source = Source::create([
            'name' => 'Kultūras Dati',
            'slug' => 'kulturas-dati',
            'url' => 'https://www.kulturadati.lv',
            'scraper_class' => KulturasDatiScraper::class,
            'is_active' => true,
        ]);

        $service = app(EventIngestionService::class);
        $log = $service->ingest($source);

        $this->assertEquals('success', $log->status);
        $this->assertGreaterThan(0, $log->items_found);
        $this->assertDatabaseHas('events', [
            'source_id' => $source->id,
        ]);

        // Second ingestion should deduplicate and update rather than duplicate
        $secondLog = $service->ingest($source);
        $this->assertEquals('success', $secondLog->status);
        $this->assertEquals(0, $secondLog->items_created);
        $this->assertGreaterThan(0, $secondLog->items_updated);
    }

    public function test_custom_404_error_page(): void
    {
        $response = $this->get('/non-existent-page-url-12345');

        $response->assertStatus(404);
        $response->assertSee('Lapa netika atrasta');
        $response->assertSee('Atgriezties uz sākumlapu');
        $response->assertDontSee('Laravel');
    }

    public function test_security_headers_and_no_framework_signatures(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeaderMissing('X-Powered-By');
    }

    public function test_events_rendered_in_order_sooner_to_later(): void
    {
        $now = now();

        // 1. Ongoing exhibition started 2 years ago, ending next year
        $pastExhibition = Event::create([
            'title' => 'Sena Izstāde No Pagātnes',
            'slug' => 'sena-izstade',
            'start_at' => $now->copy()->subYears(2),
            'end_at' => $now->copy()->addMonths(6),
            'status' => 'published',
            'published_at' => $now,
            'fingerprint' => 'test-fp-past-exhibition',
        ]);

        // 2. Event happening tomorrow
        $tomorrowEvent = Event::create([
            'title' => 'Rītdienas Teātra Izrāde',
            'slug' => 'ritdienas-teatris',
            'start_at' => $now->copy()->addDay()->setTime(19, 0),
            'status' => 'published',
            'published_at' => $now,
            'fingerprint' => 'test-fp-tomorrow-event',
        ]);

        // 3. Event happening today
        $todayEvent = Event::create([
            'title' => 'Šodienas Lielais Koncerts',
            'slug' => 'sodienas-koncerts',
            'start_at' => $now->copy()->setTime(18, 0),
            'status' => 'published',
            'published_at' => $now,
            'fingerprint' => 'test-fp-today-event',
        ]);

        // 4. Event next week
        $nextWeekEvent = Event::create([
            'title' => 'Nākamās Nedēļas Festivāls',
            'slug' => 'nakamas-nedelas-festivals',
            'start_at' => $now->copy()->addDays(7)->setTime(12, 0),
            'status' => 'published',
            'published_at' => $now,
            'fingerprint' => 'test-fp-next-week-event',
        ]);

        $upcomingEvents = Event::upcoming()->get();

        $this->assertEquals($todayEvent->id, $upcomingEvents[0]->id, 'Today event must be first');
        $this->assertEquals($tomorrowEvent->id, $upcomingEvents[1]->id, 'Tomorrow event must be second');
        $this->assertEquals($nextWeekEvent->id, $upcomingEvents[2]->id, 'Next week event must be third');
        $this->assertEquals($pastExhibition->id, $upcomingEvents[3]->id, 'Past-started ongoing exhibition must come after discrete upcoming events');

        $this->assertStringStartsWith('Līdz ', $pastExhibition->formatted_date);
    }

    public function test_only_published_events_are_visible_to_customers(): void
    {
        $publishedEvent = Event::create([
            'title' => 'Apstiprināts Pasākums',
            'slug' => 'apstiprinats-pasakums',
            'start_at' => now()->addDays(3),
            'status' => 'published',
            'published_at' => now(),
            'fingerprint' => 'test-published-event',
        ]);

        $draftEvent = Event::create([
            'title' => 'Melnraksts Pasākums',
            'slug' => 'melnraksts-pasakums',
            'start_at' => now()->addDays(4),
            'status' => 'draft',
            'published_at' => null,
            'fingerprint' => 'test-draft-event',
        ]);

        $response = $this->get('/');
        $response->assertSee('Apstiprināts Pasākums');
        $response->assertDontSee('Melnraksts Pasākums');

        // Single show page check
        $this->get('/events/' . $publishedEvent->slug)->assertStatus(200);
        $this->get('/events/' . $draftEvent->slug)->assertStatus(404);
    }

    public function test_new_events_have_null_published_at_and_draft_status_by_default(): void
    {
        $event = Event::create([
            'title' => 'Jauns Ievākts Notikums',
            'start_at' => now()->addDays(2),
        ]);

        $this->assertNull($event->published_at);
        $this->assertEquals('draft', $event->status);
        $this->assertFalse($event->isPublished());
        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'status' => 'draft',
            'published_at' => null,
        ]);
        $this->assertFalse(Event::published()->where('id', $event->id)->exists());
    }

    public function test_prune_past_events_soft_deletes_events_before_today(): void
    {
        $now = now();
        $yesterday = $now->copy()->subDay()->startOfDay()->addHours(14);
        $yesterdayEnded = $now->copy()->subDay()->startOfDay()->addHours(20);

        // 1. Event from yesterday with no end_at (ended yesterday) -> Should be soft deleted
        $pastEvent1 = Event::create([
            'title' => 'Vakardienas Pasākums Bez Beigu Datuma',
            'start_at' => $yesterday,
            'status' => 'published',
            'published_at' => $now,
            'fingerprint' => 'test-past-event-1',
        ]);

        // 2. Event from 3 days ago with end_at yesterday -> Should be soft deleted
        $pastEvent2 = Event::create([
            'title' => 'Vakardien Beigusies Izstāde',
            'start_at' => $now->copy()->subDays(3),
            'end_at' => $yesterdayEnded,
            'status' => 'published',
            'published_at' => $now,
            'fingerprint' => 'test-past-event-2',
        ]);

        // 3. Event today -> Should NOT be deleted
        $todayEvent = Event::create([
            'title' => 'Šodienas Pasākums',
            'start_at' => $now->copy()->setTime(18, 0),
            'status' => 'published',
            'published_at' => $now,
            'fingerprint' => 'test-today-event',
        ]);

        // 4. Ongoing exhibition started 2 weeks ago, ending next week -> Should NOT be deleted
        $ongoingEvent = Event::create([
            'title' => 'Notiekoša Izstāde',
            'start_at' => $now->copy()->subDays(14),
            'end_at' => $now->copy()->addDays(7),
            'status' => 'published',
            'published_at' => $now,
            'fingerprint' => 'test-ongoing-event',
        ]);

        // 5. Future event -> Should NOT be deleted
        $futureEvent = Event::create([
            'title' => 'Nākotnes Koncerts',
            'start_at' => $now->copy()->addDays(5),
            'status' => 'published',
            'published_at' => $now,
            'fingerprint' => 'test-future-event',
        ]);

        // Run the artisan command
        $this->artisan('events:prune-past')
            ->assertSuccessful();

        // Assert soft deletions
        $this->assertSoftDeleted('events', ['id' => $pastEvent1->id]);
        $this->assertSoftDeleted('events', ['id' => $pastEvent2->id]);
        $this->assertNotSoftDeleted('events', ['id' => $todayEvent->id]);
        $this->assertNotSoftDeleted('events', ['id' => $ongoingEvent->id]);
        $this->assertNotSoftDeleted('events', ['id' => $futureEvent->id]);

        // Assert standard queries exclude soft-deleted events
        $this->assertEquals(3, Event::count());
        $this->assertEquals(5, Event::withTrashed()->count());
    }

    public function test_admin_afiro_source_badge_visibility(): void
    {
        \App\Models\Role::firstOrCreate(['slug' => \App\Models\Role::ADMIN], ['name' => 'Administrators']);
        \App\Models\Role::firstOrCreate(['slug' => \App\Models\Role::REGULAR], ['name' => 'Lietotājs']);

        $admin = \App\Models\User::factory()->create();
        $admin->assignRole(\App\Models\Role::ADMIN);

        $regularUser = \App\Models\User::factory()->create();
        $regularUser->assignRole(\App\Models\Role::REGULAR);

        $afiroSource = Source::create([
            'name' => 'Afiro Pasākumu API',
            'slug' => 'afiro-api',
            'url' => 'https://api.afiro.lv/events',
            'scraper_class' => KulturasDatiScraper::class,
            'is_active' => true,
        ]);

        $bezrindasSource = Source::create([
            'name' => 'BezRindas.lv',
            'slug' => 'bezrindas',
            'url' => 'https://www.bezrindas.lv',
            'scraper_class' => KulturasDatiScraper::class,
            'is_active' => true,
        ]);

        $afiroEvent = Event::create([
            'source_id' => $afiroSource->id,
            'source_slug' => 'afiro-api',
            'source_external_id' => 'afiro-test-item-123',
            'title' => 'Afiro festivāls',
            'start_at' => now()->addDays(2),
            'status' => 'published',
            'published_at' => now(),
            'fingerprint' => 'test-afiro-event',
        ]);

        $otherEvent = Event::create([
            'source_id' => $bezrindasSource->id,
            'source_slug' => 'bezrindas',
            'source_external_id' => 'bezrindas-456',
            'title' => 'Bezrindas teātris',
            'start_at' => now()->addDays(3),
            'status' => 'published',
            'published_at' => now(),
            'fingerprint' => 'test-other-event',
        ]);

        // 1. Guest viewing homepage -> No Afiro admin indicator
        $guestResponse = $this->get('/');
        $guestResponse->assertStatus(200);
        $guestResponse->assertDontSee('https://afiro.lv/events/afiro-test-item-123');

        // 2. Regular user viewing homepage -> No Afiro admin indicator
        $userResponse = $this->actingAs($regularUser)->get('/');
        $userResponse->assertStatus(200);
        $userResponse->assertDontSee('https://afiro.lv/events/afiro-test-item-123');

        // 3. Admin user viewing homepage -> Red Afiro link for Afiro event and gray badge for other event
        $adminResponse = $this->actingAs($admin)->get('/');
        $adminResponse->assertStatus(200);
        $adminResponse->assertSee('https://afiro.lv/events/afiro-test-item-123');
        $adminResponse->assertSee('Nav Afiro notikums (Avots: BezRindas.lv)');
    }
}
