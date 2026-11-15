<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\DaugavpilsScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class DaugavpilsScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_daugavpils_scraper_metadata(): void
    {
        $scraper = new DaugavpilsScraper();
        $this->assertEquals('daugavpils-dome', $scraper->getSlug());
        $this->assertEquals('Daugavpils valstspilsēta', $scraper->getName());
    }

    public function test_daugavpils_scraper_single_date_extraction(): void
    {
        $scraper = new DaugavpilsScraper();
        $today = Carbon::create(2026, 9, 1, 0, 0, 0, 'Europe/Riga');

        $html = <<<HTML
        <div class="col-xs-6">
            <div class="event-date pink">
                <span class="date">16</span>
                <span class="month">Sep</span>
                <span class="time">16:00</span>
            </div>
            <div class="event-text">
                <h3>Koklēšanas nodarbība iesācējiem</h3>
                <p class="place">Daugavpils Vienības nama Tradīciju māja, Rīgas iela 22a</p>
            </div>
        </div>
        HTML;

        $node = new Crawler($html);
        [$start, $end] = $scraper->extractDates($node, $today);

        $this->assertNotNull($start);
        $this->assertEquals('2026-09-16 16:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertNull($end);
    }

    public function test_daugavpils_scraper_multi_date_extraction_cross_year(): void
    {
        $scraper = new DaugavpilsScraper();
        $today = Carbon::create(2026, 11, 15, 0, 0, 0, 'Europe/Riga');

        $html = <<<HTML
        <div class="col-xs-6">
            <div class="event-date grey">
                <span class="date1">15 Jan</span>
                <span class="time1">18:00</span>
                <span class="date2">17 Jan</span>
                <span class="time2">19:00</span>
            </div>
            <div class="event-text">
                <h3>XVI Starptautiskais garīgās mūzikas festivāls “Sudraba zvani”</h3>
                <p class="place">Daugavpils Vienības nams</p>
            </div>
        </div>
        HTML;

        $node = new Crawler($html);
        [$start, $end] = $scraper->extractDates($node, $today);

        $this->assertNotNull($start);
        $this->assertNotNull($end);
        // January belongs to next year (2027) relative to November 2026
        $this->assertEquals('2027-01-15 18:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertEquals('2027-01-17 19:00:00', $end->format('Y-m-d H:i:s'));
    }

    public function test_daugavpils_scraper_location_resolving(): void
    {
        $scraper = new DaugavpilsScraper();

        // 1. Rotko muzejs
        $loc1 = $scraper->resolveLocation('Rotko muzejs, Mihaila iela 3');
        $this->assertEquals('Rotko muzejs', $loc1['name']);
        $this->assertEquals('Mihaila iela 3, Daugavpils, LV-5401', $loc1['address']);
        $this->assertEquals('museum', $loc1['place_type']);

        // 2. Daugavpils teātris
        $loc2 = $scraper->resolveLocation('Daugavpils teātris, Rīgas iela 22a');
        $this->assertEquals('Daugavpils teātris', $loc2['name']);
        $this->assertEquals('theatre', $loc2['place_type']);

        // 3. Ledus halle
        $loc3 = $scraper->resolveLocation('Daugavpils Ledus halle, Stacijas iela 45A');
        $this->assertEquals('Daugavpils Ledus halle', $loc3['name']);
        $this->assertEquals('sports_complex', $loc3['place_type']);

        // 4. Sporta skola with uppercase
        $loc4 = $scraper->resolveLocation('DAUGAVPILS SPORTA SKOLA, KANDAVAS IELA 17A');
        $this->assertEquals('Daugavpils Sporta skola', $loc4['name']);
        $this->assertStringContainsString('Kandavas iela 17A', $loc4['address']);
        $this->assertEquals('sports_complex', $loc4['place_type']);

        // 5. Generic location
        $loc5 = $scraper->resolveLocation('Poļu nams, Varšavas iela 30, Daugavpils');
        $this->assertEquals('Poļu kultūras centrs (Poļu nams)', $loc5['name']);
        $this->assertStringContainsString('Varšavas iela 30', $loc5['address']);
    }

    public function test_daugavpils_scraper_strict_cinema_rules(): void
    {
        $scraper = new DaugavpilsScraper();

        // Film in title MUST be Kino and NEVER Teātris (AGENTS.md)
        $cats1 = $scraper->resolveCategories('Dokumentālā filma "Latgales stāsti"', 'Daugavpils Vienības nams');
        $this->assertContains('Kino', $cats1);
        $this->assertNotContains('Teātris', $cats1);

        // Kinoteātris MUST be Kino and NEVER Teātris (AGENTS.md)
        $cats2 = $scraper->resolveCategories('Īpašais seanss kinoteātrī', 'Silverscreen Daugavpils');
        $this->assertContains('Kino', $cats2);
        $this->assertNotContains('Teātris', $cats2);
    }

    public function test_daugavpils_scraper_theatre_music_sports_exhibitions(): void
    {
        $scraper = new DaugavpilsScraper();

        // 1. Theatre
        $cats1 = $scraper->resolveCategories('Daugavpils teātra izrāde "Agrā rūsa"', 'Daugavpils teātris, Rīgas iela 22a');
        $this->assertContains('Teātris', $cats1);
        $this->assertEquals('theatre', $scraper->resolveEntertainmentType($cats1, 'Daugavpils teātra izrāde "Agrā rūsa"'));

        // 2. Sports
        $cats2 = $scraper->resolveCategories('Optibet Hokeja Līga | RĪGAS HS/DINABURGA pret HC Hockey Punks-Mototoja', 'Daugavpils Ledus halle');
        $this->assertContains('Sports', $cats2);
        $this->assertEquals('sports', $scraper->resolveEntertainmentType($cats2, 'Optibet Hokeja Līga'));

        // 3. Music
        $cats3 = $scraper->resolveCategories('Svētku koncerts “MANAS ZEMES SIRDS” (Antra Stafecka)', 'Daugavpils Kultūras pils');
        $this->assertContains('Mūzika', $cats3);
        $this->assertContains('Svētki', $cats3);
        $this->assertEquals('concert', $scraper->resolveEntertainmentType($cats3, 'Svētku koncerts'));

        // 4. Exhibitions
        $cats4 = $scraper->resolveCategories('Keramikas izstāde un meistardarbi', 'Rotko muzejs, Mihaila iela 3');
        $this->assertContains('Izstādes', $cats4);
        $this->assertEquals('exhibition', $scraper->resolveEntertainmentType($cats4, 'Keramikas izstāde'));

        // 5. Seminars
        $cats5 = $scraper->resolveCategories('Keramikas darbnīca Rotko muzeja Amatu mājā', '18. novembra iela 8');
        $this->assertContains('Semināri', $cats5);

        // 6. Kids
        $cats6 = $scraper->resolveCategories('Muzejs bērniem | Radošā lasīšana', 'Rotko muzejs');
        $this->assertContains('Bērniem', $cats6);

        // 7. Fallback
        $cats7 = $scraper->resolveCategories('Vispārīgs notikums bez atslēgvārdiem', 'Daugavpils');
        $this->assertEquals(['Cits'], $cats7);
        $this->assertEquals('event', $scraper->resolveEntertainmentType($cats7, 'Vispārīgs notikums'));
    }

    public function test_daugavpils_skips_non_events(): void
    {
        $scraper = new DaugavpilsScraper();

        $this->assertTrue($scraper->isNonEvent('Ielaušanās spēle “Tradīciju lāde”', 'https://www.daugavpils.lv/afisa/ielausanas-spele-0107'));
        $this->assertTrue($scraper->isNonEvent('GADA PASĀKUMU KALENDĀRS', 'https://www.daugavpils.lv/afisa/gada-kalendars'));
        $this->assertTrue($scraper->isNonEvent('Ielu remonta darbi', 'https://www.daugavpils.lv/afisa/remonta-darbi'));
        $this->assertTrue($scraper->isNonEvent('Tiešsaistes pieraksts pie pašvaldības speciālistiem', 'https://www.daugavpils.lv/afisa/pieraksts'));
        $this->assertTrue($scraper->isNonEvent('Programmas „Impulss” remigrācijas veicināšanas konkursa 2. kārta'));
        $this->assertTrue($scraper->isNonEvent('PASĀKUMI DAUGAVPILĪ 11.09.- 17.09.'));

        // Real events should not be skipped
        $this->assertFalse($scraper->isNonEvent('Baltkrievu tautas ieražu svētki “Bagačs”'));
        $this->assertFalse($scraper->isNonEvent('Break-dance turnīrs "Skill Deal 2026"'));
        $this->assertFalse($scraper->isNonEvent('Optibet Hokeja Līga | RĪGAS HS/DINABURGA pret HK Zemgale/LBTU'));
    }

    public function test_daugavpils_event_ingestion(): void
    {
        $source = Source::create([
            'name' => 'Daugavpils valstspilsēta',
            'slug' => 'daugavpils-dome',
            'url' => 'https://www.daugavpils.lv/afisa/',
            'scraper_class' => DaugavpilsScraper::class,
            'is_active' => true,
        ]);

        $dto = new ScrapedEventDTO(
            title: 'Daugavpils teātra izrāde "Agrā rūsa"',
            startAt: Carbon::create(2026, 11, 25, 18, 30, 0, 'Europe/Riga'),
            endAt: null,
            description: "Daugavpils teātra izrāde \"Agrā rūsa\"\nNorises vieta: Daugavpils teātris\nAdrese: Rīgas iela 22A, Daugavpils, LV-5401",
            shortDescription: 'Daugavpils teātra izrāde "Agrā rūsa"',
            venueName: 'Daugavpils teātris',
            city: 'Daugavpils',
            region: 'Latgale',
            address: 'Rīgas iela 22A, Daugavpils, LV-5401',
            placeType: 'theatre',
            categoryNames: ['Teātris'],
            entertainmentType: 'theatre',
            isFree: false,
            imageUrl: 'https://www.daugavpils.lv/assets/upload/events/2026/10/dt-arusa-publ5-001_med.jpg',
            sourceUrl: 'https://www.daugavpils.lv/afisa/daugavpils-teatra-izrade-agra-rusa-1511',
            sourceExternalId: 'daugavpils-daugavpils-teatra-izrade-agra-rusa-1511',
            locale: 'lv',
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_external_id', 'daugavpils-daugavpils-teatra-izrade-agra-rusa-1511')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Daugavpils teātra izrāde "Agrā rūsa"', $event->title);
        $this->assertEquals('daugavpils.lv', $event->origin_host);
        $this->assertEquals('theatre', $event->entertainment_type);
        $this->assertEquals('Daugavpils', $event->location->city);
        $this->assertEquals('Latgale', $event->location->region);
    }
}
