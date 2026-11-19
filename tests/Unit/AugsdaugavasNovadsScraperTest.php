<?php

namespace Tests\Unit;

use App\Enums\SourceType;
use App\Models\Category;
use App\Models\Event;
use App\Models\Location;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\AugsdaugavasNovadsScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AugsdaugavasNovadsScraperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Category::firstOrCreate(['slug' => 'kino'], ['name' => 'Kino']);
        Category::firstOrCreate(['slug' => 'teatris'], ['name' => 'Teātris']);
        Category::firstOrCreate(['slug' => 'muzika'], ['name' => 'Mūzika']);
        Category::firstOrCreate(['slug' => 'izstades'], ['name' => 'Izstādes']);
        Category::firstOrCreate(['slug' => 'berniem'], ['name' => 'Bērniem']);
        Category::firstOrCreate(['slug' => 'sports'], ['name' => 'Sports']);
        Category::firstOrCreate(['slug' => 'seminari'], ['name' => 'Semināri']);
        Category::firstOrCreate(['slug' => 'svetki'], ['name' => 'Svētki']);
        Category::firstOrCreate(['slug' => 'citi'], ['name' => 'Cits']);
    }

    public function test_metadata(): void
    {
        $scraper = new AugsdaugavasNovadsScraper();
        $this->assertEquals('augsdaugavas-novads', $scraper->getSlug());
        $this->assertEquals('Augšdaugavas novada pašvaldība', $scraper->getName());
    }

    public function test_skips_administrative_and_council_meetings(): void
    {
        $scraper = new AugsdaugavasNovadsScraper();

        $this->assertTrue($scraper->isExcludedEvent([], 'Augšdaugavas novada domes sēde'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Finanšu komitejas sēde'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Izglītības, kultūras un sporta jautājumu komitejas sēde'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Domes ārkārtas sēde'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Domes svinīgā sēde'));
        $this->assertTrue($scraper->isExcludedEvent(['Sēde'], 'Kārtējā pagasta sēde'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Deputātu pieņemšana Ilūkstē'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Sabiedriskā apspriešana par teritorijas plānojumu'));

        // Cultural events must NOT be excluded
        $this->assertFalse($scraper->isExcludedEvent(['Svētki'], 'Valsts svētku koncerts Ilūkstē'));
        $this->assertFalse($scraper->isExcludedEvent(['Izstādes'], 'Skrindu dzimtas muzeja krājuma izstāde'));
        $this->assertFalse($scraper->isExcludedEvent(['Kino'], 'Filmas seanss Naujenes kultūras centrā'));
    }

    public function test_date_and_time_parsing(): void
    {
        $scraper = new AugsdaugavasNovadsScraper();

        // 1. Single date with time range
        [$s1, $e1, $ad1] = $scraper->parseLatvianDates('Datums 25. novembris, 2026', 'Laiks 10.00–18.00');
        $this->assertNotNull($s1);
        $this->assertNotNull($e1);
        $this->assertFalse($ad1);
        $this->assertEquals('2026-11-25 10:00:00', $s1->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-25 18:00:00', $e1->format('Y-m-d H:i:s'));

        // 2. Single date all day
        [$s2, $e2, $ad2] = $scraper->parseLatvianDates('25. novembris, 2026', 'Visu dienu');
        $this->assertNotNull($s2);
        $this->assertNotNull($e2);
        $this->assertTrue($ad2);
        $this->assertEquals('2026-11-25 00:00:00', $s2->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-25 23:59:59', $e2->format('Y-m-d H:i:s'));

        // 3. Multi-day range within same month
        [$s3, $e3, $ad3] = $scraper->parseLatvianDates('Datums 20.–25. novembris, 2026', 'Laiks 17.00–22.00');
        $this->assertNotNull($s3);
        $this->assertNotNull($e3);
        $this->assertFalse($ad3);
        $this->assertEquals('2026-11-20 17:00:00', $s3->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-25 22:00:00', $e3->format('Y-m-d H:i:s'));

        // 4. Multi-day cross-month range
        [$s4, $e4, $ad4] = $scraper->parseLatvianDates('28. novembris, 2026 – 5. decembris, 2026', '12.00–19.00');
        $this->assertNotNull($s4);
        $this->assertNotNull($e4);
        $this->assertEquals('2026-11-28 12:00:00', $s4->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-12-05 19:00:00', $e4->format('Y-m-d H:i:s'));

        // 5. Standard numeric date
        [$s5, $e5, $ad5] = $scraper->parseLatvianDates('25.11.2026', '19:00');
        $this->assertNotNull($s5);
        $this->assertNull($e5);
        $this->assertEquals('2026-11-25 19:00:00', $s5->format('Y-m-d H:i:s'));
    }

    public function test_location_resolving(): void
    {
        $scraper = new AugsdaugavasNovadsScraper();

        // 1. Ilūkstes KMC
        $loc1 = $scraper->resolveLocation('Ilūkste', 'Teātra izrāde Ilūkstes Kultūras un mākslas centrā', 'Brīvības iela 12');
        $this->assertEquals('Ilūkstes Kultūras un mākslas centrs', $loc1['name']);
        $this->assertEquals('Ilūkste', $loc1['city']);
        $this->assertEquals('Latgale', $loc1['region']);
        $this->assertEquals('cultural_center', $loc1['place_type']);

        // 2. Subates KN
        $loc2 = $scraper->resolveLocation('Subate', 'Koncerts Subates Kultūras namā', '');
        $this->assertEquals('Subates Kultūras nams', $loc2['name']);
        $this->assertEquals('Subate', $loc2['city']);

        // 3. Naujenes KC (Krauja)
        $loc3 = $scraper->resolveLocation('Krauja', 'Mākslas izstāde Naujenes Kultūras centrā', '');
        $this->assertEquals('Naujenes Kultūras centrs', $loc3['name']);
        $this->assertEquals('Krauja', $loc3['city']);

        // 4. Raiņa māja Berķenelē
        $loc4 = $scraper->resolveLocation('Kalkūnes pagasts', 'Dzejas lasījumi Raiņa mājā Berķenelē', '');
        $this->assertEquals('Kultūras centrs “Raiņa māja Berķenelē”', $loc4['name']);
        $this->assertEquals('Birkineļi', $loc4['city']);
        $this->assertEquals('museum', $loc4['place_type']);

        // 5. Bebrenes muiža
        $loc5 = $scraper->resolveLocation('Bebrene', 'Mūzikas vakars Bebrenes muižā', '');
        $this->assertEquals('Bebrenes muižas komplekss', $loc5['name']);
        $this->assertEquals('Bebrene', $loc5['city']);

        // 6. Skrindu dzimtas muzejs Vabolē
        $loc6 = $scraper->resolveLocation('Vabole', 'Etnogrāfijas darbnīca Skrindu dzimtas muzejā', '');
        $this->assertEquals('Skrindu dzimtas muzejs', $loc6['name']);
        $this->assertEquals('Vabole', $loc6['city']);

        // 7. Višķu sabiedriskais centrs Špoģos
        $loc7 = $scraper->resolveLocation('Špoģi', 'Novada sporta svētki Višķu sabiedriskajā centrā', '');
        $this->assertEquals('Višķu sabiedriskais centrs', $loc7['name']);
        $this->assertEquals('Špoģi', $loc7['city']);

        // 8. Červonkas muižas pils
        $loc8 = $scraper->resolveLocation('Vecsaliena', 'Ziemassvētku pasākums Červonkas muižas pilī', '');
        $this->assertEquals('Červonkas (Vecsalienas) muižas pils un parks', $loc8['name']);
        $this->assertEquals('Červonka', $loc8['city']);

        // 9. Generic pagasts fallback (e.g. Kalupe)
        $loc9 = $scraper->resolveLocation('Kalupes pagasts', 'Pasākums pagasta iedzīvotājiem', '');
        $this->assertEquals('Kalupe', $loc9['city']);
        $this->assertEquals('Latgale', $loc9['region']);
    }

    public function test_category_resolving_strictly_follows_agents_md(): void
    {
        $scraper = new AugsdaugavasNovadsScraper();

        // 1. Strict Kino rule: Filmas seanss is ALWAYS KINO, NEVER TEATRIS
        $cats1 = $scraper->resolveCategories(['Teātris'], 'Latviešu spēlfilmas seanss Ilūkstē', 'Kinoseanss visai ģimenei');
        $this->assertEquals(['kino'], $cats1);

        // 2. Strict Kino rule: animācijas filma
        $cats2 = $scraper->resolveCategories(['Pasākumi'], 'Animācijas filmas pirmizrāde', 'Bērnu kino vakars');
        $this->assertEquals(['kino'], $cats2);

        // 3. Actual Theatre production
        $cats3 = $scraper->resolveCategories(['Teātris'], 'Daugavpils teātra viesizrāde “Dāmu paradīze”', 'Lugas iestudējums');
        $this->assertEquals(['teatris'], $cats3);

        // 4. Exhibitions
        $cats4 = $scraper->resolveCategories(['Izstādes'], 'Latgales keramikas izstāde', 'Mākslas darbu ekspozīcija');
        $this->assertEquals(['izstades'], $cats4);

        // 5. Concerts / music
        $cats5 = $scraper->resolveCategories(['Pasākumi'], 'Novada koru svētku koncerts', 'Muzikāls uzvedums');
        $this->assertEquals(['muzika'], $cats5);

        // 6. Sports
        $cats6 = $scraper->resolveCategories(['Sports'], 'Augšdaugavas novada volejbola čempionāts', 'Sacensības komandām');
        $this->assertEquals(['sports'], $cats6);

        // 7. Children
        $cats7 = $scraper->resolveCategories(['Bērniem'], 'Radošā darbnīca bērniem un ģimenēm', 'Leļļu darbnīca');
        $this->assertEquals(['berniem'], $cats7);

        // 8. Celebrations / Svētki
        $cats8 = $scraper->resolveCategories(['Svētki'], 'Ilūkstes pilsētas svētki 2026', 'Svinības un gadatirgus');
        $this->assertEquals(['svetki'], $cats8);

        // 9. Fallback: Cits per AGENTS.md
        $cats9 = $scraper->resolveCategories([], 'Tikšanās ar novada vēsturnieku', 'Interesanta saruna');
        $this->assertEquals(['citi'], $cats9);
    }

    public function test_price_parsing(): void
    {
        $scraper = new AugsdaugavasNovadsScraper();

        $p1 = $scraper->parsePrice('Ieeja bez maksas visiem apmeklētājiem.');
        $this->assertTrue($p1['is_free']);
        $this->assertEquals(0.0, $p1['price_min']);

        $p2 = $scraper->parsePrice('Biļetes cena: 4.00 EUR pie ieejas.');
        $this->assertFalse($p2['is_free']);
        $this->assertEquals(4.0, $p2['price_min']);

        $p3 = $scraper->parsePrice('Vispārējs pasākuma apraksts bez cenas.');
        $this->assertTrue($p3['is_free']);
    }

    public function test_scrape_with_mocked_http(): void
    {
        $today = Carbon::today('Europe/Riga');
        $upcomingDateStr = $today->copy()->addDays(3)->format('d. F, Y');
        $lvMonths = [
            'January' => 'janvāris', 'February' => 'februāris', 'March' => 'marts',
            'April' => 'aprīlis', 'May' => 'maijs', 'June' => 'jūnijs',
            'July' => 'jūlijs', 'August' => 'augusts', 'September' => 'septembris',
            'October' => 'oktobris', 'November' => 'novembris', 'December' => 'decembris',
        ];
        $enMonth = $today->copy()->addDays(3)->format('F');
        $upcomingDateStr = str_replace($enMonth, $lvMonths[$enMonth], $upcomingDateStr);

        $html = <<<HTML
<!doctype html>
<html>
<body>
<div class="view-notikumu-kalendars">
    <!-- Event 1: Valid Upcoming Concert -->
    <div class="event">
        <div class="event-info">
            <span class="date-date">Datums {$upcomingDateStr}</span>
            <span class="date-time">Laiks 18.00–21.00</span>
            <span class="describing-field-value">Ilūkste</span>
        </div>
        <div class="event-image">
            <img src="/sites/default/files/styles/large/public/koncerts.jpg" alt="Svētku koncerts" />
        </div>
        <h2 class="event-title">
            <a href="/lv/notikums/latvijas-svetku-koncerts-ilukste">Svētku koncerts Ilūkstes Kultūras centrā</a>
        </h2>
        <div class="event-categories">
            <a href="/kategorija/muzika">Mūzika</a>
        </div>
        <div class="event-content">
            Novada labāko koru un solistu svētku koncerts Ilūkstes Kultūras un mākslas centrā. Ieeja brīva.
        </div>
    </div>

    <!-- Event 2: Valid Upcoming Film Screening (Strict Kino Rule) -->
    <div class="event">
        <div class="event-info">
            <span class="date-date">Datums {$upcomingDateStr}</span>
            <span class="date-time">Laiks 19.00</span>
            <span class="describing-field-value">Krauja</span>
        </div>
        <div class="event-image">
            <img src="https://www.augsdaugavasnovads.lv/files/filma.jpg" alt="Kino vakars" />
        </div>
        <h2 class="event-title">
            <a href="/lv/notikums/kino-vakars-naujene">Jaunākās latviešu spēlfilmas seanss Naujenē</a>
        </h2>
        <div class="event-categories">
            <a href="/kategorija/kino">Kino</a>
        </div>
        <div class="event-content">
            Aicinām uz spēlfilmas kinoseansu Naujenes Kultūras centrā. Biļetes cena: 3.00 EUR.
        </div>
    </div>

    <!-- Event 3: Administrative Council Meeting (Must be SKIPPED) -->
    <div class="event">
        <div class="event-info">
            <span class="date-date">Datums {$upcomingDateStr}</span>
            <span class="date-time">Laiks 10.00</span>
            <span class="describing-field-value">Daugavpils</span>
        </div>
        <h2 class="event-title">
            <a href="/lv/notikums/augsdaugavas-domes-sede">Augšdaugavas novada pašvaldības domes sēde</a>
        </h2>
        <div class="event-categories">
            <a href="/kategorija/sede">Domes sēde</a>
        </div>
        <div class="event-content">
            Domes kārtējā sēde klātienē un tiešsaistē.
        </div>
    </div>

    <!-- Event 4: Past Event (Must be SKIPPED per AGENTS.md) -->
    <div class="event">
        <div class="event-info">
            <span class="date-date">Datums 01. janvāris, 2025</span>
            <span class="date-time">Laiks 12.00</span>
            <span class="describing-field-value">Subate</span>
        </div>
        <h2 class="event-title">
            <a href="/lv/notikums/aizgajusie-svetki">Veci svētki Subatē</a>
        </h2>
        <div class="event-content">
            Pasākums no pagātnes.
        </div>
    </div>
</div>
</body>
</html>
HTML;

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html),
            new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], '<html><body><div class="view-notikumu-kalendars"></div></body></html>'),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $scraper = new AugsdaugavasNovadsScraper();
        $scraper->setClient($client);

        $source = new Source([
            'name' => 'Augšdaugavas novada pašvaldība',
            'slug' => 'augsdaugavas-novads',
            'url' => 'https://www.augsdaugavasnovads.lv/lv/notikumu-kalendars',
            'type' => SourceType::MUNICIPALITY->value,
            'is_active' => true,
        ]);

        $events = $scraper->scrape($source);

        // Assert only 2 valid upcoming cultural events scraped
        $this->assertCount(2, $events);

        // Event 1 checks
        $e1 = $events[0];
        $this->assertEquals('Svētku koncerts Ilūkstes Kultūras centrā', $e1->title);
        $this->assertEquals('Ilūkstes Kultūras un mākslas centrs', $e1->venueName);
        $this->assertEquals('Ilūkste', $e1->city);
        $this->assertEquals('Latgale', $e1->region);
        $this->assertEquals('Brīvības iela 12, Ilūkste, Augšdaugavas novads, LV-5447', $e1->address);
        $this->assertTrue($e1->isFree);
        $this->assertEquals(['muzika'], $e1->categoryNames);
        $this->assertEquals('https://www.augsdaugavasnovads.lv/sites/default/files/styles/large/public/koncerts.jpg', $e1->imageUrl);

        // Event 2 checks
        $e2 = $events[1];
        $this->assertEquals('Jaunākās latviešu spēlfilmas seanss Naujenē', $e2->title);
        $this->assertEquals('Naujenes Kultūras centrs', $e2->venueName);
        $this->assertEquals('Krauja', $e2->city);
        $this->assertEquals('Latgale', $e2->region);
        $this->assertFalse($e2->isFree);
        $this->assertEquals(3.0, $e2->priceMin);
        $this->assertEquals(['kino'], $e2->categoryNames);
        $this->assertEquals('https://www.augsdaugavasnovads.lv/files/filma.jpg', $e2->imageUrl);
    }

    public function test_augsdaugavas_novads_event_ingestion(): void
    {
        $source = Source::create([
            'name' => 'Augšdaugavas novada pašvaldība',
            'slug' => 'augsdaugavas-novads',
            'url' => 'https://www.augsdaugavasnovads.lv/lv/notikumu-kalendars',
            'scraper_class' => AugsdaugavasNovadsScraper::class,
            'type' => SourceType::MUNICIPALITY->value,
            'is_active' => true,
        ]);

        $ingestService = app(EventIngestionService::class);

        $dto = new ScrapedEventDTO(
            title: 'Dzejas dienas Raiņa mājā Berķenelē',
            startAt: Carbon::today('Europe/Riga')->addDays(5)->setTime(14, 0, 0),
            endAt: Carbon::today('Europe/Riga')->addDays(5)->setTime(18, 0, 0),
            description: 'Tradicionālās dzejas dienas Raiņa bērnības mājā Birkineļos.',
            shortDescription: 'Dzejas dienu lasījumi un mūzika Berķenelē.',
            venueName: 'Kultūras centrs “Raiņa māja Berķenelē”',
            city: 'Birkineļi',
            region: 'Latgale',
            address: 'Birkineļi, Kalkūnes pagasts, Augšdaugavas novads, LV-5449',
            placeType: 'museum',
            categoryNames: ['muzika', 'citi'],
            isFree: true,
            priceMin: 0.0,
            priceMax: 0.0,
            currency: 'EUR',
            ticketUrl: 'https://www.augsdaugavasnovads.lv/lv/notikums/dzejas-dienas-berkenele',
            imageUrl: 'https://www.augsdaugavasnovads.lv/images/berkenele.jpg',
            sourceUrl: 'https://www.augsdaugavasnovads.lv/lv/notikums/dzejas-dienas-berkenele',
            sourceExternalId: 'augsdaugavas_dzejas-dienas-berkenele_202611251400',
        );

        $result = $ingestService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        // Check Event in DB
        $event = Event::where('source_id', $source->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('Dzejas dienas Raiņa mājā Berķenelē', $event->title);
        $this->assertTrue((bool)$event->is_free);

        // Check Location linked
        $location = Location::find($event->location_id);
        $this->assertNotNull($location);
        $this->assertEquals('Kultūras centrs Raiņa māja Berķenelē', $location->name);
        $this->assertEquals('Birkineļi', $location->city);
        $this->assertEquals('Latgale', $location->region);

        // Check Category pivots (specific category 'muzika' is retained; 'citi' fallback pruned per service rule)
        $categories = $event->categories()->pluck('slug')->toArray();
        $this->assertContains('muzika', $categories);
        $this->assertNotContains('citi', $categories);
    }
}
