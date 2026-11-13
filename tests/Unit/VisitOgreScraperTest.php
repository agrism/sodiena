<?php

namespace Tests\Unit;

use App\Models\Source;
use App\Services\Scrapers\Sources\VisitOgreScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisitOgreScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitogre_scraper_metadata(): void
    {
        $scraper = new VisitOgreScraper();
        $this->assertEquals('visitogre', $scraper->getSlug());
        $this->assertEquals('Visit Ogre', $scraper->getName());
        $this->assertEquals('https://www.visitogre.lv', VisitOgreScraper::BASE_URL);
        $this->assertEquals('/lv/pasakumi', VisitOgreScraper::EVENTS_PATH);
    }

    public function test_extract_events_from_html(): void
    {
        $html = <<<HTML
        <div class="event_date">13.11.2026</div>
        <table class="event" id="event_4610">
            <tr>
                <td class="thumb">
                    <a href="https://www.visitogre.lv/lv/pasakumi/4610/dokumentala-isfilma-par-kamanam-virazam-un-dzivi-stasts-par">
                        <img src="https://www.visitogre.lv/data/images/13_novembris_ikskile_0.jpeg" alt="" />
                    </a>
                </td>
                <td>
                    <h3 style="font-weight: normal;">
                        <a href="https://www.visitogre.lv/lv/pasakumi/4610/dokumentala-isfilma-par-kamanam-virazam-un-dzivi-stasts-par">Dokumentālā īsfilma “Par kamanām, virāžām un dzīvi” Ikšķilē</a>
                    </h3>
                </td>
            </tr>
        </table>
HTML;

        $scraper = new VisitOgreScraper();
        $discovered = [];
        $count = $scraper->extractEventsFromHtml($html, 'https://www.visitogre.lv/lv/pasakumi', $discovered);

        $this->assertEquals(1, $count);
        $this->assertArrayHasKey('4610', $discovered);
        $this->assertEquals('2026-11-13', $discovered['4610']['date']);
        $this->assertEquals('Dokumentālā īsfilma “Par kamanām, virāžām un dzīvi” Ikšķilē', $discovered['4610']['listTitle']);
        $this->assertEquals('https://www.visitogre.lv/lv/pasakumi/4610/dokumentala-isfilma-par-kamanam-virazam-un-dzivi-stasts-par', $discovered['4610']['url']);
        $this->assertEquals('https://www.visitogre.lv/data/images/13_novembris_ikskile_0.jpeg', $discovered['4610']['thumbImg']);
    }

    public function test_parse_event_detail_kino_categorization(): void
    {
        $html = <<<HTML
        <h1>Dokumentālā īsfilma “Par kamanām, virāžām un dzīvi” – stāsts par ikšķilieti Daini Bremzi Ikšķilē</h1>
        <div class="event_full clearfix" id="event_4610">
            <p><strong>13. novembrī plkst. 18.00 Ikšķiles bibliotēkā filmas “Par kamanām, virāžām un dzīvi” pirmizrāde.</strong></p>
            <p>Tas ir stāsts par kamaniņu sporta pirmsākumiem Ikšķilē.</p>
            <p><img src="/data/images/13_novembris_ikskile_0.jpeg" /></p>
        </div>
HTML;

        $scraper = new VisitOgreScraper();
        $meta = [
            'id' => '4610',
            'date' => '2026-11-13',
            'url' => 'https://www.visitogre.lv/lv/pasakumi/4610/dokumentala-isfilma',
        ];

        $dto = $scraper->parseEventDetail($html, $meta['url'], $meta);

        $this->assertNotNull($dto);
        $this->assertEquals('Dokumentālā īsfilma “Par kamanām, virāžām un dzīvi” – stāsts par ikšķilieti Daini Bremzi Ikšķilē', $dto->title);
        $this->assertEquals('2026-11-13 18:00', $dto->startAt->format('Y-m-d H:i'));
        $this->assertEquals('Ikšķiles bibliotēka', $dto->venueName);
        $this->assertEquals('Ikšķile', $dto->city);
        $this->assertEquals('Vidzeme', $dto->region);
        $this->assertEquals('Peldu iela 22, Ikšķile, LV-5052', $dto->address);
        // STRICT RULE: Filma MUST be Kino and NEVER Teātris
        $this->assertContains('Kino', $dto->categoryNames);
        $this->assertNotContains('Teātris', $dto->categoryNames);
        $this->assertEquals('movie', $dto->entertainmentType);
        $this->assertEquals('visitogre-4610', $dto->sourceExternalId);
        $this->assertEquals('https://www.visitogre.lv/data/images/13_novembris_ikskile_0.jpeg', $dto->imageUrl);
    }

    public function test_parse_event_detail_concert_and_free_pricing(): void
    {
        $html = <<<HTML
        <h1>Valsts svētku koncerts “Mana Latvija” un balle Jumpravā</h1>
        <div class="event_full clearfix" id="event_4583">
            <p>14. novembrī plkst. 18.00 Jumpravas pagasta Kultūras namā svētku koncerts. Ieeja bez maksas.</p>
        </div>
HTML;

        $scraper = new VisitOgreScraper();
        $meta = [
            'id' => '4583',
            'date' => '2026-11-14',
            'url' => 'https://www.visitogre.lv/lv/pasakumi/4583/valsts-svetku-koncerts',
        ];

        $dto = $scraper->parseEventDetail($html, $meta['url'], $meta);

        $this->assertNotNull($dto);
        $this->assertEquals('2026-11-14 18:00', $dto->startAt->format('Y-m-d H:i'));
        $this->assertEquals('Jumpravas pagasta Kultūras nams', $dto->venueName);
        $this->assertEquals('Jumprava', $dto->city);
        $this->assertTrue($dto->isFree);
        $this->assertEquals(0.0, $dto->priceMin);
        $this->assertContains('Mūzika', $dto->categoryNames);
        $this->assertEquals('concert', $dto->entertainmentType);
    }

    public function test_parse_event_detail_time_range_and_ticket_url(): void
    {
        $html = <<<HTML
        <h1>Meistarklase “Mandala uz kanvas” Ogrē</h1>
        <div class="event_full clearfix" id="event_4612">
            <p>14. novembrī plkst. 11.00-14.00 Ogres novada Kultūras centra Radošajā telpā meistarklase.</p>
            <p>Biļetes: <a href="https://www.bilesuparadize.lv/lv/event/99999%20vai/">www.bilesuparadize.lv</a>. Dalības maksa 45 EUR.</p>
        </div>
HTML;

        $scraper = new VisitOgreScraper();
        $meta = [
            'id' => '4612',
            'date' => '2026-11-14',
            'url' => 'https://www.visitogre.lv/lv/pasakumi/4612/meistarklase-mandala-uz-kanvas-ogre',
        ];

        $dto = $scraper->parseEventDetail($html, $meta['url'], $meta);

        $this->assertNotNull($dto);
        $this->assertEquals('2026-11-14 11:00', $dto->startAt->format('Y-m-d H:i'));
        $this->assertNotNull($dto->endAt);
        $this->assertEquals('2026-11-14 14:00', $dto->endAt->format('Y-m-d H:i'));
        $this->assertEquals('Ogres novada Kultūras centrs, Radošā telpa', $dto->venueName);
        $this->assertEquals('Ogre', $dto->city);
        $this->assertEquals(45.0, $dto->priceMin);
        $this->assertEquals(45.0, $dto->priceMax);
        $this->assertFalse($dto->isFree);
        // Ticket URL clean extraction
        $this->assertEquals('https://www.bilesuparadize.lv/lv/event/99999', $dto->ticketUrl);
        $this->assertContains('Māksla un izstādes', $dto->categoryNames);
    }

    public function test_is_visit_ogre_event_url_guard(): void
    {
        $scraper = new VisitOgreScraper();

        // Valid URLs under /lv/pasakumi
        $this->assertTrue($scraper->isVisitOgreEventUrl('https://www.visitogre.lv/lv/pasakumi/4610/dok-filma'));
        $this->assertTrue($scraper->isVisitOgreEventUrl('https://visitogre.lv/lv/pasakumi/4610'));

        // Invalid outside URLs (must NOT wander outside visitogre.lv/lv/pasakumi)
        $this->assertFalse($scraper->isVisitOgreEventUrl('https://www.visitogre.lv/lv/kur-est'));
        $this->assertFalse($scraper->isVisitOgreEventUrl('https://www.visitogre.lv/lv/naktsmitnes'));
        $this->assertFalse($scraper->isVisitOgreEventUrl('https://www.visitogre.lv/lv/kontakti'));
        $this->assertFalse($scraper->isVisitOgreEventUrl('https://www.facebook.com/visitogre'));
        $this->assertFalse($scraper->isVisitOgreEventUrl('https://other-domain.lv/lv/pasakumi/123'));
    }

    public function test_full_scrape_with_mocked_http(): void
    {
        $source = Source::firstWhere('slug', 'visitogre') ?: Source::create([
            'name' => 'Visit Ogre',
            'slug' => 'visitogre',
            'url' => 'https://www.visitogre.lv/lv/pasakumi',
            'scraper_class' => VisitOgreScraper::class,
            'is_active' => true,
        ]);

        $futureDate = Carbon::tomorrow('Europe/Riga')->format('Y-m-d');

        // Month 0 HTML
        $monthHtml = <<<HTML
        <div id="calendar">
            <div class="header">
                <span class="title">Novembris 2026</span>
                <a class="next" href="/lv/pasakumi/?calendar=2026-12">&raquo;</a>
            </div>
            <ul class="dates">
                <li class="date"><a data-events="{$futureDate}">12</a></li>
            </ul>
        </div>
        <div id="events_list"></div>
HTML;

        // Day POST response HTML
        $dayFragHtml = <<<HTML
        <table class="event" id="event_9999">
            <tr>
                <td class="thumb">
                    <a href="https://www.visitogre.lv/lv/pasakumi/9999/test-koncerts"><img src="/data/images/test.jpg" /></a>
                </td>
                <td>
                    <h3><a href="https://www.visitogre.lv/lv/pasakumi/9999/test-koncerts">Testa svētku koncerts Ogrē</a></h3>
                </td>
            </tr>
        </table>
HTML;

        // Month 1 HTML (empty dates to trigger termination)
        $month1Html = <<<HTML
        <div id="calendar">
            <div class="header">
                <span class="title">Decembris 2026</span>
                <a class="next" href="/lv/pasakumi/?calendar=2027-1">&raquo;</a>
            </div>
            <ul class="dates"></ul>
        </div>
HTML;

        // Month 2 HTML (empty dates to trigger termination after 2 consecutive empty)
        $month2Html = <<<HTML
        <div id="calendar">
            <div class="header">
                <span class="title">Janvāris 2027</span>
            </div>
            <ul class="dates"></ul>
        </div>
HTML;

        // Event detail HTML
        $detailHtml = <<<HTML
        <h1>Testa svētku koncerts Ogrē</h1>
        <div class="event_full" id="event_9999">
            <p>Plkst. 19.00 Ogres novada Kultūras centra Lielajā zālē svētku koncerts. Ieeja bez maksas.</p>
        </div>
HTML;

        $mock = new MockHandler([
            new Response(200, [], $monthHtml),     // GET month 0
            new Response(200, [], $dayFragHtml),   // POST day
            new Response(200, [], $month1Html),    // GET month 1
            new Response(200, [], $month2Html),    // GET month 2
            new Response(200, [], $detailHtml),    // GET event 9999 detail
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $scraper = new VisitOgreScraper();
        $scraper->setClient($client);

        $events = $scraper->scrape($source);

        $this->assertCount(1, $events);
        $dto = $events->first();
        $this->assertEquals('Testa svētku koncerts Ogrē', $dto->title);
        $this->assertEquals('visitogre-9999', $dto->sourceExternalId);
        $this->assertEquals('Ogres novada Kultūras centrs, Lielā zāle', $dto->venueName);
        $this->assertEquals('Ogre', $dto->city);
        $this->assertTrue($dto->isFree);
    }
}

