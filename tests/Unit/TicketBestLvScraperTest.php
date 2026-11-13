<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\TicketBestLvScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class TicketBestLvScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticketbest_scraper_metadata(): void
    {
        $scraper = new TicketBestLvScraper();
        $this->assertEquals('ticketbest', $scraper->getSlug());
        $this->assertEquals('TicketBest', $scraper->getName());
        $this->assertEquals(TicketBestLvScraper::DEFAULT_IMAGE, 'https://ticketbest.lv/assets/logo-8dcde2e695af5ebd20fb44d14867e24891738bcaea1fe20996407175067c171b.png');
    }

    public function test_parse_card_valid_latvian_event(): void
    {
        $futureDate = now()->addDays(5)->format('Y.m.d+19:00');
        $html = <<<HTML
        <div class="card">
            <div class="card_tags"></div>
            <a href="/performances/bridgerton-klaviermuzikas-vakars?event_id=1065">
                <img class="card_image" src="/uploads/poster/bridgerton.jpg" />
            </a>
            <div class="card_info">
                <p class="card_date">
                    <span>piektdiena • 20 novembris 2026</span>
                    <span>19:00</span>
                </p>
                <a class="card_title" href="/performances/bridgerton-klaviermuzikas-vakars?event_id=1065">
                    BRIDGERTON. Klaviermūzikas vakars ar stīgu kvintetu | Rīga
                </a>
                <p class="card_adress">Latvia, Rīga, Rīgas Latviešu biedrības nams</p>
                <button class="card_button" data-unifd-locale="lv" data-unifd-showtime="{$futureDate}">Pirkt biļetes</button>
            </div>
        </div>
HTML;

        $crawler = new Crawler($html);
        $card = $crawler->filter('.card')->first();

        $scraper = new TicketBestLvScraper();
        $dto = $scraper->parseCard($card);

        $this->assertNotNull($dto);
        $this->assertEquals('BRIDGERTON. Klaviermūzikas vakars ar stīgu kvintetu | Rīga', $dto->title);
        $this->assertEquals('Rīgas Latviešu biedrības nams', $dto->venueName);
        $this->assertEquals('Rīga', $dto->city);
        $this->assertEquals('Rīga un Pierīga', $dto->region);
        $this->assertEquals('Merķeļa iela 13, Rīga', $dto->address);
        $this->assertEqualsWithDelta(56.95023, $dto->latitude, 0.0001);
        $this->assertEqualsWithDelta(24.11733, $dto->longitude, 0.0001);
        $this->assertEquals('https://ticketbest.lv/performances/bridgerton-klaviermuzikas-vakars?event_id=1065', $dto->ticketUrl);
        $this->assertEquals('https://ticketbest.lv/performances/bridgerton-klaviermuzikas-vakars?event_id=1065', $dto->sourceUrl);
        $this->assertEquals('https://ticketbest.lv/uploads/poster/bridgerton.jpg', $dto->imageUrl);
        $this->assertEquals('tb-1065', $dto->sourceExternalId);
        $this->assertContains('Mūzika', $dto->categoryNames);
        $this->assertEquals('concert', $dto->entertainmentType);
    }

    public function test_parse_card_skips_foreign_countries(): void
    {
        $futureDate = now()->addDays(5)->format('Y.m.d+19:00');

        // Estonia
        $htmlEe = <<<HTML
        <div class="card">
            <a class="card_title" href="/performances/concert-tallinn?event_id=888">Concert Tallinn</a>
            <p class="card_adress">Estonia, Tallinn, Alexela Kontserdimaja</p>
            <button class="card_button" data-unifd-showtime="{$futureDate}">Osta pilet</button>
        </div>
HTML;
        $crawlerEe = new Crawler($htmlEe);
        $scraper = new TicketBestLvScraper();
        $this->assertNull($scraper->parseCard($crawlerEe->filter('.card')->first()));

        // Lithuania
        $htmlLt = <<<HTML
        <div class="card">
            <a class="card_title" href="/performances/concert-vilnius?event_id=777">Concert Vilnius</a>
            <p class="card_adress">Lithuania, Vilnius, Compensa koncertų salė</p>
            <button class="card_button" data-unifd-showtime="{$futureDate}">Pirkti bilietus</button>
        </div>
HTML;
        $crawlerLt = new Crawler($htmlLt);
        $this->assertNull($scraper->parseCard($crawlerLt->filter('.card')->first()));
    }

    public function test_parse_card_skips_past_events(): void
    {
        $pastDate = now()->subDays(3)->format('Y.m.d+19:00');
        $html = <<<HTML
        <div class="card">
            <a class="card_title" href="/performances/old-event?event_id=111">Old Concert</a>
            <p class="card_adress">Latvia, Rīga, SPELET koncertzāle</p>
            <button class="card_button" data-unifd-showtime="{$pastDate}">Pirkt biļetes</button>
        </div>
HTML;
        $crawler = new Crawler($html);
        $scraper = new TicketBestLvScraper();
        $this->assertNull($scraper->parseCard($crawler->filter('.card')->first()));
    }

    public function test_parse_showtime_with_card_date_fallback(): void
    {
        $html = <<<HTML
        <div class="card">
            <p class="card_date">
                <span>sestdiena • 25 decembris 2026</span>
                <span>20:00</span>
            </p>
            <button class="card_button">Pirkt biļetes</button>
        </div>
HTML;
        $crawler = new Crawler($html);
        $card = $crawler->filter('.card')->first();

        $scraper = new TicketBestLvScraper();
        $parsed = $scraper->parseShowtime(null, $card);

        $this->assertNotNull($parsed);
        $this->assertEquals('2026-12-25 20:00', $parsed->format('Y-m-d H:i'));
    }

    public function test_resolve_address_known_venues_and_fallbacks(): void
    {
        $scraper = new TicketBestLvScraper();

        // Tallinas Kvartāla Angārs
        $loc1 = $scraper->resolveAddress('Latvia, Rīga, Tallinas Kvartāla Angārs');
        $this->assertEquals('Tallinas Kvartāla Angārs', $loc1['venue']);
        $this->assertEquals('Rīga', $loc1['city']);
        $this->assertEquals('Tallinas iela 10-3, Rīga', $loc1['address']);
        $this->assertEqualsWithDelta(56.96429, $loc1['lat'], 0.0001);

        // Lielais Dzintars
        $loc2 = $scraper->resolveAddress('Latvia, Liepāja, Liepājas koncertzāle LIELAIS DZINTARS');
        $this->assertEquals('Koncertzāle "Lielais dzintars"', $loc2['venue']);
        $this->assertEquals('Liepāja', $loc2['city']);
        $this->assertEquals('Radio iela 8, Liepāja', $loc2['address']);

        // Jūras vārti
        $loc3 = $scraper->resolveAddress('Latvia, Ventspils, Teātra namā "Jūras vārti"');
        $this->assertEquals('Teātra nams "Jūras vārti"', $loc3['venue']);
        $this->assertEquals('Ventspils', $loc3['city']);
        $this->assertEquals('Karlīnes iela 40, Ventspils', $loc3['address']);

        // Fallback city
        $loc4 = $scraper->resolveAddress('Latvia, Cēsis, Jauna estrāde');
        $this->assertEquals('Jauna estrāde', $loc4['venue']);
        $this->assertEquals('Cēsis', $loc4['city']);
        $this->assertEquals('Vidzeme', $loc4['region']);
    }

    public function test_strict_cinema_and_theater_rules_adherence(): void
    {
        $scraper = new TicketBestLvScraper();

        // 1. Strict cinema screening
        [$cat1, $type1] = $scraper->detectCategory('Animācijas filma “Straume”', 'Ginta Zilbaloža pilnmetrāžas filma', 'Kinoteātris');
        $this->assertEquals('Kino', $cat1);
        $this->assertEquals('cinema', $type1);

        // 2. Strict cinema venue
        [$cat2, $type2] = $scraper->detectCategory('Īpašs pasākums', 'Tikšanās ar režisoru', 'K. Suns');
        $this->assertEquals('Kino', $cat2);
        $this->assertEquals('cinema', $type2);

        // 3. Concert referring to film music or Netflix in prose is Mūzika
        [$cat3, $type3] = $scraper->detectCategory(
            'LUDOVICO EINAUDI MŪZIKA. Klavieres un stīgu kvartets',
            'Komponists sarakstījis mūziku daudzām pazīstamām filmām un klavierkoncertiem.',
            'Lielais dzintars'
        );
        $this->assertEquals('Mūzika', $cat3);
        $this->assertEquals('concert', $type3);

        // 4. Theater production
        [$cat4, $type4] = $scraper->detectCategory('Izrāde “Mīlestības vēsmas”', 'Lieliska komēdijas luga divos cēlienos', 'Teātra nams');
        $this->assertEquals('Teātris', $cat4);
        $this->assertEquals('theater', $type4);
    }

    public function test_ingest_ticketbest_event_into_database(): void
    {
        Category::updateOrCreate(['name' => 'Mūzika'], ['slug' => 'muzika']);

        $source = Source::create([
            'name' => 'TicketBest (ticketbest.lv)',
            'slug' => 'ticketbest-test',
            'url' => 'https://ticketbest.lv/',
            'scraper_class' => TicketBestLvScraper::class,
            'is_active' => true,
        ]);

        $futureDate = now()->addDays(10)->format('Y.m.d+19:00');
        $html = <<<HTML
        <div class="card">
            <a href="/performances/deep-forest-tour?event_id=1225">
                <img class="card_image" src="/uploads/deepforest.png" />
            </a>
            <div class="card_info">
                <a class="card_title" href="/performances/deep-forest-tour?event_id=1225">
                    DEEP FOREST Live Machine Tour | Riga
                </a>
                <p class="card_adress">Latvia, Rīga, Tallinas Kvartāla Angārs</p>
                <button class="card_button" data-unifd-showtime="{$futureDate}">Pirkt biļetes</button>
            </div>
        </div>
HTML;

        $crawler = new Crawler($html);
        $card = $crawler->filter('.card')->first();

        $scraper = new TicketBestLvScraper();
        $dto = $scraper->parseCard($card);
        $this->assertNotNull($dto);

        $ingestionService = app(EventIngestionService::class);
        $status = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $status);
        $this->assertEquals(1, Event::where('source_id', $source->id)->count());

        $saved = Event::where('source_id', $source->id)->first();
        $this->assertEquals('DEEP FOREST Live Machine Tour | Riga', $saved->title);
        $this->assertEquals('concert', $saved->entertainment_type);
        $this->assertEquals('Rīga', $saved->location->city);
        $this->assertEquals('Tallinas iela 10-3, Rīga', $saved->location->address);
        $this->assertEquals('https://ticketbest.lv/performances/deep-forest-tour?event_id=1225', $saved->ticket_url);

        // Verify ticket links recognize TicketBest platform
        $ticketLinks = $saved->ticket_links;
        $this->assertNotEmpty($ticketLinks);
        $this->assertEquals('TicketBest', $ticketLinks[0]['title']);
        $this->assertEquals('Pirkt biļetes (TicketBest)', $ticketLinks[0]['label']);
    }
}
