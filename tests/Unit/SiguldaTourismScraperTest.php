<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\SiguldaTourismScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class SiguldaTourismScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_sigulda_tourism_scraper_metadata(): void
    {
        $scraper = new SiguldaTourismScraper();
        $this->assertEquals('sigulda-tourism', $scraper->getSlug());
        $this->assertEquals('Siguldas novada tūrisms', $scraper->getName());
    }

    public function test_parse_dates_single_event_with_time(): void
    {
        $scraper = new SiguldaTourismScraper();
        $today = Carbon::create(2026, 10, 26, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('31. Oct', '19.00', $today);

        $this->assertNotNull($startAt);
        $this->assertNull($endAt);
        $this->assertEquals('2026-10-31 19:00:00', $startAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_single_event_with_time_range(): void
    {
        $scraper = new SiguldaTourismScraper();
        $today = Carbon::create(2026, 10, 26, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('26.Oct', '18.00 - 23.00', $today);

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-10-26 18:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-10-26 23:00:00', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_range_current_year(): void
    {
        $scraper = new SiguldaTourismScraper();
        $today = Carbon::create(2026, 10, 26, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates('25. Apr - 31. Dec', null, $today);

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-04-25 00:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-12-31 23:59:59', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_next_year_upcoming(): void
    {
        $scraper = new SiguldaTourismScraper();
        $today = Carbon::create(2026, 10, 26, 0, 0, 0, 'Europe/Riga');

        // Upcoming single date in January 2027
        [$startAt, $endAt] = $scraper->parseDates('23.Jan', '18.00', $today);

        $this->assertNotNull($startAt);
        $this->assertNull($endAt);
        $this->assertEquals('2027-01-23 18:00:00', $startAt->format('Y-m-d H:i:s'));

        // Upcoming date range in January 2027
        [$startAt2, $endAt2] = $scraper->parseDates('8. Jan - 10. Jan', '14.00 - 17.00', $today);

        $this->assertNotNull($startAt2);
        $this->assertNotNull($endAt2);
        $this->assertEquals('2027-01-08 14:00:00', $startAt2->format('Y-m-d H:i:s'));
        $this->assertEquals('2027-01-10 17:00:00', $endAt2->format('Y-m-d H:i:s'));
    }

    public function test_parse_price(): void
    {
        $scraper = new SiguldaTourismScraper();

        [$free1, $min1, $max1] = $scraper->parsePrice('Biļešu cena sākot no 20 EUR');
        $this->assertFalse($free1);
        $this->assertEquals(20.0, $min1);
        $this->assertEquals(20.0, $max1);

        [$free2, $min2, $max2] = $scraper->parsePrice('no 5 līdz 15 EUR');
        $this->assertFalse($free2);
        $this->assertEquals(5.0, $min2);
        $this->assertEquals(15.0, $max2);

        [$free3, $min3, $max3] = $scraper->parsePrice('Ieeja – bez maksas.');
        $this->assertTrue($free3);
        $this->assertEquals(0.0, $min3);
        $this->assertEquals(0.0, $max3);
    }

    public function test_resolve_location_meta(): void
    {
        $scraper = new SiguldaTourismScraper();

        $loc1 = $scraper->resolveLocationMeta('Pils iela 10, Sigulda');
        $this->assertEquals('Kultūras centrs "Siguldas DEVONS"', $loc1['name']);
        $this->assertEquals('Sigulda', $loc1['city']);
        $this->assertEquals('Vidzeme', $loc1['region']);

        $loc2 = $scraper->resolveLocationMeta('Šveices iela 19, Sigulda');
        $this->assertEquals('Koncertzāle "Baltais Flīģelis"', $loc2['name']);
        $this->assertEquals('Sigulda', $loc2['city']);

        $loc3 = $scraper->resolveLocationMeta('Mālpils Kultūras centrs, Nākotnes iela 5, Mālpils');
        $this->assertEquals('Mālpils Kultūras centrs', $loc3['name']);
        $this->assertEquals('Mālpils', $loc3['city']);

        $loc4 = $scraper->resolveLocationMeta('Atmodas iela 2, Inčukalns');
        $this->assertEquals('Inčukalns', $loc4['city']);

        $loc5 = $scraper->resolveLocationMeta('Strēlnieku iela 2, Sigulda');
        $this->assertEquals('Kino Lora', $loc5['name']);
        $this->assertEquals('cinema', $loc5['place_type']);
    }

    public function test_resolve_categories_strict_cinema_rules(): void
    {
        $scraper = new SiguldaTourismScraper();

        // 1. Kino Lora venue MUST be Kino and NEVER Teātris (AGENTS.md)
        $cats1 = $scraper->resolveCategories(['izrade'], 'Vakara seanss', 'Lieliska filma', 'Kino Lora');
        $this->assertContains('Kino', $cats1);
        $this->assertNotContains('Teātris', $cats1);

        // 2. Film keyword in title MUST be Kino and NEVER Teātris (AGENTS.md)
        $cats2 = $scraper->resolveCategories(['izrade'], 'Animācijas filma bērniem', 'Interesanta animācijas filma', 'Devons');
        $this->assertContains('Kino', $cats2);
        $this->assertNotContains('Teātris', $cats2);

        // 3. Category event_category-kino
        $cats3 = $scraper->resolveCategories(['kino'], 'Filmas seanss', 'Apraksts', 'Siguldas Devons');
        $this->assertEquals(['Kino'], $cats3);
    }

    public function test_resolve_categories_theatre_and_music(): void
    {
        $scraper = new SiguldaTourismScraper();

        $cats1 = $scraper->resolveCategories(['koncerts'], 'Borisa Rezņika dziesmu stāsts', 'Koncerts');
        $this->assertEquals(['Mūzika'], $cats1);

        $cats2 = $scraper->resolveCategories(['izrade'], 'Interaktīva izrāde', 'Luga uz skatuves');
        $this->assertEquals(['Teātris'], $cats2);

        $cats3 = $scraper->resolveCategories(['izstade'], 'Mākslas izstāde', 'Gleznu skate');
        $this->assertEquals(['Izstādes'], $cats3);
    }

    public function test_scraped_event_dto_ingestion_with_categories(): void
    {
        $this->seed(\Database\Seeders\CategorySeeder::class);

        $source = Source::create([
            'name' => 'Siguldas novada tūrisms',
            'slug' => 'sigulda-tourism',
            'url' => 'https://tourism.sigulda.lv/events/',
            'scraper_class' => SiguldaTourismScraper::class,
            'is_active' => true,
        ]);

        $dto = new ScrapedEventDTO(
            title: 'Borisa Rezņika dziesmu stāsts',
            startAt: Carbon::tomorrow('Europe/Riga')->setTime(19, 0),
            endAt: null,
            description: 'Koncerts Siguldas Devonā.',
            shortDescription: 'Koncerts Siguldas Devonā.',
            venueName: 'Kultūras centrs "Siguldas DEVONS"',
            city: 'Sigulda',
            region: 'Vidzeme',
            address: 'Pils iela 10, Sigulda, LV-2150',
            latitude: 57.1563,
            longitude: 24.8528,
            placeType: 'cultural_center',
            categoryNames: ['Mūzika'],
            entertainmentType: 'concert',
            isFree: false,
            priceMin: 20.0,
            priceMax: 20.0,
            currency: 'EUR',
            ticketUrl: 'https://www.bilesuparadize.lv/lv/event/167217',
            imageUrl: 'https://tourism.sigulda.lv/wp-content/uploads/2026/03/test.jpg',
            sourceUrl: 'https://tourism.sigulda.lv/events/pasaule-pasaulit/',
            sourceExternalId: 'sigulda-71095',
            locale: 'lv'
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_external_id', 'sigulda-71095')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Sigulda', $event->location?->city);
        $this->assertEquals('Vidzeme', $event->location?->region);
        $this->assertTrue($event->categories->pluck('slug')->contains('muzika'));
    }
}
