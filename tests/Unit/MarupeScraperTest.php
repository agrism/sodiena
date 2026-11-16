<?php

namespace Tests\Unit;

use App\Models\Source;
use App\Services\Scrapers\Sources\MarupeScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarupeScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata(): void
    {
        $scraper = new MarupeScraper();
        $this->assertEquals('marupe', $scraper->getSlug());
        $this->assertEquals('Mārupes novada pašvaldība', $scraper->getName());
        $this->assertEquals('https://www.marupe.lv', MarupeScraper::BASE_URL);
        $this->assertEquals('/lv/notikumu-kalendars', MarupeScraper::CALENDAR_PATH);
    }

    public function test_skips_administrative_and_council_meetings(): void
    {
        $scraper = new MarupeScraper();

        // 1. Categories with council/committee meetings must be skipped
        $this->assertTrue($scraper->isExcludedEvent(['Komitejas sēde'], 'Sēdes darba kārtība'));
        $this->assertTrue($scraper->isExcludedEvent(['Finanšu komitejas sēde'], 'Kārtējā sēde'));
        $this->assertTrue($scraper->isExcludedEvent(['Domes sēde'], 'Mārupes novada domes sēde'));
        $this->assertTrue($scraper->isExcludedEvent(['Pašvaldība', 'ārkārtas sēdes'], 'Ārkārtas sēdes norise'));

        // 2. Titles with council/committee meetings must be skipped
        $this->assertTrue($scraper->isExcludedEvent(['Pašvaldība'], 'Izglītības, kultūras un sporta komitejas sēde'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Deputātu tikšanās ar iedzīvotājiem Piņķos'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Domes priekšsēdētājs pieņem iedzīvotājus'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Deputāta pieņemšana Jaunmārupē'));

        // 3. Cultural and entertainment events must NOT be skipped
        $this->assertFalse($scraper->isExcludedEvent(['Koncerts'], 'Valsts svētku koncerts Mārupē'));
        $this->assertFalse($scraper->isExcludedEvent(['Teātris'], 'Izrāde Babītes KIC'));
        $this->assertFalse($scraper->isExcludedEvent(['Kino'], 'Kinoseanss Vietvalžos'));
        $this->assertFalse($scraper->isExcludedEvent(['Izstādes'], 'Mākslas izstāde Švarcenieku muižā'));
    }

    public function test_date_parsing(): void
    {
        $scraper = new MarupeScraper();

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
        $scraper = new MarupeScraper();

        // 1. Known venue: Mārupes Kultūras nams
        $loc1 = $scraper->resolveLocation('Mārupes Kultūras nams', 'Valsts svētku sarīkojums');
        $this->assertEquals('Mārupes Kultūras nams', $loc1['name']);
        $this->assertEquals('Mārupe', $loc1['city']);
        $this->assertEquals('Rīga un Pierīga', $loc1['region']);
        $this->assertEquals('cultural_center', $loc1['place_type']);
        $this->assertStringContainsString('Daugavas iela 29', $loc1['address']);

        // 2. Known venue: Babītes Kultūrizglītības centrs (Piņķi)
        $loc2 = $scraper->resolveLocation('Piņķi, Jūrmalas iela 14A', 'Koncerts Babītes Kultūrizglītības centrā');
        $this->assertEquals('Babītes Kultūrizglītības centrs', $loc2['name']);
        $this->assertEquals('Piņķi', $loc2['city']);
        $this->assertEquals('cultural_center', $loc2['place_type']);

        // 3. Known venue: Vietvalži (Spuņciems)
        $loc3 = $scraper->resolveLocation('Vietvalži', 'Mākslas nodarbība filiālē "Vietvalži"');
        $this->assertEquals('Babītes KIC filiāle "Vietvalži"', $loc3['name']);
        $this->assertEquals('Spuņciems', $loc3['city']);
        $this->assertEquals('cultural_center', $loc3['place_type']);

        // 4. Known venue: Jaunmārupes dabas parks
        $loc4 = $scraper->resolveLocation('Jaunmārupe', 'Līgo svētki pie Ūdensrožu dīķa Jaunmārupes dabas parkā');
        $this->assertEquals('Jaunmārupes Dabas parks', $loc4['name']);
        $this->assertEquals('Jaunmārupe', $loc4['city']);
        $this->assertEquals('park', $loc4['place_type']);

        // 5. Known venue: Mārupes BMX trase (Tīraine)
        $loc5 = $scraper->resolveLocation('Tīraine', 'Mārupes BMX trasē notiks kausa izcīņa');
        $this->assertEquals('Mārupes BMX trase', $loc5['name']);
        $this->assertEquals('Tīraine', $loc5['city']);
        $this->assertEquals('sports_facility', $loc5['place_type']);

        // 6. Default fallback to Mārupe (administrative center)
        $loc6 = $scraper->resolveLocation('Mārupes novads', 'Novada svētku pasākums');
        $this->assertEquals('Mārupe', $loc6['city']);
        $this->assertEquals('Rīga un Pierīga', $loc6['region']);
    }

    public function test_category_resolving_strictly_follows_agents_md(): void
    {
        $scraper = new MarupeScraper();

        // 1. Cinema / Kino (STRICT RULE): Always Kino, never Teātris
        $cats1 = $scraper->resolveCategories(['Kino'], 'Filmas seanss "Zeme, kas dzied" Mārupē');
        $this->assertEquals(['Kino'], $cats1);

        $cats2 = $scraper->resolveCategories(['Kultūra'], 'Dokumentālā filma par Mārupes vēsturi kinoteātrī');
        $this->assertEquals(['Kino'], $cats2);

        $cats3 = $scraper->resolveCategories(['Kino'], 'Animācijas filma bērniem Piņķos');
        $this->assertEquals(['Kino', 'Bērniem'], $cats3);

        // 2. Theatres (STRICT RULE): Real theatre productions
        $cats4 = $scraper->resolveCategories(['Kultūra'], 'Teātra izrāde "Silmači" Mārupes kultūras namā');
        $this->assertEquals(['Teātris'], $cats4);

        // 3. Music
        $cats5 = $scraper->resolveCategories(['Koncerts'], 'Jaukto koru svētku koncerts Babītes KIC');
        $this->assertEquals(['Mūzika'], $cats5);

        // 4. Exhibitions
        $cats6 = $scraper->resolveCategories([], 'Mākslas izstāde Švarcenieku muižā');
        $this->assertEquals(['Izstādes'], $cats6);

        // 5. Sports
        $cats7 = $scraper->resolveCategories([], 'BMX čempionāts Mārupes trasē');
        $this->assertEquals(['Sports'], $cats7);

        // 6. Fallback: Cits
        $cats8 = $scraper->resolveCategories([], 'Kopienas tikšanās un diskusija par vidi');
        $this->assertEquals(['Cits'], $cats8);
    }

    public function test_price_parsing(): void
    {
        $scraper = new MarupeScraper();

        $p1 = $scraper->parsePrice('Ieeja pasākumā bez maksas. Visi mīļi aicināti!');
        $this->assertTrue($p1['is_free']);
        $this->assertEquals(0.0, $p1['price_min']);

        $p2 = $scraper->parsePrice('Biļetes cena: 6.00 EUR pirms pasākuma.');
        $this->assertFalse($p2['is_free']);
        $this->assertEquals(6.0, $p2['price_min']);
        $this->assertEquals(6.0, $p2['price_max']);
    }

    public function test_scrape_extracts_events_directly_from_calendar_without_subpage_crawling(): void
    {
        $source = Source::create([
            'name' => 'Mārupes novada pašvaldība',
            'slug' => 'marupe',
            'url' => 'https://www.marupe.lv/lv/notikumu-kalendars',
            'scraper_class' => MarupeScraper::class,
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
                    <span class="describing-field-value">Mārupes Kultūras nams</span>
                </div>
                <div class="event-image">
                    <img src="/files/gallery_images/marupes_koncerts.jpg" />
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/valsts-svetku-koncerts-marupe">Latvijas Republikas proklamēšanas dienas koncerts Mārupē</a>
                </h2>
                <div class="event-categories">
                    <a class="badge">Koncerts</a>
                    <a class="badge">Kultūra</a>
                </div>
                <div class="event-content">
                    <p>Mārupes Kultūras namā notiks svinīgs koncerts ar novada koriem. Ieeja brīva.</p>
                </div>
            </div>

            <!-- Event 2: Valid cinema screening -->
            <div class="event">
                <div class="event-info">
                    <span class="date-date">{$formattedDate}</span>
                    <span class="date-time">19.30</span>
                    <span class="describing-field-value">Babītes Kultūrizglītības centrs</span>
                </div>
                <div class="event-image">
                    <img src="/files/gallery_images/straume_babite.jpg" />
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/filmas-seanss-straume-pinkos">Animācijas filmas "Straume" kinoseanss Piņķos</a>
                </h2>
                <div class="event-categories">
                    <a class="badge">Kino</a>
                </div>
                <div class="event-content">
                    <p>Kinoseanss visai ģimenei. Biļetes cena: 4.50 EUR.</p>
                </div>
            </div>

            <!-- Event 3: Administrative Committee Meeting (MUST BE SKIPPED) -->
            <div class="event">
                <div class="event-info">
                    <span class="date-date">{$formattedDate}</span>
                    <span class="date-time">10.00</span>
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/finansu-komitejas-sede-marupe">Mārupes novada domes Finanšu komitejas sēde</a>
                </h2>
                <div class="event-categories">
                    <a class="badge">Komitejas sēde</a>
                </div>
                <div class="event-content">
                    <p>Darba kārtības jautājumu izskatīšana.</p>
                </div>
            </div>

            <!-- Event 4: Past event (MUST BE SKIPPED according to AGENTS.md) -->
            <div class="event">
                <div class="event-info">
                    <span class="date-date">10. janvāris, 2024</span>
                    <span class="date-time">14.00</span>
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/pagatnes-notikums-marupe-2024">Vēsturisks pagātnes pasākums Mārupē</a>
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
                <p>Mārupes Kultūras namā notiks svinīgs koncerts par godu Latvijas Republikas proklamēšanas dienai. Ieeja brīva.</p>
                <p>Koncertā uzstāsies Mārupes novada jauniešu koris un solisti.</p>
            </div>
        </article>
HTML;

        $detailHtml2 = <<<HTML
        <article class="node notikums">
            <div class="field--name-body">
                <p>Animācijas filmas "Straume" kinoseanss Piņķos. Biļetes cena: 4.50 EUR.</p>
                <p>Režisors Gints Zilbalodis. Seanss ģimenēm ar bērniem.</p>
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

        $scraper = new MarupeScraper();
        $scraper->setClient($client);

        $events = $scraper->scrape($source);

        // Exactly 2 valid events extracted (committee meeting and past event were skipped)
        $this->assertCount(2, $events);

        // Event 1 verification
        $e1 = $events->first();
        $this->assertEquals('Latvijas Republikas proklamēšanas dienas koncerts Mārupē', $e1->title);
        $this->assertEquals('Mārupes Kultūras nams', $e1->venueName);
        $this->assertEquals('Mārupe', $e1->city);
        $this->assertEquals('Rīga un Pierīga', $e1->region);
        $this->assertEquals('cultural_center', $e1->placeType);
        $this->assertEquals(['Mūzika'], $e1->categoryNames);
        $this->assertEquals('concert', $e1->entertainmentType);
        $this->assertTrue($e1->isFree);
        $this->assertEquals('https://www.marupe.lv/files/gallery_images/marupes_koncerts.jpg', $e1->imageUrl);
        $this->assertEquals('https://www.marupe.lv/lv/notikums/valsts-svetku-koncerts-marupe', $e1->sourceUrl);
        $this->assertEquals('marupe-valsts-svetku-koncerts-marupe', $e1->sourceExternalId);
        $this->assertStringContainsString('Koncertā uzstāsies Mārupes novada jauniešu koris', $e1->description);

        // Event 2 verification (Strict Kino classification)
        $e2 = $events->get(1);
        $this->assertEquals('Animācijas filmas "Straume" kinoseanss Piņķos', $e2->title);
        $this->assertEquals('Babītes Kultūrizglītības centrs', $e2->venueName);
        $this->assertEquals('Piņķi', $e2->city);
        $this->assertEquals(['Kino', 'Bērniem'], $e2->categoryNames);
        $this->assertEquals('movie', $e2->entertainmentType);
        $this->assertFalse($e2->isFree);
        $this->assertEquals(4.50, (float) $e2->priceMin);
        $this->assertEquals('marupe-filmas-seanss-straume-pinkos', $e2->sourceExternalId);
        $this->assertStringContainsString('Režisors Gints Zilbalodis', $e2->description);
    }

    public function test_fetch_detail_description_extracts_full_text(): void
    {
        $detailHtml = <<<HTML
        <article class="node notikums">
            <div class="field--name-body">
                <p>Valsts svētku nedēļā sarkanbaltsarkanā lentīte pie apģērba ir neatņemama modes sastāvdaļa.</p>
                <p>Nāc un izgatavo savu unikālo svētku piespraudi meistarklasē kopā ar tekstilmākslinieci.</p>
                <p>Visi materiāli tiks nodrošināti. Dalība pasākumā bez maksas.</p>
            </div>
        </article>
HTML;

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $detailHtml),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $scraper = new MarupeScraper();
        $scraper->setClient($client);

        $desc = $scraper->fetchDetailDescription('https://www.marupe.lv/lv/notikums/svetku-aksesuaru-darbnica');
        $this->assertNotNull($desc);
        $this->assertStringContainsString('sarkanbaltsarkanā lentīte', $desc);
        $this->assertStringContainsString('meistarklasē kopā ar tekstilmākslinieci', $desc);
        $this->assertStringContainsString('Dalība pasākumā bez maksas.', $desc);
    }
}
