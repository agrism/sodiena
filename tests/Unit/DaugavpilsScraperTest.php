<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\DaugavpilsScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class DaugavpilsScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_daugavpils_scraper_metadata(): void
    {
        $scraper = new DaugavpilsScraper();
        $this->assertEquals('daugavpils-dome', $scraper->getSlug());
        $this->assertStringContainsString('Daugavpils', $scraper->getName());
    }

    public function test_daugavpils_scraper_single_date_extraction(): void
    {
        $scraper = new DaugavpilsScraper();

        $html = <<<HTML
        <div class="col-xs-6">
            <div class="event-date pink">
                <span class="date">16</span>
                <span class="month">Sep</span>
                <span class="time">16:00</span>
            </div>
            <div class="event-text">
                <h3>Koklēšanas nodarbība iesācējiem</h3>
                <p class="place">Daugavpils Vienības nama Tradīciju māja, Rīgas iela 22a</p>
            </div>
        </div>
        HTML;

        $node = new Crawler($html);
        [$start, $end] = $scraper->extractDates($node);

        $this->assertNotNull($start);
        $this->assertEquals('16', $start->format('d'));
        $this->assertEquals('09', $start->format('m'));
        $this->assertEquals('16:00', $start->format('H:i'));
        $this->assertNull($end);
    }

    public function test_daugavpils_scraper_multi_date_extraction(): void
    {
        $scraper = new DaugavpilsScraper();

        $html = <<<HTML
        <div class="col-xs-6">
            <div class="event-date grey">
                <span class="date1">19 Feb</span>
                <span class="time1">08:00</span>
                <span class="date2">31 Dec</span>
                <span class="time2">16:00</span>
            </div>
            <div class="event-text">
                <h3>Vesela un aktīva Daugavpils</h3>
                <p class="place">Daugavpils</p>
            </div>
        </div>
        HTML;

        $node = new Crawler($html);
        [$start, $end] = $scraper->extractDates($node);

        $this->assertNotNull($start);
        $this->assertNotNull($end);
        $this->assertEquals('19', $start->format('d'));
        $this->assertEquals('02', $start->format('m'));
        $this->assertEquals('08:00', $start->format('H:i'));
        $this->assertEquals('31', $end->format('d'));
        $this->assertEquals('12', $end->format('m'));
        $this->assertEquals('16:00', $end->format('H:i'));
    }

    public function test_daugavpils_scraper_location_resolving(): void
    {
        $scraper = new DaugavpilsScraper();

        $loc1 = $scraper->resolveLocation('Poļu nams, Varšavas iela 30, Daugavpils');
        $this->assertEquals('Poļu nams', $loc1['name']);
        $this->assertStringContainsString('Varšavas iela 30', $loc1['address']);

        $loc2 = $scraper->resolveLocation('Daugavpils Vienības nama Tradīciju māja, Rīgas iela 22a');
        $this->assertEquals('Daugavpils Vienības nama Tradīciju māja', $loc2['name']);
        $this->assertStringContainsString('Rīgas iela 22a', $loc2['address']);
        $this->assertStringContainsString('Daugavpils', $loc2['address']);
    }

    public function test_daugavpils_event_ingestion(): void
    {
        $source = Source::create([
            'name' => 'Daugavpils valstspilsēta (Afiša)',
            'slug' => 'daugavpils-dome',
            'url' => 'https://www.daugavpils.lv/afisa/',
            'scraper_class' => DaugavpilsScraper::class,
            'is_active' => true,
        ]);

        $dto = new ScrapedEventDTO(
            title: 'Koklēšanas nodarbība iesācējiem Daugavpilī',
            startAt: Carbon::parse('2026-09-16 16:00:00'),
            endAt: null,
            description: 'Aicinām apgūt koklēšanas prasmes Vienības namā.',
            shortDescription: 'Koklēšanas nodarbība.',
            venueName: 'Daugavpils Vienības nama Tradīciju māja',
            city: 'Daugavpils',
            region: 'Latgale',
            address: 'Rīgas iela 22a, Daugavpils',
            placeType: 'venue',
            categoryNames: ['Mūzika & Koncerti', 'Kultūra & Tradīcijas'],
            entertainmentType: 'workshop',
            isFree: true,
            imageUrl: 'https://www.daugavpils.lv/assets/upload/events/kokles.jpg',
            sourceUrl: 'https://www.daugavpils.lv/afisa/koklesana-iesacejiem-1609',
            sourceExternalId: 'daugavpils-koklesana-iesacejiem-1609',
            locale: 'lv',
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_external_id', 'daugavpils-koklesana-iesacejiem-1609')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Koklēšanas nodarbība iesācējiem Daugavpilī', $event->title);
        $this->assertEquals('daugavpils.lv', $event->origin_host);
        $this->assertEquals('https://www.daugavpils.lv/afisa/koklesana-iesacejiem-1609', $event->source_url);
        $this->assertEquals('Daugavpils', $event->location->city);
        $this->assertEquals('Latgale', $event->location->region);
    }
}
