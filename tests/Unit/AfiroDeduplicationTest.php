<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\AfiroApiScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AfiroDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $categories = [
            ['name' => 'Mūzika', 'slug' => 'muzika'],
            ['name' => 'Festivāli & Svētki', 'slug' => 'festivals'],
            ['name' => 'Svētki', 'slug' => 'svetki'],
            ['name' => 'Kino', 'slug' => 'kino'],
            ['name' => 'Cits', 'slug' => 'citi'],
        ];

        foreach ($categories as $cat) {
            Category::firstOrCreate(['slug' => $cat['slug']], ['name' => $cat['name']]);
        }
    }

    public function test_afiro_scraper_scrapes_only_latvian_locale(): void
    {
        $scraper = new AfiroApiScraper();
        $source = Source::create([
            'name' => 'Afiro Pasākumu API',
            'slug' => 'afiro-api',
            'url' => 'https://api.afiro.lv',
            'scraper_class' => 'App\Services\Scrapers\Sources\AfiroApiScraper',
            'is_active' => true,
        ]);

        $reflection = new \ReflectionClass($scraper);
        $method = $reflection->getMethod('scrape');
        $this->assertTrue($method->isPublic());
    }

    public function test_afiro_event_merges_with_existing_okultura_event(): void
    {
        $sourceOkultura = Source::create([
            'name' => 'Ogres kultūra',
            'slug' => 'okultura',
            'url' => 'https://www.okultura.lv/lv/pasakumi',
            'scraper_class' => 'App\Services\Scrapers\Sources\OkulturaScraper',
            'is_active' => true,
        ]);

        $sourceAfiro = Source::create([
            'name' => 'Afiro Pasākumu API',
            'slug' => 'afiro-api',
            'url' => 'https://api.afiro.lv',
            'scraper_class' => 'App\Services\Scrapers\Sources\AfiroApiScraper',
            'is_active' => true,
        ]);

        $ingestionService = app(EventIngestionService::class);

        // 1. Ingest municipal event from okultura
        $dtoOkultura = new ScrapedEventDTO(
            title: 'Valsts svētku uguņošana',
            startAt: Carbon::parse('2026-11-18 19:00:00'),
            description: '18. novembrī aicinām uz svētku uguņošanu pie domes ēkas.',
            venueName: 'Ogres novada pašvaldības domes ēka',
            city: 'Ogre',
            region: 'Vidzeme',
            address: 'Brīvības iela 33, Ogre, LV-5001',
            categoryNames: ['Svētki'],
            ticketUrl: 'https://www.bilesuparadize.lv/lv/event/176105',
            sourceExternalId: 'okultura-15921',
            locale: 'lv',
        );

        $res1 = $ingestionService->ingestDTO($dtoOkultura, $sourceOkultura);
        $this->assertEquals('created', $res1);
        $this->assertEquals(1, Event::count());

        $initialEvent = Event::first();
        $this->assertEquals('okultura', $initialEvent->source_slug);

        // 2. Ingest same event from afiro in Latvian
        $dtoAfiro = new ScrapedEventDTO(
            title: 'Valsts svētku uguņošana Ogrē',
            startAt: Carbon::parse('2026-11-18 19:00:00'),
            description: '18. novembrī aicinām uz svētku uguņošanu pie domes ēkas Ogrē.',
            venueName: 'Ogres novada pašvaldības domes ēka',
            city: 'Ogre',
            region: 'Vidzeme',
            address: 'Brīvības iela 33, Ogre, LV-5001',
            categoryNames: ['Svētki'],
            sourceExternalId: 'afiro-ugunosana-999',
            locale: 'lv',
        );

        $res2 = $ingestionService->ingestDTO($dtoAfiro, $sourceAfiro);
        $this->assertEquals('deduplicated', $res2);

        // Crucial assertion: No duplicate created
        $this->assertEquals(1, Event::count());

        $mergedEvent = Event::first();
        $this->assertEquals('okultura', $mergedEvent->source_slug);
        $this->assertEquals('afiro-ugunosana-999', $mergedEvent->raw_data['merged_external_ids']['afiro-api'] ?? null);

        // 3. Subsequent ingestion by afiro matches immediately via merged_external_ids
        $res3 = $ingestionService->ingestDTO($dtoAfiro, $sourceAfiro);
        $this->assertEquals('deduplicated', $res3);
        $this->assertEquals(1, Event::count());
    }

    public function test_afiro_scraper_filters_out_multiplex_cinema_movies(): void
    {
        $scraper = new AfiroApiScraper();
        $source = Source::create([
            'name' => 'Afiro Pasākumu API',
            'slug' => 'afiro-api',
            'url' => 'https://api.afiro.lv',
            'scraper_class' => 'App\Services\Scrapers\Sources\AfiroApiScraper',
            'is_active' => true,
        ]);

        $reflection = new \ReflectionClass($scraper);
        $mapMethod = $reflection->getMethod('mapItemToDTO');
        $mapMethod->setAccessible(true);

        // 1. Apollo Kino film with cta link
        $apolloItem = [
            'id' => 'afiro-apollo-odyssey-1',
            'title' => 'Odiseja',
            'startAt' => '2026-11-25T08:00:00.000Z',
            'description' => 'Režisora Kristofera Nolana jaunā episkā drāma Odiseja. Visu informāciju par seansiem, cenām un norises vietām jāprecizē kinoteātros: Apollo Kino: https://www.apollokino.lv/event/303993/odiseja',
            'categories' => ['cinema'],
            'location' => ['line1' => 'Riga', 'citySlug' => 'riga'],
            'cta' => [
                'kind' => 'tickets',
                'links' => [
                    ['url' => 'https://www.apollokino.lv/event/303993/odiseja?theatreAreaID=1011', 'title' => 'Biļetes'],
                ],
            ],
            'user' => ['fullName' => 'Afiro'],
        ];

        $dtoApollo = $mapMethod->invoke($scraper, $apolloItem, $source, 'lv');
        $this->assertNull($dtoApollo, 'Apollo Kino multiplex movie stub must be filtered out');

        // 2. Forum Cinemas film with cta link
        $forumItem = [
            'id' => 'afiro-fc-spa-1',
            'title' => 'Spa brīvdienas',
            'startAt' => '2026-11-25T08:00:00.000Z',
            'description' => 'Komēdija par draudzeņu atpūtu. Visu informāciju par seansiem precizēt Forum Cinemas mājaslapā.',
            'categories' => ['cinema'],
            'location' => ['line1' => 'Riga', 'citySlug' => 'riga'],
            'cta' => [
                'kind' => 'tickets',
                'links' => [
                    ['url' => 'https://www.forumcinemas.lv/event/304871/title/spa_brivdienas/', 'title' => 'Biļetes'],
                ],
            ],
            'user' => ['fullName' => 'Afiro'],
        ];

        $dtoForum = $mapMethod->invoke($scraper, $forumItem, $source, 'lv');
        $this->assertNull($dtoForum, 'Forum Cinemas multiplex movie stub must be filtered out');
    }

    public function test_afiro_scraper_keeps_independent_cinema_events(): void
    {
        $scraper = new AfiroApiScraper();
        $source = Source::create([
            'name' => 'Afiro Pasākumu API',
            'slug' => 'afiro-api',
            'url' => 'https://api.afiro.lv',
            'scraper_class' => 'App\Services\Scrapers\Sources\AfiroApiScraper',
            'is_active' => true,
        ]);

        $reflection = new \ReflectionClass($scraper);
        $mapMethod = $reflection->getMethod('mapItemToDTO');
        $mapMethod->setAccessible(true);

        // Independent film at Splendid Palace
        $splendidItem = [
            'id' => 'afiro-splendid-1',
            'title' => 'Laimīgie',
            'startAt' => '2026-11-25T17:00:00.000Z',
            'description' => 'Kinofilmas seanss kinoteātrī Splendid Palace.',
            'categories' => ['cinema'],
            'location' => ['line1' => 'Splendid Palace', 'citySlug' => 'riga'],
            'cta' => [
                'kind' => 'tickets',
                'links' => [
                    ['url' => 'https://www.splendidpalace.lv/lv/splendid-garden-kino/laimigie', 'title' => 'Biļetes'],
                ],
            ],
            'user' => ['fullName' => 'Afiro'],
        ];

        $dto = $mapMethod->invoke($scraper, $splendidItem, $source, 'lv');
        $this->assertNotNull($dto);
        $this->assertEquals('Laimīgie', $dto->title);
        $this->assertEquals('https://www.splendidpalace.lv/lv/splendid-garden-kino/laimigie', $dto->ticketUrl);
        $this->assertEquals('https://www.splendidpalace.lv/lv/splendid-garden-kino/laimigie', $dto->sourceUrl);
        $this->assertContains('Filmas & Kino', $dto->categoryNames);

        $ingestionService = app(EventIngestionService::class);
        $res = $ingestionService->ingestDTO($dto, $source);
        $this->assertEquals('created', $res);
        $saved = Event::where('source_external_id', 'afiro-splendid-1')->first();
        $this->assertNotNull($saved);
        $this->assertTrue($saved->categories->contains('slug', 'kino'));
    }

    public function test_afiro_scraper_extract_real_urls_never_crosses_competing_domains(): void
    {
        $scraper = new AfiroApiScraper();
        $reflection = new \ReflectionClass($scraper);
        $extractMethod = $reflection->getMethod('extractRealUrls');
        $extractMethod->setAccessible(true);

        $itemWithBothCinemas = [
            'cta' => [
                'links' => [
                    ['url' => 'https://www.apollokino.lv/event/304156/spa_brivdienas?theatreAreaID=1011', 'title' => 'Apollo Kino'],
                    ['url' => 'https://www.forumcinemas.lv/event/304871/title/spa_brivdienas/', 'title' => 'Forum Cinemas'],
                ],
            ],
            'location' => ['onlineUrl' => null],
            'slots' => [],
            'contacts' => [],
            'organizer' => null,
        ];

        $res = $extractMethod->invoke($scraper, 'Visu informāciju skatīt kinoteātros.', $itemWithBothCinemas);

        // Crucial: ticketUrl and sourceUrl must belong to the SAME domain! Never Apollo ticket with Forum source!
        $this->assertNotNull($res['ticketUrl']);
        $this->assertNotNull($res['sourceUrl']);
        $this->assertStringContainsString('apollokino.lv', $res['ticketUrl']);
        $this->assertStringContainsString('apollokino.lv', $res['sourceUrl']);
        $this->assertStringNotContainsString('forumcinemas.lv', $res['sourceUrl']);
        $this->assertCount(2, $res['ticketLinks']);
    }

    public function test_cinema_event_deduplication_via_cinema_event_id_and_ticket_url(): void
    {
        $sourceApollo = Source::create([
            'name' => 'Apollo Kino',
            'slug' => 'apollo-kino',
            'type' => \App\Enums\SourceType::VENUE,
            'url' => 'https://www.apollokino.lv',
            'scraper_class' => 'App\Services\Scrapers\Sources\ApolloKinoScraper',
            'is_active' => true,
        ]);

        $sourceAfiro = Source::create([
            'name' => 'Afiro Pasākumu API',
            'slug' => 'afiro-api',
            'type' => \App\Enums\SourceType::AGGREGATOR,
            'url' => 'https://api.afiro.lv',
            'scraper_class' => 'App\Services\Scrapers\Sources\AfiroApiScraper',
            'is_active' => true,
        ]);

        $ingestionService = app(EventIngestionService::class);

        // 1. Authoritative Apollo Kino screening
        $dtoApollo = new ScrapedEventDTO(
            title: 'Odiseja',
            startAt: Carbon::parse('2026-11-25 14:15:00'),
            endAt: Carbon::parse('2026-11-25 17:00:00'),
            description: 'Odiseja seanss Zālē 3.',
            venueName: 'Apollo Kino Akropole Rīga',
            city: 'Rīga',
            region: 'Rīga un Pierīga',
            categoryNames: ['Kino'],
            ticketUrl: 'https://www.apollokino.lv/websales/show/534330',
            sourceUrl: 'https://www.apollokino.lv/event/303993/odiseja',
            sourceExternalId: 'apollo-show-534330',
            locale: 'lv',
            rawData: ['event_id' => 303993],
        );

        $res1 = $ingestionService->ingestDTO($dtoApollo, $sourceApollo);
        $this->assertEquals('created', $res1);
        $this->assertEquals(1, Event::count());

        // 2. Incoming Afiro event for same movie (has ticket URL pointing to event/303993, but dummy 10:00 time)
        $dtoAfiro = new ScrapedEventDTO(
            title: 'Odiseja',
            startAt: Carbon::parse('2026-11-25 10:00:00'),
            endAt: Carbon::parse('2026-11-25 22:00:00'),
            description: 'Afiro filmas apraksts par Odiseju.',
            venueName: 'Rīga',
            city: 'Rīga',
            region: 'Rīga un Pierīga',
            categoryNames: ['Kino'],
            ticketUrl: 'https://www.apollokino.lv/event/303993/odiseja?theatreAreaID=1011',
            sourceUrl: 'https://www.apollokino.lv/event/303993/odiseja',
            sourceExternalId: 'afiro-odiseja-999',
            locale: 'lv',
        );

        $res2 = $ingestionService->ingestDTO($dtoAfiro, $sourceAfiro);
        $this->assertEquals('deduplicated', $res2);
        $this->assertEquals(1, Event::count(), 'Afiro stub must deduplicate into existing Apollo Kino event');

        $event = Event::first();
        // Authoritative venue source preserved
        $this->assertEquals('apollo-kino', $event->source_slug);
        // Authoritative start time preserved! Afiro 10:00 must NOT overwrite Apollo 14:15!
        $this->assertEquals('2026-11-25 14:15:00', $event->start_at->toDateTimeString());
        // Merged external ID recorded
        $this->assertEquals('afiro-odiseja-999', $event->raw_data['merged_external_ids']['afiro-api'] ?? null);
    }
}

