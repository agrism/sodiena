<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\AulaLvScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class AulaLvScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_aula_scraper_metadata(): void
    {
        $scraper = new AulaLvScraper();
        $this->assertEquals('aula', $scraper->getSlug());
        $this->assertEquals('Aula (aula.lv)', $scraper->getName());
        $this->assertEquals(AulaLvScraper::BASE_URL, 'https://www.aula.lv');
        $this->assertEquals(AulaLvScraper::DEFAULT_URL, 'https://www.aula.lv/visi');
        $this->assertEquals(AulaLvScraper::DEFAULT_IMAGE, 'https://www.aula.lv/assets/images/aula-graph-placeholder-white.png');
    }

    public function test_fetch_detail_description(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '<div class="custom-event-content-section"><div class="toggleable-content"><p>Pirmais rindkopa.</p><p>Otrais rindkopa.</p></div></div>'),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $scraper = new AulaLvScraper();
        $scraper->setClient($client);

        $desc = $scraper->fetchDetailDescription('https://www.aula.lv/pasakumi/test');
        $this->assertEquals("Pirmais rindkopa.\n\nOtrais rindkopa.", $desc);

        // Cached call
        $cachedDesc = $scraper->fetchDetailDescription('https://www.aula.lv/pasakumi/test');
        $this->assertEquals("Pirmais rindkopa.\n\nOtrais rindkopa.", $cachedDesc);
    }

    public function test_parse_card_valid_event(): void
    {
        $futureDatetime = now()->addDays(5)->format('Y-m-d 19:00:00');
        $html = <<<HTML
        <article class="event-block">
            <div class="event-inner-block">
                <a href="/pasakumi/dinaras-rudanes-koncerts-dobeles-pili?event=2504" class="thumb">
                    <img class="image" src="/uploads/event-group/1479/event/nakts-flyer-1.jpg" alt="">
                </a>
                <div class="event-text-content clearfix">
                    <a class="title" href="/pasakumi/dinaras-rudanes-koncerts-dobeles-pili?event=2504">
                        <h2 class="base">Dināras Rudānes koncerts Dobeles Pilī</h2>
                    </a>
                    <div class="date-place c-gray-dark small">
                        <time class="date" datetime="{$futureDatetime}">11. decembris</time>
                        <span class="place">Dobeles pils</span>
                    </div>
                    <div class="price c-gray-dark small">&euro; 30.00</div>
                </div>
            </div>
        </article>
HTML;

        $crawler = new Crawler($html);
        $scraper = new AulaLvScraper();
        $dto = $scraper->parseCard($crawler->filter('article.event-block')->first(), false);

        $this->assertNotNull($dto);
        $this->assertEquals('Dināras Rudānes koncerts Dobeles Pilī', $dto->title);
        $this->assertEquals('https://www.aula.lv/pasakumi/dinaras-rudanes-koncerts-dobeles-pili?event=2504', $dto->sourceUrl);
        $this->assertEquals('https://www.aula.lv/pasakumi/dinaras-rudanes-koncerts-dobeles-pili?event=2504', $dto->ticketUrl);
        $this->assertEquals('aula-2504', $dto->sourceExternalId);
        $this->assertEquals('Dobeles Pils', $dto->venueName);
        $this->assertEquals('Dobele', $dto->city);
        $this->assertEquals('Zemgale', $dto->region);
        $this->assertEquals('Tērvetes iela, Dobele, LV-3701', $dto->address);
        $this->assertEqualsWithDelta(56.62310, $dto->latitude, 0.0001);
        $this->assertEqualsWithDelta(23.27530, $dto->longitude, 0.0001);
        $this->assertEquals(30.0, $dto->priceMin);
        $this->assertEquals(30.0, $dto->priceMax);
        $this->assertContains('Mūzika', $dto->categoryNames);
        $this->assertEquals('concert', $dto->entertainmentType);
        $this->assertEquals('https://www.aula.lv/uploads/event-group/1479/event/nakts-flyer-1.jpg', $dto->imageUrl);
    }

    public function test_parse_card_skips_undated_vouchers(): void
    {
        $html = <<<HTML
        <article class="event-block">
            <div class="event-inner-block">
                <a href="/pasakumi/davanu-kartes?event=2236" class="thumb">
                    <img class="image" src="/uploads/event-group/1323/event/davanu-karte-mini-v2.png" alt="">
                </a>
                <div class="event-text-content clearfix">
                    <a class="title" href="/pasakumi/davanu-kartes?event=2236">
                        <h2 class="base">AULA dāvanu kartes</h2>
                    </a>
                    <div class="date-place c-gray-dark small">
                        <div class="date">Dažādi norises laiki</div>
                        <span class="place"></span>
                    </div>
                    <div class="price c-gray-dark small">&euro; 10.00–30.00</div>
                </div>
            </div>
        </article>
HTML;

        $crawler = new Crawler($html);
        $scraper = new AulaLvScraper();
        $this->assertNull($scraper->parseCard($crawler->filter('article.event-block')->first()));
    }

    public function test_parse_card_skips_past_events(): void
    {
        $pastDatetime = now()->subDays(5)->format('Y-m-d 19:00:00');
        $html = <<<HTML
        <article class="event-block">
            <div class="event-inner-block">
                <a class="title" href="/pasakumi/old-event?event=100">
                    <h2 class="base">Pagājis pasākums</h2>
                </a>
                <div class="date-place c-gray-dark small">
                    <time class="date" datetime="{$pastDatetime}">Pagātnē</time>
                    <span class="place">Rīga</span>
                </div>
            </div>
        </article>
HTML;

        $crawler = new Crawler($html);
        $scraper = new AulaLvScraper();
        $this->assertNull($scraper->parseCard($crawler->filter('article.event-block')->first()));
    }

    public function test_parse_prices(): void
    {
        $scraper = new AulaLvScraper();

        [$min1, $max1] = $scraper->parsePrices('€ 4.00–11.00');
        $this->assertEquals(4.0, $min1);
        $this->assertEquals(11.0, $max1);

        [$min2, $max2] = $scraper->parsePrices('€ 15.00');
        $this->assertEquals(15.0, $min2);
        $this->assertEquals(15.0, $max2);

        [$min3, $max3] = $scraper->parsePrices('€ 50.00–1,200.00');
        $this->assertEquals(50.0, $min3);
        $this->assertEquals(1200.0, $max3);

        [$min4, $max4] = $scraper->parsePrices('Izpārdots');
        $this->assertNull($min4);
        $this->assertNull($max4);
    }

    public function test_resolve_venue(): void
    {
        $scraper = new AulaLvScraper();

        // 1. Pauze
        $loc1 = $scraper->resolveVenue('Pasākumu un kultūras telpa "Pauze" (ex. OratoriO)');
        $this->assertEquals('Pasākumu un kultūras telpa "Pauze"', $loc1['venue']);
        $this->assertEquals('Rīga', $loc1['city']);
        $this->assertEquals('Sporta iela 2, Rīga, LV-1013', $loc1['address']);

        // 2. LU Lielā aula
        $loc2 = $scraper->resolveVenue('Latvijas Universitātes Lielā aula');
        $this->assertEquals('Latvijas Universitātes Lielā aula', $loc2['venue']);
        $this->assertEquals('Raiņa bulvāris 19, Rīga, LV-1586', $loc2['address']);

        // 3. Dobeles pils
        $loc3 = $scraper->resolveVenue('Dobeles pils');
        $this->assertEquals('Dobeles Pils', $loc3['venue']);
        $this->assertEquals('Dobele', $loc3['city']);
        $this->assertEquals('Zemgale', $loc3['region']);

        // 4. K. Suns
        $loc4 = $scraper->resolveVenue('K. Suns');
        $this->assertEquals('Kinoteātris "K.Suns"', $loc4['venue']);
        $this->assertEquals('cinema', $loc4['place_type']);

        // 5. Fallback with city name
        $loc5 = $scraper->resolveVenue('Dimension Ink | Cēsis');
        $this->assertEquals('Dimension Ink', $loc5['venue']);
        $this->assertEquals('Cēsis', $loc5['city']);
        $this->assertEquals('Vidzeme', $loc5['region']);
    }

    public function test_strict_category_detection_adherence(): void
    {
        $scraper = new AulaLvScraper();

        // 1. Strict cinema priority rule: venue K. Suns is ALWAYS Kino (AGENTS.md)
        [$cat1, $type1] = $scraper->detectCategory('Improvizācijas teātris TIKAI DIVAS', 'K. Suns');
        $this->assertEquals('Kino', $cat1);
        $this->assertEquals('cinema', $type1);

        // 2. Live concert referencing cinema music is Mūzika
        [$cat2, $type2] = $scraper->detectCategory('6. Lielais Koncerts: kino skaņās', 'Mežaparka Lielā estrāde');
        $this->assertEquals('Mūzika', $cat2);
        $this->assertEquals('concert', $type2);

        // 3. Theater production
        [$cat3, $type3] = $scraper->detectCategory('Improvizācijas teātris "DIVAS" un Emilija', 'Mūzikas nams “Daile”');
        $this->assertEquals('Teātris', $cat3);
        $this->assertEquals('theater', $type3);

        // 4. Choral concert
        [$cat4, $type4] = $scraper->detectCategory('Koru koncerts "Ar Latviju sirdī" LU Lielajā aulā', 'Latvijas Universitātes Lielā aula');
        $this->assertEquals('Mūzika', $cat4);
        $this->assertEquals('concert', $type4);

        // 5. Scientific conference
        [$cat5, $type5] = $scraper->detectCategory('34. zinātniski tehniskā konference “Betons, kļūdas un risinājumi”', 'RTU Domus Auditorialis');
        $this->assertEquals('Izglītība', $cat5);
        $this->assertEquals('conference', $type5);

        // 6. Fallback to Cits
        [$cat6, $type6] = $scraper->detectCategory('"Mani garšu stāsti" | Garšu stāstu veltījums Latvijai!', 'Štakeldangas muiža');
        $this->assertEquals('Cits', $cat6);
        $this->assertEquals('other', $type6);
    }

    public function test_ingest_aula_event_into_database(): void
    {
        Category::updateOrCreate(['name' => 'Mūzika'], ['slug' => 'muzika']);

        $source = Source::create([
            'name' => 'Aula (aula.lv)',
            'slug' => 'aula-test',
            'url' => 'https://www.aula.lv/visi',
            'scraper_class' => AulaLvScraper::class,
            'is_active' => true,
        ]);

        $futureDatetime = now()->addDays(8)->format('Y-m-d 17:00:00');
        $html = <<<HTML
        <article class="event-block">
            <div class="event-inner-block">
                <a href="/pasakumi/koru-koncerts-ar-latviju-sirdi?event=2497" class="thumb">
                    <img class="image" src="/uploads/event-group/1475/event/koru-koncerts.jpeg" alt="">
                </a>
                <div class="event-text-content clearfix">
                    <a class="title" href="/pasakumi/koru-koncerts-ar-latviju-sirdi?event=2497">
                        <h2 class="base">Koru koncerts "Ar Latviju sirdī"</h2>
                    </a>
                    <div class="date-place c-gray-dark small">
                        <time class="date" datetime="{$futureDatetime}">18. novembris</time>
                        <span class="place">Latvijas Universitātes Lielā aula</span>
                    </div>
                    <div class="price c-gray-dark small">&euro; 4.90–15.00</div>
                </div>
            </div>
        </article>
HTML;

        $crawler = new Crawler($html);
        $scraper = new AulaLvScraper();
        $dto = $scraper->parseCard($crawler->filter('article.event-block')->first(), false);
        $this->assertNotNull($dto);

        $ingestionService = app(EventIngestionService::class);
        $status = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $status);
        $this->assertEquals(1, Event::where('source_id', $source->id)->count());

        $saved = Event::where('source_id', $source->id)->first();
        $this->assertEquals('Koru koncerts "Ar Latviju sirdī"', $saved->title);
        $this->assertEquals('concert', $saved->entertainment_type);
        $this->assertEquals('Latvijas Universitātes Lielā aula', $saved->location->name);
        $this->assertEquals('Rīga', $saved->location->city);
        $this->assertEquals(4.90, (float) $saved->price_min);
        $this->assertEquals(15.00, (float) $saved->price_max);
        $this->assertEquals('https://www.aula.lv/pasakumi/koru-koncerts-ar-latviju-sirdi?event=2497', $saved->ticket_url);

        // Verify ticket links recognize Aula platform and label
        $ticketLinks = $saved->ticket_links;
        $this->assertNotEmpty($ticketLinks);
        $this->assertEquals('Aula.lv', $ticketLinks[0]['title']);
        $this->assertEquals('Pirkt biļetes (Aula.lv)', $ticketLinks[0]['label']);
        $this->assertEquals('aula', $ticketLinks[0]['platform']);
        $this->assertEquals('https://www.aula.lv/pasakumi/koru-koncerts-ar-latviju-sirdi?event=2497', $ticketLinks[0]['url']);
    }
}

