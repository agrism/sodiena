<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Location;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\ForumCinemasScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForumCinemasScraperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Category::firstOrCreate(['slug' => 'kino'], ['name' => 'Kino']);
        Category::firstOrCreate(['slug' => 'teatris'], ['name' => 'Teātris']);
        Category::firstOrCreate(['slug' => 'muzika'], ['name' => 'Mūzika']);
        Category::firstOrCreate(['slug' => 'berniem'], ['name' => 'Bērniem']);
        Category::firstOrCreate(['slug' => 'citi'], ['name' => 'Cits']);
    }

    public function test_metadata(): void
    {
        $scraper = new ForumCinemasScraper();
        $this->assertEquals('forum-cinemas', $scraper->getSlug());
        $this->assertEquals('Forum Cinemas', $scraper->getName());
    }

    public function test_theatre_location_resolution(): void
    {
        $scraper = new ForumCinemasScraper();

        // 1. Forum Cinemas Alfa
        $locAlfa = $scraper->resolveTheatre(1034, 'Forum Cinemas Alfa');
        $this->assertEquals('Forum Cinemas Alfa', $locAlfa['venue_name']);
        $this->assertEquals('Brīvības gatve 372, Rīga, LV-1006', $locAlfa['address']);
        $this->assertEquals('Rīga', $locAlfa['city']);
        $this->assertEquals('cinema', $locAlfa['place_type']);

        // 2. Kino Citadele / Centrs
        $locCentrs = $scraper->resolveTheatre(1032, 'Forum Cinemas Centrs');
        $this->assertEquals('Kino Citadele', $locCentrs['venue_name']);
        $this->assertEquals('13. janvāra iela 8, Rīga, LV-1050', $locCentrs['address']);
        $this->assertEquals('Rīga', $locCentrs['city']);
        $this->assertEquals('cinema', $locCentrs['place_type']);
    }

    public function test_category_resolving_strictly_follows_agents_md(): void
    {
        $scraper = new ForumCinemasScraper();

        // 1. Standard movie (STRICT RULE: ALWAYS Kino, NEVER Teātris)
        [$cat1, $type1] = $scraper->resolveCategories(
            'Kāpa: Otrā daļa',
            'Piedzīvojumi, Drāma, Asa sižeta, Fantastika',
            'Kino Citadele'
        );
        $this->assertContains('Kino', $cat1);
        $this->assertNotContains('Teātris', $cat1);
        $this->assertEquals('kino', $type1);

        // 2. Animation / Family movie -> Kino + Bērniem
        [$cat2, $type2] = $scraper->resolveCategories(
            'Krancītis suņu izstādē',
            'Komēdija, Animācija, Ģimenes',
            'Forum Cinemas Alfa'
        );
        $this->assertContains('Kino', $cat2);
        $this->assertContains('Bērniem', $cat2);
        $this->assertNotContains('Teātris', $cat2);
        $this->assertEquals('kino', $type2);

        // 3. Concert film -> Kino + Mūzika
        [$cat3, $type3] = $scraper->resolveCategories(
            'Taylor Swift: The Eras Tour koncertfilma',
            'Mūzika, Koncerts',
            'Kino Citadele'
        );
        $this->assertContains('Kino', $cat3);
        $this->assertContains('Mūzika', $cat3);
        $this->assertNotContains('Teātris', $cat3);
        $this->assertEquals('kino', $type3);
    }

    public function test_extract_xml_name(): void
    {
        $scraper = new ForumCinemasScraper();

        // 1. Clean string
        $this->assertEquals('Latviešu', $scraper->extractXmlName('Latviešu'));

        // 2. Associative array with Name
        $this->assertEquals('Angļu', $scraper->extractXmlName(['Name' => 'Angļu', 'ISOTwoLetterCode' => 'en']));

        // 3. Empty array from self-closing XML tag <SpokenLanguage />
        $this->assertNull($scraper->extractXmlName([]));
        $this->assertNull($scraper->extractXmlName(['Name' => []]));
        $this->assertNull($scraper->extractXmlName(null));
    }

    public function test_description_formatting(): void
    {
        $scraper = new ForumCinemasScraper();

        $movie = [
            'originalTitle' => 'Dune: Part Two',
            'productionYear' => 2026,
            'lengthInMinutes' => 166,
            'ratingLabel' => 'N12',
            'synopsis' => 'Pols Atreids turpina savu ceļojumu ar čani un frīmeniem.',
            'directors' => ['Denis Villeneuve'],
            'cast' => ['Timothée Chalamet', 'Zendaya'],
            'trailerUrl' => 'https://www.youtube.com/watch?v=oOKHVtgPJ00',
        ];

        $show = [
            'Theatre' => 'Kino Citadele',
        ];

        $desc = $scraper->formatDescription(
            $movie,
            $show,
            'Kāpa: Otrā daļa',
            'Piedzīvojumi, Fantastika',
            'Auditorija 1 (ISENSE)',
            '2D, Dolby Atmos',
            'Angļu',
            ['Latviešu', 'Krievu']
        );

        $this->assertStringContainsString('Pols Atreids turpina savu ceļojumu', $desc);
        $this->assertStringContainsString('Oriģinālnosaukums: Dune: Part Two', $desc);
        $this->assertStringContainsString('Režisors: Denis Villeneuve', $desc);
        $this->assertStringContainsString('Lomās: Timothée Chalamet, Zendaya', $desc);
        $this->assertStringContainsString('Treileris: https://www.youtube.com/watch?v=oOKHVtgPJ00', $desc);
        $this->assertStringContainsString('Kinoteātris: Kino Citadele', $desc);
        $this->assertStringContainsString('Zāle: Auditorija 1 (ISENSE)', $desc);
        $this->assertStringContainsString('Formāts: 2D, Dolby Atmos', $desc);
        $this->assertStringContainsString('Valoda: Angļu', $desc);
        $this->assertStringContainsString('Subtitri: Latviešu, Krievu', $desc);
    }

    public function test_image_url_resolution(): void
    {
        $scraper = new ForumCinemasScraper();

        $movie = [
            'imageUrl' => 'http://forumcinemaslv.blob.core.windows.net/1012/Event_11592/portrait_large/poster.jpg',
        ];

        $img = $scraper->resolveImageUrl([], $movie);
        $this->assertEquals('https://forumcinemaslv.blob.core.windows.net/1012/Event_11592/portrait_large/poster.jpg', $img);
    }

    public function test_scrape_with_mocked_xml(): void
    {
        $mockEventsXml = '<?xml version="1.0"?>
        <Events>
            <Event>
                <ID>305000</ID>
                <Title>Kāpa: Otrā daļa</Title>
                <OriginalTitle>Dune: Part Two</OriginalTitle>
                <ProductionYear>2026</ProductionYear>
                <LengthInMinutes>166</LengthInMinutes>
                <RatingLabel>N12</RatingLabel>
                <Genres>Piedzīvojumi, Fantastika</Genres>
                <Synopsis>Pols Atreids apvienojas ar Čani un frīmeniem.</Synopsis>
                <ShortSynopsis>Pols Atreids turpina cīņu par Arrakisu.</ShortSynopsis>
                <EventURL>https://www.forumcinemas.lv/event/305000/title/kapa_2/</EventURL>
                <Images>
                    <EventLargeImagePortrait>http://forumcinemaslv.blob.core.windows.net/Event_11592/poster.jpg</EventLargeImagePortrait>
                </Images>
                <Videos>
                    <EventVideo>
                        <Title>Trailer</Title>
                        <Location>oOKHVtgPJ00</Location>
                    </EventVideo>
                </Videos>
                <Directors>
                    <Director><FirstName>Denis</FirstName><LastName>Villeneuve</LastName></Director>
                </Directors>
                <Cast>
                    <Actor><FirstName>Timothée</FirstName><LastName>Chalamet</LastName></Actor>
                </Cast>
            </Event>
        </Events>';

        $futureTime = now()->addDays(2)->setTime(19, 0)->format('Y-m-d\TH:i:s');
        $futureEndTime = now()->addDays(2)->setTime(21, 46)->format('Y-m-d\TH:i:s');

        $mockScheduleXml = '<?xml version="1.0"?>
        <Schedule>
            <Shows>
                <Show>
                    <ID>444008</ID>
                    <EventID>305000</EventID>
                    <Title>Kāpa: Otrā daļa</Title>
                    <dttmShowStart>' . $futureTime . '</dttmShowStart>
                    <dttmShowEnd>' . $futureEndTime . '</dttmShowEnd>
                    <TheatreID>1032</TheatreID>
                    <Theatre>Forum Cinemas Centrs</Theatre>
                    <TheatreAuditorium>ISENSE zāle</TheatreAuditorium>
                    <PresentationMethod>2D, Dolby Atmos</PresentationMethod>
                    <ShowURL>http://www.forumcinemas.lv/websales/show/444008/</ShowURL>
                    <EventURL>http://www.forumcinemas.lv/event/305000/title/kapa_2/</EventURL>
                    <SpokenLanguage><Name>Angļu</Name></SpokenLanguage>
                    <SubtitleLanguage1><Name>Latviešu</Name></SubtitleLanguage1>
                </Show>
            </Shows>
        </Schedule>';

        $mockTicketHtml = '<table class="showPrices-table">
            <tr class="ticket-row">
                <td class="showPrices-table-price"><span>8,20 €</span></td>
            </tr>
            <tr class="ticket-row">
                <td class="showPrices-table-price"><span>4,50 €</span></td>
            </tr>
        </table>';

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/xml; charset=UTF-8'], $mockEventsXml),
            new Response(200, ['Content-Type' => 'text/xml; charset=UTF-8'], $mockScheduleXml),
            new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $mockTicketHtml),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $scraper = new ForumCinemasScraper();
        $scraper->setClient($client);

        $source = Source::firstOrCreate(
            ['slug' => 'forum-cinemas'],
            [
                'name' => 'Forum Cinemas',
                'url' => 'https://www.forumcinemas.lv',
                'scraper_class' => ForumCinemasScraper::class,
                'type' => 'venue',
                'is_active' => true,
            ]
        );

        $results = $scraper->scrape($source);

        $this->assertCount(1, $results);
        $dto = $results->first();

        $this->assertInstanceOf(ScrapedEventDTO::class, $dto);
        $this->assertEquals('Kāpa: Otrā daļa', $dto->title);
        $this->assertEquals('Kino Citadele', $dto->venueName);
        $this->assertEquals('Rīga', $dto->city);
        $this->assertEquals('Rīga un Pierīga', $dto->region);
        $this->assertContains('Kino', $dto->categoryNames);
        $this->assertNotContains('Teātris', $dto->categoryNames);
        $this->assertEquals('kino', $dto->entertainmentType);
        $this->assertEquals('https://www.forumcinemas.lv/websales/show/444008/', $dto->ticketUrl);
        $this->assertEquals('https://forumcinemaslv.blob.core.windows.net/Event_11592/poster.jpg', $dto->imageUrl);
        $this->assertEquals('show_444008', $dto->sourceExternalId);
        $this->assertEquals(4.50, $dto->priceMin);
        $this->assertEquals(8.20, $dto->priceMax);
        $this->assertEquals(4.50, $dto->rawData['price_min']);
        $this->assertEquals(8.20, $dto->rawData['price_max']);
        $this->assertEquals('https://www.youtube.com/watch?v=oOKHVtgPJ00', $dto->rawData['trailer_url']);
        $this->assertEquals('https://www.youtube.com/watch?v=oOKHVtgPJ00', $dto->rawData['youtube_url']);
        $this->assertStringContainsString('Treileris: https://www.youtube.com/watch?v=oOKHVtgPJ00', $dto->description);
    }

    public function test_forum_cinemas_event_ingestion(): void
    {
        $source = Source::firstOrCreate(
            ['slug' => 'forum-cinemas'],
            [
                'name' => 'Forum Cinemas',
                'url' => 'https://www.forumcinemas.lv',
                'scraper_class' => ForumCinemasScraper::class,
                'type' => 'venue',
                'is_active' => true,
            ]
        );

        $dto = new ScrapedEventDTO(
            title: 'Krancītis suņu izstādē',
            startAt: Carbon::tomorrow()->setTime(10, 10),
            endAt: Carbon::tomorrow()->setTime(11, 49),
            description: "Animācijas filma Krancītis suņu izstādē Forum Cinemas Alfa kinoteātrī.",
            shortDescription: 'Animācijas filma ģimenei Krancītis suņu izstādē.',
            venueName: 'Forum Cinemas Alfa',
            city: 'Rīga',
            region: 'Rīga un Pierīga',
            address: 'Brīvības gatve 372, Rīga, LV-1006',
            latitude: 56.9835,
            longitude: 24.2045,
            placeType: 'cinema',
            categoryNames: ['Kino', 'Bērniem'],
            entertainmentType: 'kino',
            isFree: false,
            priceMin: null,
            priceMax: null,
            currency: 'EUR',
            ticketUrl: 'https://www.forumcinemas.lv/websales/show/444008/',
            imageUrl: 'https://forumcinemaslv.blob.core.windows.net/Event_11592/poster.jpg',
            sourceUrl: 'https://www.forumcinemas.lv/event/304988/',
            sourceExternalId: 'show_444008',
            locale: 'lv',
            rawData: ['show_id' => 444008, 'theatre' => 'Forum Cinemas Alfa'],
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = \App\Models\Event::where('source_external_id', 'show_444008')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Krancītis suņu izstādē', $event->title);
        $this->assertEquals('https://www.forumcinemas.lv/websales/show/444008', $event->ticket_url);

        $cats = $event->categories->pluck('slug')->all();
        $this->assertContains('kino', $cats);
        $this->assertNotContains('teatris', $cats);

        $location = $event->location;
        $this->assertNotNull($location);
        $this->assertEquals('Forum Cinemas Alfa', $location->name);
        $this->assertEquals('Rīga', $location->city);
    }

    public function test_fetch_show_prices_parses_min_and_max(): void
    {
        $mockHtml = '<table class="showPrices-table">
            <tr class="ticket-row">
                <td class="showPrices-table-ticketCategory">Pieaugušo</td>
                <td class="showPrices-table-price"><span>12,90 €</span></td>
            </tr>
            <tr class="ticket-row">
                <td class="showPrices-table-ticketCategory">Bērniem</td>
                <td class="showPrices-table-price"><span>6,30 €</span></td>
            </tr>
            <tr class="ticket-row">
                <td class="showPrices-table-ticketCategory">Skolēnu</td>
                <td class="showPrices-table-price"><span>10,97 €</span></td>
            </tr>
        </table>';

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $mockHtml),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $scraper = new ForumCinemasScraper();
        $scraper->setClient($client);

        [$min, $max] = $scraper->fetchShowPrices('https://www.forumcinemas.lv/websales/show/444606/');

        $this->assertEquals(6.30, $min);
        $this->assertEquals(12.90, $max);
    }

    public function test_scrape_reuses_existing_prices_from_database(): void
    {
        // Pre-create an event in database with known prices
        \App\Models\Event::create([
            'title' => 'Kāpa: Otrā daļa',
            'slug' => 'kapa-otra-dala-price-test',
            'start_at' => now()->addDays(2)->setTime(19, 0),
            'source_external_id' => 'show_444008',
            'price_min' => 4.50,
            'price_max' => 8.20,
            'status' => 'published',
            'fingerprint' => 'test_fingerprint_price_444008',
            'all_day' => false,
            'is_free' => false,
            'currency' => 'EUR',
        ]);

        $futureTime = now()->addDays(2)->setTime(19, 0)->format('Y-m-d\TH:i:s');
        $futureEndTime = now()->addDays(2)->setTime(21, 46)->format('Y-m-d\TH:i:s');

        $mockEventsXml = '<?xml version="1.0"?><Events><Event><ID>305000</ID><Title>Kāpa: Otrā daļa</Title></Event></Events>';
        $mockScheduleXml = '<?xml version="1.0"?>
        <Schedule>
            <Shows>
                <Show>
                    <ID>444008</ID>
                    <EventID>305000</EventID>
                    <Title>Kāpa: Otrā daļa</Title>
                    <dttmShowStart>' . $futureTime . '</dttmShowStart>
                    <dttmShowEnd>' . $futureEndTime . '</dttmShowEnd>
                    <TheatreID>1032</TheatreID>
                    <Theatre>Forum Cinemas Centrs</Theatre>
                    <ShowURL>http://www.forumcinemas.lv/websales/show/444008/</ShowURL>
                </Show>
            </Shows>
        </Schedule>';

        // NOTE: ONLY 2 responses provided (Events and Schedule). If scraper tried to make a 3rd HTTP call to fetch price, it would throw OutOfBoundsException!
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/xml; charset=UTF-8'], $mockEventsXml),
            new Response(200, ['Content-Type' => 'text/xml; charset=UTF-8'], $mockScheduleXml),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $scraper = new ForumCinemasScraper();
        $scraper->setClient($client);

        $source = Source::firstOrCreate(
            ['slug' => 'forum-cinemas'],
            [
                'name' => 'Forum Cinemas',
                'url' => 'https://www.forumcinemas.lv',
                'scraper_class' => ForumCinemasScraper::class,
                'type' => 'venue',
                'is_active' => true,
            ]
        );

        $results = $scraper->scrape($source);
        $this->assertCount(1, $results);
        $dto = $results->first();

        $this->assertEquals(4.50, $dto->priceMin);
        $this->assertEquals(8.20, $dto->priceMax);
    }
}
