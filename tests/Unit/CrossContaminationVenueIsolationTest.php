<?php

namespace Tests\Unit;

use App\Console\Commands\ConsolidateEventsCommand;
use App\Enums\SourceType;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventTranslation;
use App\Models\Location;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrossContaminationVenueIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Source $sourceApollo;
    protected Source $sourceBs;
    protected Source $sourceBp;
    protected Source $sourceBezrindas;

    protected function setUp(): void
    {
        parent::setUp();

        Category::firstOrCreate(['slug' => 'kino'], ['name' => 'Kino']);
        Category::firstOrCreate(['slug' => 'teatris'], ['name' => 'Teātris']);
        Category::firstOrCreate(['slug' => 'muzika'], ['name' => 'Mūzika']);
        Category::firstOrCreate(['slug' => 'citi'], ['name' => 'Cits']);

        $this->sourceApollo = Source::create([
            'name' => 'Apollo Kino',
            'slug' => 'apollo-kino',
            'url' => 'https://www.apollokino.lv',
            'scraper_class' => \App\Services\Scrapers\Sources\ApolloKinoScraper::class,
            'type' => SourceType::VENUE,
            'is_active' => true,
        ]);

        $this->sourceBs = Source::create([
            'name' => 'Biļešu Serviss',
            'slug' => 'bilesu-serviss',
            'url' => 'https://www.bilesuserviss.lv',
            'scraper_class' => \App\Services\Scrapers\Sources\BilesuServissScraper::class,
            'type' => SourceType::TICKETING,
            'is_active' => true,
        ]);

        $this->sourceBp = Source::create([
            'name' => 'Biļešu Paradīze',
            'slug' => 'bilesu-paradize',
            'url' => 'https://www.bilesuparadize.lv',
            'scraper_class' => \App\Services\Scrapers\Sources\BilesuParadizeScraper::class,
            'type' => SourceType::TICKETING,
            'is_active' => true,
        ]);

        $this->sourceBezrindas = Source::create([
            'name' => 'BezRindas',
            'slug' => 'bezrindas',
            'url' => 'https://www.bezrindas.lv',
            'scraper_class' => \App\Services\Scrapers\Sources\BezRindasScraper::class,
            'type' => SourceType::TICKETING,
            'is_active' => true,
        ]);
    }

    public function test_bilesuserviss_url_equivalence_requires_matching_session_code(): void
    {
        // Sigulda session vs Jelgava session with identical movie slug "jumprava-lielais-notikums"
        $urlSigulda = 'https://www.bilesuserviss.lv/biletes/I2PU3IJQ3B/jumprava-lielais-notikums';
        $urlJelgava = 'https://www.bilesuserviss.lv/biletes/5U3LJAUUAH/jumprava-lielais-notikums';

        $this->assertFalse(
            EventIngestionService::areTicketingUrlsEquivalent($urlSigulda, $urlJelgava),
            'Different session codes on bilesuserviss must NEVER be equivalent despite matching title slug'
        );

        // Same session code with language or tracking variation
        $urlSiguldaRu = 'https://www.bilesuserviss.lv/rus/biletes/I2PU3IJQ3B/jumprava-lielais-notikums?utm_source=test';
        $this->assertTrue(
            EventIngestionService::areTicketingUrlsEquivalent($urlSigulda, $urlSiguldaRu),
            'Same session code on bilesuserviss must be equivalent across locales'
        );
    }

    public function test_cinema_ticketing_urls_with_different_show_ids_are_not_equivalent(): void
    {
        $apolloShow1 = 'https://www.apollokino.lv/websales/show/533231';
        $apolloShow2 = 'https://www.apollokino.lv/websales/show/533232';

        $this->assertFalse(
            EventIngestionService::areTicketingUrlsEquivalent($apolloShow1, $apolloShow2),
            'Different Apollo Kino show IDs must not be equivalent'
        );

        $forumShow1 = 'https://www.forumcinemas.lv/websales/show/444008';
        $forumShow2 = 'https://www.forumcinemas.lv/websales/show/444009';

        $this->assertFalse(
            EventIngestionService::areTicketingUrlsEquivalent($forumShow1, $forumShow2),
            'Different Forum Cinemas show IDs must not be equivalent'
        );
    }

    public function test_generic_city_name_is_not_equivalent_to_specific_venue(): void
    {
        $this->assertFalse(EventIngestionService::areVenuesEquivalent('Apollo Kino Rīga Plaza', 'Rīga'));
        $this->assertFalse(EventIngestionService::areVenuesEquivalent('Rīga', 'Forum Cinemas'));
        $this->assertFalse(EventIngestionService::areVenuesEquivalent('Dailes teātris', 'Rīga'));
        $this->assertFalse(EventIngestionService::areVenuesEquivalent('Koncertzāle Cēsis', 'Cēsis'));
        $this->assertFalse(EventIngestionService::areVenuesEquivalent('Kino Lora, Sigulda', 'Sigulda'));
    }

    public function test_cinema_clusters_and_branches_are_strictly_isolated(): void
    {
        // Different Apollo theatres
        $this->assertFalse(EventIngestionService::areVenuesEquivalent('Apollo Kino Rīga Plaza', 'Apollo Kino Domina'));
        $this->assertFalse(EventIngestionService::areVenuesEquivalent('Apollo Kino Akropole Rīga', 'Apollo Kino Akropole Alfa'));

        // Apollo vs other cinemas
        $this->assertFalse(EventIngestionService::areVenuesEquivalent('Apollo Kino Rīga Plaza', 'Forum Cinemas'));
        $this->assertFalse(EventIngestionService::areVenuesEquivalent('Apollo Kino Rīga Plaza', 'Kino Lora, Sigulda'));
        $this->assertFalse(EventIngestionService::areVenuesEquivalent('Apollo Kino Rīga Plaza', 'K.Suns'));
        $this->assertFalse(EventIngestionService::areVenuesEquivalent('Apollo Kino Rīga Plaza', 'Koncertzāle CĒSIS'));

        // Strict identical cinema check
        $this->assertTrue(EventIngestionService::areCinemasStrictlyIdentical('Apollo Kino Rīga Plaza', 'Apollo Kino Plaza, Rīga'));
        $this->assertFalse(EventIngestionService::areCinemasStrictlyIdentical('Apollo Kino Rīga Plaza', 'Apollo Kino Domina'));
        $this->assertFalse(EventIngestionService::areCinemasStrictlyIdentical('Apollo Kino Rīga Plaza', 'Forum Cinemas'));
        $this->assertFalse(EventIngestionService::areCinemasStrictlyIdentical('Apollo Kino Rīga Plaza', 'Kino Lora'));
    }

    public function test_theaters_and_concert_halls_are_strictly_isolated(): void
    {
        $this->assertFalse(EventIngestionService::areVenuesEquivalent('Dailes teātris', 'Jaunais Rīgas teātris'));
        $this->assertFalse(EventIngestionService::areVenuesEquivalent('Dailes teātris', 'Latvijas Nacionālais teātris'));
        $this->assertFalse(EventIngestionService::areVenuesEquivalent('Valmieras drāmas teātris', 'Liepājas teātris'));
        $this->assertFalse(EventIngestionService::areVenuesEquivalent('Arēna Rīga', 'Palladium Rīga'));
        $this->assertFalse(EventIngestionService::areVenuesEquivalent('Arēna Rīga', 'Hanzas Perons'));
        $this->assertFalse(EventIngestionService::areVenuesEquivalent('Lielais Dzintars', 'Koncertzāle Cēsis'));
    }

    public function test_cross_venue_cinema_screenings_never_merge_in_consolidate_command(): void
    {
        $locPlaza = Location::create(['name' => 'Apollo Kino Rīga Plaza', 'city' => 'Rīga', 'region' => 'Rīga']);
        $locDomina = Location::create(['name' => 'Apollo Kino Domina', 'city' => 'Rīga', 'region' => 'Rīga']);
        $locLora = Location::create(['name' => 'Kino Lora, Sigulda', 'city' => 'Sigulda', 'region' => 'Vidzeme']);
        $locCesis = Location::create(['name' => 'Koncertzāle Cēsis', 'city' => 'Cēsis', 'region' => 'Vidzeme']);

        $categoryKino = Category::where('slug', 'kino')->first();

        $eventApollo = Event::create([
            'title' => 'JUMPRAVA – LIELAIS NOTIKUMS',
            'start_at' => Carbon::parse('2026-12-01 19:00:00'),
            'location_id' => $locPlaza->id,
            'source_id' => $this->sourceApollo->id,
            'source_slug' => 'apollo-kino',
            'ticket_url' => 'https://www.apollokino.lv/websales/show/535535',
            'description' => 'Apollo Plaza seanss 2. zālē.',
        ]);
        $eventApollo->categories()->attach($categoryKino);

        $eventDomina = Event::create([
            'title' => 'JUMPRAVA – LIELAIS NOTIKUMS',
            'start_at' => Carbon::parse('2026-12-01 19:00:00'),
            'location_id' => $locDomina->id,
            'source_id' => $this->sourceApollo->id,
            'source_slug' => 'apollo-kino',
            'ticket_url' => 'https://www.apollokino.lv/websales/show/535540',
            'description' => 'Apollo Domina seanss.',
        ]);
        $eventDomina->categories()->attach($categoryKino);

        $eventSigulda = Event::create([
            'title' => 'JUMPRAVA – LIELAIS NOTIKUMS',
            'start_at' => Carbon::parse('2026-12-01 19:00:00'),
            'location_id' => $locLora->id,
            'source_id' => $this->sourceBs->id,
            'source_slug' => 'bilesu-serviss',
            'ticket_url' => 'https://www.bilesuserviss.lv/biletes/I2PU3IJQ3B/jumprava-lielais-notikums',
            'description' => 'Kino Lora seanss Siguldā.',
        ]);
        $eventSigulda->categories()->attach($categoryKino);

        $eventCesis = Event::create([
            'title' => 'JUMPRAVA – LIELAIS NOTIKUMS',
            'start_at' => Carbon::parse('2026-12-01 19:00:00'),
            'location_id' => $locCesis->id,
            'source_id' => $this->sourceBp->id,
            'source_slug' => 'bilesu-paradize',
            'ticket_url' => 'https://www.bilesuparadize.lv/lv/event/181791',
            'description' => 'Cēsu seanss.',
        ]);
        $eventCesis->categories()->attach($categoryKino);

        $cmd = new ConsolidateEventsCommand();

        // Apollo Plaza must NOT merge with Apollo Domina
        $this->assertFalse($cmd->shouldMerge($eventApollo, $eventDomina), 'Plaza and Domina must NEVER merge');

        // Apollo Plaza must NOT merge with Sigulda
        $this->assertFalse($cmd->shouldMerge($eventApollo, $eventSigulda), 'Plaza and Sigulda must NEVER merge');

        // Apollo Plaza must NOT merge with Cēsis
        $this->assertFalse($cmd->shouldMerge($eventApollo, $eventCesis), 'Plaza and Cēsis must NEVER merge');
    }

    public function test_apollo_kino_model_ticket_links_strictly_isolated(): void
    {
        $locPlaza = Location::create(['name' => 'Apollo Kino Rīga Plaza', 'city' => 'Rīga', 'region' => 'Rīga']);

        $event = Event::create([
            'title' => 'JUMPRAVA – LIELAIS NOTIKUMS',
            'start_at' => Carbon::parse('2026-12-01 19:00:00'),
            'location_id' => $locPlaza->id,
            'source_id' => $this->sourceApollo->id,
            'source_slug' => 'apollo-kino',
            'ticket_url' => 'https://www.apollokino.lv/websales/show/535535',
            'description' => 'Apollo seanss. Biļetes: https://www.bilesuparadize.lv/lv/event/181791',
            'raw_data' => [
                'cta' => [
                    'kind' => 'tickets',
                    'links' => [
                        ['url' => 'https://www.bilesuparadize.lv/lv/event/181725?utm_source=afiro', 'title' => 'Biļešu Paradīze'],
                    ],
                ],
                'ticket_links' => [
                    ['url' => 'https://www.apollokino.lv/websales/show/535535', 'title' => 'Apollo Kino'],
                    ['url' => 'https://www.bilesuparadize.lv/lv/event/181791', 'title' => 'Biļešu Paradīze Cēsis'],
                    ['url' => 'https://www.bilesuserviss.lv/biletes/I2PU3IJQ3B/jumprava-lielais-notikums', 'title' => 'Biļešu Serviss Sigulda'],
                    ['url' => 'https://www.bezrindas.lv/lv/jumprava-lielais-notikums/16817/102401', 'title' => 'BezRindas K.Suns'],
                ],
            ],
        ]);

        $links = $event->ticket_links;

        // Must ONLY return apollokino.lv link!
        $this->assertCount(1, $links);
        $this->assertEquals('https://www.apollokino.lv/websales/show/535535', $links[0]['url']);
    }

    public function test_repair_command_cleans_contaminated_apollo_events(): void
    {
        $locPlaza = Location::create(['name' => 'Apollo Kino Rīga Plaza', 'city' => 'Rīga', 'region' => 'Rīga']);

        // Contaminated Apollo Event #10260 representation
        $event = Event::create([
            'title' => 'JUMPRAVA – LIELAIS NOTIKUMS',
            'start_at' => Carbon::now()->addDays(5),
            'location_id' => $locPlaza->id,
            'source_id' => $this->sourceApollo->id,
            'source_slug' => 'apollo-kino',
            'source_url' => 'https://www.apollokino.lv/websales/show/535535',
            'ticket_url' => 'https://www.bilesuserviss.lv/biletes/I2PU3IJQ3B/jumprava-lielais-notikums',
            'short_description' => 'Filma stāsta par leģendāro grupu Jumprava.',
            'description' => "Organizators: Kino Lora SIA ... UZRĀDOT SIGULDAS NOVADA IEDZĪVOTĀJU KARTI, BIĻETES CENA CETURTDIENAS SEANSIEM 7,50 euro...",
            'raw_data' => [
                'cta' => [
                    'kind' => 'tickets',
                    'links' => [
                        ['id' => 'abc1', 'url' => 'https://www.bilesuparadize.lv/lv/event/181725?utm_source=afiro', 'title' => null],
                    ],
                ],
                'merged_external_ids' => [
                    'apollo-kino' => '535535',
                    'bilesu-serviss' => 'bilesuserviss-I2PU3IJQ3B',
                    'afiro-api' => 'h5n1n2np7mab7q1z23dtgv1u',
                ],
                'ticket_links' => [
                    ['url' => 'https://www.apollokino.lv/websales/show/535535', 'title' => 'Apollo'],
                    ['url' => 'https://www.bilesuparadize.lv/lv/event/181791', 'title' => 'Cēsis'],
                ],
            ],
        ]);

        $this->artisan('events:repair-cross-contamination')
            ->expectsOutputToContain('Completed.')
            ->assertExitCode(0);

        $event->refresh();

        // 1. ticket_url restored to Apollo
        $this->assertStringContainsString('apollokino.lv', $event->ticket_url);

        // 2. foreign ticket_links removed from raw_data
        $this->assertCount(1, $event->raw_data['ticket_links']);
        $this->assertStringContainsString('apollokino.lv', $event->raw_data['ticket_links'][0]['url']);

        // 2.2 foreign cta.links removed from raw_data
        $this->assertEmpty($event->raw_data['cta']['links']);

        // 2.3 ticket_links accessor returns only Apollo
        $this->assertCount(1, $event->ticket_links);
        $this->assertStringContainsString('apollokino.lv', $event->ticket_links[0]['url']);

        // 3. foreign merged_external_ids purged
        $this->assertArrayNotHasKey('bilesu-serviss', $event->raw_data['merged_external_ids']);
        $this->assertArrayHasKey('apollo-kino', $event->raw_data['merged_external_ids']);

        // 4. description cleaned of Kino Lora / Sigulda notes
        $this->assertStringNotContainsString('Kino Lora', $event->description);
        $this->assertStringNotContainsString('SIGULDAS', $event->description);
        $this->assertEquals('Filma stāsta par leģendāro grupu Jumprava.', $event->description);
    }

    public function test_cross_city_events_never_merge_even_with_identical_titles(): void
    {
        $locJelgava = Location::create(['name' => 'Hercoga Jēkaba laukums', 'city' => 'Jelgava', 'region' => 'Zemgale']);
        $locVentspils = Location::create(['name' => 'Lielais laukums', 'city' => 'Ventspils', 'region' => 'Kurzeme']);

        $categoryCiti = Category::where('slug', 'citi')->first();

        $eventJelgava = Event::create([
            'title' => 'Jaunā gada sagaidīšana',
            'start_at' => Carbon::parse('2026-12-31 23:00:00'),
            'location_id' => $locJelgava->id,
            'description' => 'Jaunā gada sagaidīšana Jelgavā.',
        ]);
        $eventJelgava->categories()->attach($categoryCiti);

        $eventVentspils = Event::create([
            'title' => 'Jaunā gada sagaidīšana',
            'start_at' => Carbon::parse('2026-12-31 23:00:00'),
            'location_id' => $locVentspils->id,
            'description' => 'Svētku koncerts un Jaunā gada sagaidīšana pēc Ventspils laika.',
        ]);
        $eventVentspils->categories()->attach($categoryCiti);

        $cmd = new ConsolidateEventsCommand();
        $this->assertFalse(
            $cmd->shouldMerge($eventJelgava, $eventVentspils),
            'Same title events in different cities (Jelgava vs Ventspils) must NEVER merge!'
        );
    }

    public function test_different_sports_at_same_location_and_time_never_merge(): void
    {
        $locOc = Location::create(['name' => 'Olimpiskais centrs Ventspils', 'city' => 'Ventspils', 'region' => 'Kurzeme']);
        $categoryCiti = Category::where('slug', 'citi')->first();

        $eventBoxing = Event::create([
            'title' => 'Ventspils Starptautiskais Kauss Boksā 2026',
            'start_at' => Carbon::parse('2026-12-12 10:00:00'),
            'location_id' => $locOc->id,
            'description' => 'Boksa kausa izcīņa boksa zālē.',
        ]);
        $eventBoxing->categories()->attach($categoryCiti);

        $eventBasketball = Event::create([
            'title' => 'Latvijas Jaunatnes basketbola līgas spēles zēniem Ventspils “Spars” – BK Ķekava',
            'start_at' => Carbon::parse('2026-12-12 10:00:00'),
            'location_id' => $locOc->id,
            'description' => 'Basketbola spēle basketbola hallē.',
        ]);
        $eventBasketball->categories()->attach($categoryCiti);

        $cmd = new ConsolidateEventsCommand();
        $this->assertFalse(
            $cmd->shouldMerge($eventBoxing, $eventBasketball),
            'Different sports (Boxing vs Basketball) at the same venue and time must NEVER merge!'
        );
    }

    public function test_repair_command_cleans_cross_city_and_cross_sport_contamination(): void
    {
        $locJelgava = Location::create(['name' => 'Hercoga Jēkaba laukums', 'city' => 'Jelgava', 'region' => 'Zemgale']);
        $locOc = Location::create(['name' => 'Olimpiskais centrs Ventspils', 'city' => 'Ventspils', 'region' => 'Kurzeme']);

        // Event representing corrupted #5362 (Jelgava with Ventspils merged & description)
        $eventJelgava = Event::create([
            'title' => 'Jaunā gada sagaidīšana',
            'start_at' => Carbon::now()->addDays(10),
            'location_id' => $locJelgava->id,
            'description' => 'Svētku koncerts un Jaunā gada sagaidīšana pēc Ventspils laika plkst. 00.33.44.',
            'raw_data' => [
                'merged_external_ids' => [
                    'jelgavas-valstspilseta' => 'jelgava-jauna-gada-sagaidisana-2',
                    'visitventspils' => 'visitventspils-221235',
                ],
                'location_raw' => 'Lielais laukums',
            ],
        ]);

        // Event representing corrupted #6873 (Ventspils boxing with basketball merged & description)
        $eventBoxing = Event::create([
            'title' => 'Ventspils Starptautiskais Kauss Boksā 2026',
            'start_at' => Carbon::now()->addDays(12),
            'location_id' => $locOc->id,
            'short_description' => 'Ventspils Starptautiskais Kauss Boksā 2026',
            'description' => 'Latvijas Jaunatnes basketbola līgas spēles zēniem Ventspils “Spars” – BK Ķekava (U-14, U-15 vec.gr.)',
            'raw_data' => [
                'merged_external_ids' => [
                    'visitventspils' => 'visitventspils-233517',
                    'ventspils' => 'ventspils-latvijas-jaunatnes-basketbola-ligas-spele-zeniem-ventspils-spars-kandavas-bjss-u-16-vec-gr',
                ],
            ],
        ]);

        $this->artisan('events:repair-cross-contamination')
            ->expectsOutputToContain('Completed.')
            ->assertExitCode(0);

        $eventJelgava->refresh();
        $eventBoxing->refresh();

        // 1. Jelgava event: visitventspils purged from merged IDs
        $this->assertArrayNotHasKey('visitventspils', $eventJelgava->raw_data['merged_external_ids']);
        $this->assertArrayHasKey('jelgavas-valstspilseta', $eventJelgava->raw_data['merged_external_ids']);
        // 2. Jelgava event: Ventspils text cleaned from description
        $this->assertStringNotContainsString('Ventspils', $eventJelgava->description);

        // 3. Boxing event: basketball purged from merged IDs
        $this->assertArrayNotHasKey('ventspils', $eventBoxing->raw_data['merged_external_ids']);
        $this->assertArrayHasKey('visitventspils', $eventBoxing->raw_data['merged_external_ids']);
        // 4. Boxing event: basketball description cleaned
        $this->assertStringNotContainsString('basketbola', $eventBoxing->description);
    }

    public function test_merge_descriptions_rejects_cross_city_contamination(): void
    {
        $existingJelgavaDesc = "Jaunā gada sagaidīšana Jelgavā.";
        $incomingVentspilsDesc = "Svētku koncerts un Jaunā gada sagaidīšana pēc Ventspils laika plkst. 00.33.44.";

        $merged = EventIngestionService::mergeDescriptions($existingJelgavaDesc, $incomingVentspilsDesc, false, 'Jelgava');

        $this->assertStringNotContainsString('Ventspils', $merged);
        $this->assertEquals($existingJelgavaDesc, $merged);
    }

    public function test_sports_and_theatre_events_at_same_venue_and_time_never_merge(): void
    {
        $locDaugavpils = Location::create([
            'name' => 'Daugavpils Kultūras Pils',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
        ]);

        $categorySports = Category::firstOrCreate(['slug' => 'sports'], ['name' => 'Sports']);
        $categoryTeatris = Category::firstOrCreate(['slug' => 'teatris'], ['name' => 'Teātris']);

        $eventBasketball = Event::create([
            'title' => 'Daugavpils Universitāte vs TTT Rīga Kadetes',
            'start_at' => Carbon::parse('2026-11-26 18:30:00'),
            'location_id' => $locDaugavpils->id,
            'source_slug' => 'basket-lv',
            'description' => 'BioSil SBBL basketbola spēle sporta zālē.',
        ]);
        $eventBasketball->categories()->attach($categorySports);

        $eventPlay = Event::create([
            'title' => 'MELI BEZ NOTEIKUMIEM',
            'start_at' => Carbon::parse('2026-11-26 18:30:00'),
            'location_id' => $locDaugavpils->id,
            'source_slug' => 'bilesu-paradize',
            'ticket_url' => 'https://www.bilesuparadize.lv/lv/event/178466',
            'description' => 'Hermanis Paukšs. Meli bez noteikumiem. Psiholoģiska drāma. Režisors Oļegs Šapošņikovs.',
        ]);
        $eventPlay->categories()->attach($categoryTeatris);

        $cmd = new ConsolidateEventsCommand();
        $this->assertFalse(
            $cmd->shouldMerge($eventBasketball, $eventPlay),
            'Basketball match and Theatre play at the exact same location and time must NEVER merge!'
        );
    }

    public function test_repair_command_cleans_sports_event_contaminated_with_theatre_data(): void
    {
        $locDaugavpils = Location::create([
            'name' => 'Daugavpils Kultūras Pils',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
        ]);

        $categorySports = Category::firstOrCreate(['slug' => 'sports'], ['name' => 'Sports']);
        $categoryTeatris = Category::firstOrCreate(['slug' => 'teatris'], ['name' => 'Teātris']);

        // Representation of contaminated Event #2337 (Gp2oGo)
        $event = Event::create([
            'title' => 'Daugavpils Universitāte vs TTT Rīga Kadetes',
            'slug' => 'daugavpils-universitate-vs-ttt-riga-kadetes-Gp2oGo',
            'start_at' => Carbon::now()->addDays(3),
            'location_id' => $locDaugavpils->id,
            'source_slug' => 'basket-lv',
            'ticket_url' => 'https://www.bilesuparadize.lv/lv/event/178466',
            'short_description' => 'BioSil SBBL: Daugavpils Universitāte pret TTT Rīga Kadetes (Daugavpils kultūras pils sporta zāle).',
            'description' => "Hermanis Paukšs\nMeli bez noteikumiem\nPsiholoģiska drāma\n\nIzrāde piedāvās iespēju aplūkot noziedzīgu incidentu...\nRežija - Oļegs Šapošņikovs\nLomās: Svens – Aleksandrs Komarovs",
            'raw_data' => [
                'merged_external_ids' => [
                    'afiro-api' => 'e9itht981iaks51mkdo365tt',
                    'basket-lv' => 'e9itht981iaks51mkdo365tt',
                    'daugavpils-dome' => 'daugavpils-sieviesu-baltijas-basketbola-liga-du-pret-ttt-riga-kadetes',
                ],
                'ticket_links' => [
                    ['url' => 'https://www.bilesuparadize.lv/lv/event/178466', 'title' => 'bilesuparadize.lv'],
                ],
                'organizer' => [
                    'name' => 'DAUGAVPILS TEĀTRIS VSIA',
                ],
            ],
        ]);

        $event->categories()->attach([$categorySports->id, $categoryTeatris->id]);

        EventTranslation::create([
            'event_id' => $event->id,
            'locale' => 'en',
            'title' => 'Lies Without Rules',
            'slug' => 'lies-without-rules-en',
            'description' => 'Hermanis Paukšs. Lies Without Rules. Psychological drama.',
            'short_description' => 'BioSil SBBL: Daugavpils Universitāte pret TTT Rīga Kadetes',
        ]);

        EventTranslation::create([
            'event_id' => $event->id,
            'locale' => 'ru',
            'title' => 'Охота на уток',
            'slug' => 'ohota-na-utok-ru',
            'description' => 'Александр Вампилов. Охота на уток. Экзистенциальная драма.',
            'short_description' => 'BioSil SBBL: Daugavpils Universitāte pret TTT Rīga Kadetes',
        ]);

        $this->artisan('events:repair-cross-contamination')
            ->expectsOutputToContain('Completed.')
            ->assertExitCode(0);

        $event->refresh();

        // 1. Description cleaned of Hermanis Paukšs and drāma
        $this->assertStringNotContainsString('Paukšs', $event->description);
        $this->assertStringNotContainsString('drāma', $event->description);
        $this->assertStringContainsString('BioSil SBBL', $event->description);

        // 2. Theatre ticket_url removed
        $this->assertNull($event->ticket_url);

        // 3. Foreign ticket_links purged
        $this->assertEmpty($event->raw_data['ticket_links']);

        // 4. Foreign merged IDs purged
        $this->assertArrayNotHasKey('afiro-api', $event->raw_data['merged_external_ids']);
        $this->assertArrayNotHasKey('daugavpils-dome', $event->raw_data['merged_external_ids']);

        // 5. Theatre category removed, only sports remains
        $this->assertFalse($event->categories->contains('slug', 'teatris'));
        $this->assertTrue($event->categories->contains('slug', 'sports'));

        // 6. Foreign translations cleaned (no longer "Lies Without Rules" or "Охота на уток")
        $enTrans = $event->translations()->where('locale', 'en')->first();
        $ruTrans = $event->translations()->where('locale', 'ru')->first();
        $this->assertEquals('Daugavpils Universitāte vs TTT Rīga Kadetes', $enTrans->title);
        $this->assertEquals('Daugavpils Universitāte vs TTT Rīga Kadetes', $ruTrans->title);
    }
}
