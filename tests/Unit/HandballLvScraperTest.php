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
        $this->assertEquals('Latvijas Handbola Federācija (handball.lv)', $scraper->getName());
        $this->assertEquals('/images/handball.webp', HandballLvScraper::DEFAULT_IMAGE);
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
        $this->assertEquals(HandballLvScraper::DEFAULT_IMAGE, $dto->imageUrl);
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

    public function test_resolve_location(): void
    {
        $scraper = new HandballLvScraper();

        // Exact / substring match
        $loc1 = $scraper->resolveLocation('Dobeles sporta centrs, Tērvetes iela 10, Dobele', 'ZRHK TENAX Dobele');
        $this->assertEquals('Dobeles sporta centrs', $loc1['venue']);
        $this->assertEquals('Dobele', $loc1['city']);
        $this->assertEquals('Zemgale', $loc1['region']);
        $this->assertEquals('Tērvetes iela 10, Dobele', $loc1['address']);

        // Team fallback
        $loc2 = $scraper->resolveLocation('', 'ASK/MSĢ');
        $this->assertEquals('Rīga', $loc2['city']);
        $this->assertEquals('Rīga un Pierīga', $loc2['region']);

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
        $this->assertEquals(HandballLvScraper::DEFAULT_IMAGE, $saved->image_url);
        $this->assertEquals('http://localhost:8000/images/handball.webp', $saved->display_image_url);
    }
}
