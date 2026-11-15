<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\JkpScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JkpScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata(): void
    {
        $scraper = new JkpScraper();
        $this->assertEquals('jkp', $scraper->getSlug());
        $this->assertEquals('Jēkabpils Kultūras pārvalde', $scraper->getName());
    }

    public function test_parse_dates_single_date_with_time(): void
    {
        $scraper = new JkpScraper();
        $today = Carbon::create(2026, 11, 15, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('27.10.2026', '|plkst. 19.00', $today, 2026, 10);

        $this->assertNotNull($startAt);
        $this->assertNull($endAt);
        $this->assertEquals('2026-10-27 19:00:00', $startAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_single_date_with_time_range(): void
    {
        $scraper = new JkpScraper();
        $today = Carbon::create(2026, 11, 15, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('04.12.2026', '18.00 - 20.00', $today, 2026, 12);

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-12-04 18:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-12-04 20:00:00', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_date_range_without_time(): void
    {
        $scraper = new JkpScraper();
        $today = Carbon::create(2026, 11, 1, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('28.10 - 30.11', '|plkst. 00.00', $today, 2026, 11);

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-10-28 00:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-30 23:59:59', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_cross_year_range(): void
    {
        $scraper = new JkpScraper();
        $today = Carbon::create(2026, 12, 1, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('20.12 - 05.01', '', $today, 2026, 12);

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-12-20 00:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2027-01-05 23:59:59', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_full_years_range(): void
    {
        $scraper = new JkpScraper();
        $today = Carbon::create(2026, 11, 1, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('28.10.2026 - 30.11.2026', '', $today, 2026, 11);

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-10-28 00:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-30 23:59:59', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_location_resolving_known_venues(): void
    {
        $scraper = new JkpScraper();

        // 1. Krustpils kultūras centrs
        $loc1 = $scraper->resolveLocation('Krustpils kultūras centrs');
        $this->assertEquals('Krustpils kultūras centrs', $loc1['name']);
        $this->assertEquals('Jēkabpils', $loc1['city']);
        $this->assertEquals('Latgale', $loc1['region']);
        $this->assertEquals('cultural_center', $loc1['place_type']);
        $this->assertEquals(56.5098, $loc1['lat']);

        // 2. Jēkabpils Tautas nams
        $loc2 = $scraper->resolveLocation('Jēkabpils Tautas nams');
        $this->assertEquals('Jēkabpils Tautas nams', $loc2['name']);
        $this->assertEquals('Jēkabpils', $loc2['city']);
        $this->assertEquals('Latgale', $loc2['region']);

        // 3. Krustpils pils
        $loc3 = $scraper->resolveLocation('Krustpils pils');
        $this->assertEquals('Krustpils pils', $loc3['name']);
        $this->assertEquals('castle', $loc3['place_type']);

        // 4. Vīpes amatniecības centrs "Māzers"
        $loc4 = $scraper->resolveLocation('Vīpes amatniecības centrs "Māzers"');
        $this->assertEquals('Vīpes amatniecības centrs "Māzers"', $loc4['name']);
        $this->assertEquals('Vīpe', $loc4['city']);

        // 5. Salas Tautas nams
        $loc5 = $scraper->resolveLocation('Salas Tautas nams');
        $this->assertEquals('Salas Tautas nams', $loc5['name']);
        $this->assertEquals('Sala', $loc5['city']);

        // 6. Viesītes kultūras centrs "Sēlija"
        $loc6 = $scraper->resolveLocation('Viesītes kultūras centrs "Sēlija"');
        $this->assertEquals('Viesītes kultūras centrs "Sēlija"', $loc6['name']);
        $this->assertEquals('Viesīte', $loc6['city']);

        // 7. Sēlpils Tautas nams
        $loc7 = $scraper->resolveLocation('Sēlpils Tautas nams');
        $this->assertEquals('Sēlpils Tautas nams', $loc7['name']);
        $this->assertEquals('Sēlija', $loc7['city']);

        // 8. Lones Tautas nams
        $loc8 = $scraper->resolveLocation('Lones Tautas nams');
        $this->assertEquals('Lones Tautas nams', $loc8['name']);
        $this->assertEquals('Lone', $loc8['city']);

        // 9. Biržu Tautas nams
        $loc9 = $scraper->resolveLocation('Biržu Tautas nams');
        $this->assertEquals('Biržu Tautas nams', $loc9['name']);
        $this->assertEquals('Birži', $loc9['city']);

        // 10. Gārsenes pils
        $loc10 = $scraper->resolveLocation('Gārsenes pils');
        $this->assertEquals('Gārsenes pils', $loc10['name']);
        $this->assertEquals('Gārsene', $loc10['city']);

        // 11. Fallback matching via description when raw location is empty
        $loc11 = $scraper->resolveLocation('', '20. decembrī Jēkabpilī, Krustpils kultūras centrā izskanēs Ziemassvētku Lielkoncerts');
        $this->assertEquals('Krustpils kultūras centrs', $loc11['name']);
        $this->assertEquals('Jēkabpils', $loc11['city']);

        // 12. Generic fallback
        $loc12 = $scraper->resolveLocation('');
        $this->assertEquals('Jēkabpils', $loc12['name']);
        $this->assertEquals('Jēkabpils', $loc12['city']);
        $this->assertEquals('Latgale', $loc12['region']);
    }

    public function test_agents_rule_cinema_vs_theatre_categorization(): void
    {
        $scraper = new JkpScraper();

        // 1. Cinema / Kino strict rule: ANY event with filma/kinoseanss is ALWAYS Kino and NEVER Teātris!
        $cats1 = $scraper->resolveCategories('Filma "Klajumā aiz meža"', 'Jēkabpils Tautas nams');
        $this->assertEquals(['Kino'], $cats1);
        $this->assertEquals('movie', $scraper->resolveEntertainmentType($cats1, 'Filma "Klajumā aiz meža"', ''));

        // 2. Kinovakars is Kino
        $cats2 = $scraper->resolveCategories('Kinovakars - "Rūfuss. Jūras briesmonis, kas nemācēja peldēt"', 'Variešu Tautas nams');
        $this->assertEquals(['Kino'], $cats2);
        $this->assertEquals('movie', $scraper->resolveEntertainmentType($cats2, 'Kinovakars', ''));

        // 3. kinoteātris contains substring "teātr", but MUST NEVER be classified as Teātris
        $cats3 = $scraper->resolveCategories('Jaunā ģimenes filma', 'Kinoteātris');
        $this->assertEquals(['Kino'], $cats3);
        $this->assertEquals('movie', $scraper->resolveEntertainmentType($cats3, 'Filma', ''));

        // 4. Theater: production with izrāde
        $cats4 = $scraper->resolveCategories('Bebrenes amatierteātris "Variants" ar izrādi "Džentelmenis ar pannu"', 'Rubenes Tautas nams');
        $this->assertEquals(['Teātris'], $cats4);
        $this->assertEquals('performance', $scraper->resolveEntertainmentType($cats4, 'Izrāde', ''));

        // 5. Theater: komēdija
        $cats5 = $scraper->resolveCategories('Kriminālā komēdija "Patiesais kailums" - izrāde', 'Viesītes kultūras centrs "Sēlija"');
        $this->assertEquals(['Teātris'], $cats5);
        $this->assertEquals('performance', $scraper->resolveEntertainmentType($cats5, 'Komēdija', ''));

        // 6. Concert / Music
        $cats6 = $scraper->resolveCategories('Andreja Osokina un Edgara Ošlejas koncertprogramma “Dvēseles dziesmas un ārijas”', 'Krustpils kultūras centrs');
        $this->assertEquals(['Mūzika'], $cats6);
        $this->assertEquals('concert', $scraper->resolveEntertainmentType($cats6, 'Koncerts', ''));

        // 7. Concert with family / children
        $cats7 = $scraper->resolveCategories('Koncertuzvedums bērniem "Sniega karaliene"', 'Krustpils kultūras centrs');
        $this->assertContains('Mūzika', $cats7);
        $this->assertContains('Bērniem', $cats7);
        $this->assertEquals('concert', $scraper->resolveEntertainmentType($cats7, 'Koncerts bērniem', ''));

        // 8. Exhibition
        $cats8 = $scraper->resolveCategories('Skrīveru tekstilstudijas "Putnukalns" izstāde "Par putniem"', 'Vīpes amatniecības centrs "Māzers"');
        $this->assertEquals(['Izstādes'], $cats8);
        $this->assertEquals('exhibition', $scraper->resolveEntertainmentType($cats8, 'Izstāde', ''));

        // 9. Workshop / Seminar
        $cats9 = $scraper->resolveCategories('Sveču gatavošanas meistarklase', 'Biržu Tautas nams');
        $this->assertEquals(['Semināri'], $cats9);
        $this->assertEquals('education', $scraper->resolveEntertainmentType($cats9, 'Meistarklase', ''));

        // 10. Sports tournament
        $cats10 = $scraper->resolveCategories('Biržu tautas nama ZOLĪTES ČEMPIONĀTA 2026./2027. 1. posms', 'Biržu Tautas nams');
        $this->assertEquals(['Sports'], $cats10);
        $this->assertEquals('sports', $scraper->resolveEntertainmentType($cats10, 'Zolītes čempionāts', ''));

        // 11. Festivities
        $cats11 = $scraper->resolveCategories('Latvijas Republikas proklamēšanas 108. gadadienas svētki "Tev un Latvijai"', 'Jēkabpils');
        $this->assertEquals(['Svētki'], $cats11);
        $this->assertEquals('chill', $scraper->resolveEntertainmentType($cats11, 'Svētki', ''));

        // 12. Fallback: Cits
        $cats12 = $scraper->resolveCategories('Dunavas karogu brauciens', 'Dunavas Tautas nams');
        $this->assertEquals(['Cits'], $cats12);
        $this->assertEquals('other', $scraper->resolveEntertainmentType($cats12, 'Karogu brauciens', ''));
    }

    public function test_parse_price(): void
    {
        $scraper = new JkpScraper();

        $free1 = $scraper->parsePrice('Ieeja bez maksas');
        $this->assertTrue($free1['is_free']);
        $this->assertEquals(0.0, $free1['price_min']);

        $free2 = $scraper->parsePrice('Ieeja brīva');
        $this->assertTrue($free2['is_free']);

        $paid1 = $scraper->parsePrice('Biļetes cena: 5.00 - 15.00 €');
        $this->assertFalse($paid1['is_free']);
        $this->assertEquals(5.0, $paid1['price_min']);
        $this->assertEquals(15.0, $paid1['price_max']);
    }

    public function test_jkp_scraper_multi_month_mock(): void
    {
        $month1Html = <<<'HTML'
<div class="em-calendar">
    <!-- Event 1: Upcoming concert -->
    <div class="em-cal-event-content" data-event-id="1001">
        <div class="em-modal-title">
            <a href="https://www.jkp.lv/notikumi/osokina-koncerts/">Andreja Osokina koncertprogramma</a>
        </div>
        <div class="em-item-image">
            <img src="https://www.jkp.lv/wp-content/uploads/osokins.jpg" alt="Osokins">
        </div>
        <div class="em-event-date">04.12.2026</div>
        <div class="em-event-time">|plkst. 19.00</div>
        <div class="em-event-location">Krustpils kultūras centrs</div>
        <div class="em-event-categories">Lielie notikumi</div>
        <div class="em-item-desc">Izcila klaviermūzikas programma Krustpils kultūras centrā. Biļetes: https://www.bilesuparadize.lv/lv/event/99912</div>
    </div>

    <!-- Event 2: Cinema screening -->
    <div class="em-cal-event-content" data-event-id="1002">
        <div class="em-modal-title">
            <a href="https://www.jkp.lv/notikumi/filma-tumsa-robeza/">Filma "Tumšā robeža"</a>
        </div>
        <div class="em-item-image">
            <img src="https://www.jkp.lv/wp-content/uploads/robeza.jpg" alt="Filma">
        </div>
        <div class="em-event-date">05.12.2026</div>
        <div class="em-event-time">|plkst. 18.00</div>
        <div class="em-event-location">Salas Tautas nams</div>
        <div class="em-event-categories">Lielie notikumi</div>
        <div class="em-item-desc">Spēlfilma visai ģimenei. Ieeja bez maksas.</div>
    </div>

    <!-- Event 3: News item tagged as Aktualitātes with no location (must be skipped) -->
    <div class="em-cal-event-content" data-event-id="1003">
        <div class="em-modal-title">
            <a href="https://www.jkp.lv/aktualitates/raksts/">Izsludināts literatūras konkurss</a>
        </div>
        <div class="em-event-date">08.12.2026</div>
        <div class="em-event-time">|plkst. 00.00</div>
        <div class="em-event-location"></div>
        <div class="em-event-categories">Aktualitātes</div>
        <div class="em-item-desc">Paziņojums par prozas lasījumiem.</div>
    </div>

    <!-- Event 4: Past event (2020) (must be skipped) -->
    <div class="em-cal-event-content" data-event-id="1004">
        <div class="em-modal-title">
            <a href="https://www.jkp.lv/notikumi/sens-koncerts/">Sens koncerts</a>
        </div>
        <div class="em-event-date">10.10.2020</div>
        <div class="em-event-time">|plkst. 18.00</div>
        <div class="em-event-location">Jēkabpils Tautas nams</div>
        <div class="em-event-categories">Lielie notikumi</div>
        <div class="em-item-desc">Vecs notikums.</div>
    </div>
</div>
HTML;

        $month2Html = <<<'HTML'
<div class="em-calendar">
    <!-- Duplicate of Event 1 appearing in adjacent month view (must be deduplicated) -->
    <div class="em-cal-event-content" data-event-id="1001">
        <div class="em-modal-title">
            <a href="https://www.jkp.lv/notikumi/osokina-koncerts/">Andreja Osokina koncertprogramma</a>
        </div>
        <div class="em-event-date">04.12.2026</div>
        <div class="em-event-time">|plkst. 19.00</div>
        <div class="em-event-location">Krustpils kultūras centrs</div>
        <div class="em-event-categories">Lielie notikumi</div>
        <div class="em-item-desc">Dublikāts nākamajā mēnesī.</div>
    </div>

    <!-- Event 5: New theater event in Month 2 -->
    <div class="em-cal-event-content" data-event-id="1005">
        <div class="em-modal-title">
            <a href="https://www.jkp.lv/notikumi/izrade-greizsirdi/">Izrāde - Komēdija "GREIZSIRŽI"</a>
        </div>
        <div class="em-item-image">
            <img src="https://www.jkp.lv/wp-content/uploads/greizsirdi.jpg" alt="Izrāde">
        </div>
        <div class="em-event-date">15.01.2027</div>
        <div class="em-event-time">|plkst. 19.00</div>
        <div class="em-event-location">Viesītes kultūras centrs "Sēlija"</div>
        <div class="em-event-categories">Lielie notikumi</div>
        <div class="em-item-desc">Teātra izrāde Viesītē. Biļetes cena: 8.00 €</div>
    </div>
</div>
HTML;

        // Mock responses for calendar months and detail pages
        $emptyHtml = '<div class="em-calendar"></div>';
        $currentMonth = (int) now('Europe/Riga')->format('n');
        $nextMonth = (int) now('Europe/Riga')->addMonth()->format('n');

        $client = new Client([
            'handler' => function ($request) use ($month1Html, $month2Html, $emptyHtml, $currentMonth, $nextMonth) {
                $uri = (string) $request->getUri();
                if (str_contains($uri, 'ajaxCalendar=1')) {
                    if (str_contains($uri, 'mo=' . $currentMonth . '&')) {
                        return new \GuzzleHttp\Promise\FulfilledPromise(new Response(200, ['Content-Type' => 'text/html'], $month1Html));
                    }
                    if (str_contains($uri, 'mo=' . $nextMonth . '&')) {
                        return new \GuzzleHttp\Promise\FulfilledPromise(new Response(200, ['Content-Type' => 'text/html'], $month2Html));
                    }
                    return new \GuzzleHttp\Promise\FulfilledPromise(new Response(200, ['Content-Type' => 'text/html'], $emptyHtml));
                }

                // Detail pages
                if (str_contains($uri, 'osokina-koncerts')) {
                    return new \GuzzleHttp\Promise\FulfilledPromise(new Response(200, ['Content-Type' => 'text/html'], '<div class="entry_content"><p>Pilns koncerta apraksts ar vairākiem teikumiem.</p></div>'));
                }

                return new \GuzzleHttp\Promise\FulfilledPromise(new Response(200, ['Content-Type' => 'text/html'], '<div class="entry_content"><p>Pasākuma detaļas.</p></div>'));
            }
        ]);

        $scraper = new JkpScraper();
        $scraper->setClient($client);

        $source = new Source([
            'name' => 'Jēkabpils Kultūras pārvalde',
            'slug' => 'jkp',
            'url' => 'https://www.jkp.lv/sakums/kalendars/',
            'scraper_class' => JkpScraper::class,
            'is_active' => true,
        ]);

        $events = $scraper->scrape($source);

        // Should contain exactly 3 events: 1001, 1002, 1005 (1003 is news, 1004 is past, 1001 in month 2 is duplicate)
        $this->assertCount(3, $events);

        // Event 1: Osokina koncerts
        $dto1 = $events[0];
        $this->assertEquals('Andreja Osokina koncertprogramma', $dto1->title);
        $this->assertEquals('jkp-1001', $dto1->sourceExternalId);
        $this->assertEquals('https://www.jkp.lv/notikumi/osokina-koncerts/', $dto1->sourceUrl);
        $this->assertEquals('https://www.jkp.lv/wp-content/uploads/osokins.jpg', $dto1->imageUrl);
        $this->assertEquals('Krustpils kultūras centrs', $dto1->venueName);
        $this->assertEquals('Jēkabpils', $dto1->city);
        $this->assertEquals('Latgale', $dto1->region);
        $this->assertEquals('2026-12-04 19:00:00', $dto1->startAt->format('Y-m-d H:i:s'));
        $this->assertContains('Mūzika', $dto1->categoryNames);
        $this->assertEquals('concert', $dto1->entertainmentType);
        $this->assertEquals('https://www.bilesuparadize.lv/lv/event/99912', $dto1->ticketUrl);

        // Event 2: Cinema screening
        $dto2 = $events[1];
        $this->assertEquals('Filma "Tumšā robeža"', $dto2->title);
        $this->assertEquals('jkp-1002', $dto2->sourceExternalId);
        $this->assertEquals('Salas Tautas nams', $dto2->venueName);
        $this->assertEquals('Sala', $dto2->city);
        $this->assertEquals('2026-12-05 18:00:00', $dto2->startAt->format('Y-m-d H:i:s'));
        $this->assertEquals(['Kino'], $dto2->categoryNames);
        $this->assertEquals('movie', $dto2->entertainmentType);
        $this->assertTrue($dto2->isFree);

        // Event 3: Theater in Month 2
        $dto3 = $events[2];
        $this->assertEquals('Izrāde - Komēdija "GREIZSIRŽI"', $dto3->title);
        $this->assertEquals('jkp-1005', $dto3->sourceExternalId);
        $this->assertEquals('Viesītes kultūras centrs "Sēlija"', $dto3->venueName);
        $this->assertEquals('Viesīte', $dto3->city);
        $this->assertEquals('2027-01-15 19:00:00', $dto3->startAt->format('Y-m-d H:i:s'));
        $this->assertEquals(['Teātris'], $dto3->categoryNames);
        $this->assertEquals('performance', $dto3->entertainmentType);
        $this->assertFalse($dto3->isFree);
        $this->assertEquals(8.0, $dto3->priceMin);
    }

    public function test_jkp_event_ingestion(): void
    {
        $source = Source::create([
            'name' => 'Jēkabpils Kultūras pārvalde',
            'slug' => 'jkp',
            'url' => 'https://www.jkp.lv/sakums/kalendars/',
            'scraper_class' => JkpScraper::class,
            'is_active' => true,
        ]);

        $dto = new ScrapedEventDTO(
            title: 'Andreja Osokina un Edgara Ošlejas koncertprogramma',
            startAt: Carbon::create(2026, 12, 4, 19, 0, 0, 'Europe/Riga'),
            endAt: null,
            description: 'Dvēseles dziesmas un ārijas Krustpils kultūras centrā.',
            shortDescription: 'Koncertprogramma Krustpils kultūras centrā.',
            venueName: 'Krustpils kultūras centrs',
            city: 'Jēkabpils',
            region: 'Latgale',
            address: 'Rīgas iela 212, Jēkabpils, LV-5202',
            latitude: 56.5098,
            longitude: 25.8679,
            placeType: 'cultural_center',
            categoryNames: ['Mūzika'],
            entertainmentType: 'concert',
            isFree: false,
            priceMin: 15.0,
            priceMax: 20.0,
            imageUrl: 'https://www.jkp.lv/wp-content/uploads/osokins.jpg',
            sourceUrl: 'https://www.jkp.lv/notikumi/andreja-osokina-koncerts/',
            sourceExternalId: 'jkp-9731',
            locale: 'lv',
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_external_id', 'jkp-9731')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Andreja Osokina un Edgara Ošlejas koncertprogramma', $event->title);
        $this->assertEquals('jkp.lv', $event->origin_host);
        $this->assertEquals('concert', $event->entertainment_type);
        $this->assertEquals('Jēkabpils', $event->location->city);
        $this->assertEquals('Latgale', $event->location->region);
        $this->assertFalse($event->is_free);
    }
}
