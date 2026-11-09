<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\FloorballLvScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FloorballLvScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_floorball_scraper_metadata(): void
    {
        $scraper = new FloorballLvScraper();
        $this->assertEquals('floorball-lv', $scraper->getSlug());
        $this->assertEquals('Latvijas Florbola Savienība (floorball.lv)', $scraper->getName());
        $this->assertEquals('https://sodiena.hel1.your-objectstorage.com/leagues/floorball_elvi.webp', FloorballLvScraper::DEFAULT_IMAGE);
        $this->assertEquals('https://sodiena.hel1.your-objectstorage.com/leagues/floorball_elvi.webp', FloorballLvScraper::DEFAULT_IMAGE_ELVI);
        $this->assertEquals('https://sodiena.hel1.your-objectstorage.com/leagues/floorball_latest.webp', FloorballLvScraper::DEFAULT_IMAGE_LATEST);
    }

    public function test_parse_row_elvi_men_game(): void
    {
        $futureDate = now()->addDays(5);
        $dateText = $futureDate->format('d.m');
        $year = (int) $futureDate->format('Y');

        $row = [
            $dateText,
            '18:00',
            '<a href="https://www.floorball.lv/lv/2026/chempionats/vv/komanda/386-fk-talsi-tnss-krauzers" title="FK Talsi/TNSS/Krauzers">FK&nbsp;Talsi/TNSS/Krauze..</a>',
            '',
            '<a href="https://www.floorball.lv/lv/2026/chempionats/vv/komanda/1028-lekrings" title="Lekrings">Lekrings</a>',
            'Talsu Valsts ģimnāzija',
            'M. Larinovs, A. Māliņš'
        ];

        $meta = [
            'name' => 'Elvi Florbola līga (Vīrieši)',
            'image' => FloorballLvScraper::DEFAULT_IMAGE_ELVI,
            'url' => 'https://www.floorball.lv/lv/2026/chempionats/vv/kalendars',
        ];

        $scraper = new FloorballLvScraper();
        $dto = $scraper->parseRow($row, 'vv', $meta, $year);

        $this->assertNotNull($dto);
        $this->assertEquals('FK Talsi/TNSS/Krauzers vs Lekrings', $dto->title);
        $this->assertEquals($futureDate->format('d.m.Y') . ' 18:00', $dto->startAt->format('d.m.Y H:i'));
        $this->assertEquals($futureDate->format('d.m.Y') . ' 19:45', $dto->endAt->format('d.m.Y H:i'));
        $this->assertEquals('Talsu Valsts ģimnāzijas sporta halle', $dto->venueName);
        $this->assertEquals('Talsi', $dto->city);
        $this->assertEquals('Kurzeme', $dto->region);
        $this->assertEquals('Brīvības iela 29, Talsi', $dto->address);
        $this->assertEqualsWithDelta(57.24002, $dto->latitude, 0.0001);
        $this->assertEqualsWithDelta(22.59116, $dto->longitude, 0.0001);
        $this->assertEquals('sports', $dto->entertainmentType);
        $this->assertContains('Sports', $dto->categoryNames);
        $this->assertEquals(FloorballLvScraper::DEFAULT_IMAGE_ELVI, $dto->imageUrl);
        $this->assertEquals('https://www.floorball.lv/lv/2026/chempionats/vv/kalendars', $dto->sourceUrl);
        $this->assertStringStartsWith('vv_', $dto->sourceExternalId);
    }

    public function test_parse_row_latest_game(): void
    {
        $futureDate = now()->addDays(8);
        $dateText = $futureDate->format('d.m');
        $year = (int) $futureDate->format('Y');

        $row = [
            $dateText,
            '16:00',
            '<a href="https://www.floorball.lv/lv/2026/chempionats/LE/komanda/999-sparta" title="Sparta team Automaailm">Sparta team Automaailm</a>',
            '',
            '<a href="https://www.floorball.lv/lv/2026/chempionats/LE/komanda/888-jogeva" title="Jõgeva SK Tähe/Olivia">Jõgeva SK Tähe/Olivia</a>',
            'Märjamaa Valla Spordikeskus',
            ''
        ];

        $meta = [
            'name' => 'LAT-EST florbola līga',
            'image' => FloorballLvScraper::DEFAULT_IMAGE_LATEST,
            'url' => 'https://www.floorball.lv/lv/2026/chempionats/LE/kalendars',
        ];

        $scraper = new FloorballLvScraper();
        $dto = $scraper->parseRow($row, 'LE', $meta, $year);

        $this->assertNotNull($dto);
        $this->assertEquals('Sparta team Automaailm vs Jõgeva SK Tähe/Olivia', $dto->title);
        $this->assertEquals('Märjamaa Valla Spordikeskus', $dto->venueName);
        $this->assertEquals('Märjamaa', $dto->city);
        $this->assertEquals('Igaunija', $dto->region);
        $this->assertEquals('Pärnu mnt 67, Märjamaa, Igaunija', $dto->address);
        $this->assertEqualsWithDelta(58.90386, $dto->latitude, 0.0001);
        $this->assertEqualsWithDelta(24.42711, $dto->longitude, 0.0001);
        $this->assertEquals(FloorballLvScraper::DEFAULT_IMAGE_LATEST, $dto->imageUrl);
        $this->assertStringStartsWith('LE_', $dto->sourceExternalId);
    }

    public function test_parse_row_skips_past_events(): void
    {
        $pastDate = now()->subDays(10);
        $dateText = $pastDate->format('d.m');
        $year = (int) $pastDate->format('Y');

        $row = [
            $dateText,
            '18:00',
            '<a href="https://www.floorball.lv/lv/2026/chempionats/vv/komanda/1028-lekrings" title="Lekrings">Lekrings</a>',
            '<a href="https://www.floorball.lv/lv/2026/chempionats/vv/proto/56030-lek-ulb">3:6</a>',
            '<a href="https://www.floorball.lv/lv/2026/chempionats/vv/komanda/26-masters-ulbroka-lu" title="Masters Ulbroka/LU">Masters Ulbroka/LU</a>',
            'Sporta komplekss Cēsis',
            'M. Larinovs, A. Māliņš'
        ];

        $meta = [
            'name' => 'Elvi Florbola līga (Vīrieši)',
            'image' => FloorballLvScraper::DEFAULT_IMAGE_ELVI,
            'url' => 'https://www.floorball.lv/lv/2026/chempionats/vv/kalendars',
        ];

        $scraper = new FloorballLvScraper();
        $dto = $scraper->parseRow($row, 'vv', $meta, $year);

        $this->assertNull($dto);
    }

    public function test_resolve_location(): void
    {
        $scraper = new FloorballLvScraper();

        // Exact / substring
        $loc1 = $scraper->resolveLocation('Sporta komplekss Cēsis', 'Lekrings');
        $this->assertEquals('Cēsu Sporta komplekss', $loc1['venue']);
        $this->assertEquals('Cēsis', $loc1['city']);
        $this->assertEquals('Vidzeme', $loc1['region']);
        $this->assertEquals('Piebalgas iela 18, Cēsis', $loc1['address']);

        // Lielvārdes sporta centrs
        $locLielvarde = $scraper->resolveLocation('Lielvārdes sporta centrs', 'Lielvārde/Unihoc');
        $this->assertEquals('Lielvārdes sporta centrs', $locLielvarde['venue']);
        $this->assertEquals('Raiņa iela 22, Lielvārde', $locLielvarde['address']);
        $this->assertEqualsWithDelta(56.71433, $locLielvarde['lat'], 0.0001);
        $this->assertEqualsWithDelta(24.82019, $locLielvarde['lon'], 0.0001);

        // Ulbrokas sporta komplekss
        $locUlbroka = $scraper->resolveLocation('Ulbrokas sporta komplekss', 'Masters Ulbroka/LU');
        $this->assertEquals('Institūta iela 1B, Ulbroka', $locUlbroka['address']);
        $this->assertEqualsWithDelta(56.92862, $locUlbroka['lat'], 0.0001);
        $this->assertEqualsWithDelta(24.32186, $locUlbroka['lon'], 0.0001);

        // Spuņciems
        $locSpunciems = $scraper->resolveLocation('Spuņciems', 'FBK Baloži');
        $this->assertEquals('Skolas iela 1, Spuņciems, Salas pagasts, Mārupes novads', $locSpunciems['address']);
        $this->assertEqualsWithDelta(56.93818, $locSpunciems['lat'], 0.0001);
        $this->assertEqualsWithDelta(23.70735, $locSpunciems['lon'], 0.0001);

        // Estonian venue
        $loc2 = $scraper->resolveLocation('Jõgeva spordihoone, rohu 10', 'Jõgeva SK Tähe/Olivia');
        $this->assertEquals('Jõgeva', $loc2['city']);
        $this->assertEquals('Igaunija', $loc2['region']);

        // Team fallback
        $loc3 = $scraper->resolveLocation('', 'Rubene');
        $this->assertEquals('Kocēni', $loc3['city']);
        $this->assertEquals('Vidzeme', $loc3['region']);
    }

    public function test_ingest_floorball_events_into_database(): void
    {
        Category::updateOrCreate(['name' => 'Sports'], ['slug' => 'sports']);

        $source = Source::create([
            'name' => 'Latvijas Florbola Savienība (floorball.lv)',
            'slug' => 'floorball-test',
            'url' => 'https://www.floorball.lv/lv/2026/chempionats/vv/kalendars',
            'scraper_class' => FloorballLvScraper::class,
            'is_active' => true,
        ]);

        $futureDate = now()->addDays(7);
        $dateText = $futureDate->format('d.m');
        $year = (int) $futureDate->format('Y');

        $row = [
            $dateText,
            '20:00',
            '<a href="https://www.floorball.lv/lv/2026/chempionats/vv/komanda/509-kekava-rb-b" title="Ķekava/RB&B">Ķekava/RB&B</a>',
            '',
            '<a href="https://www.floorball.lv/lv/2026/chempionats/vv/komanda/2106-fbk-sac" title="FBK SĀC">FBK SĀC</a>',
            'Ķekavas vsk. sporta komplekss',
            ''
        ];

        $meta = [
            'name' => 'Elvi Florbola līga (Vīrieši)',
            'image' => FloorballLvScraper::DEFAULT_IMAGE_ELVI,
            'url' => 'https://www.floorball.lv/lv/2026/chempionats/vv/kalendars',
        ];

        $scraper = new FloorballLvScraper();
        $dto = $scraper->parseRow($row, 'vv', $meta, $year);
        $this->assertNotNull($dto);

        $ingestionService = app(EventIngestionService::class);
        $status = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $status);
        $this->assertEquals(1, Event::where('source_id', $source->id)->count());

        $saved = Event::where('source_id', $source->id)->first();
        $this->assertEquals('Ķekava/RB&B vs FBK SĀC', $saved->title);
        $this->assertEquals('sports', $saved->entertainment_type);
        $this->assertEquals('Ķekava', $saved->location->city);
        $this->assertEquals('Rīga un Pierīga', $saved->location->region);
        $this->assertEquals(FloorballLvScraper::DEFAULT_IMAGE_ELVI, $saved->image_url);
        $this->assertEquals(FloorballLvScraper::DEFAULT_IMAGE_ELVI, $saved->display_image_url);
        $this->assertStringContainsString('Ķekavas vidusskolas sporta komplekss', $saved->description);
        $this->assertStringContainsString('floorball.lv', $saved->description);
    }

    public function test_parse_row_rich_description_with_referees_and_protocol(): void
    {
        $futureDate = now()->addDays(3);
        $dateText = $futureDate->format('d.m');
        $year = (int) $futureDate->format('Y');

        $row = [
            $dateText,
            '19:30',
            '<a href="https://www.floorball.lv/lv/2026/chempionats/vv/komanda/2106-fbk-sac" title="FBK SĀC">FBK&nbsp;SĀC</a>',
            '<a href="/proto/56072-sac-lvd">Tiešraide</a>',
            '<a href="https://www.floorball.lv/lv/2026/chempionats/vv/komanda/14-lielvarde-unihoc" title="Lielvārde/Unihoc">Lielvārde/Unihoc</a>',
            'Ādažu sporta centrs',
            'M. Larinovs, A. Māliņš'
        ];

        $meta = [
            'name' => 'Elvi Florbola līga (Vīrieši)',
            'image' => FloorballLvScraper::DEFAULT_IMAGE_ELVI,
            'url' => 'https://www.floorball.lv/lv/2026/chempionats/vv/kalendars',
        ];

        $scraper = new FloorballLvScraper();
        $dto = $scraper->parseRow($row, 'vv', $meta, $year);

        $this->assertNotNull($dto);
        $this->assertEquals('https://www.floorball.lv/proto/56072-sac-lvd', $dto->sourceUrl);
        $this->assertStringContainsString('Spēles tiesneši: M. Larinovs, A. Māliņš', $dto->description);
        $this->assertStringContainsString('Norises vieta: Ādažu sporta centrs', $dto->description);
        $this->assertStringContainsString('Adrese: Gaujas iela 30, Ādaži', $dto->description);
        $this->assertStringContainsString('Ieejas noteikumi un biļešu iegāde', $dto->description);
        $this->assertEquals('M. Larinovs, A. Māliņš', $dto->rawData['referees']);
    }

    public function test_resolve_location_new_venues(): void
    {
        $scraper = new FloorballLvScraper();

        $incukalns = $scraper->resolveLocation('Inčukalna Sporta centrs', 'SK Coyote');
        $this->assertEquals('Inčukalna Sporta centrs', $incukalns['venue']);
        $this->assertEquals('Inčukalns', $incukalns['city']);
        $this->assertEquals('Vidzeme', $incukalns['region']);
        $this->assertEquals('Atmodas iela 2, Inčukalns', $incukalns['address']);

        $balozhi = $scraper->resolveLocation('Baložu vidusskola', 'Baložu komanda');
        $this->assertEquals('Baloži', $balozhi['city']);
        $this->assertEquals('Skolas iela 6, Baloži', $balozhi['address']);

        $kuldinga = $scraper->resolveLocation('Kuldīgas sporta halle', 'Kuldīga');
        $this->assertEquals('Kuldīga', $kuldinga['city']);
        $this->assertEquals('Piltenes iela 25, Kuldīga', $kuldinga['address']);

        $ogre = $scraper->resolveLocation('Ogres Kalna Pamatskola', 'FK Ogre');
        $this->assertEquals('Ogre', $ogre['city']);
        $this->assertEquals('Meža prospekts 14, Ogre', $ogre['address']);
    }

    public function test_afiro_scraper_filters_out_floorball_stubs(): void
    {
        $afiroScraper = new \App\Services\Scrapers\Sources\AfiroApiScraper();
        $source = new Source(['id' => 1, 'name' => 'afiro', 'slug' => 'afiro-api']);

        // Use reflection to test private mapItemToDTO
        $ref = new \ReflectionClass($afiroScraper);
        $method = $ref->getMethod('mapItemToDTO');
        $method->setAccessible(true);

        // 1. Generic floorball stub from Afiro like latvijas-florbola-nQmmSg
        $stubItem = [
            'id' => 'ybdssso7op2fgidj9i541q65',
            'title' => 'Latvijas florbola',
            'startAt' => now()->addDays(5)->toIso8601String(),
            'description' => 'Florbola sezona rit pilnā sparā! Meklē Latvijas Florbola savienības mājaslapā.',
            'cta' => [
                'links' => [
                    ['url' => 'https://www.floorball.lv/lv/2025/jaunatne/zu18/kalendars', 'kind' => 'details']
                ]
            ]
        ];
        $dto1 = $method->invoke($afiroScraper, $stubItem, $source, 'lv');
        $this->assertNull($dto1, 'Floorball stub from Afiro must be filtered out');

        // 2. League digest stub with floorball.lv link
        $digestItem = [
            'id' => 'f8h36vbw1j9xmsg4g7773yd2',
            'title' => 'ELVI florbola līga sievietēm',
            'startAt' => now()->addDays(6)->toIso8601String(),
            'description' => 'Oficiālais spēļu kalendārs: https://www.floorball.lv/lv/2026/chempionats/sv/kalendars',
        ];
        $dto2 = $method->invoke($afiroScraper, $digestItem, $source, 'lv');
        $this->assertNull($dto2, 'ELVI league stub from Afiro must be filtered out');

        // 3. Regular non-floorball sports event from Afiro should be kept
        $regularItem = [
            'id' => 'valid123',
            'title' => 'Rīgas maratona koptreniņš',
            'startAt' => now()->addDays(4)->toIso8601String(),
            'description' => 'Sagatavojies pavasara skrējienam kopā ar treneriem Mežaparkā.',
            'categories' => ['sports'],
        ];
        $dto3 = $method->invoke($afiroScraper, $regularItem, $source, 'lv');
        $this->assertNotNull($dto3, 'Regular non-floorball events from Afiro must be kept');
        $this->assertEquals('Rīgas maratona koptreniņš', $dto3->title);
    }
}
