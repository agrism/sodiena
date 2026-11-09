<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\LiveRigaScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class LiveRigaScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_liveriga_scraper_metadata(): void
    {
        $scraper = new LiveRigaScraper();
        $this->assertEquals('live-riga', $scraper->getSlug());
        $this->assertEquals('Live Riga', $scraper->getName());
    }

    public function test_extract_total_pages(): void
    {
        $scraper = new LiveRigaScraper();

        $htmlWithPagesText = <<<HTML
        <div class="pagination-box">
            <div class="pagination">
                <span class="pages">1 no 13</span>
            </div>
        </div>
HTML;
        $crawler1 = new Crawler($htmlWithPagesText);
        $this->assertEquals(13, $scraper->extractTotalPages($crawler1));

        $htmlWithLiLinks = <<<HTML
        <div class="pagination-box">
            <div class="pagination">
                <ul>
                    <li><a href="?page=1">1</a></li>
                    <li><a href="?page=2">2</a></li>
                    <li><a href="?page=5">5</a></li>
                </ul>
            </div>
        </div>
HTML;
        $crawler2 = new Crawler($htmlWithLiLinks);
        $this->assertEquals(5, $scraper->extractTotalPages($crawler2));
    }

    public function test_parse_date_single_day_and_range(): void
    {
        $scraper = new LiveRigaScraper();
        $today = Carbon::create(2026, 11, 9, 0, 0, 0, 'Europe/Riga');

        // Single day upcoming in current year
        [$s1, $e1] = $scraper->parseDate('11 Nov', $today);
        $this->assertEquals('2026-11-11', $s1->format('Y-m-d'));
        $this->assertEquals('2026-11-11', $e1->format('Y-m-d'));

        // Date range in current month
        [$s2, $e2] = $scraper->parseDate('14 Nov - 15 Nov', $today);
        $this->assertEquals('2026-11-14', $s2->format('Y-m-d'));
        $this->assertEquals('2026-11-15', $e2->format('Y-m-d'));

        // Date range crossing New Year
        [$s3, $e3] = $scraper->parseDate('27 Nov - 03 Jan', $today);
        $this->assertEquals('2026-11-27', $s3->format('Y-m-d'));
        $this->assertEquals('2027-01-03', $e3->format('Y-m-d'));

        // Ongoing exhibition started earlier this year
        [$s4, $e4] = $scraper->parseDate('24 Apr - 15 Nov', $today);
        $this->assertEquals('2026-04-24', $s4->format('Y-m-d'));
        $this->assertEquals('2026-11-15', $e4->format('Y-m-d'));

        // Single day in next year (Jan < Nov)
        [$s5, $e5] = $scraper->parseDate('02 Jan', $today);
        $this->assertEquals('2027-01-02', $s5->format('Y-m-d'));
        $this->assertEquals('2027-01-02', $e5->format('Y-m-d'));

        // Single day next summer (Aug < Nov)
        [$s6, $e6] = $scraper->parseDate('14 Aug', $today);
        $this->assertEquals('2027-08-14', $s6->format('Y-m-d'));
        $this->assertEquals('2027-08-14', $e6->format('Y-m-d'));
    }

    public function test_parse_card_valid_event(): void
    {
        $html = <<<HTML
        <div class="col mb-3 mb-md-5">
            <a class="card card-item" href="/lv/apmekle/pasakumi/koncerti-klasika/pusdienlaika-koncerts-anglikanu-baznica">
                <figure>
                    <img src="/userfiles/images/apmekle/pasakumi/koncerti-klasika/2026/06/koncerts.jpg?w=300&mode=3:2|crop"
                         alt="Pusdienlaika koncerts Anglikāņu baznīcā">
                </figure>
                <div class="card-body">
                    <time class="d-none d-md-block">15 Nov</time>
                    <div class="card-pre-title">Koncerti (klasika)</div>
                    <h3 class="card-title">Pusdienlaika koncerts Anglikāņu baznīcā</h3>
                    <time class="d-block d-md-none">15 Nov</time>
                </div>
            </a>
        </div>
HTML;

        $crawler = new Crawler($html);
        $scraper = new LiveRigaScraper();
        $today = Carbon::create(2026, 11, 9, 0, 0, 0, 'Europe/Riga');
        $dto = $scraper->parseCard($crawler->filter('a.card.card-item')->first(), $today);

        $this->assertNotNull($dto);
        $this->assertEquals('Pusdienlaika koncerts Anglikāņu baznīcā', $dto->title);
        $this->assertEquals('https://www.liveriga.com/lv/apmekle/pasakumi/koncerti-klasika/pusdienlaika-koncerts-anglikanu-baznica', $dto->sourceUrl);
        $this->assertEquals('liveriga-pusdienlaika-koncerts-anglikanu-baznica-2026-11-15', $dto->sourceExternalId);
        $this->assertEquals('2026-11-15', $dto->startAt->format('Y-m-d'));
        $this->assertEquals('https://www.liveriga.com/userfiles/images/apmekle/pasakumi/koncerti-klasika/2026/06/koncerts.jpg', $dto->imageUrl);
        $this->assertEquals('Anglikāņu baznīca', $dto->venueName);
        $this->assertEquals('Rīga', $dto->city);
        $this->assertEquals(56.9503, $dto->latitude);
        $this->assertEquals(24.1039, $dto->longitude);
        $this->assertContains('muzika', $dto->categoryNames);
    }

    public function test_parse_card_skips_past_events(): void
    {
        $html = <<<HTML
        <div class="col">
            <a class="card card-item" href="/lv/apmekle/pasakumi/teatris/vecs-pasakums">
                <div class="card-body">
                    <time class="d-none d-md-block">01 Nov - 05 Nov</time>
                    <div class="card-pre-title">Teātris</div>
                    <h3 class="card-title">Vecs pasākums</h3>
                </div>
            </a>
        </div>
HTML;

        $crawler = new Crawler($html);
        $scraper = new LiveRigaScraper();
        // Today is Nov 9, 2026; event was Nov 1 - Nov 5, 2026
        $today = Carbon::create(2026, 11, 9, 0, 0, 0, 'Europe/Riga');
        $dto = $scraper->parseCard($crawler->filter('a.card.card-item')->first(), $today);

        $this->assertNull($dto);
    }

    public function test_strict_cinema_rules_adherence(): void
    {
        $scraper = new LiveRigaScraper();

        // 1. Film title in Kino category must be strictly 'kino' and never 'teatris'
        $cats1 = $scraper->resolveCategories('Dokumentālā filma "Ceļš nekad nebeidzas"', 'Kino');
        $this->assertEquals(['kino'], $cats1);
        $this->assertNotContains('teatris', $cats1);

        // 2. Film screening at K. Suns must be strictly 'kino'
        $cats2 = $scraper->resolveCategories('Animācijas filmas seanss', 'Cits', 'Kinoteātris K.Suns');
        $this->assertEquals(['kino'], $cats2);
        $this->assertNotContains('teatris', $cats2);

        // 3. Theatre play must be 'teatris'
        $cats3 = $scraper->resolveCategories('Izrāde "Frīlanceri"', 'Teātris');
        $this->assertContains('teatris', $cats3);
        $this->assertNotContains('kino', $cats3);

        // 4. Opera / ballet production must be 'teatris' (and 'muzika')
        $cats4 = $scraper->resolveCategories('Opera "Zemnieka gods. Pajaci"', 'Opera, balets');
        $this->assertContains('teatris', $cats4);
        $this->assertContains('muzika', $cats4);
        $this->assertNotContains('kino', $cats4);
    }

    public function test_resolve_venue_known_and_category_fallbacks(): void
    {
        $scraper = new LiveRigaScraper();

        // Specific venue in title: Anglikāņu baznīca
        $v1 = $scraper->resolveVenue('Pusdienlaika koncerts Anglikāņu baznīcā', 'Koncerti (klasika)', '');
        $this->assertEquals('Anglikāņu baznīca', $v1['name']);
        $this->assertEquals('Anglikāņu iela 2A, Rīga', $v1['address']);
        $this->assertEquals(56.9503, $v1['latitude']);
        $this->assertEquals(24.1039, $v1['longitude']);

        // Opera category -> LNOB
        $v2 = $scraper->resolveVenue('Opera "Toska"', 'Opera, balets', '');
        $this->assertEquals('Latvijas Nacionālā opera un balets', $v2['name']);
        $this->assertEquals('Aspazijas bulvāris 3, Rīga', $v2['address']);
        $this->assertEquals(56.9496, $v2['latitude']);
        $this->assertEquals(24.1147, $v2['longitude']);

        // Starptautiskie gadatirgi -> Ķīpsala
        $v3 = $scraper->resolveVenue('Starptautiskā mājdzīvnieku izstāde', 'Starptautiskie gadatirgi', '');
        $this->assertEquals('Starptautiskais izstāžu centrs Ķīpsala', $v3['name']);
        $this->assertEquals('Ķīpsalas iela 8, Rīga', $v3['address']);

        // Ziemassvētku tirdziņš -> Doma laukums
        $v4 = $scraper->resolveVenue('Rīgas Ziemassvētku tirdziņš', 'Tirdziņi', '');
        $this->assertEquals('Doma laukums', $v4['name']);

        // Kalnciema kvartāls in title
        $v5 = $scraper->resolveVenue('Kalnciema kvartāla tirgus', 'Tirdziņi', '');
        $this->assertEquals('Kalnciema kvartāls', $v5['name']);
        $this->assertEquals('Kalnciema iela 35, Rīga', $v5['address']);

        // Generic fallback -> Rīga center
        $v6 = $scraper->resolveVenue('Koncerts "Dievs, Tava zeme deg!"', 'Koncerti (klasika)', '');
        $this->assertEquals('Rīga', $v6['name']);
        $this->assertEquals('Rīga, Latvija', $v6['address']);
        $this->assertEquals('Rīga', $v6['city']);
    }

    public function test_ingest_liveriga_event_into_database(): void
    {
        $source = Source::create([
            'name' => 'Live Riga & Pasākumi',
            'slug' => 'live-riga',
            'url' => 'https://www.liveriga.com/lv/apmekle/pasakumi',
            'scraper_class' => LiveRigaScraper::class,
            'is_active' => true,
        ]);

        Category::create(['name' => 'Mūzika', 'slug' => 'muzika']);
        Category::create(['name' => 'Teātris', 'slug' => 'teatris']);
        Category::create(['name' => 'Kino', 'slug' => 'kino']);
        Category::create(['name' => 'Cits', 'slug' => 'citi']);

        $scraper = new LiveRigaScraper();
        $cardHtml = <<<HTML
        <a class="card card-item" href="/lv/apmekle/pasakumi/opera-balets/opera-zemnieka-gods-pajaci">
            <figure>
                <img src="/userfiles/images/zemnieka-gods.jpg?w=300&mode=3:2|crop" alt="Opera &quot;Zemnieka gods. Pajaci&quot;">
            </figure>
            <div class="card-body">
                <time class="d-none d-md-block">25 Nov</time>
                <div class="card-pre-title">Opera, balets</div>
                <h3 class="card-title">Opera "Zemnieka gods. Pajaci"</h3>
            </div>
        </a>
HTML;

        $crawler = new Crawler($cardHtml);
        $today = Carbon::create(2026, 11, 9, 0, 0, 0, 'Europe/Riga');
        $dto = $scraper->parseCard($crawler->filter('a.card.card-item')->first(), $today);

        $this->assertNotNull($dto);

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_id', $source->id)
            ->where('source_external_id', 'liveriga-opera-zemnieka-gods-pajaci-2026-11-25')
            ->first();

        $this->assertNotNull($event);
        $this->assertEquals('Opera "Zemnieka gods. Pajaci"', $event->title);
        $this->assertEquals('https://www.liveriga.com/lv/apmekle/pasakumi/opera-balets/opera-zemnieka-gods-pajaci', $event->source_url);
        $this->assertStringContainsString('2026-11-25', (string)$event->start_at);
        $this->assertEquals('Latvijas Nacionālā opera un balets', $event->location?->name);
        $this->assertEquals('Rīga', $event->location?->city);
        $this->assertEquals(56.9496, $event->location?->latitude);
        $this->assertEquals(24.1147, $event->location?->longitude);
        $this->assertTrue($event->categories->contains('slug', 'teatris'));
    }
}
