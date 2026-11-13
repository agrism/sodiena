<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\SiguldaNovadsScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class SiguldaNovadsScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata(): void
    {
        $scraper = new SiguldaNovadsScraper();
        $this->assertEquals('sigulda-novads', $scraper->getSlug());
        $this->assertEquals('Siguldas novada pašvaldība', $scraper->getName());
    }

    public function test_parse_dates_single_event_with_time_range(): void
    {
        $scraper = new SiguldaNovadsScraper();
        $today = Carbon::create(2026, 11, 10, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates(['17. Nov'], '11:10 - 13:00', $today);

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-11-17 11:10:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-17 13:00:00', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_single_event_single_time(): void
    {
        $scraper = new SiguldaNovadsScraper();
        $today = Carbon::create(2026, 11, 10, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates(['20. Nov'], '19:00', $today);

        $this->assertNotNull($startAt);
        $this->assertNull($endAt);
        $this->assertEquals('2026-11-20 19:00:00', $startAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_multi_day_range_within_year(): void
    {
        $scraper = new SiguldaNovadsScraper();
        $today = Carbon::create(2026, 11, 10, 0, 0, 0, 'Europe/Riga');

        [$startAt, $endAt] = $scraper->parseDates(['01. Jan', '- 31. Dec'], null, $today);

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-01-01 00:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-12-31 23:59:59', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_multi_day_range_cross_year(): void
    {
        $scraper = new SiguldaNovadsScraper();
        $today = Carbon::create(2026, 11, 10, 0, 0, 0, 'Europe/Riga');

        // From October 2026 to January 2027
        [$startAt, $endAt] = $scraper->parseDates(['24. Okt', '- 08. Jan'], null, $today);

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-10-24 00:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2027-01-08 23:59:59', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_upcoming_next_year(): void
    {
        $scraper = new SiguldaNovadsScraper();
        $today = Carbon::create(2026, 11, 10, 0, 0, 0, 'Europe/Riga');

        // Single date in January while currently in November -> belongs to 2027
        [$startAt, $endAt] = $scraper->parseDates(['08. Jan'], '18:00', $today);

        $this->assertNotNull($startAt);
        $this->assertNull($endAt);
        $this->assertEquals('2027-01-08 18:00:00', $startAt->format('Y-m-d H:i:s'));
    }

    public function test_resolve_location_meta_various_parishes(): void
    {
        $scraper = new SiguldaNovadsScraper();

        $loc1 = $scraper->resolveLocationMeta('Kultūras centrs "Siguldas DEVONS"');
        $this->assertEquals('Kultūras centrs "Siguldas DEVONS"', $loc1['name']);
        $this->assertEquals('Sigulda', $loc1['city']);
        $this->assertEquals('Vidzeme', $loc1['region']);
        $this->assertEquals('cultural_center', $loc1['place_type']);

        $loc2 = $scraper->resolveLocationMeta('Koncertzāle "Baltais Flīģelis"');
        $this->assertEquals('Koncertzāle "Baltais Flīģelis"', $loc2['name']);
        $this->assertEquals('Sigulda', $loc2['city']);
        $this->assertEquals('concert_hall', $loc2['place_type']);

        $loc3 = $scraper->resolveLocationMeta('Inčukalna Tautas nams');
        $this->assertEquals('Inčukalna Tautas nams', $loc3['name']);
        $this->assertEquals('Inčukalns', $loc3['city']);
        $this->assertEquals('Vidzeme', $loc3['region']);

        $loc4 = $scraper->resolveLocationMeta('Mālpils Kultūras centrs');
        $this->assertEquals('Mālpils Kultūras centrs', $loc4['name']);
        $this->assertEquals('Mālpils', $loc4['city']);

        $loc5 = $scraper->resolveLocationMeta('Lēdurgas bibliotēka');
        $this->assertEquals('Lēdurga', $loc5['city']);
        $this->assertEquals('library', $loc5['place_type']);

        $loc6 = $scraper->resolveLocationMeta('Mores Tautas nams');
        $this->assertEquals('More', $loc6['city']);

        $loc7 = $scraper->resolveLocationMeta('Allažu bibliotēka');
        $this->assertEquals('Allaži', $loc7['city']);

        $loc8 = $scraper->resolveLocationMeta('Krimuldas Tautas nams');
        $this->assertEquals('Krimulda', $loc8['city']);

        $loc9 = $scraper->resolveLocationMeta('Sunīšu Kopienas centrs');
        $this->assertEquals('Sunīši', $loc9['city']);

        $loc10 = $scraper->resolveLocationMeta('Jūdažu Sabiedriskais centrs un bibliotēka');
        $this->assertEquals('Jūdaži', $loc10['city']);

        $loc11 = $scraper->resolveLocationMeta('Kino Lora');
        $this->assertEquals('Kino Lora', $loc11['name']);
        $this->assertEquals('cinema', $loc11['place_type']);
    }

    public function test_resolve_categories_strict_cinema_rules(): void
    {
        $scraper = new SiguldaNovadsScraper();

        // 1. Film in title MUST be Kino and NEVER Teātris (AGENTS.md)
        $cats1 = $scraper->resolveCategories('Detektīvpiedzīvojumu filma visai ģimenei “Misija: atrast vecākus”', 'Kultūras centrs "Siguldas DEVONS"');
        $this->assertContains('Kino', $cats1);
        $this->assertNotContains('Teātris', $cats1);

        // 2. Kinoseanss MUST be Kino
        $cats2 = $scraper->resolveCategories('Spēlfilmas “Tumšā robeža” seanss', 'Krimuldas Tautas nams');
        $this->assertContains('Kino', $cats2);
        $this->assertNotContains('Teātris', $cats2);

        // 3. Kino Lora venue MUST be Kino
        $cats3 = $scraper->resolveCategories('Jaunākā pirmizrāde', 'Kino Lora');
        $this->assertContains('Kino', $cats3);
        $this->assertNotContains('Teātris', $cats3);
    }

    public function test_resolve_categories_theatre_music_sports_exhibitions(): void
    {
        $scraper = new SiguldaNovadsScraper();

        $cMusic = $scraper->resolveCategories('Muzikālās apvienības “Raxtu Raxti” koncerts “Viena saule, viena zeme”');
        $this->assertContains('Mūzika', $cMusic);

        $cTheatre = $scraper->resolveCategories('Aleksandra Guzenko stāvizrāde “Jūtu impērija”');
        $this->assertContains('Teātris', $cTheatre);

        $cExhibition = $scraper->resolveCategories('Gleznotājas Daigas Krūzes personālizstāde “Ainavas pulsācija”');
        $this->assertContains('Izstādes', $cExhibition);

        $cSports = $scraper->resolveCategories('Inčukalna kauss badmintonā “Inčukalna kauss 2026”');
        $this->assertContains('Sports', $cSports);

        $cWorkshop = $scraper->resolveCategories('Maskēšanās tīklu darbnīca');
        $this->assertContains('Semināri', $cWorkshop);

        $cFallback = $scraper->resolveCategories('Neidentificēts pasākums');
        $this->assertEquals(['Cits'], $cFallback);
    }

    public function test_parse_card_paid_event_with_ticket(): void
    {
        $scraper = new SiguldaNovadsScraper();
        $today = Carbon::create(2026, 11, 10, 0, 0, 0, 'Europe/Riga');

        $html = <<<HTML
        <div class="card-event-wrap">
            <div style="position: relative;">
                <img alt="Muzikālās apvienības “Raxtu Raxti” koncerts" src="https://sigulda.lv/media/uploads/test.jpeg" class="ct-image card-small-image">
            </div>
            <div class="ct-div-block card-small card-event" onclick="javascript:window.location.href='https://sigulda.lv/pasakums/raxtu-raxti/'">
                <div class="ct-div-block card-small-side">
                    <div class="ct-text-block card-small-title">Muzikālās apvienības “Raxtu Raxti” koncerts “Viena saule, viena zeme”</div>
                    <div class="ct-div-block card-event-details">
                        <div class="ct-div-block card-event-detail">
                            <div class="ct-text-block card-event-detail-text">13. Nov</div>
                        </div>
                        <div class="ct-div-block card-event-detail">
                            <div class="ct-text-block card-event-detail-text">19:00 - 21:00</div>
                        </div>
                        <div class="ct-div-block card-event-detail">
                            <div class="ct-text-block card-event-detail-text">Kultūras centrs “Siguldas devons”</div>
                        </div>
                    </div>
                </div>
                <div class="ct-div-block event-list-action-wrap">
                    <a class="ct-link button" href="https://www.bilesuparadize.lv/en/event/167242" target="_blank">
                        <div class="ct-text-block">Pirkt</div>
                    </a>
                    <div class="ct-text-block event-action-free"></div>
                </div>
            </div>
        </div>
HTML;

        $crawler = new Crawler($html);
        $card = $crawler->filter('.card-event-wrap')->first();

        $dto = $scraper->parseCard($card, 'https://sigulda.lv/pasakumi/', $today);

        $this->assertNotNull($dto);
        $this->assertEquals('Muzikālās apvienības “Raxtu Raxti” koncerts “Viena saule, viena zeme”', $dto->title);
        $this->assertEquals('2026-11-13 19:00:00', $dto->startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-13 21:00:00', $dto->endAt->format('Y-m-d H:i:s'));
        $this->assertEquals('Kultūras centrs "Siguldas DEVONS"', $dto->venueName);
        $this->assertEquals('Sigulda', $dto->city);
        $this->assertEquals('Vidzeme', $dto->region);
        $this->assertFalse($dto->isFree);
        $this->assertEquals('https://www.bilesuparadize.lv/en/event/167242', $dto->ticketUrl);
        $this->assertEquals('https://sigulda.lv/pasakums/raxtu-raxti/', $dto->sourceUrl);
        $this->assertEquals('sigulda-raxtu-raxti', $dto->sourceExternalId);
        $this->assertEquals('concert', $dto->entertainmentType);
        $this->assertContains('Mūzika', $dto->categoryNames);
    }

    public function test_parse_card_free_event(): void
    {
        $scraper = new SiguldaNovadsScraper();
        $today = Carbon::create(2026, 11, 10, 0, 0, 0, 'Europe/Riga');

        $html = <<<HTML
        <div class="card-event-wrap">
            <div style="position: relative;">
                <img alt="Karodziņu izgatavošanas meistardarbnīca" src="https://sigulda.lv/media/uploads/workshop.png" class="ct-image card-small-image">
            </div>
            <div class="ct-div-block card-small card-event" onclick="javascript:window.location.href='https://sigulda.lv/pasakums/karodzinu-darbnica/'">
                <div class="ct-div-block card-small-side">
                    <div class="ct-text-block card-small-title">Karodziņu izgatavošanas meistardarbnīca</div>
                    <div class="ct-div-block card-event-details">
                        <div class="ct-div-block card-event-detail">
                            <div class="ct-text-block card-event-detail-text">17. Nov</div>
                        </div>
                        <div class="ct-div-block card-event-detail">
                            <div class="ct-text-block card-event-detail-text">14:00 - 16:00</div>
                        </div>
                        <div class="ct-div-block card-event-detail">
                            <div class="ct-text-block card-event-detail-text">Inčukalna bibliotēka</div>
                        </div>
                    </div>
                </div>
                <div class="ct-div-block event-list-action-wrap">
                    <div class="ct-text-block event-action-free">Bezmaksas</div>
                </div>
            </div>
        </div>
HTML;

        $crawler = new Crawler($html);
        $card = $crawler->filter('.card-event-wrap')->first();

        $dto = $scraper->parseCard($card, 'https://sigulda.lv/pasakumi/', $today);

        $this->assertNotNull($dto);
        $this->assertEquals('Karodziņu izgatavošanas meistardarbnīca', $dto->title);
        $this->assertEquals('2026-11-17 14:00:00', $dto->startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-17 16:00:00', $dto->endAt->format('Y-m-d H:i:s'));
        $this->assertEquals('Inčukalna bibliotēka', $dto->venueName);
        $this->assertEquals('Inčukalns', $dto->city);
        $this->assertEquals('Vidzeme', $dto->region);
        $this->assertTrue($dto->isFree);
        $this->assertEquals(0.0, $dto->priceMin);
        $this->assertEquals('sigulda-karodzinu-darbnica', $dto->sourceExternalId);
        $this->assertContains('Semināri', $dto->categoryNames);
    }

    public function test_scraped_event_dto_ingestion_with_categories(): void
    {
        $this->seed(\Database\Seeders\CategorySeeder::class);

        $source = Source::create([
            'name' => 'Siguldas novada pašvaldība',
            'slug' => 'sigulda-novads',
            'url' => 'https://sigulda.lv/pasakumi/',
            'scraper_class' => SiguldaNovadsScraper::class,
            'is_active' => true,
        ]);

        $dto = new ScrapedEventDTO(
            title: 'Valsts svētku koncerts “Mēs esam Latvija!”',
            startAt: Carbon::tomorrow('Europe/Riga')->setTime(18, 0),
            endAt: Carbon::tomorrow('Europe/Riga')->setTime(20, 0),
            description: 'Valsts svētku koncerts Inčukalnā.',
            shortDescription: 'Valsts svētku koncerts Inčukalnā.',
            venueName: 'Inčukalna Tautas nams',
            city: 'Inčukalns',
            region: 'Vidzeme',
            address: 'Atmodas iela 1A, Inčukalns, LV-2141',
            placeType: 'cultural_center',
            categoryNames: ['Mūzika', 'Svētki'],
            entertainmentType: 'concert',
            isFree: true,
            priceMin: 0.0,
            priceMax: 0.0,
            currency: 'EUR',
            ticketUrl: 'https://sigulda.lv/pasakums/valsts-svetku-koncerts/',
            imageUrl: 'https://sigulda.lv/media/uploads/test.jpg',
            sourceUrl: 'https://sigulda.lv/pasakums/valsts-svetku-koncerts/',
            sourceExternalId: 'sigulda-valsts-svetku-koncerts',
            locale: 'lv'
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_external_id', 'sigulda-valsts-svetku-koncerts')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Inčukalns', $event->location?->city);
        $this->assertEquals('Vidzeme', $event->location?->region);
        $this->assertTrue($event->categories->pluck('slug')->contains('muzika'));
        $this->assertTrue($event->categories->pluck('slug')->contains('svētki') || $event->categories->pluck('slug')->contains('svetki'));
    }
}

