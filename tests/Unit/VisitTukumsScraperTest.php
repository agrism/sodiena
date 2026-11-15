<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\VisitTukumsScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisitTukumsScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata(): void
    {
        $scraper = new VisitTukumsScraper();
        $this->assertEquals('visittukums', $scraper->getSlug());
        $this->assertEquals('Visit Tukums', $scraper->getName());
    }

    public function test_extract_tag_and_title(): void
    {
        $scraper = new VisitTukumsScraper();

        [$tag1, $title1] = $scraper->extractTagAndTitle('TEĀTRIS | Amatierteātra izrāde "Dārgumi"');
        $this->assertEquals('TEĀTRIS', $tag1);
        $this->assertEquals('Amatierteātra izrāde "Dārgumi"', $title1);

        [$tag2, $title2] = $scraper->extractTagAndTitle('MŪZIKA | LĪVI svin 50');
        $this->assertEquals('MŪZIKA', $tag2);
        $this->assertEquals('LĪVI svin 50', $title2);

        [$tag3, $title3] = $scraper->extractTagAndTitle('VALSTS SVĒTKI | Koncerts "Spēka pavedieni" Tukumā');
        $this->assertEquals('VALSTS SVĒTKI', $tag3);
        $this->assertEquals('Koncerts "Spēka pavedieni" Tukumā', $title3);

        [$tag4, $title4] = $scraper->extractTagAndTitle('Optibet stipro skrējiens');
        $this->assertNull($tag4);
        $this->assertEquals('Optibet stipro skrējiens', $title4);
    }

    public function test_parse_dates_single_date_with_time(): void
    {
        $scraper = new VisitTukumsScraper();
        $today = Carbon::create(2026, 11, 15, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('15.11.2026 15:00', $today);

        $this->assertNotNull($startAt);
        $this->assertNull($endAt);
        $this->assertEquals('2026-11-15 15:00:00', $startAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_time_range(): void
    {
        $scraper = new VisitTukumsScraper();
        $today = Carbon::create(2026, 11, 15, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('18.11.2026 18:00 - 19:30', $today);

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-11-18 18:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-18 19:30:00', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_date_range(): void
    {
        $scraper = new VisitTukumsScraper();
        $today = Carbon::create(2026, 11, 1, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('15.11.2026 - 20.11.2026', $today);

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-11-15 00:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-20 23:59:59', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_discards_past_end_date(): void
    {
        $scraper = new VisitTukumsScraper();
        $today = Carbon::create(2026, 11, 15, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('06.12.2026 12:00 - 07.10.2026', $today);

        $this->assertNotNull($startAt);
        $this->assertNull($endAt);
        $this->assertEquals('2026-12-06 12:00:00', $startAt->format('Y-m-d H:i:s'));
    }

    public function test_location_resolving_known_venues(): void
    {
        $scraper = new VisitTukumsScraper();

        // 1. Šlokenbekas muiža
        $loc1 = $scraper->resolveLocation('LATVIJA | "Mēs esam Latvija"', 'Šlokenbekas muižā Latvijas valsts svētki', 'Slokenbeka.jpeg');
        $this->assertEquals('Šlokenbekas muiža', $loc1['name']);
        $this->assertEquals('Milzkalne', $loc1['city']);
        $this->assertEquals('Kurzeme', $loc1['region']);
        $this->assertEquals('manor', $loc1['place_type']);
        $this->assertEquals(56.9763, $loc1['lat']);

        // 2. Irlavas kultūras nams
        $loc2 = $scraper->resolveLocation('TEĀTRIS | Amatierteātra izrāde "Dārgumi"', 'Irlavas kultūras namā viesosies', 'DargumiIrlava.jpg');
        $this->assertEquals('Irlavas kultūras nams', $loc2['name']);
        $this->assertEquals('Irlava', $loc2['city']);
        $this->assertEquals('Kurzeme', $loc2['region']);

        // 3. Jaunmoku pils
        $loc3 = $scraper->resolveLocation('Mūzikas vakars', 'Pasākums norisināsies Jaunmoku pilī', 'jaunmokas.jpg');
        $this->assertEquals('Jaunmoku pils', $loc3['name']);
        $this->assertEquals('Jaunmokas', $loc3['city']);

        // 4. Kandavas kultūras nams
        $loc4 = $scraper->resolveLocation('TEĀTRIS | Izrāde', 'Kandavas kultūras namā viesosies Tukuma teātris', 'kandava.jpg');
        $this->assertEquals('Kandavas kultūras nams', $loc4['name']);
        $this->assertEquals('Kandava', $loc4['city']);

        // 5. Slampes Kultūras pils
        $loc5 = $scraper->resolveLocation('MŪZIKA | LĪVI svin 50', 'Koncerts Slampes kultūras pilī', 'LaukuMuzikantiSlampe.JPG');
        $this->assertEquals('Slampes Kultūras pils', $loc5['name']);
        $this->assertEquals('Slampe', $loc5['city']);

        // 6. Lapmežciema Tautas nams
        $loc6 = $scraper->resolveLocation('LATVIJA | “Vēstule manai rītdienai”', 'Lapmežciema Kultūras namā Latvijas dzimšanas diena', 'Lapmezciems.jpeg');
        $this->assertEquals('Lapmežciema Tautas nams', $loc6['name']);
        $this->assertEquals('Lapmežciems', $loc6['city']);

        // 7. Pūres Šokolādes muzejs
        $loc7 = $scraper->resolveLocation('LATVIJA DZIMŠANAS DIENA', 'svinēt Pūres Šokolādes muzejā', 'SpokuSokoladePure.jpg');
        $this->assertEquals('Pūres Šokolādes muzejs', $loc7['name']);
        $this->assertEquals('Pūre', $loc7['city']);
    }

    public function test_agents_rule_cinema_vs_theatre_categorization(): void
    {
        $scraper = new VisitTukumsScraper();

        // 1. Cinema / Kino strict rule: ANY event with filma/kinoseanss is ALWAYS Kino and NEVER Teātris!
        $cats1 = $scraper->resolveCategories('TEĀTRIS', 'Dokumentālā filma par Tukumu', 'Kinoseanss Tukuma kultūras namā', 'Tukuma pilsētas Kultūras nams');
        $this->assertEquals(['Kino'], $cats1);
        $this->assertEquals('movie', $scraper->resolveEntertainmentType($cats1, 'Dokumentālā filma par Tukumu', ''));

        // 2. kinoteātris contains substring "teātr", but MUST NEVER be classified as Teātris
        $cats2 = $scraper->resolveCategories(null, 'Jaunākā animācijas filma', 'Demonstrē kinoteātrī visai ģimenei', 'Kinoteātris');
        $this->assertEquals(['Kino'], $cats2);
        $this->assertEquals('movie', $scraper->resolveEntertainmentType($cats2, 'Jaunākā animācijas filma', ''));

        // 3. Theater: production with izrāde
        $cats3 = $scraper->resolveCategories('TEĀTRIS', 'Amatierteātra izrāde "Dārgumi"', 'Irlavas kultūras namā viesosies amatierteātris ar izrādi', 'Irlavas kultūras nams');
        $this->assertEquals(['Teātris'], $cats3);
        $this->assertEquals('performance', $scraper->resolveEntertainmentType($cats3, 'Amatierteātra izrāde "Dārgumi"', ''));

        // 4. Theater: luga
        $cats4 = $scraper->resolveCategories('TEĀTRIS', '"Septiņas vecmeitas"', 'Džūkstes Kultūras namā uz skatuves atdzīvosies luga', 'Džūkstes Kultūras nams');
        $this->assertEquals(['Teātris'], $cats4);
        $this->assertEquals('performance', $scraper->resolveEntertainmentType($cats4, '"Septiņas vecmeitas"', ''));

        // 5. Theater for children: muzikāla izrāde bērniem
        $cats5 = $scraper->resolveCategories('BĒRNIEM', 'Muzikāla izrāde "Dāvanu lietus"', 'Svētku izrāde bērniem', 'Kandavas kultūras nams');
        $this->assertContains('Teātris', $cats5);
        $this->assertContains('Bērniem', $cats5);
        $this->assertEquals('performance', $scraper->resolveEntertainmentType($cats5, 'Muzikāla izrāde "Dāvanu lietus"', ''));

        // 6. Sports: hike
        $cats6 = $scraper->resolveCategories('AKTĪVA ATPŪTA', 'Pārgājiens "Kino pieturvietas"', 'Pārgājiens Lapmežciema pagastā', 'Lapmežciems');
        $this->assertEquals(['Sports'], $cats6);
        $this->assertEquals('sports', $scraper->resolveEntertainmentType($cats6, 'Pārgājiens', ''));

        // 7. Workshop: meistarklase
        $cats7 = $scraper->resolveCategories('RADOŠI', 'Adventes vainagu darbnīca', 'Adventes vainagu veidošana', 'Tukums');
        $this->assertEquals(['Semināri'], $cats7);
        $this->assertEquals('education', $scraper->resolveEntertainmentType($cats7, 'Adventes vainagu darbnīca', ''));

        // 8. Fallback: Cits
        $cats8 = $scraper->resolveCategories(null, 'Noslēpumains notikums Tukumā', 'Aicinām visus interesentus', 'Tukums');
        $this->assertEquals(['Cits'], $cats8);
        $this->assertEquals('other', $scraper->resolveEntertainmentType($cats8, 'Noslēpumains notikums', ''));
    }

    public function test_parse_price(): void
    {
        $scraper = new VisitTukumsScraper();

        $free1 = $scraper->parsePrice('Ieeja bez maksas visiem apmeklētājiem.');
        $this->assertTrue($free1['is_free']);
        $this->assertEquals(0.0, $free1['price_min']);

        $free2 = $scraper->parsePrice('Brīva ieeja.');
        $this->assertTrue($free2['is_free']);

        $paid1 = $scraper->parsePrice('Biļetes cena: 15 EUR iepriekšpārdošanā.');
        $this->assertFalse($paid1['is_free']);
        $this->assertEquals(15.0, $paid1['price_min']);
        $this->assertEquals(15.0, $paid1['price_max']);

        $paid2 = $scraper->parsePrice('Dalības maksa 5,50 € līdz 12 € atkarībā no grupas.');
        $this->assertFalse($paid2['is_free']);
        $this->assertEquals(5.5, $paid2['price_min']);
        $this->assertEquals(12.0, $paid2['price_max']);
    }

    public function test_scrape_mock_html(): void
    {
        $mockHtml = <<<'HTML'
<!doctype html>
<html>
<body>
    <div class="content">
        <div class="blockList">
            <div class="article2">
                <div class="blockWrap">
                    <div class="imageWrap">
                        <a href="/lv/Pasakumi/0/6179">
                            <img src="http://visittukums.kartes.lv/files/photo/DargumiIrlava.jpg" alt="">
                        </a>
                    </div>
                    <h3><a href="/lv/Pasakumi/0/6179">TEĀTRIS | Amatierteātra izrāde "Dārgumi"</a></h3>
                    <div style="font-size: 12px;">15.11.2026 15:00</div>
                    <div class="excerpt">Irlavas kultūras namā viesosies Džūkstes amatierteātris ar izrādi “Dārgumi”. Ieeja bez maksas.</div>
                </div>
            </div>
            <div class="article2">
                <div class="blockWrap">
                    <div class="imageWrap">
                        <a href="/lv/Pasakumi/0/6183">
                            <img src="http://visittukums.kartes.lv/files/photo/Slokenbeka.jpeg" alt="">
                        </a>
                    </div>
                    <h3><a href="/lv/Pasakumi/0/6183">LATVIJA | "Mēs esam Latvija"</a></h3>
                    <div style="font-size: 12px;">17.11.2026 19:00 - 21:00</div>
                    <div class="excerpt">Šlokenbekas muižā Latvijas valsts svētki tiks atzīmēti ar svētku koncertu. Biļetes cena: 10 EUR.</div>
                </div>
            </div>
        </div>
        <div class="pagination">
            <span class="current">1</span>
        </div>
    </div>
</body>
</html>
HTML;

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $mockHtml),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $scraper = new VisitTukumsScraper();
        $scraper->setClient($client);

        $source = new Source([
            'name' => 'Visit Tukums',
            'slug' => 'visittukums',
            'url' => 'https://visittukums.lv/lv/Pasakumi',
            'scraper_class' => VisitTukumsScraper::class,
            'is_active' => true,
        ]);

        $events = $scraper->scrape($source);

        $this->assertCount(2, $events);

        // Event 1
        $dto1 = $events[0];
        $this->assertEquals('Amatierteātra izrāde "Dārgumi"', $dto1->title);
        $this->assertEquals('visittukums-6179', $dto1->sourceExternalId);
        $this->assertEquals('https://visittukums.lv/lv/Pasakumi/0/6179', $dto1->sourceUrl);
        $this->assertEquals('https://visittukums.kartes.lv/files/photo/DargumiIrlava.jpg', $dto1->imageUrl);
        $this->assertEquals('Irlavas kultūras nams', $dto1->venueName);
        $this->assertEquals('Irlava', $dto1->city);
        $this->assertEquals('Kurzeme', $dto1->region);
        $this->assertEquals('2026-11-15 15:00:00', $dto1->startAt->format('Y-m-d H:i:s'));
        $this->assertNull($dto1->endAt);
        $this->assertContains('Teātris', $dto1->categoryNames);
        $this->assertEquals('performance', $dto1->entertainmentType);
        $this->assertTrue($dto1->isFree);

        // Event 2
        $dto2 = $events[1];
        $this->assertEquals('"Mēs esam Latvija"', $dto2->title);
        $this->assertEquals('visittukums-6183', $dto2->sourceExternalId);
        $this->assertEquals('Šlokenbekas muiža', $dto2->venueName);
        $this->assertEquals('Milzkalne', $dto2->city);
        $this->assertEquals('Kurzeme', $dto2->region);
        $this->assertEquals('2026-11-17 19:00:00', $dto2->startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-17 21:00:00', $dto2->endAt->format('Y-m-d H:i:s'));
        $this->assertContains('Mūzika', $dto2->categoryNames);
        $this->assertContains('Svētki', $dto2->categoryNames);
        $this->assertEquals('concert', $dto2->entertainmentType);
        $this->assertFalse($dto2->isFree);
        $this->assertEquals(10.0, $dto2->priceMin);
    }

    public function test_visit_tukums_event_ingestion(): void
    {
        $source = Source::create([
            'name' => 'Visit Tukums',
            'slug' => 'visittukums',
            'url' => 'https://visittukums.lv/lv/Pasakumi',
            'scraper_class' => VisitTukumsScraper::class,
            'is_active' => true,
        ]);

        $dto = new ScrapedEventDTO(
            title: 'Amatierteātra izrāde "Dārgumi"',
            startAt: Carbon::create(2026, 11, 20, 15, 0, 0, 'Europe/Riga'),
            endAt: Carbon::create(2026, 11, 20, 17, 0, 0, 'Europe/Riga'),
            description: 'Irlavas kultūras namā viesosies Džūkstes amatierteātris ar izrādi “Dārgumi”.',
            shortDescription: 'Izrāde Irlavā.',
            venueName: 'Irlavas kultūras nams',
            city: 'Irlava',
            region: 'Kurzeme',
            address: 'Irlava, Irlavas pagasts, Tukuma novads, LV-3137',
            latitude: 56.8653,
            longitude: 23.0033,
            placeType: 'cultural_center',
            categoryNames: ['Teātris'],
            entertainmentType: 'performance',
            isFree: true,
            priceMin: 0.0,
            priceMax: 0.0,
            imageUrl: 'https://visittukums.kartes.lv/files/photo/DargumiIrlava.jpg',
            sourceUrl: 'https://visittukums.lv/lv/Pasakumi/0/6179',
            sourceExternalId: 'visittukums-6179',
            locale: 'lv',
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_external_id', 'visittukums-6179')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Amatierteātra izrāde "Dārgumi"', $event->title);
        $this->assertEquals('visittukums.lv', $event->origin_host);
        $this->assertEquals('performance', $event->entertainment_type);
        $this->assertEquals('Irlava', $event->location->city);
        $this->assertEquals('Kurzeme', $event->location->region);
        $this->assertTrue($event->is_free);
    }
}
