<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\VisitVentspilsScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class VisitVentspilsScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata(): void
    {
        $scraper = new VisitVentspilsScraper();
        $this->assertEquals('visitventspils', $scraper->getSlug());
        $this->assertEquals('VisitVentspils', $scraper->getName());
        $this->assertEquals('https://www.visitventspils.com', VisitVentspilsScraper::BASE_URL);
        $this->assertEquals('/pasakumi/', VisitVentspilsScraper::CALENDAR_PATH);
    }

    public function test_location_resolving_known_venues_and_novads(): void
    {
        $scraper = new VisitVentspilsScraper();

        // 1. Koncertzāle "Latvija"
        $loc1 = $scraper->resolveLocation('Lielais laukums', 'Simfoniskās mūzikas koncerts Koncertzālē "Latvija"');
        $this->assertEquals('Koncertzāle "Latvija"', $loc1['name']);
        $this->assertEquals('Ventspils', $loc1['city']);
        $this->assertEquals('Kurzeme', $loc1['region']);
        $this->assertEquals('Lielais laukums 1, Ventspils, LV-3601', $loc1['address']);
        $this->assertEquals('concert_hall', $loc1['place_type']);
        $this->assertEquals(57.3908, $loc1['lat']);

        // 2. Teātra nams "Jūras vārti"
        $loc2 = $scraper->resolveLocation('Teātra nams "Jūras vārti"', 'Rudens balle');
        $this->assertEquals('Teātra nams "Jūras vārti"', $loc2['name']);
        $this->assertEquals('Ventspils', $loc2['city']);
        $this->assertEquals('Karlīnes iela 40, Ventspils, LV-3601', $loc2['address']);
        $this->assertEquals('theater', $loc2['place_type']);

        // 3. Kino Rio
        $loc3 = $scraper->resolveLocation('Kino Rio', 'Zvaigžņu nakts');
        $this->assertEquals('Kino Rio', $loc3['name']);
        $this->assertEquals('Ventspils', $loc3['city']);
        $this->assertEquals('Pils iela 28, Ventspils, LV-3601', $loc3['address']);
        $this->assertEquals('cinema', $loc3['place_type']);

        // 4. Piejūras brīvdabas muzejs
        $loc4 = $scraper->resolveLocation('Piejūras brīvdabas muzejs', 'Mārtiņdienas svinības');
        $this->assertEquals('Piejūras brīvdabas muzejs', $loc4['name']);
        $this->assertEquals('museum', $loc4['place_type']);
        $this->assertEquals('Riņķa iela 2, Ventspils, LV-3601', $loc4['address']);

        // 5. Livonijas ordeņa pils
        $loc5 = $scraper->resolveLocation('Livonijas ordeņa pils', 'Vēstures ekspozīcija');
        $this->assertEquals('Livonijas ordeņa pils', $loc5['name']);
        $this->assertEquals('Jāņa iela 17, Ventspils, LV-3601', $loc5['address']);

        // 6. Zinātnes centrs "VIZIUM"
        $loc6 = $scraper->resolveLocation('Zinātnes centrs "VIZIUM"', 'Radošās darbnīcas');
        $this->assertEquals('Zinātnes centrs "VIZIUM"', $loc6['name']);
        $this->assertEquals('Rūpniecības iela 2, Ventspils, LV-3601', $loc6['address']);

        // 7. OC "Ventspils" / Olimpiskais centrs
        $loc7 = $scraper->resolveLocation('OC "Ventspils" ledus halle, Sporta iela 7/9', 'Hokeja mačs');
        $this->assertEquals('Olimpiskais centrs "Ventspils"', $loc7['name']);
        $this->assertEquals('sports_facility', $loc7['place_type']);

        // 8. Pārventas sporta komplekss
        $loc8 = $scraper->resolveLocation('Pārventas sporta komplekss, Tārgales iela 5b', 'Volejbola spēle');
        $this->assertEquals('Pārventas sporta komplekss', $loc8['name']);
        $this->assertEquals('Tārgales iela 5b, Ventspils, LV-3602', $loc8['address']);

        // 9. Piltene (Ventspils novads)
        $loc9 = $scraper->resolveLocation('Piltene', 'Lāčplēša diena Piltenē');
        $this->assertEquals('Piltenes kultūras nams', $loc9['name']);
        $this->assertEquals('Piltene', $loc9['city']);
        $this->assertEquals('Kurzeme', $loc9['region']);

        // 10. Jūrkalne (Ventspils novads)
        $loc10 = $scraper->resolveLocation('Jūrkalnes tautas nams', 'Svētku koncerts');
        $this->assertEquals('Jūrkalnes tautas nams', $loc10['name']);
        $this->assertEquals('Jūrkalne', $loc10['city']);

        // 11. Ugāle (Ventspils novads)
        $loc11 = $scraper->resolveLocation('Ugāles tautas nams "Gaisma"', 'Amatierteātra izrāde');
        $this->assertEquals('Ugāles tautas nams "Gaisma"', $loc11['name']);
        $this->assertEquals('Ugāle', $loc11['city']);

        // 12. Fallback
        $loc12 = $scraper->resolveLocation('', 'Pilsētas svētki');
        $this->assertEquals('Ventspils', $loc12['name']);
        $this->assertEquals('Ventspils', $loc12['city']);
        $this->assertEquals('Kurzeme', $loc12['region']);
        $this->assertEquals('Ventspils, LV-3601', $loc12['address']);
    }

    public function test_card_date_parsing(): void
    {
        $scraper = new VisitVentspilsScraper();

        // 1. Single date with time
        $html1 = <<<HTML
        <article class="list-item">
            <div class="list-item__date">
                <div class="list-item__date-main">
                    <p class="list-item__date-day">15</p>
                    <p class="list-item__date-month">Nov.<br>2026</p>
                </div>
                <div class="list-item__date-bottom">
                    <p class="list-item__date-time">10:00</p>
                </div>
            </div>
        </article>
HTML;
        $crawler1 = new Crawler($html1);
        [$start1, $end1, $allDay1] = $scraper->parseCardDates($crawler1->filter('article.list-item')->first());
        $this->assertNotNull($start1);
        $this->assertNull($end1);
        $this->assertEquals('2026-11-15 10:00:00', $start1->toDateTimeString());
        $this->assertFalse($allDay1);

        // 2. Single date with time range
        $html2 = <<<HTML
        <article class="list-item">
            <div class="list-item__date">
                <div class="list-item__date-main">
                    <p class="list-item__date-day">20</p>
                    <p class="list-item__date-month">Nov.<br>2026</p>
                </div>
                <div class="list-item__date-bottom">
                    <p class="list-item__date-time">18:00 - 21:00</p>
                </div>
            </div>
        </article>
HTML;
        $crawler2 = new Crawler($html2);
        [$start2, $end2, $allDay2] = $scraper->parseCardDates($crawler2->filter('article.list-item')->first());
        $this->assertNotNull($start2);
        $this->assertNotNull($end2);
        $this->assertEquals('2026-11-20 18:00:00', $start2->toDateTimeString());
        $this->assertEquals('2026-11-20 21:00:00', $end2->toDateTimeString());
        $this->assertFalse($allDay2);

        // 3. Multi-day range without explicit time
        $html3 = <<<HTML
        <article class="list-item">
            <div class="list-item__date">
                <div class="list-item__date-main">
                    <p class="list-item__date-day">11</p>
                    <p class="list-item__date-month">Nov.<br>2026</p>
                    <p>līdz</p>
                    <p class="list-item__date-day">18</p>
                    <p class="list-item__date-month">Nov.<br>2026</p>
                </div>
            </div>
        </article>
HTML;
        $crawler3 = new Crawler($html3);
        [$start3, $end3, $allDay3] = $scraper->parseCardDates($crawler3->filter('article.list-item')->first());
        $this->assertNotNull($start3);
        $this->assertNotNull($end3);
        $this->assertEquals('2026-11-11 10:00:00', $start3->toDateTimeString());
        $this->assertEquals('2026-11-18 20:00:00', $end3->toDateTimeString());
        $this->assertTrue($allDay3);

        // 4. Multi-day range spanning year change
        $html4 = <<<HTML
        <article class="list-item">
            <div class="list-item__date">
                <div class="list-item__date-main">
                    <p class="list-item__date-day">28</p>
                    <p class="list-item__date-month">Dec.<br>2026</p>
                    <p>līdz</p>
                    <p class="list-item__date-day">5</p>
                    <p class="list-item__date-month">Janv.<br>2027</p>
                </div>
            </div>
        </article>
HTML;
        $crawler4 = new Crawler($html4);
        [$start4, $end4, $allDay4] = $scraper->parseCardDates($crawler4->filter('article.list-item')->first());
        $this->assertNotNull($start4);
        $this->assertNotNull($end4);
        $this->assertEquals('2026-12-28 10:00:00', $start4->toDateTimeString());
        $this->assertEquals('2027-01-05 20:00:00', $end4->toDateTimeString());
    }

    public function test_strict_categorization_and_kino_rules_per_agents_md(): void
    {
        $scraper = new VisitVentspilsScraper();

        // 1. STRICT RULE: Kino Rio venue MUST ALWAYS be Kino, NEVER Teātris
        $cats1 = $scraper->resolveCategories(
            'Kino Rio',
            'Zvaigžņu nakts balle Kino Rio',
            'Izklaides vakars ar dejām'
        );
        $this->assertContains('Kino', $cats1);
        $this->assertNotContains('Teātris', $cats1);
        $this->assertEquals('movie', $scraper->resolveEntertainmentType($cats1, 'Zvaigžņu nakts balle Kino Rio'));

        // 2. STRICT RULE: Kinoteātris contains "teātr", but MUST NEVER be classified as Teātris
        $cats2 = $scraper->resolveCategories(
            'Pils iela',
            'Jaunākā latviešu spēlfilma kinoteātrī',
            'Kinoseanss visai ģimenei'
        );
        $this->assertContains('Kino', $cats2);
        $this->assertNotContains('Teātris', $cats2);

        // 3. Film in title -> Kino
        $cats3 = $scraper->resolveCategories(
            'Ventspils',
            'Dokumentālā filma "Ventspils cauri gadsimtiem"',
            'Filmas seanss'
        );
        $this->assertContains('Kino', $cats3);
        $this->assertEquals('movie', $scraper->resolveEntertainmentType($cats3, 'Dokumentālā filma'));

        // 4. Real theatre production -> Teātris, theatre
        $cats4 = $scraper->resolveCategories(
            'Teātra nams "Jūras vārti"',
            'Dirty Deal Teatro mūzikas izrāde “Alfas”',
            'Režisora Valtera Sīļa jaunākais iestudējums'
        );
        $this->assertContains('Teātris', $cats4);
        $this->assertEquals('theatre', $scraper->resolveEntertainmentType($cats4, 'Izrāde'));

        // 5. Concert / Ball -> Mūzika, concert
        $cats5 = $scraper->resolveCategories(
            'Koncertzāle "Latvija"',
            'Grupas “bet bet” 35 gadu jubilejas koncerts',
            'Svinīgā koncertprogramma'
        );
        $this->assertContains('Mūzika', $cats5);
        $this->assertEquals('concert', $scraper->resolveEntertainmentType($cats5, 'Jubilejas koncerts'));

        // 6. Sports -> Sports, sports
        $cats6 = $scraper->resolveCategories(
            'Pārventas sporta komplekss, Tārgales iela 5b',
            'Latvijas volejbola čempionāta Reģionālās līgas spēle',
            'VK Zviedrāns Team Ventspils pret Aizpute'
        );
        $this->assertContains('Sports', $cats6);
        $this->assertEquals('sports', $scraper->resolveEntertainmentType($cats6, 'Volejbola spēle'));

        // 7. Exhibition -> Izstādes, exhibition
        $cats7 = $scraper->resolveCategories(
            'Livonijas ordeņa pils',
            'Izstāde "Pils stāsti. Lelles"',
            'Muzeja krājuma leļļu ekspozīcija'
        );
        $this->assertContains('Izstādes', $cats7);
        $this->assertEquals('exhibition', $scraper->resolveEntertainmentType($cats7, 'Izstāde'));

        // 8. Children -> Bērniem
        $cats8 = $scraper->resolveCategories(
            'Bērnu pilsētiņa',
            'Muzikāla rotaļprogramma bērniem un ģimenēm',
            'Radošās darbnīcas mazajiem apmeklētājiem'
        );
        $this->assertContains('Bērniem', $cats8);

        // 9. Fallback -> Cits, event
        $cats9 = $scraper->resolveCategories(
            'Ventspils Galvenā bibliotēka',
            'Tikšanās ar rakstnieci un grāmatas atvēršanas svētki',
            'Saruna par literatūru un lasīšanu'
        );
        $this->assertEquals(['Cits'], $cats9);
        $this->assertEquals('event', $scraper->resolveEntertainmentType($cats9, 'Tikšanās ar rakstnieci'));
    }

    public function test_price_and_ticket_parsing(): void
    {
        $scraper = new VisitVentspilsScraper();

        // 1. Status with "Bezmaksas"
        $p1 = $scraper->parsePrice('Bezmaksas', 'Pasākuma apraksts', null);
        $this->assertTrue($p1['is_free']);
        $this->assertNull($p1['price_min']);

        // 2. Status with "Pirkt biļeti" and ticket link
        $p2 = $scraper->parsePrice('Pirkt biļeti', 'Biļetes cena: 15.00 EUR', 'https://www.bilesuparadize.lv/lv/event/179652');
        $this->assertFalse($p2['is_free']);
        $this->assertEquals(15.0, $p2['price_min']);
        $this->assertEquals(15.0, $p2['price_max']);

        // 3. Price range in description
        $p3 = $scraper->parsePrice('Pirkt biļeti', 'Ieejas maksa: 7.00 - 12.00 €', 'https://muzejs.ventspils.lv');
        $this->assertFalse($p3['is_free']);
        $this->assertEquals(7.0, $p3['price_min']);
        $this->assertEquals(12.0, $p3['price_max']);

        // 4. Free text in description
        $p4 = $scraper->parsePrice('', 'Ieeja visiem apmeklētājiem bez maksas', null);
        $this->assertTrue($p4['is_free']);
    }

    public function test_is_non_event(): void
    {
        $scraper = new VisitVentspilsScraper();

        $this->assertTrue($scraper->isNonEvent('darba laiks'));
        $this->assertTrue($scraper->isNonEvent('kontakti'));
        $this->assertTrue($scraper->isNonEvent('repertuārs'));
        $this->assertFalse($scraper->isNonEvent('Svētku koncerts Koncertzālē Latvija'));
    }

    public function test_full_scrape_with_mocked_http(): void
    {
        $source = Source::firstWhere('slug', 'visitventspils') ?: Source::create([
            'name' => 'VisitVentspils',
            'slug' => 'visitventspils',
            'url' => 'https://www.visitventspils.com/pasakumi/',
            'scraper_class' => VisitVentspilsScraper::class,
            'type' => 'municipality',
            'is_active' => true,
        ]);

        $tomorrow = Carbon::tomorrow('Europe/Riga');
        $months = ['Janv.', 'Febr.', 'Marts', 'Apr.', 'Maijs', 'Jūn.', 'Jūl.', 'Aug.', 'Sept.', 'Okt.', 'Nov.', 'Dec.'];
        $mStr = $months[$tomorrow->month - 1];

        // Mock HTML containing:
        // 1. Concert in Koncertzāle Latvija (ACCEPTED)
        // 2. Film event in Kino Rio (ACCEPTED - strict Kino priority)
        // 3. Cancelled event (status Atcelts) (SKIPPED)
        // 4. Past event from 2024 (SKIPPED per AGENTS.md)
        // 5. Unavailable event class list-item--unaviable (SKIPPED)
        $listHtml = <<<HTML
        <div id="list-items" class="post-list__items">
            <article class="list-item">
                <div class="list-item__date">
                    <div class="list-item__date-main">
                        <p class="list-item__date-day">{$tomorrow->day}</p>
                        <p class="list-item__date-month">{$mStr}<br>{$tomorrow->year}</p>
                    </div>
                    <div class="list-item__date-bottom">
                        <p class="list-item__date-time">19:00</p>
                    </div>
                </div>
                <div class="list-item__favorite">
                    <button class="btn add-to-favourites" data-id="900101"></button>
                </div>
                <a href="https://www.visitventspils.com/pasakums/latvijas-valsts-svetku-koncerts/" class="list-item__inner">
                    <div class="list-item__image">
                        <img src="https://www.visitventspils.com/app/uploads/koncerts.jpg" />
                    </div>
                    <div class="list-item__main">
                        <div class="list-item__content">
                            <p class="list-item__address block-desc">Koncertzāle "Latvija"</p>
                            <h3 class="h4 list-item__title">Latvijas valsts svētku koncerts</h3>
                            <p class="block-desc list-item__desc">Svētku koncertprogramma ar Liepājas Simfonisko orķestri. Biļetes cena: 15.00 EUR.</p>
                        </div>
                    </div>
                </a>
                <div class="list-item__status">
                    <a class="btn list-item__status-label" href="https://www.bilesuparadize.lv/lv/event/123456">
                        <span class="text">Pirkt biļeti</span>
                    </a>
                </div>
            </article>

            <article class="list-item">
                <div class="list-item__date">
                    <div class="list-item__date-main">
                        <p class="list-item__date-day">{$tomorrow->day}</p>
                        <p class="list-item__date-month">{$mStr}<br>{$tomorrow->year}</p>
                    </div>
                    <div class="list-item__date-bottom">
                        <p class="list-item__date-time">20:00</p>
                    </div>
                </div>
                <div class="list-item__favorite">
                    <button class="btn add-to-favourites" data-id="900102"></button>
                </div>
                <a href="https://www.visitventspils.com/pasakums/kino-rio-seanss/" class="list-item__inner">
                    <div class="list-item__image">
                        <img src="https://www.visitventspils.com/app/uploads/kino.jpg" />
                    </div>
                    <div class="list-item__main">
                        <div class="list-item__content">
                            <p class="list-item__address block-desc">Kino Rio</p>
                            <h3 class="h4 list-item__title">Jaunākās latviešu spēlfilmas seanss</h3>
                            <p class="block-desc list-item__desc">Kinoseanss Ventspils kinoteātrī Kino Rio. Ieeja bez maksas.</p>
                        </div>
                    </div>
                </a>
                <div class="list-item__status">
                    <p class="list-item__status-label">Bezmaksas</p>
                </div>
            </article>

            <article class="list-item">
                <div class="list-item__date">
                    <div class="list-item__date-main">
                        <p class="list-item__date-day">{$tomorrow->day}</p>
                        <p class="list-item__date-month">{$mStr}<br>{$tomorrow->year}</p>
                    </div>
                </div>
                <div class="list-item__favorite">
                    <button class="btn add-to-favourites" data-id="900103"></button>
                </div>
                <h3 class="h4 list-item__title">Atceltais pasākums</h3>
                <div class="list-item__status">
                    <p class="list-item__status-label">Atcelts</p>
                </div>
            </article>

            <article class="list-item">
                <div class="list-item__date">
                    <div class="list-item__date-main">
                        <p class="list-item__date-day">10</p>
                        <p class="list-item__date-month">Janv.<br>2024</p>
                    </div>
                </div>
                <div class="list-item__favorite">
                    <button class="btn add-to-favourites" data-id="900104"></button>
                </div>
                <h3 class="h4 list-item__title">Pagātnes pasākums 2024</h3>
            </article>

            <article class="list-item list-item--unaviable">
                <div class="list-item__date">
                    <div class="list-item__date-main">
                        <p class="list-item__date-day">{$tomorrow->day}</p>
                        <p class="list-item__date-month">{$mStr}<br>{$tomorrow->year}</p>
                    </div>
                </div>
                <div class="list-item__favorite">
                    <button class="btn add-to-favourites" data-id="900105"></button>
                </div>
                <h3 class="h4 list-item__title">Neaktīvs pasākums</h3>
            </article>
        </div>
HTML;

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $listHtml),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $scraper = new VisitVentspilsScraper();
        $scraper->setClient($client);

        $events = $scraper->scrape($source);

        // Only the 2 future active events should be returned
        $this->assertCount(2, $events);

        // Event 1 checks
        $event1 = $events[0];
        $this->assertEquals('Latvijas valsts svētku koncerts', $event1->title);
        $this->assertEquals('Koncertzāle "Latvija"', $event1->venueName);
        $this->assertEquals('Ventspils', $event1->city);
        $this->assertEquals('Kurzeme', $event1->region);
        $this->assertEquals('Lielais laukums 1, Ventspils, LV-3601', $event1->address);
        $this->assertEquals('concert_hall', $event1->placeType);
        $this->assertContains('Mūzika', $event1->categoryNames);
        $this->assertEquals('concert', $event1->entertainmentType);
        $this->assertFalse($event1->isFree);
        $this->assertEquals(15.0, $event1->priceMin);
        $this->assertEquals('https://www.bilesuparadize.lv/lv/event/123456', $event1->ticketUrl);
        $this->assertEquals('visitventspils-900101', $event1->sourceExternalId);

        // Event 2 checks (Strict Kino priority for Kino Rio)
        $event2 = $events[1];
        $this->assertEquals('Jaunākās latviešu spēlfilmas seanss', $event2->title);
        $this->assertEquals('Kino Rio', $event2->venueName);
        $this->assertEquals('cinema', $event2->placeType);
        $this->assertContains('Kino', $event2->categoryNames);
        $this->assertNotContains('Teātris', $event2->categoryNames);
        $this->assertEquals('movie', $event2->entertainmentType);
        $this->assertTrue($event2->isFree);
        $this->assertEquals('visitventspils-900102', $event2->sourceExternalId);
    }

    public function test_visit_ventspils_event_ingestion(): void
    {
        $source = Source::create([
            'name' => 'VisitVentspils Test',
            'slug' => 'visitventspils-test',
            'url' => 'https://www.visitventspils.com/pasakumi/',
            'scraper_class' => VisitVentspilsScraper::class,
            'type' => 'municipality',
            'is_active' => true,
        ]);

        $futureDate = Carbon::now('Europe/Riga')->addDays(4);

        $dto = new ScrapedEventDTO(
            title: 'Flamenko deju izrāde Ventspilī',
            startAt: $futureDate->copy()->setTime(19, 0),
            endAt: $futureDate->copy()->setTime(21, 0),
            description: 'Spānijas flamenko trupas viesizrāde Teātra namā Jūras vārti.',
            shortDescription: 'Spānijas flamenko trupas viesizrāde.',
            venueName: 'Teātra nams "Jūras vārti"',
            city: 'Ventspils',
            region: 'Kurzeme',
            address: 'Karlīnes iela 40, Ventspils, LV-3601',
            placeType: 'theater',
            categoryNames: ['Teātris'],
            entertainmentType: 'theatre',
            isFree: false,
            priceMin: 20.0,
            priceMax: 35.0,
            currency: 'EUR',
            ticketUrl: 'https://www.bilesuparadize.lv/lv/event/999999',
            imageUrl: 'https://www.visitventspils.com/app/uploads/flamenco.jpg',
            sourceUrl: 'https://www.visitventspils.com/pasakums/flamenko-deju-izrade/',
            sourceExternalId: 'visitventspils-flamenko-deju-izrade',
            locale: 'lv',
            rawData: []
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_external_id', 'visitventspils-flamenko-deju-izrade')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Flamenko deju izrāde Ventspilī', $event->title);
        $this->assertStringContainsString('Jūras vārti', $event->location->name);
        $this->assertEquals('Ventspils', $event->location->city);
        $this->assertEquals('Kurzeme', $event->location->region);
        $this->assertEquals('theatre', $event->entertainment_type);
        $this->assertFalse((bool) $event->is_free);
    }
}
