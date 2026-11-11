<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Event;
use App\Models\Location;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OgresMuzejsDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Category::firstOrCreate(['slug' => 'izstades'], ['name' => 'Māksla un izstādes']);
    }

    public function test_deduplication_between_ogres_novads_and_ogres_muzejs(): void
    {
        $sourceNovads = Source::create([
            'name' => 'Ogres novada pašvaldība',
            'slug' => 'ogres-novads',
            'url' => 'https://www.ogresnovads.lv/lv/notikumu-kalendars',
            'scraper_class' => 'App\Services\Scrapers\Sources\OgresNovadsScraper',
            'is_active' => true,
        ]);

        $sourceMuzejs = Source::create([
            'name' => 'Ogres Vēstures un mākslas muzejs',
            'slug' => 'ogres-muzejs',
            'url' => 'https://ogresmuzejs.lv/piedavajums',
            'scraper_class' => 'App\Services\Scrapers\Sources\OgresMuzejsScraper',
            'is_active' => true,
        ]);

        $ingestionService = app(EventIngestionService::class);

        // 1. Ingest unpacked exhibition from ogres-novads accordion
        $dtoNovads = new ScrapedEventDTO(
            title: 'Madaras Rutkēvičas personālizstāde “PĒCSTUNDAS” Ogres Vēstures un mākslas muzejā līdz 27. decembrim',
            startAt: Carbon::parse('2026-09-25 10:00:00'),
            endAt: Carbon::parse('2026-12-27 17:00:00'),
            description: 'Ogres Vēstures un mākslas muzejā līdz 27. decembrim skatāma personālizstāde.',
            venueName: 'Ogres Vēstures un mākslas muzejs',
            city: 'Ogre',
            region: 'Vidzeme',
            address: 'Brīvības iela 36, Ogre, LV-5001',
            categoryNames: ['Māksla un izstādes'],
            entertainmentType: 'exhibition',
            sourceExternalId: 'ogresnovads-madaras-rutkevicas-personalizstade',
        );

        $res1 = $ingestionService->ingestDTO($dtoNovads, $sourceNovads);
        $this->assertEquals('created', $res1);
        $this->assertEquals(1, Event::count());

        // 2. Ingest exhibition from ogresmuzejs scraper
        $dtoMuzejs = new ScrapedEventDTO(
            title: 'Madaras Rutkēvičas personālizstāde “PĒCSTUNDAS”',
            startAt: Carbon::parse('2026-09-25 10:00:00'),
            endAt: Carbon::parse('2026-12-27 17:00:00'),
            description: 'No 25. septembra muzeja Mazajā zālē tika atklāta Madaras Rutkēvičas personālizstāde “PĒCSTUNDAS”. Plašs izstādes apraksts un mākslinieces biogrāfija...',
            venueName: 'Ogres Vēstures un mākslas muzejs, Mazā zāle',
            city: 'Ogre',
            region: 'Vidzeme',
            address: 'Brīvības iela 36, Ogre, LV-5001',
            categoryNames: ['Māksla un izstādes'],
            entertainmentType: 'exhibition',
            imageUrl: 'https://ogresmuzejs.lv/uploads/madara-large.jpg',
            sourceExternalId: 'ogresmuzejs-madaras-rutkevicas-izstade-svesa-liela-eka',
        );

        $res2 = $ingestionService->ingestDTO($dtoMuzejs, $sourceMuzejs);
        $this->assertEquals('updated', $res2);

        // Must NOT create duplicate!
        $this->assertEquals(1, Event::count());

        $merged = Event::first();
        $this->assertEquals('https://ogresmuzejs.lv/uploads/madara-large.jpg', $merged->image_url);
        $this->assertStringContainsString('Plašs izstādes apraksts', $merged->description);
    }
}
