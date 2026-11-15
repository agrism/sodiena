<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\JurmalaScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JurmalaScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata(): void
    {
        $scraper = new JurmalaScraper();
        $this->assertEquals('jurmala', $scraper->getSlug());
        $this->assertEquals('Jūrmalas valstspilsēta', $scraper->getName());
        $this->assertEquals('https://www.jurmala.lv', JurmalaScraper::BASE_URL);
        $this->assertEquals('/lv/notikumu-kalendars', JurmalaScraper::CALENDAR_PATH);
    }

    public function test_location_resolving_known_venues_and_neighborhoods(): void
    {
        $scraper = new JurmalaScraper();

        // 1. Dzintaru koncertzāle
        $loc1 = $scraper->resolveLocation('Dzintari', 'Simfoniskās mūzikas koncerts Dzintaru koncertzālē');
        $this->assertEquals('Dzintaru koncertzāle', $loc1['name']);
        $this->assertEquals('Jūrmala', $loc1['city']);
        $this->assertEquals('Rīga un Pierīga', $loc1['region']);
        $this->assertEquals('Turaidas iela 1, Jūrmala, LV-2015', $loc1['address']);
        $this->assertEquals('concert_hall', $loc1['place_type']);
        $this->assertEquals(56.9749, $loc1['lat']);

        // 2. Skvērs pie Dzintaru koncertzāles
        $loc2 = $scraper->resolveLocation('Turaidas iela', 'Brīvdabas pasākums skvērā pie Dzintaru koncertzāles');
        $this->assertEquals('Skvērs pie Dzintaru koncertzāles', $loc2['name']);
        $this->assertEquals('park', $loc2['place_type']);

        // 3. Jūrmalas teātris
        $loc3 = $scraper->resolveLocation('Bulduri', 'Izrāde Jūrmalas teātrī');
        $this->assertEquals('Jūrmalas teātris', $loc3['name']);
        $this->assertEquals('theater', $loc3['place_type']);
        $this->assertEquals('Muižas iela 7, Jūrmala, LV-2010', $loc3['address']);

        // 4. Jūrmalas Kultūras centrs
        $loc4 = $scraper->resolveLocation('Majori', 'Izstādes atklāšana Jūrmalas Kultūras centrā');
        $this->assertEquals('Jūrmalas Kultūras centrs', $loc4['name']);
        $this->assertEquals('Jomas iela 35, Jūrmala, LV-2015', $loc4['address']);

        // 5. Mellužu estrāde
        $loc5 = $scraper->resolveLocation('Melluži', 'Zaļumballe Mellužu estrādē');
        $this->assertEquals('Mellužu estrāde', $loc5['name']);
        $this->assertEquals('Mellužu prospekts 6, Jūrmala, LV-2008', $loc5['address']);
        $this->assertEquals('amphitheater', $loc5['place_type']);

        // 6. Jūrmalas muzejs
        $loc6 = $scraper->resolveLocation('Majori', 'Vēstures lekcija Jūrmalas muzejā');
        $this->assertEquals('Jūrmalas muzejs', $loc6['name']);
        $this->assertEquals('museum', $loc6['place_type']);

        // 7. Bulduru Izstāžu nams
        $loc7 = $scraper->resolveLocation('Bulduri', 'Mākslas izstāde Bulduru izstāžu namā');
        $this->assertEquals('Bulduru Izstāžu nams', $loc7['name']);
        $this->assertEquals('gallery', $loc7['place_type']);

        // 8. Kauguru kultūras nams
        $loc8 = $scraper->resolveLocation('Kauguri', 'Kora koncerts Kauguru kultūras namā');
        $this->assertEquals('Kauguru kultūras nams', $loc8['name']);
        $this->assertEquals('Raiņa iela 110, Jūrmala, LV-2016', $loc8['address']);

        // 9. Ķemeru kūrorta parks
        $loc9 = $scraper->resolveLocation('Ķemeri', 'Pastaiga Ķemeru kūrorta parkā');
        $this->assertEquals('Ķemeru kūrorta parks', $loc9['name']);
        $this->assertEquals('park', $loc9['place_type']);

        // 10. Neighborhood fallback without named venue
        $loc10 = $scraper->resolveLocation('Kauguri', 'Ielu vingrošanas sacensības');
        $this->assertEquals('Kauguri', $loc10['name']);
        $this->assertEquals('Kauguri, Jūrmala, LV-2016', $loc10['address']);

        // 11. Empty fallback
        $loc11 = $scraper->resolveLocation('', 'Pilsētas svētki');
        $this->assertEquals('Jūrmalas valstspilsēta', $loc11['name']);
        $this->assertEquals('Jūrmala', $loc11['city']);
        $this->assertEquals('Rīga un Pierīga', $loc11['region']);
        $this->assertEquals('Jomas iela 1/5, Jūrmala, LV-2015', $loc11['address']);
    }

    public function test_date_and_time_parsing(): void
    {
        $scraper = new JurmalaScraper();

        // 1. Single date with time
        [$start1, $end1, $allDay1] = $scraper->parseLatvianDates('18. novembris, 2026', '16.00');
        $this->assertNotNull($start1);
        $this->assertNull($end1);
        $this->assertEquals('2026-11-18 16:00:00', $start1->toDateTimeString());
        $this->assertFalse($allDay1);

        // 2. Single date with time range
        [$start2, $end2, $allDay2] = $scraper->parseLatvianDates('20. novembris, 2026', '18:00 - 21:00');
        $this->assertNotNull($start2);
        $this->assertNotNull($end2);
        $this->assertEquals('2026-11-20 18:00:00', $start2->toDateTimeString());
        $this->assertEquals('2026-11-20 21:00:00', $end2->toDateTimeString());
        $this->assertFalse($allDay2);

        // 3. Day range with day prefix
        [$start3, $end3, $allDay3] = $scraper->parseLatvianDates('16. - 20. novembris, 2026', '10.00 - 18.00');
        $this->assertNotNull($start3);
        $this->assertNotNull($end3);
        $this->assertEquals('2026-11-16 10:00:00', $start3->toDateTimeString());
        $this->assertEquals('2026-11-20 18:00:00', $end3->toDateTimeString());

        // 4. All day event
        [$start4, $end4, $allDay4] = $scraper->parseLatvianDates('18. novembris, 2026', 'Visu dienu');
        $this->assertNotNull($start4);
        $this->assertNotNull($end4);
        $this->assertEquals('2026-11-18 00:00:00', $start4->toDateTimeString());
        $this->assertEquals('2026-11-18 23:59:59', $end4->toDateTimeString());
        $this->assertTrue($allDay4);

        // 5. Numeric date format: "25.11.2026"
        [$start5, $end5, $allDay5] = $scraper->parseLatvianDates('25.11.2026', '15:30');
        $this->assertNotNull($start5);
        $this->assertEquals('2026-11-25 15:30:00', $start5->toDateTimeString());

        // 6. Numeric date range: "25.11.2026 - 30.11.2026"
        [$start6, $end6, $allDay6] = $scraper->parseLatvianDates('25.11.2026 - 30.11.2026', '11:00 - 17:00');
        $this->assertNotNull($start6);
        $this->assertNotNull($end6);
        $this->assertEquals('2026-11-25 11:00:00', $start6->toDateTimeString());
        $this->assertEquals('2026-11-30 17:00:00', $end6->toDateTimeString());
    }

    public function test_strict_categorization_and_kino_rules_per_agents_md(): void
    {
        $scraper = new JurmalaScraper();

        // 1. Film / Cinema keywords -> ALWAYS Kino, NEVER Teātris
        $cats1 = $scraper->resolveCategories(
            ['Kultūra'],
            'Animācijas filmas "Straume" kinoseanss Jūrmalā',
            'Biļetes kinoteātra kasē'
        );
        $this->assertContains('Kino', $cats1);
        $this->assertNotContains('Teātris', $cats1);
        $this->assertEquals('movie', $scraper->resolveEntertainmentType($cats1, 'Animācijas filma'));

        // 2. kinoteātris contains "teātr", but MUST NOT be categorized as Teātris
        $cats2 = $scraper->resolveCategories(
            [],
            'Jaunākā latviešu spēlfilma kinoteātrī',
            'Filmas seanss'
        );
        $this->assertContains('Kino', $cats2);
        $this->assertNotContains('Teātris', $cats2);

        // 3. Real theatre production -> Teātris, theatre
        $cats3 = $scraper->resolveCategories(
            ['Teātris'],
            'Jūrmalas teātra izrāde "Kazanovas mētelis"',
            'Lugas iestudējums Muižas ielā 7'
        );
        $this->assertContains('Teātris', $cats3);
        $this->assertEquals('theatre', $scraper->resolveEntertainmentType($cats3, 'Izrāde'));

        // 4. Concert -> Mūzika, concert
        $cats4 = $scraper->resolveCategories(
            ['Koncerts'],
            'Jūrmalas festivāla atklāšanas koncerts Dzintaros',
            'Piedalās Latvijas Nacionālais simfoniskais orķestris'
        );
        $this->assertContains('Mūzika', $cats4);
        $this->assertEquals('concert', $scraper->resolveEntertainmentType($cats4, 'Koncerts'));

        // 5. Exhibition -> Izstādes, exhibition
        $cats5 = $scraper->resolveCategories(
            ['Izstāde'],
            'Mākslinieka gleznu personālizstāde',
            'Ekspozīcija apskatāma katru dienu'
        );
        $this->assertContains('Izstādes', $cats5);
        $this->assertEquals('exhibition', $scraper->resolveEntertainmentType($cats5, 'Izstāde'));

        // 6. Sports -> Sports, sports
        $cats6 = $scraper->resolveCategories(
            ['Sports'],
            'Jūrmalas skriešanas pusmaratons pa pludmali',
            'Dalībnieku reģistrācija un starts Majoru pludmalē'
        );
        $this->assertContains('Sports', $cats6);
        $this->assertEquals('sports', $scraper->resolveEntertainmentType($cats6, 'Pusmaratons'));

        // 7. Fallback -> Cits, event
        $cats7 = $scraper->resolveCategories(
            [],
            'Seminārs uzņēmējiem par energoefektivitāti',
            'Lekcijas un diskusijas'
        );
        $this->assertEquals(['Cits'], $cats7);
        $this->assertEquals('event', $scraper->resolveEntertainmentType($cats7, 'Seminārs'));
    }

    public function test_price_parsing(): void
    {
        $scraper = new JurmalaScraper();

        $p1 = $scraper->parsePrice('Ieeja pasākumā bez maksas.');
        $this->assertTrue($p1['is_free']);
        $this->assertNull($p1['price_min']);

        $p2 = $scraper->parsePrice('Biļetes cena: 10.00 EUR Biļešu Paradīzes kasēs.');
        $this->assertFalse($p2['is_free']);
        $this->assertEquals(10.0, $p2['price_min']);
        $this->assertEquals(10.0, $p2['price_max']);

        $p3 = $scraper->parsePrice('Ieejas maksa: 7 - 15 €.');
        $this->assertFalse($p3['is_free']);
        $this->assertEquals(7.0, $p3['price_min']);
        $this->assertEquals(15.0, $p3['price_max']);
    }

    public function test_is_non_event(): void
    {
        $scraper = new JurmalaScraper();

        $this->assertTrue($scraper->isNonEvent('darba laiks'));
        $this->assertTrue($scraper->isNonEvent('kontakti'));
        $this->assertTrue($scraper->isNonEvent('repertuārs'));
        $this->assertFalse($scraper->isNonEvent('Dzintaru koncertzāles atklāšanas koncerts'));
    }

    public function test_full_scrape_with_mocked_http(): void
    {
        $source = Source::firstWhere('slug', 'jurmala') ?: Source::create([
            'name' => 'Jūrmalas valstspilsēta',
            'slug' => 'jurmala',
            'url' => 'https://www.jurmala.lv/lv/notikumu-kalendars',
            'scraper_class' => JurmalaScraper::class,
            'type' => 'municipality',
            'is_active' => true,
        ]);

        $tomorrow = Carbon::tomorrow('Europe/Riga');
        $months = ['janvāris', 'februāris', 'marts', 'aprīlis', 'maijs', 'jūnijs', 'jūlijs', 'augusts', 'septembris', 'oktobris', 'novembris', 'decembris'];
        $formattedTomorrow = $tomorrow->day . '. ' . $months[$tomorrow->month - 1] . ', ' . $tomorrow->year;

        // Mock HTML response with 4 items:
        // 1. Concert in Dzintaru koncertzāle (ACCEPTED)
        // 2. Film screening in Kauguru kultūras nams (ACCEPTED - strict Kino)
        // 3. Past event from 2024 (MUST BE SKIPPED per AGENTS.md)
        // 4. Non-event "darba laiks" (MUST BE SKIPPED)
        $listHtml = <<<HTML
        <div class="events">
            <div class="event">
                <div class="event-info">
                    <span class="date-date">{$formattedTomorrow}</span>
                    <span class="date-time">19.00</span>
                    <span class="describing-field-value">Dzintari</span>
                </div>
                <div class="event-image">
                    <img src="/files/gallery_images/dzintari_koncerts.jpg" />
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/latvijas-valsts-svetku-koncerts-dzintaros">Latvijas valsts svētku koncerts Dzintaru koncertzālē</a>
                </h2>
                <div class="event-categories">
                    <a class="badge">Koncerts</a>
                    <a class="badge">Kultūra</a>
                </div>
                <div class="event-content">
                    <p>Dzintaru koncertzālē notiks svinīgs koncerts par godu Latvijas Republikas proklamēšanas dienai. Biļetes cena: 15.00 EUR.</p>
                </div>
            </div>

            <div class="event">
                <div class="event-info">
                    <span class="date-date">{$formattedTomorrow}</span>
                    <span class="date-time">17.30 - 19.30</span>
                    <span class="describing-field-value">Kauguri</span>
                </div>
                <div class="event-image">
                    <img src="/files/gallery_images/filma.jpg" />
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/animacijas-filmas-seanss">Animācijas filmas seanss Kauguru kultūras namā</a>
                </h2>
                <div class="event-categories">
                    <a class="badge">Kino</a>
                </div>
                <div class="event-content">
                    <p>Ieeja uz kinoseansu bez maksas.</p>
                </div>
            </div>

            <div class="event">
                <div class="event-info">
                    <span class="date-date">10. janvāris, 2024</span>
                    <span class="date-time">12.00</span>
                    <span class="describing-field-value">Majori</span>
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/pagatnes-pasakums">Pagātnes pasākums 2024</a>
                </h2>
                <div class="event-categories">
                    <a class="badge">Kultūra</a>
                </div>
                <div class="event-content">
                    <p>Vēsturisks pasākums no pagātnes.</p>
                </div>
            </div>

            <div class="event">
                <div class="event-info">
                    <span class="date-date">{$formattedTomorrow}</span>
                    <span class="date-time">10.00</span>
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/darba-laiks">Darba laiks</a>
                </h2>
            </div>
        </div>
HTML;

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $listHtml),
            new Response(200, ['Content-Type' => 'text/html'], '<div class="events"></div>'),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $scraper = new JurmalaScraper();
        $scraper->setClient($client);

        $events = $scraper->scrape($source);

        // Only 2 future events should be accepted (event 3 skipped by past date, event 4 skipped by non-event)
        $this->assertCount(2, $events);

        // Event 1 checks
        $event1 = $events[0];
        $this->assertEquals('Latvijas valsts svētku koncerts Dzintaru koncertzālē', $event1->title);
        $this->assertEquals('Dzintaru koncertzāle', $event1->venueName);
        $this->assertEquals('Jūrmala', $event1->city);
        $this->assertEquals('Rīga un Pierīga', $event1->region);
        $this->assertEquals('Turaidas iela 1, Jūrmala, LV-2015', $event1->address);
        $this->assertEquals('concert_hall', $event1->placeType);
        $this->assertContains('Mūzika', $event1->categoryNames);
        $this->assertEquals('concert', $event1->entertainmentType);
        $this->assertFalse($event1->isFree);
        $this->assertEquals(15.0, $event1->priceMin);
        $this->assertEquals('https://www.jurmala.lv/files/gallery_images/dzintari_koncerts.jpg', $event1->imageUrl);
        $this->assertEquals('jurmala-latvijas-valsts-svetku-koncerts-dzintaros', $event1->sourceExternalId);

        // Event 2 checks (Strict Kino rule)
        $event2 = $events[1];
        $this->assertEquals('Animācijas filmas seanss Kauguru kultūras namā', $event2->title);
        $this->assertEquals('Kauguru kultūras nams', $event2->venueName);
        $this->assertEquals('cultural_center', $event2->placeType);
        $this->assertContains('Kino', $event2->categoryNames);
        $this->assertNotContains('Teātris', $event2->categoryNames);
        $this->assertEquals('movie', $event2->entertainmentType);
        $this->assertTrue($event2->isFree);
        $this->assertEquals('jurmala-animacijas-filmas-seanss', $event2->sourceExternalId);
    }

    public function test_event_ingestion(): void
    {
        $source = Source::firstWhere('slug', 'jurmala') ?: Source::create([
            'name' => 'Jūrmalas valstspilsēta',
            'slug' => 'jurmala',
            'url' => 'https://www.jurmala.lv/lv/notikumu-kalendars',
            'scraper_class' => JurmalaScraper::class,
            'type' => 'municipality',
            'is_active' => true,
        ]);

        $futureDate = Carbon::now('Europe/Riga')->addDays(3);

        $dto = new ScrapedEventDTO(
            title: 'Jūrmalas džeza vakars',
            startAt: $futureDate->copy()->setTime(19, 0),
            endAt: $futureDate->copy()->setTime(21, 30),
            description: 'Džeza mūzikas koncerts Mellužu estrādē.',
            shortDescription: 'Džeza mūzikas koncerts Mellužu estrādē.',
            venueName: 'Mellužu estrāde',
            city: 'Jūrmala',
            region: 'Rīga un Pierīga',
            address: 'Mellužu prospekts 6, Jūrmala, LV-2008',
            placeType: 'amphitheater',
            categoryNames: ['Mūzika'],
            entertainmentType: 'concert',
            isFree: true,
            priceMin: null,
            priceMax: null,
            currency: 'EUR',
            ticketUrl: 'https://www.jurmala.lv/lv/notikumu-kalendars',
            imageUrl: 'https://www.jurmala.lv/images/jazz.jpg',
            sourceUrl: 'https://www.jurmala.lv/lv/notikums/jurmalas-dzeza-vakars',
            sourceExternalId: 'jurmala-jurmalas-dzeza-vakars',
            locale: 'lv',
            rawData: []
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_external_id', 'jurmala-jurmalas-dzeza-vakars')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Jūrmalas džeza vakars', $event->title);
        $this->assertEquals('Mellužu estrāde', $event->location->name);
        $this->assertEquals('Jūrmala', $event->location->city);
        $this->assertEquals('Rīga un Pierīga', $event->location->region);
        $this->assertEquals('concert', $event->entertainment_type);
        $this->assertTrue((bool) $event->is_free);
    }
}
