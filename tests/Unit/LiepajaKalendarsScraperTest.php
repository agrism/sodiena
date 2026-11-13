<?php

namespace Tests\Unit;

use App\Enums\SourceType;
use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\LiepajaKalendarsScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiepajaKalendarsScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata(): void
    {
        $scraper = new LiepajaKalendarsScraper();
        $this->assertEquals('liepaja-kalendars', $scraper->getSlug());
        $this->assertEquals('Liepājas pasākumu kalendārs', $scraper->getName());
    }

    public function test_parse_dates_single_event_with_time_range(): void
    {
        $scraper = new LiepajaKalendarsScraper();

        [$startAt, $endAt] = $scraper->parseDates('13.11.2026. 21.00–23.59');

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-11-13 21:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-13 23:59:00', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_single_event_single_time(): void
    {
        $scraper = new LiepajaKalendarsScraper();

        [$startAt, $endAt] = $scraper->parseDates('14.11.2026. 18.00');

        $this->assertNotNull($startAt);
        $this->assertNull($endAt);
        $this->assertEquals('2026-11-14 18:00:00', $startAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_crossing_midnight(): void
    {
        $scraper = new LiepajaKalendarsScraper();

        [$startAt, $endAt] = $scraper->parseDates('14.11.2026. 22.00–02.00');

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-11-14 22:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-15 02:00:00', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_same_start_and_end_time(): void
    {
        $scraper = new LiepajaKalendarsScraper();

        [$startAt, $endAt] = $scraper->parseDates('21.11.2026. 18.00–18.00');

        $this->assertNotNull($startAt);
        $this->assertNull($endAt);
        $this->assertEquals('2026-11-21 18:00:00', $startAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_multi_day_range(): void
    {
        $scraper = new LiepajaKalendarsScraper();

        [$startAt, $endAt] = $scraper->parseDates('01.12.2026.–15.12.2026.');

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-12-01 00:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-12-15 23:59:59', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_price(): void
    {
        $scraper = new LiepajaKalendarsScraper();

        // 1. Free
        [$isFree1, $min1, $max1] = $scraper->parsePrice('Bezmaksas');
        $this->assertTrue($isFree1);
        $this->assertEquals(0.0, $min1);
        $this->assertEquals(0.0, $max1);

        // 2. Price range
        [$isFree2, $min2, $max2] = $scraper->parsePrice('5–10 €');
        $this->assertFalse($isFree2);
        $this->assertEquals(5.0, $min2);
        $this->assertEquals(10.0, $max2);

        // 3. Single price with comma
        [$isFree3, $min3, $max3] = $scraper->parsePrice('12,00 €');
        $this->assertFalse($isFree3);
        $this->assertEquals(12.0, $min3);
        $this->assertEquals(12.0, $max3);

        // 4. Price range with decimal commas/dots
        [$isFree4, $min4, $max4] = $scraper->parsePrice('10,00–12.00 €');
        $this->assertFalse($isFree4);
        $this->assertEquals(10.0, $min4);
        $this->assertEquals(12.0, $max4);

        // 5. Empty
        [$isFree5, $min5, $max5] = $scraper->parsePrice('');
        $this->assertFalse($isFree5);
        $this->assertNull($min5);
        $this->assertNull($max5);
    }

    public function test_resolve_location_meta_known_venues(): void
    {
        $scraper = new LiepajaKalendarsScraper();

        // 1. Lielais dzintars
        $loc1 = $scraper->resolveLocationMeta('Koncertzāle "Lielais dzintars", Lielā zāle');
        $this->assertEquals('Koncertzāle "Lielais dzintars"', $loc1['name']);
        $this->assertEquals('Liepāja', $loc1['city']);
        $this->assertEquals('Kurzeme', $loc1['region']);
        $this->assertEquals('concert_hall', $loc1['place_type']);
        $this->assertEquals('Radio iela 8, Liepāja, LV-3401', $loc1['address']);

        // 2. Liepājas teātris
        $loc2 = $scraper->resolveLocationMeta('Liepājas teātris, Lielā zāle');
        $this->assertEquals('Liepājas teātris', $loc2['name']);
        $this->assertEquals('theatre', $loc2['place_type']);
        $this->assertEquals('Teātra iela 4, Liepāja, LV-3401', $loc2['address']);

        // 3. LOC Ledus halle
        $loc3 = $scraper->resolveLocationMeta('LOC Ledus halle');
        $this->assertEquals('LOC Ledus halle', $loc3['name']);
        $this->assertEquals('sports_complex', $loc3['place_type']);
        $this->assertEquals('Brīvības iela 1, Liepāja, LV-3401', $loc3['address']);

        // 4. Liepājas muzejs
        $loc4 = $scraper->resolveLocationMeta('Liepājas muzejs, lielā zāle');
        $this->assertEquals('Liepājas muzejs', $loc4['name']);
        $this->assertEquals('museum', $loc4['place_type']);
        $this->assertEquals('Kūrmājas prospekts 16/18, Liepāja, LV-3401', $loc4['address']);

        // 5. Fontaine Palace
        $loc5 = $scraper->resolveLocationMeta('Mūzikas klubs "Fontaine Palace"');
        $this->assertEquals('Mūzikas klubs "Fontaine Palace"', $loc5['name']);
        $this->assertEquals('club', $loc5['place_type']);
        $this->assertEquals('Dzirnavu iela 4, Liepāja, LV-3401', $loc5['address']);
    }

    public function test_resolve_location_meta_coordinates(): void
    {
        $scraper = new LiepajaKalendarsScraper();

        $loc = $scraper->resolveLocationMeta('Rožu laukums', '56.508103, 21.011585');
        $this->assertEquals('Rožu laukums', $loc['name']);
        $this->assertEquals('Liepāja', $loc['city']);
        $this->assertEquals('square', $loc['place_type']);
        $this->assertEquals(56.508103, $loc['latitude']);
        $this->assertEquals(21.011585, $loc['longitude']);
    }

    public function test_resolve_categories_strict_cinema_rules(): void
    {
        $scraper = new LiepajaKalendarsScraper();

        // 1. Film in title MUST be Kino and NEVER Teātris (AGENTS.md)
        $cats1 = $scraper->resolveCategories('Detektīvpiedzīvojumu filma visai ģimenei', 'Koncertzāle "Lielais dzintars"');
        $this->assertContains('Kino', $cats1);
        $this->assertNotContains('Teātris', $cats1);

        // 2. Cinamon venue MUST be Kino and NEVER Teātris (AGENTS.md)
        $cats2 = $scraper->resolveCategories('Jaunākais seanss', 'Kinoteātris "Cinamon Liepaja Balle"');
        $this->assertContains('Kino', $cats2);
        $this->assertNotContains('Teātris', $cats2);

        // 3. Spēlfilmas seanss MUST be Kino
        $cats3 = $scraper->resolveCategories('Spēlfilmas “Tumšā robeža” kinoseanss', 'Liepājas Latviešu biedrības nams');
        $this->assertContains('Kino', $cats3);
        $this->assertNotContains('Teātris', $cats3);

        // 4. "kinoteātris" contains "teātr", but MUST NEVER be classified as Teātris (AGENTS.md)
        $cats4 = $scraper->resolveCategories('Īpašs pasākums kinoteātrī', 'Kinoteātris "Cinamon"');
        $this->assertContains('Kino', $cats4);
        $this->assertNotContains('Teātris', $cats4);
    }

    public function test_resolve_categories_theatre_music_sports_exhibitions(): void
    {
        $scraper = new LiepajaKalendarsScraper();

        // 1. Theatre
        $cats1 = $scraper->resolveCategories('Prima Facie | Liepājas teātris', 'Koncertzāle "Lielais dzintars"');
        $this->assertContains('Teātris', $cats1);
        $this->assertEquals('theatre', $scraper->resolveEntertainmentType($cats1, 'Prima Facie | Liepājas teātris'));

        // 2. Music
        $cats2 = $scraper->resolveCategories('PND + ZŌNA Koncerts Fontaine Palace', 'Mūzikas klubs "Fontaine Palace"');
        $this->assertContains('Mūzika', $cats2);
        $this->assertEquals('concert', $scraper->resolveEntertainmentType($cats2, 'PND + ZŌNA Koncerts Fontaine Palace'));

        // 3. Sports
        $cats3 = $scraper->resolveCategories('Liepājas HK pret Energija', 'LOC Ledus halle');
        $this->assertContains('Sports', $cats3);
        $this->assertEquals('sports', $scraper->resolveEntertainmentType($cats3, 'Liepājas HK pret Energija'));

        // 4. Exhibitions
        $cats4 = $scraper->resolveCategories('Antras Grebes debijas romāna atvēršana un izstāde', 'Liepājas muzejs');
        $this->assertContains('Izstādes', $cats4);
        $this->assertEquals('exhibition', $scraper->resolveEntertainmentType($cats4, 'Antras Grebes debijas romāna atvēršana un izstāde'));

        // 5. Fallback
        $cats5 = $scraper->resolveCategories('Neidentificēts pasākums bez atslēgvārdiem', 'Kaut kur');
        $this->assertEquals(['Cits'], $cats5);
        $this->assertEquals('event', $scraper->resolveEntertainmentType($cats5, 'Neidentificēts pasākums'));
    }

    public function test_parse_detail_page_with_mocked_html(): void
    {
        $mockHtml = <<<'HTML'
<!DOCTYPE html>
<html>
<body>
    <div class="event-open">
        <h3>Prima Facie | Liepājas teātris</h3>
        <ul class="event-open-info-icons">
            <li>
                <img src="/images/calendar-icon.png">
                <div>13.11.2026. 19.00–20.55</div>
            </li>
            <li>
                <img src="/images/pin-icon.png">
                <div class="event-map-container">
                    Koncertzāle "Lielais dzintars", Eksperimentālā skatuve
                    <input type="hidden" value="Radio iela 8, Liepāja, Liepāja" id="address">
                </div>
            </li>
            <li>
                <img src="/images/ticket-icon.png">
                <div>25–30 €</div>
            </li>
            <li>
                <a href="https://www.bilesuparadize.lv/lv/event/177713" class="button">Iegādāties</a>
            </li>
        </ul>
        <div class="description">
            <p>Pēc S. Milleres lugas "Prima Facie" veidotā monoizrāde.</p>
        </div>
        <div class="images">
            <img src="https://liepajaskalendars.lv/images/events/50307/large_11a452e21f5fa73b1e06365418c4948d.jpg">
        </div>
        <ul class="event-info event-info-list">
            <li><span>Rīkotājs:</span> SIA "Liepājas teātris"</li>
            <li><span>Mājaslapa:</span> <a href="https://liepajasteatris.lv">Skatīt mājaslapu</a></li>
        </ul>
    </div>
</body>
</html>
HTML;

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $mockHtml),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mockHandler)]);

        $scraper = new LiepajaKalendarsScraper();
        $scraper->setClient($client);

        $today = Carbon::create(2026, 11, 10, 0, 0, 0, 'Europe/Riga');
        $dto = $scraper->parseDetailPage('https://kalendars.liepaja.lv/lv/2026-11-13:50307', $today);

        $this->assertNotNull($dto);
        $this->assertEquals('Prima Facie | Liepājas teātris', $dto->title);
        $this->assertEquals('2026-11-13 19:00:00', $dto->startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-13 20:55:00', $dto->endAt->format('Y-m-d H:i:s'));
        $this->assertEquals('Koncertzāle "Lielais dzintars"', $dto->venueName);
        $this->assertEquals('Radio iela 8, Liepāja, LV-3401', $dto->address);
        $this->assertEquals('Liepāja', $dto->city);
        $this->assertEquals('Kurzeme', $dto->region);
        $this->assertFalse($dto->isFree);
        $this->assertEquals(25.0, $dto->priceMin);
        $this->assertEquals(30.0, $dto->priceMax);
        $this->assertEquals('https://www.bilesuparadize.lv/lv/event/177713', $dto->ticketUrl);
        $this->assertEquals('https://liepajaskalendars.lv/images/events/50307/large_11a452e21f5fa73b1e06365418c4948d.jpg', $dto->imageUrl);
        $this->assertContains('Teātris', $dto->categoryNames);
        $this->assertEquals('theatre', $dto->entertainmentType);
        $this->assertEquals('SIA "Liepājas teātris"', $dto->rawData['organizer']);
        $this->assertEquals('liepaja-kalendars-50307-202611131900', $dto->sourceExternalId);
    }

    public function test_ingest_liepaja_event_into_database(): void
    {
        $source = Source::create([
            'name' => 'Liepājas pasākumu kalendārs',
            'slug' => 'liepaja-kalendars',
            'url' => 'https://kalendars.liepaja.lv/lv/',
            'scraper_class' => LiepajaKalendarsScraper::class,
            'type' => SourceType::MUNICIPALITY,
            'is_active' => true,
        ]);

        $dto = new ScrapedEventDTO(
            title: 'Prima Facie | Liepājas teātris',
            startAt: Carbon::create(2026, 11, 20, 19, 0, 0, 'Europe/Riga'),
            endAt: Carbon::create(2026, 11, 20, 21, 0, 0, 'Europe/Riga'),
            description: 'Monoizrāde ar Liepājas teātra aktieri',
            venueName: 'Koncertzāle "Lielais dzintars"',
            city: 'Liepāja',
            region: 'Kurzeme',
            address: 'Radio iela 8, Liepāja, LV-3401',
            placeType: 'concert_hall',
            categoryNames: ['Teātris'],
            entertainmentType: 'theatre',
            isFree: false,
            priceMin: 25.0,
            priceMax: 30.0,
            currency: 'EUR',
            ticketUrl: 'https://www.bilesuparadize.lv/lv/event/177713',
            imageUrl: 'https://liepajaskalendars.lv/images/events/50307/large.jpg',
            sourceUrl: 'https://kalendars.liepaja.lv/lv/2026-11-20:50307',
            sourceExternalId: 'liepaja-kalendars-50307-202611201900',
            locale: 'lv',
            rawData: ['organizer' => 'SIA "Liepājas teātris"']
        );

        $service = app(EventIngestionService::class);
        $result = $service->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $this->assertDatabaseHas('events', [
            'source_id' => $source->id,
            'source_external_id' => 'liepaja-kalendars-50307-202611201900',
            'title' => 'Prima Facie | Liepājas teātris',
        ]);

        $event = Event::where('source_external_id', 'liepaja-kalendars-50307-202611201900')->first();
        $this->assertNotNull($event);
        $this->assertEquals('theatre', $event->entertainment_type);
        $this->assertNotNull($event->location);
        $this->assertEquals('Liepāja', $event->location->city);
        $this->assertEquals('Kurzeme', $event->location->region);
    }
}
