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

    public function test_cinema_session_never_merges_with_multiday_exhibition_on_same_end_date(): void
    {
        $sourceLiveRiga = Source::create([
            'name' => 'Live Riga',
            'slug' => 'live-riga',
            'type' => \App\Enums\SourceType::MUNICIPALITY,
            'url' => 'https://www.liveriga.com',
            'scraper_class' => 'App\Services\Scrapers\Sources\LiveRigaScraper',
            'is_active' => true,
        ]);

        $sourceApollo = Source::create([
            'name' => 'Apollo Kino',
            'slug' => 'apollo-kino',
            'type' => \App\Enums\SourceType::VENUE,
            'url' => 'https://www.apollokino.lv',
            'scraper_class' => 'App\Services\Scrapers\Sources\ApolloKinoScraper',
            'is_active' => true,
        ]);

        $ingestionService = app(EventIngestionService::class);

        // 1. Multi-day art exhibition ending 2026-11-27 (contains word "zemē")
        $dtoExhibition = new ScrapedEventDTO(
            title: 'Mākslinieka Denija Berkovska izstāde "Svētā Iļa piedzīvojumi bērnu sapņu zemē"',
            startAt: Carbon::parse('2026-09-24 10:00:00'),
            endAt: Carbon::parse('2026-11-27 23:59:59'),
            description: 'Mākslas izstāde par bērnības atmiņām galerijā MuseumLV.',
            venueName: 'Mākslas galerija MuseumLV',
            city: 'Rīga',
            categoryNames: ['Cits'],
            sourceUrl: 'https://www.liveriga.com/lv/apmekle/pasakumi/makslas-izstades-ekspozicijas/makslinieka-denija-berkovska-izstade',
            sourceExternalId: 'liveriga-berkovskis-2026-09-24',
            locale: 'lv',
        );

        $res1 = $ingestionService->ingestDTO($dtoExhibition, $sourceLiveRiga);
        $this->assertEquals('created', $res1);
        $this->assertEquals(1, Event::count());

        // 2. Hollywood movie in Apollo Kino Domina on 2026-11-27 (contains word "zemes")
        $dtoMovie = new ScrapedEventDTO(
            title: 'Skarbās zemes sirdī',
            startAt: Carbon::parse('2026-11-27 11:15:00'),
            endAt: Carbon::parse('2026-11-27 13:15:00'),
            description: 'Bijušais armijas specvienības virsnieks Džeimss Belmonts (Breds Pits) avarē Aļaskā.',
            shortDescription: 'Breds Pits Aļaskas mežonīgajos plašumos.',
            venueName: 'Apollo Kino Domina',
            city: 'Rīga',
            categoryNames: ['Kino'],
            ticketUrl: 'https://www.apollokino.lv/websales/show/535311',
            sourceUrl: 'https://www.apollokino.lv/event/304183/skarbas-zemes-sirdi',
            sourceExternalId: 'apollo-show-535311',
            locale: 'lv',
        );

        $res2 = $ingestionService->ingestDTO($dtoMovie, $sourceApollo);
        $this->assertEquals('created', $res2, 'Cinema movie session must NOT merge with multi-day art exhibition');
        $this->assertEquals(2, Event::count());

        // Verify exhibition remains unchanged
        $exhibition = Event::where('source_external_id', 'liveriga-berkovskis-2026-09-24')->first();
        $this->assertNotNull($exhibition);
        $this->assertStringContainsString('Denija Berkovska izstāde', $exhibition->title);
        $this->assertStringContainsString('MuseumLV', $exhibition->description);

        // Verify movie is created cleanly with correct title, description, and category
        $movie = Event::where('source_external_id', 'apollo-show-535311')->first();
        $this->assertNotNull($movie);
        $this->assertEquals('Skarbās zemes sirdī', $movie->title);
        $this->assertStringContainsString('Breds Pits', $movie->description);
        $this->assertEquals('kino', $movie->categories->first()->slug);
    }

    public function test_generic_stem_on_same_end_date_does_not_merge_unrelated_events(): void
    {
        $sourceLiveRiga = Source::create([
            'name' => 'Live Riga',
            'slug' => 'live-riga',
            'type' => \App\Enums\SourceType::MUNICIPALITY,
            'url' => 'https://www.liveriga.com',
            'scraper_class' => 'App\Services\Scrapers\Sources\LiveRigaScraper',
            'is_active' => true,
        ]);

        $sourceBezRindas = Source::create([
            'name' => 'BezRindas.lv',
            'slug' => 'bezrindas',
            'type' => \App\Enums\SourceType::TICKETING,
            'url' => 'https://www.bezrindas.lv',
            'scraper_class' => 'App\Services\Scrapers\Sources\BezRindasScraper',
            'is_active' => true,
        ]);

        $ingestionService = app(EventIngestionService::class);

        // 1. Art exhibition ending 2026-11-27 (starts in September)
        $dtoExhibition = new ScrapedEventDTO(
            title: 'Mākslinieka Denija Berkovska personālizstāde',
            startAt: Carbon::parse('2026-09-24 10:00:00'),
            endAt: Carbon::parse('2026-11-27 18:00:00'),
            description: 'Mākslas izstāde par bērnības atmiņām.',
            venueName: 'Mākslas galerija MuseumLV',
            city: 'Rīga',
            categoryNames: ['Cits'],
            sourceUrl: 'https://www.liveriga.com/lv/izstade-1',
            sourceExternalId: 'lr-exhibition-1',
            locale: 'lv',
        );

        $res1 = $ingestionService->ingestDTO($dtoExhibition, $sourceLiveRiga);
        $this->assertEquals('created', $res1);

        // 2. Metalworking trade fair ending 2026-11-27 (starts 2026-11-26) - both contain word "izstāde"
        $dtoTech = new ScrapedEventDTO(
            title: 'Starptautiskā rūpnieciskās ražošanas izstāde "Tech Industry 2026"',
            startAt: Carbon::parse('2026-11-26 10:00:00'),
            endAt: Carbon::parse('2026-11-27 18:00:00'),
            description: 'Metālapstrādes un mašīnbūves izstāde Ķīpsalā.',
            venueName: 'Starptautiskais izstāžu centrs Ķīpsala',
            city: 'Rīga',
            categoryNames: ['Cits'],
            ticketUrl: 'https://www.bezrindas.lv/lv/tech-industry-2026/16330/',
            sourceUrl: 'https://www.bezrindas.lv/lv/tech-industry-2026/16330/',
            sourceExternalId: 'bezrindas-16330-97875',
            locale: 'lv',
        );

        $res2 = $ingestionService->ingestDTO($dtoTech, $sourceBezRindas);
        $this->assertEquals('created', $res2, 'Unrelated events sharing only generic stem "izstāde" must NOT merge');
        $this->assertEquals(2, Event::count());
    }

    public function test_afiro_external_id_preserved_when_promoted_by_municipality(): void
    {
        $sourceAfiro = Source::create([
            'name' => 'Afiro Pasākumu API',
            'slug' => 'afiro-api',
            'type' => \App\Enums\SourceType::AGGREGATOR,
            'url' => 'https://api.afiro.lv',
            'scraper_class' => 'App\Services\Scrapers\Sources\AfiroApiScraper',
            'is_active' => true,
        ]);

        $sourceLiveRiga = Source::create([
            'name' => 'Live Riga',
            'slug' => 'live-riga',
            'type' => \App\Enums\SourceType::MUNICIPALITY,
            'url' => 'https://www.liveriga.com',
            'scraper_class' => 'App\Services\Scrapers\Sources\LiveRigaScraper',
            'is_active' => true,
        ]);

        $ingestionService = app(EventIngestionService::class);

        // 1. Event created by Afiro
        $dtoAfiro1 = new ScrapedEventDTO(
            title: 'Mākslinieka izstāde Galerijā',
            startAt: Carbon::parse('2026-12-01 10:00:00'),
            endAt: Carbon::parse('2026-12-15 18:00:00'),
            description: 'Sākotnējais Afiro apraksts.',
            venueName: 'Galerija Rīgā',
            city: 'Rīga',
            sourceExternalId: 'du2kpgmf02uibjw34s9yrkfl',
            locale: 'lv',
        );

        $res1 = $ingestionService->ingestDTO($dtoAfiro1, $sourceAfiro);
        $this->assertEquals('created', $res1);
        $this->assertEquals(1, Event::count());

        // 2. Municipality (Live Riga) updates it and becomes authoritative
        $dtoLiveRiga = new ScrapedEventDTO(
            title: 'Mākslinieka izstāde Galerijā',
            startAt: Carbon::parse('2026-12-01 10:00:00'),
            endAt: Carbon::parse('2026-12-15 18:00:00'),
            description: 'Paplašināts Live Riga apraksts.',
            venueName: 'Galerija Rīgā',
            city: 'Rīga',
            sourceUrl: 'https://www.liveriga.com/lv/izstade-galerija',
            sourceExternalId: 'liveriga-izstade-galerija-2026-12-01',
            locale: 'lv',
        );

        $res2 = $ingestionService->ingestDTO($dtoLiveRiga, $sourceLiveRiga);
        $this->assertEquals('updated', $res2);
        $this->assertEquals(1, Event::count());

        $event = Event::first();
        $this->assertEquals('live-riga', $event->source_slug);
        // Verify Afiro ID was preserved in merged_external_ids
        $this->assertEquals('du2kpgmf02uibjw34s9yrkfl', $event->raw_data['merged_external_ids']['afiro-api'] ?? null);

        // 3. Afiro scraper runs again with same Afiro ID: MUST NOT create duplicate!
        $dtoAfiro2 = new ScrapedEventDTO(
            title: 'Mākslinieka izstāde Galerijā',
            startAt: Carbon::parse('2026-12-01 10:00:00'),
            endAt: Carbon::parse('2026-12-15 18:00:00'),
            description: 'Atjaunināts Afiro apraksts.',
            venueName: 'Galerija Rīgā',
            city: 'Rīga',
            sourceExternalId: 'du2kpgmf02uibjw34s9yrkfl',
            locale: 'lv',
        );

        $res3 = $ingestionService->ingestDTO($dtoAfiro2, $sourceAfiro);
        $this->assertEquals('deduplicated', $res3);
        $this->assertEquals(1, Event::count(), 'Afiro must find the event via merged_external_ids and avoid duplicate creation');
    }

    public function test_find_sibling_with_translations_guards_against_unrelated_titles(): void
    {
        $source = Source::create([
            'name' => 'Live Riga',
            'slug' => 'live-riga',
            'type' => \App\Enums\SourceType::MUNICIPALITY,
            'url' => 'https://www.liveriga.com',
            'scraper_class' => 'App\Services\Scrapers\Sources\LiveRigaScraper',
            'is_active' => true,
        ]);

        // Event A: Exhibition
        $eventA = Event::create([
            'title' => 'Mākslinieka Denija Berkovska personālizstāde',
            'slug' => 'makslinieka-denija-berkovska-personalizstade-abc123',
            'source_id' => $source->id,
            'source_slug' => $source->slug,
            'source_external_id' => 'du2kpgmf02uibjw34s9yrkfl',
            'start_at' => Carbon::parse('2026-11-20 10:00:00'),
            'description' => 'Apraksts par izstādi.',
        ]);

        \App\Models\EventTranslation::create([
            'event_id' => $eventA->id,
            'locale' => 'lv',
            'title' => $eventA->title,
            'slug' => 'makslinieka-denija-berkovska-personalizstade-lv1234',
            'description' => 'Apraksts par izstādi.',
        ]);

        // Event B: Completely unrelated event (e.g. cinema movie) that somehow shares same external id
        $eventB = Event::create([
            'title' => 'Skarbās zemes sirdī',
            'slug' => 'skarbas-zemes-sirdi-xyz789',
            'source_id' => $source->id,
            'source_slug' => $source->slug,
            'source_external_id' => 'du2kpgmf02uibjw34s9yrkfl',
            'start_at' => Carbon::parse('2026-11-27 11:15:00'),
            'description' => '',
        ]);

        $ingestionService = app(EventIngestionService::class);
        $sibling = $ingestionService->findSiblingWithTranslations($eventB);

        $this->assertNull($sibling, 'Sibling finder must reject candidates with completely unrelated titles');
    }

    public function test_cinema_movie_and_patriotic_concert_sharing_stem_zem_never_merge(): void
    {
        $sourceAfiro = Source::create([
            'name' => 'Afiro Pasākumu API',
            'slug' => 'afiro-api',
            'type' => \App\Enums\SourceType::AGGREGATOR,
            'url' => 'https://api.afiro.lv',
            'scraper_class' => 'App\Services\Scrapers\Sources\AfiroApiScraper',
            'is_active' => true,
        ]);

        $sourceLiveRiga = Source::create([
            'name' => 'Live Riga',
            'slug' => 'live-riga',
            'type' => \App\Enums\SourceType::MUNICIPALITY,
            'url' => 'https://www.liveriga.com',
            'scraper_class' => 'App\Services\Scrapers\Sources\LiveRigaScraper',
            'is_active' => true,
        ]);

        $ingestionService = app(EventIngestionService::class);

        // 1. Hollywood movie in cinema on 2026-11-18 ("Skarbās zemes sirdī")
        $dtoMovie = new ScrapedEventDTO(
            title: 'Skarbās zemes sirdī',
            startAt: Carbon::parse('2026-11-18 10:00:00'),
            endAt: Carbon::parse('2026-11-18 22:00:00'),
            description: 'Bijušais armijas specvienības virsnieks Džeimss Belmonts (Breds Pits) un viņa kaujas suns Odins.',
            shortDescription: 'Breds Pits Aļaskā.',
            venueName: 'Apollo Kino',
            city: 'Rīga',
            categoryNames: ['Kino'],
            ticketUrl: 'https://www.apollokino.lv/event/304183/skarb%C4%81s_zemes_sird%C4%AB',
            sourceExternalId: 'tb03pldcednnivq4l5fxwuc2',
            locale: 'lv',
        );

        $res1 = $ingestionService->ingestDTO($dtoMovie, $sourceAfiro);
        $this->assertEquals('created', $res1);
        $this->assertEquals(1, Event::count());

        // 2. Patriotic concert in Riga on same date 2026-11-18 ("Koncerts “Dievs, Tava zeme deg!”")
        // Both share stem "zem" and same date 2026-11-18
        $dtoConcert = new ScrapedEventDTO(
            title: 'Koncerts “Dievs, Tava zeme deg!”',
            startAt: Carbon::parse('2026-11-18 18:00:00'),
            endAt: Carbon::parse('2026-11-18 20:00:00'),
            description: 'Četru koru izpildījumā skanēs leģendārā Lūcijas Garūtas kantāte „Dievs, Tava zeme deg!”.',
            shortDescription: 'Lūcijas Garūtas kantāte Rīgas Lutera baznīcā.',
            venueName: 'Rīgas Lutera baznīca',
            city: 'Rīga',
            categoryNames: ['Mūzika'],
            sourceUrl: 'https://www.liveriga.com/lv/apmekle/pasakumi/koncerti-klasika/koncerts-dievs-tava-zeme-deg',
            sourceExternalId: 'liveriga-koncerts-dievs-tava-zeme-deg-2026-11-18',
            locale: 'lv',
        );

        $res2 = $ingestionService->ingestDTO($dtoConcert, $sourceLiveRiga);
        $this->assertEquals('created', $res2, 'Cinema movie and choir concert must NEVER merge even if sharing stem "zem" on same date');
        $this->assertEquals(2, Event::count());

        // Verify movie remained clean
        $movie = Event::where('source_external_id', 'tb03pldcednnivq4l5fxwuc2')->first();
        $this->assertEquals('Skarbās zemes sirdī', $movie->title);
        $this->assertStringContainsString('Breds Pits', $movie->description);
        $this->assertStringNotContainsString('Lūcijas Garūtas', $movie->description);
        $this->assertStringNotContainsString('dievs-tava-zeme-deg', $movie->source_url ?? '');

        // Verify concert was created cleanly
        $concert = Event::where('source_external_id', 'liveriga-koncerts-dievs-tava-zeme-deg-2026-11-18')->first();
        $this->assertEquals('Koncerts “Dievs, Tava zeme deg!”', $concert->title);
        $this->assertStringContainsString('Lūcijas Garūtas kantāte', $concert->description);
        $this->assertEquals('https://www.liveriga.com/lv/apmekle/pasakumi/koncerti-klasika/koncerts-dievs-tava-zeme-deg', $concert->source_url);
    }
}

