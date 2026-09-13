<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\JurmalasMuzejsScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JurmalasMuzejsScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_jurmalas_muzejs_scraper_metadata(): void
    {
        $scraper = new JurmalasMuzejsScraper();
        $this->assertEquals('jurmalas-muzejs', $scraper->getSlug());
        $this->assertStringContainsString('Jūrmalas muzejs', $scraper->getName());
    }

    public function test_jurmalas_muzejs_event_ingestion_and_source_url_update(): void
    {
        $source = Source::create([
            'name' => 'Jūrmalas muzejs',
            'slug' => 'jurmalas-muzejs',
            'url' => 'https://www.jurmalasmuzejs.lv/lv/notikumu-kalendars',
            'scraper_class' => JurmalasMuzejsScraper::class,
            'is_active' => true,
        ]);

        // Pre-existing event without source_url (like from initial aggregator)
        $existing = Event::create([
            'title' => 'Izstāde “Bilderingshof–Bilderliņi–Bulduri”',
            'slug' => 'izstade-bilderingshof-bilderlini-bulduri-test',
            'start_at' => Carbon::parse('2023-10-26 10:00:00'),
            'end_at' => Carbon::parse('2026-12-30 18:00:00'),
            'fingerprint' => 'test-fingerprint-bilderingshof',
            'status' => 'published',
            'source_slug' => 'afiro-api',
            'source_url' => null,
        ]);

        $this->assertNull($existing->origin_url);
        $this->assertNull($existing->origin_host);

        // Simulated scraped DTO directly from Jurmalas Muzejs calendar
        $dto = new ScrapedEventDTO(
            title: 'Izstāde “Bilderingshof–Bilderliņi–Bulduri”',
            startAt: Carbon::parse('2023-10-26 10:00:00'),
            endAt: Carbon::parse('2026-12-30 18:00:00'),
            description: 'Bulduru Izstāžu namā skatāma ar Bulduru apkaimes vēstures izpēti saistīta izstāde.',
            shortDescription: 'Bulduru Izstāžu namā skatāma vēstures izstāde.',
            venueName: 'Bulduru Izstāžu nams',
            city: 'Jūrmala',
            region: 'Rīga un Pierīga',
            address: 'Muižas iela 6, Jūrmala',
            latitude: 56.9839,
            longitude: 23.8569,
            placeType: 'museum',
            categoryNames: ['Izstādes & Māksla'],
            entertainmentType: 'exhibition',
            isFree: true,
            imageUrl: 'https://www.jurmalasmuzejs.lv/files/gallery_images/bilderini_0.jpg',
            sourceUrl: 'https://www.jurmalasmuzejs.lv/lv/notikums/izstade-bilderingshof-bilderlini-bulduri',
            sourceExternalId: 'jm-izstade-bilderingshof-bilderlini-bulduri',
            locale: 'lv',
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('updated', $result);

        $fresh = $existing->fresh();
        $this->assertEquals('https://www.jurmalasmuzejs.lv/lv/notikums/izstade-bilderingshof-bilderlini-bulduri', $fresh->source_url);
        $this->assertEquals('jurmalasmuzejs.lv', $fresh->origin_host);
        $this->assertEquals('https://www.jurmalasmuzejs.lv/lv/notikums/izstade-bilderingshof-bilderlini-bulduri', $fresh->origin_url);
    }
}
