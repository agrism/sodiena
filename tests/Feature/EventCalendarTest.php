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
            'fingerprint' => 'test-fp-past-exhibition',
        ]);

        // 2. Event happening tomorrow
        $tomorrowEvent = Event::create([
            'title' => 'Rītdienas Teātra Izrāde',
            'slug' => 'ritdienas-teatris',
            'start_at' => $now->copy()->addDay()->setTime(19, 0),
            'status' => 'published',
            'fingerprint' => 'test-fp-tomorrow-event',
        ]);

        // 3. Event happening today
        $todayEvent = Event::create([
            'title' => 'Šodienas Lielais Koncerts',
            'slug' => 'sodienas-koncerts',
            'start_at' => $now->copy()->setTime(18, 0),
            'status' => 'published',
            'fingerprint' => 'test-fp-today-event',
        ]);

        // 4. Event next week
        $nextWeekEvent = Event::create([
            'title' => 'Nākamās Nedēļas Festivāls',
            'slug' => 'nakamas-nedelas-festivals',
            'start_at' => $now->copy()->addDays(7)->setTime(12, 0),
            'status' => 'published',
            'fingerprint' => 'test-fp-next-week-event',
        ]);

        $upcomingEvents = Event::upcoming()->get();

        $this->assertEquals($todayEvent->id, $upcomingEvents[0]->id, 'Today event must be first');
        $this->assertEquals($tomorrowEvent->id, $upcomingEvents[1]->id, 'Tomorrow event must be second');
        $this->assertEquals($nextWeekEvent->id, $upcomingEvents[2]->id, 'Next week event must be third');
        $this->assertEquals($pastExhibition->id, $upcomingEvents[3]->id, 'Past-started ongoing exhibition must come after discrete upcoming events');

        $this->assertStringStartsWith('Līdz ', $pastExhibition->formatted_date);
    }
}
