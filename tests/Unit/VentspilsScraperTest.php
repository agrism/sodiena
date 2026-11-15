<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\VentspilsScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VentspilsScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata(): void
    {
        $scraper = new VentspilsScraper();
        $this->assertEquals('ventspils', $scraper->getSlug());
        $this->assertEquals('Ventspils valstspilsēta', $scraper->getName());
        $this->assertEquals('https://www.ventspils.lv', VentspilsScraper::BASE_URL);
        $this->assertEquals('/notikumi/?view=all', VentspilsScraper::CALENDAR_PATH);
    }

    public function test_extract_category_from_url(): void
    {
        $scraper = new VentspilsScraper();

        $this->assertEquals('sports', $scraper->extractCategoryFromUrl('https://www.ventspils.lv/notikumi/sports/ventspils-skrejiens/'));
        $this->assertEquals('muzejs', $scraper->extractCategoryFromUrl('https://www.ventspils.lv/notikumi/muzejs/lekcija-vesture/'));
        $this->assertEquals('gimenem-ar-berniem', $scraper->extractCategoryFromUrl('https://www.ventspils.lv/notikumi/gimenem-ar-berniem/darbnica/'));
        $this->assertEquals('cits', $scraper->extractCategoryFromUrl('https://www.ventspils.lv/notikumi/cits/martina-gadatirgus/'));
        $this->assertEquals('', $scraper->extractCategoryFromUrl('https://www.ventspils.lv/notikumi/'));
        $this->assertEquals('', $scraper->extractCategoryFromUrl(null));
    }

    public function test_location_resolving_known_venues_and_novads(): void
    {
        $scraper = new VentspilsScraper();

        // 1. Koncertzāle "Latvija"
        $loc1 = $scraper->resolveLocation('', 'Simfoniskās mūzikas koncerts Koncertzālē "Latvija"');
        $this->assertEquals('Koncertzāle "Latvija"', $loc1['name']);
        $this->assertEquals('Ventspils', $loc1['city']);
        $this->assertEquals('Kurzeme', $loc1['region']);
        $this->assertEquals('Lielais laukums 1, Ventspils, LV-3601', $loc1['address']);
        $this->assertEquals('concert_hall', $loc1['place_type']);
        $this->assertEquals(57.3908, $loc1['lat']);

        // 2. Teātra nams "Jūras vārti"
        $loc2 = $scraper->resolveLocation('', 'Rudens balle teātra namā "Jūras vārti"');
        $this->assertEquals('Teātra nams "Jūras vārti"', $loc2['name']);
        $this->assertEquals('theater', $loc2['place_type']);
        $this->assertEquals('Karlīnes iela 40, Ventspils, LV-3601', $loc2['address']);

        // 3. Kino Rio
        $loc3 = $scraper->resolveLocation('', 'Zvaigžņu nakts balle Kino Rio');
        $this->assertEquals('Kino Rio', $loc3['name']);
        $this->assertEquals('cinema', $loc3['place_type']);
        $this->assertEquals('Pils iela 28, Ventspils, LV-3601', $loc3['address']);

        // 4. Piejūras brīvdabas muzejs
        $loc4 = $scraper->resolveLocation('', 'Mārtiņdienas svinības Piejūras brīvdabas muzejā');
        $this->assertEquals('Piejūras brīvdabas muzejs', $loc4['name']);
        $this->assertEquals('museum', $loc4['place_type']);

        // 5. Livonijas ordeņa pils
        $loc5 = $scraper->resolveLocation('', 'Izstāde Livonijas ordeņa pilī');
        $this->assertEquals('Livonijas ordeņa pils', $loc5['name']);

        // 6. Zinātnes centrs "VIZIUM"
        $loc6 = $scraper->resolveLocation('', 'VIZIUM svētku radošā darbnīca');
        $this->assertEquals('Zinātnes centrs "VIZIUM"', $loc6['name']);

        // 7. Gāliņciema bibliotēka
        $loc7 = $scraper->resolveLocation('', 'Tikšanās Gāliņciema bibliotēkā');
        $this->assertEquals('Gāliņciema bibliotēka', $loc7['name']);

        // 8. Bērnu bibliotēka
        $loc8 = $scraper->resolveLocation('', 'Pirkstiņdarbi Bērnu bibliotēkā');
        $this->assertEquals('Ventspils Bērnu bibliotēka', $loc8['name']);

        // 9. Piltene
        $loc9 = $scraper->resolveLocation('', 'Lāčplēša diena Piltenē');
        $this->assertEquals('Piltenes kultūras nams', $loc9['name']);
        $this->assertEquals('Piltene', $loc9['city']);
        $this->assertEquals('Kurzeme', $loc9['region']);

        // 10. Fallback
        $loc10 = $scraper->resolveLocation('', 'Pilsētas svētki');
        $this->assertEquals('Ventspils', $loc10['name']);
        $this->assertEquals('Ventspils', $loc10['city']);
        $this->assertEquals('Kurzeme', $loc10['region']);
        $this->assertEquals('Jūras iela 36, Ventspils, LV-3601', $loc10['address']);
    }

    public function test_date_parsing(): void
    {
        $scraper = new VentspilsScraper();

        // 1. Date range without space after dot
        [$start1, $end1, $allDay1] = $scraper->parseLatvianDates('07.novembris, 2026 - 17.novembris, 2026');
        $this->assertNotNull($start1);
        $this->assertNotNull($end1);
        $this->assertEquals('2026-11-07 10:00:00', $start1->toDateTimeString());
        $this->assertEquals('2026-11-17 20:00:00', $end1->toDateTimeString());
        $this->assertTrue($allDay1);

        // 2. Single date without space
        [$start2, $end2, $allDay2] = $scraper->parseLatvianDates('16.novembris, 2026');
        $this->assertNotNull($start2);
        $this->assertNull($end2);
        $this->assertEquals('2026-11-16 10:00:00', $start2->toDateTimeString());
        $this->assertTrue($allDay2);

        // 3. Single date with space
        [$start3, $end3, $allDay3] = $scraper->parseLatvianDates('17. novembris, 2026');
        $this->assertNotNull($start3);
        $this->assertEquals('2026-11-17 10:00:00', $start3->toDateTimeString());

        // 4. Date range across year change
        [$start4, $end4, $allDay4] = $scraper->parseLatvianDates('28.decembris, 2026 - 05.janvāris, 2027');
        $this->assertNotNull($start4);
        $this->assertNotNull($end4);
        $this->assertEquals('2026-12-28 10:00:00', $start4->toDateTimeString());
        $this->assertEquals('2027-01-05 20:00:00', $end4->toDateTimeString());

        // 5. Numeric date
        [$start5, $end5, $allDay5] = $scraper->parseLatvianDates('16.11.2026');
        $this->assertNotNull($start5);
        $this->assertEquals('2026-11-16 10:00:00', $start5->toDateTimeString());
    }

    public function test_strict_categorization_and_kino_rules_per_agents_md(): void
    {
        $scraper = new VentspilsScraper();

        // 1. STRICT RULE: Kino Rio -> ALWAYS Kino, NEVER Teātris
        $cats1 = $scraper->resolveCategories('izklaide', 'Zvaigžņu nakts balle Kino Rio');
        $this->assertContains('Kino', $cats1);
        $this->assertNotContains('Teātris', $cats1);
        $this->assertEquals('movie', $scraper->resolveEntertainmentType($cats1, 'Zvaigžņu nakts balle Kino Rio'));

        // 2. STRICT RULE: kinoteātris contains "teātr", but MUST NEVER be classified as Teātris
        $cats2 = $scraper->resolveCategories('kultura', 'Jaunākā latviešu spēlfilma kinoteātrī');
        $this->assertContains('Kino', $cats2);
        $this->assertNotContains('Teātris', $cats2);

        // 3. Film in title -> Kino
        $cats3 = $scraper->resolveCategories('kultura', 'Dokumentālā filma par Ventas vēsturi');
        $this->assertContains('Kino', $cats3);
        $this->assertEquals('movie', $scraper->resolveEntertainmentType($cats3, 'Dokumentālā filma'));

        // 4. Theatre production -> Teātris
        $cats4 = $scraper->resolveCategories('kultura', 'Jāņa Skuteļa stāvizrāde “HA!”');
        $this->assertContains('Teātris', $cats4);
        $this->assertEquals('theatre', $scraper->resolveEntertainmentType($cats4, 'Stāvizrāde'));

        // 5. Sports -> Sports
        $cats5 = $scraper->resolveCategories('sports', 'Latvijas Jaunatnes basketbola līgas spēle meitenēm Ventspils “Spars”');
        $this->assertContains('Sports', $cats5);
        $this->assertEquals('sports', $scraper->resolveEntertainmentType($cats5, 'Basketbola spēle'));

        // 6. Concert -> Mūzika
        $cats6 = $scraper->resolveCategories('izklaide', 'Grupas “Labvēlīgais tips” Vecgada šovs “Lai laime nepāriet 2027”');
        $this->assertContains('Mūzika', $cats6);
        $this->assertEquals('concert', $scraper->resolveEntertainmentType($cats6, 'Vecgada šovs'));

        // 7. Exhibitions -> Izstādes
        $cats7 = $scraper->resolveCategories('muzejs', 'Ventspils muzeja krājuma izstāde “Drukāts Ventspilī”');
        $this->assertContains('Izstādes', $cats7);
        $this->assertEquals('exhibition', $scraper->resolveEntertainmentType($cats7, 'Izstāde'));

        // 8. Children -> Bērniem
        $cats8 = $scraper->resolveCategories('gimenem-ar-berniem', 'ZOOM Pasaku rīts');
        $this->assertContains('Bērniem', $cats8);

        // 9. Festival -> festival
        $cats9 = $scraper->resolveCategories('cits', 'Mārtiņa gadatirgus');
        $this->assertEquals('festival', $scraper->resolveEntertainmentType($cats9, 'Mārtiņa gadatirgus'));
    }

    public function test_price_parsing(): void
    {
        $scraper = new VentspilsScraper();

        $p1 = $scraper->parsePrice('Ieeja bez maksas visiem apmeklētājiem');
        $this->assertTrue($p1['is_free']);
        $this->assertNull($p1['price_min']);

        $p2 = $scraper->parsePrice('Biļetes cena: 10.00 EUR');
        $this->assertFalse($p2['is_free']);
        $this->assertEquals(10.0, $p2['price_min']);

        $p3 = $scraper->parsePrice('Parastais pasākums');
        $this->assertTrue($p3['is_free']);
    }

    public function test_is_non_event(): void
    {
        $scraper = new VentspilsScraper();

        $this->assertTrue($scraper->isNonEvent('darba laiks'));
        $this->assertTrue($scraper->isNonEvent('kontakti'));
        $this->assertTrue($scraper->isNonEvent('repertuārs'));
        $this->assertFalse($scraper->isNonEvent('Mārtiņa gadatirgus'));
    }

    public function test_full_scrape_with_mocked_http(): void
    {
        $source = Source::firstWhere('slug', 'ventspils') ?: Source::create([
            'name' => 'Ventspils valstspilsēta',
            'slug' => 'ventspils',
            'url' => 'https://www.ventspils.lv/notikumi/?view=all',
            'scraper_class' => VentspilsScraper::class,
            'type' => 'municipality',
            'is_active' => true,
        ]);

        $tomorrow = Carbon::tomorrow('Europe/Riga');
        $months = ['janvāris', 'februāris', 'marts', 'aprīlis', 'maijs', 'jūnijs', 'jūlijs', 'augusts', 'septembris', 'oktobris', 'novembris', 'decembris'];
        $mStr = $months[$tomorrow->month - 1];

        // Mock HTML response with:
        // 1. Valid concert event in the future (ACCEPTED)
        // 2. Valid movie screening in Kino Rio in the future (ACCEPTED - strict Kino)
        // 3. Past event from 2024 (SKIPPED per AGENTS.md rule)
        // 4. Non-event "darba laiks" (SKIPPED)
        $listHtml = <<<HTML
        <div class="news-category__posts">
            <article class="post-item post-item--small">
                <div class="post-item__wrap">
                    <p class="post-item__event-date">{$tomorrow->day}.{$mStr}, {$tomorrow->year}</p>
                    <a class="post-item__image" href="https://www.ventspils.lv/notikumi/kultura/svetku-koncerts-koncertzale-latvija/">
                        <img src="https://www.ventspils.lv/app/uploads/koncerts.jpg" />
                    </a>
                </div>
                <div class="post-item__content">
                    <h2 class="post-item__title">
                        <a href="https://www.ventspils.lv/notikumi/kultura/svetku-koncerts-koncertzale-latvija/">Svētku koncerts Koncertzālē "Latvija"</a>
                    </h2>
                </div>
            </article>

            <article class="post-item post-item--small">
                <div class="post-item__wrap">
                    <p class="post-item__event-date">{$tomorrow->day}.{$mStr}, {$tomorrow->year}</p>
                    <a class="post-item__image" href="https://www.ventspils.lv/notikumi/izklaide/spelfilmas-seanss-kino-rio/">
                        <img src="https://www.ventspils.lv/app/uploads/filma.jpg" />
                    </a>
                </div>
                <div class="post-item__content">
                    <h2 class="post-item__title">
                        <a href="https://www.ventspils.lv/notikumi/izklaide/spelfilmas-seanss-kino-rio/">Jaunākās latviešu spēlfilmas seanss Kino Rio</a>
                    </h2>
                </div>
            </article>

            <article class="post-item post-item--small">
                <div class="post-item__wrap">
                    <p class="post-item__event-date">10.janvāris, 2024</p>
                    <a class="post-item__image" href="https://www.ventspils.lv/notikumi/cits/pagatnes-pasakums/">
                        <img src="https://www.ventspils.lv/app/uploads/pagatne.jpg" />
                    </a>
                </div>
                <div class="post-item__content">
                    <h2 class="post-item__title">
                        <a href="https://www.ventspils.lv/notikumi/cits/pagatnes-pasakums/">Pagātnes pasākums 2024</a>
                    </h2>
                </div>
            </article>

            <article class="post-item post-item--small">
                <div class="post-item__wrap">
                    <p class="post-item__event-date">{$tomorrow->day}.{$mStr}, {$tomorrow->year}</p>
                </div>
                <div class="post-item__content">
                    <h2 class="post-item__title">
                        <a href="https://www.ventspils.lv/notikumi/cits/darba-laiks/">Darba laiks</a>
                    </h2>
                </div>
            </article>
        </div>
HTML;

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $listHtml),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $scraper = new VentspilsScraper();
        $scraper->setClient($client);

        $events = $scraper->scrape($source);

        // Only 2 future valid events returned
        $this->assertCount(2, $events);

        // Event 1 checks
        $event1 = $events[0];
        $this->assertEquals('Svētku koncerts Koncertzālē "Latvija"', $event1->title);
        $this->assertEquals('Koncertzāle "Latvija"', $event1->venueName);
        $this->assertEquals('Ventspils', $event1->city);
        $this->assertEquals('Kurzeme', $event1->region);
        $this->assertEquals('Lielais laukums 1, Ventspils, LV-3601', $event1->address);
        $this->assertEquals('concert_hall', $event1->placeType);
        $this->assertContains('Mūzika', $event1->categoryNames);
        $this->assertEquals('concert', $event1->entertainmentType);
        $this->assertEquals('ventspils-svetku-koncerts-koncertzale-latvija', $event1->sourceExternalId);

        // Event 2 checks (Strict Kino rule)
        $event2 = $events[1];
        $this->assertEquals('Jaunākās latviešu spēlfilmas seanss Kino Rio', $event2->title);
        $this->assertEquals('Kino Rio', $event2->venueName);
        $this->assertEquals('cinema', $event2->placeType);
        $this->assertContains('Kino', $event2->categoryNames);
        $this->assertNotContains('Teātris', $event2->categoryNames);
        $this->assertEquals('movie', $event2->entertainmentType);
        $this->assertEquals('ventspils-spelfilmas-seanss-kino-rio', $event2->sourceExternalId);
    }

    public function test_ventspils_event_ingestion(): void
    {
        $source = Source::create([
            'name' => 'Ventspils valstspilsēta Test',
            'slug' => 'ventspils-test',
            'url' => 'https://www.ventspils.lv/notikumi/?view=all',
            'scraper_class' => VentspilsScraper::class,
            'type' => 'municipality',
            'is_active' => true,
        ]);

        $futureDate = Carbon::now('Europe/Riga')->addDays(5);

        $dto = new ScrapedEventDTO(
            title: 'Jāņa Skuteļa stāvizrāde “HA!” Ventspilī',
            startAt: $futureDate->copy()->setTime(19, 0),
            endAt: null,
            description: 'Jāņa Skuteļa stāvizrāde “HA!” Ventspils Teātra namā Jūras vārti.',
            shortDescription: 'Jāņa Skuteļa stāvizrāde “HA!” Ventspilī.',
            venueName: 'Teātra nams "Jūras vārti"',
            city: 'Ventspils',
            region: 'Kurzeme',
            address: 'Karlīnes iela 40, Ventspils, LV-3601',
            placeType: 'theater',
            categoryNames: ['Teātris'],
            entertainmentType: 'theatre',
            isFree: true,
            priceMin: null,
            priceMax: null,
            currency: 'EUR',
            ticketUrl: 'https://www.ventspils.lv/notikumi/kultura/jana-skutela-stavizrade-ha/',
            imageUrl: 'https://www.ventspils.lv/app/uploads/skutelis.jpg',
            sourceUrl: 'https://www.ventspils.lv/notikumi/kultura/jana-skutela-stavizrade-ha/',
            sourceExternalId: 'ventspils-jana-skutela-stavizrade-ha',
            locale: 'lv',
            rawData: []
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_external_id', 'ventspils-jana-skutela-stavizrade-ha')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Jāņa Skuteļa stāvizrāde “HA!” Ventspilī', $event->title);
        $this->assertStringContainsString('Jūras vārti', $event->location->name);
        $this->assertEquals('Ventspils', $event->location->city);
        $this->assertEquals('Kurzeme', $event->location->region);
        $this->assertEquals('theatre', $event->entertainment_type);
    }
}
