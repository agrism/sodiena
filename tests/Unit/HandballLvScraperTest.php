<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\HandballLvScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class HandballLvScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_handball_scraper_metadata(): void
    {
        $scraper = new HandballLvScraper();
        $this->assertEquals('handball-lv', $scraper->getSlug());
        $this->assertEquals('Latvijas Handbola Federācija', $scraper->getName());
        $this->assertEquals('https://sodiena.hel1.your-objectstorage.com/leagues/handball.webp', HandballLvScraper::DEFAULT_IMAGE);
        $this->assertEquals('https://sodiena.hel1.your-objectstorage.com/leagues/handball.webp', HandballLvScraper::DEFAULT_IMAGE_VIRSLIGA);
        $this->assertEquals('https://sodiena.hel1.your-objectstorage.com/leagues/handball_kauss.webp', HandballLvScraper::DEFAULT_IMAGE_KAUSS);
        $this->assertEquals('https://sodiena.hel1.your-objectstorage.com/leagues/handball_baltic.webp', HandballLvScraper::DEFAULT_IMAGE_BALTIC);
        $this->assertEquals('https://sodiena.hel1.your-objectstorage.com/leagues/handball_sievietes.webp', HandballLvScraper::DEFAULT_IMAGE_SIEVĪTES);
    }

    public function test_resolve_image_for_league(): void
    {
        $scraper = new HandballLvScraper();
        $this->assertEquals(HandballLvScraper::DEFAULT_IMAGE_VIRSLIGA, $scraper->resolveImageForLeague('Latvijas SynotTip vīriešu Virslīga'));
        $this->assertEquals(HandballLvScraper::DEFAULT_IMAGE_KAUSS, $scraper->resolveImageForLeague('Latvijas SynotTip kauss vīriešiem 2027'));
        $this->assertEquals(HandballLvScraper::DEFAULT_IMAGE_BALTIC, $scraper->resolveImageForLeague('Baltic Handball League'));
        $this->assertEquals(HandballLvScraper::DEFAULT_IMAGE_SIEVĪTES, $scraper->resolveImageForLeague('Estonian - Latvian Women´s Handball League'));
        $this->assertEquals(HandballLvScraper::DEFAULT_IMAGE_SIEVĪTES, $scraper->resolveImageForLeague('Latvijas sieviešu Virslīga'));
    }

    public function test_parse_row_valid_game(): void
    {
        $futureDate = now()->addDays(10)->format('Y-m-d');
        $html = <<<HTML
        <div class="row calendar-row">
            <div class="col-md-4">
                <div class="calendar-first-column">
                    <div>
                        <a href="https://handball.lv/league/1278" class="d-block">Latvijas SynotTip kauss vīriešiem 2027</a>
                        <div style="font-size: 80%">
                            <span>Pamaturnīrs</span>
                            <div>19:30 · Salaspils Sporta nams, Salaspils, Salaspils pilsēta, Salaspils novads</div>
                        </div>
                    </div>
                </div>
            </div>
            <a href="https://handball.lv/calendar/30238" class="col-md-3">
                <div class="calendar-teams">
                    <div class="calendar-team">
                        <div>Salaspils SS</div>
                        <div class="calendar-team-result"></div>
                    </div>
                    <div class="calendar-team">
                        <div>ASK/MSĢ</div>
                        <div class="calendar-team-result"></div>
                    </div>
                </div>
            </a>
            <div class="col-md-4 text-right">
                <a href="https://youtube.com/live/eDGWg4NnHxE" target="_blank"><i class="fa fa-youtube"></i></a>
            </div>
        </div>
HTML;

        $crawler = new Crawler($html);
        $row = $crawler->filter('.calendar-row')->first();

        $scraper = new HandballLvScraper();
        $dto = $scraper->parseRow($row, $futureDate, 'https://handball.lv/league/1278/calendar');

        $this->assertNotNull($dto);
        $this->assertEquals('Salaspils SS vs ASK/MSĢ', $dto->title);
        $this->assertEquals("{$futureDate} 19:30", $dto->startAt->format('Y-m-d H:i'));
        $this->assertEquals("{$futureDate} 21:00", $dto->endAt->format('Y-m-d H:i'));
        $this->assertEquals('Salaspils Sporta nams', $dto->venueName);
        $this->assertEquals('Salaspils', $dto->city);
        $this->assertEquals('Rīga un Pierīga', $dto->region);
        $this->assertEquals('Smilšu iela 1, Salaspils', $dto->address);
        $this->assertEqualsWithDelta(56.85899, $dto->latitude, 0.0001);
        $this->assertEqualsWithDelta(24.33448, $dto->longitude, 0.0001);
        $this->assertEquals('sports', $dto->entertainmentType);
        $this->assertContains('Sports', $dto->categoryNames);
        $this->assertEquals('30238', $dto->sourceExternalId);
        $this->assertEquals('https://handball.lv/calendar/30238', $dto->sourceUrl);
        $this->assertEquals(HandballLvScraper::DEFAULT_IMAGE_KAUSS, $dto->imageUrl);
        $this->assertStringContainsString('Pamaturnīrs', $dto->description);
        $this->assertStringContainsString('https://youtube.com/live/eDGWg4NnHxE', $dto->description);
    }

    public function test_parse_row_skips_unconfirmed_playoff_placeholder(): void
    {
        $futureDate = now()->addMonths(2)->format('Y-m-d');
        $html = <<<HTML
        <div class="row calendar-row">
            <div class="col-md-4">
                <div class="calendar-first-column">
                    <div>
                        <a href="https://handball.lv/league/1278" class="d-block">Latvijas SynotTip kauss vīriešiem 2027</a>
                        <div style="font-size: 80%">
                            <span>Pusfināls 1</span>
                            <div>00:00 · </div>
                        </div>
                    </div>
                </div>
            </div>
            <a href="https://handball.lv/calendar/30252" class="col-md-3">
                <div class="calendar-teams">
                    <div class="calendar-team">
                        <div></div>
                    </div>
                    <div class="calendar-team">
                        <div></div>
                    </div>
                </div>
            </a>
        </div>
HTML;

        $crawler = new Crawler($html);
        $row = $crawler->filter('.calendar-row')->first();

        $scraper = new HandballLvScraper();
        $dto = $scraper->parseRow($row, $futureDate, 'https://handball.lv/league/1278/calendar');

        $this->assertNull($dto);
    }

    public function test_parse_row_skips_past_events(): void
    {
        $pastDate = now()->subDays(5)->format('Y-m-d');
        $html = <<<HTML
        <div class="row calendar-row">
            <div class="col-md-4">
                <div class="calendar-first-column">
                    <div>
                        <div style="font-size: 80%">
                            <span>Pamaturnīrs</span>
                            <div>18:00 · Dobeles sporta centrs, Tērvetes iela 10, Dobele</div>
                        </div>
                    </div>
                </div>
            </div>
            <a href="https://handball.lv/calendar/11111" class="col-md-3">
                <div class="calendar-teams">
                    <div class="calendar-team"><div>ZRHK TENAX Dobele</div></div>
                    <div class="calendar-team"><div>HK Ogre/RSU</div></div>
                </div>
            </a>
        </div>
HTML;

        $crawler = new Crawler($html);
        $row = $crawler->filter('.calendar-row')->first();

        $scraper = new HandballLvScraper();
        $dto = $scraper->parseRow($row, $pastDate, 'https://handball.lv/league/1278/calendar');

        $this->assertNull($dto);
    }

    public function test_parse_row_ogre_virsliga_game(): void
    {
        $futureDate = now()->addDays(5)->format('Y-m-d');
        $html = <<<HTML
        <div class="row calendar-row">
            <div class="col-md-4">
                <div class="calendar-first-column">
                    <div class="calendar-league-logo">
                        <img src="https://imagedelivery.net/y2XY-4X_-ySzHhnB-Lkcuw/6d78a686-1943-4de2-c4ec-d3ff37ad0400/huge">
                    </div>
                    <div>
                        <a href="https://handball.lv/league/1071" class="d-block">Latvijas SynotTip vīriešu Virslīga</a>
                        <div style="font-size: 80%">
                            <span>A grupa</span>
                            <div>14:00 · Ogres novada sporta centrs, Skolas iela 21, Ogre, Ogre pilsēta, Ogres novads, LV-5001</div>
                        </div>
                    </div>
                </div>
            </div>
            <a href="https://handball.lv/calendar/30181" class="col-md-3">
                <div class="calendar-teams">
                    <div class="calendar-team">
                        <div>ONSC/HK Ogre</div>
                        <div class="calendar-team-result">30</div>
                    </div>
                    <div class="calendar-team">
                        <div>SK Latgols</div>
                        <div class="calendar-team-result">40</div>
                    </div>
                </div>
            </a>
            <div class="col-md-4 text-right">
                <a href="https://youtube.com/live/GaIjCI03wso" target="_blank"><i class="fa fa-youtube"></i></a>
            </div>
        </div>
HTML;

        $crawler = new Crawler($html);
        $row = $crawler->filter('.calendar-row')->first();

        $scraper = new HandballLvScraper();
        $dto = $scraper->parseRow($row, $futureDate, 'https://handball.lv/calendar');

        $this->assertNotNull($dto);
        $this->assertEquals('ONSC/HK Ogre vs SK Latgols', $dto->title);
        $this->assertEquals("{$futureDate} 14:00", $dto->startAt->format('Y-m-d H:i'));
        $this->assertEquals("{$futureDate} 15:30", $dto->endAt->format('Y-m-d H:i'));
        $this->assertEquals('Ogres novada sporta centrs', $dto->venueName);
        $this->assertEquals('Ogre', $dto->city);
        $this->assertEquals('Vidzeme', $dto->region);
        $this->assertEquals('Skolas iela 21, Ogre', $dto->address);
        $this->assertEqualsWithDelta(56.81714, $dto->latitude, 0.0001);
        $this->assertEqualsWithDelta(24.59184, $dto->longitude, 0.0001);
        $this->assertStringContainsString('Latvijas SynotTip vīriešu Virslīga', $dto->description);
        $this->assertEquals(HandballLvScraper::DEFAULT_IMAGE_VIRSLIGA, $dto->imageUrl);
        $this->assertEquals('30181', $dto->sourceExternalId);
    }

    public function test_resolve_location(): void
    {
        $scraper = new HandballLvScraper();

        // Exact / substring match
        $loc1 = $scraper->resolveLocation('Dobeles sporta centrs, Tērvetes iela 10, Dobele', 'ZRHK TENAX Dobele');
        $this->assertEquals('Dobeles sporta centrs', $loc1['venue']);
        $this->assertEquals('Dobele', $loc1['city']);
        $this->assertEquals('Zemgale', $loc1['region']);
        $this->assertEquals('Tērvetes iela 10, Dobele', $loc1['address']);

        // Ogres novada sporta centrs
        $locOgre = $scraper->resolveLocation('Ogres novada sporta centrs, Skolas iela 21, Ogre, Ogre pilsēta, Ogres novads, LV-5001', 'ONSC/HK Ogre');
        $this->assertEquals('Ogres novada sporta centrs', $locOgre['venue']);
        $this->assertEquals('Ogre', $locOgre['city']);
        $this->assertEquals('Vidzeme', $locOgre['region']);
        $this->assertEquals('Skolas iela 21, Ogre', $locOgre['address']);

        // Ulbrokas sporta komplekss
        $locUlbroka = $scraper->resolveLocation('Ulbrokas sporta komplekss', '');
        $this->assertEquals('Institūta iela 1B, Ulbroka', $locUlbroka['address']);
        $this->assertEqualsWithDelta(56.92862, $locUlbroka['lat'], 0.0001);
        $this->assertEqualsWithDelta(24.32186, $locUlbroka['lon'], 0.0001);

        // Jēkabpils 3. vidusskola
        $locJekabpils = $scraper->resolveLocation('Jēkabpils 3. vidusskola', 'Jēkabpils');
        $this->assertEquals('Jēkabpils 3. vidusskolas sporta manēža', $locJekabpils['venue']);
        $this->assertEquals('Slimnīcas iela 5, Jēkabpils', $locJekabpils['address']);
        $this->assertEqualsWithDelta(56.50652, $locJekabpils['lat'], 0.0001);
        $this->assertEqualsWithDelta(25.88886, $locJekabpils['lon'], 0.0001);

        // Estonian venue
        $locEst = $scraper->resolveLocation('Tabasalu Kooli spordihoone', 'HC Tabasalu');
        $this->assertEquals('Tabasalu Kooli spordihoone', $locEst['venue']);
        $this->assertEquals('Tabasalu', $locEst['city']);
        $this->assertEquals('Igaunija', $locEst['region']);

        // Lithuanian venue
        $locLt = $scraper->resolveLocation('VMSM Sostines Tauras, Zygio 46, Vilnius, Lithuania', 'VHC Šviesa');
        $this->assertEquals('VMSM Sostines Tauras', $locLt['venue']);
        $this->assertEquals('Viļņa', $locLt['city']);
        $this->assertEquals('Lietuva', $locLt['region']);

        // Team fallback
        $loc2 = $scraper->resolveLocation('', 'ASK/MSĢ');
        $this->assertEquals('Rīga', $loc2['city']);
        $this->assertEquals('Rīga un Pierīga', $loc2['region']);

        // ONSC/HK Ogre team fallback
        $locOgreTeam = $scraper->resolveLocation('', 'ONSC/HK Ogre');
        $this->assertEquals('Ogre', $locOgreTeam['city']);
        $this->assertEquals('Vidzeme', $locOgreTeam['region']);

        // Default fallback
        $loc3 = $scraper->resolveLocation('', 'Nezināma komanda');
        $this->assertEquals('Rīga', $loc3['city']);
    }

    public function test_ingest_handball_events_into_database(): void
    {
        Category::updateOrCreate(['name' => 'Sports'], ['slug' => 'sports']);

        $source = Source::create([
            'name' => 'Latvijas Handbola Federācija (handball.lv)',
            'slug' => 'handball-test',
            'url' => 'https://handball.lv/league/1278/calendar',
            'scraper_class' => HandballLvScraper::class,
            'is_active' => true,
        ]);

        $futureDate = now()->addDays(14)->format('Y-m-d');
        $html = <<<HTML
        <div class="row calendar-row">
            <div class="col-md-4">
                <div class="calendar-first-column">
                    <div>
                        <a href="https://handball.lv/league/1278" class="d-block">Latvijas SynotTip kauss vīriešiem 2027</a>
                        <div style="font-size: 80%">
                            <span>Pamaturnīrs</span>
                            <div>14:00 · Dobeles sporta centrs, Tērvetes iela 10, Dobele, Dobeles novads</div>
                        </div>
                    </div>
                </div>
            </div>
            <a href="https://handball.lv/calendar/30244" class="col-md-3">
                <div class="calendar-teams">
                    <div class="calendar-team"><div>ZRHK TENAX Dobele</div></div>
                    <div class="calendar-team"><div>HK Ogre/RSU</div></div>
                </div>
            </a>
            <div class="col-md-4 text-right">
                <a href="https://youtube.com/live/-9BCutWidSo" target="_blank"><i class="fa fa-youtube"></i></a>
            </div>
        </div>
HTML;

        $crawler = new Crawler($html);
        $row = $crawler->filter('.calendar-row')->first();

        $scraper = new HandballLvScraper();
        $dto = $scraper->parseRow($row, $futureDate, $source->url);
        $this->assertNotNull($dto);

        $ingestionService = app(EventIngestionService::class);
        $status = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $status);
        $this->assertEquals(1, Event::where('source_id', $source->id)->count());

        $saved = Event::where('source_id', $source->id)->first();
        $this->assertEquals('ZRHK TENAX Dobele vs HK Ogre/RSU', $saved->title);
        $this->assertEquals('sports', $saved->entertainment_type);
        $this->assertEquals('Dobele', $saved->location->city);
        $this->assertEquals('Zemgale', $saved->location->region);
        $this->assertEquals(HandballLvScraper::DEFAULT_IMAGE_KAUSS, $saved->image_url);
        $this->assertEquals(HandballLvScraper::DEFAULT_IMAGE_KAUSS, $saved->display_image_url);
    }
}

