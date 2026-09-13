<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\LatgalesGorsScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LatgalesGorsScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_latgales_gors_scraper_metadata(): void
    {
        $scraper = new LatgalesGorsScraper();
        $this->assertEquals('latgales-gors', $scraper->getSlug());
        $this->assertStringContainsString('GORS', $scraper->getName());
    }

    public function test_latgales_gors_event_ingestion(): void
    {
        $source = Source::create([
            'name' => 'Latgales vēstniecība GORS',
            'slug' => 'latgales-gors',
            'url' => 'https://www.latgalesgors.lv/lv/notikumi',
            'scraper_class' => LatgalesGorsScraper::class,
            'is_active' => true,
        ]);

        $dto = new ScrapedEventDTO(
            title: 'POEMA PAR O.R. Antonam Rupainim 120. Muzikāls stāsts',
            startAt: Carbon::parse('2026-09-18 18:00:00'),
            endAt: null,
            description: 'Šogad aprit 120 gadi kopš dzimis latgaliešu rakstnieks Antons Rupainis.',
            shortDescription: 'Muzikāls stāsts par Antonu Rupaini.',
            venueName: 'Latgales vēstniecība GORS',
            city: 'Rēzekne',
            region: 'Latgale',
            address: 'Pils iela 4, Rēzekne, LV-4601',
            latitude: 56.5028,
            longitude: 27.3323,
            placeType: 'concert_hall',
            categoryNames: ['Mūzika & Koncerti', 'Kultūra & Tradīcijas'],
            entertainmentType: 'concert',
            isFree: false,
            imageUrl: 'https://biletes.latgalesgors.lv/files/field/image/rupainis.jpg',
            sourceUrl: 'https://www.latgalesgors.lv/lv/poema-par-or-antonam-rupainim-120-koncertuzvedums',
            sourceExternalId: 'gors-poema-par-or-antonam-rupainim-120-koncertuzvedums',
            locale: 'lv',
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_external_id', 'gors-poema-par-or-antonam-rupainim-120-koncertuzvedums')->first();
        $this->assertNotNull($event);
        $this->assertEquals('POEMA PAR O.R. Antonam Rupainim 120. Muzikāls stāsts', $event->title);
        $this->assertEquals('latgalesgors.lv', $event->origin_host);
        $this->assertEquals('https://www.latgalesgors.lv/lv/poema-par-or-antonam-rupainim-120-koncertuzvedums', $event->source_url);
        $this->assertEquals('Rēzekne', $event->location->city);
        $this->assertEquals('Latgale', $event->location->region);
    }
}
