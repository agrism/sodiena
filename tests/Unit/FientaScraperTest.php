<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\FientaScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class FientaScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_fienta_scraper_metadata(): void
    {
        $scraper = new FientaScraper();
        $this->assertEquals('fienta', $scraper->getSlug());
        $this->assertEquals('Fienta (fienta.com)', $scraper->getName());
        $this->assertEquals(FientaScraper::BASE_URL, 'https://fienta.com');
        $this->assertEquals(FientaScraper::DEFAULT_URL, 'https://fienta.com/lv');
        $this->assertEquals(FientaScraper::DEFAULT_IMAGE, 'https://fienta.com/assets_statamic/statamic/opengraph/fienta-og-en.png');
    }

    public function test_parse_card_valid_event(): void
    {
        $futureDatetime = now()->addDays(5)->format('Y-m-d 19:00');
        $html = <<<HTML
        <article class="event-card">
            <a href="https://fienta.com/lv/manaparize?utm_source=fienta-search">
                <div class="event-card-img-wrapper">
                    <div class="event-card-img">
                        <img src="https://fienta.com/cf/img/?width=548&file=/org/123/paris.jpg" alt="" />
                    </div>
                </div>
                <div class="event-card-body">
                    <div class="event-card-title">
                        <h2>Manā Parīzē - franču mūzikas koncerts</h2>
                    </div>
                    <p class="small">
                        <time datetime="{$futureDatetime}">Pk. 13. nov. 19:00</time>
                    </p>
                    <p class="small text-body-secondary">
                        Rīgas Svētā Pāvila evaņģēliski luteriskā baznīca
                    </p>
                </div>
            </a>
        </article>
HTML;

        $crawler = new Crawler($html);
        $scraper = new FientaScraper();
        $dto = $scraper->parseCard($crawler->filter('.event-card')->first());

        $this->assertNotNull($dto);
        $this->assertEquals('Manā Parīzē - franču mūzikas koncerts', $dto->title);
        $this->assertEquals('https://fienta.com/lv/manaparize', $dto->sourceUrl);
        $this->assertEquals('https://fienta.com/lv/manaparize', $dto->ticketUrl);
        $this->assertEquals('fienta-manaparize', $dto->sourceExternalId);
        $this->assertEquals('Rīgas Svētā Pāvila evaņģēliski luteriskā baznīca', $dto->venueName);
        $this->assertEquals('Rīga', $dto->city);
        $this->assertEquals('Rīga un Pierīga', $dto->region);
        $this->assertEquals('Augusta Deglava iela 1, Rīga, LV-1009', $dto->address);
        $this->assertEqualsWithDelta(56.95190, $dto->latitude, 0.0001);
        $this->assertEqualsWithDelta(24.14810, $dto->longitude, 0.0001);
        $this->assertContains('Mūzika', $dto->categoryNames);
        $this->assertEquals('concert', $dto->entertainmentType);
        $this->assertEquals('https://fienta.com/cf/img/?width=548&file=/org/123/paris.jpg', $dto->imageUrl);
    }

    public function test_parse_date_time_range_and_single(): void
    {
        $scraper = new FientaScraper();

        // 1. Single date with time
        [$s1, $e1] = $scraper->parseDateTime('2026-11-13 19:00', 'Pk. 13. nov. 19:00');
        $this->assertNotNull($s1);
        $this->assertEquals('2026-11-13 19:00', $s1->format('Y-m-d H:i'));
        $this->assertNull($e1);

        // 2. Date range spanning multiple weeks/months
        [$s2, $e2] = $scraper->parseDateTime('2026-11-14 10:00', 'S. 14. nov. 10:00 - Sv. 6. dec. 13:00');
        $this->assertNotNull($s2);
        $this->assertNotNull($e2);
        $this->assertEquals('2026-11-14 10:00', $s2->format('Y-m-d H:i'));
        $this->assertEquals('2026-12-06 13:00', $e2->format('Y-m-d H:i'));

        // 3. Invalid/empty
        [$s3, $e3] = $scraper->parseDateTime('', '');
        $this->assertNull($s3);
        $this->assertNull($e3);
    }

    public function test_parse_card_skips_past_events(): void
    {
        $pastDatetime = now()->subDays(5)->format('Y-m-d 19:00');
        $html = <<<HTML
        <article class="event-card">
            <a href="https://fienta.com/lv/old-event">
                <div class="event-card-body">
                    <div class="event-card-title">
                        <h2>Vecs pasākums</h2>
                    </div>
                    <p class="small">
                        <time datetime="{$pastDatetime}">Pirms 5 dienām</time>
                    </p>
                    <p class="small text-body-secondary">Rīga</p>
                </div>
            </a>
        </article>
HTML;

        $crawler = new Crawler($html);
        $scraper = new FientaScraper();
        $this->assertNull($scraper->parseCard($crawler->filter('.event-card')->first()));
    }

    public function test_resolve_venue(): void
    {
        $scraper = new FientaScraper();

        // 1. K. Suns cinema
        $loc1 = $scraper->resolveVenue('K. Suns, Rīga');
        $this->assertEquals('Kinoteātris "K.Suns"', $loc1['venue']);
        $this->assertEquals('Rīga', $loc1['city']);
        $this->assertEquals('Elizabetes iela 83/85, Rīga, LV-1050', $loc1['address']);
        $this->assertEquals('cinema', $loc1['place_type']);

        // 2. Salamandra Cinema
        $loc2 = $scraper->resolveVenue('The Salamandra Cinema, Riga');
        $this->assertEquals('The Salamandra Cinema', $loc2['venue']);
        $this->assertEquals('Rīga', $loc2['city']);
        $this->assertEquals('Salamandras iela 1, Rīga, LV-1024', $loc2['address']);

        // 3. Online event
        $loc3 = $scraper->resolveVenue('Tiešsaistē');
        $this->assertEquals('Tiešsaistē', $loc3['venue']);
        $this->assertEquals('online', $loc3['place_type']);

        // 4. Multiple venues
        $loc4 = $scraper->resolveVenue('Vairāki norises vietas');
        $this->assertEquals('Vairākas norises vietas', $loc4['venue']);

        // 5. Fallback city parsing
        $loc5 = $scraper->resolveVenue('VIENA TELPA, Ogre');
        $this->assertEquals('VIENA TELPA', $loc5['venue']);
        $this->assertEquals('Ogre', $loc5['city']);
        $this->assertEquals('Vidzeme', $loc5['region']);
    }

    public function test_strict_category_detection_adherence(): void
    {
        $scraper = new FientaScraper();

        // 1. Strict cinema screening (AGENTS.md)
        [$cat1, $type1] = $scraper->detectCategory('November 14: "Mysterious Skin" - Film Screening', 'The Salamandra Cinema, Riga');
        $this->assertEquals('Kino', $cat1);
        $this->assertEquals('cinema', $type1);

        // 2. Strict cinema venue
        [$cat2, $type2] = $scraper->detectCategory('Tikšanās ar režisoru', 'K. Suns');
        $this->assertEquals('Kino', $cat2);
        $this->assertEquals('cinema', $type2);

        // 3. Theater production
        [$cat3, $type3] = $scraper->detectCategory('Nāves Eņģeļi. Rūtas Treijas kriminoloģijas lekcijizrāde', 'Dailes teātris');
        $this->assertEquals('Teātris', $cat3);
        $this->assertEquals('theater', $type3);

        // 4. Music concert
        [$cat4, $type4] = $scraper->detectCategory('Grupas MENUETS Koncerts Benjamiņu namā', 'Benjamiņu nams');
        $this->assertEquals('Mūzika', $cat4);
        $this->assertEquals('concert', $type4);

        // 5. Workshop / Masterclass
        [$cat5, $type5] = $scraper->detectCategory('Macarons cepumu pagatavošanas meistarklase', 'ROOM Jūrmala');
        $this->assertEquals('Izglītība', $cat5);
        $this->assertEquals('workshop', $type5);

        // 6. Exhibition
        [$cat6, $type6] = $scraper->detectCategory('Izstāde - Mana Latvija zied un mirdz', 'Nordeķu muiža');
        $this->assertEquals('Izstādes', $cat6);
        $this->assertEquals('exhibition', $type6);

        // 7. Fallback to Cits
        [$cat7, $type7] = $scraper->detectCategory('13.11. VĪNA KAZINO', 'Vīnojam');
        $this->assertEquals('Cits', $cat7);
        $this->assertEquals('other', $type7);
    }

    public function test_ingest_fienta_event_into_database(): void
    {
        Category::updateOrCreate(['name' => 'Mūzika'], ['slug' => 'muzika']);

        $source = Source::create([
            'name' => 'Fienta (fienta.com)',
            'slug' => 'fienta-test',
            'url' => 'https://fienta.com/lv',
            'scraper_class' => FientaScraper::class,
            'is_active' => true,
        ]);

        $futureDatetime = now()->addDays(10)->format('Y-m-d 20:00');
        $html = <<<HTML
        <article class="event-card">
            <a href="https://fienta.com/lv/yut-akustiski?utm_source=fienta-search">
                <div class="event-card-img-wrapper">
                    <div class="event-card-img">
                        <img src="https://fienta.com/cf/img/?file=/org/35216/yut.jpg" alt="" />
                    </div>
                </div>
                <div class="event-card-body">
                    <div class="event-card-title">
                        <h2>YŪT | akustiski</h2>
                    </div>
                    <p class="small">
                        <time datetime="{$futureDatetime}">Pk. 13. nov. 20:00</time>
                    </p>
                    <p class="small text-body-secondary">
                        M/Darbnīca - M/House of JAZZ, Rīga
                    </p>
                </div>
            </a>
        </article>
HTML;

        $crawler = new Crawler($html);
        $scraper = new FientaScraper();
        $dto = $scraper->parseCard($crawler->filter('.event-card')->first());
        $this->assertNotNull($dto);

        $ingestionService = app(EventIngestionService::class);
        $status = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $status);
        $this->assertEquals(1, Event::where('source_id', $source->id)->count());

        $saved = Event::where('source_id', $source->id)->first();
        $this->assertEquals('YŪT | akustiski', $saved->title);
        $this->assertEquals('concert', $saved->entertainment_type);
        $this->assertEquals('M/Darbnīca', $saved->location->name);
        $this->assertEquals('Rīga', $saved->location->city);
        $this->assertEquals('Aristida Briāna iela 9, Rīga, LV-1001', $saved->location->address);
        $this->assertEquals('https://fienta.com/lv/yut-akustiski', $saved->ticket_url);

        // Verify ticket links recognize Fienta platform and label
        $ticketLinks = $saved->ticket_links;
        $this->assertNotEmpty($ticketLinks);
        $this->assertEquals('Fienta', $ticketLinks[0]['title']);
        $this->assertEquals('Pirkt biļetes (Fienta)', $ticketLinks[0]['label']);
        $this->assertEquals('fienta', $ticketLinks[0]['platform']);
        $this->assertEquals('https://fienta.com/lv/yut-akustiski', $ticketLinks[0]['url']);
    }
}

