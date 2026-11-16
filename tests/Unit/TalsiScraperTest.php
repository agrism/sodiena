<?php

namespace Tests\Unit;

use App\Models\Source;
use App\Services\Scrapers\Sources\TalsiScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TalsiScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata(): void
    {
        $scraper = new TalsiScraper();
        $this->assertEquals('talsi', $scraper->getSlug());
        $this->assertEquals('Talsu novada pašvaldība', $scraper->getName());
        $this->assertEquals('https://www.talsunovads.lv', TalsiScraper::BASE_URL);
        $this->assertEquals('/lv/notikumu-kalendars', TalsiScraper::CALENDAR_PATH);
    }

    public function test_skips_administrative_and_council_meetings(): void
    {
        $scraper = new TalsiScraper();

        // 1. Categories with council/committee meetings must be skipped
        $this->assertTrue($scraper->isExcludedEvent(['Komitejas sēde'], 'Sēdes darba kārtība'));
        $this->assertTrue($scraper->isExcludedEvent(['Finanšu komitejas sēde'], 'Kārtējā finanšu sēde'));
        $this->assertTrue($scraper->isExcludedEvent(['Domes sēde'], 'Talsu novada domes sēde'));
        $this->assertTrue($scraper->isExcludedEvent(['Pašvaldība', 'ārkārtas sēdes'], 'Ārkārtas sēdes norise'));

        // 2. Titles with council/committee meetings must be skipped
        $this->assertTrue($scraper->isExcludedEvent(['Pašvaldība'], 'Izglītības, kultūras un sporta komitejas sēde'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Deputātu tikšanās ar iedzīvotājiem Rojā'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Domes priekšsēdētājs pieņem iedzīvotājus Talsos'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Deputāta pieņemšana Sabilē'));

        // 3. Cultural and entertainment events must NOT be skipped
        $this->assertFalse($scraper->isExcludedEvent(['Koncerts'], 'Valsts svētku koncerts Talsu tautas namā'));
        $this->assertFalse($scraper->isExcludedEvent(['Teātris'], 'Teātra izrāde Dundagas pilī'));
        $this->assertFalse($scraper->isExcludedEvent(['Kino'], 'Kinoseanss kino "Auseklis"'));
        $this->assertFalse($scraper->isExcludedEvent(['Izstādes'], 'Mākslas izstāde Talsu novada muzejā'));
    }

    public function test_date_parsing(): void
    {
        $scraper = new TalsiScraper();

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
        $scraper = new TalsiScraper();

        // 1. Known venue: Talsu Tautas nams
        $loc1 = $scraper->resolveLocation('Talsu Tautas nams', 'Valsts svētku sarīkojums');
        $this->assertEquals('Talsu Tautas nams', $loc1['name']);
        $this->assertEquals('Talsi', $loc1['city']);
        $this->assertEquals('Kurzeme', $loc1['region']);
        $this->assertEquals('cultural_center', $loc1['place_type']);
        $this->assertStringContainsString('Lielā iela 19/21', $loc1['address']);

        // 2. Known venue: Kino "Auseklis"
        $loc2 = $scraper->resolveLocation('Kino Auseklis', 'Filmas seanss');
        $this->assertEquals('Kino "Auseklis"', $loc2['name']);
        $this->assertEquals('Talsi', $loc2['city']);
        $this->assertEquals('cultural_center', $loc2['place_type']);

        // 3. Known venue: Dundagas pils
        $loc3 = $scraper->resolveLocation('Dundaga', 'Koncerts Dundagas pilī');
        $this->assertEquals('Dundagas pils', $loc3['name']);
        $this->assertEquals('Dundaga', $loc3['city']);
        $this->assertEquals('historical', $loc3['place_type']);

        // 4. Known venue: Rojas Kultūras centrs
        $loc4 = $scraper->resolveLocation('Roja', 'Izrāde Rojas Kultūras centrā');
        $this->assertEquals('Rojas Kultūras centrs', $loc4['name']);
        $this->assertEquals('Roja', $loc4['city']);
        $this->assertEquals('cultural_center', $loc4['place_type']);

        // 5. Known venue: Mērsraga Tautas nams
        $loc5 = $scraper->resolveLocation('Mērsrags', 'Balle Mērsraga Tautas namā');
        $this->assertEquals('Mērsraga Tautas nams', $loc5['name']);
        $this->assertEquals('Mērsrags', $loc5['city']);
        $this->assertEquals('cultural_center', $loc5['place_type']);

        // 6. Known venue: Sabiles Vīna kalns
        $loc6 = $scraper->resolveLocation('Sabile', 'Sabiles Vīna svētku pasākums Vīna kalnā');
        $this->assertEquals('Sabiles Vīna kalns', $loc6['name']);
        $this->assertEquals('Sabile', $loc6['city']);
        $this->assertEquals('park', $loc6['place_type']);

        // 7. Known venue: Nurmuižas pils
        $loc7 = $scraper->resolveLocation('Lauciene', 'Mūzikas vakars Nurmuižas pilī');
        $this->assertEquals('Nurmuižas pils komplekss', $loc7['name']);
        $this->assertEquals('Lauciene', $loc7['city']);
        $this->assertEquals('historical', $loc7['place_type']);

        // 8. Known venue: Kolkas Lībiešu saieta nams
        $loc8 = $scraper->resolveLocation('Kolka', 'Lībiešu kultūras dienas Kolkas saieta namā');
        $this->assertEquals('Kolkas Lībiešu saieta nams', $loc8['name']);
        $this->assertEquals('Kolka', $loc8['city']);
        $this->assertEquals('cultural_center', $loc8['place_type']);

        // 9. Default fallback to Talsi (administrative center)
        $loc9 = $scraper->resolveLocation('Talsu novads', 'Novada sporta svētki');
        $this->assertEquals('Talsi', $loc9['city']);
        $this->assertEquals('Kurzeme', $loc9['region']);
    }

    public function test_category_resolving_strictly_follows_agents_md(): void
    {
        $scraper = new TalsiScraper();

        // 1. Cinema / Kino (STRICT RULE): Always Kino, never Teātris
        $cats1 = $scraper->resolveCategories(['Kino'], 'Filmas seanss "Zeme, kas dzied" Talsos');
        $this->assertEquals(['Kino'], $cats1);

        $cats2 = $scraper->resolveCategories(['Kultūra'], 'Dokumentālā filma par Talsu vēsturi kinoteātrī "Auseklis"');
        $this->assertEquals(['Kino'], $cats2);

        $cats3 = $scraper->resolveCategories(['Kino'], 'Animācijas filma bērniem Rojā');
        $this->assertEquals(['Kino', 'Bērniem'], $cats3);

        // 2. Theatres (STRICT RULE): Real theatre productions
        $cats4 = $scraper->resolveCategories(['Kultūra'], 'Teātra izrāde "Silmači" Talsu tautas namā');
        $this->assertEquals(['Teātris'], $cats4);

        // 3. Music
        $cats5 = $scraper->resolveCategories(['Koncerts'], 'Jaukto koru svētku koncerts Sauleskalna estrādē');
        $this->assertEquals(['Mūzika'], $cats5);

        // 4. Exhibitions
        $cats6 = $scraper->resolveCategories([], 'Mākslas izstāde Talsu novada muzejā');
        $this->assertEquals(['Izstādes'], $cats6);

        // 5. Sports
        $cats7 = $scraper->resolveCategories([], 'Hokeja čempionāts Talsu ledus hallē');
        $this->assertEquals(['Sports'], $cats7);

        // 6. Fallback: Cits
        $cats8 = $scraper->resolveCategories([], 'Kopienas tikšanās un diskusija par Talsu pilsētvidi');
        $this->assertEquals(['Cits'], $cats8);
    }

    public function test_price_parsing(): void
    {
        $scraper = new TalsiScraper();

        $p1 = $scraper->parsePrice('Ieeja pasākumā bez maksas. Visi mīļi gaidīti!');
        $this->assertTrue($p1['is_free']);
        $this->assertEquals(0.0, $p1['price_min']);

        $p2 = $scraper->parsePrice('Biļetes cena: 5.00 EUR pirms pasākuma.');
        $this->assertFalse($p2['is_free']);
        $this->assertEquals(5.0, $p2['price_min']);
        $this->assertEquals(5.0, $p2['price_max']);
    }

    public function test_scrape_extracts_events_directly_from_calendar_without_subpage_crawling(): void
    {
        $source = Source::create([
            'name' => 'Talsu novada pašvaldība',
            'slug' => 'talsi',
            'url' => 'https://www.talsunovads.lv/lv/notikumu-kalendars',
            'scraper_class' => TalsiScraper::class,
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
                    <span class="date-time">19.00</span>
                    <span class="describing-field-value">Talsu Tautas nams</span>
                </div>
                <div class="event-image">
                    <img src="/files/gallery_images/talsu_valsts_svetku_koncerts.jpg" />
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/valsts-svetku-koncerts-talsos">Latvijas Republikas proklamēšanas dienas koncerts Talsos</a>
                </h2>
                <div class="event-categories">
                    <a class="badge">Koncerts</a>
                    <a class="badge">Kultūra</a>
                </div>
                <div class="event-content">
                    <p>Svinīgs koncerts Talsu Tautas namā ar novada koriem. Ieeja brīva.</p>
                </div>
            </div>

            <!-- Event 2: Valid cinema screening -->
            <div class="event">
                <div class="event-info">
                    <span class="date-date">{$formattedDate}</span>
                    <span class="date-time">18.00</span>
                    <span class="describing-field-value">Kino Auseklis</span>
                </div>
                <div class="event-image">
                    <img src="/files/gallery_images/straume_talsi.jpg" />
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/filmas-seanss-straume-talsos">Animācijas filmas "Straume" kinoseanss Talsos</a>
                </h2>
                <div class="event-categories">
                    <a class="badge">Kino</a>
                </div>
                <div class="event-content">
                    <p>Kinoseanss visai ģimenei kino "Auseklis". Biļetes cena: 4.00 EUR.</p>
                </div>
            </div>

            <!-- Event 3: Administrative Committee Meeting (MUST BE SKIPPED) -->
            <div class="event">
                <div class="event-info">
                    <span class="date-date">{$formattedDate}</span>
                    <span class="date-time">10.00</span>
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/finansu-komitejas-sede-talsi">Talsu novada pašvaldības Finanšu komitejas sēde</a>
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
                    <a href="/lv/notikums/pagatnes-notikums-talsi-2024">Vēsturisks pagātnes pasākums Talsos</a>
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
                <p>Talsu Tautas namā notiks svinīgs koncerts par godu Latvijas Republikas proklamēšanas dienai. Ieeja brīva.</p>
                <p>Koncertā piedalās Talsu novada apvienotais koris un pūtēju orķestris.</p>
            </div>
        </article>
HTML;

        $detailHtml2 = <<<HTML
        <article class="node notikums">
            <div class="field--name-body">
                <p>Animācijas filmas "Straume" kinoseanss Talsos. Biļetes cena: 4.00 EUR.</p>
                <p>Režisors Gints Zilbalodis. Seanss visai ģimenei kinoteātrī "Auseklis".</p>
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

        $scraper = new TalsiScraper();
        $scraper->setClient($client);

        $events = $scraper->scrape($source);

        // Exactly 2 valid events extracted (committee meeting and past event were skipped)
        $this->assertCount(2, $events);

        // Event 1 verification
        $e1 = $events->first();
        $this->assertEquals('Latvijas Republikas proklamēšanas dienas koncerts Talsos', $e1->title);
        $this->assertEquals('Talsu Tautas nams', $e1->venueName);
        $this->assertEquals('Talsi', $e1->city);
        $this->assertEquals('Kurzeme', $e1->region);
        $this->assertEquals('cultural_center', $e1->placeType);
        $this->assertEquals(['Mūzika'], $e1->categoryNames);
        $this->assertEquals('concert', $e1->entertainmentType);
        $this->assertTrue($e1->isFree);
        $this->assertEquals('https://www.talsunovads.lv/files/gallery_images/talsu_valsts_svetku_koncerts.jpg', $e1->imageUrl);
        $this->assertEquals('https://www.talsunovads.lv/lv/notikums/valsts-svetku-koncerts-talsos', $e1->sourceUrl);
        $this->assertEquals('talsi-valsts-svetku-koncerts-talsos', $e1->sourceExternalId);
        $this->assertStringContainsString('Talsu novada apvienotais koris', $e1->description);

        // Event 2 verification (Strict Kino classification)
        $e2 = $events->get(1);
        $this->assertEquals('Animācijas filmas "Straume" kinoseanss Talsos', $e2->title);
        $this->assertEquals('Kino "Auseklis"', $e2->venueName);
        $this->assertEquals('Talsi', $e2->city);
        $this->assertEquals(['Kino', 'Bērniem'], $e2->categoryNames);
        $this->assertEquals('movie', $e2->entertainmentType);
        $this->assertFalse($e2->isFree);
        $this->assertEquals(4.00, (float) $e2->priceMin);
        $this->assertEquals('talsi-filmas-seanss-straume-talsos', $e2->sourceExternalId);
        $this->assertStringContainsString('Režisors Gints Zilbalodis', $e2->description);
    }

    public function test_fetch_detail_description_extracts_full_text(): void
    {
        $detailHtml = <<<HTML
        <article class="node notikums">
            <div class="field--name-body">
                <p>Talsu novada muzeja izstāžu zālē apskatāma retrospektīva mākslas izstāde.</p>
                <p>Izstādē eksponēti vairāk nekā piecdesmit autoru darbi, kas tapuši pēdējā desmitgadē.</p>
                <p>Ieejas maksa: 2.50 EUR pieaugušajiem, 1.50 EUR skolēniem un pensionāriem.</p>
            </div>
        </article>
HTML;

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $detailHtml),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $scraper = new TalsiScraper();
        $scraper->setClient($client);

        $desc = $scraper->fetchDetailDescription('https://www.talsunovads.lv/lv/notikums/makslas-izstade-talsos');
        $this->assertNotNull($desc);
        $this->assertStringContainsString('Talsu novada muzeja izstāžu zālē', $desc);
        $this->assertStringContainsString('retrospektīva mākslas izstāde', $desc);
        $this->assertStringContainsString('Ieejas maksa: 2.50 EUR', $desc);
    }
}
