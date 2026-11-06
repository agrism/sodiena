<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\LffVirsligaScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class LffVirsligaScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_lff_virsliga_scraper_metadata(): void
    {
        $scraper = new LffVirsligaScraper();
        $this->assertEquals('lff-virsliga', $scraper->getSlug());
        $this->assertEquals('Tonybet Virslīga (LFF)', $scraper->getName());
        $this->assertEquals('/images/virsliga.webp', LffVirsligaScraper::DEFAULT_IMAGE);
    }

    public function test_parse_tab1_match_valid(): void
    {
        $futureDate = now()->addDays(5)->format('d.m.Y');
        $html = <<<HTML
        <div class="tr match" data-id="23300248">
            <div>
                <div class="matchday">
                    <h4>13:00</h4>
                    <h5>36</h5>
                    <h6>kārta</h6>
                </div>
            </div>
            <div>
                <div class="clubs">
                    <div class="club">
                        <div class="title"><span><a href="/klubi/riga-fc-38388/">Riga FC</a></span></div>
                        <div class="logo"><img alt="" src="https://lff.lv/logo-riga.png"></div>
                        <div class="result"><span class="res1">-</span></div>
                    </div>
                    <div class="club">
                        <div class="title"><span><a href="/klubi/fc-rfs-36395/">FC RFS</a></span></div>
                        <div class="logo"><img alt="" src="https://lff.lv/logo-rfs.png"></div>
                        <div class="result"><span class="res2">-</span></div>
                    </div>
                </div>
                <div class="stadium">Skonto stadions</div>
            </div>
            <div><a class="info" href="/speles/riga-fc-fc-rfs-23300248/"> </a></div>
        </div>
HTML;

        $crawler = new Crawler($html);
        $row = $crawler->filter('.tr.match')->first();

        $scraper = new LffVirsligaScraper();
        $dto = $scraper->parseTab1Match($row, $futureDate, 'https://lff.lv/sacensibas/viriesi/virsliga/?tab=content_1_1');

        $this->assertNotNull($dto);
        $this->assertEquals('Riga FC vs FC RFS', $dto->title);
        $this->assertEquals("{$futureDate} 13:00", $dto->startAt->format('d.m.Y H:i'));
        $this->assertEquals("{$futureDate} 14:55", $dto->endAt->format('d.m.Y H:i'));
        $this->assertEquals('Skonto stadions', $dto->venueName);
        $this->assertEquals('Rīga', $dto->city);
        $this->assertEquals('Rīga un Pierīga', $dto->region);
        $this->assertEquals('Emiļa Melngaiļa iela 1a, Rīga', $dto->address);
        $this->assertEqualsWithDelta(56.96139, $dto->latitude, 0.0001);
        $this->assertEqualsWithDelta(24.11667, $dto->longitude, 0.0001);
        $this->assertEquals('sports', $dto->entertainmentType);
        $this->assertContains('Sports', $dto->categoryNames);
        $this->assertEquals('23300248', $dto->sourceExternalId);
        $this->assertEquals('https://lff.lv/speles/riga-fc-fc-rfs-23300248/', $dto->sourceUrl);
        $this->assertEquals(LffVirsligaScraper::DEFAULT_IMAGE, $dto->imageUrl);
        $this->assertStringContainsString('36. kārta', $dto->description);
        $this->assertStringContainsString('Skonto stadions', $dto->shortDescription);
    }

    public function test_parse_tab1_match_skips_past_events(): void
    {
        $pastDate = now()->subDays(2)->format('d.m.Y');
        $html = <<<HTML
        <div class="tr match" data-id="12345678">
            <div>
                <div class="matchday">
                    <h4>15:00</h4>
                    <h5>30</h5>
                    <h6>kārta</h6>
                </div>
            </div>
            <div>
                <div class="clubs">
                    <div class="club"><div class="title"><span>FS Jelgava</span></div></div>
                    <div class="club"><div class="title"><span>FK Auda</span></div></div>
                </div>
                <div class="stadium">Zemgales OC</div>
            </div>
        </div>
HTML;

        $crawler = new Crawler($html);
        $row = $crawler->filter('.tr.match')->first();

        $scraper = new LffVirsligaScraper();
        $dto = $scraper->parseTab1Match($row, $pastDate, 'https://lff.lv/sacensibas/viriesi/virsliga/?tab=content_1_1');

        $this->assertNull($dto);
    }

    public function test_parse_tab2_match_fallback(): void
    {
        $futureDate = now()->addDays(10);
        $day = $futureDate->format('j');
        $year = $futureDate->format('Y');
        $monthNum = (int) $futureDate->format('n');
        $latvianMonths = [
            1 => 'jan', 2 => 'feb', 3 => 'mar', 4 => 'apr',
            5 => 'mai', 6 => 'jūn', 7 => 'jūl', 8 => 'aug',
            9 => 'sep', 10 => 'okt', 11 => 'nov', 12 => 'dec',
        ];
        $monthStr = $latvianMonths[$monthNum];

        $html = <<<HTML
        <div class="tr match" data-id="99900011">
            <div>
                <div class="date">
                    <h4>sestdiena</h4>
                    <h5>{$day}</h5>
                    <h6>{$monthStr}</h6>
                    <div class="h7">14:00</div>
                    <div class="h8">{$year}</div>
                </div>
            </div>
            <div>
                <div class="clubs">
                    <div class="club"><div class="title"><span>FK Liepāja</span></div></div>
                    <div class="club"><div class="title"><span>FK Tukums 2000</span></div></div>
                </div>
                <div class="stadium">Stadions "Daugava", rezerves laukums</div>
            </div>
            <div><a class="info" href="/speles/liepaja-tukums-99900011/"> </a></div>
        </div>
HTML;

        $crawler = new Crawler($html);
        $row = $crawler->filter('.tr.match')->first();

        $scraper = new LffVirsligaScraper();
        $dto = $scraper->parseTab2Match($row, 'https://lff.lv/sacensibas/viriesi/virsliga/?tab=content_1_2');

        $this->assertNotNull($dto);
        $this->assertEquals('FK Liepāja vs FK Tukums 2000', $dto->title);
        $this->assertEquals($futureDate->format('d.m.Y') . ' 14:00', $dto->startAt->format('d.m.Y H:i'));
        $this->assertEquals('Liepāja', $dto->city);
        $this->assertEquals('Kurzeme', $dto->region);
        $this->assertEquals('Stadions "Daugava" (rezerves laukums)', $dto->venueName);
        $this->assertEquals('Jūrmalas parks 3, Liepāja', $dto->address);
        $this->assertEqualsWithDelta(56.50501, $dto->latitude, 0.0001);
    }

    public function test_resolve_location_exact_and_fuzzy(): void
    {
        $scraper = new LffVirsligaScraper();

        // Exact match
        $loc1 = $scraper->resolveLocation('Skonto stadions', 'Riga FC');
        $this->assertEquals('Skonto stadions', $loc1['venue']);
        $this->assertEquals('Emiļa Melngaiļa iela 1a, Rīga', $loc1['address']);

        // Fuzzy/quotes match
        $loc2 = $scraper->resolveLocation('Stadions “Daugava”, Liepāja', 'FK Liepāja');
        $this->assertEquals('Liepāja', $loc2['city']);
        $this->assertEquals('Jūrmalas parks 3, Liepāja', $loc2['address']);

        // Fallback by home team
        $loc3 = $scraper->resolveLocation('Nezināms stadions', 'FS Jelgava');
        $this->assertEquals('Jelgava', $loc3['city']);
        $this->assertEquals('Zemgale', $loc3['region']);
    }

    public function test_ingest_lff_virsliga_events_into_database(): void
    {
        Category::updateOrCreate(['name' => 'Sports'], ['slug' => 'sports']);

        $source = Source::create([
            'name' => 'Tonybet Virslīga (LFF)',
            'slug' => 'lff-virsliga-test',
            'url' => 'https://lff.lv/sacensibas/viriesi/virsliga/?tab=content_1_1',
            'scraper_class' => LffVirsligaScraper::class,
            'is_active' => true,
        ]);

        $futureDate = now()->addDays(7)->format('d.m.Y');
        $html = <<<HTML
        <div class="tr match" data-id="23300244">
            <div>
                <div class="matchday">
                    <h4>13:00</h4>
                    <h5>36</h5>
                    <h6>kārta</h6>
                </div>
            </div>
            <div>
                <div class="clubs">
                    <div class="club"><div class="title"><span>FS Jelgava</span></div></div>
                    <div class="club"><div class="title"><span>Ogre United</span></div></div>
                </div>
                <div class="stadium">FK Jelgava Sporta bāzes mākslīgais laukums</div>
            </div>
            <div><a class="info" href="/speles/fs-jelgava-ogre-united-23300244/"> </a></div>
        </div>
HTML;

        $crawler = new Crawler($html);
        $row = $crawler->filter('.tr.match')->first();

        $scraper = new LffVirsligaScraper();
        $dto = $scraper->parseTab1Match($row, $futureDate, $source->url);
        $this->assertNotNull($dto);

        $ingestionService = app(EventIngestionService::class);
        $status = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $status);
        $this->assertEquals(1, Event::where('source_id', $source->id)->count());

        $saved = Event::where('source_id', $source->id)->first();
        $this->assertEquals('FS Jelgava vs Ogre United', $saved->title);
        $this->assertEquals('sports', $saved->entertainment_type);
        $this->assertEquals('Jelgava', $saved->location->city);
        $this->assertEquals('Zemgale', $saved->location->region);
        $this->assertEquals(LffVirsligaScraper::DEFAULT_IMAGE, $saved->image_url);
        $this->assertEquals('http://localhost:8000/images/virsliga.webp', $saved->display_image_url);
    }
}
