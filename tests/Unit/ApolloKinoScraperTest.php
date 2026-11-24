<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Location;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\ApolloKinoScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApolloKinoScraperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed essential canonical categories
        Category::firstOrCreate(['slug' => 'kino'], ['name' => 'Kino']);
        Category::firstOrCreate(['slug' => 'teatris'], ['name' => 'Teātris']);
        Category::firstOrCreate(['slug' => 'muzika'], ['name' => 'Mūzika']);
        Category::firstOrCreate(['slug' => 'berniem'], ['name' => 'Bērniem']);
        Category::firstOrCreate(['slug' => 'sports'], ['name' => 'Sports']);
        Category::firstOrCreate(['slug' => 'citi'], ['name' => 'Cits']);
    }

    public function test_metadata(): void
    {
        $scraper = new ApolloKinoScraper();
        $this->assertEquals('apollo-kino', $scraper->getSlug());
        $this->assertEquals('Apollo Kino', $scraper->getName());
    }

    public function test_theatre_location_resolution(): void
    {
        $scraper = new ApolloKinoScraper();

        // 1. Akropole Rīga
        $loc1 = $scraper->resolveTheatre(1035, 'Apollo Kino Akropole Rīga');
        $this->assertEquals('Apollo Kino Akropole Rīga', $loc1['venue_name']);
        $this->assertEquals('Maskavas iela 257, Rīga', $loc1['address']);
        $this->assertEquals('Rīga', $loc1['city']);
        $this->assertEquals('Rīga un Pierīga', $loc1['region']);
        $this->assertEquals(56.9099, $loc1['latitude']);
        $this->assertEquals(24.1878, $loc1['longitude']);
        $this->assertEquals('cinema', $loc1['place_type']);

        // 2. Rīga Plaza
        $loc2 = $scraper->resolveTheatre(1037, 'Apollo Kino Rīga Plaza');
        $this->assertEquals('Apollo Kino Rīga Plaza', $loc2['venue_name']);
        $this->assertEquals('Mūkusalas iela 71, Rīga', $loc2['address']);
        $this->assertEquals('Rīga', $loc2['city']);
        $this->assertEquals(56.9298, $loc2['latitude']);
        $this->assertEquals('cinema', $loc2['place_type']);

        // 3. Domina
        $loc3 = $scraper->resolveTheatre(1038, 'Apollo Kino Domina');
        $this->assertEquals('Apollo Kino Domina', $loc3['venue_name']);
        $this->assertEquals('Ieriķu iela 3, Rīga', $loc3['address']);
        $this->assertEquals('Rīga', $loc3['city']);
        $this->assertEquals(56.9678, $loc3['latitude']);
        $this->assertEquals('cinema', $loc3['place_type']);

        // 4. Akropole Alfa
        $loc4 = $scraper->resolveTheatre(0, 'Apollo Kino Akropole Alfa');
        $this->assertEquals('Apollo Kino Akropole Alfa', $loc4['venue_name']);
        $this->assertEquals('Brīvības gatve 372, Rīga', $loc4['address']);

        // 5. Ventspils
        $loc5 = $scraper->resolveTheatre(0, 'Apollo Kino Ventspils');
        $this->assertEquals('Apollo Kino Ventspils', $loc5['venue_name']);
        $this->assertEquals('Ventspils', $loc5['city']);
        $this->assertEquals('Kurzeme', $loc5['region']);
    }

    public function test_category_resolving_strictly_follows_agents_md(): void
    {
        $scraper = new ApolloKinoScraper();

        // 1. Regular drama / thriller movie -> ALWAYS Kino, NEVER Teātris!
        [$cat1, $type1] = $scraper->resolveCategories(
            'Tumšā robeža',
            'Asa sižeta filma, Vēsturiska drāma',
            'Apollo Kino Akropole Rīga'
        );
        $this->assertContains('Kino', $cat1);
        $this->assertNotContains('Teātris', $cat1);
        $this->assertEquals('kino', $type1);

        // 2. Family animation movie -> Kino + Bērniem
        [$cat2, $type2] = $scraper->resolveCategories(
            'Glaunais runcis',
            'Animācija, Ģimenes filma, Komēdija',
            'Apollo Kino Rīga Plaza'
        );
        $this->assertContains('Kino', $cat2);
        $this->assertContains('Bērniem', $cat2);
        $this->assertNotContains('Teātris', $cat2);
        $this->assertEquals('kids', $type2);

        // 3. Concert film / music -> Kino + Mūzika
        [$cat3, $type3] = $scraper->resolveCategories(
            'Queen Budapest: Live Concert',
            'Koncertfilma, Mūzika',
            'Apollo Kino Domina'
        );
        $this->assertContains('Kino', $cat3);
        $this->assertContains('Mūzika', $cat3);
        $this->assertNotContains('Teātris', $cat3);
        $this->assertEquals('concert', $type3);

        // 4. Ballet in cinema -> STRICT AGENTS.md RULE: Must remain Kino, NEVER Teātris!
        [$cat4, $type4] = $scraper->resolveCategories(
            'Parīzes Operas balets — Romeo un Džuljeta',
            'Balets, Mūzika',
            'Apollo Kino Akropole Rīga'
        );
        $this->assertContains('Kino', $cat4);
        $this->assertNotContains('Teātris', $cat4);
    }

    public function test_description_formatting(): void
    {
        $scraper = new ApolloKinoScraper();

        $movie = [
            'originalTitle' => 'Ebenezer',
            'productionYear' => 2026,
            'lengthInMinutes' => 115,
            'ratingLabel' => '12+',
            'synopsis' => 'Klasiskā Čārlza Dikensa Ziemassvētku stāsta mūsdienīga adaptācija.',
            'directors' => ['Kristofers Nolans'],
            'cast' => ['Džons Bērntāls', 'Mets Deimons', 'Enna Hataveja'],
        ];

        $show = [
            'OriginalTitle' => 'Ebenezer',
            'ProductionYear' => 2026,
            'LengthInMinutes' => 115,
            'RatingLabel' => '12+',
        ];

        $desc = $scraper->formatDescription(
            $movie,
            $show,
            'Skrūdžs: Ziemassvētku dziesma',
            'Komēdija, Drāma, Fantastika',
            '6. Zvaigžņu zāle',
            'IMAX 3D',
            'Angļu',
            ['Latviešu', 'Krievu']
        );

        $this->assertStringContainsString('Klasiskā Čārlza Dikensa Ziemassvētku stāsta', $desc);
        $this->assertStringContainsString('Oriģinālnosaukums: Ebenezer', $desc);
        $this->assertStringContainsString('Izlaiduma gads: 2026', $desc);
        $this->assertStringContainsString('Filmas garums: 115 min.', $desc);
        $this->assertStringContainsString('Žanrs: Komēdija, Drāma, Fantastika', $desc);
        $this->assertStringContainsString('Vecuma ierobežojums: 12+', $desc);
        $this->assertStringContainsString('Valoda: Angļu', $desc);
        $this->assertStringContainsString('Subtitri: Latviešu, Krievu', $desc);
        $this->assertStringContainsString('Formāts: IMAX 3D', $desc);
        $this->assertStringContainsString('Kinozāle: 6. Zvaigžņu zāle', $desc);
        $this->assertStringContainsString('Režisors: Kristofers Nolans', $desc);
        $this->assertStringContainsString('Galvenajās lomās: Džons Bērntāls, Mets Deimons, Enna Hataveja', $desc);
    }

    public function test_image_url_resolution(): void
    {
        $scraper = new ApolloKinoScraper();

        $show = [
            'Images' => [
                'EventLargeImagePortrait' => 'https://mcswebsites.blob.core.windows.net/test/large.jpg',
                'EventMediumImagePortrait' => 'https://mcswebsites.blob.core.windows.net/test/medium.jpg',
            ],
        ];

        $movie = [
            'imageUrl' => 'https://mcswebsites.blob.core.windows.net/test/movie.jpg',
        ];

        $this->assertEquals(
            'https://mcswebsites.blob.core.windows.net/test/large.jpg',
            $scraper->resolveImageUrl($show, $movie)
        );

        // Fallback when show image is missing
        $this->assertEquals(
            'https://mcswebsites.blob.core.windows.net/test/movie.jpg',
            $scraper->resolveImageUrl(['Images' => []], $movie)
        );
    }

    public function test_scrape_with_mocked_http(): void
    {
        $mockEvents = [
            [
                'ID' => 304207,
                'Title' => 'Skrūdžs: Ziemassvētku dziesma',
                'OriginalTitle' => 'Ebenezer',
                'ProductionYear' => 2026,
                'LengthInMinutes' => 115,
                'Genres' => 'Komēdija, Drāma, Fantastika',
                'RatingLabel' => '12+',
                'ShortSynopsis' => 'Ziemassvētku dziesmas stāsts.',
                'Synopsis' => 'Pilns apraksts par Skrūdža piedzīvojumiem Ziemassvētku naktī.',
                'EventURL' => 'https://www.apollokino.lv/event/304207',
                'Images' => [
                    'EventLargeImagePortrait' => 'https://mcswebsites.blob.core.windows.net/posters/skrudzs.jpg',
                ],
                'Videos' => [
                    ['Location' => 'vwN9rfJ1Pr4'],
                ],
                'Directors' => [['FirstName' => 'Režisors', 'LastName' => 'Bērziņš']],
                'Cast' => [['FirstName' => 'Aktieris', 'LastName' => 'Ozols']],
            ],
        ];

        $futureDate = now()->addDays(2)->format('Y-m-d') . 'T18:00:00';
        $futureEndDate = now()->addDays(2)->format('Y-m-d') . 'T20:00:00';

        $mockSchedule = [
            'PubDate' => now()->toIso8601String(),
            'Shows' => [
                [
                    'ID' => 534330,
                    'EventID' => 304207,
                    'Title' => 'Skrūdžs: Ziemassvētku dziesma',
                    'OriginalTitle' => 'Ebenezer',
                    'ProductionYear' => 2026,
                    'LengthInMinutes' => 115,
                    'dttmShowStart' => $futureDate,
                    'dttmShowEnd' => $futureEndDate,
                    'RatingLabel' => '12+',
                    'Genres' => 'Komēdija, Drāma, Fantastika',
                    'TheatreID' => 1035,
                    'Theatre' => 'Apollo Kino Akropole Rīga',
                    'TheatreAuditorium' => '6. Zvaigžņu zāle',
                    'PresentationMethod' => '2D',
                    'ShowURL' => 'https://www.apollokino.lv/websales/show/534330',
                    'EventURL' => 'https://www.apollokino.lv/event/304207',
                    'SpokenLanguage' => ['Name' => 'Latviešu'],
                    'SubtitleLanguage1' => ['Name' => 'Angļu'],
                    'Images' => [
                        'EventLargeImagePortrait' => 'https://mcswebsites.blob.core.windows.net/posters/skrudzs.jpg',
                    ],
                ],
            ],
        ];

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($mockEvents)),
            new Response(200, ['Content-Type' => 'application/json'], json_encode($mockSchedule)),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $scraper = new ApolloKinoScraper();
        $scraper->setClient($client);

        $source = Source::firstOrCreate(
            ['slug' => 'apollo-kino'],
            [
                'name' => 'Apollo Kino',
                'url' => 'https://www.apollokino.lv',
                'scraper_class' => ApolloKinoScraper::class,
                'type' => 'venue',
                'is_active' => true,
            ]
        );

        $results = $scraper->scrape($source);

        $this->assertCount(1, $results);
        $dto = $results->first();

        $this->assertInstanceOf(ScrapedEventDTO::class, $dto);
        $this->assertEquals('Skrūdžs: Ziemassvētku dziesma', $dto->title);
        $this->assertEquals('Apollo Kino Akropole Rīga', $dto->venueName);
        $this->assertEquals('Maskavas iela 257, Rīga', $dto->address);
        $this->assertEquals('Rīga', $dto->city);
        $this->assertEquals('cinema', $dto->placeType);
        $this->assertContains('Kino', $dto->categoryNames);
        $this->assertNotContains('Teātris', $dto->categoryNames);
        $this->assertEquals('kino', $dto->entertainmentType);
        $this->assertEquals('https://www.apollokino.lv/websales/show/534330', $dto->ticketUrl);
        $this->assertEquals('show_534330', $dto->sourceExternalId);
        $this->assertNotNull($dto->priceMin);
        $this->assertNotNull($dto->priceMax);
        $this->assertGreaterThan(0, $dto->priceMin);
        $this->assertGreaterThanOrEqual($dto->priceMin, $dto->priceMax);
        $this->assertEquals('https://www.youtube.com/watch?v=vwN9rfJ1Pr4', $dto->rawData['trailer_url']);
        $this->assertEquals('https://www.youtube.com/watch?v=vwN9rfJ1Pr4', $dto->rawData['youtube_url']);
        $this->assertStringContainsString('Treileris: https://www.youtube.com/watch?v=vwN9rfJ1Pr4', $dto->description);
        $this->assertStringContainsString('Skrūdža piedzīvojumiem', $dto->description);
        $this->assertStringContainsString('Kinozāle: 6. Zvaigžņu zāle', $dto->description);
    }

    public function test_apollo_kino_event_ingestion(): void
    {
        $source = Source::firstOrCreate(
            ['slug' => 'apollo-kino'],
            [
                'name' => 'Apollo Kino',
                'url' => 'https://www.apollokino.lv',
                'scraper_class' => ApolloKinoScraper::class,
                'type' => 'venue',
                'is_active' => true,
            ]
        );

        $dto = new ScrapedEventDTO(
            title: 'Odiseja (IMAX seanss)',
            startAt: Carbon::tomorrow()->setTime(19, 0),
            endAt: Carbon::tomorrow()->setTime(21, 52),
            description: "Režisora Kristofera Nolana jaunā episkā drāma 'Odiseja' uzņemta ar IMAX kamerām.\n\nFormāts: IMAX 2D\nKinozāle: 1. IMAX zāle",
            shortDescription: 'Režisora Kristofera Nolana jaunā episkā drāma.',
            venueName: 'Apollo Kino Akropole Rīga',
            city: 'Rīga',
            region: 'Rīga un Pierīga',
            address: 'Maskavas iela 257, Rīga',
            latitude: 56.9099,
            longitude: 24.1878,
            placeType: 'cinema',
            categoryNames: ['Kino'],
            entertainmentType: 'kino',
            isFree: false,
            priceMin: null,
            priceMax: null,
            currency: 'EUR',
            ticketUrl: 'https://www.apollokino.lv/websales/show/999111',
            imageUrl: 'https://mcswebsites.blob.core.windows.net/posters/odiseja.jpg',
            sourceUrl: 'https://www.apollokino.lv/event/303993/odiseja',
            sourceExternalId: 'show_999111',
            locale: 'lv',
            rawData: ['show_id' => 999111, 'theatre_id' => 1035],
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = \App\Models\Event::where('source_external_id', 'show_999111')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Odiseja (IMAX seanss)', $event->title);
        $this->assertEquals('https://www.apollokino.lv/websales/show/999111', $event->ticket_url);

        // Strict category verification: MUST have kino, MUST NOT have teatris!
        $cats = $event->categories->pluck('slug')->all();
        $this->assertContains('kino', $cats);
        $this->assertNotContains('teatris', $cats);

        // Location verification
        $location = $event->location;
        $this->assertNotNull($location);
        $this->assertEquals('Apollo Kino Akropole Rīga', $location->name);
        $this->assertEquals('Maskavas iela 257, Rīga', $location->address);
        $this->assertEquals('Rīga', $location->city);
        $this->assertEquals('cinema', $location->place_type);
    }

    public function test_calculate_show_prices_for_various_theatres_and_halls(): void
    {
        $scraper = new ApolloKinoScraper();

        $wednesdayNoon = Carbon::parse('2026-11-25 14:00:00'); // Wednesday 14:00 (weekday)
        $fridayNight = Carbon::parse('2026-11-27 19:30:00');   // Friday 19:30 (weekend rates)
        $saturdayNight = Carbon::parse('2026-11-28 20:00:00'); // Saturday 20:00 (weekend rates)

        // 1. Akropole Rīga (1035) - Standarta 2D weekday vs weekend
        [$min1, $max1] = $scraper->calculateShowPrices(1035, '2. zāle', '2D', $wednesdayNoon);
        $this->assertEquals(5.49, $min1);
        $this->assertEquals(8.88, $max1);

        [$min2, $max2] = $scraper->calculateShowPrices(1035, '2. zāle', '2D', $saturdayNight);
        $this->assertEquals(6.19, $min2);
        $this->assertEquals(9.99, $max2);

        // 2. Akropole Rīga (1035) - IMAX 2D and 3D
        [$minImax2D, $maxImax2D] = $scraper->calculateShowPrices(1035, '1. IMAX zāle', 'IMAX 2D', $wednesdayNoon);
        $this->assertEquals(7.49, $minImax2D);
        $this->assertEquals(10.88, $maxImax2D);

        [$minImax3D, $maxImax3D] = $scraper->calculateShowPrices(1035, '1. IMAX zāle', 'IMAX 3D', $fridayNight);
        $this->assertEquals(9.19, $minImax3D);
        $this->assertEquals(12.99, $maxImax3D);

        // 3. Akropole Rīga (1035) - Restorāna zāle
        [$minRest, $maxRest] = $scraper->calculateShowPrices(1035, '7. zāle Restorāns', '2D', $wednesdayNoon);
        $this->assertEquals(8.05, $minRest);
        $this->assertEquals(11.44, $maxRest);

        // 4. Rīga Plaza (1037) - Vintage zāle & Divāns
        [$minVint, $maxVint] = $scraper->calculateShowPrices(1037, '8. Vintage zāle', '2D', $saturdayNight);
        $this->assertEquals(13.19, $minVint);
        $this->assertEquals(16.99, $maxVint);

        [$minDiv, $maxDiv] = $scraper->calculateShowPrices(1037, '2. zāle Dīvāns', '2D', $wednesdayNoon);
        $this->assertEquals(7.69, $minDiv);
        $this->assertEquals(11.08, $maxDiv);

        // 5. Domina (1038) - Standarta 2D weekday vs weekend
        [$minDom1, $maxDom1] = $scraper->calculateShowPrices(1038, '1. zāle', '2D', $wednesdayNoon);
        $this->assertEquals(4.99, $minDom1);
        $this->assertEquals(8.21, $maxDom1);

        [$minDom2, $maxDom2] = $scraper->calculateShowPrices(1038, '1. zāle', '2D', $saturdayNight);
        $this->assertEquals(5.69, $minDom2);
        $this->assertEquals(9.32, $maxDom2);
    }

    public function test_get_price_matrix_defaults_to_default_matrix(): void
    {
        $scraper = new ApolloKinoScraper();
        $matrix = $scraper->getPriceMatrix();

        $this->assertEquals(ApolloKinoScraper::DEFAULT_PRICE_MATRIX, $matrix);
        $this->assertArrayHasKey(1035, $matrix);
        $this->assertArrayHasKey(1037, $matrix);
        $this->assertArrayHasKey(1038, $matrix);
    }

    public function test_parse_cinema_pricing_html(): void
    {
        $scraper = new ApolloKinoScraper();

        $html = '
        <div class="price-slider">
            <div class="price-slider__cell"></div>
            <div class="price-slider__cell">No pirmdienas līdz ceturtdienai Piektdienās līdz 16:59</div>
            <div class="price-slider__cell">Piektdienās no 17:00 Brīvdienās un svētku dienās</div>
            <div class="price-slider__cell">Apollo Klubabiedra cena</div>
            <div class="price-slider__cell">2D-filma 7,99 € Standarta cena 8,88 € 3D-filma 8,89 € Standarta cena 9,88 €</div>
            <div class="price-slider__cell">2D-filma 8,99 € Standarta cena 9,99 € 3D-filma 9,89 € Standarta cena 10,99 €</div>
            <div class="price-slider__cell">Bērnu biļetes cena</div>
            <div class="price-slider__cell">2D-filma 5,49 € 3D-filma 6,49 €</div>
            <div class="price-slider__cell">2D-filma 6,19 € 3D-filma 7,19 €</div>
        </div>';

        $parsed = $scraper->parseCinemaPricingHtml($html);

        $this->assertArrayHasKey('standarta', $parsed);
        $this->assertEquals([5.49, 8.88], $parsed['standarta']['2D']['weekday']);
        $this->assertEquals([6.19, 9.99], $parsed['standarta']['2D']['weekend']);
        $this->assertEquals([6.49, 9.88], $parsed['standarta']['3D']['weekday']);
        $this->assertEquals([7.19, 10.99], $parsed['standarta']['3D']['weekend']);
    }

    public function test_get_price_matrix_dynamic_refresh_on_web_success(): void
    {
        $mockHtml = '
        <div class="price-slider">
            <div class="price-slider__cell"></div>
            <div class="price-slider__cell">No pirmdienas līdz ceturtdienai Piektdienās līdz 16:59</div>
            <div class="price-slider__cell">Piektdienās no 17:00 Brīvdienās un svētku dienās</div>
            <div class="price-slider__cell">Apollo Klubabiedra cena</div>
            <div class="price-slider__cell">2D-filma 8,00 € Standarta cena 9.50 €</div>
            <div class="price-slider__cell">2D-filma 9,00 € Standarta cena 10.50 €</div>
            <div class="price-slider__cell">Bērnu biļetes cena</div>
            <div class="price-slider__cell">2D-filma 6.00 €</div>
            <div class="price-slider__cell">2D-filma 7.00 €</div>
        </div>';

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $mockHtml), // Akropole
            new Response(200, ['Content-Type' => 'text/html'], $mockHtml), // Plaza
            new Response(200, ['Content-Type' => 'text/html'], $mockHtml), // Domina
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $scraper = new ApolloKinoScraper();
        $scraper->setClient($client);

        Cache::flush();
        $matrix = $scraper->getPriceMatrix(forceRefresh: true);

        $this->assertIsArray($matrix);
        $this->assertEquals([6.00, 9.50], $matrix[1035]['standarta']['2D']['weekday']);
        $this->assertEquals([7.00, 10.50], $matrix[1035]['standarta']['2D']['weekend']);
    }

    public function test_get_price_matrix_fallback_on_web_failure_and_logs_error(): void
    {
        Log::spy();

        // Simulate 500 error from Apollo web server
        $mock = new MockHandler([
            new Response(500, [], 'Internal Server Error'),
            new Response(500, [], 'Internal Server Error'),
            new Response(500, [], 'Internal Server Error'),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $scraper = new ApolloKinoScraper();
        $scraper->setClient($client);

        Cache::flush();

        // Must not throw an exception, must return default matrix fallback
        $matrix = $scraper->getPriceMatrix(forceRefresh: true);

        $this->assertEquals(ApolloKinoScraper::DEFAULT_PRICE_MATRIX, $matrix);

        // Error must be logged as required
        Log::shouldHaveReceived('error')
            ->atLeast()->once();
    }
}
