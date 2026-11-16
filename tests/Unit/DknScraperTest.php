<?php

namespace Tests\Unit;

use App\Models\Source;
use App\Services\Scrapers\Sources\DknScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DknScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata(): void
    {
        $scraper = new DknScraper();
        $this->assertEquals('dkn', $scraper->getSlug());
        $this->assertEquals('Dienvidkurzemes novada pašvaldība', $scraper->getName());
        $this->assertEquals('https://www.dkn.lv', DknScraper::BASE_URL);
        $this->assertEquals('/lv/notikumu-kalendars', DknScraper::CALENDAR_PATH);
    }

    public function test_skips_administrative_and_council_meetings(): void
    {
        $scraper = new DknScraper();

        // 1. Categories with council/committee meetings must be skipped
        $this->assertTrue($scraper->isExcludedEvent(['Komitejas sēde'], 'Sēdes darba kārtība'));
        $this->assertTrue($scraper->isExcludedEvent(['Finanšu komitejas sēde'], 'Kārtējā sēde'));
        $this->assertTrue($scraper->isExcludedEvent(['Domes sēde'], 'Dienvidkurzemes novada domes sēde'));
        $this->assertTrue($scraper->isExcludedEvent(['Pašvaldība', 'ārkārtas sēdes'], 'Ārkārtas sēdes norise'));

        // 2. Titles with council/committee meetings must be skipped
        $this->assertTrue($scraper->isExcludedEvent(['Pašvaldība'], 'Izglītības, kultūras un sporta komitejas sēde'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Deputātu tikšanās ar iedzīvotājiem Priekulē'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Domes priekšsēdētājs pieņem iedzīvotājus'));

        // 3. Cultural and entertainment events must NOT be skipped
        $this->assertFalse($scraper->isExcludedEvent(['Koncerts'], 'Valsts svētku koncerts Aizputē'));
        $this->assertFalse($scraper->isExcludedEvent(['Teātris'], 'Izrāde "Dienvidkurzemes stāsti"'));
        $this->assertFalse($scraper->isExcludedEvent(['Kino'], 'Kinoseanss Grobiņā'));
        $this->assertFalse($scraper->isExcludedEvent(['Izstādes'], 'Tautas mākslas izstāde'));
    }

    public function test_date_parsing(): void
    {
        $scraper = new DknScraper();

        // Single date with time: "18. novembris, 2026", "18.00"
        [$start1, $end1, $allDay1] = $scraper->parseLatvianDates('18. novembris, 2026', '18.00');
        $this->assertEquals('2026-11-18 18:00:00', $start1->toDateTimeString());
        $this->assertNull($end1);
        $this->assertFalse($allDay1);

        // Date range with start and end times: "15. - 18. novembris, 2026", "12.00 - 15.00"
        [$start2, $end2, $allDay2] = $scraper->parseLatvianDates('15. - 18. novembris, 2026', '12.00 - 15.00');
        $this->assertEquals('2026-11-15 12:00:00', $start2->toDateTimeString());
        $this->assertEquals('2026-11-18 15:00:00', $end2->toDateTimeString());
        $this->assertFalse($allDay2);

        // Single date with "Visu dienu"
        [$start3, $end3, $allDay3] = $scraper->parseLatvianDates('24. decembris, 2026', 'Visu dienu');
        $this->assertEquals('2026-12-24 00:00:00', $start3->toDateTimeString());
        $this->assertEquals('2026-12-24 23:59:59', $end3->toDateTimeString());
        $this->assertTrue($allDay3);

        // Standard numeric date: "05.12.2026", "19:30"
        [$start4, $end4, $allDay4] = $scraper->parseLatvianDates('05.12.2026', '19:30');
        $this->assertEquals('2026-12-05 19:30:00', $start4->toDateTimeString());
        $this->assertNull($end4);
    }

    public function test_location_resolving(): void
    {
        $scraper = new DknScraper();

        // 1. Known venue: Aizputes kultūras nams
        $loc1 = $scraper->resolveLocation('Aizputes kultūras nams', 'Valsts svētku sarīkojums');
        $this->assertEquals('Aizputes kultūras nams', $loc1['name']);
        $this->assertEquals('Aizpute', $loc1['city']);
        $this->assertEquals('Kurzeme', $loc1['region']);
        $this->assertEquals('cultural_center', $loc1['place_type']);
        $this->assertStringContainsString('Atmodas iela 16', $loc1['address']);

        // 2. Known venue: Priekules kultūras nams
        $loc2 = $scraper->resolveLocation('Priekule, Peldu iela 1', 'Balle Priekules kultūras namā');
        $this->assertEquals('Priekules kultūras nams', $loc2['name']);
        $this->assertEquals('Priekule', $loc2['city']);

        // 3. Known venue: Pāvilostas pilsētas kultūras nams
        $loc3 = $scraper->resolveLocation('Pāvilosta, Dzintaru iela', 'Koncerts Pāvilostas kultūras namā');
        $this->assertEquals('Pāvilostas pilsētas kultūras nams', $loc3['name']);
        $this->assertEquals('Pāvilosta', $loc3['city']);

        // 4. Parish detection: Kazdanga
        $loc4 = $scraper->resolveLocation('Kazdangas pagasts', 'Satikšanās Kazdangas parkā');
        $this->assertEquals('Kazdanga', $loc4['city']);
        $this->assertEquals('Kurzeme', $loc4['region']);

        // 5. Default fallback to Grobiņa (administrative center)
        $loc5 = $scraper->resolveLocation('Dienvidkurzemes novads', 'Novada sporta svētki');
        $this->assertEquals('Grobiņa', $loc5['city']);
        $this->assertEquals('Kurzeme', $loc5['region']);
    }

    public function test_category_resolving_strictly_follows_agents_md(): void
    {
        $scraper = new DknScraper();

        // 1. Cinema / Kino (STRICT RULE): Always Kino, never Teātris
        $cats1 = $scraper->resolveCategories(['Kino'], 'Filmas seanss "Straume" Aizputē');
        $this->assertEquals(['Kino'], $cats1);

        $cats2 = $scraper->resolveCategories(['Kultūra'], 'Dokumentālā filma par novada vēsturi kinoteātrī');
        $this->assertEquals(['Kino'], $cats2);

        $cats3 = $scraper->resolveCategories(['Kino'], 'Animācijas filma bērniem Priekulē');
        $this->assertEquals(['Kino', 'Bērniem'], $cats3);

        // 2. Theatres (STRICT RULE): Real theatre productions
        $cats4 = $scraper->resolveCategories(['Kultūra'], 'Teātra izrāde "Dullais Dauka" Grobiņā');
        $this->assertEquals(['Teātris'], $cats4);

        // 3. Music
        $cats5 = $scraper->resolveCategories(['Koncerts'], 'Jaukto koru svētku koncerts Kazdangā');
        $this->assertEquals(['Mūzika'], $cats5);

        // 4. Exhibitions
        $cats6 = $scraper->resolveCategories([], 'Gleznu izstāde "Kurzemes krāsas" Rucavā');
        $this->assertEquals(['Izstādes'], $cats6);

        // 5. Sports
        $cats7 = $scraper->resolveCategories([], 'Basketbola turnīrs Pāvilostā');
        $this->assertEquals(['Sports'], $cats7);

        // 6. Fallback: Cits
        $cats8 = $scraper->resolveCategories([], 'Kopienas vakars un sarunas pie tējas');
        $this->assertEquals(['Cits'], $cats8);
    }

    public function test_price_parsing(): void
    {
        $scraper = new DknScraper();

        $p1 = $scraper->parsePrice('Ieeja brīva. Visi laipni aicināti!');
        $this->assertTrue($p1['is_free']);
        $this->assertEquals(0.0, $p1['price_min']);

        $p2 = $scraper->parsePrice('Biļetes cena: 5.00 EUR pie ieejas.');
        $this->assertFalse($p2['is_free']);
        $this->assertEquals(5.0, $p2['price_min']);
        $this->assertEquals(5.0, $p2['price_max']);
    }

    public function test_scrape_extracts_events_directly_from_calendar_without_subpage_crawling(): void
    {
        $source = Source::create([
            'name' => 'Dienvidkurzemes novada pašvaldība',
            'slug' => 'dkn',
            'url' => 'https://www.dkn.lv/lv/notikumu-kalendars',
            'scraper_class' => DknScraper::class,
            'is_active' => true,
        ]);

        $futureDate = Carbon::now('Europe/Riga')->addDays(2);
        $formattedDate = $futureDate->translatedFormat('j. F, Y');

        $html = <<<HTML
        <div class="calendar-list">
            <!-- Event 1: Valid cultural concert -->
            <div class="event">
                <div class="event-info">
                    <span class="date-date">{$formattedDate}</span>
                    <span class="date-time">18.00</span>
                    <span class="describing-field-value">Aizputes kultūras nams</span>
                </div>
                <div class="event-image">
                    <img src="/files/gallery_images/valsts_svetku_koncerts.jpg" />
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/valsts-svetku-koncerts-aizpute">Latvijas Republikas proklamēšanas dienai veltīts koncerts</a>
                </h2>
                <div class="event-categories">
                    <a class="badge">Koncerts</a>
                    <a class="badge">Kultūra</a>
                </div>
                <div class="event-content">
                    <p>Aizputes kultūras namā izskanēs svinīgs svētku koncerts ar novada jaukto kori. Ieeja brīva.</p>
                </div>
            </div>

            <!-- Event 2: Valid cinema screening -->
            <div class="event">
                <div class="event-info">
                    <span class="date-date">{$formattedDate}</span>
                    <span class="date-time">19.30</span>
                    <span class="describing-field-value">Priekules kultūras nams</span>
                </div>
                <div class="event-image">
                    <img src="/files/gallery_images/kino_straume.jpg" />
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/filmas-seanss-straume-priekule">Animācijas filmas "Straume" kinoseanss</a>
                </h2>
                <div class="event-categories">
                    <a class="badge">Kino</a>
                </div>
                <div class="event-content">
                    <p>Kinoseanss visai ģimenei. Biļetes cena: 3.50 EUR.</p>
                </div>
            </div>

            <!-- Event 3: Administrative Committee Meeting (MUST BE SKIPPED) -->
            <div class="event">
                <div class="event-info">
                    <span class="date-date">{$formattedDate}</span>
                    <span class="date-time">10.00</span>
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/izglitibas-un-kulturas-komiteja">Izglītības, kultūras un sporta komitejas sēde</a>
                </h2>
                <div class="event-categories">
                    <a class="badge">Komitejas sēde</a>
                </div>
                <div class="event-content">
                    <p>Izskatāmie darba kārtības jautājumi.</p>
                </div>
            </div>

            <!-- Event 4: Past event (MUST BE SKIPPED according to AGENTS.md) -->
            <div class="event">
                <div class="event-info">
                    <span class="date-date">10. janvāris, 2024</span>
                    <span class="date-time">14.00</span>
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/pagatnes-notikums-2024">Vēsturisks pagātnes pasākums</a>
                </h2>
                <div class="event-categories">
                    <a class="badge">Kultūra</a>
                </div>
            </div>
        </div>
HTML;

        $detailHtml1 = <<<HTML
        <article class="node notikums">
            <div class="field--name-body">
                <p>Aizputes kultūras namā svinīgs koncerts par godu Latvijas Republikas proklamēšanas dienai. Ieeja brīva.</p>
                <p>Programmā Aizputes mūzikas skolas audzēkņu un kora uzstāšanās.</p>
            </div>
        </article>
HTML;

        $detailHtml2 = <<<HTML
        <article class="node notikums">
            <div class="field--name-body">
                <p>Animācijas filmas "Straume" kinoseanss Priekulē. Biļetes cena: 3.50 EUR.</p>
                <p>Režisors Gints Zilbalodis. Seanss visai ģimenei.</p>
            </div>
        </article>
HTML;

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'text/html'], $detailHtml1),
            new Response(200, ['Content-Type' => 'text/html'], $detailHtml2),
            new Response(200, ['Content-Type' => 'text/html'], '<div class="calendar-list"></div>'), // empty next page
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $scraper = new DknScraper();
        $scraper->setClient($client);

        $events = $scraper->scrape($source);

        // Exactly 2 valid events extracted (committee meeting and past event were skipped)
        $this->assertCount(2, $events);

        // Event 1 verification
        $e1 = $events->first();
        $this->assertEquals('Latvijas Republikas proklamēšanas dienai veltīts koncerts', $e1->title);
        $this->assertEquals('Aizputes kultūras nams', $e1->venueName);
        $this->assertEquals('Aizpute', $e1->city);
        $this->assertEquals('Kurzeme', $e1->region);
        $this->assertEquals('cultural_center', $e1->placeType);
        $this->assertEquals(['Mūzika'], $e1->categoryNames);
        $this->assertEquals('concert', $e1->entertainmentType);
        $this->assertTrue($e1->isFree);
        $this->assertEquals('https://www.dkn.lv/files/gallery_images/valsts_svetku_koncerts.jpg', $e1->imageUrl);
        $this->assertEquals('https://www.dkn.lv/lv/notikums/valsts-svetku-koncerts-aizpute', $e1->sourceUrl);
        $this->assertEquals('dkn-valsts-svetku-koncerts-aizpute', $e1->sourceExternalId);
        $this->assertStringContainsString('Aizputes mūzikas skolas audzēkņu', $e1->description);

        // Event 2 verification (Strict Kino classification)
        $e2 = $events->get(1);
        $this->assertEquals('Animācijas filmas "Straume" kinoseanss', $e2->title);
        $this->assertEquals('Priekules kultūras nams', $e2->venueName);
        $this->assertEquals('Priekule', $e2->city);
        $this->assertEquals(['Kino', 'Bērniem'], $e2->categoryNames);
        $this->assertEquals('movie', $e2->entertainmentType);
        $this->assertFalse($e2->isFree);
        $this->assertEquals(3.50, (float) $e2->priceMin);
        $this->assertEquals('dkn-filmas-seanss-straume-priekule', $e2->sourceExternalId);
        $this->assertStringContainsString('Režisors Gints Zilbalodis', $e2->description);
    }

    public function test_fetch_detail_description_extracts_full_text(): void
    {
        $detailHtml = <<<HTML
        <article class="node notikums">
            <div class="field--name-body">
                <p>Aizputes kultūras namā 10.00 Aizputes amatierteātris “Priekšspēle” ar izrādi “Čūsku ballīte”.</p>
                <p>Režisore: Juta Priedniece. Autore: Anita Grīniece.</p>
                <p>12.00 Vaiņodes amatierteātris ar izrādi "Kaimiņu būšana".</p>
                <p>Ieejas maksa: 2.00 EUR.</p>
            </div>
        </article>
HTML;

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $detailHtml),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $scraper = new DknScraper();
        $scraper->setClient($client);

        $desc = $scraper->fetchDetailDescription('https://www.dkn.lv/lv/notikums/dienvidkurzemes-novada-amatierteatru-skate-2026');
        $this->assertNotNull($desc);
        $this->assertStringContainsString('Čūsku ballīte', $desc);
        $this->assertStringContainsString('Juta Priedniece', $desc);
        $this->assertStringContainsString('Vaiņodes amatierteātris', $desc);
    }
}
