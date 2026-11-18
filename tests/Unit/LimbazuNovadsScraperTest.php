<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Location;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\LimbazuNovadsScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LimbazuNovadsScraperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Category::firstOrCreate(['slug' => 'kino'], ['name' => 'Kino']);
        Category::firstOrCreate(['slug' => 'teatris'], ['name' => 'Teātris']);
        Category::firstOrCreate(['slug' => 'muzika'], ['name' => 'Mūzika']);
        Category::firstOrCreate(['slug' => 'izstades'], ['name' => 'Izstādes']);
        Category::firstOrCreate(['slug' => 'svetki'], ['name' => 'Svētki']);
        Category::firstOrCreate(['slug' => 'berniem'], ['name' => 'Bērniem']);
        Category::firstOrCreate(['slug' => 'citi'], ['name' => 'Cits']);
    }

    public function test_metadata(): void
    {
        $scraper = new LimbazuNovadsScraper();
        $this->assertEquals('limbazu-novads', $scraper->getSlug());
        $this->assertEquals('Limbažu novada pašvaldība', $scraper->getName());
    }

    public function test_date_range_parsing(): void
    {
        $scraper = new LimbazuNovadsScraper();

        // 1. Single day with range
        $d1 = $scraper->parseDateRange('19. Novembris 17:00 - 18:30');
        $this->assertNotNull($d1);
        $this->assertEquals(19, $d1['start_at']->day);
        $this->assertEquals(11, $d1['start_at']->month);
        $this->assertEquals(17, $d1['start_at']->hour);
        $this->assertEquals(0, $d1['start_at']->minute);
        $this->assertEquals(18, $d1['end_at']->hour);
        $this->assertEquals(30, $d1['end_at']->minute);

        // 2. Single day with start only
        $d2 = $scraper->parseDateRange('21. Novembris 10:30');
        $this->assertNotNull($d2);
        $this->assertEquals(21, $d2['start_at']->day);
        $this->assertEquals(10, $d2['start_at']->hour);
        $this->assertNull($d2['end_at']);

        // 3. Multi-day range
        $d3 = $scraper->parseDateRange('19. Decembris 17:00 - 20. Decembris 21:00');
        $this->assertNotNull($d3);
        $this->assertEquals(19, $d3['start_at']->day);
        $this->assertEquals(12, $d3['start_at']->month);
        $this->assertEquals(17, $d3['start_at']->hour);
        $this->assertEquals(20, $d3['end_at']->day);
        $this->assertEquals(12, $d3['end_at']->month);
        $this->assertEquals(21, $d3['end_at']->hour);

        // 4. Empty or invalid
        $this->assertNull($scraper->parseDateRange(''));
    }

    public function test_location_resolving(): void
    {
        $scraper = new LimbazuNovadsScraper();

        // 1. Limbažu kultūras nams
        $loc1 = $scraper->resolveLocation('14. Jampadrača deju svētki "MŪS VIENO DEJA" Limbažos', 'Pasākums notiks Limbažu kultūras namā');
        $this->assertEquals('Limbažu Kultūras nams', $loc1['venue_name']);
        $this->assertEquals('Limbaži', $loc1['city']);
        $this->assertEquals('Vidzeme', $loc1['region']);
        $this->assertEquals('cultural_center', $loc1['place_type']);

        // 2. Salacgrīva
        $loc2 = $scraper->resolveLocation('Koncerts “Džezs un Ziemassvētki” Salacgrīvā', 'Salacgrīvas kultūras namā');
        $this->assertEquals('Salacgrīvas Kultūras nams', $loc2['venue_name']);
        $this->assertEquals('Salacgrīva', $loc2['city']);

        // 3. Vidriži
        $loc3 = $scraper->resolveLocation('Filma "Tumšā robeža" un tikšanās ar aktieri Jēkabu Reini Vidrižos', 'Sporta un kultūras centrs "Vidriži"');
        $this->assertEquals('Sporta un kultūras centrs "Vidriži"', $loc3['venue_name']);
        $this->assertEquals('Vidriži', $loc3['city']);

        // 4. Minhauzena muzejs Duntē
        $loc4 = $scraper->resolveLocation('Zigfrīda Muktupāvela koncerts Minhauzena muzejā', 'Duntes muižā');
        $this->assertEquals('Minhauzena muzejs', $loc4['venue_name']);
        $this->assertEquals('Dunte', $loc4['city']);
        $this->assertEquals('museum', $loc4['place_type']);

        // 5. Puikule
        $loc5 = $scraper->resolveLocation('Muižas balle Puikulē', 'Visi mīļi gaidīti');
        $this->assertEquals('Puikules muiža', $loc5['venue_name']);
        $this->assertEquals('Puikule', $loc5['city']);
    }

    public function test_category_resolving_strictly_follows_agents_md(): void
    {
        $scraper = new LimbazuNovadsScraper();

        // 1. Strict Kino rule: Filma "Tumšā robeža" is ALWAYS Kino, NEVER Teātris!
        [$cat1, $type1] = $scraper->resolveCategories(
            'Filma "Tumšā robeža" un tikšanās ar aktieri Jēkabu Reini Vidrižos',
            'KINOfilma "TUMŠĀ ROBEŽA" Sporta un kultūras centrs "Vidriži"',
            'Sporta un kultūras centrs "Vidriži"'
        );
        $this->assertContains('Kino', $cat1);
        $this->assertNotContains('Teātris', $cat1);
        $this->assertEquals('kino', $type1);

        // 2. Real Theatre production
        [$cat2, $type2] = $scraper->resolveCategories(
            'Pannas teātra komēdija "Greizsirži" Limbažos',
            'Lieliska humora izrāde visai ģimenei',
            'Limbažu Kultūras nams'
        );
        $this->assertContains('Teātris', $cat2);
        $this->assertEquals('standup', $type2);

        // 3. Music / Concert
        [$cat3, $type3] = $scraper->resolveCategories(
            'Koncerts "Dziesmu pilni rati" Liepupē',
            'Skaists vokālais koncerts Liepupes tautas namā',
            'Liepupes Tautas nams'
        );
        $this->assertContains('Mūzika', $cat3);
        $this->assertEquals('concert', $type3);

        // 4. Exhibition
        [$cat4, $type4] = $scraper->resolveCategories(
            'Izstāde "Aģes upes ceļš uz jūru"',
            'Tēlotājmākslas un fotogrāfiju izstāde',
            'Limbažu muzejs'
        );
        $this->assertContains('Izstādes', $cat4);
        $this->assertEquals('exhibition', $type4);

        // 5. Celebration / Festival
        [$cat5, $type5] = $scraper->resolveCategories(
            '14. Jampadrača deju svētki "MŪS VIENO DEJA" Limbažos',
            'Tautas deju kolektīvu svētki',
            'Limbažu Kultūras nams'
        );
        $this->assertContains('Svētki', $cat5);
        $this->assertEquals('festival', $type5);
    }

    public function test_price_parsing(): void
    {
        $scraper = new LimbazuNovadsScraper();

        // 1. "cena 5,- EUR"
        $p1 = $scraper->parsePrices('Biļetes var iegādāties Limbažu kultūras nama kasē, cena 5,- EUR');
        $this->assertFalse($p1['is_free']);
        $this->assertEquals(5.0, $p1['price_min']);

        // 2. "Ieejas maksa – 7 €"
        $p2 = $scraper->parsePrices('Ieejas maksa – 7 €, biļetes pirms pasākuma');
        $this->assertFalse($p2['is_free']);
        $this->assertEquals(7.0, $p2['price_min']);

        // 3. "Ieeja bez maksas"
        $p3 = $scraper->parsePrices('Visiem apmeklētājiem ieeja bez maksas.');
        $this->assertTrue($p3['is_free']);
        $this->assertEquals(0.0, $p3['price_min']);
    }

    public function test_scrape_with_mocked_http(): void
    {
        $mockListHtml = '<div id="sepPasWrap">
            <div class="sepPas">
                <span class="kad"><b>12.</b> Dec</span>
                <a href="jaunumi?4884" target="_top">
                    <img loading="lazy" src="box/jaunumi/4884.webp" alt="">
                    <b>Filma "Tumšā robeža" un tikšanās ar aktieri Jēkabu Reini Vidrižos</b>
                </a>
            </div>
        </div>';

        $mockDetailHtml = '<!DOCTYPE html><html><body>
            <div class="column col-8">
                <div class="date"><span>12. Decembris 16:00 - 18:00</span></div>
                <h1>Filma "Tumšā robeža" un tikšanās ar aktieri Jēkabu Reini Vidrižos</h1>
                KINOfilma "TUMŠĀ ROBEŽA"<br />Sporta un kultūras centrs "Vidriži"<br />12. decembrī<br />plkst.16:00<br />Biļetes cena: 5 €
                <p>Aicinām uz režisora Andreja Ēķa jauno spiegu trilleri “Tumšā robeža”!</p>
                <a href="https://www.bilesuparadize.lv/lv/event/999888">Pirkt biļeti</a>
            </div>
            <div class="column col-4 jaunumsOpenIMG">
                <a href="box/jaunumi/4884.1792234822.webp" data-fancybox="gallery">
                    <img src="tumbs/?w=1&i=box/jaunumi/4884.1792234822.webp" alt="Titulbilde">
                </a>
            </div>
        </body></html>';

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $mockListHtml),
            new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $mockDetailHtml),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $scraper = new LimbazuNovadsScraper();
        $scraper->setClient($client);

        $source = Source::firstOrCreate(
            ['slug' => 'limbazu-novads'],
            [
                'name' => 'Limbažu novada pašvaldība',
                'url' => 'https://www.visitlimbazi.lv/lv/pasakumi-drizuma',
                'scraper_class' => LimbazuNovadsScraper::class,
                'type' => 'municipality',
                'is_active' => true,
            ]
        );

        $results = $scraper->scrape($source);

        $this->assertCount(1, $results);
        $dto = $results->first();

        $this->assertInstanceOf(ScrapedEventDTO::class, $dto);
        $this->assertEquals('Filma "Tumšā robeža" un tikšanās ar aktieri Jēkabu Reini Vidrižos', $dto->title);
        $this->assertEquals('Sporta un kultūras centrs "Vidriži"', $dto->venueName);
        $this->assertEquals('Vidriži', $dto->city);
        $this->assertEquals('Vidzeme', $dto->region);
        $this->assertContains('Kino', $dto->categoryNames);
        $this->assertNotContains('Teātris', $dto->categoryNames);
        $this->assertEquals('kino', $dto->entertainmentType);
        $this->assertEquals(5.0, $dto->priceMin);
        $this->assertEquals('https://www.bilesuparadize.lv/lv/event/999888', $dto->ticketUrl);
        $this->assertEquals('https://www.visitlimbazi.lv/lv/box/jaunumi/4884.1792234822.webp', $dto->imageUrl);
        $this->assertEquals('visitlimbazi_4884', $dto->sourceExternalId);
        $this->assertStringContainsString('Andreja Ēķa jauno spiegu trilleri', $dto->description);
    }

    public function test_limbazu_novads_event_ingestion(): void
    {
        $source = Source::firstOrCreate(
            ['slug' => 'limbazu-novads'],
            [
                'name' => 'Limbažu novada pašvaldība',
                'url' => 'https://www.visitlimbazi.lv/lv/pasakumi-drizuma',
                'scraper_class' => LimbazuNovadsScraper::class,
                'type' => 'municipality',
                'is_active' => true,
            ]
        );

        $dto = new ScrapedEventDTO(
            title: '14. Jampadrača deju svētki "MŪS VIENO DEJA" Limbažos',
            startAt: Carbon::tomorrow()->setTime(18, 0),
            endAt: Carbon::tomorrow()->setTime(20, 0),
            description: "14. Jampadrača deju svētki 'MŪS VIENO DEJA' Limbažu kultūras namā pulcēs labākos deju kolektīvus.",
            shortDescription: '14. Jampadrača deju svētki Limbažos.',
            venueName: 'Limbažu Kultūras nams',
            city: 'Limbaži',
            region: 'Vidzeme',
            address: 'Rīgas iela 9, Limbaži, Limbažu novads, LV-4001',
            latitude: 57.5146,
            longitude: 24.7176,
            placeType: 'cultural_center',
            categoryNames: ['Svētki'],
            entertainmentType: 'festival',
            isFree: false,
            priceMin: 5.0,
            priceMax: 5.0,
            currency: 'EUR',
            ticketUrl: 'https://www.bilesuparadize.lv/lv/event/179759',
            imageUrl: 'https://www.visitlimbazi.lv/lv/box/jaunumi/4877.webp',
            sourceUrl: 'https://www.visitlimbazi.lv/lv/jaunumi?4877',
            sourceExternalId: 'visitlimbazi_4877',
            locale: 'lv',
            rawData: ['visitlimbazi_id' => '4877'],
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = \App\Models\Event::where('source_external_id', 'visitlimbazi_4877')->first();
        $this->assertNotNull($event);
        $this->assertEquals('14. Jampadrača deju svētki "MŪS VIENO DEJA" Limbažos', $event->title);
        $this->assertEquals('https://www.bilesuparadize.lv/lv/event/179759', $event->ticket_url);

        $cats = $event->categories->pluck('slug')->all();
        $this->assertContains('svetki', $cats);

        $location = $event->location;
        $this->assertNotNull($location);
        $this->assertEquals('Limbažu Kultūras nams', $location->name);
        $this->assertEquals('Limbaži', $location->city);
    }
}
