<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\EstLatblScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class EstLatblScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_estlatbl_scraper_metadata(): void
    {
        $scraper = new EstLatblScraper();
        $this->assertEquals('estlatbl', $scraper->getSlug());
        $this->assertEquals('Optibet Latvijas - Igaunijas Basketbola Līga', $scraper->getName());
    }

    public function test_parse_row_valid_latvian_game(): void
    {
        $futureDate = now()->addDays(5)->format('d.m.Y');
        $html = <<<HTML
        <table>
            <tbody>
                <tr>
                    <td onclick="doRedirectGame('https://www.estlatbl.com/lv/game-center?gid=2027212089')" class="dateAndTimeTd">
                        <div class="dateAndTime">S  {$futureDate}, 16:00</div>
                        <div class="arena">Sporta arena Ogre</div>
                    </td>
                    <td class="homeTeam">
                        <div class="standingTeamBlock">
                            <div class="logo"><img src="https://www.estlatbl.com/logo-ogre.png" /></div>
                            <div class="teamName"><span class="homeTeamNameDesktop">BK Ogre</span></div>
                        </div>
                    </td>
                    <td class="center result">-</td>
                    <td class="right awayTeam">
                        <div class="standingTeamBlock">
                            <div class="logo"><img src="https://www.estlatbl.com/logo-kalev.png" /></div>
                            <div class="teamName"><span class="visitorTeamNameDesktop">Bigbank/Kalev</span></div>
                        </div>
                    </td>
                    <td class="center broadcast">
                        <div>
                            <img title="Delfi TV" />
                            <img title="TV4" />
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
HTML;

        $crawler = new Crawler($html);
        $row = $crawler->filter('tbody tr')->first();

        $scraper = new EstLatblScraper();
        $dto = $scraper->parseRow($row, 'https://www.estlatbl.com/lv/game-center');

        $this->assertNotNull($dto);
        $this->assertEquals('BK Ogre vs Bigbank/Kalev', $dto->title);
        $this->assertEquals("{$futureDate} 16:00", $dto->startAt->format('d.m.Y H:i'));
        $this->assertEquals("{$futureDate} 17:45", $dto->endAt->format('d.m.Y H:i'));
        $this->assertEquals('Sporta arēna Ogre', $dto->venueName);
        $this->assertEquals('Ogre', $dto->city);
        $this->assertEquals('Vidzeme', $dto->region);
        $this->assertEquals('Gunāra Astras iela 1, Ogre', $dto->address);
        $this->assertEquals('sports', $dto->entertainmentType);
        $this->assertContains('Sports', $dto->categoryNames);
        $this->assertEquals('2027212089', $dto->sourceExternalId);
        $this->assertEquals('https://www.estlatbl.com/lv/game-center?gid=2027212089', $dto->sourceUrl);
        $this->assertEquals(EstLatblScraper::DEFAULT_IMAGE, $dto->imageUrl);
        $this->assertStringContainsString('Delfi TV, TV4', $dto->description);
    }

    public function test_parse_row_valid_estonian_game(): void
    {
        $futureDate = now()->addDays(3)->format('d.m.Y');
        $html = <<<HTML
        <table>
            <tbody>
                <tr>
                    <td onclick="doRedirectGame('https://www.estlatbl.com/lv/game-center?gid=2027212047')" class="dateAndTimeTd">
                        <div class="dateAndTime">S  {$futureDate}, 17:00</div>
                        <div class="arena">Pärnu Spordihall</div>
                    </td>
                    <td class="homeTeam">
                        <div class="standingTeamBlock">
                            <div class="logo"><img src="https://www.estlatbl.com/logo-parnu.png" /></div>
                            <div class="teamName"><span class="homeTeamNameDesktop">BC Pärnu</span></div>
                        </div>
                    </td>
                    <td class="center result">-</td>
                    <td class="right awayTeam">
                        <div class="standingTeamBlock">
                            <div class="logo"><img src="https://www.estlatbl.com/logo-ventspils.png" /></div>
                            <div class="teamName"><span class="visitorTeamNameDesktop">BK Ventspils</span></div>
                        </div>
                    </td>
                    <td class="center broadcast">
                        <div>
                            <img title="Delfi TV" />
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
HTML;

        $crawler = new Crawler($html);
        $row = $crawler->filter('tbody tr')->first();

        $scraper = new EstLatblScraper();
        $dto = $scraper->parseRow($row, 'https://www.estlatbl.com/lv/game-center');

        $this->assertNotNull($dto);
        $this->assertEquals('BC Pärnu vs BK Ventspils', $dto->title);
        $this->assertEquals('Pärnu Spordihall', $dto->venueName);
        $this->assertEquals('Pērnava', $dto->city);
        $this->assertEquals('Igaunija', $dto->region);
    }

    public function test_parse_row_skips_past_games(): void
    {
        $pastDate = now()->subDays(5)->format('d.m.Y');
        $html = <<<HTML
        <table>
            <tbody>
                <tr>
                    <td onclick="doRedirectGame('https://www.estlatbl.com/lv/game-center?gid=2027212001')" class="dateAndTimeTd">
                        <div class="dateAndTime">S  {$pastDate}, 17:00</div>
                        <div class="arena">Daugavas sporta nams</div>
                    </td>
                    <td class="homeTeam">
                        <span class="homeTeamNameDesktop">VEF Rīga</span>
                    </td>
                    <td class="center result">85 - 70</td>
                    <td class="right awayTeam">
                        <span class="visitorTeamNameDesktop">BK Ventspils</span>
                    </td>
                </tr>
            </tbody>
        </table>
HTML;

        $crawler = new Crawler($html);
        $row = $crawler->filter('tbody tr')->first();

        $scraper = new EstLatblScraper();
        $dto = $scraper->parseRow($row, 'https://www.estlatbl.com/lv/game-center');

        $this->assertNull($dto);
    }

    public function test_resolve_location_fallbacks(): void
    {
        $scraper = new EstLatblScraper();

        $riga = $scraper->resolveLocation('Komandas Sporta Spelu Halle', 'Rīgas Zeļļi');
        $this->assertEquals('Komandu sporta spēļu zāle', $riga['venue']);
        $this->assertEquals('Rīga', $riga['city']);

        $fallback = $scraper->resolveLocation('*koht täpsustamisel', 'Bigbank/Kalev');
        $this->assertEquals('Tallina', $fallback['city']);
        $this->assertEquals('Igaunija', $fallback['region']);
    }

    public function test_estlatbl_event_ingestion_via_service(): void
    {
        Category::create(['name' => 'Sports', 'slug' => 'sports']);

        $source = Source::create([
            'name' => 'EstLatBL',
            'slug' => 'estlatbl',
            'url' => 'https://www.estlatbl.com/lv/game-center',
            'scraper_class' => EstLatblScraper::class,
            'is_active' => true,
        ]);

        $futureDate = now()->addDays(4);
        $dto = new \App\Services\Scrapers\DTO\ScrapedEventDTO(
            title: 'BK Liepāja vs Valmiera Glass VIA',
            startAt: $futureDate,
            endAt: (clone $futureDate)->addMinutes(105),
            description: 'Basketbola līgas spēle Liepājā.',
            shortDescription: 'Basketbola spēle Liepājā',
            venueName: 'Liepājas Olimpiskais centrs',
            city: 'Liepāja',
            region: 'Kurzeme',
            address: 'Brīvības iela 39, Liepāja',
            categoryNames: ['Sports'],
            entertainmentType: 'sports',
            sourceExternalId: '999888',
            sourceUrl: 'https://www.estlatbl.com/lv/game-center?gid=999888'
        );

        $service = app(EventIngestionService::class);
        $status = $service->ingestDTO($dto, $source);

        $this->assertEquals('created', $status);

        $event = Event::where('source_external_id', '999888')->first();
        $this->assertNotNull($event);
        $this->assertEquals('BK Liepāja vs Valmiera Glass VIA', $event->title);
        $this->assertEquals('Liepāja', $event->location->city);
        $this->assertTrue($event->categories->contains('slug', 'sports'));
    }
}

