<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\DzejasDienasScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DzejasDienasScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_dzejas_dienas_scraper_metadata(): void
    {
        $scraper = new DzejasDienasScraper();
        $this->assertEquals('dzejas-dienas', $scraper->getSlug());
        $this->assertStringContainsString('Dzejas dienas', $scraper->getName());
    }

    public function test_dzejas_dienas_event_fuzzy_merging_with_existing_event(): void
    {
        $source = Source::create([
            'name' => 'Dzejas dienas',
            'slug' => 'dzejas-dienas',
            'url' => 'https://www.dzejasdienas.com/programma/',
            'scraper_class' => DzejasDienasScraper::class,
            'is_active' => true,
        ]);

        // Pre-existing event in DB from aggregator
        $existing = Event::create([
            'title' => 'Dzejas dienas “VĀRDS | SKAŅA | KRĀSA”',
            'slug' => 'dzejas-dienas-vards-skana-krasa-iSDzlG',
            'start_at' => Carbon::parse('2026-09-19 13:00:00'),
            'fingerprint' => 'test-fingerprint-dzejas-dienas-vards',
            'status' => 'published',
            'source_slug' => 'afiro-api',
            'source_url' => null,
        ]);

        $this->assertNull($existing->origin_url);
        $this->assertNull($existing->origin_host);

        // Scraped event DTO from dzejasdienas.com
        $dto = new ScrapedEventDTO(
            title: '“Vārds. Skaņa. Krāsa” Bulduros',
            startAt: Carbon::parse('2026-09-19 13:00:00'),
            endAt: null,
            description: 'Dzejas un mūzikas pasākums Bulduru lapenē pie jūras.',
            shortDescription: 'Dzejas un mūzikas pasākums Bulduru lapenē.',
            venueName: 'Bulduru lapene pie jūras',
            city: 'Jūrmala',
            region: 'Rīga un Pierīga',
            address: 'Bulduri, Jūrmala',
            latitude: 56.9839,
            longitude: 23.8569,
            placeType: 'culture_centre',
            categoryNames: ['Kultūra & Tradīcijas'],
            entertainmentType: 'culture',
            isFree: true,
            imageUrl: 'https://www.dzejasdienas.com/wp-content/uploads/2019/09/aplis-default-og-img.jpg',
            sourceUrl: 'https://www.dzejasdienas.com/programma/vards-skana-krasa-bulduros/',
            sourceExternalId: 'dzed-vards-skana-krasa-bulduros',
            locale: 'lv',
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('updated', $result);

        $fresh = $existing->fresh();
        $this->assertEquals('https://www.dzejasdienas.com/programma/vards-skana-krasa-bulduros/', $fresh->source_url);
        $this->assertEquals('dzejasdienas.com', $fresh->origin_host);
        $this->assertEquals('https://www.dzejasdienas.com/programma/vards-skana-krasa-bulduros/', $fresh->origin_url);
    }
}
