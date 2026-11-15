<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\VisitJekabpilsScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisitJekabpilsScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata(): void
    {
        $scraper = new VisitJekabpilsScraper();
        $this->assertEquals('visitjekabpils', $scraper->getSlug());
        $this->assertEquals('Visit Jēkabpils', $scraper->getName());
    }

    public function test_parse_dates_single_date_with_time(): void
    {
        $scraper = new VisitJekabpilsScraper();
        $today = Carbon::create(2026, 11, 15, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('15 Nov | 10:00', '2026-11-15', $today);

        $this->assertNotNull($startAt);
        $this->assertNull($endAt);
        $this->assertEquals('2026-11-15 10:00:00', $startAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_single_date_with_time_range(): void
    {
        $scraper = new VisitJekabpilsScraper();
        $today = Carbon::create(2026, 11, 15, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('15 Nov | 10:00 - 13:00', '2026-11-15', $today);

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-11-15 10:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-15 13:00:00', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_date_range_same_year(): void
    {
        $scraper = new VisitJekabpilsScraper();
        $today = Carbon::create(2026, 11, 15, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('24 Aug - 4 Dec | 10:00', '2026-08-24', $today);

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-08-24 10:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-12-04 23:59:59', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_cross_year_range(): void
    {
        $scraper = new VisitJekabpilsScraper();
        $today = Carbon::create(2026, 12, 1, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('20 Dec - 5 Jan | 18:00', '2026-12-20', $today);

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-12-20 18:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2027-01-05 23:59:59', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_location_resolving_known_venues(): void
    {
        $scraper = new VisitJekabpilsScraper();

        // 1. Krustpils pils
        $loc1 = $scraper->resolveLocation('Daiņa Bērziņa pasteļu izstāde „Zūdošo laiku paturot" Krustpils pilī', 'daina-berzina-krustpils-pili');
        $this->assertEquals('Krustpils pils', $loc1['name']);
        $this->assertEquals('Jēkabpils', $loc1['city']);
        $this->assertEquals('Latgale', $loc1['region']);
        $this->assertEquals('castle', $loc1['place_type']);
        $this->assertEquals(56.5106, $loc1['lat']);

        // 2. Krustpils kultūras centrs
        $loc2 = $scraper->resolveLocation('Vizmai Belševicai 95. Koncertuzvedums Krustpils kultūras centrā');
        $this->assertEquals('Krustpils kultūras centrs', $loc2['name']);
        $this->assertEquals('Jēkabpils', $loc2['city']);

        // 3. Brīvdabas nodaļa "Sēļu sēta"
        $loc3 = $scraper->resolveLocation('Epoksīda sveķu darbnīca "Sēļu sētā"');
        $this->assertEquals('Brīvdabas nodaļa "Sēļu sēta"', $loc3['name']);
        $this->assertEquals('Jēkabpils', $loc3['city']);
        $this->assertEquals('museum', $loc3['place_type']);

        // 4. Jēkabpils sporta halle
        $loc4 = $scraper->resolveLocation('AKTIVITĀTES JĒKABPILS SPORTA HALLES LEDUS ARĒNĀ');
        $this->assertEquals('Jēkabpils sporta halle', $loc4['name']);
        $this->assertEquals('Jēkabpils', $loc4['city']);

        // 5. Jēkabpils kartingu halle
        $loc5 = $scraper->resolveLocation('JĒKABPILS KARTINGU HALLES KARTINGA ČEMPIONĀTS');
        $this->assertEquals('Jēkabpils kartingu halle', $loc5['name']);
        $this->assertEquals('Jēkabpils', $loc5['city']);

        // 6. Taborkalna skatu tornis
        $loc6 = $scraper->resolveLocation('Pārgājiens uz Taborkalna skatu torni');
        $this->assertEquals('Taborkalna skatu tornis', $loc6['name']);
        $this->assertEquals('Sēlija', $loc6['city']);

        // 7. Asares muiža
        $loc7 = $scraper->resolveLocation('PĀRGĀJIENS ASARES MUIŽA UN APKĀRTNES LEĢENDAS');
        $this->assertEquals('Asares muiža', $loc7['name']);
        $this->assertEquals('Asare', $loc7['city']);

        // 8. Ilzu luterāņu baznīca (does NOT match "milžu")
        $loc8 = $scraper->resolveLocation('Jauno durvju atklāšanas svētki Ilzu baznīcā');
        $this->assertEquals('Ilzu luterāņu baznīca', $loc8['name']);
        $this->assertEquals('Cīruļi', $loc8['city']);

        // 9. Gārsenes pils (milžu pārgājiens matches gārsen, not ilzu)
        $loc9 = $scraper->resolveLocation('GĀRSENES MILŽU LEĢENDU PĀRGĀJIENS');
        $this->assertEquals('Gārsenes pils', $loc9['name']);
        $this->assertEquals('Gārsene', $loc9['city']);

        // 10. Kafejnīca "Ķēķis"
        $loc10 = $scraper->resolveLocation('STEIKU MEISTARKLASE KAFEJNĪCĀ "ĶĒĶIS"');
        $this->assertEquals('Kafejnīca "Ķēķis"', $loc10['name']);
        $this->assertEquals('Jēkabpils', $loc10['city']);

        // 11. Kafejnīca "Lauku šiks"
        $loc11 = $scraper->resolveLocation('Adventes vainagu meistarklase kafejnīcā "Lauku šiks"');
        $this->assertEquals('Kafejnīca "Lauku šiks"', $loc11['name']);
        $this->assertEquals('Viesīte', $loc11['city']);

        // 12. Tirdzniecības centrs "Sedumi"
        $loc12 = $scraper->resolveLocation('Adventes vainagu meistarklase Sedumi Dārza centrā');
        $this->assertEquals('Tirdzniecības centrs "Sedumi"', $loc12['name']);
        $this->assertEquals('Jēkabpils', $loc12['city']);

        // 13. Tirdzniecības centrs "Aura"
        $loc13 = $scraper->resolveLocation('Aicina pieteikties tirgotājus Ziemassvētku tirdziņam t/c AURA Jēkabpilī');
        $this->assertEquals('Tirdzniecības centrs "Aura"', $loc13['name']);
        $this->assertEquals('Jēkabpils', $loc13['city']);

        // 14. Vīpes amatniecības centrs "Māzers"
        $loc14 = $scraper->resolveLocation('Izstāde “Par putniem” Vīpē');
        $this->assertEquals('Vīpes amatniecības centrs "Māzers"', $loc14['name']);
        $this->assertEquals('Vīpe', $loc14['city']);

        // 15. Salas Tautas nams
        $loc15 = $scraper->resolveLocation('Izstāde “Sēlijas kods” Salā');
        $this->assertEquals('Salas Tautas nams', $loc15['name']);
        $this->assertEquals('Sala', $loc15['city']);

        // 16. Viesītes kultūras centrs "Sēlija"
        $loc16 = $scraper->resolveLocation('LATVIJAS REPUBLIKAS PROKLAMĒŠANAS 108. GADADIENAI VELTĪTS SARĪKOJUMS VIESĪTĒ');
        $this->assertEquals('Viesītes kultūras centrs "Sēlija"', $loc16['name']);
        $this->assertEquals('Viesīte', $loc16['city']);

        // 17. Biržu Tautas nams
        $loc17 = $scraper->resolveLocation('Sveču gatavošanas meistarklase Biržu Tautas namā');
        $this->assertEquals('Biržu Tautas nams', $loc17['name']);
        $this->assertEquals('Birži', $loc17['city']);

        // 18. Generic fallback
        $loc18 = $scraper->resolveLocation('Nezināms pasākums');
        $this->assertEquals('Jēkabpils', $loc18['name']);
        $this->assertEquals('Jēkabpils', $loc18['city']);
        $this->assertEquals('Latgale', $loc18['region']);
    }

    public function test_agents_rule_cinema_vs_theatre_categorization(): void
    {
        $scraper = new VisitJekabpilsScraper();

        // 1. Cinema / Kino strict rule: ANY event with filma/kinoseanss is ALWAYS Kino and NEVER Teātris!
        $cats1 = $scraper->resolveCategories('Filma "Klajumā aiz meža"', 'Kultūras pasākumi');
        $this->assertEquals(['Kino'], $cats1);
        $this->assertEquals('movie', $scraper->resolveEntertainmentType($cats1, 'Filma "Klajumā aiz meža"', ''));

        // 2. Kinovakars is Kino
        $cats2 = $scraper->resolveCategories('Kinovakars visai ģimenei', 'Kultūras pasākumi');
        $this->assertEquals(['Kino'], $cats2);
        $this->assertEquals('movie', $scraper->resolveEntertainmentType($cats2, 'Kinovakars', ''));

        // 3. kinoteātris contains substring "teātr", but MUST NEVER be classified as Teātris
        $cats3 = $scraper->resolveCategories('Filmas seanss', 'Kinoteātris');
        $this->assertEquals(['Kino'], $cats3);
        $this->assertEquals('movie', $scraper->resolveEntertainmentType($cats3, 'Filma', ''));

        // 4. Theater: production with izrāde
        $cats4 = $scraper->resolveCategories('UFO 60 NOKRĀSAS | jubilejas izrāde ar dziļu vēstījumu Jēkabpilī', 'Kultūras pasākumi');
        $this->assertEquals(['Teātris'], $cats4);
        $this->assertEquals('performance', $scraper->resolveEntertainmentType($cats4, 'Izrāde', ''));

        // 5. Concert / Music
        $cats5 = $scraper->resolveCategories('Triānas parka koncerts "ES GAIDU ZIEMU"', 'Kultūras pasākumi');
        $this->assertEquals(['Mūzika'], $cats5);
        $this->assertEquals('concert', $scraper->resolveEntertainmentType($cats5, 'Koncerts', ''));

        // 6. Concert with family / children
        $cats6 = $scraper->resolveCategories('Koncertuzvedums bērniem „Sniega karaliene” Jēkabpilī', 'Kultūras pasākumi');
        $this->assertContains('Mūzika', $cats6);
        $this->assertContains('Bērniem', $cats6);
        $this->assertEquals('concert', $scraper->resolveEntertainmentType($cats6, 'Koncerts bērniem', ''));

        // 7. Exhibition
        $cats7 = $scraper->resolveCategories('Daiņa Bērziņa pasteļu izstāde „Zūdošo laiku paturot" Krustpils pilī', 'Izstādes');
        $this->assertEquals(['Izstādes'], $cats7);
        $this->assertEquals('exhibition', $scraper->resolveEntertainmentType($cats7, 'Izstāde', ''));

        // 8. Workshop / Seminar
        $cats8 = $scraper->resolveCategories('Sveču gatavošanas meistarklase Biržu Tautas namā', 'Radošās darbnīcas un meistarklases');
        $this->assertEquals(['Semināri'], $cats8);
        $this->assertEquals('education', $scraper->resolveEntertainmentType($cats8, 'Meistarklase', ''));

        // 9. Sports tournament
        $cats9 = $scraper->resolveCategories('LATVIJAS ČEMPIONĀTS KARPU MAKŠĶERĒŠANĀ 2027', 'Aktīvā atpūta');
        $this->assertEquals(['Sports'], $cats9);
        $this->assertEquals('sports', $scraper->resolveEntertainmentType($cats9, 'Čempionāts', ''));

        // 10. Fairs / Festivities
        $cats10 = $scraper->resolveCategories('TIRGUS ZIEMASSVĒTKU NOSKAŅĀS VIESĪTĒ', 'Tirdziņi');
        $this->assertEquals(['Svētki'], $cats10);
        $this->assertEquals('chill', $scraper->resolveEntertainmentType($cats10, 'Tirgus', ''));

        // 11. Fallback: Cits
        $cats11 = $scraper->resolveCategories('Sēlijas Prozas lasījumi 2026 Jēkabpilī', 'Citi pasākumi');
        $this->assertEquals(['Cits'], $cats11);
        $this->assertEquals('other', $scraper->resolveEntertainmentType($cats11, 'Prozas lasījumi', ''));
    }

    public function test_parse_price(): void
    {
        $scraper = new VisitJekabpilsScraper();

        $free1 = $scraper->parsePrice('Ieeja bez maksas');
        $this->assertTrue($free1['is_free']);
        $this->assertEquals(0.0, $free1['price_min']);

        $free2 = $scraper->parsePrice('Ieeja brīva');
        $this->assertTrue($free2['is_free']);

        $paid1 = $scraper->parsePrice('Biļetes cena: 5.00 - 15.00 €');
        $this->assertFalse($paid1['is_free']);
        $this->assertEquals(5.0, $paid1['price_min']);
        $this->assertEquals(15.0, $paid1['price_max']);
    }

    public function test_visit_jekabpils_scraper_mock(): void
    {
        $mockHtml = <<<'HTML'
<div class="row row-events-main">
    <!-- Event 1: Exhibition in Krustpils pils -->
    <div class="card card-events">
        <div class="event-image">
            <a href="/lv/visi-pasakumi/2026-08-24/daina-berzina-pastelu-izstade-zudoso-laiku-paturot-krustpils-pili/" class="event-link">
                <img src="/uploads/plugins/events/middle_daina.jpg" alt="Pasteļu izstāde">
            </a>
        </div>
        <div class="event-times">24 Aug - 4 Dec | 10:00</div>
        <div class="card-body">
            <a href="/lv/visi-pasakumi/2026-08-24/daina-berzina-pastelu-izstade-zudoso-laiku-paturot-krustpils-pili/" class="event-link">
                <h4>Daiņa Bērziņa pasteļu izstāde „Zūdošo laiku paturot" Krustpils pilī</h4>
            </a>
            <a href="/lv/visi-pasakumi/?filter%5B%5D=12" class="event-cat-btn">Izstādes</a>
        </div>
    </div>

    <!-- Event 2: Sports championship -->
    <div class="card card-events">
        <div class="event-image">
            <a href="/lv/visi-pasakumi/2027-05-20/latvijas-cempionats-karpu-makskeresana-2027/" class="event-link">
                <img src="/uploads/plugins/events/middle_karpa.jpg" alt="Čempionāts">
            </a>
        </div>
        <div class="event-times">20 Mai - 23 Mai | 06:00</div>
        <div class="card-body">
            <a href="/lv/visi-pasakumi/2027-05-20/latvijas-cempionats-karpu-makskeresana-2027/" class="event-link">
                <h4>LATVIJAS ČEMPIONĀTS KARPU MAKŠĶERĒŠANĀ 2027</h4>
            </a>
            <a href="/lv/visi-pasakumi/?filter%5B%5D=14" class="event-cat-btn">Aktīvā atpūta</a>
        </div>
    </div>

    <!-- Event 3: Cinema screening -->
    <div class="card card-events">
        <div class="event-image">
            <a href="/lv/visi-pasakumi/2026-12-05/filma-klajuma-aiz-meza/" class="event-link">
                <img src="/uploads/plugins/events/middle_filma.jpg" alt="Filma">
            </a>
        </div>
        <div class="event-times">5 Dec | 18:00</div>
        <div class="card-body">
            <a href="/lv/visi-pasakumi/2026-12-05/filma-klajuma-aiz-meza/" class="event-link">
                <h4>Filma "Klajumā aiz meža"</h4>
            </a>
            <a href="/lv/visi-pasakumi/?filter%5B%5D=10" class="event-cat-btn">Kultūras pasākumi</a>
        </div>
    </div>

    <!-- Event 4: Past event from 2020 (must be skipped) -->
    <div class="card card-events">
        <div class="event-image">
            <a href="/lv/visi-pasakumi/2020-01-10/sens-pasakums/" class="event-link">
                <img src="/uploads/plugins/events/middle_sens.jpg" alt="Sens">
            </a>
        </div>
        <div class="event-times">10 Jan | 10:00</div>
        <div class="card-body">
            <a href="/lv/visi-pasakumi/2020-01-10/sens-pasakums/" class="event-link">
                <h4>Sens pasākums</h4>
            </a>
            <a href="/lv/visi-pasakumi/?filter%5B%5D=10" class="event-cat-btn">Kultūras pasākumi</a>
        </div>
    </div>
</div>
HTML;

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $mockHtml),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $scraper = new VisitJekabpilsScraper();
        $scraper->setClient($client);

        $source = new Source([
            'name' => 'Visit Jēkabpils',
            'slug' => 'visitjekabpils',
            'url' => 'https://visit.jekabpils.lv/lv/visi-pasakumi/',
            'scraper_class' => VisitJekabpilsScraper::class,
            'is_active' => true,
        ]);

        $events = $scraper->scrape($source);

        // Event 4 is skipped (past) => exactly 3 events
        $this->assertCount(3, $events);

        // Event 1: Exhibition
        $dto1 = $events[0];
        $this->assertEquals('Daiņa Bērziņa pasteļu izstāde „Zūdošo laiku paturot" Krustpils pilī', $dto1->title);
        $this->assertEquals('visitjekabpils-2026-08-24-daina-berzina-pastelu-izstade-zudoso-laiku-paturot-krustpils-pili', $dto1->sourceExternalId);
        $this->assertEquals('https://visit.jekabpils.lv/lv/visi-pasakumi/2026-08-24/daina-berzina-pastelu-izstade-zudoso-laiku-paturot-krustpils-pili/', $dto1->sourceUrl);
        $this->assertEquals('https://visit.jekabpils.lv/uploads/plugins/events/middle_daina.jpg', $dto1->imageUrl);
        $this->assertEquals('Krustpils pils', $dto1->venueName);
        $this->assertEquals('Jēkabpils', $dto1->city);
        $this->assertEquals('Latgale', $dto1->region);
        $this->assertEquals('2026-08-24 10:00:00', $dto1->startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-12-04 23:59:59', $dto1->endAt->format('Y-m-d H:i:s'));
        $this->assertEquals(['Izstādes'], $dto1->categoryNames);
        $this->assertEquals('exhibition', $dto1->entertainmentType);

        // Event 2: Sports
        $dto2 = $events[1];
        $this->assertEquals('LATVIJAS ČEMPIONĀTS KARPU MAKŠĶERĒŠANĀ 2027', $dto2->title);
        $this->assertEquals('2027-05-20 06:00:00', $dto2->startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2027-05-23 23:59:59', $dto2->endAt->format('Y-m-d H:i:s'));
        $this->assertEquals(['Sports'], $dto2->categoryNames);
        $this->assertEquals('sports', $dto2->entertainmentType);

        // Event 3: Cinema
        $dto3 = $events[2];
        $this->assertEquals('Filma "Klajumā aiz meža"', $dto3->title);
        $this->assertEquals('2026-12-05 18:00:00', $dto3->startAt->format('Y-m-d H:i:s'));
        $this->assertNull($dto3->endAt);
        $this->assertEquals(['Kino'], $dto3->categoryNames);
        $this->assertEquals('movie', $dto3->entertainmentType);
    }

    public function test_visit_jekabpils_event_ingestion(): void
    {
        $source = Source::create([
            'name' => 'Visit Jēkabpils',
            'slug' => 'visitjekabpils',
            'url' => 'https://visit.jekabpils.lv/lv/visi-pasakumi/',
            'scraper_class' => VisitJekabpilsScraper::class,
            'is_active' => true,
        ]);

        $dto = new ScrapedEventDTO(
            title: 'Triānas parka koncerts "ES GAIDU ZIEMU"',
            startAt: Carbon::create(2026, 12, 12, 19, 0, 0, 'Europe/Riga'),
            endAt: null,
            description: 'Triānas parka koncerts "ES GAIDU ZIEMU"',
            shortDescription: 'Triānas parka koncerts "ES GAIDU ZIEMU"',
            venueName: 'Krustpils kultūras centrs',
            city: 'Jēkabpils',
            region: 'Latgale',
            address: 'Rīgas iela 212, Jēkabpils, LV-5202',
            latitude: 56.5098,
            longitude: 25.8679,
            placeType: 'cultural_center',
            categoryNames: ['Mūzika'],
            entertainmentType: 'concert',
            isFree: false,
            priceMin: null,
            priceMax: null,
            imageUrl: 'https://visit.jekabpils.lv/uploads/plugins/events/middle_triana.jpg',
            sourceUrl: 'https://visit.jekabpils.lv/lv/visi-pasakumi/2026-12-12/trianas-parka-koncerts-es-gaidu-ziemu/',
            sourceExternalId: 'visitjekabpils-2026-12-12-trianas-parka-koncerts-es-gaidu-ziemu',
            locale: 'lv',
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_external_id', 'visitjekabpils-2026-12-12-trianas-parka-koncerts-es-gaidu-ziemu')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Triānas parka koncerts "ES GAIDU ZIEMU"', $event->title);
        $this->assertEquals('visit.jekabpils.lv', $event->origin_host);
        $this->assertEquals('concert', $event->entertainment_type);
        $this->assertEquals('Jēkabpils', $event->location->city);
        $this->assertEquals('Latgale', $event->location->region);
    }
}
