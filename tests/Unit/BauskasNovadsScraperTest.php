<?php

namespace Tests\Unit;

use App\Models\Source;
use App\Services\Scrapers\Sources\BauskasNovadsScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BauskasNovadsScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata(): void
    {
        $scraper = new BauskasNovadsScraper();
        $this->assertEquals('bauskas-novads', $scraper->getSlug());
        $this->assertEquals('Bauskas novada pašvaldība', $scraper->getName());
        $this->assertEquals('https://www.bauskasnovads.lv', BauskasNovadsScraper::BASE_URL);
        $this->assertEquals('/lv/notikumu-kalendars', BauskasNovadsScraper::CALENDAR_PATH);
    }

    public function test_skips_administrative_and_council_meetings(): void
    {
        $scraper = new BauskasNovadsScraper();

        // 1. Categories with council/committee meetings must be skipped
        $this->assertTrue($scraper->isExcludedEvent(['Komitejas sēde'], 'Sēdes darba kārtība'));
        $this->assertTrue($scraper->isExcludedEvent(['Finanšu komitejas sēde'], 'Kārtējā sēde'));
        $this->assertTrue($scraper->isExcludedEvent(['Domes sēde'], 'Bauskas novada domes sēde'));
        $this->assertTrue($scraper->isExcludedEvent(['Pašvaldība', 'ārkārtas sēdes'], 'Ārkārtas sēdes norise'));

        // 2. Titles with council/committee meetings must be skipped
        $this->assertTrue($scraper->isExcludedEvent(['Pašvaldība'], 'Izglītības, kultūras un sporta komitejas sēde'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Deputātu tikšanās ar iedzīvotājiem Iecavā'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Domes priekšsēdētājs pieņem iedzīvotājus'));

        // 3. Cultural and entertainment events must NOT be skipped
        $this->assertFalse($scraper->isExcludedEvent(['Koncerts'], 'Valsts svētku koncerts Bauskā'));
        $this->assertFalse($scraper->isExcludedEvent(['Teātris'], 'Izrāde "Bauskas leģendas"'));
        $this->assertFalse($scraper->isExcludedEvent(['Kino'], 'Kinoseanss Iecavā'));
        $this->assertFalse($scraper->isExcludedEvent(['Izstādes'], 'Mākslas izstāde Rundāles pilī'));
    }

    public function test_date_parsing(): void
    {
        $scraper = new BauskasNovadsScraper();

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
        $scraper = new BauskasNovadsScraper();

        // 1. Known venue: Bauskas Kultūras centrs
        $loc1 = $scraper->resolveLocation('Bauskas Kultūras centrs', 'Valsts svētku sarīkojums');
        $this->assertEquals('Bauskas Kultūras centrs', $loc1['name']);
        $this->assertEquals('Bauska', $loc1['city']);
        $this->assertEquals('Zemgale', $loc1['region']);
        $this->assertEquals('cultural_center', $loc1['place_type']);
        $this->assertStringContainsString('Kalna iela 18', $loc1['address']);

        // 2. Known venue: Iecavas kultūras nams
        $loc2 = $scraper->resolveLocation('Iecava, Rīgas iela 18', 'Koncerts Iecavas kultūras namā');
        $this->assertEquals('Iecavas kultūras nams', $loc2['name']);
        $this->assertEquals('Iecava', $loc2['city']);

        // 3. Known venue: Rundāles pils
        $loc3 = $scraper->resolveLocation('Rundāles pils', 'Klasiskās mūzikas koncerts Baltajā zālē');
        $this->assertEquals('Rundāles pils muzejs', $loc3['name']);
        $this->assertEquals('Pilsrundāle', $loc3['city']);
        $this->assertEquals('historical', $loc3['place_type']);

        // 4. Known venue: Vecumnieku Tautas nams
        $loc4 = $scraper->resolveLocation('Vecumnieki', 'Balles vakars Vecumnieku Tautas namā');
        $this->assertEquals('Vecumnieku Tautas nams', $loc4['name']);
        $this->assertEquals('Vecumnieki', $loc4['city']);

        // 5. Parish detection: Skaistkalne
        $loc5 = $scraper->resolveLocation('Skaistkalnes pagasts', 'Satikšanās Skaistkalnes muižas parkā');
        $this->assertEquals('Skaistkalne', $loc5['city']);
        $this->assertEquals('Zemgale', $loc5['region']);

        // 6. Default fallback to Bauska (administrative center)
        $loc6 = $scraper->resolveLocation('Bauskas novads', 'Novada sporta svētki');
        $this->assertEquals('Bauska', $loc6['city']);
        $this->assertEquals('Zemgale', $loc6['region']);
    }

    public function test_category_resolving_strictly_follows_agents_md(): void
    {
        $scraper = new BauskasNovadsScraper();

        // 1. Cinema / Kino (STRICT RULE): Always Kino, never Teātris
        $cats1 = $scraper->resolveCategories(['Kino'], 'Filmas seanss "Zeme, kas dzied" Bauskā');
        $this->assertEquals(['Kino'], $cats1);

        $cats2 = $scraper->resolveCategories(['Kultūra'], 'Dokumentālā filma par Zemgales vēsturi kinoteātrī');
        $this->assertEquals(['Kino'], $cats2);

        $cats3 = $scraper->resolveCategories(['Kino'], 'Animācijas filma bērniem Iecavā');
        $this->assertEquals(['Kino', 'Bērniem'], $cats3);

        // 2. Theatres (STRICT RULE): Real theatre productions
        $cats4 = $scraper->resolveCategories(['Kultūra'], 'Teātra izrāde "Skroderdienas Silmačos" Bauskas pilskalnā');
        $this->assertEquals(['Teātris'], $cats4);

        // 3. Music
        $cats5 = $scraper->resolveCategories(['Koncerts'], 'Jaukto koru svētku koncerts Iecavas parkā');
        $this->assertEquals(['Mūzika'], $cats5);

        // 4. Exhibitions
        $cats6 = $scraper->resolveCategories([], 'Mākslas izstāde "Zemgales ainavas" Bauskas muzejā');
        $this->assertEquals(['Izstādes'], $cats6);

        // 5. Sports
        $cats7 = $scraper->resolveCategories([], 'Basketbola turnīrs Bauskas sporta hallē');
        $this->assertEquals(['Sports'], $cats7);

        // 6. Fallback: Cits
        $cats8 = $scraper->resolveCategories([], 'Kopienas vakars un tikšanās ar novadpētnieku');
        $this->assertEquals(['Cits'], $cats8);
    }

    public function test_price_parsing(): void
    {
        $scraper = new BauskasNovadsScraper();

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
            'name' => 'Bauskas novada pašvaldība',
            'slug' => 'bauskas-novads',
            'url' => 'https://www.bauskasnovads.lv/lv/notikumu-kalendars',
            'scraper_class' => BauskasNovadsScraper::class,
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
                    <span class="describing-field-value">Bauskas Kultūras centrs</span>
                </div>
                <div class="event-image">
                    <img src="/files/gallery_images/bauskas_valsts_svetku_koncerts.jpg" />
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/valsts-svetku-koncerts-bauska">Latvijas Republikas proklamēšanas dienai veltīts koncerts Bauskā</a>
                </h2>
                <div class="event-categories">
                    <a class="badge">Koncerts</a>
                    <a class="badge">Kultūra</a>
                </div>
                <div class="event-content">
                    <p>Bauskas Kultūras centrā izskanēs svinīgs svētku koncerts ar novada jaukto kori "Dzīle". Ieeja brīva.</p>
                </div>
            </div>

            <!-- Event 2: Valid cinema screening -->
            <div class="event">
                <div class="event-info">
                    <span class="date-date">{$formattedDate}</span>
                    <span class="date-time">19.30</span>
                    <span class="describing-field-value">Iecavas kultūras nams</span>
                </div>
                <div class="event-image">
                    <img src="/files/gallery_images/kino_straume_iecava.jpg" />
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/filmas-seanss-straume-iecava">Animācijas filmas "Straume" kinoseanss Iecavā</a>
                </h2>
                <div class="event-categories">
                    <a class="badge">Kino</a>
                </div>
                <div class="event-content">
                    <p>Kinoseanss visai ģimenei. Biļetes cena: 4.00 EUR.</p>
                </div>
            </div>

            <!-- Event 3: Administrative Committee Meeting (MUST BE SKIPPED) -->
            <div class="event">
                <div class="event-info">
                    <span class="date-date">{$formattedDate}</span>
                    <span class="date-time">10.00</span>
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/izglitibas-un-kulturas-komiteja-bauska">Izglītības, kultūras un sporta komitejas sēde</a>
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
                    <a href="/lv/notikums/pagatnes-notikums-bauska-2024">Vēsturisks pagātnes pasākums</a>
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
                <p>Bauskas Kultūras centrā izskanēs svinīgs svētku koncerts ar novada jaukto kori "Dzīle". Ieeja brīva.</p>
                <p>Programmā latviešu komponistu skaņdarbi un patriotiskas dziesmas svētku noskaņās.</p>
            </div>
        </article>
HTML;

        $detailHtml2 = <<<HTML
        <article class="node notikums">
            <div class="field--name-body">
                <p>Kinoseanss visai ģimenei. Animācijas filma "Straume". Biļetes cena: 4.00 EUR.</p>
                <p>Režisors Gints Zilbalodis. Starptautiski atzīta animācijas filma.</p>
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

        $scraper = new BauskasNovadsScraper();
        $scraper->setClient($client);

        $events = $scraper->scrape($source);

        // Exactly 2 valid events extracted (committee meeting and past event were skipped)
        $this->assertCount(2, $events);

        // Event 1 verification
        $e1 = $events->first();
        $this->assertEquals('Latvijas Republikas proklamēšanas dienai veltīts koncerts Bauskā', $e1->title);
        $this->assertEquals('Bauskas Kultūras centrs', $e1->venueName);
        $this->assertEquals('Bauska', $e1->city);
        $this->assertEquals('Zemgale', $e1->region);
        $this->assertEquals('cultural_center', $e1->placeType);
        $this->assertEquals(['Mūzika'], $e1->categoryNames);
        $this->assertEquals('concert', $e1->entertainmentType);
        $this->assertTrue($e1->isFree);
        $this->assertEquals('https://www.bauskasnovads.lv/files/gallery_images/bauskas_valsts_svetku_koncerts.jpg', $e1->imageUrl);
        $this->assertEquals('https://www.bauskasnovads.lv/lv/notikums/valsts-svetku-koncerts-bauska', $e1->sourceUrl);
        $this->assertEquals('bauskasnovads-valsts-svetku-koncerts-bauska', $e1->sourceExternalId);
        $this->assertStringContainsString('Programmā latviešu komponistu', $e1->description);

        // Event 2 verification (Strict Kino classification)
        $e2 = $events->get(1);
        $this->assertEquals('Animācijas filmas "Straume" kinoseanss Iecavā', $e2->title);
        $this->assertEquals('Iecavas kultūras nams', $e2->venueName);
        $this->assertEquals('Iecava', $e2->city);
        $this->assertEquals(['Kino', 'Bērniem'], $e2->categoryNames);
        $this->assertEquals('movie', $e2->entertainmentType);
        $this->assertFalse($e2->isFree);
        $this->assertEquals(4.00, (float) $e2->priceMin);
        $this->assertEquals('bauskasnovads-filmas-seanss-straume-iecava', $e2->sourceExternalId);
        $this->assertStringContainsString('Režisors Gints Zilbalodis', $e2->description);
    }

    public function test_fetch_detail_description_extracts_full_text(): void
    {
        $detailHtml = <<<HTML
        <article class="node notikums">
            <div class="field--name-body">
                <p>9.00 B.Jukņeviča "Tās kartupeļu kastes..." Vecumnieku tautas nama amatierteātris</p>
                <p>11.00 R.Blaumanis "Trīnes grēki" Bauskas Tautas teātris</p>
                <p>13.30 A.Upīts "Sūnu ciema zēni" Iecavas amatierteātris</p>
                <p>Ieeja brīva.</p>
            </div>
        </article>
HTML;

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $detailHtml),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $scraper = new BauskasNovadsScraper();
        $scraper->setClient($client);

        $desc = $scraper->fetchDetailDescription('https://www.bauskasnovads.lv/lv/notikums/bauskas-novada-amatierteatru-skate-3');
        $this->assertNotNull($desc);
        $this->assertStringContainsString('Vecumnieku tautas nama amatierteātris', $desc);
        $this->assertStringContainsString('Trīnes grēki', $desc);
        $this->assertStringContainsString('Ieeja brīva.', $desc);
    }
}
