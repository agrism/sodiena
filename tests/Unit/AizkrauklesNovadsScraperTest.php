<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\AizkrauklesNovadsScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AizkrauklesNovadsScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_aizkraukles_novads_scraper_metadata(): void
    {
        $scraper = new AizkrauklesNovadsScraper();
        $this->assertEquals('aizkraukles-novads', $scraper->getSlug());
        $this->assertStringContainsString('Aizkraukles', $scraper->getName());
    }

    public function test_aizkraukles_novads_date_parsing(): void
    {
        $scraper = new AizkrauklesNovadsScraper();

        // Single date with time
        [$start1, $end1] = $scraper->parseLatvianDates('14. septembris, 2026', '19.45-20.45');
        $this->assertEquals('2026-09-14 19:45:00', $start1->toDateTimeString());
        $this->assertEquals('2026-09-14 20:45:00', $end1->toDateTimeString());

        // Date range
        [$start2, $end2] = $scraper->parseLatvianDates('1. janvāris, 2026 – 31. decembris, 2026', 'Visu dienu');
        $this->assertEquals('2026-01-01 10:00:00', $start2->toDateTimeString());
        $this->assertEquals('2026-12-31 18:00:00', $end2->toDateTimeString());
    }

    public function test_aizkraukles_novads_location_resolving(): void
    {
        $scraper = new AizkrauklesNovadsScraper();

        // 3-part: City, Venue, Street
        $loc1 = $scraper->resolveLocation('Jaunjelgava, Jaunjegavas vidusskola, Uzvaras iela 1');
        $this->assertEquals('Jaunjelgava', $loc1['city']);
        $this->assertEquals('Jaunjegavas vidusskola', $loc1['name']);
        $this->assertStringContainsString('Uzvaras iela 1', $loc1['address']);

        // Koknese with quotes
        $loc2 = $scraper->resolveLocation('Koknese, Biedrības "Baltaine" radošā māja, Melioratoru iela 1A');
        $this->assertEquals('Koknese', $loc2['city']);
        $this->assertStringContainsString('Baltaine', $loc2['name']);

        // Remote / Online
        $loc3 = $scraper->resolveLocation('Attālināti');
        $this->assertEquals('Aizkraukle', $loc3['city']);
        $this->assertStringContainsString('Attālināti', $loc3['name']);
    }

    public function test_aizkraukles_novads_event_ingestion(): void
    {
        $source = Source::create([
            'name' => 'Aizkraukles novada pašvaldība',
            'slug' => 'aizkraukles-novads',
            'url' => 'https://www.aizkraukle.lv/lv/notikumu-kalendars',
            'scraper_class' => AizkrauklesNovadsScraper::class,
            'is_active' => true,
        ]);

        $dto = new ScrapedEventDTO(
            title: 'Vingrošanas nodarbība Jaunjelgavā',
            startAt: Carbon::parse('2026-09-14 19:45:00'),
            endAt: Carbon::parse('2026-09-14 20:45:00'),
            description: 'Pirmdienās norisināsies vingrošanas nodarbības.',
            shortDescription: 'Vingrošanas nodarbības.',
            venueName: 'Jaunjelgavas vidusskola',
            city: 'Jaunjelgava',
            region: 'Zemgale',
            address: 'Uzvaras iela 1, Jaunjelgava',
            placeType: 'venue',
            categoryNames: ['Sports & Aktīvā atpūta'],
            entertainmentType: 'active',
            isFree: true,
            imageUrl: 'https://www.aizkraukle.lv/files/vingrosana.png',
            sourceUrl: 'https://www.aizkraukle.lv/lv/notikums/vingrosanas-nodarbiba-79',
            sourceExternalId: 'aizkraukle-vingrosanas-nodarbiba-79',
            locale: 'lv',
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_external_id', 'aizkraukle-vingrosanas-nodarbiba-79')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Vingrošanas nodarbība Jaunjelgavā', $event->title);
        $this->assertEquals('aizkraukle.lv', $event->origin_host);
        $this->assertEquals('https://www.aizkraukle.lv/lv/notikums/vingrosanas-nodarbiba-79', $event->source_url);
        $this->assertEquals('Jaunjelgava', $event->location->city);
        $this->assertEquals('Zemgale', $event->location->region);
    }
}
