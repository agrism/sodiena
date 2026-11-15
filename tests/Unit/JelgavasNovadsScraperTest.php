<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\JelgavasNovadsScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JelgavasNovadsScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata(): void
    {
        $scraper = new JelgavasNovadsScraper();
        $this->assertEquals('jelgavas-novads', $scraper->getSlug());
        $this->assertEquals('Jelgavas novada pašvaldība', $scraper->getName());
        $this->assertEquals('https://www.jelgavasnovads.lv', JelgavasNovadsScraper::BASE_URL);
        $this->assertEquals('/lv/notikumu-kalendars', JelgavasNovadsScraper::CALENDAR_PATH);
    }

    public function test_skips_komitejas_sede_and_non_events(): void
    {
        $scraper = new JelgavasNovadsScraper();

        // 1. Categories with "komitejas sēde" must be skipped
        $this->assertTrue($scraper->isExcludedEvent(['Komitejas sēde'], 'Sēdes norise'));
        $this->assertTrue($scraper->isExcludedEvent(['Finanšu komitejas sēde'], 'Darba kārtība'));
        $this->assertTrue($scraper->isExcludedEvent(['Pašvaldība', 'komitejas sēdes'], 'Izskatāmie jautājumi'));

        // 2. Title with "komitejas sēde" must be skipped
        $this->assertTrue($scraper->isExcludedEvent(['Pašvaldība'], 'Izglītības, kultūras un sporta komitejas sēde'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Attīstības un tautsaimniecības komitejas sēdes'));

        // 3. Normal cultural categories and titles must NOT be skipped
        $this->assertFalse($scraper->isExcludedEvent(['Koncerts'], 'Valsts svētku koncerts'));
        $this->assertFalse($scraper->isExcludedEvent(['Teātris'], 'Izrāde "Skroderdienas Silmačos"'));
        $this->assertFalse($scraper->isExcludedEvent(['Kino', 'Kultūra'], 'Filmas seanss Elejā'));
        $this->assertFalse($scraper->isExcludedEvent(['Izstāde'], 'Tautas lietišķās mākslas izstāde'));

        // 4. Non-events
        $this->assertTrue($scraper->isNonEvent('darba laiks'));
        $this->assertTrue($scraper->isNonEvent('kontakti'));
        $this->assertFalse($scraper->isNonEvent('Koncerts Elejas muižas parkā'));
    }

    public function test_location_resolving_known_venues_and_parishes(): void
    {
        $scraper = new JelgavasNovadsScraper();

        // 1. Ozolnieku Tautas nams
        $loc1 = $scraper->resolveLocation('Jelgavas novads, Ozolnieki', 'Kora koncerts Ozolnieku Tautas namā');
        $this->assertEquals('Ozolnieku Tautas nams', $loc1['name']);
        $this->assertEquals('Ozolnieki', $loc1['city']);
        $this->assertEquals('Zemgale', $loc1['region']);
        $this->assertEquals('Rīgas iela 18, Ozolnieki, Jelgavas novads, LV-3018', $loc1['address']);

        // 2. Elejas saieta nams
        $loc2 = $scraper->resolveLocation('Jelgavas novads, Eleja', 'Izrāde Elejas saieta namā');
        $this->assertEquals('Elejas saieta nams', $loc2['name']);
        $this->assertEquals('Eleja', $loc2['city']);
        $this->assertEquals('Lietuvas iela 17, Eleja, Jelgavas novads, LV-3006', $loc2['address']);

        // 3. Elejas muižas parks
        $loc3 = $scraper->resolveLocation('Elejas pagasts', 'Brīvdabas pasākums Elejas muižas parkā');
        $this->assertEquals('Elejas muižas parks', $loc3['name']);
        $this->assertEquals('Eleja', $loc3['city']);
        $this->assertEquals('park', $loc3['place_type']);

        // 4. Nākotnes kultūras nams
        $loc4 = $scraper->resolveLocation('Glūdas pagasts, Nākotne', 'Balles vakars Nākotnes kultūras namā');
        $this->assertEquals('Nākotnes kultūras nams', $loc4['name']);
        $this->assertEquals('Nākotne', $loc4['city']);

        // 5. Vilces muiža
        $loc5 = $scraper->resolveLocation('Vilces pagasts', 'Ekskursija Vilces muižā');
        $this->assertEquals('Vilces muiža', $loc5['name']);
        $this->assertEquals('Vilce', $loc5['city']);

        // 6. Zaļenieku kultūras nams / Zaļā muiža
        $loc6 = $scraper->resolveLocation('Zaļenieku pagasts', 'Koncerts Zaļajā muižā');
        $this->assertEquals('Zaļā muiža', $loc6['name']);
        $this->assertEquals('Zaļenieki', $loc6['city']);

        // 7. Parish fallback
        $loc7 = $scraper->resolveLocation('Valgundes pagasts', 'Tikšanās Valgundē');
        $this->assertEquals('Valgundes Tautas nams', $loc7['name']);
        $this->assertEquals('Valgunde', $loc7['city']);
    }

    public function test_date_parsing(): void
    {
        $scraper = new JelgavasNovadsScraper();

        // 1. Single date with time
        [$start1, $end1, $allDay1] = $scraper->parseLatvianDates('18. novembris, 2026', '16.00');
        $this->assertNotNull($start1);
        $this->assertNull($end1);
        $this->assertEquals('2026-11-18 16:00:00', $start1->toDateTimeString());
        $this->assertFalse($allDay1);

        // 2. Single date with time range
        [$start2, $end2, $allDay2] = $scraper->parseLatvianDates('20. novembris, 2026', '18:00 - 21:00');
        $this->assertNotNull($start2);
        $this->assertNotNull($end2);
        $this->assertEquals('2026-11-20 18:00:00', $start2->toDateTimeString());
        $this->assertEquals('2026-11-20 21:00:00', $end2->toDateTimeString());

        // 3. Day range with day prefix
        [$start3, $end3, $allDay3] = $scraper->parseLatvianDates('16. - 20. novembris, 2026', '10.00 - 18.00');
        $this->assertNotNull($start3);
        $this->assertNotNull($end3);
        $this->assertEquals('2026-11-16 10:00:00', $start3->toDateTimeString());
        $this->assertEquals('2026-11-20 18:00:00', $end3->toDateTimeString());

        // 4. All day event
        [$start4, $end4, $allDay4] = $scraper->parseLatvianDates('18. novembris, 2026', 'Visu dienu');
        $this->assertNotNull($start4);
        $this->assertNotNull($end4);
        $this->assertEquals('2026-11-18 00:00:00', $start4->toDateTimeString());
        $this->assertEquals('2026-11-18 23:59:59', $end4->toDateTimeString());
        $this->assertTrue($allDay4);
    }

    public function test_resolve_categories_and_strict_kino_rules(): void
    {
        $scraper = new JelgavasNovadsScraper();

        // 1. Film in title with cinema category -> must be Kino, never Teātris
        $cats1 = $scraper->resolveCategories(
            ['Kultūra'],
            'Animācijas filmas "Straume" kinoseanss Ozolniekos',
            'Ieeja uz seansu brīva'
        );
        $this->assertContains('Kino', $cats1);
        $this->assertNotContains('Teātris', $cats1);
        $this->assertEquals('movie', $scraper->resolveEntertainmentType($cats1, 'Animācijas filma'));

        // 2. Theatre performance -> Teātris
        $cats2 = $scraper->resolveCategories(
            ['Teātris'],
            'Amatierteātra izrāde "Dullais Dauka" Kalnciemā',
            'Režisora iestudējums'
        );
        $this->assertContains('Teātris', $cats2);
        $this->assertEquals('theatre', $scraper->resolveEntertainmentType($cats2, 'Izrāde'));

        // 3. Concert -> Mūzika
        $cats3 = $scraper->resolveCategories(
            ['Koncerts'],
            'Jelgavas novada koru svētku koncerts Elejā',
            'Skanēs latviešu kora dziesmas'
        );
        $this->assertContains('Mūzika', $cats3);
        $this->assertEquals('concert', $scraper->resolveEntertainmentType($cats3, 'Koncerts'));

        // 4. Exhibition -> Izstādes
        $cats4 = $scraper->resolveCategories(
            ['Izstāde'],
            'Tautas daiļamata meistaru darbu izstāde',
            'Ekspozīcija apskatāma katru dienu'
        );
        $this->assertContains('Izstādes', $cats4);
        $this->assertEquals('exhibition', $scraper->resolveEntertainmentType($cats4, 'Izstāde'));
    }

    public function test_full_scrape_with_mocked_http(): void
    {
        $source = Source::firstWhere('slug', 'jelgavas-novads') ?: Source::create([
            'name' => 'Jelgavas novada pašvaldība',
            'slug' => 'jelgavas-novads',
            'url' => 'https://www.jelgavasnovads.lv/lv/notikumu-kalendars',
            'scraper_class' => JelgavasNovadsScraper::class,
            'type' => 'municipality',
            'is_active' => true,
        ]);

        $tomorrow = Carbon::tomorrow('Europe/Riga');
        $months = ['janvāris', 'februāris', 'marts', 'aprīlis', 'maijs', 'jūnijs', 'jūlijs', 'augusts', 'septembris', 'oktobris', 'novembris', 'decembris'];
        $formattedTomorrow = $tomorrow->day . '. ' . $months[$tomorrow->month - 1] . ', ' . $tomorrow->year;

        // Mock HTML response with 4 items:
        // 1. Cultural concert (ACCEPTED)
        // 2. Category "Komitejas sēde" (MUST BE SKIPPED)
        // 3. Title "Finanšu komitejas sēde" (MUST BE SKIPPED)
        // 4. Past event from 2024 (MUST BE SKIPPED)
        $listHtml = <<<HTML
        <div class="events">
            <div class="event">
                <div class="event-info">
                    <span class="date-date">{$formattedTomorrow}</span>
                    <span class="date-time">18.00</span>
                    <span class="describing-field-value">Jelgavas novads, Elejas pagasts, Eleja</span>
                </div>
                <div class="event-image">
                    <img src="/files/gallery_images/koncerts.jpg" />
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/latvijas-valsts-svetku-koncerts-eleja">Latvijas valsts svētku koncerts Elejā</a>
                </h2>
                <div class="event-categories">
                    <a class="badge">Koncerts</a>
                    <a class="badge">Kultūra</a>
                </div>
                <div class="event-content">
                    <p>Elejas saieta namā notiks svinīgs koncerts par godu Latvijas Republikas proklamēšanas dienai. Ieeja brīva.</p>
                </div>
            </div>

            <div class="event">
                <div class="event-info">
                    <span class="date-date">{$formattedTomorrow}</span>
                    <span class="date-time">10.00</span>
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/izglitibas-komiteja">Izglītības un kultūras jautājumu apspriešana</a>
                </h2>
                <div class="event-categories">
                    <a class="badge">Komitejas sēde</a>
                </div>
            </div>

            <div class="event">
                <div class="event-info">
                    <span class="date-date">{$formattedTomorrow}</span>
                    <span class="date-time">14.00</span>
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/finansu-komitejas-sede">Finanšu komitejas sēde</a>
                </h2>
                <div class="event-categories">
                    <a class="badge">Pašvaldība</a>
                </div>
            </div>

            <div class="event">
                <div class="event-info">
                    <span class="date-date">10. janvāris, 2024</span>
                    <span class="date-time">12.00</span>
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/pagatnes-pasakums">Pagātnes pasākums</a>
                </h2>
                <div class="event-categories">
                    <a class="badge">Kultūra</a>
                </div>
            </div>
        </div>
HTML;

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $listHtml),
            new Response(200, ['Content-Type' => 'text/html'], '<div class="events"></div>'),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $scraper = new JelgavasNovadsScraper();
        $scraper->setClient($client);

        $events = $scraper->scrape($source);

        // Only event 1 should be accepted; 2 & 3 skipped by komitejas sēde, 4 skipped by past date
        $this->assertCount(1, $events);

        $event = $events->first();
        $this->assertEquals('Latvijas valsts svētku koncerts Elejā', $event->title);
        $this->assertEquals('Elejas saieta nams', $event->venueName);
        $this->assertEquals('Eleja', $event->city);
        $this->assertEquals('Zemgale', $event->region);
        $this->assertContains('Mūzika', $event->categoryNames);
        $this->assertEquals('concert', $event->entertainmentType);
        $this->assertTrue($event->isFree);
        $this->assertEquals('https://www.jelgavasnovads.lv/files/gallery_images/koncerts.jpg', $event->imageUrl);
        $this->assertEquals('https://www.jelgavasnovads.lv/lv/notikums/latvijas-valsts-svetku-koncerts-eleja', $event->sourceUrl);
        $this->assertEquals('jelgavasnovads-latvijas-valsts-svetku-koncerts-eleja', $event->sourceExternalId);
    }

    public function test_jelgavas_novads_event_ingestion(): void
    {
        $source = Source::create([
            'name' => 'Jelgavas novada pašvaldība',
            'slug' => 'jelgavas-novads-test',
            'url' => 'https://www.jelgavasnovads.lv/lv/notikumu-kalendars',
            'scraper_class' => JelgavasNovadsScraper::class,
            'type' => 'municipality',
            'is_active' => true,
        ]);

        $dto = new ScrapedEventDTO(
            title: 'Valsts svētku koncerts Ozolniekos',
            startAt: Carbon::parse('2026-11-18 17:00:00'),
            endAt: null,
            description: 'Ozolnieku Tautas namā notiks svinīgs koncerts.',
            shortDescription: 'Svinīgs koncerts Ozolniekos.',
            venueName: 'Ozolnieku Tautas nams',
            city: 'Ozolnieki',
            region: 'Zemgale',
            address: 'Rīgas iela 18, Ozolnieki, Jelgavas novads, LV-3018',
            placeType: 'cultural_center',
            categoryNames: ['Mūzika'],
            entertainmentType: 'concert',
            isFree: true,
            priceMin: 0.0,
            priceMax: 0.0,
            currency: 'EUR',
            ticketUrl: 'https://www.jelgavasnovads.lv/lv/notikums/svetku-koncerts-ozolniekos',
            imageUrl: 'https://www.jelgavasnovads.lv/files/koncerts.jpg',
            sourceUrl: 'https://www.jelgavasnovads.lv/lv/notikums/svetku-koncerts-ozolniekos',
            sourceExternalId: 'jelgavasnovads-svetku-koncerts-ozolniekos',
            locale: 'lv',
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);
        $this->assertDatabaseHas('events', [
            'source_id' => $source->id,
            'source_external_id' => 'jelgavasnovads-svetku-koncerts-ozolniekos',
        ]);

        $event = Event::where('source_external_id', 'jelgavasnovads-svetku-koncerts-ozolniekos')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Valsts svētku koncerts Ozolniekos', $event->title);
        $this->assertEquals('Ozolnieki', $event->location->city);
        $this->assertEquals('Zemgale', $event->location->region);
        $this->assertEquals('concert', $event->entertainment_type);
        $this->assertTrue((bool)$event->is_free);
    }
}
