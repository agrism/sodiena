<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\BezRindasScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class BezRindasScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_bezrindas_scraper_metadata(): void
    {
        $scraper = new BezRindasScraper();
        $this->assertEquals('bezrindas', $scraper->getSlug());
        $this->assertEquals('BezRindas.lv (Visi pasākumi)', $scraper->getName());
    }

    public function test_bezrindas_parse_event_page(): void
    {
        $scraper = new BezRindasScraper();

        $sampleHtml = <<<HTML
        <!DOCTYPE html>
        <html>
        <head><title>Stand-up izrāde Kandidāts</title></head>
        <body>
            <h1 class="title-text">Stand-up izrāde "Kandidāts"</h1>
            <div class="event-info-poster">
                <img src="https://cdn.bezrindas.lv/uploads/events/kandidats.jpg" />
            </div>
            <div class="description">
                Lieliska komēdijas izrāde par vēlēšanām un politiku.
            </div>
            <div class="box-group">
                <div class="unit box" data-eventfrom="20261015" data-eventto="20261015">
                    <div class="event-info-oneliner">
                        <span class="icon-calendar">15. oktobris, 19:00</span>
                    </div>
                    <div class="event-info-oneliner">
                        <span class="icon-location"><b><a href="/lv/vietas/riga-splendid-palace">Splendid Palace</a></b></span>
                    </div>
                    <div class="max_price">15.00 - 25.00 €</div>
                    <a id="event-details-link" href="https://www.bezrindas.lv/lv/stand-up-kandidats/16283/45210/">Pirkt biļeti</a>
                </div>
                <div class="unit box" data-eventfrom="20261020" data-eventto="20261020">
                    <div class="event-info-oneliner">
                        <span class="icon-calendar">20. oktobris, 18:30</span>
                    </div>
                    <div class="event-info-oneliner">
                        <span class="icon-location"><b><a href="/lv/vietas/liepaja-lielais-dzintars">Lielais Dzintars</a></b></span>
                    </div>
                    <div class="max_price">20.00 €</div>
                    <a id="event-details-link" href="https://www.bezrindas.lv/lv/stand-up-kandidats/16283/45211/">Pirkt biļeti</a>
                </div>
            </div>
        </body>
        </html>
HTML;

        $pageCrawler = new Crawler($sampleHtml);
        $events = collect();
        $card = [
            'url' => 'https://www.bezrindas.lv/lv/stand-up-kandidats/16283/',
            'title' => 'Stand-up izrāde "Kandidāts"',
            'place' => 'Splendid Palace',
            'dateText' => '15. oktobris',
            'img' => 'https://cdn.bezrindas.lv/uploads/events/kandidats.jpg',
        ];

        $scraper->parseEventPage($pageCrawler, 'https://www.bezrindas.lv/lv/stand-up-kandidats/16283/', $card, $events);

        $this->assertCount(2, $events);

        $first = $events->first();
        $this->assertEquals('Stand-up izrāde "Kandidāts"', $first->title);
        $this->assertEquals('Splendid Palace', $first->venueName);
        $this->assertEquals('Rīga', $first->city);
        $this->assertEquals('2026-10-15 19:00', $first->startAt->format('Y-m-d H:i'));
        $this->assertEquals('bezrindas-16283-45210', $first->sourceExternalId);
        $this->assertEquals('https://www.bezrindas.lv/lv/stand-up-kandidats/16283/45210/', $first->ticketUrl);
        $this->assertEquals(15.0, $first->priceMin);
        $this->assertEquals(25.0, $first->priceMax);
        $this->assertEquals(false, $first->isFree);
        $this->assertEquals('https://cdn.bezrindas.lv/uploads/events/kandidats.jpg', $first->imageUrl);

        $second = $events->get(1);
        $this->assertEquals('Lielais Dzintars', $second->venueName);
        $this->assertEquals('Liepāja', $second->city);
        $this->assertEquals('2026-10-20 18:30', $second->startAt->format('Y-m-d H:i'));
        $this->assertEquals('bezrindas-16283-45211', $second->sourceExternalId);
        $this->assertEquals(20.0, $second->priceMin);
        $this->assertEquals(20.0, $second->priceMax);
    }

    public function test_bezrindas_event_ingestion(): void
    {
        $source = Source::create([
            'name' => 'BezRindas.lv (Visi pasākumi)',
            'slug' => 'bezrindas',
            'url' => 'https://www.bezrindas.lv/lv/visi-pasakumi',
            'scraper_class' => BezRindasScraper::class,
            'is_active' => true,
        ]);

        $scraper = new BezRindasScraper();
        $sampleHtml = <<<HTML
        <!DOCTYPE html>
        <html>
        <body>
            <h1 class="title-text">Grupa Carnival Youth Koncerts Cēsīs</h1>
            <div class="description">Grupas albuma prezentācijas koncerttūre Cēsu koncertzālē.</div>
            <div class="box-group">
                <div class="unit box" data-eventfrom="20261112" data-eventto="20261112">
                    <div class="event-info-oneliner"><span class="icon-calendar">12. novembris, 20:00</span></div>
                    <div class="event-info-oneliner"><span class="icon-location"><b><a href="/lv/vietas/cesis-koncertzale">Koncertzāle Cēsis</a></b></span></div>
                    <div class="max_price">25.00 €</div>
                    <a id="event-details-link" href="https://www.bezrindas.lv/lv/carnival-youth-cesis/99887/112233/">Pirkt</a>
                </div>
            </div>
        </body>
        </html>
HTML;

        $pageCrawler = new Crawler($sampleHtml);
        $events = collect();
        $scraper->parseEventPage($pageCrawler, 'https://www.bezrindas.lv/lv/carnival-youth-cesis/99887/', [], $events);

        $this->assertCount(1, $events);
        $dto = $events->first();

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_external_id', 'bezrindas-99887-112233')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Grupa Carnival Youth Koncerts Cēsīs', $event->title);
        $this->assertEquals('bezrindas.lv', $event->origin_host);
        $this->assertEquals('Koncertzāle Cēsis', $event->location->name);
        $this->assertEquals('Cēsis', $event->location->city);
        $this->assertEquals('2026-11-12 20:00:00', $event->start_at->toDateTimeString());
    }
}
