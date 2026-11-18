<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\KuldigasNovadsScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KuldigasNovadsScraperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Category::updateOrCreate(['slug' => 'kino'], ['name' => 'Kino']);
        Category::updateOrCreate(['slug' => 'teatris'], ['name' => 'Teātris']);
        Category::updateOrCreate(['slug' => 'muzika'], ['name' => 'Mūzika']);
        Category::updateOrCreate(['slug' => 'izstades'], ['name' => 'Izstādes']);
        Category::updateOrCreate(['slug' => 'sports'], ['name' => 'Sports']);
        Category::updateOrCreate(['slug' => 'berniem'], ['name' => 'Bērniem']);
        Category::updateOrCreate(['slug' => 'citi'], ['name' => 'Cits']);
    }

    public function test_metadata(): void
    {
        $scraper = new KuldigasNovadsScraper();
        $this->assertEquals('kuldigas-novads', $scraper->getSlug());
        $this->assertEquals('Kuldīgas novada pašvaldība', $scraper->getName());
        $this->assertEquals('https://kalendars.kuldigasnovads.lv', KuldigasNovadsScraper::BASE_URL);
        $this->assertEquals('/event/', KuldigasNovadsScraper::CALENDAR_PATH);
    }

    public function test_skips_administrative_and_council_meetings(): void
    {
        $scraper = new KuldigasNovadsScraper();

        // 1. Categories with council/committee meetings must be skipped
        $this->assertTrue($scraper->isExcludedEvent(['Komitejas sēde'], 'Sēdes darba kārtība'));
        $this->assertTrue($scraper->isExcludedEvent(['Finanšu komitejas sēde'], 'Kuldīgas novada domes Finanšu komitejas sēde'));
        $this->assertTrue($scraper->isExcludedEvent(['Domes sēde'], 'Kuldīgas novada domes sēde'));
        $this->assertTrue($scraper->isExcludedEvent(['Pašvaldība', 'ārkārtas sēdes'], 'Ārkārtas sēdes norise'));

        // 2. Titles with council/committee meetings must be skipped
        $this->assertTrue($scraper->isExcludedEvent([], 'Kuldīgas novada domes Finanšu komitejas sēde'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Domes priekšsēdētāja pieņem iedzīvotājus'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Lokālplānojuma publiskā apspriešana'));

        // 3. Cultural and entertainment events must NOT be skipped
        $this->assertFalse($scraper->isExcludedEvent(['Koncerts'], 'Svētku koncerts Turlavā'));
        $this->assertFalse($scraper->isExcludedEvent(['Kino'], 'Kino: Ceļš nekad nebeidzas'));
        $this->assertFalse($scraper->isExcludedEvent(['Teātris'], 'Izrāde Kuldīgas kultūras centrā'));
        $this->assertFalse($scraper->isExcludedEvent(['Izstādes'], 'Izstāde "Apturēt laiku"'));
        $this->assertFalse($scraper->isExcludedEvent(['Sports'], 'Kuldīgas novada atklātais čempionāts volejbolā sievietēm'));
    }

    public function test_date_parsing(): void
    {
        $scraper = new KuldigasNovadsScraper();

        // 1. Single numeric date with time: "20.11.2026", "19:00"
        [$start1, $end1, $allDay1] = $scraper->parseLatvianDates('20.11.2026', '19:00');
        $this->assertEquals('2026-11-20 19:00:00', $start1->toDateTimeString());
        $this->assertEquals('2026-11-20 21:00:00', $end1->toDateTimeString());
        $this->assertFalse($allDay1);

        // 2. Date range without time: "12.06.2026 - 10.01.2027"
        [$start2, $end2, $allDay2] = $scraper->parseLatvianDates('12.06.2026 - 10.01.2027', '');
        $this->assertEquals('2026-06-12 00:00:00', $start2->toDateTimeString());
        $this->assertEquals('2027-01-10 23:59:00', $end2->toDateTimeString());
        $this->assertTrue($allDay2);

        // 3. Date range with time range: "15.11.2026 - 18.11.2026", "10:00 - 18:00"
        [$start3, $end3, $allDay3] = $scraper->parseLatvianDates('15.11.2026 - 18.11.2026', '10:00 - 18:00');
        $this->assertEquals('2026-11-15 10:00:00', $start3->toDateTimeString());
        $this->assertEquals('2026-11-18 18:00:00', $end3->toDateTimeString());
        $this->assertFalse($allDay3);

        // 4. Latvian month name: "19. novembris 2026"
        [$start4, $end4, $allDay4] = $scraper->parseLatvianDates('19. novembris 2026', '15:30');
        $this->assertEquals('2026-11-19 15:30:00', $start4->toDateTimeString());
        $this->assertFalse($allDay4);
    }

    public function test_location_resolving(): void
    {
        $scraper = new KuldigasNovadsScraper();

        // 1. Known venue: Kuldīgas Kultūras centrs
        $loc1 = $scraper->resolveLocation('Kuldīgas Kultūras centrs', 'Piltenes iela 32, Kuldīga', 'Koncerts', 'Apraksts', 56.9758, 21.9542);
        $this->assertEquals('Kuldīgas Kultūras centrs', $loc1['name']);
        $this->assertEquals('Kuldīga', $loc1['city']);
        $this->assertEquals('Kurzeme', $loc1['region']);
        $this->assertEquals('cultural_center', $loc1['place_type']);
        $this->assertEquals(56.9758, $loc1['latitude']);
        $this->assertEquals(21.9542, $loc1['longitude']);

        // 2. Known venue: Kuldīgas novada muzejs
        $loc2 = $scraper->resolveLocation('Kuldīgas novada muzejs', '', 'Izstāde', '');
        $this->assertEquals('Kuldīgas novada muzejs', $loc2['name']);
        $this->assertEquals('Kuldīga', $loc2['city']);
        $this->assertEquals('museum', $loc2['place_type']);
        $this->assertStringContainsString('Pils iela 5', $loc2['address']);

        // 3. Known venue in Skrunda
        $loc3 = $scraper->resolveLocation('Skrundas kultūras nams', '', 'Balle', '');
        $this->assertEquals('Skrundas Kultūras nams', $loc3['name']);
        $this->assertEquals('Skrunda', $loc3['city']);
        $this->assertEquals('Kurzeme', $loc3['region']);

        // 4. Known venue in Alsunga
        $loc4 = $scraper->resolveLocation('Alsungas pils', '', 'Ekskursija', '');
        $this->assertEquals('Alsungas Livonijas ordeņa pils', $loc4['name']);
        $this->assertEquals('Alsunga', $loc4['city']);

        // 5. Parish detection from text
        $loc5 = $scraper->resolveLocation('Turlavas pagasta pārvalde', '', 'Pasākums Turlavā', 'Gaidām visus Turlavas centrā');
        $this->assertEquals('Turlava', $loc5['city']);
        $this->assertEquals('Kurzeme', $loc5['region']);
    }

    public function test_category_resolving_strictly_follows_agents_md(): void
    {
        $scraper = new KuldigasNovadsScraper();

        // 1. Cinema / Kino strict rule: movies MUST be KINO and NEVER TEATRIS
        $cats1 = $scraper->resolveCategories(['Kino'], 'Kino: Ceļš nekad nebeidzas', 'Dokumentālā filma par alpīnistu Teodoru Ķirsi');
        $this->assertEquals(['Kino'], $cats1);

        $cats2 = $scraper->resolveCategories([], 'Spēlfilma "Zeme, kas dzied"', 'Kinoseanss Kuldīgā');
        $this->assertEquals(['Kino'], $cats2);

        // 2. Theatre
        $cats3 = $scraper->resolveCategories(['Teātris'], 'Dailes teātra viesizrāde "Spēles ar uguni"');
        $this->assertEquals(['Teātris'], $cats3);

        // 3. Music
        $cats4 = $scraper->resolveCategories([], 'Grupas "Raxtu Raxti" lielkoncerts Kuldīgā');
        $this->assertEquals(['Mūzika'], $cats4);

        // 4. Exhibitions
        $cats5 = $scraper->resolveCategories(['Izstādes'], 'Izstāde "Apturēt laiku"');
        $this->assertEquals(['Izstādes'], $cats5);

        // 5. Sports
        $cats6 = $scraper->resolveCategories(['Sports'], 'Kuldīgas atklātais čempionāts volejbolā');
        $this->assertEquals(['Sports'], $cats6);

        // 6. Fallback -> Cits
        $cats7 = $scraper->resolveCategories([], 'Kuldīgas novada senioru tikšanās un diskusija');
        $this->assertEquals(['Cits'], $cats7);
    }

    public function test_price_parsing(): void
    {
        $scraper = new KuldigasNovadsScraper();

        // 1. Single price "4.20"
        $p1 = $scraper->parsePrice('4.20');
        $this->assertFalse($p1['is_free']);
        $this->assertEquals(4.20, $p1['price_min']);
        $this->assertEquals(4.20, $p1['price_max']);

        // 2. Range "10.00 - 15.00"
        $p2 = $scraper->parsePrice('10.00 - 15.00');
        $this->assertFalse($p2['is_free']);
        $this->assertEquals(10.0, $p2['price_min']);
        $this->assertEquals(15.0, $p2['price_max']);

        // 3. Free
        $p3 = $scraper->parsePrice('', 'Ieeja bez maksas.');
        $this->assertTrue($p3['is_free']);
        $this->assertNull($p3['price_min']);
        $this->assertNull($p3['price_max']);
    }

    public function test_scrape_with_mocked_http(): void
    {
        $archiveHtml = <<<'HTML'
<!DOCTYPE html>
<html>
<body>
    <div class="ovaev-content">
        <div class="type1">
            <div class="event-thumbnail">
                <a href="https://kalendars.kuldigasnovads.lv/event/kino-cels-nekad-nebeidzas/">
                    <img src="https://kalendars.kuldigasnovads.lv/uploads/thumb.jpg" />
                </a>
            </div>
            <div class="event_post">
                <div class="post_cat"><a class="event_type" href="#">Kino</a></div>
                <h2 class="event_title">
                    <a href="https://kalendars.kuldigasnovads.lv/event/kino-cels-nekad-nebeidzas/">Kino: Ceļš nekad nebeidzas</a>
                </h2>
                <div class="venue"><span class="number">Kuldīgas kultūras centrs</span></div>
            </div>
        </div>
    </div>
</body>
</html>
HTML;

        $detailHtml = <<<'HTML'
<!DOCTYPE html>
<html>
<body>
    <h2 class="ovaev-event-title">Kino: Ceļš nekad nebeidzas</h2>
    <div class="ovaev-event-date">
        <span class="second_font">25.11.2026</span>
    </div>
    <div class="ovaev-event-time">
        <span class="second_font">18:00</span>
    </div>
    <div class="ovaev-event-location">Kuldīgas kultūras centrs</div>
    <div class="ovaev-price-widget"><span class="ovaev-price-widget__text">4.20</span></div>
    <div class="ovaev-event-content">
        <p>Dokumentāla filma par leģendāro alpīnistu Teodoru Ķirsi.</p>
    </div>
    <div class="tab-content">
        <div data-address="Piltenes iela 32, Kuldīga" data-lat="56.9758" data-lng="21.9542"></div>
    </div>
    <div class="ovaev-booking-btn">
        <a href="https://www.bilesuparadize.lv/lv/event/180000">Pirkt biļeti</a>
    </div>
    <div class="ovaev-event-thumbnail">
        <img src="https://kalendars.kuldigasnovads.lv/uploads/full.jpg" />
    </div>
</body>
</html>
HTML;

        $mock = new MockHandler([
            new Response(200, [], $archiveHtml), // Page 1 archive
            new Response(200, [], $detailHtml),  // Detail event page
            new Response(200, [], '<!DOCTYPE html><html><body></body></html>'), // Page 2 empty stop
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $scraper = new KuldigasNovadsScraper();
        $scraper->setClient($client);

        $source = Source::firstOrCreate(
            ['slug' => 'kuldigas-novads'],
            [
                'name' => 'Kuldīgas novada pašvaldība',
                'url' => 'https://kalendars.kuldigasnovads.lv/event/',
                'scraper_class' => KuldigasNovadsScraper::class,
                'type' => 'municipality',
                'is_active' => true,
            ]
        );

        $events = $scraper->scrape($source);

        $this->assertCount(1, $events);
        $dto = $events->first();

        $this->assertEquals('Kino: Ceļš nekad nebeidzas', $dto->title);
        $this->assertEquals('2026-11-25 18:00:00', $dto->startAt->toDateTimeString());
        $this->assertEquals('2026-11-25 20:00:00', $dto->endAt->toDateTimeString());
        $this->assertEquals('Kuldīgas Kultūras centrs', $dto->venueName);
        $this->assertEquals('Kuldīga', $dto->city);
        $this->assertEquals('Kurzeme', $dto->region);
        $this->assertEquals('Piltenes iela 32, Kuldīga', $dto->address);
        $this->assertEquals(56.9758, $dto->latitude);
        $this->assertEquals(21.9542, $dto->longitude);
        $this->assertEquals(['Kino'], $dto->categoryNames);
        $this->assertEquals('kino', $dto->entertainmentType);
        $this->assertFalse($dto->isFree);
        $this->assertEquals(4.20, $dto->priceMin);
        $this->assertEquals('https://www.bilesuparadize.lv/lv/event/180000', $dto->ticketUrl);
        $this->assertEquals('https://kalendars.kuldigasnovads.lv/uploads/full.jpg', $dto->imageUrl);
        $this->assertStringContainsString('Teodoru Ķirsi', $dto->description);
    }

    public function test_kuldigas_novads_event_ingestion(): void
    {
        $source = Source::firstOrCreate(
            ['slug' => 'kuldigas-novads'],
            [
                'name' => 'Kuldīgas novada pašvaldība',
                'url' => 'https://kalendars.kuldigasnovads.lv/event/',
                'scraper_class' => KuldigasNovadsScraper::class,
                'type' => 'municipality',
                'is_active' => true,
            ]
        );

        $scraper = new KuldigasNovadsScraper();

        $dto = new \App\Services\Scrapers\DTO\ScrapedEventDTO(
            title: 'Kuldīgas Ziemassvētku koncerts',
            startAt: Carbon::tomorrow('Europe/Riga')->setTime(19, 0),
            endAt: Carbon::tomorrow('Europe/Riga')->setTime(21, 0),
            description: 'Svinīgs Ziemassvētku koncerts Kuldīgas Kultūras centrā ar kori un orķestri.',
            shortDescription: 'Svinīgs Ziemassvētku koncerts Kuldīgas Kultūras centrā.',
            venueName: 'Kuldīgas Kultūras centrs',
            city: 'Kuldīga',
            region: 'Kurzeme',
            address: 'Piltenes iela 32, Kuldīga, Kuldīgas novads, LV-3301',
            latitude: 56.9758,
            longitude: 21.9542,
            placeType: 'cultural_center',
            categoryNames: ['Mūzika'],
            entertainmentType: 'koncerts',
            isFree: false,
            priceMin: 15.0,
            priceMax: 20.0,
            currency: 'EUR',
            ticketUrl: 'https://www.bilesuparadize.lv/lv/event/199999',
            imageUrl: 'https://kalendars.kuldigasnovads.lv/uploads/koncerts.jpg',
            sourceUrl: 'https://kalendars.kuldigasnovads.lv/event/ziemassvetku-koncerts/',
            sourceExternalId: 'kuldigasnovads-ziemassvetku-koncerts',
            locale: 'lv'
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_external_id', 'kuldigasnovads-ziemassvetku-koncerts')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Kuldīgas Ziemassvētku koncerts', $event->title);
        $this->assertEquals('kuldigas-novads', $event->source_slug);
        $this->assertEquals(15.0, (float) $event->price_min);
        $this->assertEquals(20.0, (float) $event->price_max);
        $this->assertEquals('Kuldīgas Kultūras centrs', $event->location->name);
        $this->assertEquals('Kuldīga', $event->location->city);
        $this->assertEquals('Kurzeme', $event->location->region);
        $this->assertTrue($event->categories->contains('slug', 'muzika'));
    }
}
