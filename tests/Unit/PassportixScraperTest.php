<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\PassportixScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class PassportixScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_passportix_scraper_metadata(): void
    {
        $scraper = new PassportixScraper();
        $this->assertEquals('passportix', $scraper->getSlug());
        $this->assertEquals('Passportix', $scraper->getName());
    }

    public function test_parse_dates_single_event_with_time_range(): void
    {
        $scraper = new PassportixScraper();
        [$startAt, $endAt] = $scraper->parseDates('2026-10-31T15:00:00', 'Sestdiena, 31/10/26', '15:00 – 17:00');

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-10-31 15:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-10-31 17:00:00', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_date_range(): void
    {
        $scraper = new PassportixScraper();
        [$startAt, $endAt] = $scraper->parseDates('2026-03-15T15:00:00', 'Svētdiena, 15/03/26 - 08/11/26', '15:00 – 15:00');

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-03-15 15:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-08 15:00:00', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_single_event_without_end_time(): void
    {
        $scraper = new PassportixScraper();
        [$startAt, $endAt] = $scraper->parseDates('2026-11-15T16:00:00', 'Svētdiena, 15/11/26', null);

        $this->assertNotNull($startAt);
        $this->assertNull($endAt);
        $this->assertEquals('2026-11-15 16:00:00', $startAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_price(): void
    {
        $scraper = new PassportixScraper();

        [$free1, $min1, $max1] = $scraper->parsePrice('€ 2.50-15.00');
        $this->assertFalse($free1);
        $this->assertEquals(2.5, $min1);
        $this->assertEquals(15.0, $max1);

        [$free2, $min2, $max2] = $scraper->parsePrice('€ 5.00');
        $this->assertFalse($free2);
        $this->assertEquals(5.0, $min2);
        $this->assertEquals(5.0, $max2);

        [$free3, $min3, $max3] = $scraper->parsePrice('Ieeja bezmaksas');
        $this->assertTrue($free3);
        $this->assertEquals(0.0, $min3);
        $this->assertEquals(0.0, $max3);
    }

    public function test_location_metadata_detection(): void
    {
        $scraper = new PassportixScraper();

        $loc1 = $scraper->detectLocationMeta('Liepājas Olimpiskais centrs');
        $this->assertEquals('Liepāja', $loc1['city']);
        $this->assertEquals('Kurzeme', $loc1['region']);

        $loc2 = $scraper->detectLocationMeta('Sporta arēna "Ogre"');
        $this->assertEquals('Ogre', $loc2['city']);
        $this->assertEquals('Vidzeme', $loc2['region']);

        $loc3 = $scraper->detectLocationMeta('LNK Sporta Parks');
        $this->assertEquals('Rīga', $loc3['city']);
        $this->assertEquals('Rīga un Pierīga', $loc3['region']);

        $loc4 = $scraper->detectLocationMeta('Valmieras Olimpiskais centrs');
        $this->assertEquals('Valmiera', $loc4['city']);
        $this->assertEquals('Vidzeme', $loc4['region']);

        $loc5 = $scraper->detectLocationMeta('Jelgavas ledus halle');
        $this->assertEquals('Jelgava', $loc5['city']);
        $this->assertEquals('Zemgale', $loc5['region']);
    }

    public function test_extract_external_id(): void
    {
        $scraper = new PassportixScraper();

        $this->assertEquals(
            'passportix-929',
            $scraper->extractExternalId('https://www.passportix.eu/pasakumi/bk-liepaja-vs-bristol-flyers?event=929')
        );

        $this->assertEquals(
            'passportix-rfs-vs-grobina',
            $scraper->extractExternalId('https://www.passportix.eu/pasakumi/rfs-vs-grobina')
        );
    }

    public function test_parse_event_article_from_card(): void
    {
        $scraper = new PassportixScraper();

        $html = <<<HTML
        <article class="event-article">
            <a class="event-article--inner" href="/pasakumi/bk-liepaja-vs-bristol-flyers?event=929">
                <div class="event-article--image-block">
                    <picture class="event-article--image">
                        <img src="/uploads/event-group/864/event/liepajasbasketbols-774x826.png" alt="" loading="lazy">
                    </picture>
                </div>
                <div class="event-article--content">
                    <h3 class="event-article--title h5">BK Liepāja vs Bristol Flyers</h3>
                    <div class="event-article--price c-accent-400">€ 2.50-15.00</div>
                    <time class="event-article--date" datetime="2026-10-27T19:00:00">
                        <span>Ot, 27/10/26</span>
                    </time>
                    <div class="event-article--place">
                        <span>Liepājas Olimpiskais centrs</span>
                    </div>
                </div>
            </a>
        </article>
HTML;

        $crawler = new Crawler($html, 'https://www.passportix.eu/pasakumi');
        $node = $crawler->filter('article.event-article')->first();

        $dto = $scraper->parseEventArticle($node, 'https://www.passportix.eu/pasakumi');

        $this->assertNotNull($dto);
        $this->assertEquals('BK Liepāja vs Bristol Flyers', $dto->title);
        $this->assertEquals('passportix-929', $dto->sourceExternalId);
        $this->assertEquals('Liepājas Olimpiskais centrs', $dto->venueName);
        $this->assertEquals('Liepāja', $dto->city);
        $this->assertEquals('Kurzeme', $dto->region);
        $this->assertEquals(2.5, $dto->priceMin);
        $this->assertEquals(15.0, $dto->priceMax);
        $this->assertFalse($dto->isFree);
        $this->assertContains('Sports', $dto->categoryNames);
        $this->assertEquals('https://www.passportix.eu/pasakumi/bk-liepaja-vs-bristol-flyers?event=929', $dto->sourceUrl);
    }

    public function test_passportix_event_ingestion_into_database(): void
    {
        $source = Source::create([
            'name' => 'Passportix',
            'slug' => 'passportix',
            'url' => 'https://www.passportix.eu/pasakumi',
            'scraper_class' => PassportixScraper::class,
            'is_active' => true,
        ]);

        $scraper = new PassportixScraper();

        $html = <<<HTML
        <article class="event-article">
            <a class="event-article--inner" href="/pasakumi/lvbet-liga-leevon-ppk-valmiera-fc?event=940">
                <div class="event-article--image-block">
                    <picture class="event-article--image">
                        <img src="/uploads/event-group/875/event/pasakumu-saraksta-774x826.jpg" alt="" loading="lazy">
                    </picture>
                </div>
                <div class="event-article--content">
                    <h3 class="event-article--title h5">LVBET līga: Leevon PPK - Valmiera FC</h3>
                    <div class="event-article--price c-accent-400">€ 5.00</div>
                    <time class="event-article--date" datetime="2026-10-31T15:00:00">
                        <span>Se, 31/10/26</span>
                    </time>
                    <div class="event-article--place">
                        <span>LNK Sporta Parks</span>
                    </div>
                </div>
            </a>
        </article>
HTML;

        $crawler = new Crawler($html, 'https://www.passportix.eu/pasakumi');
        $node = $crawler->filter('article.event-article')->first();

        $dto = $scraper->parseEventArticle($node, 'https://www.passportix.eu/pasakumi');
        $this->assertNotNull($dto);

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_external_id', 'passportix-940')->first();
        $this->assertNotNull($event);
        $this->assertEquals('LVBET līga: Leevon PPK - Valmiera FC', $event->title);
        $this->assertEquals(5.0, (float)$event->price_min);
        $this->assertEquals(5.0, (float)$event->price_max);
        $this->assertEquals('passportix.eu', $event->origin_host);
        $this->assertNotNull($event->location);
        $this->assertEquals('Rīga', $event->location->city);
        $this->assertStringContainsString('LNK Sporta Parks', $event->location->name);

        // Verify categorized into Sports
        $catSlugs = $event->categories->pluck('slug')->toArray();
        $this->assertContains('sports', $catSlugs);
    }
}
