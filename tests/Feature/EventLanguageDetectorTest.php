<?php

namespace Tests\Feature;

use App\DTO\EventLanguageData;
use App\Enums\EventLanguageMode;
use App\Enums\EventLanguageSource;
use App\Enums\SourceType;
use App\Models\Category;
use App\Models\Event;
use App\Models\Location;
use App\Models\Source;
use App\Services\EventLanguageService;
use App\Services\Language\EventLanguageDetector;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventLanguageDetectorTest extends TestCase
{
    use RefreshDatabase;

    private EventLanguageDetector $detector;
    private Source $defaultSource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new EventLanguageDetector();
        $this->defaultSource = Source::create([
            'name' => 'Default Source',
            'slug' => 'default-source',
            'url' => 'https://example.com',
            'scraper_class' => \App\Services\Scrapers\Sources\ForumCinemasScraper::class,
            'type' => SourceType::VENUE,
            'is_active' => true,
        ]);
    }

    private function createEvent(array $attributes = [], array $categorySlugs = []): Event
    {
        $event = Event::create(array_merge([
            'title' => 'Testa pasākums',
            'slug' => 'testa-pasakums-' . uniqid(),
            'start_at' => now()->addDays(2),
            'source_id' => $this->defaultSource->id,
            'source_slug' => $this->defaultSource->slug,
            'language_mode' => EventLanguageMode::Unknown,
            'language_source' => EventLanguageSource::Unknown,
        ], $attributes));

        if (!empty($categorySlugs)) {
            $catIds = [];
            foreach ($categorySlugs as $slug) {
                $cat = Category::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
                $catIds[] = $cat->id;
            }
            $event->categories()->sync($catIds);
        }

        return $event;
    }

    /**
     * 1. Sports: sports match -> language_free; sports conference -> NOT language_free.
     */
    public function test_sports_match_is_language_free_but_conference_is_not(): void
    {
        // Regular sports match
        $sportsEvent = $this->createEvent([
            'title' => 'Basketbola līgas spēle: Rīga vs Ventspils',
        ], ['sports']);

        $detected = $this->detector->detectForEvent($sportsEvent);
        $this->assertNotNull($detected);
        $this->assertSame(EventLanguageMode::LanguageFree, $detected->mode);
        $this->assertSame([], $detected->spokenLanguages);
        $this->assertSame(EventLanguageSource::Inferred, $detected->source);

        // Sports conference (exception)
        $confEvent = $this->createEvent([
            'title' => 'Sporta medicīnas konference',
            'description' => 'Konferencē uzstāsies ārsti un speciālisti.',
        ], ['sports', 'seminari']);

        $detectedConf = $this->detector->detectForEvent($confEvent);
        $this->assertNotNull($detectedConf);
        $this->assertNotSame(EventLanguageMode::LanguageFree, $detectedConf->mode);
        $this->assertSame(EventLanguageMode::Spoken, $detectedConf->mode);
    }

    /**
     * 2. Exhibitions: exhibition -> language_free; guided tour -> NOT language_free.
     */
    public function test_exhibition_is_language_free_but_guided_tour_is_not(): void
    {
        $exhibition = $this->createEvent([
            'title' => 'Mākslas izstāde: Ainavas',
        ], ['izstades']);

        $detected = $this->detector->detectForEvent($exhibition);
        $this->assertNotNull($detected);
        $this->assertSame(EventLanguageMode::LanguageFree, $detected->mode);

        $tour = $this->createEvent([
            'title' => 'Izstādes ekskursija gida pavadībā',
            'description' => 'Apskate gida vadībā.',
        ], ['izstades']);

        $detectedTour = $this->detector->detectForEvent($tour);
        // Tour has exception keyword "ekskursij" / "gida pavadīb" -> should not be language_free
        if ($detectedTour !== null) {
            $this->assertNotSame(EventLanguageMode::LanguageFree, $detectedTour->mode);
        } else {
            $this->assertNull($detectedTour);
        }
    }

    /**
     * 3. Music & concerts: concert -> language_free; opera and poetry -> NOT language_free.
     */
    public function test_concert_is_language_free_but_opera_and_poetry_are_not(): void
    {
        $concert = $this->createEvent([
            'title' => 'Simfoniskās mūzikas koncerts',
        ], ['muzika']);

        $detected = $this->detector->detectForEvent($concert);
        $this->assertNotNull($detected);
        $this->assertSame(EventLanguageMode::LanguageFree, $detected->mode);

        $opera = $this->createEvent([
            'title' => 'Opera Karmena',
        ], ['muzika']);

        $detectedOpera = $this->detector->detectForEvent($opera);
        if ($detectedOpera !== null) {
            $this->assertNotSame(EventLanguageMode::LanguageFree, $detectedOpera->mode);
        } else {
            $this->assertNull($detectedOpera);
        }

        $poetry = $this->createEvent([
            'title' => 'Dzejas un mūzikas vakars',
        ], ['muzika']);

        $detectedPoetry = $this->detector->detectForEvent($poetry);
        if ($detectedPoetry !== null) {
            $this->assertNotSame(EventLanguageMode::LanguageFree, $detectedPoetry->mode);
        } else {
            $this->assertNull($detectedPoetry);
        }
    }

    /**
     * 4. Festivities and markets -> language_free.
     */
    public function test_festivity_and_market_are_language_free(): void
    {
        $market = $this->createEvent([
            'title' => 'Pilsētas svētki un amatnieku tirdziņš',
        ], ['svetki']);

        $detected = $this->detector->detectForEvent($market);
        $this->assertNotNull($detected);
        $this->assertSame(EventLanguageMode::LanguageFree, $detected->mode);
    }

    /**
     * 5. Known theatre venues:
     * - Dailes teātris -> spoken: ['lv']
     * - Mihaila Čehova Rīgas Krievu teātris -> spoken: ['ru']
     * - Latvijas Leļļu teātris -> spoken: []
     * - Ballet at Dailes teātris -> language_free
     */
    public function test_theatre_venues_dailes_chekhov_lellu_and_ballet(): void
    {
        // Latvian theatre
        $dailesLocation = Location::create([
            'name' => 'Dailes teātris',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'place_type' => 'theatre',
        ]);
        $dailesEvent = $this->createEvent([
            'title' => 'Dramatiska luga',
            'location_id' => $dailesLocation->id,
        ], ['teatris']);

        $detectedDailes = $this->detector->detectForEvent($dailesEvent);
        $this->assertNotNull($detectedDailes);
        $this->assertSame(EventLanguageMode::Spoken, $detectedDailes->mode);
        $this->assertSame(['lv'], $detectedDailes->spokenLanguages);
        $this->assertSame(EventLanguageSource::Inferred, $detectedDailes->source);

        // Russian theatre
        $chekhovLocation = Location::create([
            'name' => 'Mihaila Čehova Rīgas Krievu teātris',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'place_type' => 'theatre',
        ]);
        $chekhovEvent = $this->createEvent([
            'title' => 'Jauns iestudējums',
            'location_id' => $chekhovLocation->id,
        ], ['teatris']);

        $detectedChekhov = $this->detector->detectForEvent($chekhovEvent);
        $this->assertNotNull($detectedChekhov);
        $this->assertSame(EventLanguageMode::Spoken, $detectedChekhov->mode);
        $this->assertSame(['ru'], $detectedChekhov->spokenLanguages);

        // Multilingual theatre (Leļļu teātris)
        $lelluLocation = Location::create([
            'name' => 'Latvijas Leļļu teātris',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'place_type' => 'theatre',
        ]);
        $lelluEvent = $this->createEvent([
            'title' => 'Pasaka bērniem',
            'location_id' => $lelluLocation->id,
        ], ['teatris']);

        $detectedLellu = $this->detector->detectForEvent($lelluEvent);
        $this->assertNotNull($detectedLellu);
        $this->assertSame(EventLanguageMode::Spoken, $detectedLellu->mode);
        $this->assertSame([], $detectedLellu->spokenLanguages);

        // Ballet at Dailes teātris -> language_free (7.1 precedes 7.2)
        $balletEvent = $this->createEvent([
            'title' => 'Viesizrāde: Balets Žizēle',
            'location_id' => $dailesLocation->id,
        ], ['teatris']);

        $detectedBallet = $this->detector->detectForEvent($balletEvent);
        $this->assertNotNull($detectedBallet);
        $this->assertSame(EventLanguageMode::LanguageFree, $detectedBallet->mode);
    }

    /**
     * 6. Cinema without Markus XML and without text clues -> mode: spoken, spoken: [] (never guess ['lv']).
     */
    public function test_cinema_without_markus_and_text_defaults_to_spoken_with_empty_languages(): void
    {
        $cinemaEvent = $this->createEvent([
            'title' => 'Noslēpumainā filma',
            'description' => 'Aizraujošs stāsts par nākotni.',
        ], ['kino']);

        $detected = $this->detector->detectForEvent($cinemaEvent);
        $this->assertNotNull($detected);
        $this->assertSame(EventLanguageMode::Spoken, $detected->mode);
        $this->assertSame([], $detected->spokenLanguages);
        $this->assertSame([], $detected->subtitleLanguages);
        $this->assertSame(EventLanguageSource::Inferred, $detected->source);
    }

    /**
     * 7. Municipality / Cultural center / Library:
     * - Defaults to ['lv']
     * - If Cyrillic in title -> spoken: [] (never guess ['lv']).
     */
    public function test_municipality_cultural_center_defaults_to_latvian_unless_cyrillic(): void
    {
        $muniSource = Source::create([
            'name' => 'Ogres Novads',
            'slug' => 'ogres-novads',
            'url' => 'https://ogre.lv',
            'scraper_class' => \App\Services\Scrapers\Sources\OgresNovadsScraper::class,
            'type' => SourceType::MUNICIPALITY,
            'is_active' => true,
        ]);

        $cultCenter = Location::create([
            'name' => 'Ogres novada Kultūras centrs',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'place_type' => 'cultural_center',
        ]);

        $lvEvent = $this->createEvent([
            'source_id' => $muniSource->id,
            'source_slug' => $muniSource->slug,
            'location_id' => $cultCenter->id,
            'title' => 'Novada sarunu un lekciju vakars',
        ], ['seminari']);

        $detectedLv = $this->detector->detectForEvent($lvEvent);
        $this->assertNotNull($detectedLv);
        $this->assertSame(EventLanguageMode::Spoken, $detectedLv->mode);
        $this->assertSame(['lv'], $detectedLv->spokenLanguages);

        // Event with Cyrillic characters in title
        $cyrillicEvent = $this->createEvent([
            'source_id' => $muniSource->id,
            'source_slug' => $muniSource->slug,
            'location_id' => $cultCenter->id,
            'title' => 'Концерт народной песни',
        ], ['citi']);

        $detectedCyrillic = $this->detector->detectForEvent($cyrillicEvent);
        $this->assertNotNull($detectedCyrillic);
        $this->assertSame(EventLanguageMode::Spoken, $detectedCyrillic->mode);
        $this->assertSame([], $detectedCyrillic->spokenLanguages);
    }

    /**
     * 8. Data Fusion Integration:
     * - Re-import doesn't overwrite manual
     * - Re-import doesn't overwrite explicit with inferred
     * - updated_at is NOT touched if language hasn't changed.
     */
    public function test_data_fusion_protection_and_no_unnecessary_touch_on_reimport(): void
    {
        $ingestionService = new EventIngestionService();

        // Step 1: Ingest event with explicit cinema language
        $scrapedDTO = new ScrapedEventDTO(
            title: 'Kino filma: Jaunais vilnis',
            startAt: Carbon::parse('2026-12-10 18:00:00'),
            endAt: Carbon::parse('2026-12-10 20:00:00'),
            description: 'Filmas apraksts',
            shortDescription: 'Filmas īss apraksts',
            venueName: 'Kino Rīga',
            city: 'Rīga',
            categoryNames: ['Kino'],
            sourceExternalId: 'kino_1001',
            rawData: [
                'language' => [
                    'mode' => 'spoken',
                    'spoken' => ['fr'],
                    'subtitles' => ['lv'],
                    'source' => 'explicit',
                ],
            ]
        );

        $res = $ingestionService->ingestDTO($scrapedDTO, $this->defaultSource);
        $this->assertSame('created', $res);

        $event = Event::where('source_external_id', 'kino_1001')->firstOrFail();
        $this->assertSame(EventLanguageMode::Spoken, $event->language_mode);
        $this->assertSame(['fr'], $event->spoken_languages);
        $this->assertSame(['lv'], $event->subtitle_languages);
        $this->assertSame(EventLanguageSource::Explicit, $event->language_source);

        // Step 2: Now simulate a detector run (which would infer level 3 defaults)
        $inferredData = new EventLanguageData(
            mode: EventLanguageMode::Spoken,
            spokenLanguages: ['lv'],
            subtitleLanguages: [],
            source: EventLanguageSource::Inferred
        );

        $changed = EventLanguageService::applyToEvent($event, $inferredData);
        $this->assertFalse($changed, 'Inferred data must NOT overwrite explicit cinema language');
        $event->refresh();
        $this->assertSame(EventLanguageSource::Explicit, $event->language_source);
        $this->assertSame(['fr'], $event->spoken_languages);

        // Step 3: Upgrade to manual
        $manualData = new EventLanguageData(
            mode: EventLanguageMode::Spoken,
            spokenLanguages: ['fr', 'de'],
            subtitleLanguages: ['en'],
            source: EventLanguageSource::Manual
        );
        $changedManual = EventLanguageService::applyToEvent($event, $manualData);
        $this->assertTrue($changedManual);
        $event->refresh();
        $this->assertSame(EventLanguageSource::Manual, $event->language_source);

        // Step 4: Re-running ingestion must NOT overwrite manual
        $resReimport = $ingestionService->ingestDTO($scrapedDTO, $this->defaultSource);
        $this->assertSame('updated', $resReimport);
        $event->refresh();
        $this->assertSame(EventLanguageSource::Manual, $event->language_source);
        $this->assertSame(['de', 'fr'], $event->spoken_languages);

        // Step 5: Applying identical data returns false and does not change updated_at
        $tBefore = $event->updated_at;
        $identicalManual = new EventLanguageData(
            mode: EventLanguageMode::Spoken,
            spokenLanguages: ['fr', 'de'],
            subtitleLanguages: ['en'],
            source: EventLanguageSource::Manual
        );
        $appliedAgain = EventLanguageService::applyToEvent($event, $identicalManual);
        $this->assertFalse($appliedAgain);
        $this->assertEquals($tBefore, $event->fresh()->updated_at);
    }

    /**
     * 9. Step 4.1 Regression: Exhibitions with misleading words in description (IDs 15, 190)
     * - Only title is checked for exclusions, not description.
     */
    public function test_exhibitions_with_talks_in_description_are_language_free_but_guided_tour_in_title_is_not(): void
    {
        // ID 15: Jaņa Rozentāla 160 gadu jubilejas izstāde no LNMM kolekcijas
        $event15 = $this->createEvent([
            'title' => 'Jaņa Rozentāla 160 gadu jubilejas izstāde no LNMM kolekcijas',
            'description' => 'Izstādes ietvaros plānotas tikšanās, ekskursijas un sarunas ar kuratoriem.',
        ], ['izstades']);

        $detected15 = $this->detector->detectForEvent($event15);
        $this->assertNotNull($detected15);
        $this->assertSame(EventLanguageMode::LanguageFree, $detected15->mode);
        $this->assertSame([], $detected15->spokenLanguages);

        // ID 190: Izstāde "Māra Vaičunas. Ārpus"
        $event190 = $this->createEvent([
            'title' => 'Izstāde "Māra Vaičunas. Ārpus"',
            'description' => 'Izstādes gaitā notiks sarunas un diskusijas ar autori.',
        ], ['izstades']);

        $detected190 = $this->detector->detectForEvent($event190);
        $this->assertNotNull($detected190);
        $this->assertSame(EventLanguageMode::LanguageFree, $detected190->mode);

        // Tour in title -> NOT language_free
        $tourEvent = $this->createEvent([
            'title' => 'Izstādes ekskursija gida pavadībā',
            'description' => 'Apskate gida vadībā.',
        ], ['izstades']);

        $detectedTour = $this->detector->detectForEvent($tourEvent);
        if ($detectedTour !== null) {
            $this->assertNotSame(EventLanguageMode::LanguageFree, $detectedTour->mode);
        } else {
            $this->assertNull($detectedTour);
        }
    }

    /**
     * 10. Step 4.1 Regression: Dramatic theatre with movement/choreography in description
     * - Hamlet with movement coach in description -> NOT language_free (mode: spoken)
     * - Ballet in theatre -> language_free
     */
    public function test_dramatic_theatre_with_movement_in_description_is_spoken_but_ballet_is_language_free(): void
    {
        $hamlet = $this->createEvent([
            'title' => 'Hamlets',
            'description' => 'Kustību režisors Jānis Bērziņš. Horeogrāfija un dejas elementi.',
        ], ['teatris']);

        $detectedHamlet = $this->detector->detectForEvent($hamlet);
        $this->assertNotNull($detectedHamlet);
        $this->assertSame(EventLanguageMode::Spoken, $detectedHamlet->mode);

        $ballet = $this->createEvent([
            'title' => 'Balets Gulbju ezers',
            'description' => 'Pasaulslavenais P. Čaikovska balets.',
        ], ['teatris']);

        $detectedBallet = $this->detector->detectForEvent($ballet);
        $this->assertNotNull($detectedBallet);
        $this->assertSame(EventLanguageMode::LanguageFree, $detectedBallet->mode);
    }

    /**
     * 11. Step 4.1 Regression: Multi-category conflicts arbitration
     * - Skroderdienas (teatris + muzika) @ Dailes teātris -> spoken: ['lv']
     * - Jumprava (kino + muzika) @ cinema venue -> spoken: []
     * - Simfoniskā orķestra koncerts (teatris + muzika) -> language_free
     * - Piga Gastrobārs (teatris + svetki, unclear conflict) -> spoken: [] (never language_free)
     * - Bērnu radošā darbnīca (berniem + svetki) -> NOT language_free (mode: spoken, spoken: [])
     */
    public function test_multi_category_conflicts_arbitration(): void
    {
        $theatreLocation = Location::create([
            'name' => 'Dailes teātris Lielā zāle',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'place_type' => 'theatre',
        ]);

        $cinemaLocation = Location::create([
            'name' => 'Apollo Kino Plaza',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'place_type' => 'cinema',
        ]);

        // Skroderdienas (teatris + muzika @ Dailes teātris) -> spoken: ['lv']
        $skroderdienas = $this->createEvent([
            'title' => 'Muzikāla izrāde "Skroderdienas"',
            'location_id' => $theatreLocation->id,
        ], ['teatris', 'muzika']);

        $detectedSkroderdienas = $this->detector->detectForEvent($skroderdienas);
        $this->assertNotNull($detectedSkroderdienas);
        $this->assertSame(EventLanguageMode::Spoken, $detectedSkroderdienas->mode);
        $this->assertSame(['lv'], $detectedSkroderdienas->spokenLanguages);

        // Jumprava (kino + muzika @ cinema venue) -> spoken: []
        $jumprava = $this->createEvent([
            'title' => 'Jumprava - Lielais notikums',
            'location_id' => $cinemaLocation->id,
        ], ['kino', 'muzika']);

        $detectedJumprava = $this->detector->detectForEvent($jumprava);
        $this->assertNotNull($detectedJumprava);
        $this->assertSame(EventLanguageMode::Spoken, $detectedJumprava->mode);
        $this->assertSame([], $detectedJumprava->spokenLanguages);

        // Simfoniskā orķestra koncerts (teatris + muzika) -> language_free
        $symphony = $this->createEvent([
            'title' => 'Simfoniskā orķestra koncerts',
        ], ['teatris', 'muzika']);

        $detectedSymphony = $this->detector->detectForEvent($symphony);
        $this->assertNotNull($detectedSymphony);
        $this->assertSame(EventLanguageMode::LanguageFree, $detectedSymphony->mode);

        // Piga Gastrobārs (teatris + svetki, unclear conflict) -> spoken: [] (never language_free)
        $piga = $this->createEvent([
            'title' => 'Piga Gastrobārs. Pasākumi.',
        ], ['teatris', 'svetki']);

        $detectedPiga = $this->detector->detectForEvent($piga);
        $this->assertNotNull($detectedPiga);
        $this->assertSame(EventLanguageMode::Spoken, $detectedPiga->mode);
        $this->assertSame([], $detectedPiga->spokenLanguages);

        // Bērnu radošā darbnīca un pasaku lasījums (berniem + svetki) -> spoken: [] (never language_free)
        $kidsWorkshop = $this->createEvent([
            'title' => 'Bērnu radošā darbnīca un pasaku lasījums',
        ], ['berniem', 'svetki']);

        $detectedKids = $this->detector->detectForEvent($kidsWorkshop);
        $this->assertNotNull($detectedKids);
        $this->assertSame(EventLanguageMode::Spoken, $detectedKids->mode);
        $this->assertSame([], $detectedKids->spokenLanguages);
    }

    /**
     * 12. Step 4.1 Regression: Sub-halls and independent theatres recognition (IDs 627, 655)
     * - Dailes teātris Lielā zāle -> spoken: ['lv']
     * - Austrumu Robeža teātra klubs -> spoken: ['lv']
     * - Teātris "Joriks" -> spoken: [] (multilingual, does not guess 'lv')
     */
    public function test_repertoire_theatre_sub_halls_and_independent_theatres(): void
    {
        // ID 627: Dailes teātris Lielā zāle
        $dailesLielaZale = Location::create([
            'name' => 'Dailes teātris Lielā zāle',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'place_type' => 'theatre',
        ]);
        $event627 = $this->createEvent([
            'title' => 'PIRMAVOTS',
            'location_id' => $dailesLielaZale->id,
        ], ['teatris']);

        $detected627 = $this->detector->detectForEvent($event627);
        $this->assertNotNull($detected627);
        $this->assertSame(EventLanguageMode::Spoken, $detected627->mode);
        $this->assertSame(['lv'], $detected627->spokenLanguages);

        // ID 655: Austrumu Robeža teātra klubs
        $austrumuRobeza = Location::create([
            'name' => 'Austrumu Robeža teātra klubs',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'place_type' => 'theatre',
        ]);
        $event655 = $this->createEvent([
            'title' => 'Vilciens',
            'location_id' => $austrumuRobeza->id,
        ], ['teatris']);

        $detected655 = $this->detector->detectForEvent($event655);
        $this->assertNotNull($detected655);
        $this->assertSame(EventLanguageMode::Spoken, $detected655->mode);
        $this->assertSame(['lv'], $detected655->spokenLanguages);

        // Teātris "Joriks" (multilingual theatre in Rēzekne) -> spoken: [] (never guess 'lv')
        $joriks = Location::create([
            'name' => 'Teātris "Joriks"',
            'city' => 'Rēzekne',
            'region' => 'Latgale',
            'place_type' => 'theatre',
        ]);
        $eventJoriks = $this->createEvent([
            'title' => 'Izrāde',
            'location_id' => $joriks->id,
        ], ['teatris']);

        $detectedJoriks = $this->detector->detectForEvent($eventJoriks);
        $this->assertNotNull($detectedJoriks);
        $this->assertSame(EventLanguageMode::Spoken, $detectedJoriks->mode);
        $this->assertSame([], $detectedJoriks->spokenLanguages);
    }
}
