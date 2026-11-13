<?php

namespace Tests\Unit;

use App\Enums\SourceType;
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

class SiguldaDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Category::firstOrCreate(['slug' => 'kino'], ['name' => 'Kino']);
        Category::firstOrCreate(['slug' => 'teatris'], ['name' => 'Teātris']);
        Category::firstOrCreate(['slug' => 'citi'], ['name' => 'Cits']);
    }

    public function test_ticketing_url_canonicalization_and_equivalence(): void
    {
        // 1. Canonicalization strips tracking params & trailing slashes
        $urlWithTracking = 'https://www.bilesuserviss.lv/lat/biletes/kino/filma/tumsa-robeza-487667/?utm_source=afiro&ref=123';
        $canonical = EventIngestionService::canonicalizeTicketUrl($urlWithTracking);
        $this->assertEquals('https://www.bilesuserviss.lv/lat/biletes/kino/filma/tumsa-robeza-487667', $canonical);

        // 2. BezRindas base event URL matches session URL
        $bezrindasBase = 'https://www.bezrindas.lv/lv/jutu-imperija-stand-up-izrade/15786/';
        $bezrindasSession = 'https://www.bezrindas.lv/lv/jutu-imperija-stand-up-izrade/15786/97356';
        $this->assertTrue(EventIngestionService::areTicketingUrlsEquivalent($bezrindasBase, $bezrindasSession));

        // 3. Biļešu Serviss cross-locale with tracking params
        $bsLat = 'https://www.bilesuserviss.lv/lat/biletes/kino/filma/tumsa-robeza-487667/';
        $bsRus = 'https://www.bilesuserviss.lv/rus/biletes/kino/filma/tumsa-robeza-487667/?utm_source=afiro';
        $this->assertTrue(EventIngestionService::areTicketingUrlsEquivalent($bsLat, $bsRus));

        // 4. Different events do not match
        $otherBs = 'https://www.bilesuserviss.lv/lat/biletes/kino/filma/cita-filma-111111/';
        $this->assertFalse(EventIngestionService::areTicketingUrlsEquivalent($bsLat, $otherBs));
    }

    public function test_latvian_stemming_handles_inflections(): void
    {
        $stems1 = EventIngestionService::getLatvianWordStems('Aleksandrs Guzenko stāvizrāde');
        $stems2 = EventIngestionService::getLatvianWordStems('Aleksandra Guzenko stand up izrāde');

        $this->assertContains('aleksandr', $stems1);
        $this->assertContains('aleksandr', $stems2);
        $this->assertContains('guzenk', $stems1);
        $this->assertContains('guzenk', $stems2);
    }

    public function test_title_segments_extraction(): void
    {
        $title1 = 'Aleksandrs Guzenko stāvizrāde “Jūtu impērija” Siguldas Kultūras namā';
        $segments1 = EventIngestionService::extractTitleSegments($title1);
        $this->assertContains('jūtu impērija', $segments1);

        $title2 = 'Jūtu impērija - stand up izrāde';
        $segments2 = EventIngestionService::extractTitleSegments($title2);
        $this->assertContains('jūtu impērija', $segments2);
    }

    public function test_cinema_identification(): void
    {
        $this->assertTrue(EventIngestionService::isCinemaEvent('Tumšā robeža', 'Kino Lora, Sigulda'));
        $this->assertTrue(EventIngestionService::isCinemaEvent('Filmas seanss', 'Siguldas Kultūras nams'));
    }

    public function test_deduplication_case_1_the_dark_border_and_tumsa_robeza(): void
    {
        $sourceAfiro = Source::create([
            'name' => 'Afiro API',
            'slug' => 'afiro-api',
            'type' => SourceType::AGGREGATOR,
            'url' => 'https://afiro.lv',
            'scraper_class' => 'App\Services\Scrapers\Sources\AfiroApiScraper',
            'is_active' => true,
        ]);

        $sourceBs = Source::create([
            'name' => 'Biļešu Serviss',
            'slug' => 'bilesuserviss',
            'type' => SourceType::TICKETING,
            'url' => 'https://www.bilesuserviss.lv',
            'scraper_class' => 'App\Services\Scrapers\Sources\BilesuServissScraper',
            'is_active' => true,
        ]);

        $ingestionService = app(EventIngestionService::class);
        $eventTime = Carbon::now()->addDays(5)->setTime(15, 0, 0);

        // 1. Ingest English item from Afiro first
        $dtoAfiro = new ScrapedEventDTO(
            title: 'The Dark Border',
            startAt: $eventTime,
            description: 'Movie screening at Kino Lora.',
            venueName: 'KINO LORA, Sigulda',
            city: 'Sigulda',
            region: 'Vidzeme',
            categoryNames: ['Kino'],
            ticketUrl: 'https://www.bilesuserviss.lv/rus/biletes/kino/filma/tumsa-robeza-487667/?utm_source=afiro',
            sourceExternalId: 'afiro-tumsa-robeza',
            locale: 'en',
        );

        $res1 = $ingestionService->ingestDTO($dtoAfiro, $sourceAfiro);
        $this->assertEquals('created', $res1);
        $this->assertEquals(1, Event::count());

        $firstEvent = Event::first();
        $this->assertEquals('The Dark Border', $firstEvent->title);

        // 2. Ingest Latvian item from Biļešu Serviss
        $dtoBs = new ScrapedEventDTO(
            title: 'Tumšā robeža',
            startAt: $eventTime,
            description: 'Filmas Tumšā robeža kinoseanss Kino Lora.',
            venueName: 'Kino Lora',
            city: 'Sigulda',
            region: 'Vidzeme',
            categoryNames: ['Kino'],
            ticketUrl: 'https://www.bilesuserviss.lv/lat/biletes/kino/filma/tumsa-robeza-487667/',
            sourceExternalId: 'bs-tumsa-robeza-487667',
            locale: 'lv',
        );

        $res2 = $ingestionService->ingestDTO($dtoBs, $sourceBs);
        $this->assertEquals('updated', $res2);
        $this->assertEquals(1, Event::count());

        $updatedEvent = Event::first();
        $this->assertEquals('Tumšā robeža', $updatedEvent->title);
        $this->assertStringStartsWith('tumsa-robeza-', $updatedEvent->slug);
    }

    public function test_deduplication_case_2_kot_v_shliape_and_glaunais_runcis(): void
    {
        $sourceAfiro = Source::create([
            'name' => 'Afiro API',
            'slug' => 'afiro-api',
            'type' => SourceType::AGGREGATOR,
            'url' => 'https://afiro.lv',
            'scraper_class' => 'App\Services\Scrapers\Sources\AfiroApiScraper',
            'is_active' => true,
        ]);

        $sourceBs = Source::create([
            'name' => 'Biļešu Serviss',
            'slug' => 'bilesuserviss',
            'type' => SourceType::TICKETING,
            'url' => 'https://www.bilesuserviss.lv',
            'scraper_class' => 'App\Services\Scrapers\Sources\BilesuServissScraper',
            'is_active' => true,
        ]);

        $ingestionService = app(EventIngestionService::class);
        $eventTime = Carbon::now()->addDays(5)->setTime(15, 15, 0);

        // 1. Ingest Russian title from Afiro
        $dtoAfiro = new ScrapedEventDTO(
            title: 'Кот в шляпе',
            startAt: $eventTime,
            description: 'Анимационный фильм в Kino Lora.',
            venueName: 'Kino Lora, Sigulda',
            city: 'Sigulda',
            region: 'Vidzeme',
            categoryNames: ['Kino'],
            ticketUrl: 'https://www.bilesuserviss.lv/rus/biletes/kino/filma/glaunais-runcis-487668/?utm_source=afiro',
            sourceExternalId: 'afiro-glaunais-runcis',
            locale: 'ru',
        );

        $res1 = $ingestionService->ingestDTO($dtoAfiro, $sourceAfiro);
        $this->assertEquals('created', $res1);
        $this->assertEquals(1, Event::count());

        // 2. Ingest Latvian title from Biļešu Serviss
        $dtoBs = new ScrapedEventDTO(
            title: 'Glaunais runcis',
            startAt: $eventTime,
            description: 'Animācijas filma visai ģimenei.',
            venueName: 'Kino Lora',
            city: 'Sigulda',
            region: 'Vidzeme',
            categoryNames: ['Kino'],
            ticketUrl: 'https://www.bilesuserviss.lv/lat/biletes/kino/filma/glaunais-runcis-487668/',
            sourceExternalId: 'bs-glaunais-runcis-487668',
            locale: 'lv',
        );

        $res2 = $ingestionService->ingestDTO($dtoBs, $sourceBs);
        $this->assertEquals('updated', $res2);
        $this->assertEquals(1, Event::count());

        $updatedEvent = Event::first();
        $this->assertEquals('Glaunais runcis', $updatedEvent->title);
        $this->assertStringStartsWith('glaunais-runcis-', $updatedEvent->slug);
    }

    public function test_deduplication_case_3_jutu_imperija_three_way_merge(): void
    {
        $sourceBezRindas = Source::create([
            'name' => 'BezRindas.lv',
            'slug' => 'bezrindas',
            'type' => SourceType::TICKETING,
            'url' => 'https://www.bezrindas.lv',
            'scraper_class' => 'App\Services\Scrapers\Sources\BezRindasScraper',
            'is_active' => true,
        ]);

        $sourceSigulda = Source::create([
            'name' => 'Siguldas novada pašvaldība',
            'slug' => 'sigulda-novads',
            'type' => SourceType::MUNICIPALITY,
            'url' => 'https://sigulda.lv/pasakumi',
            'scraper_class' => 'App\Services\Scrapers\Sources\SiguldaNovadsScraper',
            'is_active' => true,
        ]);

        $ingestionService = app(EventIngestionService::class);
        $eventTime = Carbon::now()->addDays(7)->setTime(19, 0, 0);

        // 1. Ingest from BezRindas base event URL
        $dto1 = new ScrapedEventDTO(
            title: 'Jūtu impērija - stand up izrāde',
            startAt: $eventTime,
            description: 'Aleksandra Guzenko stand up izrāde Siguldas kultūras namā.',
            venueName: 'Siguldas Kultūras nams',
            city: 'Sigulda',
            region: 'Vidzeme',
            categoryNames: ['Teātris'],
            ticketUrl: 'https://www.bezrindas.lv/lv/jutu-imperija-stand-up-izrade/15786/',
            sourceExternalId: 'bezrindas-15786',
            locale: 'lv',
        );

        $res1 = $ingestionService->ingestDTO($dto1, $sourceBezRindas);
        $this->assertEquals('created', $res1);
        $this->assertEquals(1, Event::count());

        // 2. Ingest BezRindas session URL
        $dto2 = new ScrapedEventDTO(
            title: 'Jūtu impērija. Aleksandra Guzenko stand up komēdija',
            startAt: $eventTime,
            description: 'Aleksandrs Guzenko ar stāvizrādi Jūtu impērija.',
            venueName: 'Siguldas Kultūras nams',
            city: 'Sigulda',
            region: 'Vidzeme',
            categoryNames: ['Teātris'],
            ticketUrl: 'https://www.bezrindas.lv/lv/jutu-imperija-stand-up-izrade/15786/97356',
            sourceExternalId: 'bezrindas-session-97356',
            locale: 'lv',
        );

        $res2 = $ingestionService->ingestDTO($dto2, $sourceBezRindas);
        $this->assertEquals('updated', $res2);
        $this->assertEquals(1, Event::count());

        // 3. Ingest from Sigulda municipal scraper
        $dto3 = new ScrapedEventDTO(
            title: 'Aleksandrs Guzenko stāvizrāde “Jūtu impērija” Siguldas Kultūras namā',
            startAt: $eventTime,
            description: 'Pasākums Siguldā, biļetes pieejamas BezRindas.lv.',
            venueName: 'Siguldas Kultūras nams',
            city: 'Sigulda',
            region: 'Vidzeme',
            categoryNames: ['Teātris'],
            ticketUrl: 'https://www.bezrindas.lv/lv/jutu-imperija-stand-up-izrade/15786/97356',
            sourceExternalId: 'sigulda-jutu-imperija',
            locale: 'lv',
        );

        $res3 = $ingestionService->ingestDTO($dto3, $sourceSigulda);
        $this->assertEquals('updated', $res3);
        $this->assertEquals(1, Event::count());
    }

    public function test_consolidation_command_merges_existing_duplicates_and_upgrades_slug(): void
    {
        $sourceAfiro = Source::create([
            'name' => 'Afiro API',
            'slug' => 'afiro-api',
            'type' => SourceType::AGGREGATOR,
            'url' => 'https://afiro.lv',
            'scraper_class' => 'App\Services\Scrapers\Sources\AfiroApiScraper',
            'is_active' => true,
        ]);

        $sourceBs = Source::create([
            'name' => 'Biļešu Serviss',
            'slug' => 'bilesuserviss',
            'type' => SourceType::TICKETING,
            'url' => 'https://www.bilesuserviss.lv',
            'scraper_class' => 'App\Services\Scrapers\Sources\BilesuServissScraper',
            'is_active' => true,
        ]);

        $location = Location::create([
            'name' => 'Kino Lora',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
        ]);

        $eventTime = Carbon::now()->addDays(3)->setTime(15, 0, 0);

        // Pre-create duplicate events directly in the database
        $event1 = Event::create([
            'title' => 'The Dark Border',
            'slug' => 'the-dark-border-9MbVDx',
            'source_id' => $sourceAfiro->id,
            'source_slug' => $sourceAfiro->slug,
            'location_id' => $location->id,
            'start_at' => $eventTime,
            'ticket_url' => 'https://www.bilesuserviss.lv/rus/biletes/kino/filma/tumsa-robeza-487667/?utm_source=afiro',
            'fingerprint' => 'hash1',
            'status' => 'published',
        ]);

        $event2 = Event::create([
            'title' => 'Tumšā robeža',
            'slug' => 'tumsa-robeza-FwQDq9',
            'source_id' => $sourceBs->id,
            'source_slug' => $sourceBs->slug,
            'location_id' => $location->id,
            'start_at' => $eventTime,
            'ticket_url' => 'https://www.bilesuserviss.lv/lat/biletes/kino/filma/tumsa-robeza-487667/',
            'fingerprint' => 'hash2',
            'status' => 'published',
        ]);

        $this->assertEquals(2, Event::count());

        // Run consolidation command
        Artisan::call('events:consolidate');

        $this->assertEquals(1, Event::whereNull('deleted_at')->count());
        $surviving = Event::whereNull('deleted_at')->first();

        $this->assertEquals('Tumšā robeža', $surviving->title);
        $this->assertStringStartsWith('tumsa-robeza-', $surviving->slug);
    }
}
