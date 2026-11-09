<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\EkaseLvScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class EkaseLvScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_ekase_scraper_metadata(): void
    {
        $scraper = new EkaseLvScraper();
        $this->assertEquals('ekase', $scraper->getSlug());
        $this->assertEquals('eKase (ekase.lv)', $scraper->getName());
        $this->assertEquals('https://ekase.lv/img/ekase-logo.svg', EkaseLvScraper::DEFAULT_IMAGE);
        $this->assertEquals('https://ekase.lv', EkaseLvScraper::BASE_URL);
        $this->assertEquals('https://ekase.lv/lv/events/program', EkaseLvScraper::PROGRAM_URL);
    }

    public function test_parse_date_time(): void
    {
        $scraper = new EkaseLvScraper();

        $d1 = $scraper->parseDateTime('21. novembris 2026 20:00');
        $this->assertNotNull($d1);
        $this->assertEquals('2026-11-21 20:00', $d1->format('Y-m-d H:i'));

        $d2 = $scraper->parseDateTime('14. novembris - 20. novembris 2026 16:00');
        $this->assertNotNull($d2);
        $this->assertEquals('2026-11-14 16:00', $d2->format('Y-m-d H:i'));

        $d3 = $scraper->parseDateTime('24. jūlijs 2027 19:00');
        $this->assertNotNull($d3);
        $this->assertEquals('2027-07-24 19:00', $d3->format('Y-m-d H:i'));

        $d4 = $scraper->parseDateTime('13. februāris 2027');
        $this->assertNotNull($d4);
        $this->assertEquals('2027-02-13 00:00', $d4->format('Y-m-d H:i'));

        $this->assertNull($scraper->parseDateTime(''));
        $this->assertNull($scraper->parseDateTime('nav datuma'));
    }

    public function test_resolve_venue(): void
    {
        $scraper = new EkaseLvScraper();

        // 1. Valmieras Kultūras centrs
        $loc1 = $scraper->resolveVenue('Valmieras Kultūras centrs');
        $this->assertEquals('Valmieras Kultūras centrs', $loc1['venue']);
        $this->assertEquals('Valmiera', $loc1['city']);
        $this->assertEquals('Vidzeme', $loc1['region']);
        $this->assertEquals('Rīgas iela 10, Valmiera, LV-4201', $loc1['address']);
        $this->assertEqualsWithDelta(57.53860, $loc1['lat'], 0.0001);

        // 2. Lielais dzintars
        $loc2 = $scraper->resolveVenue('Liepājas koncertzāle "Lielais dzintars"');
        $this->assertEquals('Koncertzāle "Lielais dzintars"', $loc2['venue']);
        $this->assertEquals('Liepāja', $loc2['city']);
        $this->assertEquals('Kurzeme', $loc2['region']);

        // 3. GORS
        $loc3 = $scraper->resolveVenue('GORS, Latgales vēstniecība');
        $this->assertEquals('Latgales vēstniecība GORS', $loc3['venue']);
        $this->assertEquals('Rēzekne', $loc3['city']);
        $this->assertEquals('Latgale', $loc3['region']);

        // 4. Lutera baznīca
        $loc4 = $scraper->resolveVenue('Rīgas evaņģēliski luteriskā Lutera baznīca');
        $this->assertEquals('Rīgas Lutera baznīca (Torņakalna baznīca)', $loc4['venue']);
        $this->assertEquals('Rīga', $loc4['city']);
        $this->assertEquals('Torņakalna iela 3/5, Rīga, LV-1004', $loc4['address']);

        // 5. Fallback with city name
        $loc5 = $scraper->resolveVenue('Siguldas pils estrāde');
        $this->assertEquals('Siguldas pils estrāde', $loc5['venue']);
        $this->assertEquals('Sigulda', $loc5['city']);
        $this->assertEquals('Vidzeme', $loc5['region']);
    }

    public function test_parse_prices(): void
    {
        $scraper = new EkaseLvScraper();

        $html = <<<HTML
        <main class="content-wrapper">
            <div class="main-event-content">
                <div class="area-prices">
                    <div class="price">
                        <div class="amount">14 &euro; - 18 &euro;</div>
                    </div>
                    <div class="price">
                        <div class="amount">18 &euro;</div>
                    </div>
                </div>
            </div>
        </main>
HTML;
        $crawler = new Crawler($html);
        [$min, $max] = $scraper->parsePrices($crawler);
        $this->assertEquals(14.0, $min);
        $this->assertEquals(18.0, $max);
    }

    public function test_strict_category_detection_adherence(): void
    {
        $scraper = new EkaseLvScraper();

        // 1. Strict cinema screening
        [$cat1, $type1] = $scraper->detectCategory('Animācijas filma “Straume”', 'Filmas seanss', 'Kinoteātris');
        $this->assertEquals('Kino', $cat1);
        $this->assertEquals('cinema', $type1);

        // 2. Strict cinema venue
        [$cat2, $type2] = $scraper->detectCategory('Tikšanās ar māksliniekiem', 'Sarunas', 'Splendid Palace');
        $this->assertEquals('Kino', $cat2);
        $this->assertEquals('cinema', $type2);

        // 3. Concert referencing film music is Mūzika
        [$cat3, $type3] = $scraper->detectCategory(
            'Filmu mūzikas koncerts',
            'Skaņdarbi no pasaulslavenām filmām orķestra izpildījumā',
            'Koncertzāle Cēsis'
        );
        $this->assertEquals('Mūzika', $cat3);
        $this->assertEquals('concert', $type3);

        // 4. Theater production
        [$cat4, $type4] = $scraper->detectCategory('Izrāde “Mēness dārzs”', 'Teātra iestudējums divos cēlienos', 'Valmieras teātris');
        $this->assertEquals('Teātris', $cat4);
        $this->assertEquals('theater', $type4);

        // 5. Music concert
        [$cat5, $type5] = $scraper->detectCategory(
            'Carnival Youth "Labākā Doma Pasaulē"',
            'Koncertā izskanēs dziesmas no jaunā albuma',
            'Valmieras Kultūras centrs'
        );
        $this->assertEquals('Mūzika', $cat5);
        $this->assertEquals('concert', $type5);

        // 6. Band name BrainStorm
        [$cat6, $type6] = $scraper->detectCategory(
            'BrainStorm and NOËP Pärnu Rannastaadion',
            'Stadiona koncerti Igaunijā ar latviešu grupu BrainStorm un NOËP',
            'Pärnu Rannastaadion'
        );
        $this->assertEquals('Mūzika', $cat6);
        $this->assertEquals('concert', $type6);

        // 7. Fallback to Cits
        [$cat7, $type7] = $scraper->detectCategory('Radošā darbnīca pieaugušajiem', 'Praktiskas nodarbības', 'Kultūras nams');
        $this->assertEquals('Cits', $cat7);
        $this->assertEquals('other', $type7);
    }

    public function test_ingest_ekase_event_into_database(): void
    {
        Category::updateOrCreate(['name' => 'Mūzika'], ['slug' => 'muzika']);

        $source = Source::create([
            'name' => 'eKase (ekase.lv)',
            'slug' => 'ekase-test',
            'url' => 'https://ekase.lv/lv/events/program',
            'scraper_class' => EkaseLvScraper::class,
            'is_active' => true,
        ]);

        $futureDate = now()->addDays(12)->setTime(20, 0);

        // Mock scraper instance that parses our test page
        $scraper = new class($futureDate) extends EkaseLvScraper {
            private Carbon $testDate;

            public function __construct(Carbon $testDate)
            {
                parent::__construct();
                $this->testDate = $testDate;
            }

            public function parseEventPage(string $url): ?\App\Services\Scrapers\DTO\ScrapedEventDTO
            {
                $location = $this->resolveVenue('Valmieras Kultūras centrs');
                [$category, $entertainmentType] = $this->detectCategory('Carnival Youth "Labākā Doma Pasaulē", Valmiera', 'Grupa Carnival Youth dodas tūrē', $location['venue']);

                return new \App\Services\Scrapers\DTO\ScrapedEventDTO(
                    title: 'Carnival Youth "Labākā Doma Pasaulē", Valmiera',
                    startAt: $this->testDate,
                    endAt: null,
                    description: 'Grupa Carnival Youth dodas tūrē ar jauno albumu.',
                    shortDescription: 'Grupa Carnival Youth dodas tūrē ar jauno albumu.',
                    venueName: $location['venue'],
                    city: $location['city'],
                    region: $location['region'],
                    address: $location['address'],
                    latitude: $location['lat'],
                    longitude: $location['lon'],
                    placeType: $location['place_type'],
                    categoryNames: [$category],
                    entertainmentType: $entertainmentType,
                    isFree: false,
                    priceMin: 14.0,
                    priceMax: 18.0,
                    currency: 'EUR',
                    ticketUrl: 'https://ekase.lv/lv/events/view/310',
                    imageUrl: 'https://ekase.lv/img/shows/86_thumb.jpg',
                    sourceUrl: 'https://ekase.lv/lv/events/view/310',
                    sourceExternalId: '310',
                    locale: 'lv',
                    rawData: [
                        'artist' => 'Carnival Youth',
                        'ekase_url' => 'https://ekase.lv/lv/events/view/310',
                    ]
                );
            }
        };

        $dto = $scraper->parseEventPage('https://ekase.lv/lv/events/view/310');
        $this->assertNotNull($dto);

        $ingestionService = app(EventIngestionService::class);
        $status = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $status);
        $this->assertEquals(1, Event::where('source_id', $source->id)->count());

        $saved = Event::where('source_id', $source->id)->first();
        $this->assertEquals('Carnival Youth "Labākā Doma Pasaulē", Valmiera', $saved->title);
        $this->assertEquals('concert', $saved->entertainment_type);
        $this->assertEquals('Valmiera', $saved->location->city);
        $this->assertEquals('Rīgas iela 10, Valmiera, LV-4201', $saved->location->address);
        $this->assertEquals(14.0, (float) $saved->price_min);
        $this->assertEquals(18.0, (float) $saved->price_max);
        $this->assertEquals('https://ekase.lv/lv/events/view/310', $saved->ticket_url);

        // Verify ticket links recognize eKase platform and label
        $ticketLinks = $saved->ticket_links;
        $this->assertNotEmpty($ticketLinks);
        $this->assertEquals('eKase', $ticketLinks[0]['title']);
        $this->assertEquals('Pirkt biļetes (eKase.lv)', $ticketLinks[0]['label']);
        $this->assertEquals('ekase', $ticketLinks[0]['platform']);
        $this->assertEquals('https://ekase.lv/lv/events/view/310', $ticketLinks[0]['url']);
    }
}
