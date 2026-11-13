<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\LffTelpuFutbolsScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class LffTelpuFutbolsScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_lff_telpu_futbols_scraper_metadata(): void
    {
        $scraper = new LffTelpuFutbolsScraper();
        $this->assertEquals('lff-telpu-futbols', $scraper->getSlug());
        $this->assertEquals('Optibet Telpu futbola Virslīga', $scraper->getName());
        $this->assertEquals('https://lff.lv/files/images/_resized/0000004721_1050_624_cut.jpg', LffTelpuFutbolsScraper::DEFAULT_IMAGE);
    }

    public function test_parse_row_valid_futsal_match(): void
    {
        $futureDate = now()->addDays(5);
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
        <div class="tr match" data-id="25157929">
            <div>
                <div class="date">
                    <h4>sestdiena</h4>
                    <h5>{$day}</h5>
                    <h6>{$monthStr}</h6>
                    <div class="h7">17:00</div>
                    <div class="h8">{$year}</div>
                </div>
            </div>
            <div>
                <div class="clubs">
                    <div class="club">
                        <div class="title"><span class="sizeMedium"><a href="/klubi/futbola-parks-academy-37334/?cid=25157557">Futbola Parks Academy</a></span></div>
                        <div class="logo"><img alt="" src="https://lff.lv/logo1.png"></div>
                        <div class="result"><span class="res1">-</span></div>
                    </div>
                    <div class="club">
                        <div class="title"><span><a href="/klubi/tfk-salaspils-13249/?cid=25157557">TFK Salaspils</a></span></div>
                        <div class="logo"><img alt="" src="https://lff.lv/logo2.png"></div>
                        <div class="result"><span class="res2">-</span></div>
                    </div>
                </div>
                <div class="stadium">Sauriešu sporta halle</div>
            </div>
            <div><a href="/speles/futbola-parks-academy-tfk-salaspils-25157929/"> </a></div>
        </div>
HTML;

        $crawler = new Crawler($html);
        $row = $crawler->filter('.tr.match')->first();

        $scraper = new LffTelpuFutbolsScraper();
        $dto = $scraper->parseRow($row, 'https://lff.lv/sacensibas/telpu-futbols/virsliga/?tab=content_1_2');

        $this->assertNotNull($dto);
        $this->assertEquals('Futbola Parks Academy vs TFK Salaspils', $dto->title);
        $this->assertEquals($futureDate->format('d.m.Y') . ' 17:00', $dto->startAt->format('d.m.Y H:i'));
        $this->assertEquals($futureDate->format('d.m.Y') . ' 18:30', $dto->endAt->format('d.m.Y H:i'));
        $this->assertEquals('Sauriešu sporta halle', $dto->venueName);
        $this->assertEquals('Saurieši', $dto->city);
        $this->assertEquals('Rīga un Pierīga', $dto->region);
        $this->assertEquals('Līdumnieku iela 10, Saurieši, Ropažu novads', $dto->address);
        $this->assertEqualsWithDelta(56.92098, $dto->latitude, 0.0001);
        $this->assertEqualsWithDelta(24.35591, $dto->longitude, 0.0001);
        $this->assertEquals('sports', $dto->entertainmentType);
        $this->assertContains('Sports', $dto->categoryNames);
        $this->assertNull($dto->ticketUrl);
        $this->assertEquals('https://lff.lv/speles/futbola-parks-academy-tfk-salaspils-25157929/', $dto->sourceUrl);
        $this->assertEquals(LffTelpuFutbolsScraper::DEFAULT_IMAGE, $dto->imageUrl);
        $this->assertEquals('25157929', $dto->sourceExternalId);
        $this->assertStringContainsString('Optibet Telpu futbola Virslīgas spēle', $dto->description);
        $this->assertStringContainsString('Sauriešu sporta halle', $dto->shortDescription);
    }

    public function test_parse_row_with_empty_time_defaults_to_midnight(): void
    {
        $futureDate = now()->addDays(20);
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
        <div class="tr match" data-id="25157945">
            <div>
                <div class="date">
                    <h4>sestdiena</h4>
                    <h5>{$day}</h5>
                    <h6>{$monthStr}</h6>
                    <div class="h7"></div>
                    <div class="h8">{$year}</div>
                </div>
            </div>
            <div>
                <div class="clubs">
                    <div class="club"><div class="title"><span>FK Nīca/OtankiMill</span></div></div>
                    <div class="club"><div class="title"><span>FC Nikers</span></div></div>
                </div>
                <div class="stadium">Nīcas sporta halle</div>
            </div>
            <div><a href="/speles/fk-nicaotankimill-fc-nikers-25157945/"> </a></div>
        </div>
HTML;

        $crawler = new Crawler($html);
        $row = $crawler->filter('.tr.match')->first();

        $scraper = new LffTelpuFutbolsScraper();
        $dto = $scraper->parseRow($row, 'https://lff.lv/sacensibas/telpu-futbols/virsliga/?tab=content_1_2');

        $this->assertNotNull($dto);
        $this->assertEquals('FK Nīca/OtankiMill vs FC Nikers', $dto->title);
        $this->assertEquals($futureDate->format('d.m.Y') . ' 00:00', $dto->startAt->format('d.m.Y H:i'));
        $this->assertEquals('Nīca', $dto->city);
        $this->assertEquals('Kurzeme', $dto->region);
        $this->assertEquals('Skolas iela 14, Nīca, Dienvidkurzemes novads', $dto->address);
        $this->assertEqualsWithDelta(56.34601, $dto->latitude, 0.0001);
        $this->assertEqualsWithDelta(21.07007, $dto->longitude, 0.0001);
    }

    public function test_parse_row_skips_past_events(): void
    {
        $pastDate = now()->subDays(5);
        $day = $pastDate->format('j');
        $year = $pastDate->format('Y');
        $monthNum = (int) $pastDate->format('n');
        $latvianMonths = [
            1 => 'jan', 2 => 'feb', 3 => 'mar', 4 => 'apr',
            5 => 'mai', 6 => 'jūn', 7 => 'jūl', 8 => 'aug',
            9 => 'sep', 10 => 'okt', 11 => 'nov', 12 => 'dec',
        ];
        $monthStr = $latvianMonths[$monthNum];

        $html = <<<HTML
        <div class="tr match" data-id="25157901">
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
                    <div class="club"><div class="title"><span>FC Talsi</span></div></div>
                    <div class="club"><div class="title"><span>AVClub</span></div></div>
                </div>
                <div class="stadium">Talsu sporta halle</div>
            </div>
        </div>
HTML;

        $crawler = new Crawler($html);
        $row = $crawler->filter('.tr.match')->first();

        $scraper = new LffTelpuFutbolsScraper();
        $dto = $scraper->parseRow($row, 'https://lff.lv/sacensibas/telpu-futbols/virsliga/?tab=content_1_2');

        $this->assertNull($dto);
    }

    public function test_resolve_location_venues_and_fallbacks(): void
    {
        $scraper = new LffTelpuFutbolsScraper();

        // Exact match Kuldīga
        $loc1 = $scraper->resolveLocation('Kuldīgas sporta halle', 'FC Nikers');
        $this->assertEquals('Kuldīgas sporta halle', $loc1['venue']);
        $this->assertEquals('Kuldīga', $loc1['city']);
        $this->assertEquals('Kurzeme', $loc1['region']);
        $this->assertEquals('Piltenes iela 25, Kuldīga', $loc1['address']);

        // Salaspils Sporta nams
        $loc2 = $scraper->resolveLocation('Salaspils Sporta nams', 'TFK Salaspils');
        $this->assertEquals('Salaspils Sporta nams', $loc2['venue']);
        $this->assertEquals('Salaspils', $loc2['city']);
        $this->assertEquals('Smilšu iela 1, Salaspils', $loc2['address']);

        // Aizkraukles sporta halle
        $loc3 = $scraper->resolveLocation('Aizkraukles sporta halle', 'Squad/Samgus Aizkraukle');
        $this->assertEquals('Aizkraukles novada Sporta centrs', $loc3['venue']);
        $this->assertEquals('Aizkraukle', $loc3['city']);
        $this->assertEquals('Zemgale', $loc3['region']);
        $this->assertEquals('Lāčplēša iela 21B, Aizkraukle', $loc3['address']);

        // Fallback by team
        $loc4 = $scraper->resolveLocation('Nezināma zāle', 'FK Nīca/OtankiMill');
        $this->assertEquals('Nīca', $loc4['city']);
        $this->assertEquals('Kurzeme', $loc4['region']);
    }

    public function test_ingest_futsal_events_into_database(): void
    {
        Category::updateOrCreate(['name' => 'Sports'], ['slug' => 'sports']);

        $source = Source::create([
            'name' => 'Optibet Telpu futbola Virslīga (LFF)',
            'slug' => 'lff-telpu-futbols-test',
            'url' => 'https://lff.lv/sacensibas/telpu-futbols/virsliga/?tab=content_1_2',
            'scraper_class' => LffTelpuFutbolsScraper::class,
            'is_active' => true,
        ]);

        $futureDate = now()->addDays(7);
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
        <div class="tr match" data-id="25157933">
            <div>
                <div class="date">
                    <h4>sestdiena</h4>
                    <h5>{$day}</h5>
                    <h6>{$monthStr}</h6>
                    <div class="h7">15:00</div>
                    <div class="h8">{$year}</div>
                </div>
            </div>
            <div>
                <div class="clubs">
                    <div class="club"><div class="title"><span>Squad/Samgus Aizkraukle</span></div></div>
                    <div class="club"><div class="title"><span>Futbola Parks Academy</span></div></div>
                </div>
                <div class="stadium">Aizkraukles sporta halle</div>
            </div>
            <div><a href="/speles/squadsamgus-aizkraukle-futbola-parks-academy-25157933/"> </a></div>
        </div>
HTML;

        $crawler = new Crawler($html);
        $row = $crawler->filter('.tr.match')->first();

        $scraper = new LffTelpuFutbolsScraper();
        $dto = $scraper->parseRow($row, $source->url);
        $this->assertNotNull($dto);

        $ingestionService = app(EventIngestionService::class);
        $status = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $status);
        $this->assertEquals(1, Event::where('source_id', $source->id)->count());

        $saved = Event::where('source_id', $source->id)->first();
        $this->assertEquals('Squad/Samgus Aizkraukle vs Futbola Parks Academy', $saved->title);
        $this->assertEquals('sports', $saved->entertainment_type);
        $this->assertEquals('Aizkraukle', $saved->location->city);
        $this->assertEquals('Zemgale', $saved->location->region);
        $this->assertEquals(LffTelpuFutbolsScraper::DEFAULT_IMAGE, $saved->image_url);
        $this->assertNull($saved->ticket_url);
        $this->assertEquals('https://lff.lv/speles/squadsamgus-aizkraukle-futbola-parks-academy-25157933/', $saved->source_url);
    }
}
