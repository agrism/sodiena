<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\BilesuParadizeScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BilesuParadizeScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_bilesu_paradize_scraper_metadata(): void
    {
        $scraper = new BilesuParadizeScraper();
        $this->assertEquals('bilesu-paradize', $scraper->getSlug());
        $this->assertEquals('Biļešu Paradīze', $scraper->getName());
    }

    public function test_bilesu_paradize_parse_puppeteer_event(): void
    {
        $scraper = new BilesuParadizeScraper();
        $source = Source::create([
            'name' => 'Biļešu Paradīze',
            'slug' => 'bilesu-paradize',
            'url' => 'https://www.bilesuparadize.lv/lv',
            'scraper_class' => BilesuParadizeScraper::class,
            'is_active' => true,
        ]);

        $raw = [
            'title' => 'Simfoniskās mūzikas lielkoncerts Dzintaros',
            'description' => 'Izcils simfoniskā orķestra koncerts ar pasaulslaveniem solistiem.',
            'venue_name' => 'Dzintaru koncertzāle',
            'city' => 'Jūrmala',
            'date_text' => '2026-10-25 19:00:00',
            'price_text' => '25.00 - 65.00 €',
            'ticket_url' => 'https://www.bilesuparadize.lv/lv/event/12345',
            'image_url' => 'https://www.bilesuparadize.lv/images/concert.jpg',
            'source_url' => 'https://www.bilesuparadize.lv/lv/event/12345',
        ];

        $dto = $scraper->parsePuppeteerEvent($raw, $source);

        $this->assertNotNull($dto);
        $this->assertEquals('Simfoniskās mūzikas lielkoncerts Dzintaros', $dto->title);
        $this->assertEquals('Dzintaru koncertzāle', $dto->venueName);
        $this->assertEquals('Jūrmala', $dto->city);
        $this->assertEquals(25.0, $dto->priceMin);
        $this->assertEquals(65.0, $dto->priceMax);
        $this->assertFalse($dto->isFree);
        $this->assertContains('Mūzika', $dto->categoryNames);
    }

    public function test_bilesu_paradize_event_ingestion_via_service(): void
    {
        $source = Source::create([
            'name' => 'Biļešu Paradīze',
            'slug' => 'bilesu-paradize',
            'url' => 'https://www.bilesuparadize.lv/lv',
            'scraper_class' => BilesuParadizeScraper::class,
            'is_active' => true,
        ]);

        // Mock HTTP call to Browserless content endpoint
        Http::fake([
            '*/content*' => Http::response('
                <html>
                <body>
                    <div class="events-grid">
                        <div class="event-card">
                            <h3 class="event-title">Dailes Teātra Jauniestudējums "Ziedonis"</h3>
                            <div class="event-venue">Dailes teātris</div>
                            <div class="event-date">2026-11-15 19:00:00</div>
                            <div class="event-price">18.00 - 45.00 €</div>
                            <a href="https://www.bilesuparadize.lv/lv/event/98765">Biļetes</a>
                            <img src="https://www.bilesuparadize.lv/images/ziedonis.jpg" />
                        </div>
                    </div>
                </body>
                </html>
            ', 200),
        ]);

        $ingestionService = app(EventIngestionService::class);
        $log = $ingestionService->ingest($source);

        $this->assertEquals('success', $log->status);
        $this->assertEquals(1, $log->items_created);

        $this->assertDatabaseHas('events', [
            'title' => 'Dailes Teātra Jauniestudējums "Ziedonis"',
        ]);

        $event = Event::where('title', 'Dailes Teātra Jauniestudējums "Ziedonis"')->first();
        $this->assertNotNull($event);
        $this->assertEquals('draft', $event->status);
        $this->assertEquals('Dailes teātris', $event->location?->name);
        $this->assertTrue($event->categories->pluck('slug')->contains('teatris'));
    }

    public function test_bilesu_paradize_parse_nuxt_event_sessions(): void
    {
        $scraper = new BilesuParadizeScraper();
        $source = Source::create([
            'name' => 'Biļešu Paradīze',
            'slug' => 'bilesu-paradize-test',
            'url' => 'https://www.bilesuparadize.lv/lv',
            'scraper_class' => BilesuParadizeScraper::class,
            'is_active' => true,
        ]);

        $nuxtData = [
            "DZIMŠANAS DIENAS KŪKA — Biļešu Paradīze",
            [
                "date_time" => 2,
                "performance_id" => 3,
                "id" => 4,
                "hall_titles" => 5,
            ],
            "2026-10-21 19:00:00",
            100,
            175373,
            "Jaunais Rīgas teātris, Lielā zāle",
            [
                "date_time" => 7,
                "performance_id" => 3,
                "id" => 8,
                "hall_titles" => 5,
            ],
            "2026-10-22 19:00:00",
            175374,
        ];

        $html = '<!DOCTYPE html><html><head><title>DZIMŠANAS DIENAS KŪKA — Biļešu Paradīze</title></head><body><script id="__NUXT_DATA__" type="application/json">' . json_encode($nuxtData) . '</script></body></html>';

        $dtos = $scraper->parseNuxtEventSessions($html, 'https://www.bilesuparadize.lv/lv/event/171278', $source);

        $this->assertCount(2, $dtos);
        $this->assertEquals('DZIMŠANAS DIENAS KŪKA', $dtos[0]->title);
        $this->assertEquals('https://www.bilesuparadize.lv/lv/event/175373', $dtos[0]->ticketUrl);
        $this->assertEquals('Jaunais Rīgas teātris, Lielā zāle', $dtos[0]->venueName);
        $this->assertEquals('2026-10-21 19:00:00', $dtos[0]->startAt->format('Y-m-d H:i:s'));

        $this->assertEquals('https://www.bilesuparadize.lv/lv/event/175374', $dtos[1]->ticketUrl);
        $this->assertEquals('2026-10-22 19:00:00', $dtos[1]->startAt->format('Y-m-d H:i:s'));
    }
}
