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
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OgreDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    protected Source $sourceNovads;
    protected Source $sourceOkultura;
    protected Source $sourceVisit;
    protected EventIngestionService $ingestionService;

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

        $this->sourceNovads = Source::firstOrCreate(
            ['slug' => 'ogres-novads'],
            [
                'name' => 'Ogres novada pašvaldība',
                'url' => 'https://www.ogresnovads.lv/lv/notikumu-kalendars',
                'scraper_class' => 'App\Services\Scrapers\Sources\OgresNovadsScraper',
                'is_active' => true,
            ]
        );

        $this->sourceOkultura = Source::firstOrCreate(
            ['slug' => 'okultura'],
            [
                'name' => 'Ogres novada Kultūras centrs',
                'url' => 'https://www.okultura.lv/lv/pasakumi',
                'scraper_class' => 'App\Services\Scrapers\Sources\OkulturaScraper',
                'is_active' => true,
            ]
        );

        $this->sourceVisit = Source::firstOrCreate(
            ['slug' => 'visitogre'],
            [
                'name' => 'Visit Ogre',
                'url' => 'https://www.visitogre.lv/lv/pasakumi',
                'scraper_class' => 'App\Services\Scrapers\Sources\VisitOgreScraper',
                'is_active' => true,
            ]
        );

        $this->ingestionService = app(EventIngestionService::class);
    }

    /**
     * Pair 1: Valsts svētku uguņošana vs Valsts svētku uguņošana Ogrē
     */
    public function test_valsts_svetku_ugunosana_deduplication(): void
    {
        // 1. Ingest from okultura
        $dtoOkultura = new ScrapedEventDTO(
            title: 'Valsts svētku uguņošana',
            startAt: Carbon::parse('2026-11-18 19:00:00'),
            endAt: Carbon::parse('2026-11-18 21:00:00'),
            description: 'Plkst. 19.00 Pie Ogres novada pašvaldības domes ēkas Brīvības ielā 33 SVĒTKU UGUŅOŠANA',
            venueName: 'Ogres novada pašvaldības domes ēka',
            city: 'Ogre',
            region: 'Vidzeme',
            address: 'Brīvības iela 33, Ogre, LV-5001',
            placeType: 'municipal',
            categoryNames: ['Festivāli & Svētki'],
            ticketUrl: 'https://www.bilesuparadize.lv/lv/event/176105',
            sourceExternalId: 'okultura-ugunosana-176105',
        );
        $res1 = $this->ingestionService->ingestDTO($dtoOkultura, $this->sourceOkultura);
        $this->assertEquals('created', $res1);
        $this->assertEquals(1, Event::count());

        // 2. Ingest from ogres-novads
        $dtoNovads = new ScrapedEventDTO(
            title: 'Valsts svētku uguņošana Ogrē',
            startAt: Carbon::parse('2026-11-18 19:00:00'),
            endAt: Carbon::parse('2026-11-18 21:00:00'),
            description: 'Valsts svētku uguņošana pie domes ēkas Brīvības ielā 33',
            venueName: 'Ogres novada pašvaldības domes ēka',
            city: 'Ogre',
            region: 'Vidzeme',
            address: 'Brīvības iela 33, Ogre, LV-5001',
            placeType: 'municipal',
            categoryNames: ['Festivāli & Svētki'],
            sourceExternalId: 'ogresnovads-valsts-svetku-ugunosana-ogre',
        );
        $res2 = $this->ingestionService->ingestDTO($dtoNovads, $this->sourceNovads);
        $this->assertEquals('deduplicated', $res2);
        $this->assertEquals(1, Event::count(), 'Valsts svētku uguņošana must deduplicate between okultura and ogres-novads');

        $event = Event::first();
        $this->assertStringContainsString('uguņošana', mb_strtolower($event->title));
        $this->assertEquals('https://www.bilesuparadize.lv/lv/event/176105', $event->ticket_url);
    }

    /**
     * Pair 2: Danču vakars kopā ar Ogres danču klubu Ogrē vs DANČU VAKARS kopā ar Ogres danču klubu
     */
    public function test_dancu_vakars_deduplication(): void
    {
        // 1. Ingest from visitogre (which has primary ticket 178863 and secondary ticket 178862 in raw_data)
        $dtoVisit = new ScrapedEventDTO(
            title: 'Danču vakars kopā ar Ogres danču klubu Ogrē',
            startAt: Carbon::parse('2026-11-20 19:00:00'),
            endAt: Carbon::parse('2026-11-20 21:00:00'),
            description: 'Ogres Danču klubs aicina uz danču vakaru',
            venueName: 'Ogres novada Kultūras centrs, Lielā zāle',
            city: 'Ogre',
            region: 'Vidzeme',
            address: 'Brīvības iela 15, Ogre, LV-5001',
            placeType: 'cultural_center',
            categoryNames: ['Mūzika'],
            ticketUrl: 'https://www.bilesuparadize.lv/lv/event/178863',
            sourceExternalId: 'visitogre-4632',
            rawData: [
                'ticket_links' => [
                    ['url' => 'https://www.bilesuparadize.lv/lv/event/178862', 'title' => 'Biļetes'],
                    ['url' => 'https://www.bilesuparadize.lv/lv/event/178863', 'title' => 'Biļetes'],
                ],
            ]
        );
        $res1 = $this->ingestionService->ingestDTO($dtoVisit, $this->sourceVisit);
        $this->assertEquals('created', $res1);
        $this->assertEquals(1, Event::count());

        // 2. Ingest from okultura with uppercase title and ticket 178862
        $dtoOkultura = new ScrapedEventDTO(
            title: 'DANČU VAKARS kopā ar Ogres danču klubu',
            startAt: Carbon::parse('2026-11-20 19:00:00'),
            endAt: Carbon::parse('2026-11-20 21:00:00'),
            description: 'Ogres Danču klubs aicina uz danču vakaru, kur dzīvās mūzikas pavadībā...',
            venueName: 'Ogres novada Kultūras centrs, Lielā zāle',
            city: 'Ogre',
            region: 'Vidzeme',
            address: 'Brīvības iela 15, Ogre, LV-5001',
            placeType: 'cultural_center',
            categoryNames: ['Mūzika'],
            ticketUrl: 'https://www.bilesuparadize.lv/lv/event/178862',
            sourceExternalId: 'okultura-dancu-vakars-178862',
        );
        $res2 = $this->ingestionService->ingestDTO($dtoOkultura, $this->sourceOkultura);
        $this->assertEquals('deduplicated', $res2);
        $this->assertEquals(1, Event::count(), 'Danču vakars must deduplicate between visitogre and okultura');
    }

    /**
     * Pair 3: Imantam Kalniņam 85 RAXTU RAXTI | VIENA SAULE, VIENA ZEME
     * vs Grupas “Raxtu Raxti” tūres “VIENA SAULE, VIENA ZEME” koncerts Ogrē
     */
    public function test_raxtu_raxti_jubilee_and_title_segments_deduplication(): void
    {
        // 1. Ingest from okultura with artist jubilee number "85"
        $dtoOkultura = new ScrapedEventDTO(
            title: 'Imantam Kalniņam 85 RAXTU RAXTI | VIENA SAULE, VIENA ZEME',
            startAt: Carbon::parse('2026-11-21 19:00:00'),
            endAt: Carbon::parse('2026-11-21 21:00:00'),
            description: 'Koncertprogramma veltīta komponista Imanta Kalniņa 85. jubilejai',
            venueName: 'Ogres novada Kultūras centrs, Lielā zāle',
            city: 'Ogre',
            region: 'Vidzeme',
            address: 'Brīvības iela 15, Ogre, LV-5001',
            placeType: 'cultural_center',
            categoryNames: ['Mūzika'],
            ticketUrl: 'https://www.bilesuparadize.lv/lv/event/167245',
            sourceExternalId: 'okultura-raxtu-raxti-167245',
        );
        $res1 = $this->ingestionService->ingestDTO($dtoOkultura, $this->sourceOkultura);
        $this->assertEquals('created', $res1);
        $this->assertEquals(1, Event::count());

        // 2. Ingest from visitogre without "85" and with quotes around "VIENA SAULE, VIENA ZEME"
        $dtoVisit = new ScrapedEventDTO(
            title: 'Grupas “Raxtu Raxti” tūres “VIENA SAULE, VIENA ZEME” koncerts Ogrē',
            startAt: Carbon::parse('2026-11-21 19:00:00'),
            endAt: Carbon::parse('2026-11-21 21:00:00'),
            description: 'Grupas Raxtu Raxti koncerts Ogrē',
            venueName: 'Ogres novada Kultūras centrs, Lielā zāle',
            city: 'Ogre',
            region: 'Vidzeme',
            address: 'Brīvības iela 15, Ogre, LV-5001',
            placeType: 'cultural_center',
            categoryNames: ['Mūzika'],
            ticketUrl: 'https://www.visitogre.lv/lv/pasakumi/4636/grupas-raxtu-raxti-tures-viena-saule-viena-zeme-koncerts-ogr',
            sourceExternalId: 'visitogre-4636',
        );
        $res2 = $this->ingestionService->ingestDTO($dtoVisit, $this->sourceVisit);
        $this->assertEquals('deduplicated', $res2);
        $this->assertEquals(1, Event::count(), 'Raxtu Raxti concert must deduplicate despite jubilee number 85');
    }

    /**
     * Pair 4: Spēlfilma “Tumšā robeža” Ogrē vs Spēlfilma TUMŠĀ ROBEŽA
     */
    public function test_spelfilma_tumsa_robeza_cinema_session_deduplication(): void
    {
        // 1. Ingest from okultura
        $dtoOkultura = new ScrapedEventDTO(
            title: 'Spēlfilma TUMŠĀ ROBEŽA',
            startAt: Carbon::parse('2026-11-25 19:00:00'),
            endAt: Carbon::parse('2026-11-25 21:00:00'),
            description: 'Kinoseanss Ogres novada Kultūras centrā',
            venueName: 'Ogres novada Kultūras centrs, Lielā zāle',
            city: 'Ogre',
            region: 'Vidzeme',
            address: 'Brīvības iela 15, Ogre, LV-5001',
            placeType: 'cultural_center',
            categoryNames: ['Kino'],
            ticketUrl: 'https://www.bilesuparadize.lv/lv/event/178848',
            sourceExternalId: 'okultura-tumsa-robeza-178848',
        );
        $res1 = $this->ingestionService->ingestDTO($dtoOkultura, $this->sourceOkultura);
        $this->assertEquals('created', $res1);
        $this->assertEquals(1, Event::count());

        // 2. Ingest from ogres-novads
        $dtoNovads = new ScrapedEventDTO(
            title: 'Spēlfilma “Tumšā robeža” Ogrē',
            startAt: Carbon::parse('2026-11-25 19:00:00'),
            endAt: Carbon::parse('2026-11-25 21:00:00'),
            description: 'Filmas seanss Ogres Kultūras centrā',
            venueName: 'Ogres novada Kultūras centrs, Lielā zāle',
            city: 'Ogre',
            region: 'Vidzeme',
            address: 'Brīvības iela 15, Ogre, LV-5001',
            placeType: 'cultural_center',
            categoryNames: ['Kino'],
            ticketUrl: 'https://www.ogresnovads.lv/lv/notikums/spelfilma-tumsa-robeza-ogre',
            sourceExternalId: 'ogresnovads-spelfilma-tumsa-robeza-ogre',
        );
        $res2 = $this->ingestionService->ingestDTO($dtoNovads, $this->sourceNovads);
        $this->assertEquals('deduplicated', $res2);
        $this->assertEquals(1, Event::count(), 'Tumšā robeža movie session must deduplicate');
    }

    /**
     * Test ConsolidateEventsCommand merges all 4 pairs when pre-existing in DB
     */
    public function test_events_consolidate_command_merges_all_four_pairs(): void
    {
        $locDomesEka = Location::create([
            'name' => 'Ogres novada pašvaldības domes ēka',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Brīvības iela 33, Ogre, LV-5001',
        ]);

        $locKulturasCentrs = Location::create([
            'name' => 'Ogres novada Kultūras centrs, Lielā zāle',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Brīvības iela 15, Ogre, LV-5001',
        ]);

        // Pair 1 pre-existing
        $p1a = Event::create([
            'title' => 'Valsts svētku uguņošana',
            'start_at' => Carbon::parse('2026-11-18 19:00:00'),
            'location_id' => $locDomesEka->id,
            'source_id' => $this->sourceOkultura->id,
            'source_slug' => 'okultura',
            'source_external_id' => 'okultura-p1',
            'ticket_url' => 'https://www.bilesuparadize.lv/lv/event/176105',
        ]);
        $p1b = Event::create([
            'title' => 'Valsts svētku uguņošana Ogrē',
            'start_at' => Carbon::parse('2026-11-18 19:00:00'),
            'location_id' => $locDomesEka->id,
            'source_id' => $this->sourceNovads->id,
            'source_slug' => 'ogres-novads',
            'source_external_id' => 'ogresnovads-p1',
            'ticket_url' => 'https://www.ogresnovads.lv/lv/notikums/valsts-svetku-ugunosana-ogre',
        ]);

        // Pair 2 pre-existing
        $p2a = Event::create([
            'title' => 'Danču vakars kopā ar Ogres danču klubu Ogrē',
            'start_at' => Carbon::parse('2026-11-20 19:00:00'),
            'location_id' => $locKulturasCentrs->id,
            'source_id' => $this->sourceVisit->id,
            'source_slug' => 'visitogre',
            'source_external_id' => 'visitogre-p2',
            'ticket_url' => 'https://www.bilesuparadize.lv/lv/event/178863',
            'raw_data' => [
                'ticket_links' => [
                    ['url' => 'https://www.bilesuparadize.lv/lv/event/178862'],
                ],
            ],
        ]);
        $p2b = Event::create([
            'title' => 'DANČU VAKARS kopā ar Ogres danču klubu',
            'start_at' => Carbon::parse('2026-11-20 19:00:00'),
            'location_id' => $locKulturasCentrs->id,
            'source_id' => $this->sourceOkultura->id,
            'source_slug' => 'okultura',
            'source_external_id' => 'okultura-p2',
            'ticket_url' => 'https://www.bilesuparadize.lv/lv/event/178862',
        ]);

        // Pair 3 pre-existing
        $p3a = Event::create([
            'title' => 'Imantam Kalniņam 85 RAXTU RAXTI | VIENA SAULE, VIENA ZEME',
            'start_at' => Carbon::parse('2026-11-21 19:00:00'),
            'location_id' => $locKulturasCentrs->id,
            'source_id' => $this->sourceOkultura->id,
            'source_slug' => 'okultura',
            'source_external_id' => 'okultura-p3',
            'ticket_url' => 'https://www.bilesuparadize.lv/lv/event/167245',
        ]);
        $p3b = Event::create([
            'title' => 'Grupas “Raxtu Raxti” tūres “VIENA SAULE, VIENA ZEME” koncerts Ogrē',
            'start_at' => Carbon::parse('2026-11-21 19:00:00'),
            'location_id' => $locKulturasCentrs->id,
            'source_id' => $this->sourceVisit->id,
            'source_slug' => 'visitogre',
            'source_external_id' => 'visitogre-p3',
            'ticket_url' => 'https://www.visitogre.lv/lv/pasakumi/4636',
        ]);

        // Pair 4 pre-existing
        $p4a = Event::create([
            'title' => 'Spēlfilma “Tumšā robeža” Ogrē',
            'start_at' => Carbon::parse('2026-11-25 19:00:00'),
            'location_id' => $locKulturasCentrs->id,
            'source_id' => $this->sourceNovads->id,
            'source_slug' => 'ogres-novads',
            'source_external_id' => 'ogresnovads-p4',
            'ticket_url' => 'https://www.ogresnovads.lv/lv/notikums/spelfilma-tumsa-robeza-ogre',
        ]);
        $p4b = Event::create([
            'title' => 'Spēlfilma TUMŠĀ ROBEŽA',
            'start_at' => Carbon::parse('2026-11-25 19:00:00'),
            'location_id' => $locKulturasCentrs->id,
            'source_id' => $this->sourceOkultura->id,
            'source_slug' => 'okultura',
            'source_external_id' => 'okultura-p4',
            'ticket_url' => 'https://www.bilesuparadize.lv/lv/event/178848',
        ]);

        // Before consolidation: exactly 8 events
        $this->assertEquals(8, Event::count());

        // Run consolidate command
        $exitCode = Artisan::call('events:consolidate', ['--days' => 30]);
        $this->assertEquals(0, $exitCode);

        // After consolidation: exactly 4 events active, 4 soft-deleted
        $this->assertEquals(4, Event::count());
        $this->assertEquals(8, Event::withTrashed()->count());

        // Verify merged external IDs are stored on canonical events
        $remaining = Event::all();
        foreach ($remaining as $event) {
            $this->assertNotNull($event->raw_data);
            $this->assertArrayHasKey('merged_external_ids', $event->raw_data);
        }
    }

    /**
     * Test that genuine number differences for multi-part events or subscriptions DO NOT merge
     */
    public function test_differing_part_numbers_remain_distinct(): void
    {
        $dtoPart1 = new ScrapedEventDTO(
            title: 'Lielais finālkoncerts: 1. daļa',
            startAt: Carbon::parse('2026-11-28 18:00:00'),
            venueName: 'Ogres novada Kultūras centrs, Lielā zāle',
            city: 'Ogre',
            sourceExternalId: 'novads-part-1',
        );
        $dtoPart2 = new ScrapedEventDTO(
            title: 'Lielais finālkoncerts: 2. daļa',
            startAt: Carbon::parse('2026-11-28 18:00:00'),
            venueName: 'Ogres novada Kultūras centrs, Lielā zāle',
            city: 'Ogre',
            sourceExternalId: 'okultura-part-2',
        );

        $this->ingestionService->ingestDTO($dtoPart1, $this->sourceNovads);
        $this->ingestionService->ingestDTO($dtoPart2, $this->sourceOkultura);

        // Crucial: Must be 2 separate events
        $this->assertEquals(2, Event::count());
    }
}
