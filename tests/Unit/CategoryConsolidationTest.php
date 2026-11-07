<?php

namespace Tests\Unit;

use App\Console\Commands\ConsolidateCategoriesCommand;
use App\Models\Category;
use App\Models\Event;
use App\Models\Location;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryConsolidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mapping_to_canonical_slugs(): void
    {
        $this->assertEquals('kino', ConsolidateCategoriesCommand::mapToCanonicalSlug('Filmas & Kino'));
        $this->assertEquals('kino', ConsolidateCategoriesCommand::mapToCanonicalSlug('Kino'));
        $this->assertEquals('kino', ConsolidateCategoriesCommand::mapToCanonicalSlug('Movies & Cinema'));

        $this->assertEquals('teatris', ConsolidateCategoriesCommand::mapToCanonicalSlug('Teātris'));
        $this->assertEquals('teatris', ConsolidateCategoriesCommand::mapToCanonicalSlug('Teātris & Izrādes'));
        $this->assertEquals('teatris', ConsolidateCategoriesCommand::mapToCanonicalSlug('Stand-up comedy'));
        $this->assertEquals('teatris', ConsolidateCategoriesCommand::mapToCanonicalSlug('Opera un balets'));

        $this->assertEquals('muzika', ConsolidateCategoriesCommand::mapToCanonicalSlug('Mūzika & Koncerti'));
        $this->assertEquals('muzika', ConsolidateCategoriesCommand::mapToCanonicalSlug('Mūzika'));
        $this->assertEquals('muzika', ConsolidateCategoriesCommand::mapToCanonicalSlug('Klasiskā mūzika'));
        $this->assertEquals('muzika', ConsolidateCategoriesCommand::mapToCanonicalSlug('Latviešu Mūzika'));

        $this->assertEquals('izstades', ConsolidateCategoriesCommand::mapToCanonicalSlug('Kultūra & Māksla'));
        $this->assertEquals('izstades', ConsolidateCategoriesCommand::mapToCanonicalSlug('Izstādes & Māksla'));
        $this->assertEquals('izstades', ConsolidateCategoriesCommand::mapToCanonicalSlug('Muzeju ekspozīcijas'));

        $this->assertEquals('berniem', ConsolidateCategoriesCommand::mapToCanonicalSlug('Ģimenēm & Bērniem'));
        $this->assertEquals('berniem', ConsolidateCategoriesCommand::mapToCanonicalSlug('Bērniem & Ģimenei'));

        $this->assertEquals('sports', ConsolidateCategoriesCommand::mapToCanonicalSlug('Sports & Aktīvā atpūta'));
        $this->assertEquals('sports', ConsolidateCategoriesCommand::mapToCanonicalSlug('Daba & Pārgājieni'));
        $this->assertEquals('sports', ConsolidateCategoriesCommand::mapToCanonicalSlug('Velobrauciens un skriešana'));
        $this->assertEquals('sports', ConsolidateCategoriesCommand::mapToCanonicalSlug('Basketbols un futbols'));
        $this->assertEquals('sports', ConsolidateCategoriesCommand::mapToCanonicalSlug('FIBA Eurobasket'));

        // Test inferFromContentAndVenue with sports and child ticket disclaimers
        $this->assertEquals('sports', ConsolidateCategoriesCommand::inferFromContentAndVenue(
            'Latvija - Vācija. FIBA Pasaules kauss 2027. Kvalifikācija',
            'Bērniem līdz 6 gadu vecumam (ieskaitot) ieeja bez maksas (neaizņemot atsevišķu sēdvietu).',
            'Xiaomi Arēna'
        ));
        $this->assertEquals('sports', ConsolidateCategoriesCommand::inferFromContentAndVenue(
            'Florbols, ELVI līga vīriešiem: Ķekava/RB&B - Masters Ulbroka/LU',
            'Bērniem līdz 7 gadiem ieeja brīva.',
            'Ķekavas sporta nams'
        ));

        $this->assertEquals('seminari', ConsolidateCategoriesCommand::mapToCanonicalSlug('Izglītība & Semināri'));
        $this->assertEquals('seminari', ConsolidateCategoriesCommand::mapToCanonicalSlug('Semināri & Meistarklases'));
        $this->assertEquals('seminari', ConsolidateCategoriesCommand::mapToCanonicalSlug('Bizness & Izglītība'));

        $this->assertEquals('svetki', ConsolidateCategoriesCommand::mapToCanonicalSlug('Festivāli & Svētki'));
        $this->assertEquals('svetki', ConsolidateCategoriesCommand::mapToCanonicalSlug('Gastronomija & Tirgi'));
        $this->assertEquals('svetki', ConsolidateCategoriesCommand::mapToCanonicalSlug('Naktsdzīve & Ballītes'));

        $this->assertEquals('citi', ConsolidateCategoriesCommand::mapToCanonicalSlug('Pilnīgi nezināma lieta 12345'));
    }

    public function test_categories_consolidate_artisan_command(): void
    {
        // Create obsolete category
        $oldCat = Category::create([
            'name' => 'Kultūra & Tradīcijas',
            'slug' => 'kultura-tradicijas',
            'icon' => 'tag',
            'color' => 'slate',
        ]);

        $source = Source::create([
            'name' => 'Test Source',
            'slug' => 'test-source',
            'url' => 'https://example.com',
            'scraper_class' => \App\Services\Scrapers\BezRindasScraper::class,
            'is_active' => true,
        ]);

        $location = Location::create([
            'name' => 'Test Vieta',
            'city' => 'Rīga',
            'region' => 'Rīga',
            'address' => 'Brīvības iela 1',
        ]);

        $event = Event::create([
            'source_id' => $source->id,
            'source_slug' => $source->slug,
            'location_id' => $location->id,
            'title' => 'Mākslas izstāde Rīgā',
            'slug' => 'makslas-izstade-riga',
            'start_at' => now()->addDays(2),
            'status' => 'published',
            'published_at' => now(),
        ]);

        $event->categories()->attach($oldCat->id);

        $this->assertEquals(1, $oldCat->events()->count());

        // Run consolidation command
        $this->artisan('categories:consolidate')->assertSuccessful();

        // Verify only 9 canonical categories exist
        $this->assertEquals(9, Category::count());
        $this->assertDatabaseMissing('categories', ['slug' => 'kultura-tradicijas']);
        $this->assertDatabaseHas('categories', ['slug' => 'izstades', 'name' => 'Izstādes']);
        $this->assertDatabaseHas('categories', ['slug' => 'kino', 'name' => 'Kino']);
        $this->assertDatabaseHas('categories', ['slug' => 'teatris', 'name' => 'Teātris']);
        $this->assertDatabaseHas('categories', ['slug' => 'citi', 'name' => 'Cits']);

        // Verify event was remapped to 'izstades'
        $izstadesCat = Category::where('slug', 'izstades')->first();
        $this->assertTrue($event->fresh()->categories->contains('id', $izstadesCat->id));
    }

    public function test_ingestion_service_maps_new_categories_strictly_to_canonical(): void
    {
        $service = app(EventIngestionService::class);

        $source = Source::create([
            'name' => 'Test Ingestion Source',
            'slug' => 'test-ingestion-source',
            'url' => 'https://example.com',
            'scraper_class' => \App\Services\Scrapers\BezRindasScraper::class,
            'is_active' => true,
        ]);

        $dto = new ScrapedEventDTO(
            title: 'Jauns kino vakars',
            description: 'Kino seanss brīvā dabā',
            startAt: now()->addDays(3),
            endAt: null,
            isFree: true,
            priceMin: 0,
            priceMax: 0,
            currency: 'EUR',
            categoryNames: ['Kino un filmas jaunumi'],
            venueName: 'Kino Teātris Splendid',
            address: 'Elizabetes iela 61',
            city: 'Rīga',
            imageUrl: null,
            ticketUrl: null,
            sourceUrl: 'https://example.com/event-1',
            sourceExternalId: 'ext-123'
        );

        $service->ingestDTO($dto, $source);

        $dtoUnmapped = new ScrapedEventDTO(
            title: 'Neatpazīts notikums',
            description: 'Dažāds apraksts',
            startAt: now()->addDays(4),
            endAt: null,
            isFree: true,
            priceMin: 0,
            priceMax: 0,
            currency: 'EUR',
            categoryNames: ['Neatpazīts žanrs xyz'],
            venueName: 'Kaut kur',
            address: 'Brīvības 1',
            city: 'Rīga',
            imageUrl: null,
            ticketUrl: null,
            sourceUrl: 'https://example.com/event-2',
            sourceExternalId: 'ext-456'
        );

        $service->ingestDTO($dtoUnmapped, $source);

        $event = Event::where('source_external_id', 'ext-123')->first();
        $this->assertNotNull($event);
        $categorySlugs = $event->categories->pluck('slug')->toArray();
        $this->assertContains('kino', $categorySlugs);
        $this->assertNotContains('citi', $categorySlugs);

        $eventUnmapped = Event::where('source_external_id', 'ext-456')->first();
        $this->assertNotNull($eventUnmapped);
        $unmappedCategorySlugs = $eventUnmapped->categories->pluck('slug')->toArray();
        $this->assertContains('citi', $unmappedCategorySlugs);

        // No random custom category slug should exist in categories table
        $this->assertDatabaseMissing('categories', ['slug' => 'kino-un-filmas-jaunumi']);
        $this->assertDatabaseMissing('categories', ['slug' => 'neatpazits-zanrs-xyz']);
    }

    public function test_piedzivojums_tumsa_categorized_as_teatris_and_seminari_not_sports(): void
    {
        $source = Source::create([
            'name' => 'Biļešu Paradīze',
            'slug' => 'bp-test',
            'url' => 'https://example.com',
            'scraper_class' => \App\Services\Scrapers\Sources\BilesuParadizeScraper::class,
            'is_active' => true,
        ]);

        $service = app(EventIngestionService::class);

        $dto = new ScrapedEventDTO(
            title: 'Piedzīvojums tumsā',
            description: 'Aizraujoša sporta spēle SHOWDOWN tumsā. Noskaidrosim, kurš ir īstais SHOWDOWN čempions!',
            startAt: now()->addDays(2),
            endAt: null,
            isFree: false,
            priceMin: 15,
            priceMax: 20,
            currency: 'EUR',
            categoryNames: [],
            venueName: 'Tumsas Pasaule',
            address: 'Ķengaraga iela 1',
            city: 'Rīga',
            imageUrl: null,
            ticketUrl: null,
            sourceUrl: 'https://example.com/piedzivojums',
            sourceExternalId: 'tumsa-101'
        );

        $service->ingestDTO($dto, $source);

        $event = Event::where('source_external_id', 'tumsa-101')->first();
        $this->assertNotNull($event);
        $categorySlugs = $event->categories->pluck('slug')->toArray();

        $this->assertContains('teatris', $categorySlugs);
        $this->assertContains('seminari', $categorySlugs);
        $this->assertNotContains('sports', $categorySlugs);
    }

    public function test_park_excursion_categorized_as_izstades_not_sports(): void
    {
        $source = Source::create([
            'name' => 'Afiro API',
            'slug' => 'afiro-test-exc',
            'url' => 'https://example.com',
            'scraper_class' => \App\Services\Scrapers\Sources\AfiroApiScraper::class,
            'is_active' => true,
        ]);

        $service = app(EventIngestionService::class);

        $dto = new ScrapedEventDTO(
            title: 'Ekskursija pa Ķemeru kūrorta parku (angļu valodā)',
            description: 'Pastaigas laikā būs iespēja uzzināt par Ķemeru vēsturi un izstaigāt kūrorta parku.',
            startAt: now()->addDays(4),
            endAt: null,
            isFree: false,
            priceMin: 5,
            priceMax: 5,
            currency: 'EUR',
            categoryNames: ['Daba & Pārgājieni'],
            venueName: 'Ķemeru kūrorta parks',
            address: 'Tūristu iela 1',
            city: 'Jūrmala',
            imageUrl: null,
            ticketUrl: null,
            sourceUrl: 'https://example.com/kemeri',
            sourceExternalId: 'kemeri-exc-1'
        );

        $service->ingestDTO($dto, $source);

        $event = Event::where('source_external_id', 'kemeri-exc-1')->first();
        $this->assertNotNull($event);
        $categorySlugs = $event->categories->pluck('slug')->toArray();

        $this->assertContains('izstades', $categorySlugs);
        $this->assertNotContains('sports', $categorySlugs);
    }

    public function test_concert_with_sports_reference_in_bio_categorized_as_muzika_not_sports(): void
    {
        $source = Source::create([
            'name' => 'Biļešu Paradīze',
            'slug' => 'bp-test-concert',
            'url' => 'https://example.com',
            'scraper_class' => \App\Services\Scrapers\Sources\BilesuParadizeScraper::class,
            'is_active' => true,
        ]);

        $service = app(EventIngestionService::class);

        $dto = new ScrapedEventDTO(
            title: 'Rudenīga tikšanās ar Grupu BIG AL & THE JOKERS',
            description: 'Grupas un tās muzikālā stila cienītāji pievienojas rokenrola virpulī. 2025. gadā grupa uzstājās Eiropas basketbola čempionāta izklaides programmā.',
            startAt: now()->addDays(5),
            endAt: null,
            isFree: false,
            priceMin: 15,
            priceMax: 30,
            currency: 'EUR',
            categoryNames: [],
            venueName: 'Madonas novada kultūras centrs',
            address: 'Raiņa iela 12',
            city: 'Madona',
            imageUrl: null,
            ticketUrl: null,
            sourceUrl: 'https://example.com/big-al',
            sourceExternalId: 'big-al-1'
        );

        $service->ingestDTO($dto, $source);

        $event = Event::where('source_external_id', 'big-al-1')->first();
        $this->assertNotNull($event);
        $categorySlugs = $event->categories->pluck('slug')->toArray();

        $this->assertContains('muzika', $categorySlugs);
        $this->assertNotContains('sports', $categorySlugs);
    }

    public function test_theatre_play_with_actress_named_liga_categorized_as_teatris_not_sports(): void
    {
        $source = Source::create([
            'name' => 'Biļešu Paradīze',
            'slug' => 'bp-test-play',
            'url' => 'https://example.com',
            'scraper_class' => \App\Services\Scrapers\Sources\BilesuParadizeScraper::class,
            'is_active' => true,
        ]);

        $service = app(EventIngestionService::class);

        $dto = new ScrapedEventDTO(
            title: 'Intīmās dzīves',
            description: 'Viena no izsmalcinātākajām komēdijām. Lomās: Evija Krūze, Līga Zeļģe, Ainārs Ančevskis.',
            startAt: now()->addDays(6),
            endAt: null,
            isFree: false,
            priceMin: 20,
            priceMax: 40,
            currency: 'EUR',
            categoryNames: [],
            venueName: 'Latvijas Nacionālais teātris, Lielā zāle',
            address: 'Kronvalda bulvāris 2',
            city: 'Rīga',
            imageUrl: null,
            ticketUrl: null,
            sourceUrl: 'https://example.com/intimas',
            sourceExternalId: 'intimas-play-1'
        );

        $service->ingestDTO($dto, $source);

        $event = Event::where('source_external_id', 'intimas-play-1')->first();
        $this->assertNotNull($event);
        $categorySlugs = $event->categories->pluck('slug')->toArray();

        $this->assertContains('teatris', $categorySlugs);
        $this->assertNotContains('sports', $categorySlugs);
    }

    public function test_open_day_transporta_instituts_categorized_as_seminari_not_sports(): void
    {
        $source = Source::create([
            'name' => 'Afiro API',
            'slug' => 'afiro-test',
            'url' => 'https://example.com',
            'scraper_class' => \App\Services\Scrapers\Sources\AfiroApiScraper::class,
            'is_active' => true,
        ]);

        $service = app(EventIngestionService::class);

        $dto = new ScrapedEventDTO(
            title: 'Atvērto durvju diena topošajiem bakalaura studentiem un viņu vecākiem',
            description: 'Uzzini par studiju iespējām IT, robotikas, aviācijas, transporta un loģistikas jomās. 11:00-11:20 Ledlauža spēle, 12:30 Kahoot spēle.',
            startAt: now()->addDays(3),
            endAt: null,
            isFree: true,
            priceMin: 0,
            priceMax: 0,
            currency: 'EUR',
            categoryNames: ['Izglītība & Semināri'],
            venueName: 'Transporta un sakaru institūts (TSI)',
            address: 'Lauvas iela 2',
            city: 'Rīga',
            imageUrl: null,
            ticketUrl: null,
            sourceUrl: 'https://example.com/tsi-open-day',
            sourceExternalId: 'tsi-open-day-1'
        );

        $service->ingestDTO($dto, $source);

        $event = Event::where('source_external_id', 'tsi-open-day-1')->first();
        $this->assertNotNull($event);
        $categorySlugs = $event->categories->pluck('slug')->toArray();

        $this->assertContains('seminari', $categorySlugs);
        $this->assertNotContains('sports', $categorySlugs);
    }

    public function test_karalu_spele_is_teatris_not_sports(): void
    {
        $source = Source::create([
            'name' => 'Biļešu Paradīze',
            'slug' => 'bp-karalu-spele',
            'url' => 'https://example.com',
            'scraper_class' => \App\Services\Scrapers\Sources\BilesuParadizeScraper::class,
            'is_active' => true,
        ]);

        $service = app(EventIngestionService::class);

        $dto = new ScrapedEventDTO(
            title: 'KARAĻU SPĒLE',
            description: 'Izrādes pamatā Stefana Cveiga “Šaha novele”. Pasaules čempions šahā Mirko Čentovičs...',
            startAt: now()->addDays(5),
            endAt: null,
            isFree: false,
            priceMin: 25,
            priceMax: 35,
            currency: 'EUR',
            categoryNames: ['Teātris'],
            venueName: 'Jaunais Rīgas teātris, Lielā zāle',
            address: 'Lāčplēša iela 25',
            city: 'Rīga',
            imageUrl: null,
            ticketUrl: null,
            sourceUrl: 'https://example.com/karalu-spele',
            sourceExternalId: 'karalu-spele-1'
        );

        $service->ingestDTO($dto, $source);

        $event = Event::where('source_external_id', 'karalu-spele-1')->first();
        $this->assertNotNull($event);
        $categorySlugs = $event->categories->pluck('slug')->toArray();

        $this->assertContains('teatris', $categorySlugs);
        $this->assertNotContains('sports', $categorySlugs);
    }

    public function test_museum_exhibition_lecture_with_historian_liga_is_not_sports(): void
    {
        $source = Source::create([
            'name' => 'Jūrmalas muzejs',
            'slug' => 'jm-test',
            'url' => 'https://example.com',
            'scraper_class' => \App\Services\Scrapers\Sources\JurmalasMuzejsScraper::class,
            'is_active' => true,
        ]);

        $service = app(EventIngestionService::class);

        $dto = new ScrapedEventDTO(
            title: 'Ekspresizstādes "Augusts Muižulis - Lāčplēša Kara ordeņa kavalieris" atklāšana un Jūrmalas muzeja pētnieces, vēsturnieces Līgas Strazdas lekcija',
            description: 'Ekspresizstāde un lekcija Jūrmalas muzejā par vēsturiskiem notikumiem.',
            startAt: now()->addDays(4),
            endAt: null,
            isFree: true,
            priceMin: 0,
            priceMax: 0,
            currency: 'EUR',
            categoryNames: ['Izstādes & Māksla'],
            venueName: 'Jūrmalas muzejs',
            address: 'Tirgoņu iela 29',
            city: 'Jūrmala',
            imageUrl: null,
            ticketUrl: null,
            sourceUrl: 'https://example.com/muizulis',
            sourceExternalId: 'muizulis-exhibition-1'
        );

        $service->ingestDTO($dto, $source);

        $event = Event::where('source_external_id', 'muizulis-exhibition-1')->first();
        $this->assertNotNull($event);
        $categorySlugs = $event->categories->pluck('slug')->toArray();

        $this->assertContains('izstades', $categorySlugs);
        $this->assertContains('seminari', $categorySlugs);
        $this->assertNotContains('sports', $categorySlugs);
    }

    public function test_padel_tournament_categorized_as_sports_not_izstades(): void
    {
        $source = Source::create([
            'name' => 'Afiro API',
            'slug' => 'afiro-padel-test',
            'url' => 'https://example.com',
            'scraper_class' => \App\Services\Scrapers\Sources\AfiroApiScraper::class,
            'is_active' => true,
        ]);

        $service = app(EventIngestionService::class);

        $dto = new ScrapedEventDTO(
            title: 'Regulārie padela turnīri Padel Club Riga!',
            description: 'Piedalies aizraujošos padela turnīros katru nedēļu.',
            startAt: now()->addDays(2),
            endAt: null,
            isFree: false,
            priceMin: 15,
            priceMax: 25,
            currency: 'EUR',
            categoryNames: ['Kultūra & Māksla'], // Simulating Afiro fallback
            venueName: 'Padel Club Riga',
            address: 'Kandavas iela 14',
            city: 'Rīga',
            imageUrl: null,
            ticketUrl: null,
            sourceUrl: 'https://example.com/padel-turniri',
            sourceExternalId: 'padel-tournament-1'
        );

        $service->ingestDTO($dto, $source);

        $event = Event::where('source_external_id', 'padel-tournament-1')->first();
        $this->assertNotNull($event);
        $categorySlugs = $event->categories->pluck('slug')->toArray();

        $this->assertContains('sports', $categorySlugs);
        $this->assertNotContains('izstades', $categorySlugs);
    }

    public function test_english_basketball_championship_categorized_as_sports_not_izstades(): void
    {
        $source = Source::create([
            'name' => 'Afiro API',
            'slug' => 'afiro-bb-test',
            'url' => 'https://example.com',
            'scraper_class' => \App\Services\Scrapers\Sources\AfiroApiScraper::class,
            'is_active' => true,
        ]);

        $service = app(EventIngestionService::class);

        $dto = new ScrapedEventDTO(
            title: 'Latvian Youth Basketball Championship',
            description: 'Youth basketball matches U16 and U19.',
            startAt: now()->addDays(3),
            endAt: null,
            isFree: true,
            priceMin: 0,
            priceMax: 0,
            currency: 'EUR',
            categoryNames: ['Kultūra & Māksla'], // Simulating Afiro fallback
            venueName: 'Jūrmalas sporta skola',
            address: 'Nometņu iela 2',
            city: 'Jūrmala',
            imageUrl: null,
            ticketUrl: null,
            sourceUrl: 'https://example.com/bb-champ',
            sourceExternalId: 'bb-champ-1'
        );

        $service->ingestDTO($dto, $source);

        $event = Event::where('source_external_id', 'bb-champ-1')->first();
        $this->assertNotNull($event);
        $categorySlugs = $event->categories->pluck('slug')->toArray();

        $this->assertContains('sports', $categorySlugs);
        $this->assertNotContains('izstades', $categorySlugs);
    }

    public function test_handball_virsliga_categorized_as_sports_not_izstades(): void
    {
        $source = Source::create([
            'name' => 'Afiro API',
            'slug' => 'afiro-handball-test',
            'url' => 'https://example.com',
            'scraper_class' => \App\Services\Scrapers\Sources\AfiroApiScraper::class,
            'is_active' => true,
        ]);

        $service = app(EventIngestionService::class);

        $dto = new ScrapedEventDTO(
            title: 'Latvijas SynotTip handbola Virslīga — vīriešu spēļu kalendārs',
            description: 'Latvijas handbola virslīgas mači un spēļu kalendārs.',
            startAt: now()->addDays(4),
            endAt: null,
            isFree: false,
            priceMin: 5,
            priceMax: 10,
            currency: 'EUR',
            categoryNames: ['Kultūra & Māksla'], // Simulating Afiro fallback
            venueName: 'Ogres sporta centrs',
            address: 'Skolas iela 12',
            city: 'Ogre',
            imageUrl: null,
            ticketUrl: null,
            sourceUrl: 'https://example.com/handball-virsliga',
            sourceExternalId: 'handball-virsliga-1'
        );

        $service->ingestDTO($dto, $source);

        $event = Event::where('source_external_id', 'handball-virsliga-1')->first();
        $this->assertNotNull($event);
        $categorySlugs = $event->categories->pluck('slug')->toArray();

        $this->assertContains('sports', $categorySlugs);
        $this->assertNotContains('izstades', $categorySlugs);
    }

    public function test_bet_bet_jubilejas_koncerts_is_muzika_not_izstades(): void
    {
        $source = Source::create([
            'name' => 'Latgales vēstniecība GORS',
            'slug' => 'gors-test',
            'url' => 'https://example.com',
            'scraper_class' => \App\Services\Scrapers\Sources\LatgalesGorsScraper::class,
            'is_active' => true,
        ]);

        $service = app(EventIngestionService::class);

        $dto = new ScrapedEventDTO(
            title: 'BET BET. 35 gadu jubilejas koncerts ar Raimonda PAULA dziesmām',
            description: 'Grupas "bet bet" jubilejas koncerts.',
            startAt: now()->addDays(5),
            endAt: null,
            isFree: false,
            priceMin: 20,
            priceMax: 30,
            currency: 'EUR',
            categoryNames: ['Mūzika & Koncerti', 'Kultūra & Tradīcijas'],
            venueName: 'Latgales vēstniecība GORS',
            address: 'Pils iela 4',
            city: 'Rēzekne',
            imageUrl: null,
            ticketUrl: null,
            sourceUrl: 'https://example.com/bet-bet-koncerts',
            sourceExternalId: 'bet-bet-koncerts-1'
        );

        $service->ingestDTO($dto, $source);

        $event = Event::where('source_external_id', 'bet-bet-koncerts-1')->first();
        $this->assertNotNull($event);
        $categorySlugs = $event->categories->pluck('slug')->toArray();

        $this->assertContains('muzika', $categorySlugs);
        $this->assertNotContains('izstades', $categorySlugs);
        $this->assertNotContains('sports', $categorySlugs);
    }
}
