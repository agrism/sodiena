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
        $this->assertEquals('https://sodiena.hel1.your-objectstorage.com/leagues/volejbols.webp', VolejbolsLvScraper::DEFAULT_IMAGE);
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
        $this->assertEquals(VolejbolsLvScraper::DEFAULT_IMAGE, $saved->display_image_url);
    }

    public function test_parse_dataproject_competitions_filters_season_and_leagues(): void
    {
        $html = <<<'HTML'
<ul class="core-menu">
    <li class="nav-link">
        <a href="#">Sezonas</a>
        <ul class="dropdown1">
            <li>
                <a href="#">LATVF 2026/27</a>
                <ul class="dropdown2">
                    <li><a href="CompetitionHome.aspx?ID=177">Latvijas Čempionāts Nacionālā līga 2026/2027 vīriešiem</a></li>
                    <li><a href="CompetitionHome.aspx?ID=178">LČ Nacionālā līga sievietēm 2026/2027</a></li>
                    <li><a href="CompetitionHome.aspx?ID=185">Latvijas Jaunatnes čempionāts U15M 2026/2027</a></li>
                    <li><a href="CompetitionHome.aspx?ID=179">Latvijas Kauss 2026</a></li>
                </ul>
            </li>
            <li>
                <a href="#">LATVF 2025/26</a>
                <ul class="dropdown2">
                    <li><a href="CompetitionHome.aspx?ID=124">LČ Nacionālā līga sievietēm 2025/2026</a></li>
                </ul>
            </li>
        </ul>
    </li>
</ul>
HTML;

        $crawler = new Crawler($html);
        $scraper = new VolejbolsLvScraper();
        $competitions = $scraper->parseDataProjectCompetitions($crawler, 'lvf', 2026, 2027);

        $this->assertCount(2, $competitions);
        $this->assertSame([177, 178], array_column($competitions, 'id'));
    }

    public function test_parse_dataproject_matches_handles_future_played_past_and_templates(): void
    {
        $future = Carbon::now('Europe/Riga')->addDays(4);
        $futureDot = $future->format('d.m.Y') . ' - 18:00';
        $futureSlash = $future->format('d/m/Y') . ' - 19:30';
        $pastDot = Carbon::now('Europe/Riga')->subDays(2)->format('d.m.Y') . ' - 15:00';

        $lvfHtml = <<<HTML
<div>
    <div class="t-row t-hidden-xs t-hidden-sm">
        <div onclick="javascript:window.location='MatchStatistics.aspx?mID=5534&amp;ID=177';"></div>
        <span id="ctl00_Content_Main_650_LB_DataOra">{$futureDot}</span>
        <span id="ctl00_Content_Main_650_LB_Palasport">Pārventas Sporta halle, Tārgales 5a</span>
        <span id="ctl00_Content_Main_650_LBL_HomeTeamName">VK Ventspils</span>
        <span id="ctl00_Content_Main_650_LBL_Separ" class="Calendar_Match_Result"><b> - </b></span>
        <span id="ctl00_Content_Main_650_LBL_GuestTeamName">RSU</span>
    </div>
    <div class="t-row t-hidden-xs t-hidden-sm">
        <div onclick="javascript:window.location='MatchStatistics.aspx?mID=5522&amp;ID=177';"></div>
        <span id="ctl00_Content_Main_650_LB_DataOra">{$futureDot}</span>
        <span id="ctl00_Content_Main_650_LB_Palasport">RVS sporta zāle</span>
        <span id="ctl00_Content_Main_650_Label2"><b>RSU</b></span>
        <span id="ctl00_Content_Main_650_Label1" class="Calendar_Match_Result"><b>3</b></span>
        <span id="ctl00_Content_Main_650_Label5" class="Calendar_Match_Result"><b>0</b></span>
        <span id="ctl00_Content_Main_650_Label4">Aizpute</span>
    </div>
    <div class="t-row t-hidden-xs t-hidden-sm">
        <div onclick="javascript:window.location='MatchStatistics.aspx?mID=5510&amp;ID=177';"></div>
        <span id="ctl00_Content_Main_650_LB_DataOra">{$pastDot}</span>
        <span id="ctl00_Content_Main_650_LB_Palasport">RVS sporta zāle</span>
        <span id="ctl00_Content_Main_650_LBL_HomeTeamName">RSU</span>
        <span id="ctl00_Content_Main_650_LBL_Separ" class="Calendar_Match_Result"><b> - </b></span>
        <span id="ctl00_Content_Main_650_LBL_GuestTeamName">Aizpute</span>
    </div>
</div>
HTML;

        $scraper = new VolejbolsLvScraper();
        $todayStart = Carbon::now('Europe/Riga')->startOfDay();

        $lvfEvents = $scraper->parseDataProjectMatches(
            new Crawler($lvfHtml),
            'lvf',
            177,
            'Latvijas Čempionāts Nacionālā līga 2026/2027 vīriešiem',
            $todayStart
        );

        $this->assertCount(1, $lvfEvents);
        $dto = $lvfEvents->first();
        $this->assertEquals('VK Ventspils vs RSU', $dto->title);
        $this->assertEquals('lvf_5534', $dto->sourceExternalId);
        $this->assertEquals('https://volejbols.lv/spele/5534/', $dto->sourceUrl);
        $this->assertEquals('Pārventas Sporta halle', $dto->venueName);
        $this->assertEquals('Ventspils', $dto->city);

        $bvlHtml = <<<HTML
<div>
    <div class="t-row t-hidden-xs t-hidden-sm">
        <div onclick="javascript:window.location='MatchStatistics.aspx?mID=1652&amp;ID=29';"></div>
        <span id="ctl00_Content_Main_50_LB_DataOra">{$futureSlash}</span>
        <span id="ctl00_Content_Main_50_LB_Palasport">Jüri Sports hall</span>
        <span id="ctl00_Content_Main_50_Label2">Rae Spordikool/VIASTON</span>
        <span id="ctl00_Content_Main_50_LBL_Separ" class="Calendar_Match_Result"><b> - </b></span>
        <span id="ctl00_Content_Main_50_Label4">Riga Volleyball School/LU</span>
    </div>
</div>
HTML;

        $bvlEvents = $scraper->parseDataProjectMatches(
            new Crawler($bvlHtml),
            'bvl',
            29,
            'Elme Messer Women`s Baltic League 2026/2027',
            $todayStart
        );

        $this->assertCount(1, $bvlEvents);
        $bvlDto = $bvlEvents->first();
        $this->assertEquals('lvf_bvl_1652', $bvlDto->sourceExternalId);
        $this->assertEquals('https://volejbols.lv/bvl/spele/1652/', $bvlDto->sourceUrl);
        $this->assertEquals('Jüri spordihoone', $bvlDto->venueName);
        $this->assertEquals('Jüri', $bvlDto->city);
        $this->assertEquals('Igaunija', $bvlDto->region);
    }

    public function test_scrape_discovers_and_parses_dataproject_portals_via_mock_http(): void
    {
        $now = Carbon::now('Europe/Riga');
        $seasonStart = $now->month >= 7 ? $now->year : $now->year - 1;
        $seasonEnd = $seasonStart + 1;
        $seasonLabel = "{$seasonStart}/{$seasonEnd}";
        $futureDateDot = $now->copy()->addDays(3)->format('d.m.Y') . ' - 19:00';
        $futureDateSlash = $now->copy()->addDays(5)->format('d/m/Y') . ' - 17:00';

        $bvlMain = <<<HTML
<ul class="core-menu"><li class="nav-link"><ul class="dropdown1"><li>
    <a href="#">BVL {$seasonLabel}</a>
    <ul class="dropdown2"><li><a href="CompetitionHome.aspx?ID=29">Elme Messer Men`s Baltic League {$seasonLabel}</a></li></ul>
</li></ul></li></ul>
HTML;
        $bvlMatches = <<<HTML
<div><div class="t-row t-hidden-xs t-hidden-sm">
    <div onclick="window.location='MatchStatistics.aspx?mID=1699&amp;ID=29';"></div>
    <span id="x_LB_DataOra">{$futureDateSlash}</span>
    <span id="x_LB_Palasport">RIMI Olimpiskais centrs, Grostonas iela 6b</span>
    <span id="x_LBL_HomeTeamName">Robežsardze / RSU</span>
    <span id="x_LBL_Separ" class="Calendar_Match_Result"> - </span>
    <span id="x_LBL_GuestTeamName">Jēkabpils Lūši</span>
</div></div>
HTML;
        $lvfMain = <<<HTML
<ul class="core-menu"><li class="nav-link"><ul class="dropdown1"><li>
    <a href="#">LATVF {$seasonLabel}</a>
    <ul class="dropdown2"><li><a href="CompetitionHome.aspx?ID=182">Latvijas čempionāts Reģionālā līga {$seasonLabel} vīrieši</a></li></ul>
</li></ul></li></ul>
HTML;
        $lvfMatches = <<<HTML
<div><div class="t-row t-hidden-xs t-hidden-sm">
    <div onclick="window.location='MatchStatistics.aspx?mID=5785&amp;ID=182';"></div>
    <span id="x_LB_DataOra">{$futureDateDot}</span>
    <span id="x_LB_Palasport">Dunalkas sporta nam</span>
    <span id="x_Label2">VK Dunalka</span>
    <span id="x_LBL_Separ" class="Calendar_Match_Result"> - </span>
    <span id="x_Label4">Liepāja/LSSS</span>
</div></div>
HTML;

        $mock = new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, [], $bvlMain),
            new \GuzzleHttp\Psr7\Response(200, [], $bvlMatches),
            new \GuzzleHttp\Psr7\Response(200, [], $lvfMain),
            new \GuzzleHttp\Psr7\Response(200, [], $lvfMatches),
        ]);

        $scraper = new VolejbolsLvScraper();
        $scraper->setClient(new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create($mock)]));

        $source = new Source(['slug' => 'volejbols-lv', 'name' => 'Latvijas Volejbola Federācija (volejbols.lv)']);
        $events = $scraper->scrape($source);

        $this->assertCount(2, $events);
        $this->assertEquals('lvf_bvl_1699', $events[0]->sourceExternalId);
        $this->assertEquals('Rimi Olimpiskais centrs', $events[0]->venueName);
        $this->assertEquals('lvf_5785', $events[1]->sourceExternalId);
        $this->assertEquals('Dunalkas sporta nams', $events[1]->venueName);
        $this->assertEquals('Dunalka', $events[1]->city);
        $this->assertEquals('Kurzeme', $events[1]->region);
    }
}

