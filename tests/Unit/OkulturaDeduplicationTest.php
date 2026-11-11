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

class OkulturaDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed basic categories
        $categories = [
            ['name' => 'Mūzika', 'slug' => 'muzika'],
            ['name' => 'Kino', 'slug' => 'kino'],
            ['name' => 'Teātris', 'slug' => 'teatris'],
            ['name' => 'Festivāli & Svētki', 'slug' => 'festivals'],
            ['name' => 'Māksla un izstādes', 'slug' => 'izstades'],
            ['name' => 'Cits', 'slug' => 'citi'],
        ];

        foreach ($categories as $cat) {
            Category::firstOrCreate(['slug' => $cat['slug']], ['name' => $cat['name']]);
        }
    }

    public function test_deduplication_between_ogres_novads_and_okultura(): void
    {
        $sourceNovads = Source::create([
            'name' => 'Ogres novada pašvaldība',
            'slug' => 'ogres-novads',
            'url' => 'https://www.ogresnovads.lv/lv/notikumu-kalendars',
            'scraper_class' => 'App\Services\Scrapers\Sources\OgresNovadsScraper',
            'is_active' => true,
        ]);

        $sourceOkultura = Source::create([
            'name' => 'Ogres kultūra',
            'slug' => 'okultura',
            'url' => 'https://www.okultura.lv/lv/pasakumi',
            'scraper_class' => 'App\Services\Scrapers\Sources\OkulturaScraper',
            'is_active' => true,
        ]);

        $ingestionService = app(EventIngestionService::class);

        // 1. Ingest event from ogres-novads
        $dtoNovads = new ScrapedEventDTO(
            title: 'Koncerts VILIM ar pūtēju orķestri',
            startAt: Carbon::parse('2026-11-14 16:00:00'),
            description: 'Pūtēju orķestra koncerts Ogres kultūras centrā.',
            venueName: 'Ogres novada Kultūras centrs, Lielā zāle',
            city: 'Ogre',
            region: 'Vidzeme',
            address: 'Brīvības iela 15, Ogre',
            categoryNames: ['Mūzika'],
            entertainmentType: 'concert',
            sourceExternalId: 'ogresnovads-koncerts-vilim',
        );

        $res1 = $ingestionService->ingestDTO($dtoNovads, $sourceNovads);
        $this->assertEquals('created', $res1);
        $this->assertEquals(1, Event::count());

        $initialEvent = Event::first();
        $this->assertNull($initialEvent->ticket_url);

        // 2. Ingest same event from okultura with full details, ticket URL, and price
        $dtoOkultura = new ScrapedEventDTO(
            title: 'Koncerts VILIM ar pūtēju orķestri',
            startAt: Carbon::parse('2026-11-14 16:00:00'),
            description: 'Koncertā piedalās Ogres novada Kultūras centra pūtēju orķestris. Diriģents...',
            venueName: 'Ogres novada Kultūras centrs, Lielā zāle',
            city: 'Ogre',
            region: 'Vidzeme',
            address: 'Brīvības iela 15, Ogre, LV-5001',
            categoryNames: ['Mūzika'],
            entertainmentType: 'concert',
            priceMin: 5.0,
            priceMax: 7.0,
            ticketUrl: 'https://www.bilesuparadize.lv/lv/event/179325',
            sourceExternalId: 'okultura-15914',
        );

        $res2 = $ingestionService->ingestDTO($dtoOkultura, $sourceOkultura);
        $this->assertEquals('updated', $res2);

        // Crucial Assertion: NO DUPLICATE CREATED
        $this->assertEquals(1, Event::count());

        $mergedEvent = Event::first();
        $this->assertEquals('https://www.bilesuparadize.lv/lv/event/179325', $mergedEvent->ticket_url);
        $this->assertEquals(5.0, (float) $mergedEvent->price_min);
        $this->assertEquals(7.0, (float) $mergedEvent->price_max);
        $this->assertStringContainsString('Koncertā piedalās Ogres novada Kultūras centra pūtēju orķestris', $mergedEvent->description);
        $this->assertStringContainsString('Diriģents', $mergedEvent->description);
    }

    public function test_postponed_event_deduplication_via_ticket_url(): void
    {
        $sourceParadize = Source::create([
            'name' => 'Biļešu Paradīze',
            'slug' => 'bilesu-paradize',
            'url' => 'https://www.bilesuparadize.lv',
            'scraper_class' => 'App\Services\Scrapers\Sources\BilesuParadizeScraper',
            'is_active' => true,
        ]);

        $sourceOkultura = Source::create([
            'name' => 'Ogres kultūra',
            'slug' => 'okultura',
            'url' => 'https://www.okultura.lv/lv/pasakumi',
            'scraper_class' => 'App\Services\Scrapers\Sources\OkulturaScraper',
            'is_active' => true,
        ]);

        $ingestionService = app(EventIngestionService::class);

        // 1. Initial event ingested with old date 2026-11-12
        $dtoInitial = new ScrapedEventDTO(
            title: 'PIE LAIKA | brīvības dziesmas',
            startAt: Carbon::parse('2026-11-12 19:00:00'),
            description: 'Koncertprogramma veltīta Latvijas brīvībai.',
            venueName: 'Ogres novada Kultūras centrs, Lielā zāle',
            city: 'Ogre',
            ticketUrl: 'https://www.bilesuparadize.lv/lv/event/170768',
            sourceExternalId: 'bp-170768',
        );

        $ingestionService->ingestDTO($dtoInitial, $sourceParadize);
        $this->assertEquals(1, Event::count());
        $this->assertEquals('2026-11-12 19:00:00', Event::first()->start_at->toDateTimeString());

        // 2. okultura scraper runs, detects postponement to 2027-05-05, shares same ticket URL
        $dtoPostponed = new ScrapedEventDTO(
            title: '...PIE LAIKA | brīvības dziesmas',
            startAt: Carbon::parse('2027-05-05 19:00:00'),
            description: 'Koncerts pārcelts uz 2027. gada 5. maiju plkst. 19.00. Iegādātās biļetes derīgas.',
            venueName: 'Ogres novada Kultūras centrs, Lielā zāle',
            city: 'Ogre',
            ticketUrl: 'https://www.bilesuparadize.lv/lv/event/170768',
            sourceExternalId: 'okultura-15821',
        );

        $res = $ingestionService->ingestDTO($dtoPostponed, $sourceOkultura);
        $this->assertEquals('updated', $res);

        // Crucial: Must NOT create duplicate, must update date to postponed date
        $this->assertEquals(1, Event::count());
        $updatedEvent = Event::first();
        $this->assertEquals('2027-05-05 19:00:00', $updatedEvent->start_at->toDateTimeString());
        $this->assertStringContainsString('Koncerts pārcelts uz 2027. gada 5. maiju', $updatedEvent->description);
    }

    public function test_title_case_variation_deduplication(): void
    {
        $sourceNovads = Source::firstOrCreate(
            ['slug' => 'ogres-novads'],
            ['name' => 'Ogres novads', 'url' => 'https://ogresnovads.lv', 'scraper_class' => 'App\Services\Scrapers\Sources\OgresNovadsScraper', 'is_active' => true]
        );

        $sourceOkultura = Source::firstOrCreate(
            ['slug' => 'okultura'],
            ['name' => 'Ogres kultūra', 'url' => 'https://okultura.lv', 'scraper_class' => 'App\Services\Scrapers\Sources\OkulturaScraper', 'is_active' => true]
        );

        $ingestionService = app(EventIngestionService::class);

        // 1. Lowercase/Capitalized from ogresnovads
        $dto1 = new ScrapedEventDTO(
            title: 'Lāčplēša diena Ogrē',
            startAt: Carbon::parse('2026-11-11 17:30:00'),
            venueName: 'Ogres pilsētvide',
            city: 'Ogre',
            categoryNames: ['Festivāli & Svētki'],
            sourceExternalId: 'novads-lacplesa-diena',
        );
        $ingestionService->ingestDTO($dto1, $sourceNovads);
        $this->assertEquals(1, Event::count());

        // 2. ALL CAPS from okultura
        $dto2 = new ScrapedEventDTO(
            title: 'LĀČPLĒŠA DIENA OGRĒ',
            startAt: Carbon::parse('2026-11-11 17:30:00'),
            venueName: 'Ogres pilsētvide',
            city: 'Ogre',
            categoryNames: ['Festivāli & Svētki'],
            sourceExternalId: 'okultura-15899',
        );
        $res = $ingestionService->ingestDTO($dto2, $sourceOkultura);
        $this->assertEquals('updated', $res);
        $this->assertEquals(1, Event::count());
    }

    public function test_municipal_schedule_discrepancy_merges_when_same_location(): void
    {
        $sourceNovads = Source::firstOrCreate(
            ['slug' => 'ogres-novads'],
            ['name' => 'Ogres novads', 'url' => 'https://ogresnovads.lv', 'scraper_class' => 'App\Services\Scrapers\Sources\OgresNovadsScraper', 'is_active' => true]
        );

        $sourceVisit = Source::firstOrCreate(
            ['slug' => 'visitogre'],
            ['name' => 'Visit Ogre', 'url' => 'https://visitogre.lv', 'scraper_class' => 'App\Services\Scrapers\Sources\VisitOgreScraper', 'is_active' => true]
        );

        $ingestionService = app(EventIngestionService::class);

        // 1. VisitOgre has 18:00 in Taurupes Tautas nams
        $dto1 = new ScrapedEventDTO(
            title: 'Latvijas Republikas proklamēšanas dienai veltīts koncerts kopā ar Žoržu Siksnu Taurupē',
            startAt: Carbon::parse('2026-11-16 18:00:00'),
            venueName: 'Taurupes Tautas nams',
            city: 'Taurupe',
            region: 'Vidzeme',
            categoryNames: ['Mūzika'],
            sourceExternalId: 'visitogre-4588',
        );
        $ingestionService->ingestDTO($dto1, $sourceVisit);
        $this->assertEquals(1, Event::count());

        // 2. Ogres novads has 19:00 for the same concert
        $dto2 = new ScrapedEventDTO(
            title: 'Latvijas Republikas proklamēšanas dienai veltīts koncerts kopā ar Žoržu Siksnu Taurupē',
            startAt: Carbon::parse('2026-11-16 19:00:00'),
            venueName: 'Taurupes Tautas nams',
            city: 'Taurupe',
            region: 'Vidzeme',
            categoryNames: ['Mūzika'],
            sourceExternalId: 'novads-zorzs-siksna-taurupe',
        );
        $res = $ingestionService->ingestDTO($dto2, $sourceNovads);
        $this->assertEquals('updated', $res);
        $this->assertEquals(1, Event::count(), 'Identical concert with 60 min schedule discrepancy must merge');
    }

    public function test_generic_center_merges_with_specific_parish_when_descriptions_match(): void
    {
        $sourceNovads = Source::firstOrCreate(
            ['slug' => 'ogres-novads'],
            ['name' => 'Ogres novads', 'url' => 'https://ogresnovads.lv', 'scraper_class' => 'App\Services\Scrapers\Sources\OgresNovadsScraper', 'is_active' => true]
        );

        $sourceVisit = Source::firstOrCreate(
            ['slug' => 'visitogre'],
            ['name' => 'Visit Ogre', 'url' => 'https://visitogre.lv', 'scraper_class' => 'App\Services\Scrapers\Sources\VisitOgreScraper', 'is_active' => true]
        );

        $ingestionService = app(EventIngestionService::class);

        $flyerText = "17. novembrī no plkst. 22.00 līdz 3.00 Valsts svētku balle ar grupu “Zelta kniede”. Galdiņu rezervācija (obligāta): 22408067 Ieeja 5,00EUR";

        // 1. VisitOgre has generic default city Ogre
        $dto1 = new ScrapedEventDTO(
            title: 'Valsts svētku balle ar grupu “Zelta kniede”',
            startAt: Carbon::parse('2026-11-17 22:00:00'),
            description: $flyerText,
            venueName: 'Ogres novada sporta centrs',
            city: 'Ogre',
            region: 'Vidzeme',
            categoryNames: ['Festivāli & Svētki'],
            sourceExternalId: 'visitogre-4624',
        );
        $ingestionService->ingestDTO($dto1, $sourceVisit);
        $this->assertEquals(1, Event::count());

        // 2. Ogres novads has specific parish Mazozoli
        $dto2 = new ScrapedEventDTO(
            title: 'Valsts svētku balle ar grupu “Zelta kniede”',
            startAt: Carbon::parse('2026-11-17 22:00:00'),
            description: $flyerText,
            venueName: 'Mazozolu pagasta Kultūras nams',
            city: 'Mazozoli',
            region: 'Vidzeme',
            categoryNames: ['Festivāli & Svētki'],
            sourceExternalId: 'novads-valsts-svetku-balle-mazozoli',
        );
        $res = $ingestionService->ingestDTO($dto2, $sourceNovads);
        $this->assertEquals('updated', $res);
        $this->assertEquals(1, Event::count(), 'Identical flyer description with generic candidate city must merge and adopt parish');
    }
}
