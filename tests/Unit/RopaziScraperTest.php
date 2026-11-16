<?php

namespace Tests\Unit;

use App\Models\Source;
use App\Services\Scrapers\Sources\RopaziScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RopaziScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata(): void
    {
        $scraper = new RopaziScraper();
        $this->assertEquals('ropazi', $scraper->getSlug());
        $this->assertEquals('Ropažu novada pašvaldība', $scraper->getName());
        $this->assertEquals('https://www.ropazi.lv', RopaziScraper::BASE_URL);
        $this->assertEquals('/lv/notikumu-kalendars', RopaziScraper::CALENDAR_PATH);
    }

    public function test_skips_administrative_and_council_meetings(): void
    {
        $scraper = new RopaziScraper();

        // 1. Categories with council/committee meetings must be skipped
        $this->assertTrue($scraper->isExcludedEvent(['Komitejas sēde'], 'Sēdes darba kārtība'));
        $this->assertTrue($scraper->isExcludedEvent(['Finanšu komitejas sēde'], 'Kārtējā finanšu sēde'));
        $this->assertTrue($scraper->isExcludedEvent(['Domes sēde'], 'Ropažu novada domes sēde'));
        $this->assertTrue($scraper->isExcludedEvent(['Pašvaldība', 'ārkārtas sēdes'], 'Ārkārtas sēdes norise'));

        // 2. Titles with council/committee meetings must be skipped
        $this->assertTrue($scraper->isExcludedEvent(['Pašvaldība'], 'Izglītības, kultūras un sporta komitejas sēde'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Deputātu tikšanās ar iedzīvotājiem Vangažos'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Domes vadība pieņem iedzīvotājus Ulbrokā'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Deputāta pieņemšana Zaķumuižā'));

        // 3. Cultural and entertainment events must NOT be skipped
        $this->assertFalse($scraper->isExcludedEvent(['Koncerts'], 'Valsts svētku koncerts Ulbrokas Pērlē'));
        $this->assertFalse($scraper->isExcludedEvent(['Teātris'], 'Teātra izrāde Ropažos'));
        $this->assertFalse($scraper->isExcludedEvent(['Kino'], 'Kinoseanss Vangažos'));
        $this->assertFalse($scraper->isExcludedEvent(['Izstādes'], 'Mākslas izstāde Berģu kultūras namā'));
    }

    public function test_date_parsing(): void
    {
        $scraper = new RopaziScraper();

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
        $scraper = new RopaziScraper();

        // 1. Known venue: Kultūras centrs "Ulbrokas Pērle"
        $loc1 = $scraper->resolveLocation('Kultūras centrs Ulbrokas Pērle', 'Valsts svētku sarīkojums');
        $this->assertEquals('Kultūras centrs "Ulbrokas Pērle"', $loc1['name']);
        $this->assertEquals('Ulbroka', $loc1['city']);
        $this->assertEquals('Rīga un Pierīga', $loc1['region']);
        $this->assertEquals('cultural_center', $loc1['place_type']);
        $this->assertStringContainsString('Institūta iela 3', $loc1['address']);

        // 2. Known venue: Ropažu Kultūras centrs
        $loc2 = $scraper->resolveLocation('Ropaži, Sporta iela 2', 'Koncerts Ropažu Kultūras centrā');
        $this->assertEquals('Ropažu Kultūras centrs', $loc2['name']);
        $this->assertEquals('Ropaži', $loc2['city']);
        $this->assertEquals('cultural_center', $loc2['place_type']);

        // 3. Known venue: Vangažu Kultūras nams
        $loc3 = $scraper->resolveLocation('Gaujas iela 12, Vangaži', 'Teātra izrāde Vangažu Kultūras namā');
        $this->assertEquals('Vangažu Kultūras nams', $loc3['name']);
        $this->assertEquals('Vangaži', $loc3['city']);
        $this->assertEquals('cultural_center', $loc3['place_type']);

        // 4. Known venue: Berģu Kultūras nams
        $loc4 = $scraper->resolveLocation('Berģi', 'Kamermūzikas vakars Berģu kultūras namā');
        $this->assertEquals('Berģu Kultūras nams', $loc4['name']);
        $this->assertEquals('Berģi', $loc4['city']);
        $this->assertEquals('cultural_center', $loc4['place_type']);

        // 5. Known venue: Zaķumuižas Kultūras nams
        $loc5 = $scraper->resolveLocation('Zaķumuiža', 'Balles vakars Zaķumuižas kultūras namā');
        $this->assertEquals('Zaķumuižas Kultūras nams', $loc5['name']);
        $this->assertEquals('Zaķumuiža', $loc5['city']);
        $this->assertEquals('cultural_center', $loc5['place_type']);

        // 6. Default fallback to Ulbroka (administrative center)
        $loc6 = $scraper->resolveLocation('Ropažu novads', 'Novada sporta svētki');
        $this->assertEquals('Ulbroka', $loc6['city']);
        $this->assertEquals('Rīga un Pierīga', $loc6['region']);
    }

    public function test_category_resolving_strictly_follows_agents_md(): void
    {
        $scraper = new RopaziScraper();

        // 1. Cinema / Kino (STRICT RULE): Always Kino, never Teātris
        $cats1 = $scraper->resolveCategories(['Kino'], 'Filmas seanss "Zeme, kas dzied" Ulbrokā');
        $this->assertEquals(['Kino'], $cats1);

        $cats2 = $scraper->resolveCategories(['Kultūra'], 'Dokumentālā filma par Ropažu vēsturi kinoteātrī');
        $this->assertEquals(['Kino'], $cats2);

        $cats3 = $scraper->resolveCategories(['Kino'], 'Animācijas filma bērniem Vangažos');
        $this->assertEquals(['Kino', 'Bērniem'], $cats3);

        // 2. Theatres (STRICT RULE): Real theatre productions
        $cats4 = $scraper->resolveCategories(['Kultūra'], 'Teātra izrāde "Silmači" Ulbrokas Pērlē');
        $this->assertEquals(['Teātris'], $cats4);

        // 3. Music
        $cats5 = $scraper->resolveCategories(['Koncerts'], 'Jaukto koru svētku koncerts Ropažu estrādē');
        $this->assertEquals(['Mūzika'], $cats5);

        // 4. Exhibitions
        $cats6 = $scraper->resolveCategories([], 'Mākslas izstāde Garkalnes centrā');
        $this->assertEquals(['Izstādes'], $cats6);

        // 5. Sports
        $cats7 = $scraper->resolveCategories([], 'Futbola turnīrs Upesciema stadionā');
        $this->assertEquals(['Sports'], $cats7);

        // 6. Fallback: Cits
        $cats8 = $scraper->resolveCategories([], 'Kopienas tikšanās un diskusija par Ropažu attīstību');
        $this->assertEquals(['Cits'], $cats8);
    }

    public function test_price_parsing(): void
    {
        $scraper = new RopaziScraper();

        $p1 = $scraper->parsePrice('Ieeja pasākumā bez maksas. Visi mīļi gaidīti!');
        $this->assertTrue($p1['is_free']);
        $this->assertEquals(0.0, $p1['price_min']);

        $p2 = $scraper->parsePrice('Biļetes cena: 7.00 EUR "Biļešu paradīzes" kasēs.');
        $this->assertFalse($p2['is_free']);
        $this->assertEquals(7.0, $p2['price_min']);
        $this->assertEquals(7.0, $p2['price_max']);
    }

    public function test_scrape_extracts_events_directly_from_calendar_without_subpage_crawling(): void
    {
        $source = Source::create([
            'name' => 'Ropažu novada pašvaldība',
            'slug' => 'ropazi',
            'url' => 'https://www.ropazi.lv/lv/notikumu-kalendars',
            'scraper_class' => RopaziScraper::class,
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
                    <span class="describing-field-value">Kultūras centrs Ulbrokas Pērle</span>
                </div>
                <div class="event-image">
                    <img src="/files/gallery_images/valsts_svetku_koncerts_ulbroka.jpg" />
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/valsts-svetku-koncerts-ulbrokas-perle">Latvijas Republikas proklamēšanas dienas koncerts "Ulbrokas Pērlē"</a>
                </h2>
                <div class="event-categories">
                    <a class="badge">Koncerts</a>
                    <a class="badge">Kultūra</a>
                </div>
                <div class="event-content">
                    <p>Svinīgs koncerts "Ulbrokas Pērlē" ar koriem un solistiem. Ieeja brīva.</p>
                </div>
            </div>

            <!-- Event 2: Valid cinema screening -->
            <div class="event">
                <div class="event-info">
                    <span class="date-date">{$formattedDate}</span>
                    <span class="date-time">18.30</span>
                    <span class="describing-field-value">Ropažu Kultūras centrs</span>
                </div>
                <div class="event-image">
                    <img src="/files/gallery_images/straume_ropazi.jpg" />
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/filmas-seanss-straume-ropazos">Animācijas filmas "Straume" kinoseanss Ropažos</a>
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
                    <a href="/lv/notikums/finansu-komitejas-sede-ropazi">Finanšu un ekonomikas attīstības komitejas sēde</a>
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
                    <a href="/lv/notikums/pagatnes-notikums-ropazi-2024">Vēsturisks pagātnes pasākums Ropažos</a>
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
                <p>Kultūras centrā "Ulbrokas Pērle" izskanēs svinīgs koncerts par godu Latvijas Republikas proklamēšanas dienai. Ieeja brīva.</p>
                <p>Piedalās novada kori un viesmākslinieki.</p>
            </div>
        </article>
HTML;

        $detailHtml2 = <<<HTML
        <article class="node notikums">
            <div class="field--name-body">
                <p>Kinoseanss visai ģimenei Ropažos. Filma "Straume". Biļetes cena: 4.00 EUR.</p>
                <p>Režisors Gints Zilbalodis.</p>
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

        $scraper = new RopaziScraper();
        $scraper->setClient($client);

        $events = $scraper->scrape($source);

        // Exactly 2 valid events extracted (committee meeting and past event were skipped)
        $this->assertCount(2, $events);

        // Event 1 verification
        $e1 = $events->first();
        $this->assertEquals('Latvijas Republikas proklamēšanas dienas koncerts "Ulbrokas Pērlē"', $e1->title);
        $this->assertEquals('Kultūras centrs "Ulbrokas Pērle"', $e1->venueName);
        $this->assertEquals('Ulbroka', $e1->city);
        $this->assertEquals('Rīga un Pierīga', $e1->region);
        $this->assertEquals('cultural_center', $e1->placeType);
        $this->assertEquals(['Mūzika'], $e1->categoryNames);
        $this->assertEquals('concert', $e1->entertainmentType);
        $this->assertTrue($e1->isFree);
        $this->assertEquals('https://www.ropazi.lv/files/gallery_images/valsts_svetku_koncerts_ulbroka.jpg', $e1->imageUrl);
        $this->assertEquals('https://www.ropazi.lv/lv/notikums/valsts-svetku-koncerts-ulbrokas-perle', $e1->sourceUrl);
        $this->assertEquals('ropazi-valsts-svetku-koncerts-ulbrokas-perle', $e1->sourceExternalId);
        $this->assertStringContainsString('Piedalās novada kori', $e1->description);

        // Event 2 verification (Strict Kino classification)
        $e2 = $events->get(1);
        $this->assertEquals('Animācijas filmas "Straume" kinoseanss Ropažos', $e2->title);
        $this->assertEquals('Ropažu Kultūras centrs', $e2->venueName);
        $this->assertEquals('Ropaži', $e2->city);
        $this->assertEquals(['Kino', 'Bērniem'], $e2->categoryNames);
        $this->assertEquals('movie', $e2->entertainmentType);
        $this->assertFalse($e2->isFree);
        $this->assertEquals(4.00, (float) $e2->priceMin);
        $this->assertEquals('ropazi-filmas-seanss-straume-ropazos', $e2->sourceExternalId);
        $this->assertStringContainsString('Režisors Gints Zilbalodis', $e2->description);
    }

    public function test_fetch_detail_description_extracts_full_text(): void
    {
        $detailHtml = <<<HTML
        <article class="node notikums">
            <div class="field--name-body">
                <p>"Staro Ropaži 2026" norisināsies 18. novembrī no plkst. 19.00 līdz 22.00.</p>
                <p>Apmeklētājiem būs iespēja apskatīt dažādus izgaismotus vides objektus, baudīt gaismas instalācijas un muzikālus priekšnesumus.</p>
                <p>Ieeja bez maksas.</p>
            </div>
        </article>
HTML;

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $detailHtml),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $scraper = new RopaziScraper();
        $scraper->setClient($client);

        $desc = $scraper->fetchDetailDescription('https://www.ropazi.lv/lv/notikums/staro-ropazi-0');
        $this->assertNotNull($desc);
        $this->assertStringContainsString('Staro Ropaži 2026', $desc);
        $this->assertStringContainsString('gaismas instalācijas', $desc);
        $this->assertStringContainsString('Ieeja bez maksas.', $desc);
    }
}
