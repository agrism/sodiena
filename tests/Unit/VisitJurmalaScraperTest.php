<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\VisitJurmalaScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisitJurmalaScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata(): void
    {
        $scraper = new VisitJurmalaScraper();
        $this->assertEquals('visitjurmala', $scraper->getSlug());
        $this->assertEquals('Visit Jūrmala', $scraper->getName());

        $client = (function () {
            return $this->httpClient;
        })->call($scraper);
        $this->assertEquals(60, $client->getConfig('timeout'));
        $this->assertEquals(60, $client->getConfig('connect_timeout'));
    }

    public function test_parse_dates_single_date_with_time_range(): void
    {
        $scraper = new VisitJurmalaScraper();
        $today = Carbon::create(2026, 11, 15, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('Svētdiena, 15.novembris 10:30 - 13:00', '2026-11-15', $today);

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-11-15 10:30:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-15 13:00:00', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_single_date_with_single_time(): void
    {
        $scraper = new VisitJurmalaScraper();
        $today = Carbon::create(2026, 11, 15, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('Svētdiena, 15.novembris 17:00', '2026-11-15', $today);

        $this->assertNotNull($startAt);
        $this->assertNull($endAt);
        $this->assertEquals('2026-11-15 17:00:00', $startAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_multi_day_range(): void
    {
        $scraper = new VisitJurmalaScraper();
        $today = Carbon::create(2026, 11, 1, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('11.novembris - 22.novembris', '2026-11-11', $today);

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-11-11 00:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-22 23:59:59', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_cross_year_range(): void
    {
        $scraper = new VisitJurmalaScraper();
        $today = Carbon::create(2026, 11, 15, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('28.novembris 17:00 - 10.janvāris (2027) 21:00', '2026-11-28', $today);

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-11-28 17:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2027-01-10 21:00:00', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_location_resolving_known_venues(): void
    {
        $scraper = new VisitJurmalaScraper();

        // 1. Dzintaru koncertzāle
        $loc1 = $scraper->resolveLocationMeta('Dzintaru koncertzāle', 'Turaidas iela 1');
        $this->assertEquals('Dzintaru koncertzāle', $loc1['name']);
        $this->assertEquals('Jūrmala', $loc1['city']);
        $this->assertEquals('Rīga un Pierīga', $loc1['region']);
        $this->assertEquals('concert_hall', $loc1['place_type']);
        $this->assertEquals(56.9749, $loc1['lat']);

        // 2. Jūrmalas teātris
        $loc2 = $scraper->resolveLocationMeta('Jūrmalas teātris', 'Muižas iela 7');
        $this->assertEquals('Jūrmalas teātris', $loc2['name']);
        $this->assertEquals('theater', $loc2['place_type']);
        $this->assertEquals(56.9839, $loc2['lat']);

        // 3. Jūrmalas muzejs
        $loc3 = $scraper->resolveLocationMeta('Jūrmalas muzejs', 'Tirgoņu iela 29');
        $this->assertEquals('Jūrmalas muzejs', $loc3['name']);
        $this->assertEquals('museum', $loc3['place_type']);

        // 4. Bulduru Izstāžu nams
        $loc4 = $scraper->resolveLocationMeta('Bulduru Izstāžu nams', 'Muižas iela 6');
        $this->assertEquals('Bulduru Izstāžu nams', $loc4['name']);
        $this->assertEquals('museum', $loc4['place_type']);

        // 5. Ķemeru kūrorta parks
        $loc5 = $scraper->resolveLocationMeta('Ķemeru kūrorta parks', 'Tūristu iela 32');
        $this->assertEquals('Ķemeru kūrorta parks', $loc5['name']);
        $this->assertEquals('park', $loc5['place_type']);

        // 6. Baltic Beach Hotel & SPA
        $loc6 = $scraper->resolveLocationMeta('Kūrortviesnīca “Baltic Beach Hotel & SPA”', 'Jūras iela 23/25');
        $this->assertEquals('Baltic Beach Hotel & SPA', $loc6['name']);
        $this->assertEquals('hotel', $loc6['place_type']);

        // 7. Unknown venue fallback
        $loc7 = $scraper->resolveLocationMeta('Nejauša Kafejnīca', 'Smilšu iela 10');
        $this->assertEquals('Nejauša Kafejnīca', $loc7['name']);
        $this->assertEquals('Smilšu iela 10, Jūrmala', $loc7['address']);
        $this->assertEquals('restaurant', $loc7['place_type']);
    }

    public function test_resolve_categories_strict_cinema_rules(): void
    {
        $scraper = new VisitJurmalaScraper();

        // STRICT AGENTS.MD RULE: Any event with filma or kinoseanss MUST be Kino and NEVER Teātris!
        $cats1 = $scraper->resolveCategories('Dokumentālā filma par Jūrmalas vēsturi', 'Filmas seanss un diskusija', 'Jūrmalas Kultūras centrs');
        $this->assertContains('Kino', $cats1);
        $this->assertNotContains('Teātris', $cats1);
        $this->assertEquals('movie', $scraper->resolveEntertainmentType($cats1, 'Dokumentālā filma', 'Filmas seanss'));

        // Kinoteātris contains "teātr", but MUST NOT be classified as Teātris
        $cats2 = $scraper->resolveCategories('Kinoseanss brīvā dabā', 'Pasākums kinoteātrī', 'Kinoteātris');
        $this->assertContains('Kino', $cats2);
        $this->assertNotContains('Teātris', $cats2);
    }

    public function test_resolve_categories_theatre_music_exhibitions_sports(): void
    {
        $scraper = new VisitJurmalaScraper();

        // 1. Theatre
        $cats1 = $scraper->resolveCategories('Izrāde “OPĀLU MĪL VISI”', 'Izrādes centrā ir vientuļā Opāla', 'Jūrmalas teātris');
        $this->assertContains('Teātris', $cats1);
        $this->assertEquals('performance', $scraper->resolveEntertainmentType($cats1, 'Izrāde “OPĀLU MĪL VISI”', 'Izrādes centrā'));

        // 2. Music
        $cats2 = $scraper->resolveCategories('Elīna un Vestards Šimkus. Ir viens vakars', 'Koncertprogrammā skanēs skaņdarbi', 'Dzintaru koncertzāle');
        $this->assertContains('Mūzika', $cats2);
        $this->assertEquals('concert', $scraper->resolveEntertainmentType($cats2, 'Elīna un Vestards Šimkus', 'Koncertprogrammā'));

        // 3. Exhibitions
        $cats3 = $scraper->resolveCategories('Izstāde “Bilderlingshof - Bilderiņi”', 'Izstādē eksponēti vēsturiski priekšmeti', 'Bulduru Izstāžu nams');
        $this->assertContains('Izstādes', $cats3);
        $this->assertEquals('exhibition', $scraper->resolveEntertainmentType($cats3, 'Izstāde', 'Izstādē'));

        // 4. Sports
        $cats4 = $scraper->resolveCategories('Nūjošanas nodarbības sestdienās', 'Aicinām iedzīvotājus nūjot sertificēta trenera vadībā', 'Jūrmala');
        $this->assertContains('Sports', $cats4);
        $this->assertEquals('sports', $scraper->resolveEntertainmentType($cats4, 'Nūjošanas nodarbības', 'Nūjot'));

        // 5. Celebrations & Gastronomy
        $cats5 = $scraper->resolveCategories('Latvijas Svētku Brančs', 'Svētku tematikai atbilstošs piedāvājums', 'Restorāns "Lokāls Veranda"');
        $this->assertContains('Svētki', $cats5);
        $this->assertEquals('chill', $scraper->resolveEntertainmentType($cats5, 'Svētku Brančs', 'Svētku tematikai'));
    }

    public function test_parse_price(): void
    {
        $scraper = new VisitJurmalaScraper();

        // 1. Paid event with adult price and free kids pricing
        $price1 = $scraper->parsePrice('Cena pieaugušajiem – 29 EUR, bērniem no 7 līdz 12 gadiem – 14 EUR. Bērniem līdz 6 gadu vecumam – bez maksas.');
        $this->assertFalse($price1['is_free']);
        $this->assertEquals(14.0, $price1['price_min']);
        $this->assertEquals(29.0, $price1['price_max']);

        // 2. Free event
        $price2 = $scraper->parsePrice('Ieeja pasākumā bez maksas. Visi laipni gaidīti!');
        $this->assertTrue($price2['is_free']);
        $this->assertEquals(0.0, $price2['price_min']);
        $this->assertEquals(0.0, $price2['price_max']);
    }

    public function test_scrape_mock_html(): void
    {
        $futureDate = now()->addDays(5)->format('Y-m-d');
        $futureDay = now()->addDays(5)->format('j');
        $mockHtml = <<<HTML
<!DOCTYPE html>
<html>
<body>
    <div class="event-list-item" id="event-{$futureDate}-10435">
        <div class="event-date">
            <div>Piektdiena, {$futureDay}.novembris</div>
            19:00 - 21:00
        </div>
        <div class="event-list-image">
            <a href="https://visitjurmala.lv//images/events/test_s1.jpg" class="gallery-item" title="Koncerts Dzintaros">
                <img src="/placeholder.jpg" data-src="/test_s3.jpg" alt="Koncerts" />
            </a>
        </div>
        <div class="event-list-descr">
            <h2><a href="https://visitjurmala.lv/lv/pasakumi/koncerts-dzintaros/">Koncerts Dzintaru koncertzālē</a></h2>
            <div class="event-more-descr">
                <div class="event-details">
                    <ul class="contacts">
                        <li class="col-100"><label>Datums</label> Piektdiena, {$futureDay}.novembris</li>
                        <li class="col-100"><label>Norises vieta</label> <ul><li><a href="https://visitjurmala.lv/dzintari/">Dzintaru koncertzāle</a></li></ul></li>
                        <li class="col-100"><label>Adrese</label> Turaidas iela 1</li>
                        <li class="col-100"><label>Mājas lapa</label> <a href="https://www.dzintarukoncertzale.lv/">https://www.dzintarukoncertzale.lv/</a></li>
                    </ul>
                </div>
                <p>Brīnišķīgs kamermūzikas vakars Dzintaru koncertzālē ar izciliem mūziķiem.</p>
                <p>Biļešu cena: 25 EUR.</p>
            </div>
        </div>
    </div>
</body>
</html>
HTML;

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $mockHtml),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $scraper = new VisitJurmalaScraper();
        $scraper->setClient($client);

        $source = new Source([
            'name' => 'Visit Jūrmala',
            'slug' => 'visitjurmala',
            'url' => 'https://visitjurmala.lv/lv/pasakumi/',
            'scraper_class' => VisitJurmalaScraper::class,
            'is_active' => true,
        ]);

        $events = $scraper->scrape($source);

        $this->assertCount(1, $events);
        $dto = $events->first();

        $this->assertEquals('Koncerts Dzintaru koncertzālē', $dto->title);
        $this->assertEquals('Dzintaru koncertzāle', $dto->venueName);
        $this->assertEquals('Jūrmala', $dto->city);
        $this->assertEquals('Rīga un Pierīga', $dto->region);
        $this->assertEquals('concert_hall', $dto->placeType);
        $this->assertEquals("{$futureDate} 19:00:00", $dto->startAt->format('Y-m-d H:i:s'));
        $this->assertEquals("{$futureDate} 21:00:00", $dto->endAt->format('Y-m-d H:i:s'));
        $this->assertEquals('https://visitjurmala.lv/images/events/test_s1.jpg', $dto->imageUrl);
        $this->assertEquals("vj-{$futureDate}-10435", $dto->sourceExternalId);
        $this->assertContains('Mūzika', $dto->categoryNames);
        $this->assertEquals('concert', $dto->entertainmentType);
        $this->assertFalse($dto->isFree);
        $this->assertEquals(25.0, $dto->priceMin);
    }

    public function test_visitjurmala_event_ingestion(): void
    {
        $source = Source::create([
            'name' => 'Visit Jūrmala',
            'slug' => 'visitjurmala',
            'url' => 'https://visitjurmala.lv/lv/pasakumi/',
            'scraper_class' => VisitJurmalaScraper::class,
            'is_active' => true,
        ]);

        $dto = new ScrapedEventDTO(
            title: 'Izrāde “OPĀLU MĪL VISI” | Jūrmalas teātris',
            startAt: Carbon::create(2026, 11, 20, 16, 0, 0, 'Europe/Riga'),
            endAt: Carbon::create(2026, 11, 20, 18, 0, 0, 'Europe/Riga'),
            description: 'Izrādes centrā ir vientuļā un labsirdīgā krāmu vācēja un tirgotāja Opāla Kronkija.',
            shortDescription: 'Izrāde Jūrmalas teātrī.',
            venueName: 'Jūrmalas teātris',
            city: 'Jūrmala',
            region: 'Rīga un Pierīga',
            address: 'Muižas iela 7, Jūrmala, LV-2010',
            latitude: 56.9839,
            longitude: 23.8560,
            placeType: 'theater',
            categoryNames: ['Teātris'],
            entertainmentType: 'performance',
            isFree: false,
            imageUrl: 'https://visitjurmala.lv/images/events/opals.jpg',
            sourceUrl: 'https://visitjurmala.lv/lv/pasakumi/izrade-opalu-mil-visi/',
            sourceExternalId: 'vj-2026-11-20-10546',
            locale: 'lv',
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_external_id', 'vj-2026-11-20-10546')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Izrāde “OPĀLU MĪL VISI” | Jūrmalas teātris', $event->title);
        $this->assertEquals('visitjurmala.lv', $event->origin_host);
        $this->assertEquals('performance', $event->entertainment_type);
        $this->assertEquals('Jūrmala', $event->location->city);
        $this->assertEquals('Rīga un Pierīga', $event->location->region);
    }
}
