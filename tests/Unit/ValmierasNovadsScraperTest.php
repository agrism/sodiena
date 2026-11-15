<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\ValmierasNovadsScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValmierasNovadsScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata(): void
    {
        $scraper = new ValmierasNovadsScraper();
        $this->assertEquals('valmieras-novads', $scraper->getSlug());
        $this->assertEquals('Valmieras novada pašvaldība', $scraper->getName());
    }

    public function test_parse_dates_single_day(): void
    {
        $scraper = new ValmierasNovadsScraper();

        [$startAt, $endAt] = $scraper->parseDates('2026-12-03', '2026-12-03', '19:00', '<p>Izrāde Valmieras Kultūras centrā.</p>');

        $this->assertNotNull($startAt);
        $this->assertNull($endAt);
        $this->assertEquals('2026-12-03 19:00:00', $startAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_single_day_with_end_time_in_content(): void
    {
        $scraper = new ValmierasNovadsScraper();
        $content = '<p>Sestdien, 19. decembrī, no plkst. 11.00–15.00 notiks ziemas saulgriežu svinības un tirdziņš.</p>';

        [$startAt, $endAt] = $scraper->parseDates('2026-12-19', '2026-12-19', '11:00', $content);

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-12-19 11:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-12-19 15:00:00', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_multi_day(): void
    {
        $scraper = new ValmierasNovadsScraper();

        [$startAt, $endAt] = $scraper->parseDates('2026-11-13', '2026-11-18', '', '<p>Gaismas stāsti Mazsalacā.</p>');

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-11-13 00:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-18 23:59:59', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_location_resolving_known_venues(): void
    {
        $scraper = new ValmierasNovadsScraper();

        // 1. Valmieras Kultūras centrs
        $loc1 = $scraper->resolveLocationMeta('Valmieras Kultūras centrā');
        $this->assertEquals('Valmieras Kultūras centrs', $loc1['name']);
        $this->assertEquals('Valmiera', $loc1['city']);
        $this->assertEquals('Vidzeme', $loc1['region']);
        $this->assertEquals('culture_centre', $loc1['place_type']);
        $this->assertEquals(57.5385, $loc1['lat']);

        // 2. Kultūrtelpa Akustika
        $loc2 = $scraper->resolveLocationMeta('kultūrtelpā "Akustika"');
        $this->assertEquals('Kultūrtelpa "Akustika"', $loc2['name']);
        $this->assertEquals('Valmiera', $loc2['city']);
        $this->assertEquals('culture_centre', $loc2['place_type']);

        // 3. 3D Cinema / Kino Gaisma
        $loc3 = $scraper->resolveLocationMeta('3D Cinema Valmierā');
        $this->assertEquals('3D Cinema Valmierā', $loc3['name']);
        $this->assertEquals('cinema', $loc3['place_type']);

        // 4. Valmieras Drāmas teātris
        $loc4 = $scraper->resolveLocationMeta('Valmieras teātrī');
        $this->assertEquals('Valmieras teātris', $loc4['name']);
        $this->assertEquals('theater', $loc4['place_type']);

        // 5. Valmieras muzejs
        $loc5 = $scraper->resolveLocationMeta('Valmieras muzejā, Bruņinieku iela 1');
        $this->assertEquals('Valmieras muzejs', $loc5['name']);
        $this->assertEquals('museum', $loc5['place_type']);

        // 6. Mazsalacas Kultūras centrs
        $loc6 = $scraper->resolveLocationMeta('Mazsalacas Kultūras centrā');
        $this->assertEquals('Mazsalacas Kultūras centrs', $loc6['name']);
        $this->assertEquals('Mazsalaca', $loc6['city']);

        // 7. Valmiermuižas alus darītava
        $loc7 = $scraper->resolveLocationMeta('Valmiermuižas alus viesistabā');
        $this->assertEquals('Valmiermuižas pils parks un alus darītava', $loc7['name']);
        $this->assertEquals('Valmiermuiža', $loc7['city']);

        // 8. Unknown location with town name
        $loc8 = $scraper->resolveLocationMeta('Rūjienas ielā 1, Mazsalacā');
        $this->assertEquals('Mazsalaca', $loc8['city']);
        $this->assertEquals('Vidzeme', $loc8['region']);
    }

    public function test_resolve_categories_strict_cinema_rules(): void
    {
        $scraper = new ValmierasNovadsScraper();

        // STRICT AGENTS.MD RULE: Any event with filma or kinoseanss MUST be Kino and NEVER Teātris!
        $cats1 = $scraper->resolveCategories('Dokumentālā filma par Valmieru', 'Filmas seanss un diskusija', 'Valmieras Kultūras centrs');
        $this->assertContains('Kino', $cats1);
        $this->assertNotContains('Teātris', $cats1);
        $this->assertEquals('movie', $scraper->resolveEntertainmentType($cats1, 'Dokumentālā filma', 'Filmas seanss'));

        // Kinoteātris contains "teātr", but MUST NOT be classified as Teātris
        $cats2 = $scraper->resolveCategories('Kinoseanss bērniem', 'Filmas demonstrēšana kinoteātrī', 'Kinoteātris');
        $this->assertContains('Kino', $cats2);
        $this->assertNotContains('Teātris', $cats2);

        // 3D Cinema venue
        $cats3 = $scraper->resolveCategories('Jaunākās filmas pirmizrāde', 'Pirmizrāde uz lielā ekrāna', '3D Cinema Valmierā');
        $this->assertContains('Kino', $cats3);
        $this->assertNotContains('Teātris', $cats3);
    }

    public function test_resolve_categories_theatre_music_sports_seminars(): void
    {
        $scraper = new ValmierasNovadsScraper();

        // 1. Theatre
        $cats1 = $scraper->resolveCategories('Ufo jubilejas izrāde “Ufo 60 nokrāsas”', 'Humora izrāde un joki', 'Valmieras Kultūras centrs');
        $this->assertContains('Teātris', $cats1);
        $this->assertEquals('performance', $scraper->resolveEntertainmentType($cats1, 'Ufo jubilejas izrāde', 'Humora izrāde'));

        // 2. Music
        $cats2 = $scraper->resolveCategories('Vestards Šimkus koncerts "Ferenca Lista romantiskā klaviermūzika"', 'Klaviermūzikas vakars', 'Kultūrtelpa "Akustika"');
        $this->assertContains('Mūzika', $cats2);
        $this->assertEquals('concert', $scraper->resolveEntertainmentType($cats2, 'Vestards Šimkus koncerts', 'Klaviermūzikas vakars'));

        // 3. Sports
        $cats3 = $scraper->resolveCategories('Novusa turnīrs "Dikļu kauss novusā"', 'Novusa sacensības Dikļu Kultūras centrā', 'Dikļu Kultūras centrs');
        $this->assertContains('Sports', $cats3);
        $this->assertEquals('sports', $scraper->resolveEntertainmentType($cats3, 'Novusa turnīrs', 'Sacensības'));

        // 4. Seminars
        $cats4 = $scraper->resolveCategories('Meistarklase "Sveču liešana"', 'Praktiska nodarbība un meistarklase', 'Sēļu muiža');
        $this->assertContains('Semināri', $cats4);
        $this->assertEquals('education', $scraper->resolveEntertainmentType($cats4, 'Meistarklase', 'Nodarbība'));

        // 5. Celebrations & Markets
        $cats5 = $scraper->resolveCategories('Ziemas saulgriežu svinības un tirdziņš Valmiermuižā', 'Gadatirgus un svētku programma', 'Valmiermuiža');
        $this->assertContains('Svētki', $cats5);
        $this->assertEquals('chill', $scraper->resolveEntertainmentType($cats5, 'Ziemas saulgriežu svinības', 'Gadatirgus'));
    }

    public function test_parse_price(): void
    {
        $scraper = new ValmierasNovadsScraper();

        // 1. Paid event
        $price1 = $scraper->parsePrice('Ieejas maksa 5 EUR. Biļetes iepriekšpārdošanā.');
        $this->assertFalse($price1['is_free']);
        $this->assertEquals(5.0, $price1['price_min']);
        $this->assertEquals(5.0, $price1['price_max']);

        // 2. Free event
        $price2 = $scraper->parsePrice('Ieeja pasākumā bez maksas. Visi mīļi gaidīti!');
        $this->assertTrue($price2['is_free']);
        $this->assertEquals(0.0, $price2['price_min']);
        $this->assertEquals(0.0, $price2['price_max']);
    }

    public function test_scrape_mock_json_and_html(): void
    {
        $mockHtml = <<<'HTML'
<!DOCTYPE html>
<html>
<body>
    <div class="posters-list-img">
        <figure>
            <img src="https://www.valmierasnovads.lv/content/uploads/2026/11/koncerts-valmiera.png" alt="Koncerts Valmieras Kultūras centrā" />
        </figure>
    </div>
</body>
</html>
HTML;

        $mockJson = json_encode([
            [
                'summary' => 'Koncerts Valmieras Kultūras centrā',
                'content' => '<p>19. decembrī plkst. 18.00 Valmieras Kultūras centrā notiks skaists svētku koncerts. Biļešu cena: 10 EUR. Biļetes: <a href="https://www.bilesuparadize.lv/lv/event/999999">Biļešu paradīze</a></p>',
                'startDate' => '2026-12-19',
                'endDate' => '2026-12-19',
                'link' => 'https://www.valmierasnovads.lv/events/koncerts-valmieras-kulturas-centra/',
                'timeFrom' => '18:00',
                'place' => 'Valmieras Kultūras centrā',
            ],
        ]);

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $mockHtml),
            new Response(200, ['Content-Type' => 'application/json'], $mockJson),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $scraper = new ValmierasNovadsScraper();
        $scraper->setClient($client);

        $source = new Source([
            'name' => 'Valmieras novada pašvaldība',
            'slug' => 'valmieras-novads',
            'url' => 'https://www.valmierasnovads.lv/aktualitates/norisu-kalendars/',
            'scraper_class' => ValmierasNovadsScraper::class,
            'is_active' => true,
        ]);

        $events = $scraper->scrape($source);

        $this->assertCount(1, $events);
        $dto = $events->first();

        $this->assertEquals('Koncerts Valmieras Kultūras centrā', $dto->title);
        $this->assertEquals('Valmieras Kultūras centrs', $dto->venueName);
        $this->assertEquals('Valmiera', $dto->city);
        $this->assertEquals('Vidzeme', $dto->region);
        $this->assertEquals('culture_centre', $dto->placeType);
        $this->assertEquals('2026-12-19 18:00:00', $dto->startAt->format('Y-m-d H:i:s'));
        $this->assertNull($dto->endAt);
        $this->assertEquals('https://www.valmierasnovads.lv/content/uploads/2026/11/koncerts-valmiera.png', $dto->imageUrl);
        $this->assertEquals('https://www.bilesuparadize.lv/lv/event/999999', $dto->ticketUrl);
        $this->assertEquals('valmiera-koncerts-valmieras-kulturas-centra-2026-12-19', $dto->sourceExternalId);
        $this->assertContains('Mūzika', $dto->categoryNames);
        $this->assertEquals('concert', $dto->entertainmentType);
        $this->assertFalse($dto->isFree);
        $this->assertEquals(10.0, $dto->priceMin);
    }

    public function test_valmieras_novads_event_ingestion(): void
    {
        $source = Source::create([
            'name' => 'Valmieras novada pašvaldība',
            'slug' => 'valmieras-novads',
            'url' => 'https://www.valmierasnovads.lv/aktualitates/norisu-kalendars/',
            'scraper_class' => ValmierasNovadsScraper::class,
            'is_active' => true,
        ]);

        $dto = new ScrapedEventDTO(
            title: 'Ufo jubilejas izrāde “Ufo 60 nokrāsas”',
            startAt: Carbon::create(2026, 12, 3, 19, 0, 0, 'Europe/Riga'),
            endAt: null,
            description: 'Valmieras Kultūras centrā humora meistars un radio personība Kaspars Upacieris jeb Ufo.',
            shortDescription: 'Humora izrāde Valmierā.',
            venueName: 'Valmieras Kultūras centrs',
            city: 'Valmiera',
            region: 'Vidzeme',
            address: 'Rīgas iela 10, Valmiera, LV-4201',
            latitude: 57.5385,
            longitude: 25.4260,
            placeType: 'culture_centre',
            categoryNames: ['Teātris'],
            entertainmentType: 'performance',
            isFree: false,
            imageUrl: 'https://www.valmierasnovads.lv/content/uploads/ufo.png',
            sourceUrl: 'https://www.valmierasnovads.lv/events/ufo-jubilejas-izrade/',
            sourceExternalId: 'valmiera-ufo-jubilejas-izrade-2026-12-03',
            locale: 'lv',
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_external_id', 'valmiera-ufo-jubilejas-izrade-2026-12-03')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Ufo jubilejas izrāde “Ufo 60 nokrāsas”', $event->title);
        $this->assertEquals('valmierasnovads.lv', $event->origin_host);
        $this->assertEquals('performance', $event->entertainment_type);
        $this->assertEquals('Valmiera', $event->location->city);
        $this->assertEquals('Vidzeme', $event->location->region);
    }
}
