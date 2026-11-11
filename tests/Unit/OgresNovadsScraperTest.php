<?php

namespace Tests\Unit;

use App\Models\Source;
use App\Services\Scrapers\Sources\OgresNovadsScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class OgresNovadsScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_ogres_novads_scraper_metadata(): void
    {
        $scraper = new OgresNovadsScraper();
        $this->assertEquals('ogres-novads', $scraper->getSlug());
        $this->assertEquals('Ogres novada pašvaldība', $scraper->getName());
        $this->assertEquals('https://www.ogresnovads.lv', OgresNovadsScraper::BASE_URL);
        $this->assertEquals('/lv/notikumu-kalendars', OgresNovadsScraper::CALENDAR_PATH);
    }

    public function test_skip_sanaksme_and_veselibas_veicinasana(): void
    {
        $scraper = new OgresNovadsScraper();

        // 1. Sanāksme must be skipped
        $this->assertTrue($scraper->shouldSkipCategories(['Sanāksme']));
        $this->assertTrue($scraper->shouldSkipCategories(['Valsts pārvalde', 'Sanāksme']));
        $this->assertTrue($scraper->isExcludedTitle('Birzgales pagasta iedzīvotāju padomes sēde'));
        $this->assertTrue($scraper->isExcludedTitle('Ogresgala pagasta iedzīvotāju padome'));

        // 2. Veselības veicināšana must be skipped
        $this->assertTrue($scraper->shouldSkipCategories(['Veselības veicināšana']));
        $this->assertTrue($scraper->shouldSkipCategories(['Veselība un sports', 'Veselības veicināšana']));

        // 3. Normal cultural categories must NOT be skipped
        $this->assertFalse($scraper->shouldSkipCategories(['Koncerts']));
        $this->assertFalse($scraper->shouldSkipCategories(['Izstāde']));
        $this->assertFalse($scraper->shouldSkipCategories(['Kino']));
        $this->assertFalse($scraper->shouldSkipCategories(['Valsts svētki', 'Lāčplēša diena']));
        $this->assertFalse($scraper->isExcludedTitle('Lāčplēša dienas koncerts Ogrē'));
    }

    public function test_unpack_accordion_exhibitions(): void
    {
        $html = <<<HTML
        <div class="accordion" id="accordion-1">
            <div class="card">
                <div class="card-header" id="madaras-rutkevicas-personalizstade">
                    <button class="btn btn-link">
                        Madaras Rutkēvičas personālizstāde “PĒCSTUNDAS” Ogres Vēstures un mākslas muzejā līdz 27. decembrim
                    </button>
                </div>
                <div class="card-body">
                    <p>Ogres Vēstures un mākslas muzejā tika atklāta personālizstāde. Ieeja bez maksas.</p>
                    <img src="/files/gallery_images/madara.jpg" />
                </div>
            </div>
        </div>
        <div class="accordion" id="accordion-2">
            <div class="card">
                <div class="card-header" id="past-exhibition">
                    <button class="btn btn-link">
                        Vecā izstāde Tīnūžu Tautas namā līdz 5. novembrim
                    </button>
                </div>
                <div class="card-body">
                    <p>Izstāde, kas jau beigusies.</p>
                </div>
            </div>
        </div>
        <div class="accordion" id="accordion-3">
            <div class="card">
                <div class="card-header" id="irita-galeja">
                    <button class="btn btn-link">
                        Iritas Galejas gleznu izstāde "Notvert gaismu" Madlienas kultūras namā līdz 7. decembrim
                    </button>
                </div>
                <div class="card-body">
                    <p>Madlienas kultūras namā no 1. oktobra līdz 7. decembrim apskatāma gleznu izstāde.</p>
                    <img src="/files/gallery_images/irita.jpg" />
                </div>
            </div>
        </div>
HTML;

        $scraper = new OgresNovadsScraper();
        $crawler = new Crawler($html, 'https://www.ogresnovads.lv/lv/notikums/izstades-novembri');

        $this->assertTrue($scraper->isAccordionExhibitionBundle($crawler));

        $today = Carbon::create(2026, 11, 11, 0, 0, 0, 'Europe/Riga');
        $unpacked = $scraper->unpackAccordionExhibitions($crawler, 'https://www.ogresnovads.lv/lv/notikums/izstades-novembri', $today);

        // Card 2 ended on Nov 5th (< Nov 11th), so it should be skipped as a past event!
        // Cards 1 and 3 run until Dec 27th and Dec 7th, so they must be active.
        $this->assertCount(2, $unpacked);

        $dto1 = $unpacked[0];
        $this->assertStringContainsString('Madaras Rutkēvičas personālizstāde', $dto1->title);
        $this->assertEquals('Ogres Vēstures un mākslas muzejs', $dto1->venueName);
        $this->assertEquals('Ogre', $dto1->city);
        $this->assertEquals('Vidzeme', $dto1->region);
        $this->assertEquals('Brīvības iela 36, Ogre, LV-5001', $dto1->address);
        $this->assertEquals('2026-12-27 19:00', $dto1->endAt->format('Y-m-d H:i'));
        $this->assertEquals('https://www.ogresnovads.lv/lv/notikums/izstades-novembri#madaras-rutkevicas-personalizstade', $dto1->sourceUrl);
        $this->assertEquals('ogresnovads-izstade-madaras-rutkevicas-personalizstade', $dto1->sourceExternalId);
        $this->assertContains('Māksla un izstādes', $dto1->categoryNames);
        $this->assertEquals('exhibition', $dto1->entertainmentType);
        $this->assertEquals('https://www.ogresnovads.lv/files/gallery_images/madara.jpg', $dto1->imageUrl);

        $dto2 = $unpacked[1];
        $this->assertStringContainsString('Iritas Galejas gleznu izstāde', $dto2->title);
        $this->assertEquals('Madlienas Kultūras nams', $dto2->venueName);
        $this->assertEquals('Madliena', $dto2->city);
        $this->assertEquals('2026-12-07 19:00', $dto2->endAt->format('Y-m-d H:i'));
    }

    public function test_standard_event_parsing_and_kino_rules(): void
    {
        $scraper = new OgresNovadsScraper();

        // 1. Kino Priority Rule: "Lāčplēša dienas pasākums un dokumentālā filma Tomē"
        $cats = $scraper->resolveCategories(['Lāčplēša diena'], 'Dokumentālā filma "Zvaigžņu ceļš" Tomē');
        $this->assertContains('Kino', $cats);
        $this->assertNotContains('Teātris', $cats);
        $this->assertEquals('movie', $scraper->resolveEntertainmentType($cats, 'Filma'));

        // 2. Dates parsing: "11. novembris, 2026" and "16.00"
        [$startAt, $endAt, $allDay] = $scraper->parseLatvianDates('11. novembris, 2026', '16.00');
        $this->assertNotNull($startAt);
        $this->assertEquals('2026-11-11 16:00', $startAt->format('Y-m-d H:i'));
        $this->assertNull($endAt);
        $this->assertFalse($allDay);

        // 3. Location resolving for Tīnūži
        $loc = $scraper->resolveLocation('Tīnūžu pagasts', 'Lāčplēša dienas pasākums Tīnūžos');
        $this->assertEquals('Tīnūžu Tautas nams', $loc['name']);
        $this->assertEquals('Tīnūži', $loc['city']);
        $this->assertEquals('Vidzeme', $loc['region']);
    }

    public function test_standard_event_concert_and_free_price(): void
    {
        $scraper = new OgresNovadsScraper();

        $price = $scraper->parsePrice('Ieeja pasākumā bez maksas.');
        $this->assertTrue($price['is_free']);
        $this->assertEquals(0.0, $price['price_min']);

        $priceWithFee = $scraper->parsePrice('Dalības maksa: 15 EUR.');
        $this->assertFalse($priceWithFee['is_free']);
        $this->assertEquals(15.0, $priceWithFee['price_min']);
    }

    public function test_full_scrape_with_mocked_http(): void
    {
        $source = Source::firstWhere('slug', 'ogres-novads') ?: Source::create([
            'name' => 'Ogres novada pašvaldība',
            'slug' => 'ogres-novads',
            'url' => 'https://www.ogresnovads.lv/lv/notikumu-kalendars',
            'scraper_class' => OgresNovadsScraper::class,
            'is_active' => true,
        ]);

        $futureDate = Carbon::tomorrow('Europe/Riga')->format('d. mēnesis, Y');
        // e.g. "12. novembris, 2026"
        $tomorrow = Carbon::tomorrow('Europe/Riga');
        $months = ['janvāris', 'februāris', 'marts', 'aprīlis', 'maijs', 'jūnijs', 'jūlijs', 'augusts', 'septembris', 'oktobris', 'novembris', 'decembris'];
        $formattedTomorrow = $tomorrow->day . '. ' . $months[$tomorrow->month - 1] . ', ' . $tomorrow->year;

        // Page 0 HTML with 3 events:
        // 1. Regular cultural event (accepted)
        // 2. Sanāksme (must be skipped)
        // 3. Veselības veicināšana (must be skipped)
        $listHtml = <<<HTML
        <div class="events">
            <div class="event">
                <div class="event-info">
                    <span class="date-date">{$formattedTomorrow}</span>
                    <span class="date-time">18.00</span>
                    <span class="describing-field-value">Ogres novads, Ogre</span>
                </div>
                <div class="event-image">
                    <img src="/files/gallery_images/koncerts.jpg" />
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/latvijas-svetku-koncerts-ogre?date=0">Latvijas svētku koncerts Ogrē</a>
                </h2>
                <div class="event-categories">
                    <a class="badge">Koncerts</a>
                    <a class="badge">Valsts svētki</a>
                </div>
            </div>

            <div class="event">
                <div class="event-info">
                    <span class="date-date">{$formattedTomorrow}</span>
                    <span class="date-time">15.00</span>
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/iedzivotaju-padomes-sede">Birzgales pagasta iedzīvotāju padomes sēde</a>
                </h2>
                <div class="event-categories">
                    <a class="badge">Sanāksme</a>
                </div>
            </div>

            <div class="event">
                <div class="event-info">
                    <span class="date-date">{$formattedTomorrow}</span>
                    <span class="date-time">10.00</span>
                </div>
                <h2 class="event-title">
                    <a href="/lv/notikums/mobila-diagnostika">Mobilā diagnostika Lielvārdē</a>
                </h2>
                <div class="event-categories">
                    <a class="badge">Veselības veicināšana</a>
                </div>
            </div>
        </div>
HTML;

        $detailHtml = <<<HTML
        <div class="event-content">
            <div class="cke_editable">
                <p>Ogres novada Kultūras centra Lielajā zālē svētku koncerts. Ieeja bez maksas.</p>
            </div>
        </div>
HTML;

        // Page 1 HTML (empty to trigger termination)
        $emptyPageHtml = '<div class="events"></div>';

        $mock = new MockHandler([
            new Response(200, [], $listHtml),       // Page 0
            new Response(200, [], $detailHtml),     // Detail for concert
            new Response(200, [], $emptyPageHtml),  // Page 1
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $scraper = new OgresNovadsScraper();
        $scraper->setClient($client);

        $events = $scraper->scrape($source);

        // Sanāksme and Veselības veicināšana must be skipped, leaving only 1 valid concert
        $this->assertCount(1, $events);
        $dto = $events->first();
        $this->assertEquals('Latvijas svētku koncerts Ogrē', $dto->title);
        $this->assertEquals('Ogres novada Kultūras centrs, Lielā zāle', $dto->venueName);
        $this->assertEquals('Ogre', $dto->city);
        $this->assertContains('Mūzika', $dto->categoryNames);
        $this->assertEquals('concert', $dto->entertainmentType);
        $this->assertTrue($dto->isFree);
        $this->assertEquals('ogresnovads-latvijas-svetku-koncerts-ogre', $dto->sourceExternalId);
    }
}

