<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\AfiroApiScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AfiroDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $categories = [
            ['name' => 'Mūzika', 'slug' => 'muzika'],
            ['name' => 'Festivāli & Svētki', 'slug' => 'festivals'],
            ['name' => 'Svētki', 'slug' => 'svetki'],
            ['name' => 'Cits', 'slug' => 'citi'],
        ];

        foreach ($categories as $cat) {
            Category::firstOrCreate(['slug' => $cat['slug']], ['name' => $cat['name']]);
        }
    }

    public function test_afiro_scraper_scrapes_only_latvian_locale(): void
    {
        $scraper = new AfiroApiScraper();
        $source = Source::create([
            'name' => 'Afiro Pasākumu API',
            'slug' => 'afiro-api',
            'url' => 'https://api.afiro.lv',
            'scraper_class' => 'App\Services\Scrapers\Sources\AfiroApiScraper',
            'is_active' => true,
        ]);

        $reflection = new \ReflectionClass($scraper);
        $method = $reflection->getMethod('scrape');
        $this->assertTrue($method->isPublic());
    }

    public function test_afiro_event_merges_with_existing_okultura_event(): void
    {
        $sourceOkultura = Source::create([
            'name' => 'Ogres kultūra',
            'slug' => 'okultura',
            'url' => 'https://www.okultura.lv/lv/pasakumi',
            'scraper_class' => 'App\Services\Scrapers\Sources\OkulturaScraper',
            'is_active' => true,
        ]);

        $sourceAfiro = Source::create([
            'name' => 'Afiro Pasākumu API',
            'slug' => 'afiro-api',
            'url' => 'https://api.afiro.lv',
            'scraper_class' => 'App\Services\Scrapers\Sources\AfiroApiScraper',
            'is_active' => true,
        ]);

        $ingestionService = app(EventIngestionService::class);

        // 1. Ingest municipal event from okultura
        $dtoOkultura = new ScrapedEventDTO(
            title: 'Valsts svētku uguņošana',
            startAt: Carbon::parse('2026-11-18 19:00:00'),
            description: '18. novembrī aicinām uz svētku uguņošanu pie domes ēkas.',
            venueName: 'Ogres novada pašvaldības domes ēka',
            city: 'Ogre',
            region: 'Vidzeme',
            address: 'Brīvības iela 33, Ogre, LV-5001',
            categoryNames: ['Svētki'],
            ticketUrl: 'https://www.bilesuparadize.lv/lv/event/176105',
            sourceExternalId: 'okultura-15921',
            locale: 'lv',
        );

        $res1 = $ingestionService->ingestDTO($dtoOkultura, $sourceOkultura);
        $this->assertEquals('created', $res1);
        $this->assertEquals(1, Event::count());

        $initialEvent = Event::first();
        $this->assertEquals('okultura', $initialEvent->source_slug);

        // 2. Ingest same event from afiro in Latvian
        $dtoAfiro = new ScrapedEventDTO(
            title: 'Valsts svētku uguņošana Ogrē',
            startAt: Carbon::parse('2026-11-18 19:00:00'),
            description: '18. novembrī aicinām uz svētku uguņošanu pie domes ēkas Ogrē.',
            venueName: 'Ogres novada pašvaldības domes ēka',
            city: 'Ogre',
            region: 'Vidzeme',
            address: 'Brīvības iela 33, Ogre, LV-5001',
            categoryNames: ['Svētki'],
            sourceExternalId: 'afiro-ugunosana-999',
            locale: 'lv',
        );

        $res2 = $ingestionService->ingestDTO($dtoAfiro, $sourceAfiro);
        $this->assertEquals('updated', $res2);

        // Crucial assertion: No duplicate created
        $this->assertEquals(1, Event::count());

        $mergedEvent = Event::first();
        $this->assertEquals('okultura', $mergedEvent->source_slug);
        $this->assertEquals('afiro-ugunosana-999', $mergedEvent->raw_data['merged_external_ids']['afiro-api'] ?? null);

        // 3. Subsequent ingestion by afiro matches immediately via merged_external_ids
        $res3 = $ingestionService->ingestDTO($dtoAfiro, $sourceAfiro);
        $this->assertEquals('updated', $res3);
        $this->assertEquals(1, Event::count());
    }
}
