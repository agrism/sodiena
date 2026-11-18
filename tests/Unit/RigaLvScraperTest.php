<?php

namespace Tests\Unit;

use App\Enums\SourceType;
use App\Models\Category;
use App\Models\Event;
use App\Models\Location;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\RigaLvScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RigaLvScraperTest extends TestCase
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
        $scraper = new RigaLvScraper();
        $this->assertEquals('riga-lv', $scraper->getSlug());
        $this->assertEquals('Rīgas valstspilsēta', $scraper->getName());
    }

    public function test_skips_administrative_and_council_meetings(): void
    {
        $scraper = new RigaLvScraper();

        $this->assertTrue($scraper->isExcludedEvent([], 'Rīgas domes svinīgā sēde'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Finanšu un administrācijas lietu komitejas sēde'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Satiksmes un transporta lietu komitejas sēde'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Iepirkumu komisijas sēde'));
        $this->assertTrue($scraper->isExcludedEvent(['Sēde'], 'Kārtējā sēde'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Nekustamā īpašuma izsole'));

        // Cultural events must NOT be excluded
        $this->assertFalse($scraper->isExcludedEvent(['Svētki'], 'Svētku pasākumi 18. novembrī'));
        $this->assertFalse($scraper->isExcludedEvent(['Izstādes'], 'Porcelāna muzeja krājuma izstāde'));
        $this->assertFalse($scraper->isExcludedEvent(['Koncerti'], 'Orķestra RĪGA svētku koncerts'));
    }

    public function test_date_and_time_parsing(): void
    {
        $scraper = new RigaLvScraper();

        // 1. Single date with time range
        [$s1, $e1, $ad1] = $scraper->parseLatvianDates('Datums 18. novembris, 2026', 'Laiks 10.00–18.00');
        $this->assertNotNull($s1);
        $this->assertNotNull($e1);
        $this->assertFalse($ad1);
        $this->assertEquals('2026-11-18 10:00:00', $s1->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-18 18:00:00', $e1->format('Y-m-d H:i:s'));

        // 2. Single date all day
        [$s2, $e2, $ad2] = $scraper->parseLatvianDates('18. novembris, 2026', 'Visu dienu');
        $this->assertNotNull($s2);
        $this->assertNotNull($e2);
        $this->assertTrue($ad2);
        $this->assertEquals('2026-11-18 00:00:00', $s2->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-18 23:59:00', $e2->format('Y-m-d H:i:s'));

        // 3. Multi-day range within same month
        [$s3, $e3, $ad3] = $scraper->parseLatvianDates('Datums 15.–18. novembris, 2026', 'Laiks 17.00–23.00');
        $this->assertNotNull($s3);
        $this->assertNotNull($e3);
        $this->assertFalse($ad3);
        $this->assertEquals('2026-11-15 17:00:00', $s3->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-18 23:00:00', $e3->format('Y-m-d H:i:s'));

        // 4. Multi-day cross-month range
        [$s4, $e4, $ad4] = $scraper->parseLatvianDates('3. novembris, 2026 – 6. decembris, 2026', '11.00–18.00');
        $this->assertNotNull($s4);
        $this->assertNotNull($e4);
        $this->assertEquals('2026-11-03 11:00:00', $s4->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-12-06 18:00:00', $e4->format('Y-m-d H:i:s'));
    }

    public function test_location_resolving(): void
    {
        $scraper = new RigaLvScraper();

        // 1. Porcelāna muzejs
        $loc1 = $scraper->resolveLocation('Rīga', 'Rīgas Porcelāna muzejā apskatāma izstāde', 'Kalēju iela');
        $this->assertEquals('Rīgas Porcelāna muzejs', $loc1['name']);
        $this->assertEquals('Kalēju iela 9/11, Rīga, LV-1050', $loc1['address']);
        $this->assertEquals('museum', $loc1['place_type']);

        // 2. Ziemeļblāzma
        $loc2 = $scraper->resolveLocation('Rīga', 'Koncerts “Rīgas pastkartes Latvijai”', 'Kultūras pilī “Ziemeļblāzma”');
        $this->assertEquals('Kultūras pils “Ziemeļblāzma”', $loc2['name']);
        $this->assertEquals('Ziemeļblāzmas iela 36, Rīga, LV-1015', $loc2['address']);

        // 3. Krastmala
        $loc3 = $scraper->resolveLocation('Rīga', 'Militārā parāde 11. novembra krastmalā', '');
        $this->assertEquals('11. novembra krastmala', $loc3['name']);

        // 4. Splendid Palace
        $loc4 = $scraper->resolveLocation('Rīga', 'Filmu festivāls kinoteātrī Splendid Palace', '');
        $this->assertEquals('Kinoteātris “Splendid Palace”', $loc4['name']);
        $this->assertEquals('cinema', $loc4['place_type']);

        // 5. Fallback
        $loc5 = $scraper->resolveLocation('Rīga', 'Pasākums pilsētas centrā', '');
        $this->assertEquals('Rīga', $loc5['name']);
        $this->assertEquals('Rīga, Latvija', $loc5['address']);
    }

    public function test_category_resolving_strictly_follows_agents_md(): void
    {
        $scraper = new RigaLvScraper();

        // 1. Strict Kino rule: Splendid Palace is ALWAYS KINO, NEVER TEATRIS
        $cats1 = $scraper->resolveCategories(['Teātris'], 'Izrāde kinoteātrī Splendid Palace', '', 'Kinoteātris “Splendid Palace”');
        $this->assertEquals(['kino'], $cats1);

        // 2. Strict Kino rule: Filmas seanss is ALWAYS KINO
        $cats2 = $scraper->resolveCategories(['Pasākumi'], 'Dokumentālās filmas seanss', 'Kinoseanss visai ģimenei');
        $this->assertEquals(['kino'], $cats2);

        // 3. Actual Theatre production
        $cats3 = $scraper->resolveCategories(['Teātris'], 'Amatierteātra izrāde “Skroderdienas Silmačos”', 'Lugas iestudējums');
        $this->assertEquals(['teatris'], $cats3);

        // 4. Exhibitions
        $cats4 = $scraper->resolveCategories(['Izstādes'], 'Porcelāna izstāde', 'Mākslas darbu ekspozīcija');
        $this->assertEquals(['izstades'], $cats4);

        // 5. Concerts / music
        $cats5 = $scraper->resolveCategories(['Pasākumi'], 'Orķestra “Rīga” svētku koncerts', 'Muzikāls uzvedums');
        $this->assertEquals(['muzika'], $cats5);

        // 6. Celebrations / Svētki
        $cats6 = $scraper->resolveCategories(['Svētki'], 'Gaismas festivāls “Staro Rīga 2026”', 'Svinības pilsētā');
        $this->assertEquals(['svetki'], $cats6);
    }

    public function test_price_parsing(): void
    {
        $scraper = new RigaLvScraper();

        $p1 = $scraper->parsePrice('Ieeja bez maksas visiem apmeklētājiem.');
        $this->assertTrue($p1['is_free']);
        $this->assertEquals(0.0, $p1['price_min']);

        $p2 = $scraper->parsePrice('Biļetes cena: 5.00 EUR pie ieejas.');
        $this->assertFalse($p2['is_free']);
        $this->assertEquals(5.0, $p2['price_min']);

        $p3 = $scraper->parsePrice('Vispārējs pasākuma apraksts bez maksas norādes.');
        $this->assertTrue($p3['is_free']);
    }

    public function test_scrape_with_mocked_http(): void
    {
        $today = Carbon::today('Europe/Riga');
        $upcomingDateStr = $today->copy()->addDays(2)->format('d. F, Y');
        // Latvian month replacement
        $lvMonths = [
            'January' => 'janvāris', 'February' => 'februāris', 'March' => 'marts',
            'April' => 'aprīlis', 'May' => 'maijs', 'June' => 'jūnijs',
            'July' => 'jūlijs', 'August' => 'augusts', 'September' => 'septembris',
            'October' => 'oktobris', 'November' => 'novembris', 'December' => 'decembris'
        ];
        $enMonth = $today->copy()->addDays(2)->format('F');
        $upcomingDateStr = str_replace($enMonth, $lvMonths[$enMonth], $upcomingDateStr);

        $html = <<<HTML
<!doctype html>
<html>
<body>
<div class="view-notikumu-kalendars">
    <div class="event">
        <div class="event-info">
            <span class="date-date">Datums {$upcomingDateStr}</span>
            <span class="date-time">Laiks 17.00–23.00</span>
            <span class="describing-field-value">Rīga</span>
        </div>
        <div class="event-image">
            <img src="https://www.riga.lv/files/staro.jpg" alt="Staro Rīga">
        </div>
        <h2 class="event-title">
            <a href="/lv/notikums/staro-riga-2026?date=0">Staro Rīga 2026</a>
        </h2>
        <div class="event-content">
            <span>Svinot Latvijas dzimšanas dienu, ikviens aicināts piedzīvot kopīgus gaismas mirkļus. Ieeja bez maksas.</span>
        </div>
        <div class="event-categories">
            <a class="badge">Svētki</a>
        </div>
    </div>
    <div class="event">
        <div class="event-info">
            <span class="date-date">Datums {$upcomingDateStr}</span>
            <span class="date-time">Laiks 11.00–18.00</span>
            <span class="describing-field-value">Rīga</span>
        </div>
        <div class="event-image">
            <img src="https://www.riga.lv/files/porcelans.jpg" alt="Porcelāns">
        </div>
        <h2 class="event-title">
            <a href="/lv/notikums/porcelana-muzeja-izstade?date=0">Rīgas Porcelāna muzejā apskatāma izstāde</a>
        </h2>
        <div class="event-content">
            <span>Rīgas Porcelāna muzejā būs skatāma krājuma izstāde. Biļetes cena: 3.50 EUR.</span>
        </div>
        <div class="event-categories">
            <a class="badge">Izstādes</a>
        </div>
    </div>
    <div class="event">
        <h2 class="event-title">
            <a href="/lv/notikums/rigas-domes-sviniga-sede?date=0">Rīgas domes svinīgā sēde</a>
        </h2>
        <div class="event-categories">
            <a class="badge">Domes sēde</a>
        </div>
    </div>
</div>
</body>
</html>
HTML;

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'text/html'], '<html><body><div class="view-notikumu-kalendars"></div></body></html>'),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $scraper = new RigaLvScraper();
        $scraper->setClient($client);

        $source = new Source([
            'id' => 998,
            'name' => 'Rīgas valstspilsēta',
            'slug' => 'riga-lv',
            'url' => 'https://www.riga.lv/lv/notikumu-kalendars',
            'scraper_class' => RigaLvScraper::class,
            'type' => SourceType::MUNICIPALITY,
            'is_active' => true,
        ]);

        $events = $scraper->scrape($source);

        // Sēde must be skipped -> exactly 2 events
        $this->assertCount(2, $events);

        // Event 1: Staro Rīga
        $e1 = $events->firstWhere('title', 'Staro Rīga 2026');
        $this->assertNotNull($e1);
        $this->assertEquals('Rīga', $e1->city);
        $this->assertEquals('https://www.riga.lv/files/staro.jpg', $e1->imageUrl);
        $this->assertTrue($e1->isFree);
        $this->assertContains('svetki', $e1->categoryNames);

        // Event 2: Porcelāna muzejs
        $e2 = $events->firstWhere('title', 'Rīgas Porcelāna muzejā apskatāma izstāde');
        $this->assertNotNull($e2);
        $this->assertEquals('Rīgas Porcelāna muzejs', $e2->venueName);
        $this->assertEquals('Kalēju iela 9/11, Rīga, LV-1050', $e2->address);
        $this->assertFalse($e2->isFree);
        $this->assertEquals(3.50, $e2->priceMin);
        $this->assertContains('izstades', $e2->categoryNames);
    }

    public function test_riga_lv_event_ingestion(): void
    {
        $source = Source::create([
            'name' => 'Rīgas valstspilsēta',
            'slug' => 'riga-lv',
            'url' => 'https://www.riga.lv/lv/notikumu-kalendars',
            'scraper_class' => RigaLvScraper::class,
            'type' => SourceType::MUNICIPALITY,
            'is_active' => true,
        ]);

        $dto = new ScrapedEventDTO(
            title: 'Gaismas festivāls “Staro Rīga 2026”',
            startAt: Carbon::tomorrow('Europe/Riga')->setTime(17, 0),
            endAt: Carbon::tomorrow('Europe/Riga')->setTime(23, 0),
            description: 'Svinot Latvijas dzimšanas dienu, ikviens aicināts vērot gaismas instalācijas. Ieeja brīva.',
            shortDescription: 'Staro Rīga gaismas instalācijas.',
            venueName: '11. novembra krastmala',
            city: 'Rīga',
            region: 'Rīga un Pierīga',
            address: '11. novembra krastmala, Rīga',
            latitude: 56.9465,
            longitude: 24.1030,
            placeType: 'square',
            categoryNames: ['svetki'],
            isFree: true,
            priceMin: 0.0,
            priceMax: 0.0,
            currency: 'EUR',
            ticketUrl: 'https://www.riga.lv/lv/notikums/staro-riga-2026?date=0',
            imageUrl: 'https://www.riga.lv/files/staro.jpg',
            sourceUrl: 'https://www.riga.lv/lv/notikums/staro-riga-2026?date=0',
            sourceExternalId: 'riga_staro-riga-2026_202611201700',
            rawData: []
        );

        $service = app(EventIngestionService::class);
        $result = $service->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_id', $source->id)
            ->where('source_external_id', 'riga_staro-riga-2026_202611201700')
            ->first();

        $this->assertNotNull($event);
        $this->assertEquals('Gaismas festivāls “Staro Rīga 2026”', $event->title);
        $this->assertTrue($event->is_free);
        $this->assertNotNull($event->location);
        $this->assertEquals('Rīga', $event->location->city);

        $categories = $event->categories->pluck('slug')->toArray();
        $this->assertContains('svetki', $categories);
    }
}
