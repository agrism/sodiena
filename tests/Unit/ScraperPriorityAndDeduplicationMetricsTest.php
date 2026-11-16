<?php

namespace Tests\Unit;

use App\Enums\SourceType;
use App\Models\Category;
use App\Models\Event;
use App\Models\Location;
use App\Models\ScrapeLog;
use App\Models\Source;
use App\Services\Scrapers\Contracts\EventScraperInterface;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class PrimaryScraperStub implements EventScraperInterface
{
    public function getSlug(): string
    {
        return 'primary-stub';
    }

    public function getName(): string
    {
        return 'Primary Stub';
    }

    public function scrape(Source $source): Collection
    {
        return collect([
            new ScrapedEventDTO(
                title: 'Valsts svētku ugunsdzēšana',
                startAt: Carbon::create(2026, 11, 18, 19, 0, 0, 'Europe/Riga'),
                endAt: Carbon::create(2026, 11, 18, 21, 0, 0, 'Europe/Riga'),
                description: 'Svinīgs pasākums primārajā vietā',
                shortDescription: 'Svinīgs pasākums',
                venueName: 'Ogres kultūras centrs',
                city: 'Ogre',
                region: 'Vidzeme',
                address: 'Brīvības iela 15',
                placeType: 'venue',
                categoryNames: ['Svētki'],
                entertainmentType: 'celebration',
                isFree: true,
                imageUrl: 'https://example.com/primary.jpg',
                sourceUrl: 'https://primary.example.com/event/1',
                sourceExternalId: 'p_100',
                locale: 'lv'
            )
        ]);
    }
}

class AggregatorScraperStub implements EventScraperInterface
{
    public function getSlug(): string
    {
        return 'aggregator-stub';
    }

    public function getName(): string
    {
        return 'Aggregator Stub';
    }

    public function scrape(Source $source): Collection
    {
        // Scrapes the exact same event as primary stub, but coming from an aggregator
        return collect([
            new ScrapedEventDTO(
                title: 'Valsts svētku ugunsdzēšana',
                startAt: Carbon::create(2026, 11, 18, 19, 0, 0, 'Europe/Riga'),
                endAt: Carbon::create(2026, 11, 18, 21, 0, 0, 'Europe/Riga'),
                description: 'Svinīgs pasākums agregatorā',
                shortDescription: 'Svinīgs pasākums',
                venueName: 'Ogres KC',
                city: 'Ogre',
                region: 'Vidzeme',
                address: 'Brīvības iela 15',
                placeType: 'venue',
                categoryNames: ['Svētki'],
                entertainmentType: 'celebration',
                isFree: true,
                imageUrl: 'https://example.com/aggregator.jpg',
                sourceUrl: 'https://aggregator.example.com/event/100',
                sourceExternalId: 'agg_999',
                locale: 'lv'
            )
        ]);
    }
}

class ScraperPriorityAndDeduplicationMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sources_order_by_priority_places_venues_first_and_aggregators_last(): void
    {
        $agg = Source::create([
            'name' => 'Kultūras Dati',
            'slug' => 'kd-agg',
            'url' => 'https://kd.example.com',
            'scraper_class' => AggregatorScraperStub::class,
            'type' => SourceType::AGGREGATOR,
            'is_active' => true,
        ]);

        $muni = Source::create([
            'name' => 'Ogres Novads',
            'slug' => 'ogres-muni',
            'url' => 'https://ogre.example.com',
            'scraper_class' => PrimaryScraperStub::class,
            'type' => SourceType::MUNICIPALITY,
            'is_active' => true,
        ]);

        $venue = Source::create([
            'name' => 'Ogres Teātris',
            'slug' => 'ogres-venue',
            'url' => 'https://teatris.example.com',
            'scraper_class' => PrimaryScraperStub::class,
            'type' => SourceType::VENUE,
            'is_active' => true,
        ]);

        $sports = Source::create([
            'name' => 'Basket Lv',
            'slug' => 'basket-sports',
            'url' => 'https://basket.example.com',
            'scraper_class' => PrimaryScraperStub::class,
            'type' => SourceType::SPORTS,
            'is_active' => true,
        ]);

        $ordered = Source::orderByPriority()->pluck('slug')->all();

        $this->assertEquals([
            'ogres-venue',    // 90
            'basket-sports',  // 85
            'ogres-muni',     // 70
            'kd-agg',         // 50
        ], $ordered);
    }

    public function test_ingestion_counts_deduplicated_events_and_saves_metrics_to_source_and_log(): void
    {
        Category::updateOrCreate(['slug' => 'citi'], ['name' => 'Cits']);

        $primarySource = Source::create([
            'name' => 'Ogres Kultūras Nams',
            'slug' => 'ogres-kc',
            'url' => 'https://ogreskc.lv',
            'scraper_class' => PrimaryScraperStub::class,
            'type' => SourceType::VENUE,
            'is_active' => true,
        ]);

        $aggregatorSource = Source::create([
            'name' => 'Afiro Agregators',
            'slug' => 'afiro-agg',
            'url' => 'https://afiro.example.com',
            'scraper_class' => AggregatorScraperStub::class,
            'type' => SourceType::AGGREGATOR,
            'is_active' => true,
        ]);

        /** @var EventIngestionService $service */
        $service = app(EventIngestionService::class);

        // 1. Run primary scraper: should create the event
        $primaryLog = $service->ingest($primarySource);

        $this->assertEquals(1, $primaryLog->items_found);
        $this->assertEquals(1, $primaryLog->items_created);
        $this->assertEquals(0, $primaryLog->items_updated);
        $this->assertEquals(0, $primaryLog->items_deduplicated);
        $this->assertEquals(1, Event::count());
        $this->assertEquals(0, $primarySource->fresh()->duplicates_prevented_count);

        // 2. Run aggregator scraper: finds the existing event, avoids duplicate and counts as deduplicated
        $aggLog = $service->ingest($aggregatorSource);

        $this->assertEquals(1, $aggLog->items_found);
        $this->assertEquals(0, $aggLog->items_created);
        $this->assertEquals(0, $aggLog->items_updated);
        $this->assertEquals(1, $aggLog->items_deduplicated);

        // Still only 1 event in total in DB (no duplicate created!)
        $this->assertEquals(1, Event::count());

        // Event remains owned by primary authoritative venue source
        $event = Event::first();
        $this->assertEquals($primarySource->id, $event->source_id);

        // Verify deduplication metric was saved on aggregator source record
        $this->assertEquals(1, $aggregatorSource->fresh()->duplicates_prevented_count);

        // Merged external ID was tracked
        $mergedIds = $event->raw_data['merged_external_ids'] ?? [];
        $this->assertArrayHasKey('afiro-agg', $mergedIds);
        $this->assertEquals('agg_999', $mergedIds['afiro-agg']);
    }
}
