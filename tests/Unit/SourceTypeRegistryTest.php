<?php

namespace Tests\Unit;

use App\Enums\SourceType;
use App\Models\Event;
use App\Models\Location;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceTypeRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_type_enum_properties_and_priorities(): void
    {
        $types = SourceType::cases();
        $this->assertCount(7, $types);

        // Verify priorities
        $venue = SourceType::VENUE;
        $ticketing = SourceType::TICKETING;
        $sports = SourceType::SPORTS;
        $municipality = SourceType::MUNICIPALITY;
        $aggregator = SourceType::AGGREGATOR;

        // General priority: Venue > Municipality > Aggregator
        $this->assertGreaterThan($aggregator->priority(), $venue->priority());
        $this->assertGreaterThan($aggregator->priority(), $municipality->priority());

        // Ticket priority: Ticketing > Venue > Municipality > Aggregator
        $this->assertGreaterThan($venue->ticketPriority(), $ticketing->ticketPriority());
        $this->assertGreaterThan($municipality->ticketPriority(), $venue->ticketPriority());
        $this->assertGreaterThan($aggregator->ticketPriority(), $municipality->ticketPriority());

        // Location priority: Venue > Municipality > Ticketing > Aggregator
        $this->assertGreaterThan($municipality->locationPriority(), $venue->locationPriority());
        $this->assertGreaterThan($ticketing->locationPriority(), $municipality->locationPriority());
        $this->assertGreaterThan($aggregator->locationPriority(), $ticketing->locationPriority());
    }

    public function test_source_model_type_casting_and_fallback(): void
    {
        $source = Source::create([
            'name' => 'Biļešu Paradīze Test',
            'slug' => 'bilesu-paradize-test',
            'url' => 'https://www.bilesuparadize.lv',
            'scraper_class' => 'App\\Services\\Scrapers\\BilesuParadizeScraper',
            'is_active' => true,
            'type' => SourceType::TICKETING,
        ]);

        $this->assertInstanceOf(SourceType::class, $source->type);
        $this->assertEquals(SourceType::TICKETING, $source->type);
        $this->assertEquals('Biļešu tirgotājs', $source->type->label());

        // Test fallback for unspecified or null
        $otherSource = Source::create([
            'name' => 'Unknown Source',
            'slug' => 'unknown-source',
            'url' => 'https://example.com',
            'scraper_class' => 'App\\Services\\Scrapers\\UnknownScraper',
            'is_active' => false,
        ]);

        $this->assertEquals(SourceType::OTHER, $otherSource->type);
    }

    public function test_ingestion_field_fusion_respects_source_type_priorities(): void
    {
        $service = app(EventIngestionService::class);

        // 1. Create Aggregator source & Venue source
        $aggregatorSource = Source::create([
            'name' => 'Generic Aggregator',
            'slug' => 'generic-aggregator',
            'url' => 'https://aggregator.example.com',
            'scraper_class' => 'App\\Services\\Scrapers\\GenericScraper',
            'is_active' => true,
            'type' => SourceType::AGGREGATOR,
        ]);

        $venueSource = Source::create([
            'name' => 'Ogres Teātris',
            'slug' => 'ogres-teatris',
            'url' => 'https://okultura.lv',
            'scraper_class' => 'App\\Services\\Scrapers\\OkulturaScraper',
            'is_active' => true,
            'type' => SourceType::VENUE,
        ]);

        $ticketingSource = Source::create([
            'name' => 'Biļešu Paradīze',
            'slug' => 'bilesu-paradize',
            'url' => 'https://bilesuparadize.lv',
            'scraper_class' => 'App\\Services\\Scrapers\\BilesuParadizeScraper',
            'is_active' => true,
            'type' => SourceType::TICKETING,
        ]);

        $looseLoc = Location::create(['name' => 'Ogre, Centrs', 'city' => 'Ogre', 'region' => 'Vidzeme']);
        $exactLoc = Location::create(['name' => 'Ogres novada Kultūras centrs', 'city' => 'Ogre', 'region' => 'Vidzeme', 'address' => 'Brīvības iela 15']);

        $startAt = Carbon::now()->addDays(5)->setTime(19, 0, 0);

        // Step 1: Ingest event from secondary Aggregator with loose location and generic ticket
        $aggDto = new ScrapedEventDTO(
            title: 'Lielā Svētku Izrāde',
            startAt: $startAt,
            endAt: null,
            description: 'Apraksts no agregatora',
            venueName: 'Ogre, Centrs',
            city: 'Ogre',
            ticketUrl: 'https://aggregator.example.com/event/123',
            imageUrl: 'https://aggregator.example.com/poster.jpg',
            isFree: false,
            priceMin: 10.0,
            priceMax: 20.0,
            sourceUrl: 'https://aggregator.example.com/event/123',
            sourceExternalId: 'agg-123'
        );

        $res1 = $service->ingestDTO($aggDto, $aggregatorSource);
        $this->assertEquals('created', $res1);

        $event = Event::where('title', 'Lielā Svētku Izrāde')->first();
        $this->assertNotNull($event);
        $this->assertEquals($aggregatorSource->id, $event->source_id);

        // Step 2: Ingest event from authoritative Venue source
        // Venue has higher general priority (90 vs 50) and higher location priority (100 vs 30)
        $venueDto = new ScrapedEventDTO(
            title: 'Lielā Svētku Izrāde',
            startAt: $startAt,
            endAt: null,
            description: 'Pilns oficiālais apraksts no norises vietas',
            venueName: 'Ogres novada Kultūras centrs',
            address: 'Brīvības iela 15',
            city: 'Ogre',
            ticketUrl: null,
            imageUrl: 'https://okultura.lv/banner.jpg',
            isFree: false,
            priceMin: null,
            priceMax: null,
            sourceUrl: 'https://okultura.lv/pasakumi/456',
            sourceExternalId: 'okultura-456'
        );

        $res2 = $service->ingestDTO($venueDto, $venueSource);
        $this->assertEquals('updated', $res2);

        $event->refresh();
        // Authoritative venue source was adopted because Venue priority > Aggregator priority
        $this->assertEquals($venueSource->id, $event->source_id);
        $this->assertEquals($venueSource->slug, $event->source_slug);
        // Location updated to exact venue location because Venue locationPriority > Aggregator
        $this->assertEquals('Ogres novada Kultūras centrs', $event->location->name);
        // Ticket URL was preserved from aggregator because Venue had no ticket URL
        $this->assertEquals('https://aggregator.example.com/event/123', $event->ticket_url);

        // Step 3: Ingest ticketing platform with higher ticketPriority (100 vs 70/30)
        $ticketDto = new ScrapedEventDTO(
            title: 'Lielā Svētku Izrāde',
            startAt: $startAt,
            endAt: null,
            description: 'Īss biļešu tirgotāja apraksts',
            venueName: 'Ogre, Brīvības 15',
            city: 'Ogre',
            ticketUrl: 'https://www.bilesuparadize.lv/lv/event/99999',
            imageUrl: null,
            isFree: false,
            priceMin: 12.0,
            priceMax: 25.0,
            sourceUrl: 'https://www.bilesuparadize.lv/lv/event/99999',
            sourceExternalId: 'bp-99999'
        );

        $res3 = $service->ingestDTO($ticketDto, $ticketingSource);
        $this->assertEquals('updated', $res3);

        $event->refresh();
        // Direct checkout ticket URL and prices updated because Ticketing ticketPriority > Venue
        $this->assertEquals('https://www.bilesuparadize.lv/lv/event/99999', $event->ticket_url);
        $this->assertEquals(12.0, (float) $event->price_min);
        $this->assertEquals(25.0, (float) $event->price_max);
        // Location is still the authoritative venue location because Venue locationPriority (100) > Ticketing (40)
        $this->assertEquals('Ogres novada Kultūras centrs', $event->location->name);
    }

    public function test_candidate_quality_score_includes_source_type_bonus(): void
    {
        $service = app(EventIngestionService::class);

        $venueSource = Source::create([
            'name' => 'Venue Source',
            'slug' => 'venue-source',
            'url' => 'https://venue.com',
            'scraper_class' => 'App\\Services\\Scrapers\\VenueScraper',
            'is_active' => true,
            'type' => SourceType::VENUE, // priority = 90 -> bonus = 4.5
        ]);

        $aggregatorSource = Source::create([
            'name' => 'Aggregator Source',
            'slug' => 'aggregator-source',
            'url' => 'https://aggregator.com',
            'scraper_class' => 'App\\Services\\Scrapers\\AggregatorScraper',
            'is_active' => true,
            'type' => SourceType::AGGREGATOR, // priority = 50 -> bonus = 2.5
        ]);

        $eventVenue = Event::create([
            'title' => 'Venue Event',
            'description' => 'Test event description',
            'start_at' => Carbon::now()->addDays(2),
            'source_id' => $venueSource->id,
            'source_slug' => $venueSource->slug,
        ]);

        $eventAggregator = Event::create([
            'title' => 'Aggregator Event',
            'description' => 'Test event description',
            'start_at' => Carbon::now()->addDays(2),
            'source_id' => $aggregatorSource->id,
            'source_slug' => $aggregatorSource->slug,
        ]);

        $scoreVenue = $service->calculateCandidateQualityScore($eventVenue);
        $scoreAggregator = $service->calculateCandidateQualityScore($eventAggregator);

        $this->assertGreaterThan($scoreAggregator, $scoreVenue);
    }

    public function test_cli_scrape_filter_by_type(): void
    {
        $this->artisan('events:scrape', ['--type' => 'thematic', '--dry-run' => true])
            ->assertExitCode(0);
    }

    public function test_admin_sources_view_filtering_by_type(): void
    {
        $adminRole = \App\Models\Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $user = \App\Models\User::factory()->create();
        $user->assignRole($adminRole);

        Source::create([
            'name' => 'Venue Alpha',
            'slug' => 'venue-alpha',
            'url' => 'https://venue-alpha.com',
            'scraper_class' => 'App\\Services\\Scrapers\\VenueScraper',
            'is_active' => true,
            'type' => SourceType::VENUE,
        ]);

        Source::create([
            'name' => 'Ticketing Beta',
            'slug' => 'ticketing-beta',
            'url' => 'https://ticketing-beta.com',
            'scraper_class' => 'App\\Services\\Scrapers\\TicketingScraper',
            'is_active' => true,
            'type' => SourceType::TICKETING,
        ]);

        // Filter by venue
        $response = $this->actingAs($user)->get(route('events.sources', ['type' => 'venue']));
        $response->assertStatus(200);
        $response->assertSee('Venue Alpha');
        $response->assertDontSee('Ticketing Beta');

        // All sources
        $allResponse = $this->actingAs($user)->get(route('events.sources'));
        $allResponse->assertStatus(200);
        $allResponse->assertSee('Venue Alpha');
        $allResponse->assertSee('Ticketing Beta');
    }
}
