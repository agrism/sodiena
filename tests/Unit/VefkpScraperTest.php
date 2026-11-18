<?php

namespace Tests\Unit;

use App\Enums\SourceType;
use App\Models\Category;
use App\Models\Event;
use App\Models\Location;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\VefkpScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VefkpScraperTest extends TestCase
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
        Category::firstOrCreate(['slug' => 'seminari'], ['name' => 'Semināri']);
        Category::firstOrCreate(['slug' => 'svetki'], ['name' => 'Svētki']);
        Category::firstOrCreate(['slug' => 'citi'], ['name' => 'Cits']);

        // Create standard VEF KP Location (ID 99 in production)
        Location::firstOrCreate(
            ['slug' => 'vef-kulturas-pils-riga'],
            [
                'name' => 'VEF Kultūras pils',
                'city' => 'Rīga',
                'region' => 'Rīga un Pierīga',
                'address' => 'Ropažu iela 2, Rīga',
                'latitude' => 56.9745,
                'longitude' => 24.16605,
                'place_type' => 'cultural_centre',
            ]
        );
    }

    public function test_metadata(): void
    {
        $scraper = new VefkpScraper();
        $this->assertEquals('vef-kulturas-pils', $scraper->getSlug());
        $this->assertEquals('VEF Kultūras pils', $scraper->getName());
    }

    public function test_date_parsing_various_patterns(): void
    {
        $scraper = new VefkpScraper();

        // 1. Single date with time
        [$s1, $e1, $ad1] = $scraper->parseEventDates('Pk. 02.10.2026. 19:00');
        $this->assertNotNull($s1);
        $this->assertNull($e1);
        $this->assertFalse($ad1);
        $this->assertEquals('2026-10-02 19:00', $s1->format('Y-m-d H:i'));

        // 2. Date with time range
        [$s2, $e2, $ad2] = $scraper->parseEventDates('Sv. 11.10.2026. 13:00 - 17:00');
        $this->assertNotNull($s2);
        $this->assertNotNull($e2);
        $this->assertFalse($ad2);
        $this->assertEquals('2026-10-11 13:00', $s2->format('Y-m-d H:i'));
        $this->assertEquals('2026-10-11 17:00', $e2->format('Y-m-d H:i'));

        // 3. Multi-day range with times
        [$s3, $e3, $ad3] = $scraper->parseEventDates('O. 13.10.2026. 9:00 - Sv. 29.11.2026. 20:00');
        $this->assertNotNull($s3);
        $this->assertNotNull($e3);
        $this->assertFalse($ad3);
        $this->assertEquals('2026-10-13 09:00', $s3->format('Y-m-d H:i'));
        $this->assertEquals('2026-11-29 20:00', $e3->format('Y-m-d H:i'));

        // 4. Multi-day range dates only
        [$s4, $e4, $ad4] = $scraper->parseEventDates('13.10.2026. - 29.11.2026.');
        $this->assertNotNull($s4);
        $this->assertNotNull($e4);
        $this->assertTrue($ad4);
        $this->assertEquals('2026-10-13', $s4->format('Y-m-d'));
        $this->assertEquals('2026-11-29', $e4->format('Y-m-d'));

        // 5. Single date only
        [$s5, $e5, $ad5] = $scraper->parseEventDates('15.12.2026.');
        $this->assertNotNull($s5);
        $this->assertNull($e5);
        $this->assertTrue($ad5);
        $this->assertEquals('2026-12-15', $s5->format('Y-m-d'));
    }

    public function test_category_resolution_strictly_follows_agents_md(): void
    {
        $scraper = new VefkpScraper();

        // 1. Strict Kino priority rule: Filma or kinoseanss is ALWAYS KINO and NEVER TEATRIS
        $cats1 = $scraper->resolveCategories('45', 'Animācijas filma | Straume', 'Lieliskā filma visai ģimenei kinoteātrī');
        $this->assertEquals(['kino'], $cats1);

        // 2. Kinofestivāls / kinoseanss
        $cats2 = $scraper->resolveCategories('45;44', 'Rīgas kinofestivāla filmas seanss', 'Dokumentālā filma par mūziku');
        $this->assertEquals(['kino'], $cats2);

        // 3. Actual Theatre production (izrāde, luga, stand-up izrāde)
        $cats3 = $scraper->resolveCategories('45', 'EDGARS BĀLIŅŠ | stand-up izrāde VIDĒJAIS LATVIETIS', 'Stāvizrāde ar jokiem');
        $this->assertContains('teatris', $cats3);

        // 4. Operete / opera / balets
        $cats4 = $scraper->resolveCategories('45;44', 'PARĪZES BOHĒMA ǀ eko-modes operete', 'Operetes iestudējums');
        $this->assertContains('teatris', $cats4);
        $this->assertContains('muzika', $cats4);

        // 5. Concerts / music (WP cat 44)
        $cats5 = $scraper->resolveCategories('44', 'KONCERTS | Latvian Blues Band', 'Blūza grupas uzstāšanās');
        $this->assertEquals(['muzika'], $cats5);

        // 6. Kids / berniem (WP cat 261)
        $cats6 = $scraper->resolveCategories('261', 'Muzikāls uzvedums bērniem | Pasaka par princesi', 'Bērnu koncerts');
        $this->assertContains('berniem', $cats6);

        // 7. Exhibitions (WP cat 41)
        $cats7 = $scraper->resolveCategories('41', 'VISVALDIS ASARIS | ZEME', 'Mākslinieka personālizstāde');
        $this->assertEquals(['izstades'], $cats7);

        // 8. Workshops / Masterclasses (WP cat 314, 54)
        $cats8 = $scraper->resolveCategories('314', 'MEISTARKLASE | Keramikas veidošana', 'Radošā darbnīca');
        $this->assertEquals(['seminari'], $cats8);

        // 9. Fallback -> citi
        $cats9 = $scraper->resolveCategories('', 'VEF vēstures diskusiju klubs', 'Ikgadējā sapulce');
        $this->assertEquals(['citi'], $cats9);
    }

    public function test_mocked_scrape_and_dto_construction(): void
    {
        $today = Carbon::today('Europe/Riga');
        $upcomingDateStr = $today->copy()->addDays(5)->format('d.m.Y.');
        $pastDateStr = $today->copy()->subDays(10)->format('d.m.Y.');

        $homeHtml = <<<'HTML'
<!doctype html>
<html>
<head>
    <script>
    var php_vars = {"home":"https://www.vefkp.lv","ajax_url":"https://www.vefkp.lv/wp-admin/admin-ajax.php","nonce":"testnonce123"};
    </script>
</head>
<body></body>
</html>
HTML;

        $archiveHtml = <<<HTML
<div class="loop-event" data-event_categories="44" data-venues="47" data-date-from="" data-date-to="">
    <div class="event-inner">
        <div class="event-image">
            <img src="https://www.vefkp.lv/uploads/mauglis.jpg" alt="">
        </div>
        <div class="event-date">Pk. {$upcomingDateStr} 19:00</div>
        <div class="event-title"><h2>ROKOPERA | MAUGLIS</h2></div>
        <div class="event-description">Lielā zāle</div>
        <div class="event-links">
            <a href="https://www.bilesuparadize.lv/lv/event/153414" class="btn-primary" target="_blank">Pirkt biļetes</a>
            <a href="https://www.vefkp.lv/pasakumi/rokopera-mauglis/" class="btn-outline">Uzzināt vairāk</a>
        </div>
    </div>
</div>
<div class="loop-event" data-event_categories="41" data-venues="47">
    <div class="event-inner">
        <div class="event-image">
            <img src="https://www.vefkp.lv/uploads/asaris.jpg" alt="">
        </div>
        <div class="event-date">O. {$upcomingDateStr} 10:00 - Sv. {$upcomingDateStr} 18:00</div>
        <div class="event-title"><h2>VISVALDIS ASARIS | ZEME</h2></div>
        <div class="event-description">Izstāžu zāle</div>
        <div class="event-links">
            <a href="" class="btn-primary disabled">Bez maksas</a>
            <a href="https://www.vefkp.lv/pasakumi/visvaldis-asaris-zeme/" class="btn-outline">Uzzināt vairāk</a>
        </div>
    </div>
</div>
<div class="loop-event" data-event_categories="44">
    <div class="event-inner">
        <div class="event-date">Pk. {$pastDateStr} 19:00</div>
        <div class="event-title"><h2>PAGĀTNES KONCERTS</h2></div>
        <div class="event-links">
            <a href="https://www.vefkp.lv/pasakumi/pagatnes-koncerts/" class="btn-outline">Uzzināt</a>
        </div>
    </div>
</div>
HTML;

        $detailHtml = <<<'HTML'
<!doctype html>
<html>
<body>
    <div class="hero-title"><h1>ROKOPERA | MAUGLIS</h1></div>
    <div class="hero-date">Pk. 19:00</div>
    <div class="hero-description"><p>Lielā zāle</p></div>
    <div class="hero-links">
        <a href="https://www.bilesuparadize.lv/lv/event/153414" class="btn-primary">Pirkt biļetes</a>
    </div>
    <div class="article-content">
        <p>Rokopera “Mauglis” ir vērienīgākais muzikālais iestudējums bērniem un jauniešiem.</p>
        <p>Pasākuma organizators informē, ka Pasākuma laikā tiks veikta foto un video uzņemšana.</p>
    </div>
</body>
</html>
HTML;

        $detailFreeHtml = <<<'HTML'
<!doctype html>
<html>
<body>
    <div class="hero-title"><h1>VISVALDIS ASARIS | ZEME</h1></div>
    <div class="hero-description"><p>Izstāžu zāle</p></div>
    <div class="hero-links">
        <a href="" class="btn-primary disabled">Bez maksas</a>
    </div>
    <div class="article-content">
        <p>Visvalža Asara personālizstāde ZEME skatāma Izstāžu zālē.</p>
    </div>
</body>
</html>
HTML;

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $homeHtml),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'archive' => $archiveHtml,
                'calendar' => '',
                'days' => []
            ])),
            // Detail page for Mauglis
            new Response(200, ['Content-Type' => 'text/html'], $detailHtml),
            // Detail page for Asaris
            new Response(200, ['Content-Type' => 'text/html'], $detailFreeHtml),
            // Subsequent month queries return empty array to trigger early break
            new Response(200, ['Content-Type' => 'application/json'], json_encode([])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $scraper = new VefkpScraper();
        $scraper->setClient($client);

        $source = new Source([
            'id' => 999,
            'name' => 'VEF Kultūras pils',
            'slug' => 'vef-kulturas-pils',
            'url' => 'https://www.vefkp.lv/pasakumi/',
            'type' => SourceType::VENUE,
            'is_active' => true,
        ]);

        $events = $scraper->scrape($source);

        // Past event must be skipped -> 2 events remaining
        $this->assertCount(2, $events);

        // Event 1: Mauglis
        $e1 = $events->firstWhere('title', 'ROKOPERA | MAUGLIS');
        $this->assertNotNull($e1);
        $this->assertEquals('VEF Kultūras pils', $e1->venueName);
        $this->assertEquals('Rīga', $e1->city);
        $this->assertEquals('Ropažu iela 2, Rīga, LV-1039', $e1->address);
        $this->assertEquals('https://www.bilesuparadize.lv/lv/event/153414', $e1->ticketUrl);
        $this->assertEquals('https://www.vefkp.lv/uploads/mauglis.jpg', $e1->imageUrl);
        $this->assertStringContainsString('Rokopera “Mauglis”', $e1->description);
        $this->assertStringNotContainsString('Pasākuma organizators informē', $e1->description);
        $this->assertStringContainsString('Norises vieta: Lielā zāle', $e1->description);
        $this->assertContains('muzika', $e1->categoryNames);
        $this->assertFalse($e1->isFree);

        // Event 2: Free exhibition Asaris
        $e2 = $events->firstWhere('title', 'VISVALDIS ASARIS | ZEME');
        $this->assertNotNull($e2);
        $this->assertTrue($e2->isFree);
        $this->assertEquals(0.0, $e2->priceMin);
        $this->assertContains('izstades', $e2->categoryNames);
        $this->assertStringStartsWith('Norises vieta: Izstāžu zāle', $e2->description);
    }

    public function test_vefkp_event_ingestion_and_location_link(): void
    {
        $source = Source::create([
            'name' => 'VEF Kultūras pils',
            'slug' => 'vef-kulturas-pils',
            'url' => 'https://www.vefkp.lv/pasakumi/',
            'scraper_class' => VefkpScraper::class,
            'type' => SourceType::VENUE,
            'is_active' => true,
        ]);

        $dto = new ScrapedEventDTO(
            title: 'ROKOPERA | MAUGLIS',
            startAt: Carbon::tomorrow('Europe/Riga')->setTime(19, 0),
            endAt: null,
            description: 'Norises vieta: Lielā zāle\n\nRokopera Mauglis VEF Kultūras pilī.',
            shortDescription: 'Rokopera Mauglis',
            venueName: 'VEF Kultūras pils',
            city: 'Rīga',
            region: 'Rīga un Pierīga',
            address: 'Ropažu iela 2, Rīga, LV-1039',
            latitude: 56.9745,
            longitude: 24.16605,
            placeType: 'cultural_centre',
            categoryNames: ['muzika', 'teatris'],
            isFree: false,
            priceMin: null,
            priceMax: null,
            currency: 'EUR',
            ticketUrl: 'https://www.bilesuparadize.lv/lv/event/153414',
            imageUrl: 'https://www.vefkp.lv/uploads/mauglis.jpg',
            sourceUrl: 'https://www.vefkp.lv/pasakumi/rokopera-mauglis-2/',
            sourceExternalId: 'vefkp_rokopera-mauglis_202611251900',
            rawData: [
                'hall' => 'Lielā zāle',
                'ticket_url' => 'https://www.bilesuparadize.lv/lv/event/153414',
            ]
        );

        $service = app(EventIngestionService::class);
        $result = $service->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_id', $source->id)
            ->where('source_external_id', 'vefkp_rokopera-mauglis_202611251900')
            ->first();

        $this->assertNotNull($event);
        $this->assertEquals('ROKOPERA | MAUGLIS', $event->title);
        $this->assertEquals('https://www.bilesuparadize.lv/lv/event/153414', $event->ticket_url);

        // Must be linked to Location 'VEF Kultūras pils'
        $this->assertNotNull($event->location);
        $this->assertEquals('VEF Kultūras pils', $event->location->name);
        $this->assertEquals('Rīga', $event->location->city);

        // Verify categories
        $categories = $event->categories->pluck('slug')->toArray();
        $this->assertContains('muzika', $categories);
    }
}
