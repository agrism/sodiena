<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\DienvidkurzemeScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DienvidkurzemeScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata(): void
    {
        $scraper = new DienvidkurzemeScraper();
        $this->assertEquals('dienvidkurzeme', $scraper->getSlug());
        $this->assertEquals('Dienvidkurzeme Travel', $scraper->getName());
        $this->assertEquals('https://www.dienvidkurzeme.travel', DienvidkurzemeScraper::BASE_URL);
        $this->assertEquals('/pasakumi-dienvidkurzeme/', DienvidkurzemeScraper::CALENDAR_PATH);
        $this->assertEquals('41a31238ed947d80727babf9503e8fef4d59d22f863c8949542047ec6b87f836@group.calendar.google.com', DienvidkurzemeScraper::DEFAULT_CALENDAR_ID);
    }

    public function test_location_resolving_known_venues_and_novads(): void
    {
        $scraper = new DienvidkurzemeScraper();

        // 1. Aizputes kultūras nams
        $loc1 = $scraper->resolveLocation('Aizputes kultūras nams, Atmodas iela 16, Aizpute', 'Valsts svētku koncerts');
        $this->assertEquals('Aizputes kultūras nams', $loc1['name']);
        $this->assertEquals('Aizpute', $loc1['city']);
        $this->assertEquals('Kurzeme', $loc1['region']);
        $this->assertEquals('Atmodas iela 16, Aizpute, LV-3456', $loc1['address']);
        $this->assertEquals('cultural_center', $loc1['place_type']);
        $this->assertEquals(56.7208, $loc1['lat']);

        // 2. OTTO Hotel & Sun
        $loc2 = $scraper->resolveLocation('OTTO Hotel & Sun, Dzintaru iela 7, Pāvilosta', 'Džeza vakars');
        $this->assertEquals('OTTO Hotel & Sun', $loc2['name']);
        $this->assertEquals('Pāvilosta', $loc2['city']);
        $this->assertEquals('hotel', $loc2['place_type']);

        // 3. Pāvilostas kultūras nams
        $loc3 = $scraper->resolveLocation('Pāvilostas pilsētas kultūras nams, Dzintaru iela 47', 'Svētku koncerts');
        $this->assertEquals('Pāvilostas pilsētas kultūras nams', $loc3['name']);
        $this->assertEquals('Pāvilosta', $loc3['city']);
        $this->assertEquals('Kurzeme', $loc3['region']);

        // 4. Priekules kultūras nams
        $loc4 = $scraper->resolveLocation('Priekules kultūras nams, Peldu iela 1', 'Koru sadziedāšanās');
        $this->assertEquals('Priekules kultūras nams', $loc4['name']);
        $this->assertEquals('Priekule', $loc4['city']);

        // 5. Kazdangas kultūras nams / pils
        $loc5 = $scraper->resolveLocation('Kazdangas kultūras nams, Pils gatve 4', 'Dzejkoncerts');
        $this->assertEquals('Kazdangas kultūras nams', $loc5['name']);
        $this->assertEquals('Kazdanga', $loc5['city']);

        // 6. Cīravas kultūras nams
        $loc6 = $scraper->resolveLocation('Cīravas kultūras nams', 'Svētku pasākums');
        $this->assertEquals('Cīravas kultūras nams', $loc6['name']);
        $this->assertEquals('Cīrava', $loc6['city']);

        // 7. Vērgales kultūras nams
        $loc7 = $scraper->resolveLocation('Vērgales kultūras nams', 'Vērgales zelta prāti');
        $this->assertEquals('Vērgales kultūras nams', $loc7['name']);
        $this->assertEquals('Vērgale', $loc7['city']);

        // 8. Zentas Mauriņas piemiņas istabas Grobiņā
        $loc8 = $scraper->resolveLocation('Zentas Mauriņas piemiņas istabas', 'Gleznu izstāde');
        $this->assertEquals('Zentas Mauriņas piemiņas istabas', $loc8['name']);
        $this->assertEquals('Grobiņa', $loc8['city']);
        $this->assertEquals('museum', $loc8['place_type']);

        // 9. Durbes kultūras nams
        $loc9 = $scraper->resolveLocation('Durbes kultūras nams', 'Teātru skate');
        $this->assertEquals('Durbes kultūras nams', $loc9['name']);
        $this->assertEquals('Durbe', $loc9['city']);

        // 10. Vaiņodes kultūras nams
        $loc10 = $scraper->resolveLocation('Vaiņodes kultūras nams', 'Koncerts');
        $this->assertEquals('Vaiņodes kultūras nams', $loc10['name']);
        $this->assertEquals('Vaiņode', $loc10['city']);

        // 11. Generic location in Saraiķi
        $loc11 = $scraper->resolveLocation('Saraiķi, Vērgales pagasts, Dienvidkurzemes novads, LV-3463, Latvija', 'Pārgājiens');
        $this->assertEquals('Saraiķi', $loc11['name']);
        $this->assertEquals('Saraiķi', $loc11['city']);
        $this->assertEquals('Kurzeme', $loc11['region']);

        // 12. Fallback
        $loc12 = $scraper->resolveLocation('', 'Novada svētki');
        $this->assertEquals('Grobiņa', $loc12['city']);
        $this->assertEquals('Kurzeme', $loc12['region']);
    }

    public function test_date_parsing_utc_and_allday(): void
    {
        $scraper = new DienvidkurzemeScraper();

        // 1. UTC timestamp: 2026-01-16 09:00:00Z -> 11:00:00 in Europe/Riga (UTC+2)
        $start1 = $scraper->parseIcsDate('20260116T090000Z');
        $this->assertNotNull($start1);
        $this->assertEquals('2026-01-16 11:00:00', $start1->format('Y-m-d H:i:s'));
        $this->assertEquals('Europe/Riga', $start1->timezoneName);

        // 2. All-day start date (VALUE=DATE)
        $start2 = $scraper->parseIcsDate('20261212', 'VALUE=DATE');
        $this->assertNotNull($start2);
        $this->assertEquals('2026-12-12 00:00:00', $start2->format('Y-m-d H:i:s'));

        // 3. All-day non-inclusive end date (VALUE=DATE)
        $end2 = $scraper->parseIcsEndDate('20261213', 'VALUE=DATE', $start2);
        $this->assertNotNull($end2);
        $this->assertEquals('2026-12-12 23:59:59', $end2->format('Y-m-d H:i:s'));

        // 4. Local timestamp
        $start3 = $scraper->parseIcsDate('20261115T180000');
        $this->assertNotNull($start3);
        $this->assertEquals('2026-11-15 18:00:00', $start3->format('Y-m-d H:i:s'));
    }

    public function test_strict_categorization_and_kino_rules_per_agents_md(): void
    {
        $scraper = new DienvidkurzemeScraper();

        // RULE 1: Cinema / Kino Strict Priority:
        // Cinema venues or keywords must ALWAYS be Kino and NEVER Teātris!
        $this->assertEquals(['Kino'], $scraper->inferCategory('Kinoseanss "Zeme"', null, 'Aizputes kultūras nams'));
        $this->assertEquals(['Kino'], $scraper->inferCategory('Filmas “Freimis. Mārtiņš Freimanis” demonstrēšana', null, 'Aizputes kultūras nams'));
        $this->assertEquals(['Kino'], $scraper->inferCategory('Animācijas filma bērniem "Lācītis"', null, 'Vērgales kultūras nams'));
        $this->assertEquals(['Kino'], $scraper->inferCategory('Dokumentālā filma par piekrasti', null, 'Pāvilostas kultūras nams'));
        $this->assertEquals(['Kino'], $scraper->inferCategory('Teātra un mākslas filmas vakars', null, 'Kinoteātris Bize'));
        $this->assertEquals(['Kino'], $scraper->inferCategory('Dienvidkurzemes kino vakars', null, 'Kino Rio'));

        // RULE 2: Theaters - only actual productions or theater venues
        $this->assertEquals(['Teātris'], $scraper->inferCategory('Izrāde “Augusts. Osedžas zeme”', null, 'Aizputes kultūras nams'));
        $this->assertEquals(['Teātris'], $scraper->inferCategory('Izrāde “Kalendārs” un Ziemassvētku vecīša pasts', null, 'Dunalkas kultūras nams'));
        $this->assertEquals(['Teātris'], $scraper->inferCategory('Novada teātru skate Durbē', null, 'Durbes kultūras nams'));
        $this->assertEquals(['Teātris'], $scraper->inferCategory('Durbes amatierteātra izrāde “Saki labu vai neko”', null, 'Vecpils kultūras nams'));

        // RULE 3: Music & Concerts
        $this->assertEquals(['Mūzika'], $scraper->inferCategory('Valsts svētku koncerts ar Ansi Klintsonu', null, 'Aizputes kultūras nams'));
        $this->assertEquals(['Mūzika'], $scraper->inferCategory('Koncertprogramma “Saules koks”', null, 'Gramzdas tautas nams'));
        $this->assertEquals(['Mūzika'], $scraper->inferCategory('Svētku balle ar grupu “Tandēms trijatā”', null, 'Nīcas kultūras nams'));
        $this->assertEquals(['Mūzika'], $scraper->inferCategory('Koru koncerts “Mana zeme skan”', null, 'Priekules kultūras nams'));

        // RULE 4: Sports
        $this->assertEquals(['Sports'], $scraper->inferCategory('Izpēdo Dienvidkurzemi- Saraiķi', null, 'Saraiķi'));
        $this->assertEquals(['Sports'], $scraper->inferCategory('Lukturīšu pārgājiens ar izziņas elementiem', null, 'Pāvilosta'));

        // RULE 5: Exhibitions & Art
        $this->assertEquals(['Izstādes'], $scraper->inferCategory('Fotoizstādes “Bezgalība” atklāšana', null, 'Aizputes novadpētniecības muzejs'));
        $this->assertEquals(['Izstādes'], $scraper->inferCategory('Gleznu izstādes “Saskarsme ar koku” atklāšana', null, 'Zentas Mauriņas piemiņas istabas'));

        // RULE 6: Kids & Family
        $this->assertEquals(['Bērniem'], $scraper->inferCategory('Bērnu radošās brokastis', null, 'Aizputes novadpētniecības muzejs'));

        // RULE 7: Education & Workshops
        $this->assertEquals(['Izglītība'], $scraper->inferCategory('Meistarklase “Citādas sveces”', null, 'Aizputes novadpētniecības muzejs'));
        $this->assertEquals(['Izglītība'], $scraper->inferCategory('Seminārs “Atbildīga aizņemšanās”', null, 'Aizputes pilsētas bibliotēka'));

        // RULE 8: Holidays & Traditions
        $this->assertEquals(['Kultūra'], $scraper->inferCategory('Valsts svētku pasākums “Te ir mūsu mājas”', null, 'Cīravas kultūras nams'));
        $this->assertEquals(['Kultūra'], $scraper->inferCategory('Grobiņas egles iedegšana', null, 'Grobiņa'));

        // Fallback
        $this->assertEquals(['Cits'], $scraper->inferCategory('Dienvidkurzemes tikšanās', null, 'Grobiņa'));
    }

    public function test_price_and_free_admission_extraction(): void
    {
        $scraper = new DienvidkurzemeScraper();

        // 1. Free admission
        $res1 = $scraper->extractPricesAndFree('Koncerts svētkos. Ieeja bez maksas.');
        $this->assertTrue($res1['is_free']);
        $this->assertEquals(0.0, $res1['price_min']);
        $this->assertEquals(0.0, $res1['price_max']);

        // 2. Fixed price in eiro
        $res2 = $scraper->extractPricesAndFree('Balle ar dzīvo mūziku. Ieeja 10 eiro.');
        $this->assertFalse($res2['is_free']);
        $this->assertEquals(10.0, $res2['price_min']);
        $this->assertEquals(10.0, $res2['price_max']);

        // 3. Price range
        $res3 = $scraper->extractPricesAndFree('Biļetes bērniem 3 eiro, pārējiem 6 eiro.');
        $this->assertFalse($res3['is_free']);
        $this->assertEquals(3.0, $res3['price_min']);
        $this->assertEquals(6.0, $res3['price_max']);
    }

    public function test_media_and_link_extraction(): void
    {
        $scraper = new DienvidkurzemeScraper();

        $desc = '<p>Plašāka informācija par pasākumu <a href="https://www.dienvidkurzeme.travel/jaunumi/params/post/123/pasakums">dienvidkurzeme.travel</a>.</p>' .
            '<p>Biļetes nopērkamas <a href="https://www.bilesuparadize.lv/lv/event/999">Biļešu paradīzē</a>.</p>';
        $attaches = [
            'https://drive.google.com/open?id=1xtc91rcUcKAbxhGDvIRc3FygBoQgSNLY',
        ];

        $res = $scraper->extractUrlsAndImage($desc, $attaches, 'https://www.dienvidkurzeme.travel/pasakumi-dienvidkurzeme/');

        $this->assertEquals('https://www.bilesuparadize.lv/lv/event/999', $res['ticket_url']);
        $this->assertEquals('https://www.dienvidkurzeme.travel/jaunumi/params/post/123/pasakums', $res['source_url']);
        $this->assertEquals('https://lh3.googleusercontent.com/d/1xtc91rcUcKAbxhGDvIRc3FygBoQgSNLY', $res['image_url']);
    }

    public function test_calendar_discovery_from_html(): void
    {
        $scraper = new DienvidkurzemeScraper();

        // Sample HTML with Mozello embedded Google Calendar
        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <body>
            <div class="moze-inserted-code">
                <iframe src="https://calendar.google.com/calendar/embed?height=600&amp;wkst=1&amp;ctz=Europe%2FRiga&amp;showPrint=0&amp;mode=AGENDA&amp;src=NDFhMzEyMzhlZDk0N2Q4MDcyN2JhYmY5NTAzZThmZWY0ZDU5ZDIyZjg2M2M4OTQ5NTQyMDQ3ZWM2Yjg3ZjgzNkBncm91cC5jYWxlbmRhci5nb29nbGUuY29t&amp;color=%23D50000"></iframe>
            </div>
        </body>
        </html>
HTML;

        $mock = new MockHandler([
            new Response(200, [], $html),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $scraper->setClient($client);

        $icsUrl = $scraper->discoverIcsUrl('https://www.dienvidkurzeme.travel/pasakumi-dienvidkurzeme/');
        $this->assertStringContainsString('calendar.google.com/calendar/ical/', $icsUrl);
        $this->assertStringContainsString('41a31238ed947d80727babf9503e8fef4d59d22f863c8949542047ec6b87f836%40group.calendar.google.com', $icsUrl);
    }

    public function test_mock_http_scraping_flow(): void
    {
        $futureDate = Carbon::tomorrow('Europe/Riga')->format('Ymd\T170000\Z');
        $futureEndDate = Carbon::tomorrow('Europe/Riga')->addHours(2)->format('Ymd\T190000\Z');

        $icsContent = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Google Inc//Google Calendar 70.9054//EN
BEGIN:VEVENT
UID:event-future-1@google.com
SUMMARY:Valsts svētku koncerts ar Ansi Klintsonu
DESCRIPTION:Aizputes kultūras namā svētku koncerts. Ieeja bez maksas. Vairāk: <a href="https://www.dienvidkurzeme.travel/jaunumi/params/post/555/svētki">Info</a>
LOCATION:Aizputes kultūras nams\, Atmodas iela 16\, Aizpute\, Dienvidkurzemes novads\, Latvija
DTSTART:{$futureDate}
DTEND:{$futureEndDate}
STATUS:CONFIRMED
END:VEVENT
BEGIN:VEVENT
UID:event-past-1@google.com
SUMMARY:Pagātnes pasākums
DESCRIPTION:Šim pasākumam jābūt izlaistam
LOCATION:Grobiņa
DTSTART:20200101T100000Z
DTEND:20200101T120000Z
STATUS:CONFIRMED
END:VEVENT
END:VCALENDAR
ICS;

        $pageHtml = '<iframe src="https://calendar.google.com/calendar/embed?src=NDFhMzEyMzhlZDk0N2Q4MDcyN2JhYmY5NTAzZThmZWY0ZDU5ZDIyZjg2M2M4OTQ5NTQyMDQ3ZWM2Yjg3ZjgzNkBncm91cC5jYWxlbmRhci5nb29nbGUuY29t"></iframe>';

        $mock = new MockHandler([
            new Response(200, [], $pageHtml),
            new Response(200, [], $icsContent),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $scraper = new DienvidkurzemeScraper();
        $scraper->setClient($client);

        $source = new Source([
            'name' => 'Dienvidkurzeme Travel',
            'slug' => 'dienvidkurzeme',
            'url' => 'https://www.dienvidkurzeme.travel/pasakumi-dienvidkurzeme/',
        ]);

        $events = $scraper->scrape($source);

        // Past event skipped, only future event scraped
        $this->assertCount(1, $events);

        /** @var ScrapedEventDTO $event */
        $event = $events->first();
        $this->assertEquals('Valsts svētku koncerts ar Ansi Klintsonu', $event->title);
        $this->assertEquals('Aizputes kultūras nams', $event->venueName);
        $this->assertEquals('Aizpute', $event->city);
        $this->assertEquals('Kurzeme', $event->region);
        $this->assertTrue($event->isFree);
        $this->assertEquals(['Mūzika'], $event->categoryNames);
        $this->assertEquals('event-future-1@google.com', $event->sourceExternalId);
        $this->assertEquals('https://www.dienvidkurzeme.travel/jaunumi/params/post/555/svētki', $event->sourceUrl);
    }

    public function test_ingest_into_database_with_event_ingestion_service(): void
    {
        $futureDate = Carbon::tomorrow('Europe/Riga')->format('Ymd\T180000\Z');

        $icsContent = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-ingest-test-1@google.com
SUMMARY:Izrāde “Augusts. Osedžas zeme”
DESCRIPTION:Liepājas teātra izrāde Aizputē. Biļetes 5 eiro.
LOCATION:Aizputes kultūras nams\, Atmodas iela 16\, Aizpute\, Dienvidkurzemes novads
DTSTART:{$futureDate}
STATUS:CONFIRMED
ATTACH;FILENAME=poster.jpg:https://drive.google.com/open?id=12345testId
END:VEVENT
END:VCALENDAR
ICS;

        $pageHtml = '<iframe src="https://calendar.google.com/calendar/embed?src=NDFhMzEyMzhlZDk0N2Q4MDcyN2JhYmY5NTAzZThmZWY0ZDU5ZDIyZjg2M2M4OTQ5NTQyMDQ3ZWM2Yjg3ZjgzNkBncm91cC5jYWxlbmRhci5nb29nbGUuY29t"></iframe>';

        $mock = new MockHandler([
            new Response(200, [], $pageHtml),
            new Response(200, [], $icsContent),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $source = Source::create([
            'name' => 'Dienvidkurzeme Travel',
            'slug' => 'dienvidkurzeme',
            'url' => 'https://www.dienvidkurzeme.travel/pasakumi-dienvidkurzeme/',
            'scraper_class' => DienvidkurzemeScraper::class,
            'type' => 'municipality',
            'is_active' => true,
        ]);

        $scraper = new DienvidkurzemeScraper();
        $scraper->setClient($client);
        $this->app->instance(DienvidkurzemeScraper::class, $scraper);

        $ingestionService = app(EventIngestionService::class);
        $log = $ingestionService->ingest($source);

        $this->assertEquals('success', $log->status);
        $this->assertEquals(1, $log->items_found);
        $this->assertEquals(1, $log->items_created);

        $event = Event::where('source_id', $source->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('Izrāde “Augusts. Osedžas zeme”', $event->title);
        $this->assertEquals('event-ingest-test-1@google.com', $event->source_external_id);
        $this->assertEquals('https://lh3.googleusercontent.com/d/12345testId', $event->image_url);
        $this->assertEquals(5.0, $event->price_min);
        $this->assertEquals(5.0, $event->price_max);

        // Location verification
        $this->assertNotNull($event->location);
        $this->assertEquals('Aizputes kultūras nams', $event->location->name);
        $this->assertEquals('Aizpute', $event->location->city);
        $this->assertEquals('Kurzeme', $event->location->region);
    }
}
