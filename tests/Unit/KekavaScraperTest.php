<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\KekavaScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KekavaScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata(): void
    {
        $scraper = new KekavaScraper();
        $this->assertEquals('kekava', $scraper->getSlug());
        $this->assertEquals('Ķekavas novads', $scraper->getName());
        $this->assertEquals('https://kekava.lv', KekavaScraper::BASE_URL);
        $this->assertEquals('/notikumi/', KekavaScraper::CALENDAR_PATH);
    }

    public function test_location_resolving_known_venues_and_novads(): void
    {
        $scraper = new KekavaScraper();

        // 1. Ķekavas kultūras nams
        $loc1 = $scraper->resolveLocation('Ķekavas kultūras nams', 'Ziemassvētku lielkoncerts');
        $this->assertEquals('Ķekavas kultūras nams', $loc1['name']);
        $this->assertEquals('Ķekava', $loc1['city']);
        $this->assertEquals('Rīga un Pierīga', $loc1['region']);
        $this->assertEquals('Gaismas iela 17, Ķekava, LV-2123', $loc1['address']);
        $this->assertEquals('cultural_center', $loc1['place_type']);
        $this->assertEquals(56.8285, $loc1['lat']);

        // 2. Ķekavas novadpētniecības muzejs
        $loc2 = $scraper->resolveLocation('Ķekavas novadpētniecības muzejs', 'Lai svinam!');
        $this->assertEquals('Ķekavas novadpētniecības muzejs', $loc2['name']);
        $this->assertEquals('Ķekava', $loc2['city']);
        $this->assertEquals('museum', $loc2['place_type']);

        // 3. Ķekavas sporta klubs
        $loc3 = $scraper->resolveLocation('Ķekavas sporta klubs', '5 km skrējiens');
        $this->assertEquals('Ķekavas sporta klubs', $loc3['name']);
        $this->assertEquals('Ķekava', $loc3['city']);
        $this->assertEquals('sports_facility', $loc3['place_type']);

        // 4. Baložu pilsētas kultūras nams
        $loc4 = $scraper->resolveLocation('Baložu pilsētas kultūras nams', 'GADU MIJAS BALLE');
        $this->assertEquals('Baložu pilsētas kultūras nams', $loc4['name']);
        $this->assertEquals('Baloži', $loc4['city']);
        $this->assertEquals('Skolas iela 4, Baloži, LV-2128', $loc4['address']);
        $this->assertEquals('cultural_center', $loc4['place_type']);

        // 5. Baložu pilsētas bibliotēka
        $loc5 = $scraper->resolveLocation('Baložu pilsētas bibliotēka', 'Grāmatu pietura');
        $this->assertEquals('Baložu pilsētas bibliotēka', $loc5['name']);
        $this->assertEquals('Baloži', $loc5['city']);
        $this->assertEquals('library', $loc5['place_type']);

        // 6. Baldones muzejs
        $loc6 = $scraper->resolveLocation('Baldones muzejs', 'Porcelāna izstāde');
        $this->assertEquals('Baldones muzejs', $loc6['name']);
        $this->assertEquals('Baldone', $loc6['city']);
        $this->assertEquals('museum', $loc6['place_type']);

        // 7. Baldones vidusskolas stadions
        $loc7 = $scraper->resolveLocation('Baldones vidusskolas stadions', 'Svētku skrējiens');
        $this->assertEquals('Baldones vidusskolas stadions', $loc7['name']);
        $this->assertEquals('Baldone', $loc7['city']);
        $this->assertEquals('sports_facility', $loc7['place_type']);

        // 8. Katlakalna Tautas nams
        $loc8 = $scraper->resolveLocation('Katlakalna Tautas nams', 'Kino vakars');
        $this->assertEquals('Katlakalna Tautas nams', $loc8['name']);
        $this->assertEquals('Katlakalns', $loc8['city']);
        $this->assertEquals('cultural_center', $loc8['place_type']);

        // 9. Doles Tautas nams
        $loc9 = $scraper->resolveLocation('Doles Tautas nams', 'DANČU VAKARS');
        $this->assertEquals('Doles Tautas nams', $loc9['name']);
        $this->assertEquals('Ķekava', $loc9['city']);

        // 10. Valdlauči
        $loc10 = $scraper->resolveLocation('Valdlauči', 'Ziemassvētku pasākums');
        $this->assertEquals('Valdlauči', $loc10['name']);
        $this->assertEquals('Valdlauči', $loc10['city']);

        // 11. Generic town fallback
        $loc11 = $scraper->resolveLocation('Ķekava', 'Valsts svētku koncerts');
        $this->assertEquals('Ķekava', $loc11['name']);
        $this->assertEquals('Ķekava', $loc11['city']);
        $this->assertEquals('Rīga un Pierīga', $loc11['region']);

        // 12. Empty fallback
        $loc12 = $scraper->resolveLocation('', 'Novada svētki');
        $this->assertEquals('Ķekava', $loc12['city']);
        $this->assertEquals('Rīga un Pierīga', $loc12['region']);
    }

    public function test_date_parsing_various_formats(): void
    {
        $scraper = new KekavaScraper();

        // 1. Multi-day range with times: 19.09.2026 10:00 – 03.12.2026 17:00
        [$start1, $end1] = $scraper->parseDates('19.09.2026 10:00 &nbsp;&ndash;&nbsp; 03.12.2026 17:00');
        $this->assertNotNull($start1);
        $this->assertNotNull($end1);
        $this->assertEquals('2026-09-19 10:00:00', $start1->toDateTimeString());
        $this->assertEquals('2026-12-03 17:00:00', $end1->toDateTimeString());

        // 2. Multi-day all-day range: 01.10.2026 00:00 – 30.12.2026 00:00
        [$start2, $end2] = $scraper->parseDates('01.10.2026 00:00 &nbsp;&ndash;&nbsp; 30.12.2026 00:00');
        $this->assertNotNull($start2);
        $this->assertNotNull($end2);
        $this->assertEquals('2026-10-01 00:00:00', $start2->toDateTimeString());
        $this->assertEquals('2026-12-30 23:59:59', $end2->toDateTimeString());

        // 3. Single date with time range: 18.11.2026 10:00 - 11:30
        [$start3, $end3] = $scraper->parseDates('18.11.2026 10:00 - 11:30');
        $this->assertNotNull($start3);
        $this->assertNotNull($end3);
        $this->assertEquals('2026-11-18 10:00:00', $start3->toDateTimeString());
        $this->assertEquals('2026-11-18 11:30:00', $end3->toDateTimeString());

        // 4. Cross-year range: 31.12.2026 22:00 – 01.01.2027 03:00
        [$start4, $end4] = $scraper->parseDates('31.12.2026 22:00 &nbsp;&ndash;&nbsp; 01.01.2027 03:00');
        $this->assertNotNull($start4);
        $this->assertNotNull($end4);
        $this->assertEquals('2026-12-31 22:00:00', $start4->toDateTimeString());
        $this->assertEquals('2027-01-01 03:00:00', $end4->toDateTimeString());

        // 5. Single date with single time: 18.11.2026 18:00
        [$start5, $end5] = $scraper->parseDates('18.11.2026 18:00');
        $this->assertNotNull($start5);
        $this->assertNull($end5);
        $this->assertEquals('2026-11-18 18:00:00', $start5->toDateTimeString());

        // 6. Fallback from datetime tag: null raw, fallback 2026-11-20
        [$start6, $end6] = $scraper->parseDates('', '2026-11-20');
        $this->assertNotNull($start6);
        $this->assertEquals('2026-11-20 00:00:00', $start6->toDateTimeString());
    }

    public function test_strict_categorization_and_kino_rules_per_agents_md(): void
    {
        $scraper = new KekavaScraper();

        // RULE 1: Cinema / Kino Strict Priority:
        // Cinema venues or keywords must ALWAYS be Kino and NEVER Teātris!
        $this->assertEquals(['Kino'], $scraper->inferCategory('MISIJA: ATRAST VECĀKUS | ģimenes filma', 'FILMA', 'Baložu pilsētas kultūras nams'));
        $this->assertEquals(['Kino'], $scraper->inferCategory('TUMŠĀ ROBEŽA | vēsturiska spēlfilma', 'FILMA', 'Baložu pilsētas kultūras nams'));
        $this->assertEquals(['Kino'], $scraper->inferCategory('Kino vakars- Klajumā aiz meža', 'FILMA', 'Katlakalna Tautas nams'));
        $this->assertEquals(['Kino'], $scraper->inferCategory('Kinoseanss "Zeme"', null, 'Ķekavas kultūras nams'));
        $this->assertEquals(['Kino'], $scraper->inferCategory('Dokumentālā filma par piekrasti', null, 'Ķekavas kultūras nams'));
        $this->assertEquals(['Kino'], $scraper->inferCategory('Teātra un mākslas filmas vakars', null, 'Kinoteātris Bize'));
        $this->assertEquals(['Kino'], $scraper->inferCategory('Kino izrāde bērniem', null, 'Kino Rio'));

        // RULE 2: Theaters - only actual productions or theater venues
        $this->assertEquals(['Teātris'], $scraper->inferCategory('Leļļu teātra izrāde ZIEMASSVĒTKI VISIEM', 'IZRĀDE', 'Katlakalna Tautas nams'));
        $this->assertEquals(['Teātris'], $scraper->inferCategory('ČUČUMUIŽAS ZIEMASSVĒTKU PASAKA | muzikāla izrāde', 'IZRĀDE', 'Baložu pilsētas kultūras nams'));
        $this->assertEquals(['Teātris'], $scraper->inferCategory('Amatierteātra izrāde "Skroderdienas"', null, 'Ķekavas kultūras nams'));

        // RULE 3: Music & Concerts
        $this->assertEquals(['Mūzika'], $scraper->inferCategory('ES SAVU DZIESMU VELTU TEV', 'KONCERTS', 'Katlakalna Tautas nams'));
        $this->assertEquals(['Mūzika'], $scraper->inferCategory('Valsts svētku koncerts TEV, LATVIJA!', 'KONCERTS', 'Ķekava'));
        $this->assertEquals(['Mūzika'], $scraper->inferCategory('DANČU VAKARS', 'Danči', 'Doles Tautas nams'));
        $this->assertEquals(['Mūzika'], $scraper->inferCategory('BALLE "DEJO AR MAN IANDREJOS!"', 'Balle', 'Doles Tautas nams'));
        $this->assertEquals(['Mūzika'], $scraper->inferCategory('Ziemassvētku lielkoncerts', 'KONCERTS', 'Ķekavas kultūras nams'));
        $this->assertEquals(['Mūzika'], $scraper->inferCategory('GADU MIJAS BALLE | ar grupu ROJA', 'Balle', 'Baložu pilsētas kultūras nams'));

        // RULE 4: Sports
        $this->assertEquals(['Sports'], $scraper->inferCategory('5 km skrējiens "Vienoti Latvijai"', 'SPORTS', 'Ķekavas sporta klubs'));
        $this->assertEquals(['Sports'], $scraper->inferCategory('Latvijas svētku skrējiens 2026', 'SPORTS', 'Baldones vidusskolas stadions'));
        $this->assertEquals(['Sports'], $scraper->inferCategory('Atklātais šaha turnīrs', 'SPORTS', 'Baložu vidusskola'));
        $this->assertEquals(['Sports'], $scraper->inferCategory('Ķekavas novada CORNHOLE čempionāts', 'SPORTS', 'Baldones sporta komplekss'));

        // RULE 5: Exhibitions
        $this->assertEquals(['Izstādes'], $scraper->inferCategory('Mercendarbes muižā atkal ienāk PORCELĀNS!', 'IZSTĀDE', 'Baldones muzejs'));
        $this->assertEquals(['Izstādes'], $scraper->inferCategory('Lai svinam!', 'IZSTĀDE', 'Ķekavas novadpētniecības muzejs'));

        // RULE 6: Kids & Family
        $this->assertEquals(['Bērniem'], $scraper->inferCategory('Grāmatu Tārpiņa nodarbība', 'Tikšanās', 'Ķekavas pagasta bibliotēka'));

        // RULE 7: Education & Workshops
        $this->assertEquals(['Izglītība'], $scraper->inferCategory('Ieraugi krāpnieka slēpto nodomu', 'LEKCIJA', 'Baložu pilsētas bibliotēka'));
        $this->assertEquals(['Izglītība'], $scraper->inferCategory('DĀVANU IZGATAVOŠANAS DARBNĪCAS', 'Darbnīca', 'Baložu pilsētas kultūras nams'));
        $this->assertEquals(['Izglītība'], $scraper->inferCategory('Grāmatu pietura', 'Lasītāju klubs', 'Baložu pilsētas bibliotēka'));

        // RULE 8: Holidays & Culture
        $this->assertEquals(['Kultūra'], $scraper->inferCategory('BALOŽU PILSĒTAS EGLES IEDEGŠANA | ADVENTES TIRGUS', 'Tradīcijas', 'Baložu pilsētas kultūras nama skvērs'));
        $this->assertEquals(['Kultūra'], $scraper->inferCategory('Kad pilsētā iemirdzas Ziemassvētki', 'SVĒTKU PASĀKUMS', 'Valdlauči'));

        // Fallback
        $this->assertEquals(['Cits'], $scraper->inferCategory('Ķekavas iedzīvotāju sapulce', null, 'Ķekava'));
    }

    public function test_price_extraction(): void
    {
        $scraper = new KekavaScraper();

        // 1. Single price: €4,00
        $res1 = $scraper->extractPricesAndFree('€4,00', 'Filmas seanss');
        $this->assertFalse($res1['is_free']);
        $this->assertEquals(4.0, $res1['price_min']);
        $this->assertEquals(4.0, $res1['price_max']);

        // 2. Escaped quote: €'4,00
        $res2 = $scraper->extractPricesAndFree("€&#039;4,00", 'Filmas seanss');
        $this->assertFalse($res2['is_free']);
        $this->assertEquals(4.0, $res2['price_min']);
        $this->assertEquals(4.0, $res2['price_max']);

        // 3. Price range: €5.00-8.00
        $res3 = $scraper->extractPricesAndFree('€5.00-8.00', 'Izrāde');
        $this->assertFalse($res3['is_free']);
        $this->assertEquals(5.0, $res3['price_min']);
        $this->assertEquals(8.0, $res3['price_max']);

        // 4. Multiple prices: €15.00 un 20.00
        $res4 = $scraper->extractPricesAndFree('€15.00 un 20.00', 'Koncerts');
        $this->assertFalse($res4['is_free']);
        $this->assertEquals(15.0, $res4['price_min']);
        $this->assertEquals(20.0, $res4['price_max']);

        // 5. Typo format: €10:00
        $res5 = $scraper->extractPricesAndFree('€10:00', 'Balle');
        $this->assertFalse($res5['is_free']);
        $this->assertEquals(10.0, $res5['price_min']);
        $this->assertEquals(10.0, $res5['price_max']);

        // 6. Free admission
        $res6 = $scraper->extractPricesAndFree('', 'Koncerts svētkos. Ieeja bez maksas.');
        $this->assertTrue($res6['is_free']);
        $this->assertEquals(0.0, $res6['price_min']);
        $this->assertEquals(0.0, $res6['price_max']);
    }

    public function test_mock_http_scraping_flow(): void
    {
        $futureDateStr = Carbon::tomorrow('Europe/Riga')->format('d.m.Y');

        $html = <<<HTML
<!DOCTYPE html>
<html>
<body>
    <div class="gme-events-calendar-list">
        <!-- Event 1: Future concert -->
        <div class="gme-common-g-row gme-events-calendar-list__event-row">
            <div class="gme-events-calendar-list__event-date-tag gme-common-g-col">
                <time class="gme-events-calendar-list__event-date-tag-datetime" datetime="{$futureDateStr}">
                    <span class="gme-events-calendar-list__event-date-tag-daynum">18</span>
                </time>
            </div>
            <div class="gme-events-calendar-list__event-wrapper gme-common-g-col">
                <article class="gme-events-calendar-list__event gme-common-g-row">
                    <div class="gme-events-calendar-list__event-featured-image-wrapper">
                        <a href="https://kekava.lv/notikumi/valsts-svetku-koncerts/">
                            <img src="https://kekava.lv/wp-content/uploads/sites/15/2026/11/koncerts.jpg" class="gme-events-calendar-list__event-featured-image">
                        </a>
                    </div>
                    <div class="gme-events-calendar-list__event-details">
                        <header class="gme-events-calendar-list__event-header">
                            <div class="gme-events-calendar-list__event-datetime-wrapper">
                                <time class="gme-events-calendar-list__event-datetime">
                                    {$futureDateStr} 18:00 - 19:30
                                </time>
                            </div>
                            <h3 class="gme-events-calendar-list__event-title">
                                <a href="https://kekava.lv/notikumi/valsts-svetku-koncerts/" class="gme-events-calendar-list__event-title-link">
                                    Valsts svētku koncerts TEV, LATVIJA!
                                </a>
                            </h3>
                            <address class="gme-events-calendar-list__event-venue">
                                <span class="gme-events-calendar-list__event-venue-title">
                                    Ķekavas kultūras nams
                                </span>
                            </address>
                            <span class="gme-event-category-badge">
                                KONCERTS
                            </span>
                        </header>
                        <div class="gme-events-calendar-list__event-cost">
                            €5,00
                        </div>
                    </div>
                </article>
            </div>
        </div>

        <!-- Event 2: Past event (must be skipped) -->
        <div class="gme-common-g-row gme-events-calendar-list__event-row">
            <div class="gme-events-calendar-list__event-wrapper gme-common-g-col">
                <article class="gme-events-calendar-list__event gme-common-g-row">
                    <div class="gme-events-calendar-list__event-details">
                        <header class="gme-events-calendar-list__event-header">
                            <div class="gme-events-calendar-list__event-datetime-wrapper">
                                <time class="gme-events-calendar-list__event-datetime">
                                    01.01.2020 10:00 - 12:00
                                </time>
                            </div>
                            <h3 class="gme-events-calendar-list__event-title">
                                <a href="https://kekava.lv/notikumi/pagatnes-notikums/" class="gme-events-calendar-list__event-title-link">
                                    Pagātnes notikums
                                </a>
                            </h3>
                            <address class="gme-events-calendar-list__event-venue">
                                <span class="gme-events-calendar-list__event-venue-title">
                                    Ķekava
                                </span>
                            </address>
                        </header>
                    </div>
                </article>
            </div>
        </div>
    </div>
</body>
</html>
HTML;

        $mock = new MockHandler([
            new Response(200, [], $html),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $scraper = new KekavaScraper();
        $scraper->setClient($client);

        $source = new Source([
            'name' => 'Ķekavas novads',
            'slug' => 'kekava',
            'url' => 'https://kekava.lv/notikumi/',
        ]);

        $events = $scraper->scrape($source);

        // Past event skipped, only future event scraped
        $this->assertCount(1, $events);

        /** @var ScrapedEventDTO $event */
        $event = $events->first();
        $this->assertEquals('Valsts svētku koncerts TEV, LATVIJA!', $event->title);
        $this->assertEquals('Ķekavas kultūras nams', $event->venueName);
        $this->assertEquals('Ķekava', $event->city);
        $this->assertEquals('Rīga un Pierīga', $event->region);
        $this->assertEquals('Gaismas iela 17, Ķekava, LV-2123', $event->address);
        $this->assertEquals(['Mūzika'], $event->categoryNames);
        $this->assertEquals(5.0, $event->priceMin);
        $this->assertEquals(5.0, $event->priceMax);
        $this->assertEquals('https://kekava.lv/wp-content/uploads/sites/15/2026/11/koncerts.jpg', $event->imageUrl);
        $this->assertEquals('valsts-svetku-koncerts', $event->sourceExternalId);
        $this->assertEquals('https://kekava.lv/notikumi/valsts-svetku-koncerts/', $event->sourceUrl);
    }

    public function test_ingest_into_database_with_event_ingestion_service(): void
    {
        $futureDateStr = Carbon::tomorrow('Europe/Riga')->format('d.m.Y');

        $html = <<<HTML
<!DOCTYPE html>
<html>
<body>
    <div class="gme-events-calendar-list">
        <div class="gme-common-g-row gme-events-calendar-list__event-row">
            <div class="gme-events-calendar-list__event-wrapper gme-common-g-col">
                <article class="gme-events-calendar-list__event gme-common-g-row">
                    <div class="gme-events-calendar-list__event-featured-image-wrapper">
                        <a href="https://kekava.lv/notikumi/misija-atrast-vecakus/">
                            <img src="https://kekava.lv/wp-content/uploads/sites/15/2026/10/misija.png" class="gme-events-calendar-list__event-featured-image">
                        </a>
                    </div>
                    <div class="gme-events-calendar-list__event-details">
                        <header class="gme-events-calendar-list__event-header">
                            <div class="gme-events-calendar-list__event-datetime-wrapper">
                                <time class="gme-events-calendar-list__event-datetime">
                                    {$futureDateStr} 16:00 - 17:25
                                </time>
                            </div>
                            <h3 class="gme-events-calendar-list__event-title">
                                <a href="https://kekava.lv/notikumi/misija-atrast-vecakus/" class="gme-events-calendar-list__event-title-link">
                                    MISIJA: ATRAST VECĀKUS | ģimenes filma
                                </a>
                            </h3>
                            <address class="gme-events-calendar-list__event-venue">
                                <span class="gme-events-calendar-list__event-venue-title">
                                    Baložu pilsētas kultūras nams
                                </span>
                            </address>
                            <span class="gme-event-category-badge">
                                FILMA
                            </span>
                        </header>
                        <div class="gme-events-calendar-list__event-cost">
                            €4,00
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </div>
</body>
</html>
HTML;

        $mock = new MockHandler([
            new Response(200, [], $html),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $source = Source::create([
            'name' => 'Ķekavas novads',
            'slug' => 'kekava',
            'url' => 'https://kekava.lv/notikumi/',
            'scraper_class' => KekavaScraper::class,
            'type' => 'municipality',
            'is_active' => true,
        ]);

        $scraper = new KekavaScraper();
        $scraper->setClient($client);
        $this->app->instance(KekavaScraper::class, $scraper);

        $ingestionService = app(EventIngestionService::class);
        $log = $ingestionService->ingest($source);

        $this->assertEquals('success', $log->status);
        $this->assertEquals(1, $log->items_found);
        $this->assertEquals(1, $log->items_created);

        $event = Event::where('source_id', $source->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('MISIJA: ATRAST VECĀKUS | ģimenes filma', $event->title);
        $this->assertEquals('misija-atrast-vecakus', $event->source_external_id);
        $this->assertEquals('https://kekava.lv/wp-content/uploads/sites/15/2026/10/misija.png', $event->image_url);
        $this->assertEquals(4.0, $event->price_min);
        $this->assertEquals(4.0, $event->price_max);

        // Location verification
        $this->assertNotNull($event->location);
        $this->assertEquals('Baložu pilsētas kultūras nams', $event->location->name);
        $this->assertEquals('Baloži', $event->location->city);
        $this->assertEquals('Rīga un Pierīga', $event->location->region);
    }
}
