<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Event;
use App\Models\Location;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\JelgavasValstspilsetaScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JelgavasValstspilsetaScraperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed basic canonical categories for testing
        $canonicalCategories = [
            'Mūzika' => 'Mūzikas pasākumi, koncerti',
            'Kino' => 'Kino filmas un seansi',
            'Teātris' => 'Teātra izrādes',
            'Izstādes' => 'Mākslas un vēstures izstādes',
            'Sports' => 'Sporta sacensības un nodarbības',
            'Bērniem' => 'Pasākumi ģimenēm ar bērniem',
            'Izglītība' => 'Semināri, lekcijas, meistarklases',
            'Svētki' => 'Pilsētas un valsts svētki',
            'Cits' => 'Citi pasākumi',
        ];

        foreach ($canonicalCategories as $name => $desc) {
            Category::firstOrCreate(
                ['name' => $name],
                ['slug' => \Illuminate\Support\Str::slug($name), 'description' => $desc]
            );
        }
    }

    public function test_metadata(): void
    {
        $scraper = new JelgavasValstspilsetaScraper();
        $this->assertEquals('jelgavas-valstspilseta', $scraper->getSlug());
        $this->assertEquals('Jelgavas valstspilsētas pašvaldība', $scraper->getName());
    }

    public function test_skips_administrative_and_council_meetings(): void
    {
        $scraper = new JelgavasValstspilsetaScraper();

        $this->assertTrue($scraper->isExcludedEvent([], 'Jelgavas domes sēde'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Finanšu komitejas sēde'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Ārkārtas sēde par budžetu'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Domes deputāti pieņem iedzīvotājus'));
        $this->assertTrue($scraper->isExcludedEvent([], 'Lokālplānojuma publiskā apspriešana'));

        // Normal cultural events must NOT be skipped
        $this->assertFalse($scraper->isExcludedEvent([], 'Svētku koncerts “Lai zied gaismā, Latvija!”'));
        $this->assertFalse($scraper->isExcludedEvent([], 'Keksa filmu vakars'));
        $this->assertFalse($scraper->isExcludedEvent([], 'Ādolfa Alunāna teātra pirmizrāde “Čaukstenes”'));
    }

    public function test_date_and_time_parsing(): void
    {
        $scraper = new JelgavasValstspilsetaScraper();

        // 1. Single date with time range
        [$start1, $end1, $allDay1] = $scraper->parseLatvianDates('18.11.2026', '18:00 - 22:00');
        $this->assertNotNull($start1);
        $this->assertNotNull($end1);
        $this->assertFalse($allDay1);
        $this->assertEquals('2026-11-18 18:00:00', $start1->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-18 22:00:00', $end1->format('Y-m-d H:i:s'));

        // 2. Single date with single start time
        [$start2, $end2, $allDay2] = $scraper->parseLatvianDates('19.11.2026', '16:00');
        $this->assertNotNull($start2);
        $this->assertNotNull($end2);
        $this->assertFalse($allDay2);
        $this->assertEquals('2026-11-19 16:00:00', $start2->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-19 18:00:00', $end2->format('Y-m-d H:i:s')); // default +2h

        // 3. Date range without time (all day exhibition)
        [$start3, $end3, $allDay3] = $scraper->parseLatvianDates('No 16.11.2026 līdz 20.11.2026');
        $this->assertNotNull($start3);
        $this->assertNotNull($end3);
        $this->assertTrue($allDay3);
        $this->assertEquals('2026-11-16 00:00:00', $start3->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-20 23:59:00', $end3->format('Y-m-d H:i:s'));

        // 4. Over year boundary date range
        [$start4, $end4, $allDay4] = $scraper->parseLatvianDates('No 17.10.2026 līdz 03.01.2027');
        $this->assertNotNull($start4);
        $this->assertNotNull($end4);
        $this->assertTrue($allDay4);
        $this->assertEquals('2026-10-17 00:00:00', $start4->format('Y-m-d H:i:s'));
        $this->assertEquals('2027-01-03 23:59:00', $end4->format('Y-m-d H:i:s'));
    }

    public function test_price_parsing(): void
    {
        $scraper = new JelgavasValstspilsetaScraper();

        // Free variants
        $p1 = $scraper->parsePrice('Bez maksas');
        $this->assertTrue($p1['is_free']);
        $this->assertEquals(0.0, $p1['price_min']);
        $this->assertEquals(0.0, $p1['price_max']);

        $p2 = $scraper->parsePrice('Dalība bez maksas');
        $this->assertTrue($p2['is_free']);

        $p3 = $scraper->parsePrice('0.00 eiro');
        $this->assertTrue($p3['is_free']);
        $this->assertEquals(0.0, $p3['price_min']);

        // Fixed price
        $p4 = $scraper->parsePrice('3.00 eiro');
        $this->assertFalse($p4['is_free']);
        $this->assertEquals(3.0, $p4['price_min']);
        $this->assertEquals(3.0, $p4['price_max']);

        $p5 = $scraper->parsePrice('€15');
        $this->assertFalse($p5['is_free']);
        $this->assertEquals(15.0, $p5['price_min']);
        $this->assertEquals(15.0, $p5['price_max']);

        // Price ranges
        $p6 = $scraper->parsePrice('6.00–15.00 eiro');
        $this->assertFalse($p6['is_free']);
        $this->assertEquals(6.0, $p6['price_min']);
        $this->assertEquals(15.0, $p6['price_max']);

        $p7 = $scraper->parsePrice('18-30 EIRO');
        $this->assertFalse($p7['is_free']);
        $this->assertEquals(18.0, $p7['price_min']);
        $this->assertEquals(30.0, $p7['price_max']);

        $p8 = $scraper->parsePrice('0,75-1,50 Eiro');
        $this->assertFalse($p8['is_free']);
        $this->assertEquals(0.75, $p8['price_min']);
        $this->assertEquals(1.5, $p8['price_max']);

        $p9 = $scraper->parsePrice('5,6 EIRO');
        $this->assertFalse($p9['is_free']);
        $this->assertEquals(5.6, $p9['price_min']);
        $this->assertEquals(5.6, $p9['price_max']);

        $p10 = $scraper->parsePrice('');
        $this->assertFalse($p10['is_free']);
        $this->assertNull($p10['price_min']);
    }

    public function test_location_resolving(): void
    {
        $scraper = new JelgavasValstspilsetaScraper();

        // 1. Baznīcas tornis
        $loc1 = $scraper->resolveLocation('Uz Jelgavas Sv. Trīsvienības baznīcas torņa', 'Multimediāls uzvedums');
        $this->assertEquals('Jelgavas Sv. Trīsvienības baznīcas tornis', $loc1['name']);
        $this->assertEquals('Jelgava', $loc1['city']);
        $this->assertEquals('Zemgale', $loc1['region']);
        $this->assertEquals('Akadēmijas iela 1, Jelgava, LV-3001', $loc1['address']);
        $this->assertEquals('museum', $loc1['place_type']);
        $this->assertEquals(56.652411, $loc1['latitude']);

        // 2. Hercoga Jēkaba laukums
        $loc2 = $scraper->resolveLocation('Hercoga Jēkaba laukums', 'Svētku koncerts');
        $this->assertEquals('Hercoga Jēkaba laukums', $loc2['name']);
        $this->assertEquals('square', $loc2['place_type']);

        // 3. Pasta sala
        $loc3 = $scraper->resolveLocation('Pasta sala', 'Festivāls');
        $this->assertEquals('Pasta sala', $loc3['name']);
        $this->assertEquals('park', $loc3['place_type']);

        // 4. Muzejs
        $loc4 = $scraper->resolveLocation('Ģ. Eliasa Jelgavas vēstures un mākslas muzejs', 'Izstāde');
        $this->assertEquals('Ģ. Eliasa Jelgavas vēstures un mākslas muzejs', $loc4['name']);
        $this->assertEquals('museum', $loc4['place_type']);

        // 5. Bibliotēka ar adresi
        $loc5 = $scraper->resolveLocation('Pārlielupes bibliotēka, Loka maģistrāle 17, Jelgava', 'Tikšanās');
        $this->assertEquals('Pārlielupes bibliotēka', $loc5['name']);
        $this->assertEquals('library', $loc5['place_type']);

        // 6. Ādolfa Alunāna teātris
        $loc6 = $scraper->resolveLocation('Ādolfa Alunāna Jelgavas teātris', 'Izrāde');
        $this->assertEquals('Ādolfa Alunāna Jelgavas teātris', $loc6['name']);
        $this->assertEquals('theatre', $loc6['place_type']);
    }

    public function test_category_resolving_strictly_follows_agents_md(): void
    {
        $scraper = new JelgavasValstspilsetaScraper();

        // 1. Strict Cinema / Kino rule: Any movie event is ALWAYS Kino and NEVER Teātris!
        [$catKino1, $typeKino1] = $scraper->resolveCategories([], 'Keksa filmu vakars', 'Skatīsimies aizraujošu filmu', 'Jelgava');
        $this->assertContains('Kino', $catKino1);
        $this->assertNotContains('Teātris', $catKino1);
        $this->assertEquals('kino', $typeKino1);

        [$catKino2, $typeKino2] = $scraper->resolveCategories([], 'Filmu vakars svētku noskaņās', 'Kino seanss visai ģimenei', 'Jelgava');
        $this->assertContains('Kino', $catKino2);
        $this->assertNotContains('Teātris', $catKino2);
        $this->assertEquals('kino', $typeKino2);

        // 2. Real theater productions must be classified as Teātris
        [$catTeatris, $typeTeatris] = $scraper->resolveCategories(
            [],
            'Ādolfa Alunāna Jelgavas teātra pirmizrāde “Čaukstenes”',
            'Izrāde divos cēlienos',
            'Ādolfa Alunāna Jelgavas teātris'
        );
        $this->assertContains('Teātris', $catTeatris);
        $this->assertEquals('theatre', $typeTeatris);

        // 3. Concerts & Music
        [$catMusic, $typeMusic] = $scraper->resolveCategories(
            [],
            'Svētku koncerts “Lai zied gaismā, Latvija!”',
            'Koncertā piedalās koris un orķestris',
            'Hercoga Jēkaba laukums'
        );
        $this->assertContains('Mūzika', $catMusic);
        $this->assertEquals('concert', $typeMusic);

        // 4. Exhibitions
        [$catExhib, $typeExhib] = $scraper->resolveCategories(
            ['Izstādes'],
            'Kaspara Zariņa personālizstāde ”Mana pasaule”',
            'Gleznu izstādes atklāšana',
            'Tornis'
        );
        $this->assertContains('Izstādes', $catExhib);
        $this->assertEquals('exhibition', $typeExhib);

        // 5. Sports
        [$catSport, $typeSport] = $scraper->resolveCategories(
            [],
            'Jelgavas atklātā čempionāta telpu futbolā spēle',
            'Futbola turnīrs sporta hallē',
            'ZOC'
        );
        $this->assertContains('Sports', $catSport);
        $this->assertEquals('sports', $typeSport);

        // 6. Educational / Workshops
        [$catEdu, $typeEdu] = $scraper->resolveCategories(
            [],
            'Praktiskā darbnīca ar lielformāta printeri',
            'Radošā darbnīca un meistarklase',
            'ZRKAC'
        );
        $this->assertContains('Izglītība', $catEdu);
        $this->assertEquals('educational', $typeEdu);

        // 7. Fallback to Cits
        [$catCits, $typeCits] = $scraper->resolveCategories([], 'Pilsētas tikšanās', 'Informācija', 'Jelgava');
        $this->assertContains('Cits', $catCits);
        $this->assertEquals('other', $typeCits);
    }

    public function test_scrape_with_mocked_http(): void
    {
        $upcomingDate = Carbon::today('Europe/Riga')->addDays(3)->format('d.m.Y');

        $archiveHtml = <<<HTML
<!DOCTYPE html>
<html>
<head><title>Pasākumi - Jelgava</title></head>
<body>
    <div class="events-items-wrap">
        <div class="events-date-items">
            <div class="events-dates">{$upcomingDate}</div>
            <div class="events-times">18:00 - 22:00</div>
        </div>
        <div class="event-image-wrap">
            <img src="https://www.jelgava.lv/wp-content/uploads/2026/10/tornis.jpg">
        </div>
        <div class="events-items">
            <div class="events-title">
                <a href="https://www.jelgava.lv/pasakumi/multimedials-uzvedums-jelgavas-speka-laudis/">Multimediāls uzvedums “Jelgavas spēka ļaudis”</a>
            </div>
            <div class="events-places">Uz Jelgavas Sv. Trīsvienības baznīcas torņa</div>
            <div class="events-price">Bez maksas</div>
            <div class="events-organizer">Kultūra</div>
        </div>
    </div>
</body>
</html>
HTML;

        $detailHtml = <<<'HTML'
<!DOCTYPE html>
<html>
<head><title>Multimediāls uzvedums - Jelgava</title></head>
<body>
    <div class="block-article-open">
        <div class="block-page-heading">
            <div class="styled-as-h2">Multimediāls uzvedums “Jelgavas spēka ļaudis”</div>
        </div>
        <div class="article-open-meta">
            <a href="https://www.jelgava.lv/pasakumu-kategorija/pilseta/">Pilsēta</a>
            <span>18.11. 18:00 - 22:00 | Uz Jelgavas Sv. Trīsvienības baznīcas torņa | Bez maksas</span>
        </div>
        <div class="row">
            <div class="col-24 col-md-10">
                <div class="block-content-padding">
                    <img src="https://www.jelgava.lv/wp-content/uploads/2026/10/large-tornis.jpg">
                </div>
            </div>
            <div class="col-24 col-md-14">
                <div class="block-content-padding">
                    <p>Tā ir gaismas projekcija par cilvēkiem, kuru idejas, talants un drosme sākušās Jelgavā.</p>
                    <p>Ar atkārtojumu ik pa 10 minūtēm.</p>
                </div>
            </div>
        </div>
        <div class="block-content-padding">
            <div class="event-organizer">Kultūra</div>
        </div>
    </div>
</body>
</html>
HTML;

        $mock = new MockHandler([
            new Response(200, [], $archiveHtml), // Page 1
            new Response(200, [], $detailHtml),  // Detail event page
            new Response(200, [], '<!DOCTYPE html><html><body></body></html>'), // Page 2 empty stop
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $scraper = new JelgavasValstspilsetaScraper();
        $scraper->setClient($client);

        $source = Source::firstOrCreate(
            ['slug' => 'jelgavas-valstspilseta'],
            [
                'name' => 'Jelgavas valstspilsēta',
                'url' => 'https://www.jelgava.lv/pasakumi/',
                'scraper_class' => JelgavasValstspilsetaScraper::class,
                'type' => 'municipality',
                'is_active' => true,
            ]
        );

        $events = $scraper->scrape($source);

        $this->assertCount(1, $events);
        /** @var ScrapedEventDTO $dto */
        $dto = $events->first();

        $this->assertEquals('Multimediāls uzvedums “Jelgavas spēka ļaudis”', $dto->title);
        $this->assertStringContainsString('Tā ir gaismas projekcija par cilvēkiem', $dto->description);
        $this->assertEquals('Jelgavas Sv. Trīsvienības baznīcas tornis', $dto->venueName);
        $this->assertEquals('Jelgava', $dto->city);
        $this->assertEquals('Zemgale', $dto->region);
        $this->assertEquals('Akadēmijas iela 1, Jelgava, LV-3001', $dto->address);
        $this->assertEquals(56.652411, $dto->latitude);
        $this->assertTrue($dto->isFree);
        $this->assertEquals(0.0, $dto->priceMin);
        $this->assertEquals('https://www.jelgava.lv/wp-content/uploads/2026/10/large-tornis.jpg', $dto->imageUrl);
        $this->assertEquals('jelgava-multimedials-uzvedums-jelgavas-speka-laudis', $dto->sourceExternalId);
    }

    public function test_jelgavas_valstspilseta_event_ingestion(): void
    {
        $source = Source::firstOrCreate(
            ['slug' => 'jelgavas-valstspilseta'],
            [
                'name' => 'Jelgavas valstspilsēta',
                'url' => 'https://www.jelgava.lv/pasakumi/',
                'scraper_class' => JelgavasValstspilsetaScraper::class,
                'type' => 'municipality',
                'is_active' => true,
            ]
        );

        $dto = new ScrapedEventDTO(
            title: 'Svētku koncerts “Lai zied gaismā, Latvija!”',
            startAt: Carbon::tomorrow('Europe/Riga')->setTime(18, 0),
            endAt: Carbon::tomorrow('Europe/Riga')->setTime(20, 0),
            description: 'Vērienīgs Latvijas Republikas proklamēšanas gadadienas koncerts Hercoga Jēkaba laukumā.',
            shortDescription: 'Vērienīgs Latvijas Republikas proklamēšanas gadadienas koncerts.',
            venueName: 'Hercoga Jēkaba laukums',
            city: 'Jelgava',
            region: 'Zemgale',
            address: 'Hercoga Jēkaba laukums, Jelgava, LV-3001',
            latitude: 56.652644,
            longitude: 23.725299,
            placeType: 'square',
            categoryNames: ['Mūzika', 'Svētki'],
            entertainmentType: 'concert',
            isFree: true,
            priceMin: 0.0,
            priceMax: 0.0,
            currency: 'EUR',
            ticketUrl: null,
            imageUrl: 'https://www.jelgava.lv/wp-content/uploads/2026/10/koncerts.jpg',
            sourceUrl: 'https://www.jelgava.lv/pasakumi/svetku-koncerts-lai-zied-gaisma-latvija/',
            sourceExternalId: 'jelgava-svetku-koncerts-lai-zied-gaisma-latvija',
            locale: 'lv',
            rawData: ['test' => true]
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_external_id', 'jelgava-svetku-koncerts-lai-zied-gaisma-latvija')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Svētku koncerts “Lai zied gaismā, Latvija!”', $event->title);
        $this->assertTrue($event->is_free);
        $this->assertEquals($source->id, $event->source_id);

        $location = Location::find($event->location_id);
        $this->assertNotNull($location);
        $this->assertEquals('Hercoga Jēkaba laukums', $location->name);
        $this->assertEquals('Jelgava', $location->city);
        $this->assertEquals('Zemgale', $location->region);

        $categories = $event->categories->pluck('name')->toArray();
        $this->assertContains('Mūzika', $categories);
    }
}
