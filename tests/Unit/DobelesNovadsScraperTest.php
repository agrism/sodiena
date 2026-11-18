<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Location;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\DobelesNovadsScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class DobelesNovadsScraperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Category::firstOrCreate(['slug' => 'kino'], ['name' => 'Kino']);
        Category::firstOrCreate(['slug' => 'teatris'], ['name' => 'Teātris']);
        Category::firstOrCreate(['slug' => 'muzika'], ['name' => 'Mūzika']);
        Category::firstOrCreate(['slug' => 'izstades'], ['name' => 'Izstādes']);
        Category::firstOrCreate(['slug' => 'sports'], ['name' => 'Sports']);
        Category::firstOrCreate(['slug' => 'seminari'], ['name' => 'Semināri']);
        Category::firstOrCreate(['slug' => 'svetki'], ['name' => 'Svētki']);
        Category::firstOrCreate(['slug' => 'berniem'], ['name' => 'Bērniem']);
        Category::firstOrCreate(['slug' => 'citi'], ['name' => 'Cits']);
    }

    public function test_metadata(): void
    {
        $scraper = new DobelesNovadsScraper();
        $this->assertEquals('dobeles-novads', $scraper->getSlug());
        $this->assertEquals('Dobeles novada pašvaldība', $scraper->getName());
    }

    public function test_date_range_parsing(): void
    {
        $scraper = new DobelesNovadsScraper();

        // 1. Full multi-day with times
        $d1 = $scraper->parseDateRange('25.10.2026 11:00 - 22.11.2026 - 14:00');
        $this->assertNotNull($d1);
        $this->assertEquals(25, $d1['start_at']->day);
        $this->assertEquals(10, $d1['start_at']->month);
        $this->assertEquals(11, $d1['start_at']->hour);
        $this->assertEquals(22, $d1['end_at']->day);
        $this->assertEquals(11, $d1['end_at']->month);
        $this->assertEquals(14, $d1['end_at']->hour);

        // 2. Multi-day with start time only
        $d2 = $scraper->parseDateRange('06.10.2026 08:00 - 27.11.2026');
        $this->assertNotNull($d2);
        $this->assertEquals(6, $d2['start_at']->day);
        $this->assertEquals(8, $d2['start_at']->hour);
        $this->assertEquals(27, $d2['end_at']->day);
        $this->assertEquals(23, $d2['end_at']->hour);

        // 3. Single day time range
        $d3 = $scraper->parseDateRange('20.11.2026 18:00 - 20:30');
        $this->assertNotNull($d3);
        $this->assertEquals(20, $d3['start_at']->day);
        $this->assertEquals(18, $d3['start_at']->hour);
        $this->assertEquals(20, $d3['end_at']->day);
        $this->assertEquals(20, $d3['end_at']->hour);
        $this->assertEquals(30, $d3['end_at']->minute);

        // 4. Date range without times
        $d4 = $scraper->parseDateRange('19.09.2026 - 01.12.2026');
        $this->assertNotNull($d4);
        $this->assertEquals(19, $d4['start_at']->day);
        $this->assertEquals(9, $d4['start_at']->month);
        $this->assertEquals(1, $d4['end_at']->day);
        $this->assertEquals(12, $d4['end_at']->month);

        // 5. Single day with start time
        $d5 = $scraper->parseDateRange('18.11.2026 17:00');
        $this->assertNotNull($d5);
        $this->assertEquals(18, $d5['start_at']->day);
        $this->assertEquals(17, $d5['start_at']->hour);
        $this->assertNull($d5['end_at']);

        // 6. Single day only
        $d6 = $scraper->parseDateRange('22.11.2026');
        $this->assertNotNull($d6);
        $this->assertEquals(22, $d6['start_at']->day);
        $this->assertEquals(11, $d6['start_at']->month);
        $this->assertNull($d6['end_at']);

        // 7. Empty
        $this->assertNull($scraper->parseDateRange(''));
    }

    public function test_location_resolving(): void
    {
        $scraper = new DobelesNovadsScraper();

        // 1. Dobeles pilsētas kultūras nams
        $loc1 = $scraper->resolveLocation('Dobeles pilsētas Kultūras nams', '', 'Mika Galvanovska koncerts', 'Pasākums Dobelē');
        $this->assertEquals('Dobeles pilsētas Kultūras nams', $loc1['venue_name']);
        $this->assertEquals('Dobele', $loc1['city']);
        $this->assertEquals('Zemgale', $loc1['region']);
        $this->assertEquals('cultural_center', $loc1['place_type']);

        // 2. Dobeles vēsturiskais tirgus laukums
        $loc2 = $scraper->resolveLocation('Dobeles pilsētas vēsturiskais centrs', '', 'Dobeles egles iedegšana', 'Tirgus laukumā');
        $this->assertEquals('Dobeles vēsturiskais tirgus laukums', $loc2['venue_name']);
        $this->assertEquals('Dobele', $loc2['city']);
        $this->assertEquals('square', $loc2['place_type']);

        // 3. Dobeles Pils
        $loc3 = $scraper->resolveLocation('Dobeles Pils', '', 'Dināras Rudānes koncerts', 'Koncerts Dobeles pilī');
        $this->assertEquals('Dobeles Pils', $loc3['venue_name']);
        $this->assertEquals('Dobele', $loc3['city']);
        $this->assertEquals('castle', $loc3['place_type']);

        // 4. Auces pilsētas Kultūras nams
        $loc4 = $scraper->resolveLocation('Auces pilsētas Kultūras nams', '', 'Ingas Ābeles lugas izrāde', '');
        $this->assertEquals('Auces pilsētas Kultūras nams', $loc4['venue_name']);
        $this->assertEquals('Auce', $loc4['city']);
        $this->assertEquals('Zemgale', $loc4['region']);

        // 5. LVM dabas parks Tērvetē
        $loc5 = $scraper->resolveLocation('LVM dabas parks Tērvetē', '', 'Rūķu pilsētas Gaismas svētki', 'Tērvetē');
        $this->assertEquals('LVM dabas parks Tērvetē', $loc5['venue_name']);
        $this->assertEquals('Tērvete', $loc5['city']);
        $this->assertEquals('park', $loc5['place_type']);

        // 6. Lauku māja "Putni"
        $loc6 = $scraper->resolveLocation('Māja laukos "Putni"', '', 'Privāts gadu mijas pasākums Putnos', '');
        $this->assertEquals('Lauku māja "Putni"', $loc6['venue_name']);
        $this->assertEquals('Putni', $loc6['city']);

        // 7. Zoltners
        $loc7 = $scraper->resolveLocation('Zoltners restorāns, viesnīca', '', 'Brančs', '');
        $this->assertEquals('Zoltners restorāns un viesnīca', $loc7['venue_name']);
        $this->assertEquals('Kroņauce', $loc7['city']);
        $this->assertEquals('restaurant', $loc7['place_type']);
    }

    public function test_category_resolving_strictly_follows_agents_md(): void
    {
        $scraper = new DobelesNovadsScraper();

        // 1. STRICT CINEMA RULE: Spēlfilma is ALWAYS Kino and NEVER Teātris!
        [$cat1, $type1] = $scraper->resolveCategories(
            'Spēlfilma "Tumšā robeža" Dobeles pilsētas kultūras namā',
            'Kino seanss un tikšanās ar aktieriem',
            'Dobeles pilsētas Kultūras nams'
        );
        $this->assertContains('Kino', $cat1);
        $this->assertNotContains('Teātris', $cat1);
        $this->assertEquals('kino', $type1);

        // 2. Strict Cinema with kinoseanss
        [$cat2, $type2] = $scraper->resolveCategories(
            'Animācijas filmas "Straume" kinoseanss bērniem',
            'Ginta Zilbaloža filmas demonstrēšana',
            'Dobeles Kultūras nams'
        );
        $this->assertContains('Kino', $cat2);
        $this->assertNotContains('Teātris', $cat2);
        $this->assertEquals('kino', $type2);

        // 3. Real Theatre: Ingas Ābeles luga
        [$cat3, $type3] = $scraper->resolveCategories(
            'Ingas Ābeles lugas „DZELZSZĀLE” izrāde – Slampes Kultūras pils amatierteātra „Fēnikss” iestudējums',
            'Režisors un aktieri priecēs skatītājus',
            'Auces pilsētas Kultūras nams'
        );
        $this->assertContains('Teātris', $cat3);
        $this->assertEquals('theatre', $type3);

        // 4. Music: Mika Galvanovska Valsts svētku koncerts
        [$cat4, $type4] = $scraper->resolveCategories(
            'Mika Galvanovska Valsts svētku koncerts "Par savu zemi saukt"',
            'Svētku koncerts ar grupu un solistu',
            'Dobeles pilsētas Kultūras nams'
        );
        $this->assertContains('Mūzika', $cat4);
        $this->assertContains('Svētki', $cat4);
        $this->assertEquals('concert', $type4);

        // 5. Exhibitions: Gleznu izstāde
        [$cat5, $type5] = $scraper->resolveCategories(
            'Gleznu izstāde “Zemgale mūsu sirdīs”',
            'Mākslinieku darbu ekspozīcija',
            'Dobeles Pils'
        );
        $this->assertContains('Izstādes', $cat5);
        $this->assertEquals('exhibition', $type5);

        // 6. Sports: Izskrien Latviju
        [$cat6, $type6] = $scraper->resolveCategories(
            'Izskrien Latviju Dobelē',
            'Kopīgs patriotisks skrējiens par godu valsts svētkiem',
            'Dobeles Atbrīvošanas piemineklis'
        );
        $this->assertContains('Sports', $cat6);
        $this->assertEquals('sports', $type6);

        // 7. Workshops: Adventes vainagu meistarklase
        [$cat7, $type7] = $scraper->resolveCategories(
            'Adventes vainagu meistarklase',
            'Radoša vainagu pīšanas darbnīca meistara vadībā',
            'Kārļa Ulmaņa piemiņas muzejs "Pikšas"'
        );
        $this->assertContains('Semināri', $cat7);
        $this->assertContains('Svētki', $cat7);
        $this->assertEquals('workshop', $type7);
    }

    public function test_price_parsing(): void
    {
        $scraper = new DobelesNovadsScraper();

        $p1 = $scraper->parsePrices('Ieeja bez maksas! Aicināti visi interesenti.');
        $this->assertTrue($p1['is_free']);
        $this->assertEquals(0.0, $p1['price_min']);

        $p2 = $scraper->parsePrices('Biļetes "Biļešu Paradīze" kasēs, biļetes cena no 15 eiro.');
        $this->assertFalse($p2['is_free']);
        $this->assertEquals(15.0, $p2['price_min']);

        $p3 = $scraper->parsePrices('Ieejas maksa – 8 EUR.');
        $this->assertFalse($p3['is_free']);
        $this->assertEquals(8.0, $p3['price_min']);

        $p4 = $scraper->parsePrices('Ieejas maksa: 30.');
        $this->assertFalse($p4['is_free']);
        $this->assertEquals(30.0, $p4['price_min']);
    }

    public function test_ticket_url_extraction_and_unwrapping(): void
    {
        $scraper = new DobelesNovadsScraper();

        $htmlWithFacebookRedirect = '<div><a href="https://l.facebook.com/l.php?u=http%3A%2F%2Fwww.aula.lv%2F%3Ffbclid%3D123&h=xyz">Pirkt biļeti</a></div>';
        $crawler1 = new Crawler($htmlWithFacebookRedirect);
        $url1 = $scraper->extractTicketUrl($crawler1, 'https://fallback.com');
        $this->assertEquals('http://www.aula.lv/?fbclid=123', $url1);

        $htmlWithBilesuParadize = '<div><p>Biļetes kasēs un internetā: <a href="https://www.bilesuparadize.lv/lv/event/178158">Biļešu Paradīze</a></p></div>';
        $crawler2 = new Crawler($htmlWithBilesuParadize);
        $url2 = $scraper->extractTicketUrl($crawler2, 'https://fallback.com');
        $this->assertEquals('https://www.bilesuparadize.lv/lv/event/178158', $url2);
    }

    public function test_mock_scrape_visitdobele(): void
    {
        $mockListHtml = '<!doctype html><html><body>
            <div id="page">
                <a class="card" href="https://visitdobele.lv/pasakumi/20323/ziemassvetku-koncerts-sirds-ir-balss-5" style="background-image:url(https://viss.lv/dati/visitdobele/cover.webp)">
                    <span class="card-calendar"><i class="i-calendar"></i>13.12.2026 15:00</span>
                    <span class="card-title">Ziemassvētku koncerts “Sirds ir balss 5”<span class="i-location">Dobeles pilsētas Kultūras nams</span></span>
                </a>
            </div>
        </body></html>';

        $mockDetailHtml = '<!doctype html><html><body>
            <h1>Ziemassvētku koncerts “Sirds ir balss 5”</h1>
            <div class="objekts pasakums" id="op20323">
                <span class="obj_datums"><i class="i-calendar"></i>13.12.2026 15:00 <span class="norises_vieta"><i class="i-location"></i>Dobeles pilsētas Kultūras nams</span></span>
                <div class="obj_saturs" id="p20323">
                    <p><img class="img_50" src="https://viss.lv/dati/visitdobele/v/sirdsirbalss.webp" srcset="https://viss.lv/dati/visitdobele/1920/sirdsirbalss.webp 1920w, https://viss.lv/dati/visitdobele/800/sirdsirbalss.webp 800w">Aicinām uz svētku koncertu Dobeles pilsētas Kultūras namā 13. decembrī plkst. 15.00. Biļetes "Biļešu Paradīze" kasēs, biļetes cena no 15 eiro.<br>Jau piekto gadu pēc kārtas Ziemassvētku laikā satiekas šarmantā trijotne.<br><a href="https://www.bilesuparadize.lv/lv/event/178158">Pirkt biļetes</a></p>
                </div>
            </div>
        </body></html>';

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $mockListHtml),
            new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $mockDetailHtml),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $scraper = new DobelesNovadsScraper();
        $scraper->setClient($client);

        $source = Source::firstOrCreate(
            ['slug' => 'dobeles-novads'],
            [
                'name' => 'Dobeles novada pašvaldība',
                'url' => 'https://visitdobele.lv/pasakumi',
                'scraper_class' => DobelesNovadsScraper::class,
                'type' => 'municipality',
                'is_active' => true,
            ]
        );

        $results = $scraper->scrape($source);

        $this->assertCount(1, $results);
        $dto = $results->first();

        $this->assertInstanceOf(ScrapedEventDTO::class, $dto);
        $this->assertEquals('Ziemassvētku koncerts “Sirds ir balss 5”', $dto->title);
        $this->assertEquals('Dobeles pilsētas Kultūras nams', $dto->venueName);
        $this->assertEquals('Dobele', $dto->city);
        $this->assertEquals('Zemgale', $dto->region);
        $this->assertContains('Mūzika', $dto->categoryNames);
        $this->assertContains('Svētki', $dto->categoryNames);
        $this->assertEquals('concert', $dto->entertainmentType);
        $this->assertEquals(15.0, $dto->priceMin);
        $this->assertEquals('https://www.bilesuparadize.lv/lv/event/178158', $dto->ticketUrl);
        $this->assertEquals('https://viss.lv/dati/visitdobele/1920/sirdsirbalss.webp', $dto->imageUrl);
        $this->assertEquals('dobele_20323', $dto->sourceExternalId);
        $this->assertStringContainsString('Jau piekto gadu pēc kārtas Ziemassvētku laikā', $dto->description);
    }

    public function test_dobeles_novads_event_ingestion(): void
    {
        $source = Source::firstOrCreate(
            ['slug' => 'dobeles-novads'],
            [
                'name' => 'Dobeles novada pašvaldība',
                'url' => 'https://visitdobele.lv/pasakumi',
                'scraper_class' => DobelesNovadsScraper::class,
                'type' => 'municipality',
                'is_active' => true,
            ]
        );

        $dto = new ScrapedEventDTO(
            title: 'Mika Galvanovska Valsts svētku koncerts "Par savu zemi saukt"',
            startAt: Carbon::tomorrow()->setTime(17, 0),
            endAt: null,
            description: 'Mika Galvanovska svētku koncerts Dobeles pilsētas kultūras namā ar grupu un īpašu programmu.',
            shortDescription: 'Mika Galvanovska svētku koncerts Dobelē.',
            venueName: 'Dobeles pilsētas Kultūras nams',
            city: 'Dobele',
            region: 'Zemgale',
            address: 'Baznīcas iela 6, Dobele, Dobeles novads, LV-3701',
            latitude: 56.6263,
            longitude: 23.2798,
            placeType: 'cultural_center',
            categoryNames: ['Mūzika', 'Svētki'],
            entertainmentType: 'concert',
            isFree: true,
            priceMin: 0.0,
            priceMax: 0.0,
            currency: 'EUR',
            ticketUrl: 'https://visitdobele.lv/pasakumi/20464/mika-galvanovska-valsts-svetku-koncerts',
            imageUrl: 'https://viss.lv/dati/visitdobele/galvanovskis.webp',
            sourceUrl: 'https://visitdobele.lv/pasakumi/20464/mika-galvanovska-valsts-svetku-koncerts',
            sourceExternalId: 'dobele_20464',
            locale: 'lv',
            rawData: ['visitdobele_id' => '20464'],
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = \App\Models\Event::where('source_external_id', 'dobele_20464')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Mika Galvanovska Valsts svētku koncerts "Par savu zemi saukt"', $event->title);
        $this->assertTrue((bool) $event->is_free);

        $cats = $event->categories->pluck('slug')->all();
        $this->assertContains('muzika', $cats);
        $this->assertContains('svetki', $cats);

        $location = $event->location;
        $this->assertNotNull($location);
        $this->assertEquals('Dobeles pilsētas Kultūras nams', $location->name);
        $this->assertEquals('Dobele', $location->city);
        $this->assertEquals('Zemgale', $location->region);
    }
}

