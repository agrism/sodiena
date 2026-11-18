<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Event;
use App\Models\Location;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\TicketshopScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketshopScraperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
        $scraper = new TicketshopScraper();
        $this->assertEquals('ticketshop', $scraper->getSlug());
        $this->assertEquals('Ticketshop.lv', $scraper->getName());
    }

    public function test_skips_cancelled_events(): void
    {
        $scraper = new TicketshopScraper();

        $cancelled1 = [
            'id' => 100,
            'label' => ['type' => 'cancelled', 'caption' => 'Atcelts'],
        ];
        $this->assertTrue($scraper->isCancelledEvent($cancelled1));

        $cancelled2 = [
            'id' => 101,
            'label' => ['type' => 'custom', 'caption' => 'Pasākums atcelts!'],
        ];
        $this->assertTrue($scraper->isCancelledEvent($cancelled2));

        $cancelled3 = [
            'id' => 102,
            'status' => '0',
        ];
        $this->assertTrue($scraper->isCancelledEvent($cancelled3));

        // Active / Soldout events are NOT cancelled
        $active1 = [
            'id' => 103,
            'label' => null,
            'status' => '1',
        ];
        $this->assertFalse($scraper->isCancelledEvent($active1));

        $soldout = [
            'id' => 104,
            'label' => ['type' => 'soldout', 'caption' => 'Izpārdots'],
            'status' => '1',
        ];
        $this->assertFalse($scraper->isCancelledEvent($soldout));
    }

    public function test_location_and_region_resolution(): void
    {
        $scraper = new TicketshopScraper();

        // 1. Rīga venue
        $detail1 = [
            'location' => [
                'name' => 'Melnā Piektdiena',
                'lat' => '56.9720477',
                'lng' => '24.1542273',
                'address' => [
                    'address' => 'Brīvības gatve 193c, Rīga',
                    'city' => 'Rīga',
                ],
            ],
        ];
        $loc1 = $scraper->resolveLocation($detail1, []);
        $this->assertEquals('Melnā Piektdiena', $loc1['venue_name']);
        $this->assertEquals('Rīga', $loc1['city']);
        $this->assertEquals('Rīga un Pierīga', $loc1['region']);
        $this->assertEquals('Brīvības gatve 193c, Rīga', $loc1['address']);
        $this->assertEquals(56.9720477, $loc1['latitude']);
        $this->assertEquals(24.1542273, $loc1['longitude']);
        $this->assertEquals('concert_hall', $loc1['place_type']);

        // 2. Jelgava venue
        $detail2 = [
            'location' => [
                'name' => 'Melno Cepurīšu Balerija',
                'lat' => 56.6472031,
                'lng' => 23.7159875,
                'address' => [
                    'address' => 'Raiņa Iela 28, Jelgava',
                    'city' => 'Jelgava',
                ],
            ],
        ];
        $loc2 = $scraper->resolveLocation($detail2, []);
        $this->assertEquals('Melno Cepurīšu Balerija', $loc2['venue_name']);
        $this->assertEquals('Jelgava', $loc2['city']);
        $this->assertEquals('Zemgale', $loc2['region']);

        // 3. Liepāja venue
        $detail3 = [
            'location' => [
                'name' => 'Fontaine Palace',
                'lat' => 56.510,
                'lng' => 21.005,
                'address' => [
                    'address' => 'Dzirnavu iela 4, Liepāja',
                    'city' => 'Liepaja',
                ],
            ],
        ];
        $loc3 = $scraper->resolveLocation($detail3, []);
        $this->assertEquals('Fontaine Palace', $loc3['venue_name']);
        $this->assertEquals('Liepāja', $loc3['city']);
        $this->assertEquals('Kurzeme', $loc3['region']);

        // 4. Cinema venue K. Suns
        $detail4 = [
            'location' => [
                'name' => 'kinoteātris K. Suns',
                'address' => [
                    'address' => 'Elizabetes iela 83/85, Rīga',
                    'city' => 'Rīga',
                ],
            ],
        ];
        $loc4 = $scraper->resolveLocation($detail4, []);
        $this->assertEquals('cinema', $loc4['place_type']);
    }

    public function test_category_resolving_strictly_follows_agents_md(): void
    {
        $scraper = new TicketshopScraper();

        // 1. Strict Kino rule: K. Suns venue is ALWAYS Kino and NEVER Teātris!
        [$catKino1, $typeKino1] = $scraper->resolveCategories(
            2, // category_id = 2 is "Teātris" in Ticketshop, BUT venue is K. Suns!
            'Kinoseanss "Zelta klasika"',
            'Filmas seanss kinoteātrī',
            'kinoteātris K. Suns'
        );
        $this->assertContains('Kino', $catKino1);
        $this->assertNotContains('Teātris', $catKino1);
        $this->assertEquals('kino', $typeKino1);

        // 2. Strict Kino rule: Any event with filma / dokumentālā filma is ALWAYS Kino!
        [$catKino2, $typeKino2] = $scraper->resolveCategories(
            0,
            'Dokumentālā filma "Latvijas sirds"',
            'Filmas pirmizrāde un tikšanās ar režisoru',
            'Splendid Palace'
        );
        $this->assertContains('Kino', $catKino2);
        $this->assertNotContains('Teātris', $catKino2);
        $this->assertEquals('kino', $typeKino2);

        // 3. Real Theatre productions
        [$catTeatris, $typeTeatris] = $scraper->resolveCategories(
            2,
            'Izrāde "Divi šūpolēs"',
            'Liriska komēdija divās daļās',
            'Jaunatnes teātris "Brīvo Aktieru Sabiedrība"'
        );
        $this->assertContains('Teātris', $catTeatris);
        $this->assertEquals('theatre', $typeTeatris);

        // 4. Puppetry for kids
        [$catPuppet, $typePuppet] = $scraper->resolveCategories(
            2,
            'Pasaku izrāde "Trīs sivēntiņi"',
            'Leļļu teātris bērniem un vecākiem',
            'Leļļu teātris "Ciemos pie pasaciņas"'
        );
        $this->assertContains('Teātris', $catPuppet);
        $this->assertContains('Bērniem', $catPuppet);
        $this->assertEquals('kids', $typePuppet);

        // 5. Concerts / Music
        [$catMusic, $typeMusic] = $scraper->resolveCategories(
            1,
            'NAPALM DEATH (UK) koncerts',
            'Leģendārais grindcore koncerts Rīgā',
            'Melnā Piektdiena'
        );
        $this->assertContains('Mūzika', $catMusic);
        $this->assertEquals('concert', $typeMusic);

        // 6. Stand-up
        [$catStandup, $typeStandup] = $scraper->resolveCategories(
            4,
            'Rīgas Stand-up vakars',
            'Komēdijas šovs klubā',
            'Klubs "Depo"'
        );
        $this->assertContains('Teātris', $catStandup);
        $this->assertEquals('standup', $typeStandup);

        // 7. Festivals
        [$catFest, $typeFest] = $scraper->resolveCategories(
            3,
            'Daydream Jazz festivāls',
            'Džeza mūzikas festivāls',
            'Rīga'
        );
        $this->assertContains('Svētki', $catFest);
        $this->assertEquals('festival', $typeFest);
    }

    public function test_image_url_resolution(): void
    {
        $scraper = new TicketshopScraper();

        // 1. With avatar
        $img1 = $scraper->resolveImageUrl(107439, ['avatar' => 'abc123hash'], []);
        $this->assertEquals('https://cdn.ticketshop.lv/event/107439/abc123hash/403x570.jpg', $img1);

        // 2. With banner only
        $img2 = $scraper->resolveImageUrl(107440, ['banner' => 'bannerhash'], []);
        $this->assertEquals('https://cdn.ticketshop.lv/event/107440/bannerhash/1000x500.jpg', $img2);

        // 3. No image
        $img3 = $scraper->resolveImageUrl(107441, [], []);
        $this->assertNull($img3);
    }

    public function test_scrape_with_mocked_http(): void
    {
        $tomorrow = Carbon::tomorrow('Europe/Riga')->format('Y-m-d\TH:i:sP');

        $listJson = json_encode([
            'data' => [
                [
                    'id' => 107439,
                    'name' => 'NAPALM DEATH (UK) koncerts',
                    'date_from' => $tomorrow,
                    'location_name' => 'Melnā Piektdiena',
                    'avatar' => 'd2189485760d95ad11899f196a98c36e',
                    'currency' => 'EUR',
                    'lowest_price' => '30.00',
                    'category_id' => 1,
                    'status' => '1',
                ],
                [
                    'id' => 108171,
                    'name' => 'Atcelts koncerts',
                    'date_from' => $tomorrow,
                    'location_name' => 'Klubs "Depo"',
                    'label' => ['type' => 'cancelled', 'caption' => 'Atcelts'],
                    'status' => '1',
                ],
            ],
            'meta' => [
                'total' => 2,
            ],
        ]);

        $detailJson = json_encode([
            'id' => 107439,
            'name' => 'NAPALM DEATH (UK) koncerts',
            'description' => '<p>Rīgā atgriežas britu grindcore leģenda NAPALM DEATH!</p><p>Biļetes pieejamas iepriekšpārdošanā.</p>',
            'lowest_price' => '30.00',
            'highest_price' => '45.00',
            'currency' => 'EUR',
            'date_from' => $tomorrow,
            'date_to' => null,
            'avatar' => 'd2189485760d95ad11899f196a98c36e',
            'category_id' => 1,
            'location' => [
                'name' => 'Melnā Piektdiena',
                'lat' => '56.9720477',
                'lng' => '24.1542273',
                'address' => [
                    'address' => 'Brīvības gatve 193c, Rīga',
                    'city' => 'Rīga',
                ],
            ],
        ]);

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $listJson),
            new Response(200, ['Content-Type' => 'application/json'], $detailJson),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $scraper = new TicketshopScraper();
        $scraper->setClient($client);

        $source = Source::firstOrCreate(
            ['slug' => 'ticketshop'],
            [
                'name' => 'Ticketshop.lv',
                'url' => 'https://ticketshop.lv/lv/events',
                'scraper_class' => TicketshopScraper::class,
                'type' => 'ticketing',
                'is_active' => true,
            ]
        );

        $events = $scraper->scrape($source);

        // Cancelled event must be skipped, exactly 1 active event returned
        $this->assertCount(1, $events);
        /** @var ScrapedEventDTO $dto */
        $dto = $events->first();

        $this->assertEquals('NAPALM DEATH (UK) koncerts', $dto->title);
        $this->assertStringContainsString('Rīgā atgriežas britu grindcore leģenda', $dto->description);
        $this->assertEquals('Melnā Piektdiena', $dto->venueName);
        $this->assertEquals('Rīga', $dto->city);
        $this->assertEquals('Rīga un Pierīga', $dto->region);
        $this->assertEquals('Brīvības gatve 193c, Rīga', $dto->address);
        $this->assertEquals(56.9720477, $dto->latitude);
        $this->assertEquals(24.1542273, $dto->longitude);
        $this->assertEquals('concert_hall', $dto->placeType);
        $this->assertContains('Mūzika', $dto->categoryNames);
        $this->assertEquals('concert', $dto->entertainmentType);
        $this->assertFalse($dto->isFree);
        $this->assertEquals(30.0, $dto->priceMin);
        $this->assertEquals(45.0, $dto->priceMax);
        $this->assertEquals('https://ticketshop.lv/lv/events/107439', $dto->ticketUrl);
        $this->assertEquals('ticketshop-107439', $dto->sourceExternalId);
    }

    public function test_ticketshop_event_ingestion(): void
    {
        $source = Source::firstOrCreate(
            ['slug' => 'ticketshop'],
            [
                'name' => 'Ticketshop.lv',
                'url' => 'https://ticketshop.lv/lv/events',
                'scraper_class' => TicketshopScraper::class,
                'type' => 'ticketing',
                'is_active' => true,
            ]
        );

        $dto = new ScrapedEventDTO(
            title: 'Liriska komēdija "NAKTS UZ TRIJIEM"',
            startAt: Carbon::tomorrow('Europe/Riga')->setTime(19, 0),
            endAt: Carbon::tomorrow('Europe/Riga')->setTime(21, 0),
            description: 'Viesizrāde Jaunatnes teātrī. Mīlestības trīsstūris ar negaidītiem pavērsieniem.',
            shortDescription: 'Viesizrāde Jaunatnes teātrī.',
            venueName: 'Jaunatnes teātris "Brīvo Aktieru Sabiedrība"',
            city: 'Rīga',
            region: 'Rīga un Pierīga',
            address: 'A.Čaka iela 67/69, Rīga, LV-1011',
            latitude: 56.9559551,
            longitude: 24.1356947,
            placeType: 'theatre',
            categoryNames: ['Teātris'],
            entertainmentType: 'theatre',
            isFree: false,
            priceMin: 10.0,
            priceMax: 15.0,
            currency: 'EUR',
            ticketUrl: 'https://ticketshop.lv/lv/events/108245',
            imageUrl: 'https://cdn.ticketshop.lv/event/108245/59af5332c22190f7ee1a59ae29228ebd/403x570.jpg',
            sourceUrl: 'https://ticketshop.lv/lv/events/108245',
            sourceExternalId: 'ticketshop-108245',
            locale: 'lv',
            rawData: ['ticketshop_id' => 108245]
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_external_id', 'ticketshop-108245')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Liriska komēdija "NAKTS UZ TRIJIEM"', $event->title);
        $this->assertEquals(10.0, (float) $event->price_min);
        $this->assertEquals(15.0, (float) $event->price_max);
        $this->assertEquals('https://ticketshop.lv/lv/events/108245', $event->ticket_url);
        $this->assertEquals($source->id, $event->source_id);

        $location = Location::find($event->location_id);
        $this->assertNotNull($location);
        $this->assertStringContainsString('Brīvo Aktieru Sabiedrība', $location->name);
        $this->assertEquals('Rīga', $location->city);

        $this->assertTrue($event->categories->contains('slug', 'teatris'));
    }
}
