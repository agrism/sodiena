<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\VolejbolsLvScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class VolejbolsLvScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_volejbols_scraper_metadata(): void
    {
        $scraper = new VolejbolsLvScraper();
        $this->assertEquals('volejbols-lv', $scraper->getSlug());
        $this->assertEquals('Latvijas Volejbola Federācija (volejbols.lv)', $scraper->getName());
        $this->assertEquals('/images/volejbols.webp', VolejbolsLvScraper::DEFAULT_IMAGE);
    }

    public function test_parse_match_elme_messer_baltic_league(): void
    {
        $futureDate = now()->addDays(5);
        $dateStr = $futureDate->format('Y-m-d');

        $html = <<<HTML
<ul>
<li data-date="{$dateStr}">
    <a href="https://volejbols.lv/bvl/spele/1652/" class="year-cal-item">
        <span class="year-cal-item__date"><span class="year-cal-item__day">14</span><span class="year-cal-item__month">Nov.</span></span>
        <div class="year-cal-item__body">
            <span class="year-cal-item__title">Elme Messer Men`s Baltic League 2026/2027</span>
            <span class="year-cal-item__teams">Jēkabpils Lūši – Robežsardze / RSU</span>
            <span class="year-cal-item__time">18:00</span>
        </div>
    </a>
</li>
</ul>
HTML;

        $subpageCache = [
            'https://volejbols.lv/bvl/spele/1652/' => [
                'venue' => 'Jēkabpils Sporta nams, Brīvības iela 2J, Jēkabpils',
                'home' => 'Jēkabpils Lūši',
                'away' => 'Robežsardze / RSU',
                'league' => 'Elme Messer Men`s Baltic League 2026/2027',
                'is_played' => false,
            ]
        ];

        $crawler = new Crawler($html);
        $scraper = new VolejbolsLvScraper();
        $dto = $scraper->parseMatch($crawler->filter('li')->first(), now()->format('Y-m-d'), $subpageCache);

        $this->assertNotNull($dto);
        $this->assertEquals('Jēkabpils Lūši vs Robežsardze / RSU', $dto->title);
        $this->assertEquals($futureDate->format('d.m.Y') . ' 18:00', $dto->startAt->format('d.m.Y H:i'));
        $this->assertEquals($futureDate->format('d.m.Y') . ' 19:45', $dto->endAt->format('d.m.Y H:i')); // +105 min
        $this->assertEquals('Jēkabpils sporta nams', $dto->venueName);
        $this->assertEquals('Jēkabpils', $dto->city);
        $this->assertEquals('Latgale', $dto->region);
        $this->assertEquals('Brīvības iela 2J, Jēkabpils', $dto->address);
        $this->assertEqualsWithDelta(56.48790, $dto->latitude, 0.0001);
        $this->assertEqualsWithDelta(25.88816, $dto->longitude, 0.0001);
        $this->assertEquals('sports', $dto->entertainmentType);
        $this->assertContains('Sports', $dto->categoryNames);
        $this->assertEquals(VolejbolsLvScraper::DEFAULT_IMAGE, $dto->imageUrl);
        $this->assertEquals('https://volejbols.lv/bvl/spele/1652/', $dto->sourceUrl);
        $this->assertEquals('lvf_bvl_1652', $dto->sourceExternalId);
    }

    public function test_parse_match_latvijas_cempionats(): void
    {
        $futureDate = now()->addDays(7);
        $dateStr = $futureDate->format('Y-m-d');

        $html = <<<HTML
<ul>
<li data-date="{$dateStr}">
    <a href="https://volejbols.lv/spele/5531/" class="year-cal-item">
        <div class="year-cal-item__body">
            <span class="year-cal-item__title">Latvijas Čempionāts Nacionālā līga 2026/2027 vīriešiem</span>
            <span class="year-cal-item__teams">Aizpute – Jelgavas novads / Evopipes</span>
            <span class="year-cal-item__time">18:00</span>
        </div>
    </a>
</li>
</ul>
HTML;

        $subpageCache = [
            'https://volejbols.lv/spele/5531/' => [
                'venue' => 'Aizputes Sporta centrs, Ziedu iela 7, Aizpute',
                'home' => 'Aizpute',
                'away' => 'Jelgavas novads / Evopipes',
                'league' => 'Latvijas Čempionāts Nacionālā līga 2026/2027 vīriešiem',
                'is_played' => false,
            ]
        ];

        $crawler = new Crawler($html);
        $scraper = new VolejbolsLvScraper();
        $dto = $scraper->parseMatch($crawler->filter('li')->first(), now()->format('Y-m-d'), $subpageCache);

        $this->assertNotNull($dto);
        $this->assertEquals('Aizpute vs Jelgavas novads / Evopipes', $dto->title);
        $this->assertEquals('Aizputes Sporta centrs', $dto->venueName);
        $this->assertEquals('Aizpute', $dto->city);
        $this->assertEquals('Kurzeme', $dto->region);
        $this->assertEquals('Ziedu iela 7B, Aizpute', $dto->address);
        $this->assertEqualsWithDelta(56.71957, $dto->latitude, 0.0001);
        $this->assertEqualsWithDelta(21.61044, $dto->longitude, 0.0001);
        $this->assertEquals('lvf_5531', $dto->sourceExternalId);
    }

    public function test_parse_match_lc_nacionala_liga(): void
    {
        $futureDate = now()->addDays(10);
        $dateStr = $futureDate->format('Y-m-d');

        $html = <<<HTML
<ul>
<li data-date="{$dateStr}">
    <a href="https://volejbols.lv/spele/5561/" class="year-cal-item">
        <div class="year-cal-item__body">
            <span class="year-cal-item__title">LČ Nacionālā līga sievietēm 2026/2027</span>
            <span class="year-cal-item__teams">VK Lazdukalns / Balvu novads – Mārupe</span>
            <span class="year-cal-item__time">14:00</span>
        </div>
    </a>
</li>
</ul>
HTML;

        $subpageCache = [
            'https://volejbols.lv/spele/5561/' => [
                'venue' => 'Balvu sākumskola',
                'home' => 'VK Lazdukalns / Balvu novads',
                'away' => 'Mārupe',
                'league' => 'LČ Nacionālā līga sievietēm 2026/2027',
                'is_played' => false,
            ]
        ];

        $crawler = new Crawler($html);
        $scraper = new VolejbolsLvScraper();
        $dto = $scraper->parseMatch($crawler->filter('li')->first(), now()->format('Y-m-d'), $subpageCache);

        $this->assertNotNull($dto);
        $this->assertEquals('VK Lazdukalns / Balvu novads vs Mārupe', $dto->title);
        $this->assertEquals('Balvu sākumskolas sporta zāle', $dto->venueName);
        $this->assertEquals('Balvi', $dto->city);
        $this->assertEquals('Latgale', $dto->region);
        $this->assertEquals('Partizānu iela 16, Balvi', $dto->address);
        $this->assertEqualsWithDelta(57.12994, $dto->latitude, 0.0001);
        $this->assertEqualsWithDelta(27.26220, $dto->longitude, 0.0001);
        $this->assertEquals('lvf_5561', $dto->sourceExternalId);
    }

    public function test_parse_match_skips_unwanted_leagues(): void
    {
        $futureDate = now()->addDays(2);
        $dateStr = $futureDate->format('Y-m-d');

        $html = <<<HTML
<ul>
<li data-date="{$dateStr}">
    <a href="https://volejbols.lv/spele/5810/" class="year-cal-item">
        <div class="year-cal-item__body">
            <span class="year-cal-item__title">Latvijas Jaunatnes čempionāts U14 Z 2026/2027</span>
            <span class="year-cal-item__teams">Jūrmalas Sporta skola – Liepājas SSS</span>
            <span class="year-cal-item__time">10:00</span>
        </div>
    </a>
</li>
</ul>
HTML;

        $subpageCache = [];
        $crawler = new Crawler($html);
        $scraper = new VolejbolsLvScraper();
        $dto = $scraper->parseMatch($crawler->filter('li')->first(), now()->format('Y-m-d'), $subpageCache);

        $this->assertNull($dto);
    }

    public function test_parse_match_skips_past_dates(): void
    {
        $pastDate = now()->subDays(5);
        $dateStr = $pastDate->format('Y-m-d');

        $html = <<<HTML
<ul>
<li data-date="{$dateStr}">
    <a href="https://volejbols.lv/spele/5500/" class="year-cal-item">
        <div class="year-cal-item__body">
            <span class="year-cal-item__title">Elme Messer Men`s Baltic League 2026/2027</span>
            <span class="year-cal-item__teams">VK Ventspils – RSU</span>
            <span class="year-cal-item__time">18:00</span>
        </div>
    </a>
</li>
</ul>
HTML;

        $subpageCache = [];
        $crawler = new Crawler($html);
        $scraper = new VolejbolsLvScraper();
        $dto = $scraper->parseMatch($crawler->filter('li')->first(), now()->format('Y-m-d'), $subpageCache);

        $this->assertNull($dto);
    }

    public function test_parse_match_skips_played_games(): void
    {
        $futureDate = now()->addDays(1);
        $dateStr = $futureDate->format('Y-m-d');

        $html = <<<HTML
<ul>
<li data-date="{$dateStr}">
    <a href="https://volejbols.lv/spele/5529/" class="year-cal-item">
        <div class="year-cal-item__body">
            <span class="year-cal-item__title">Latvijas Čempionāts Nacionālā līga 2026/2027 vīriešiem</span>
            <span class="year-cal-item__teams">Jelgavas novads – VK Ventspils</span>
            <span class="year-cal-item__time">13:00</span>
        </div>
    </a>
</li>
</ul>
HTML;

        $subpageCache = [
            'https://volejbols.lv/spele/5529/' => [
                'venue' => 'Jelgavas novada SC, Jelgava',
                'home' => 'Jelgavas novads',
                'away' => 'VK Ventspils',
                'league' => 'Latvijas Čempionāts Nacionālā līga 2026/2027 vīriešiem',
                'is_played' => true,
            ]
        ];

        $crawler = new Crawler($html);
        $scraper = new VolejbolsLvScraper();
        $dto = $scraper->parseMatch($crawler->filter('li')->first(), now()->format('Y-m-d'), $subpageCache);

        $this->assertNull($dto);
    }

    public function test_resolve_location_known_venues_and_fallbacks(): void
    {
        $scraper = new VolejbolsLvScraper();

        // Rimi Olympic center
        $loc1 = $scraper->resolveLocation('Rimi Olympic center, Riga', 'Robežsardze / RSU');
        $this->assertEquals('Rimi Olimpiskais centrs', $loc1['venue']);
        $this->assertEquals('Rīga', $loc1['city']);
        $this->assertEquals('Grostonas iela 6B, Rīga', $loc1['address']);
        $this->assertEqualsWithDelta(56.96766, $loc1['lat'], 0.0001);

        // Tartu
        $loc2 = $scraper->resolveLocation('Tartu Ülikooli SH Ilmar Kullami nimeline peaväljak, Tartu', 'TÜ/Bigbank');
        $this->assertEquals('Tartu Ülikooli Spordihoone', $loc2['venue']);
        $this->assertEquals('Tartu', $loc2['city']);
        $this->assertEquals('Igaunija', $loc2['region']);

        // Kaunas
        $loc3 = $scraper->resolveLocation('President Valdas Adamkus Sports Center (VDU), Kaunas', 'VDU Kaunas');
        $this->assertEquals('President Valdas Adamkus Sports Center (VDU)', $loc3['venue']);
        $this->assertEquals('Kauņa', $loc3['city']);
        $this->assertEquals('Lietuva', $loc3['region']);

        // Team fallback
        $loc4 = $scraper->resolveLocation('', 'VK Ventspils');
        $this->assertEquals('Ventspils', $loc4['city']);
        $this->assertEquals('Kurzeme', $loc4['region']);
    }

    public function test_ingest_volejbols_events_into_database(): void
    {
        Category::updateOrCreate(['name' => 'Sports'], ['slug' => 'sports']);

        $source = Source::create([
            'name' => 'Latvijas Volejbola Federācija (volejbols.lv)',
            'slug' => 'volejbols-test',
            'url' => 'https://volejbols.lv/kalendars/',
            'scraper_class' => VolejbolsLvScraper::class,
            'is_active' => true,
        ]);

        $futureDate = now()->addDays(8);
        $dateStr = $futureDate->format('Y-m-d');

        $html = <<<HTML
<ul>
<li data-date="{$dateStr}">
    <a href="https://volejbols.lv/spele/5534/" class="year-cal-item">
        <div class="year-cal-item__body">
            <span class="year-cal-item__title">Latvijas Čempionāts Nacionālā līga 2026/2027 vīriešiem</span>
            <span class="year-cal-item__teams">VK Ventspils – RSU</span>
            <span class="year-cal-item__time">13:00</span>
        </div>
    </a>
</li>
</ul>
HTML;

        $subpageCache = [
            'https://volejbols.lv/spele/5534/' => [
                'venue' => 'Pārventas Sporta halle, Tārgales 5a, Ventspils',
                'home' => 'VK Ventspils',
                'away' => 'RSU',
                'league' => 'Latvijas Čempionāts Nacionālā līga 2026/2027 vīriešiem',
                'is_played' => false,
            ]
        ];

        $crawler = new Crawler($html);
        $scraper = new VolejbolsLvScraper();
        $dto = $scraper->parseMatch($crawler->filter('li')->first(), now()->format('Y-m-d'), $subpageCache);
        $this->assertNotNull($dto);

        $ingestionService = app(EventIngestionService::class);
        $status = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $status);
        $this->assertEquals(1, Event::where('source_id', $source->id)->count());

        $saved = Event::where('source_id', $source->id)->first();
        $this->assertEquals('VK Ventspils vs RSU', $saved->title);
        $this->assertEquals('sports', $saved->entertainment_type);
        $this->assertEquals('Pārventas Sporta halle', $saved->location->name);
        $this->assertEquals('Ventspils', $saved->location->city);
        $this->assertEquals('Kurzeme', $saved->location->region);
        $this->assertEquals(VolejbolsLvScraper::DEFAULT_IMAGE, $saved->image_url);
        $this->assertEquals('http://localhost:8000/images/volejbols.webp', $saved->display_image_url);
    }
}

