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
    }
}
