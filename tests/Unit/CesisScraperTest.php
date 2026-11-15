<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\CesisScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CesisScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata(): void
    {
        $scraper = new CesisScraper();
        $this->assertEquals('cesis', $scraper->getSlug());
        $this->assertEquals('Pasākumi Cēsīs', $scraper->getName());
    }

    public function test_parse_dates_single_date_with_time(): void
    {
        $scraper = new CesisScraper();
        $today = Carbon::create(2026, 11, 15, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('15.11.2026', '15:00', $today);

        $this->assertNotNull($startAt);
        $this->assertNull($endAt);
        $this->assertEquals('2026-11-15 15:00:00', $startAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_single_date_with_time_range(): void
    {
        $scraper = new CesisScraper();
        $today = Carbon::create(2026, 11, 15, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('15.11.2026', '11:00 - 15:00', $today);

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-11-15 11:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-15 15:00:00', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_date_range_without_time(): void
    {
        $scraper = new CesisScraper();
        $today = Carbon::create(2026, 11, 1, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('25.09.2026 - 15.11.2026', '', $today);

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-09-25 00:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-15 23:59:59', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_multi_day_with_time_range(): void
    {
        $scraper = new CesisScraper();
        $today = Carbon::create(2026, 11, 15, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('21.11.2026 - 22.11.2026', '18:00 - 22:00', $today);

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-11-21 18:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-22 22:00:00', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_leading_dash(): void
    {
        $scraper = new CesisScraper();
        $today = Carbon::create(2026, 11, 15, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('- 21.11.2026', '', $today);

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-11-15 00:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-21 23:59:59', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_location_resolving_known_venues(): void
    {
        $scraper = new CesisScraper();

        // 1. Koncertzāle "Cēsis"
        $loc1 = $scraper->resolveLocation('Koncertzāle "Cēsis"');
        $this->assertEquals('Koncertzāle "Cēsis"', $loc1['name']);
        $this->assertEquals('Cēsis', $loc1['city']);
        $this->assertEquals('Vidzeme', $loc1['region']);
        $this->assertEquals('concert_hall', $loc1['place_type']);
        $this->assertEquals(57.3134, $loc1['lat']);

        // 2. Radošais kvartāls "Zeit"
        $loc2 = $scraper->resolveLocation('Radošais kvartāls "Zeit"');
        $this->assertEquals('Radošais kvartāls "Zeit"', $loc2['name']);
        $this->assertEquals('Līgatne', $loc2['city']);
        $this->assertEquals('Vidzeme', $loc2['region']);

        // 3. Straupes zirgu pasts
        $loc3 = $scraper->resolveLocation('Straupes zirgu pasts');
        $this->assertEquals('Straupes zirgu pasts', $loc3['name']);
        $this->assertEquals('Straupe', $loc3['city']);
        $this->assertEquals('landmark', $loc3['place_type']);

        // 4. Liepas Kultūras nams
        $loc4 = $scraper->resolveLocation('Liepas Kultūras nams');
        $this->assertEquals('Liepas Kultūras nams', $loc4['name']);
        $this->assertEquals('Liepa', $loc4['city']);

        // 5. Priekuļu Kultūras nams
        $loc5 = $scraper->resolveLocation('Priekuļu Kultūras nams');
        $this->assertEquals('Priekuļu Kultūras nams', $loc5['name']);
        $this->assertEquals('Priekuļi', $loc5['city']);

        // 6. Jaunpiebalgas Kultūras centrs
        $loc6 = $scraper->resolveLocation('Jaunpiebalgas Kultūras centrs');
        $this->assertEquals('Jaunpiebalgas Kultūras centrs', $loc6['name']);
        $this->assertEquals('Jaunpiebalga', $loc6['city']);

        // 7. Cēsu pils
        $loc7 = $scraper->resolveLocation('Cēsu pils');
        $this->assertEquals('Cēsu pils', $loc7['name']);
        $this->assertEquals('castle', $loc7['place_type']);
    }

    public function test_agents_rule_cinema_vs_theatre_categorization(): void
    {
        $scraper = new CesisScraper();

        // 1. Cinema / Kino strict rule: ANY event with filma/kinoseanss is ALWAYS Kino and NEVER Teātris!
        $cats1 = $scraper->resolveCategories('Režisora Andreja Ēķa filma “Tumšā robeža”', 'Picērija "Muiža"');
        $this->assertEquals(['Kino'], $cats1);
        $this->assertEquals('movie', $scraper->resolveEntertainmentType($cats1, 'Režisora Andreja Ēķa filma “Tumšā robeža”', ''));

        // 2. Documentary film
        $cats2 = $scraper->resolveCategories('Dokumentālā filma par leģendāro alpīnistu Teodoru Ķirsi', 'Koncertzāle "Cēsis"');
        $this->assertEquals(['Kino'], $cats2);
        $this->assertEquals('movie', $scraper->resolveEntertainmentType($cats2, 'Dokumentālā filma', ''));

        // 3. kinoteātris contains substring "teātr", but MUST NEVER be classified as Teātris
        $cats3 = $scraper->resolveCategories('Jaunā animācijas filma', 'Kinoteātris Cēsis');
        $this->assertEquals(['Kino'], $cats3);
        $this->assertEquals('movie', $scraper->resolveEntertainmentType($cats3, 'Animācijas filma', ''));

        // 4. Theater: production with izrāde
        $cats4 = $scraper->resolveCategories('Jaunpiebalgas amatierteātra "Triksteri" izrāde "Bārenīte un mātes meita"', 'Taurenes Kultūras nams');
        $this->assertEquals(['Teātris'], $cats4);
        $this->assertEquals('performance', $scraper->resolveEntertainmentType($cats4, 'Izrāde', ''));

        // 5. Theater: komēdija
        $cats5 = $scraper->resolveCategories('Komēdija "Visiem vajag naudu"', 'Stalbes Tautas nams');
        $this->assertEquals(['Teātris'], $cats5);
        $this->assertEquals('performance', $scraper->resolveEntertainmentType($cats5, 'Komēdija', ''));

        // 6. Theater for children: izrāde bērniem
        $cats6 = $scraper->resolveCategories('Izrāde bērniem “Lāstekas jociņi”', 'Veselavas muiža');
        $this->assertContains('Teātris', $cats6);
        $this->assertContains('Bērniem', $cats6);
        $this->assertEquals('performance', $scraper->resolveEntertainmentType($cats6, 'Izrāde bērniem', ''));

        // 7. Exhibition: gleznu izstāde
        $cats7 = $scraper->resolveCategories('Valdas Bomikas netradicionālo gleznu izstāde', 'Kultūras biedrība "Harmonija"');
        $this->assertEquals(['Izstādes'], $cats7);
        $this->assertEquals('exhibition', $scraper->resolveEntertainmentType($cats7, 'Gleznu izstāde', ''));

        // 8. Workshop: meistarklase
        $cats8 = $scraper->resolveCategories('Ziemassvētku eglīšu rotājumu meistarklase', 'Līgatnes Amatu māja');
        $this->assertEquals(['Semināri'], $cats8);
        $this->assertEquals('education', $scraper->resolveEntertainmentType($cats8, 'Meistarklase', ''));

        // 9. Fallback: Cits
        $cats9 = $scraper->resolveCategories('Svinīgā ziedu nolikšana Cēsīs', 'Vienības laukums');
        $this->assertEquals(['Cits'], $cats9);
        $this->assertEquals('other', $scraper->resolveEntertainmentType($cats9, 'Ziedu nolikšana', ''));
    }

    public function test_parse_price(): void
    {
        $scraper = new CesisScraper();

        $free1 = $scraper->parsePrice('Bez maksas');
        $this->assertTrue($free1['is_free']);
        $this->assertEquals(0.0, $free1['price_min']);

        $free2 = $scraper->parsePrice('Ieeja bez maksas');
        $this->assertTrue($free2['is_free']);

        $paid1 = $scraper->parsePrice('15€ - 30€');
        $this->assertFalse($paid1['is_free']);
        $this->assertEquals(15.0, $paid1['price_min']);
        $this->assertEquals(30.0, $paid1['price_max']);

        $paid2 = $scraper->parsePrice('3€');
        $this->assertFalse($paid2['is_free']);
        $this->assertEquals(3.0, $paid2['price_min']);
        $this->assertEquals(3.0, $paid2['price_max']);

        $empty = $scraper->parsePrice('');
        $this->assertFalse($empty['is_free']);
        $this->assertNull($empty['price_min']);
    }

    public function test_scrape_mock_html(): void
    {
        $mockHtml = <<<'HTML'
<!DOCTYPE html>
<html lang="lv">
<body>
    <div class="owl-carousel">
        <div class="event-thumbnail">
            <a href="https://pasakumi.cesis.lv/pasakums/latvijas-valsts-svetku-koncerts-es-esmu-sava-vieta/">
                <div class="event-poster" style="background-image:url('https://pasakumi.cesis.lv/wp-content/uploads/2026/10/1811bilde.png')">
                    <span class="date">18.11.2026</span>
                </div>
                <h3>Latvijas valsts svētku koncerts "Es esmu savā vietā"</h3>
                <p>Koncertu caurvij stāsts par latvieti, kurš joprojām sēj un pļauj rudzus.</p>
                <span class="event-location">Koncertzāle "Cēsis"</span>
            </a>
        </div>
    </div>
    <div class="filter-results-container">
        <div class="row results-event">
            <div class="col-md-1 col-sm-2 col-xs-4"><span class="date">18.11.2026</span></div>
            <div class="col-md-1 col-sm-1 col-xs-6"><span class="time">17:00</span></div>
            <div class="col-md-5 col-sm-4 col-xs-12"><a class="title" href="https://pasakumi.cesis.lv/pasakums/latvijas-valsts-svetku-koncerts-es-esmu-sava-vieta/">Latvijas valsts svētku koncerts "Es esmu savā vietā"</a></div>
            <div class="col-md-3 col-sm-3 col-xs-12"><span class="location">Koncertzāle "Cēsis"</span></div>
            <div class="col-md-1 col-sm-1 col-xs-6 price-wrap"><span class="price">15€ - 30€</span></div>
            <div class="col-md-1 col-sm-1 col-xs-6 buy-wrap"><a target="_blank" href="https://www.bilesuparadize.lv/lv/event/170001" class="buy-button">Pirkt</a></div>
        </div>
        <div class="row results-event">
            <div class="col-md-1 col-sm-2 col-xs-4"><span class="date">20.11.2026</span></div>
            <div class="col-md-1 col-sm-1 col-xs-6"><span class="time">19:00</span></div>
            <div class="col-md-5 col-sm-4 col-xs-12"><a class="title" href="https://pasakumi.cesis.lv/pasakums/filma-tumsas-robeza/">Režisora Andreja Ēķa filma “Tumšā robeža”</a></div>
            <div class="col-md-3 col-sm-3 col-xs-12"><span class="location">Picērija "Muiža"</span></div>
            <div class="col-md-1 col-sm-1 col-xs-6 price-wrap"><span class="price">Bez maksas</span></div>
            <div class="col-md-1 col-sm-1 col-xs-6 buy-wrap"></div>
        </div>
    </div>
</body>
</html>
HTML;

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $mockHtml),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $scraper = new CesisScraper();
        $scraper->setClient($client);

        $source = new Source([
            'name' => 'Pasākumi Cēsīs',
            'slug' => 'cesis',
            'url' => 'https://pasakumi.cesis.lv/',
            'scraper_class' => CesisScraper::class,
            'is_active' => true,
        ]);

        $events = $scraper->scrape($source);

        $this->assertCount(2, $events);

        // Event 1: Concert in Koncertzāle "Cēsis"
        $dto1 = $events[0];
        $this->assertEquals('Latvijas valsts svētku koncerts "Es esmu savā vietā"', $dto1->title);
        $this->assertEquals('cesis-latvijas-valsts-svetku-koncerts-es-esmu-sava-vieta', $dto1->sourceExternalId);
        $this->assertEquals('https://pasakumi.cesis.lv/pasakums/latvijas-valsts-svetku-koncerts-es-esmu-sava-vieta/', $dto1->sourceUrl);
        $this->assertEquals('https://pasakumi.cesis.lv/wp-content/uploads/2026/10/1811bilde.png', $dto1->imageUrl);
        $this->assertEquals('Koncertzāle "Cēsis"', $dto1->venueName);
        $this->assertEquals('Cēsis', $dto1->city);
        $this->assertEquals('Vidzeme', $dto1->region);
        $this->assertEquals('2026-11-18 17:00:00', $dto1->startAt->format('Y-m-d H:i:s'));
        $this->assertNull($dto1->endAt);
        $this->assertContains('Mūzika', $dto1->categoryNames);
        $this->assertContains('Svētki', $dto1->categoryNames);
        $this->assertEquals('concert', $dto1->entertainmentType);
        $this->assertFalse($dto1->isFree);
        $this->assertEquals(15.0, $dto1->priceMin);
        $this->assertEquals(30.0, $dto1->priceMax);
        $this->assertEquals('https://www.bilesuparadize.lv/lv/event/170001', $dto1->ticketUrl);

        // Event 2: Cinema screening
        $dto2 = $events[1];
        $this->assertEquals('Režisora Andreja Ēķa filma “Tumšā robeža”', $dto2->title);
        $this->assertEquals('cesis-filma-tumsas-robeza', $dto2->sourceExternalId);
        $this->assertEquals('Picērija "Muiža"', $dto2->venueName);
        $this->assertEquals('Cēsis', $dto2->city);
        $this->assertEquals('2026-11-20 19:00:00', $dto2->startAt->format('Y-m-d H:i:s'));
        $this->assertEquals(['Kino'], $dto2->categoryNames);
        $this->assertEquals('movie', $dto2->entertainmentType);
        $this->assertTrue($dto2->isFree);
    }

    public function test_cesis_event_ingestion(): void
    {
        $source = Source::create([
            'name' => 'Pasākumi Cēsīs',
            'slug' => 'cesis',
            'url' => 'https://pasakumi.cesis.lv/',
            'scraper_class' => CesisScraper::class,
            'is_active' => true,
        ]);

        $dto = new ScrapedEventDTO(
            title: 'Kaspara Zemīša solokoncerts',
            startAt: Carbon::create(2026, 11, 20, 19, 0, 0, 'Europe/Riga'),
            endAt: null,
            description: 'Lai mūzika palīdz sajust Latvijas svētku laika īpašo noskaņu!',
            shortDescription: 'Solokoncerts Zeit kvartālā.',
            venueName: 'Radošais kvartāls "Zeit"',
            city: 'Līgatne',
            region: 'Vidzeme',
            address: 'Gaujas iela 4, Līgatne, LV-4110',
            latitude: 57.2348,
            longitude: 25.0415,
            placeType: 'cultural_center',
            categoryNames: ['Mūzika'],
            entertainmentType: 'concert',
            isFree: false,
            priceMin: 20.0,
            priceMax: 20.0,
            imageUrl: 'https://pasakumi.cesis.lv/wp-content/uploads/2026/10/zemitis.jpg',
            sourceUrl: 'https://pasakumi.cesis.lv/pasakums/kaspara-zemisa-solokoncerts/',
            sourceExternalId: 'cesis-kaspara-zemisa-solokoncerts',
            locale: 'lv',
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_external_id', 'cesis-kaspara-zemisa-solokoncerts')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Kaspara Zemīša solokoncerts', $event->title);
        $this->assertEquals('pasakumi.cesis.lv', $event->origin_host);
        $this->assertEquals('concert', $event->entertainment_type);
        $this->assertEquals('Līgatne', $event->location->city);
        $this->assertEquals('Vidzeme', $event->location->region);
        $this->assertFalse($event->is_free);
    }
}
