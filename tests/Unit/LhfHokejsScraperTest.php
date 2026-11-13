<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\LhfHokejsScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class LhfHokejsScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_lhf_scraper_metadata(): void
    {
        $scraper = new LhfHokejsScraper();
        $this->assertEquals('lhf-hokejs', $scraper->getSlug());
        $this->assertEquals('Latvijas Hokeja Federācija', $scraper->getName());
        $this->assertEquals('https://sodiena.hel1.your-objectstorage.com/leagues/hokejs_ohl.webp', LhfHokejsScraper::DEFAULT_IMAGE);
        $this->assertEquals('https://sodiena.hel1.your-objectstorage.com/leagues/hokejs_ohl.webp', LhfHokejsScraper::DEFAULT_IMAGE_OHL);
        $this->assertEquals('https://sodiena.hel1.your-objectstorage.com/leagues/hokejs_sievietes.webp', LhfHokejsScraper::DEFAULT_IMAGE_SIEVĪTES);
    }

    public function test_parse_row_ohl_game(): void
    {
        $futureDate = now()->addDays(5);
        $dateStr = $futureDate->format('d.m.Y');

        $html = <<<HTML
<table><tbody>
<tr>
    <td class="ta-c desktop" data-order="0054">#54</td>
    <td class="mobile tablet" data-order="PRI-LHK">PRILHK</td>
    <td class="desktop" data-order="PRI"><div class="team"><div class="image"><img src="/logo.png" alt="logo"/></div>HK Prizma/RTU</div></td>
    <td class="desktop" data-order="LHK"><div class="team"><div class="image"><img src="/logo2.png" alt="logo"/></div>Liepājas hokeja komanda</div></td>
    <td class="ta-l desktop" data-order="Volvo SC ledus halle"><svg></svg> Volvo SC ledus halle</td>
    <td class="desktop" data-order="{$dateStr}">{$dateStr}</td>
    <td class="desktop" data-order="19:00">19:00</td>
    <td class="tablet mobile">{$dateStr} 19:00</td>
    <td></td>
    <td class="ta-r"><div class="table_action_button"><a href="/lv/turniri/optibet-hokeja-liga/2026-2027/1245/speles/pri-lhk/20261110/35867" title="Spēles dati">Dati</a></div></td>
</tr>
</tbody></table>
HTML;

        $meta = [
            'name' => 'Optibet Hokeja Līga (OHL)',
            'image' => LhfHokejsScraper::DEFAULT_IMAGE_OHL,
            'url' => 'https://www.lhf.lv/lv/turniri/optibet-hokeja-liga/speles',
        ];

        $crawler = new Crawler($html);
        $scraper = new LhfHokejsScraper();
        $dto = $scraper->parseRow($crawler->filter('tr')->first(), 'ohl', $meta);

        $this->assertNotNull($dto);
        $this->assertEquals('HK Prizma/RTU vs Liepājas hokeja komanda', $dto->title);
        $this->assertEquals($futureDate->format('d.m.Y') . ' 19:00', $dto->startAt->format('d.m.Y H:i'));
        $this->assertEquals($futureDate->format('d.m.Y') . ' 21:15', $dto->endAt->format('d.m.Y H:i')); // +135 min
        $this->assertEquals('Volvo sporta centrs', $dto->venueName);
        $this->assertEquals('Rīga', $dto->city);
        $this->assertEquals('Rīga un Pierīga', $dto->region);
        $this->assertEquals('Jūrmalas gatve 78D, Rīga', $dto->address);
        $this->assertEqualsWithDelta(56.95133, $dto->latitude, 0.0001);
        $this->assertEqualsWithDelta(24.00691, $dto->longitude, 0.0001);
        $this->assertEquals('sports', $dto->entertainmentType);
        $this->assertContains('Sports', $dto->categoryNames);
        $this->assertEquals(LhfHokejsScraper::DEFAULT_IMAGE_OHL, $dto->imageUrl);
        $this->assertEquals('https://www.lhf.lv/lv/turniri/optibet-hokeja-liga/2026-2027/1245/speles/pri-lhk/20261110/35867', $dto->sourceUrl);
        $this->assertEquals('lhf_ohl_35867', $dto->sourceExternalId);
        $this->assertEquals('54', $dto->rawData['match_number']);
    }

    public function test_parse_row_womens_game(): void
    {
        $futureDate = now()->addDays(10);
        $dateStr = $futureDate->format('d.m.Y');

        $html = <<<HTML
<table><tbody>
<tr>
    <td class="ta-c desktop" data-order="0004">#4</td>
    <td class="mobile tablet">HSK-KLP</td>
    <td class="desktop"><div class="team">HS Kurbads</div></td>
    <td class="desktop"><div class="team">HC Klaipėda Girls</div></td>
    <td class="ta-l desktop">Kurbads ledus halle</td>
    <td class="desktop">{$dateStr}</td>
    <td class="desktop">14:25</td>
    <td class="tablet mobile">{$dateStr} 14:25</td>
    <td></td>
    <td class="ta-r">
        <a href="https://www.latvijashokejs.tv/lv/game/6ae0787edc3718a0c16828a3">Stream</a>
        <a href="/lv/turniri/baltijas-un-latvijas-cempionats-sievietem/2026-2027/1250/speles/hsk-klp/20261114/37874" title="Spēles dati">Dati</a>
    </td>
</tr>
</tbody></table>
HTML;

        $meta = [
            'name' => 'Baltijas un Latvijas Čempionāts sievietēm',
            'image' => LhfHokejsScraper::DEFAULT_IMAGE_SIEVĪTES,
            'url' => 'https://www.lhf.lv/lv/turniri/baltijas-un-latvijas-cempionats-sievietem/speles',
        ];

        $crawler = new Crawler($html);
        $scraper = new LhfHokejsScraper();
        $dto = $scraper->parseRow($crawler->filter('tr')->first(), 'sievietes', $meta);

        $this->assertNotNull($dto);
        $this->assertEquals('HS Kurbads vs HC Klaipėda Girls', $dto->title);
        $this->assertEquals('Kurbads ledus halle', $dto->venueName);
        $this->assertEquals('Rīga', $dto->city);
        $this->assertEquals('Rīga un Pierīga', $dto->region);
        $this->assertEquals('Rumbulas iela 19, Rīga', $dto->address);
        $this->assertEqualsWithDelta(56.94350, $dto->latitude, 0.0001);
        $this->assertEqualsWithDelta(24.17796, $dto->longitude, 0.0001);
        $this->assertEquals(LhfHokejsScraper::DEFAULT_IMAGE_SIEVĪTES, $dto->imageUrl);
        $this->assertEquals('https://www.lhf.lv/lv/turniri/baltijas-un-latvijas-cempionats-sievietem/2026-2027/1250/speles/hsk-klp/20261114/37874', $dto->sourceUrl);
        $this->assertEquals('lhf_sievietes_37874', $dto->sourceExternalId);
        $this->assertEquals('https://www.latvijashokejs.tv/lv/game/6ae0787edc3718a0c16828a3', $dto->rawData['stream_url']);
    }

    public function test_parse_row_tbd_time_defaults_to_midnight(): void
    {
        $futureDate = now()->addDays(12);
        $dateStr = $futureDate->format('d.m.Y');

        $html = <<<HTML
<table><tbody>
<tr>
    <td class="ta-c desktop">#100</td>
    <td class="mobile tablet">ZMG-KYC</td>
    <td class="desktop">HK Zemgale/LBTU</td>
    <td class="desktop">HC Kyiv Capitals</td>
    <td class="ta-l desktop">Jelgavas ledus halle</td>
    <td class="desktop">{$dateStr}</td>
    <td class="desktop">TP</td>
    <td class="tablet mobile">{$dateStr} TP</td>
    <td></td>
    <td class="ta-r"><a href="/lv/turniri/optibet-hokeja-liga/2026-2027/1245/speles/zmg-kyc/20261120/35899">Dati</a></td>
</tr>
</tbody></table>
HTML;

        $meta = [
            'name' => 'Optibet Hokeja Līga (OHL)',
            'image' => LhfHokejsScraper::DEFAULT_IMAGE_OHL,
            'url' => 'https://www.lhf.lv/lv/turniri/optibet-hokeja-liga/speles',
        ];

        $crawler = new Crawler($html);
        $scraper = new LhfHokejsScraper();
        $dto = $scraper->parseRow($crawler->filter('tr')->first(), 'ohl', $meta);

        $this->assertNotNull($dto);
        $this->assertEquals($futureDate->format('d.m.Y') . ' 00:00', $dto->startAt->format('d.m.Y H:i'));
        $this->assertStringContainsString('Spēles sākuma laiks tiks precizēts', $dto->description);
    }

    public function test_parse_row_skips_played_games(): void
    {
        $futureDate = now()->addDays(1);
        $dateStr = $futureDate->format('d.m.Y');

        $html = <<<HTML
<table><tbody>
<tr>
    <td class="ta-c desktop">#53</td>
    <td class="mobile tablet">HCP-PUN</td>
    <td class="desktop">HC Panter</td>
    <td class="desktop">HC Hockey Punks-Mototoja</td>
    <td class="ta-l desktop">Tallina, Igaunija</td>
    <td class="desktop">{$dateStr}</td>
    <td class="desktop">14:05</td>
    <td class="tablet mobile">{$dateStr} 14:05</td>
    <td>5:2</td>
    <td class="ta-r"><a href="/lv/turniri/optibet-hokeja-liga/2026-2027/1245/speles/35866">Dati</a></td>
</tr>
</tbody></table>
HTML;

        $meta = [
            'name' => 'Optibet Hokeja Līga (OHL)',
            'image' => LhfHokejsScraper::DEFAULT_IMAGE_OHL,
            'url' => 'https://www.lhf.lv/lv/turniri/optibet-hokeja-liga/speles',
        ];

        $crawler = new Crawler($html);
        $scraper = new LhfHokejsScraper();
        $dto = $scraper->parseRow($crawler->filter('tr')->first(), 'ohl', $meta);

        $this->assertNull($dto);
    }

    public function test_parse_row_skips_past_games(): void
    {
        $pastDate = now()->subDays(3);
        $dateStr = $pastDate->format('d.m.Y');

        $html = <<<HTML
<table><tbody>
<tr>
    <td class="ta-c desktop">#1</td>
    <td class="mobile tablet">PRI-LHK</td>
    <td class="desktop">HK Prizma/RTU</td>
    <td class="desktop">Liepājas hokeja komanda</td>
    <td class="ta-l desktop">Volvo SC ledus halle</td>
    <td class="desktop">{$dateStr}</td>
    <td class="desktop">19:00</td>
    <td class="tablet mobile">{$dateStr} 19:00</td>
    <td></td>
    <td class="ta-r"><a href="/lv/turniri/optibet-hokeja-liga/2026-2027/1245/speles/35800">Dati</a></td>
</tr>
</tbody></table>
HTML;

        $meta = [
            'name' => 'Optibet Hokeja Līga (OHL)',
            'image' => LhfHokejsScraper::DEFAULT_IMAGE_OHL,
            'url' => 'https://www.lhf.lv/lv/turniri/optibet-hokeja-liga/speles',
        ];

        $crawler = new Crawler($html);
        $scraper = new LhfHokejsScraper();
        $dto = $scraper->parseRow($crawler->filter('tr')->first(), 'ohl', $meta);

        $this->assertNull($dto);
    }

    public function test_resolve_location_international_and_fallbacks(): void
    {
        $scraper = new LhfHokejsScraper();

        // Tallinn
        $tallinn = $scraper->resolveLocation('Tallina, Igaunija', 'HC Panter');
        $this->assertEquals('Škoda Jäähall (Tallinn)', $tallinn['venue']);
        $this->assertEquals('Tallina', $tallinn['city']);
        $this->assertEquals('Igaunija', $tallinn['region']);
        $this->assertEqualsWithDelta(59.42614, $tallinn['lat'], 0.0001);

        // Vilnius
        $vilnius = $scraper->resolveLocation('Vilniaus pramogų arena', 'HC Hockey Punks-Mototoja');
        $this->assertEquals('Vilniaus pramogų arena', $vilnius['venue']);
        $this->assertEquals('Viļņa', $vilnius['city']);
        $this->assertEquals('Lietuva', $vilnius['region']);

        // Elektrėnai
        $elektrenai = $scraper->resolveLocation('Elektrenai ice arena', 'HC Energija');
        $this->assertEquals('Elektrėnų ledo rūmai', $elektrenai['venue']);
        $this->assertEquals('Elektrēni', $elektrenai['city']);
        $this->assertEquals('Lietuva', $elektrenai['region']);

        // Klaipēda
        $klaipeda = $scraper->resolveLocation('Klaipēda', 'HC Klaipėda Girls');
        $this->assertEquals('Klaipėdos ledo arena (Akropolis)', $klaipeda['venue']);
        $this->assertEquals('Klaipēda', $klaipeda['city']);
        $this->assertEquals('Lietuva', $klaipeda['region']);

        // Akropole
        $akropole = $scraper->resolveLocation('T/c Akropole slidotava', 'SHK L&L Hope');
        $this->assertEquals('Akropole Rīga ledus arēna', $akropole['venue']);
        $this->assertEquals('Rīga', $akropole['city']);
        $this->assertEquals('Rīga un Pierīga', $akropole['region']);

        // Team fallback
        $teamFb = $scraper->resolveLocation('', 'HK Zemgale/LBTU');
        $this->assertEquals('Jelgava', $teamFb['city']);
        $this->assertEquals('Zemgale', $teamFb['region']);
    }

    public function test_ingest_lhf_events_into_database(): void
    {
        Category::updateOrCreate(['name' => 'Sports'], ['slug' => 'sports']);

        $source = Source::create([
            'name' => 'Latvijas Hokeja Federācija (LHF)',
            'slug' => 'lhf-test',
            'url' => 'https://www.lhf.lv/lv/turniri/optibet-hokeja-liga/speles',
            'scraper_class' => LhfHokejsScraper::class,
            'is_active' => true,
        ]);

        $futureDate = now()->addDays(6);
        $dateStr = $futureDate->format('d.m.Y');

        $html = <<<HTML
<table><tbody>
<tr>
    <td class="ta-c desktop">#60</td>
    <td class="mobile tablet">MOG-ZMG</td>
    <td class="desktop"><div class="team">HK MOGO/RSU</div></td>
    <td class="desktop"><div class="team">HK Zemgale/LBTU</div></td>
    <td class="ta-l desktop">Mogo ledus halle</td>
    <td class="desktop">{$dateStr}</td>
    <td class="desktop">19:15</td>
    <td class="tablet mobile">{$dateStr} 19:15</td>
    <td></td>
    <td class="ta-r"><a href="/lv/turniri/optibet-hokeja-liga/2026-2027/1245/speles/mog-zmg/20261115/35880">Dati</a></td>
</tr>
</tbody></table>
HTML;

        $meta = [
            'name' => 'Optibet Hokeja Līga (OHL)',
            'image' => LhfHokejsScraper::DEFAULT_IMAGE_OHL,
            'url' => 'https://www.lhf.lv/lv/turniri/optibet-hokeja-liga/speles',
        ];

        $crawler = new Crawler($html);
        $scraper = new LhfHokejsScraper();
        $dto = $scraper->parseRow($crawler->filter('tr')->first(), 'ohl', $meta);
        $this->assertNotNull($dto);

        $ingestionService = app(EventIngestionService::class);
        $status = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $status);
        $this->assertEquals(1, Event::where('source_id', $source->id)->count());

        $saved = Event::where('source_id', $source->id)->first();
        $this->assertEquals('HK MOGO/RSU vs HK Zemgale/LBTU', $saved->title);
        $this->assertEquals('sports', $saved->entertainment_type);
        $this->assertEquals('Mogo ledus halle', $saved->location->name);
        $this->assertEquals('Rīga', $saved->location->city);
        $this->assertEquals('Rīga un Pierīga', $saved->location->region);
        $this->assertEquals(LhfHokejsScraper::DEFAULT_IMAGE_OHL, $saved->image_url);
        $this->assertEquals(LhfHokejsScraper::DEFAULT_IMAGE_OHL, $saved->display_image_url);
    }
}
