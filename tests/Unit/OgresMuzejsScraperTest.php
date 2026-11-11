<?php

namespace Tests\Unit;

use App\Models\Source;
use App\Services\Scrapers\Sources\OgresMuzejsScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class OgresMuzejsScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_ogres_muzejs_scraper_metadata(): void
    {
        $scraper = new OgresMuzejsScraper();
        $this->assertEquals('ogres-muzejs', $scraper->getSlug());
        $this->assertEquals('Ogres Vēstures un mākslas muzejs (ogresmuzejs.lv)', $scraper->getName());
        $this->assertEquals('https://ogresmuzejs.lv', OgresMuzejsScraper::BASE_URL);
        $this->assertEquals('/piedavajums', OgresMuzejsScraper::OFFERS_PATH);
    }

    public function test_extract_cards_only_from_current_and_upcoming_sections(): void
    {
        $html = <<<HTML
        <div class="row" id="row-current">
            <div class="col medium-4">
                <div class="section-title-container">
                    <h3 class="section-title">Aktuālās izstādes</h3>
                </div>
            </div>
            <div class="col medium-8">
                <div class="box">
                    <a href="https://ogresmuzejs.lv/aktualo-izstade-1/">
                        <h5 class="post-title">Aktuālā izstāde 1</h5>
                    </a>
                    <p>No 2026. gada 1. decembra līdz 2027. gada 15. janvārim</p>
                </div>
            </div>
        </div>
        <div class="row" id="row-upcoming">
            <div class="col medium-4">
                <div class="section-title-container">
                    <h3 class="section-title">Gaidāmās izstādes</h3>
                </div>
            </div>
            <div class="col medium-8">
                <div class="box">
                    <a href="https://ogresmuzejs.lv/gaidama-izstade-2/">
                        <h5 class="post-title">Gaidāmā izstāde 2</h5>
                    </a>
                    <p>No 2027. gada 20. janvāra līdz 28. februārim</p>
                </div>
            </div>
        </div>
        <div class="row" id="row-classes">
            <div class="col medium-4">
                <div class="section-title-container">
                    <h3 class="section-title">Muzejpedagoģiskās nodarbības</h3>
                </div>
            </div>
            <div class="col medium-8">
                <div class="box">
                    <a href="https://ogresmuzejs.lv/nodarbiba-1/">
                        <h5 class="post-title">Nodarbība bērniem</h5>
                    </a>
                </div>
            </div>
        </div>
        <div class="row" id="row-activities">
            <div class="col medium-4">
                <div class="section-title-container">
                    <h3 class="section-title">Pasākumi</h3>
                </div>
            </div>
            <div class="col medium-8">
                <div class="box">
                    <a href="https://ogresmuzejs.lv/senioru-riti/">
                        <h5 class="post-title">Senioru rīti</h5>
                    </a>
                </div>
            </div>
        </div>
HTML;

        $scraper = new OgresMuzejsScraper();
        $crawler = new Crawler($html, 'https://ogresmuzejs.lv/piedavajums');

        $cards = $scraper->extractExhibitionCards($crawler);

        // Must ONLY contain the 2 items from Aktuālās and Gaidāmās izstādes
        $this->assertCount(2, $cards);
        $this->assertArrayHasKey('https://ogresmuzejs.lv/aktualo-izstade-1/', $cards);
        $this->assertArrayHasKey('https://ogresmuzejs.lv/gaidama-izstade-2/', $cards);
        $this->assertArrayNotHasKey('https://ogresmuzejs.lv/nodarbiba-1/', $cards);
        $this->assertArrayNotHasKey('https://ogresmuzejs.lv/senioru-riti/', $cards);
    }

    public function test_permanent_exhibitions_without_dates_are_skipped(): void
    {
        $scraper = new OgresMuzejsScraper();
        $today = Carbon::create(2026, 11, 11, 0, 0, 0, 'Europe/Riga');

        // Permanent exhibition card with address only and no dates
        $itemBrivibasCels = [
            'section' => 'current',
            'url' => 'https://ogresmuzejs.lv/ekspozicija-brivibas-cels/',
            'title' => 'Ekspozīcija “Brīvības ceļš”',
            'card_text' => 'Izstādes un ekspozīcijas Ekspozīcija “Brīvības ceļš” Ogrē, Brīvības ielā 2',
            'image' => 'https://ogresmuzejs.lv/uploads/brivibas.jpg',
        ];

        // Detail HTML also has no dates
        $detailHtml = '<div class="entry-content"><p>Ekspozīcija “Brīvības ceļš” atrodas Ogrē, Brīvības ielā 2. Tā vēsta par Latvijas cīņu par brīvību.</p></div>';

        $mock = new MockHandler([
            new Response(200, [], $detailHtml),
        ]);
        $scraper->setClient(new Client(['handler' => HandlerStack::create($mock)]));

        $dto = $scraper->processExhibitionItem($itemBrivibasCels, $today);

        // As agreed: must be skipped!
        $this->assertNull($dto, 'Permanent exhibition without dates must be skipped');
    }

    public function test_timed_exhibitions_date_parsing(): void
    {
        $scraper = new OgresMuzejsScraper();
        $today = Carbon::create(2026, 11, 11, 0, 0, 0, 'Europe/Riga');

        // 1. Cross-year range
        $res1 = $scraper->parseExhibitionDates('No 2026. gada 9. oktobra līdz 2027. gada 9. janvārim', '', $today);
        $this->assertNotNull($res1);
        $this->assertEquals('2026-10-09', $res1[0]->toDateString());
        $this->assertEquals('2027-01-09', $res1[1]->toDateString());

        // 2. Same-year range
        $res2 = $scraper->parseExhibitionDates('No 25. septembra līdz 27. decembrim', '', $today);
        $this->assertNotNull($res2);
        $this->assertEquals('2026-09-25', $res2[0]->toDateString());
        $this->assertEquals('2026-12-27', $res2[1]->toDateString());

        // 3. Next-year range
        $res3 = $scraper->parseExhibitionDates('No 2027. gada 22.janvāra līdz 28.martam', '', $today);
        $this->assertNotNull($res3);
        $this->assertEquals('2027-01-22', $res3[0]->toDateString());
        $this->assertEquals('2027-03-28', $res3[1]->toDateString());
    }

    public function test_venue_and_address_resolution(): void
    {
        $scraper = new OgresMuzejsScraper();

        // 1. Exhibition at Brīvības ceļš branch (Brīvības iela 2)
        $v1 = $scraper->resolveVenueAndAddress('Reiņa Hofmaņa izstāde', 'Izstāde būs skatāma ekspozīcijā “Brīvības ceļš”, Brīvības ielā 2');
        $this->assertEquals('Ogres Vēstures un mākslas muzeja ekspozīcija "Brīvības ceļš"', $v1['name']);
        $this->assertEquals('Brīvības iela 2, Ogre, LV-5001', $v1['address']);
        $this->assertEquals('Ogre', $v1['city']);

        // 2. Exhibition at Main museum building
        $v2 = $scraper->resolveVenueAndAddress('Starptautiskā izstāde “IZELPA. TELPA”', 'Ogres Vēstures un mākslas muzejā');
        $this->assertEquals('Ogres Vēstures un mākslas muzejs', $v2['name']);
        $this->assertEquals('Brīvības iela 36, Ogre, LV-5001', $v2['address']);
        $this->assertEquals('Ogre', $v2['city']);
    }

    public function test_past_exhibitions_are_skipped(): void
    {
        $scraper = new OgresMuzejsScraper();
        $today = Carbon::create(2026, 11, 11, 0, 0, 0, 'Europe/Riga');

        $pastItem = [
            'section' => 'current',
            'url' => 'https://ogresmuzejs.lv/veca-izstade/',
            'title' => 'Vecā izstāde',
            'card_text' => 'No 1. septembra līdz 31. oktobrim',
        ];

        $detailHtml = '<div class="entry-content"><p>Izstāde, kas beigusies oktobrī.</p></div>';
        $mock = new MockHandler([new Response(200, [], $detailHtml)]);
        $scraper->setClient(new Client(['handler' => HandlerStack::create($mock)]));

        $dto = $scraper->processExhibitionItem($pastItem, $today);
        $this->assertNull($dto, 'Past exhibition must be skipped');
    }

    public function test_full_scrape_with_mocked_http(): void
    {
        $source = Source::firstWhere('slug', 'ogres-muzejs') ?: Source::create([
            'name' => 'Ogres Vēstures un mākslas muzejs',
            'slug' => 'ogres-muzejs',
            'url' => 'https://ogresmuzejs.lv/piedavajums',
            'scraper_class' => OgresMuzejsScraper::class,
            'is_active' => true,
        ]);

        $offersHtml = <<<HTML
        <div class="row">
            <div class="col medium-4">
                <div class="section-title-container">
                    <h3 class="section-title">Aktuālās izstādes</h3>
                </div>
            </div>
            <div class="col medium-8">
                <div class="box">
                    <a href="https://ogresmuzejs.lv/izstade-izelpa/">
                        <h5 class="post-title">Starptautiskā izstāde “IZELPA. TELPA”</h5>
                    </a>
                    <p>No 2026. gada 9. oktobra līdz 2027. gada 9. janvārim</p>
                </div>
                <div class="box">
                    <a href="https://ogresmuzejs.lv/ekspozicija-brivibas-cels/">
                        <h5 class="post-title">Ekspozīcija “Brīvības ceļš”</h5>
                    </a>
                    <p>Ogrē, Brīvības ielā 2</p>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col medium-4">
                <div class="section-title-container">
                    <h3 class="section-title">Gaidāmās izstādes</h3>
                </div>
            </div>
            <div class="col medium-8">
                <div class="box">
                    <a href="https://ogresmuzejs.lv/izstade-pierobeza/">
                        <h5 class="post-title">Reiņa Hofmaņa izstāde “Pierobeža”</h5>
                    </a>
                    <p>No 2026. gada 25. novembra līdz 2027. gada 18. janvārim</p>
                </div>
            </div>
        </div>
HTML;

        $detailIzelpa = <<<HTML
        <div class="article-inner">
            <div class="entry-image"><img src="https://ogresmuzejs.lv/uploads/izelpa-large.jpg" /></div>
            <div class="entry-content">
                <p>Starptautiskā telpiskās mākslas izstāde Ogres Vēstures un mākslas muzejā. Ieeja bez maksas.</p>
            </div>
        </div>
HTML;

        $detailBrivibas = <<<HTML
        <div class="entry-content">
            <p>Ekspozīcija Brīvības ceļš atrodas Brīvības ielā 2.</p>
        </div>
HTML;

        $detailPierobeza = <<<HTML
        <div class="article-inner">
            <div class="entry-image"><img src="https://ogresmuzejs.lv/uploads/pierobeza-large.jpg" /></div>
            <div class="entry-content">
                <p>Fotogrāfa Reiņa Hofmaņa personālizstāde ekspozīcijā “Brīvības ceļš” Brīvības ielā 2.</p>
            </div>
        </div>
HTML;

        $mock = new MockHandler([
            new Response(200, [], $offersHtml),
            new Response(200, [], $detailIzelpa),
            new Response(200, [], $detailBrivibas),
            new Response(200, [], $detailPierobeza),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $scraper = new OgresMuzejsScraper();
        $scraper->setClient($client);

        $events = $scraper->scrape($source);

        // The permanent dateless exhibition "Brīvības ceļš" is skipped, leaving 2 valid timed exhibitions
        $this->assertCount(2, $events);

        $e1 = $events->first();
        $this->assertEquals('Starptautiskā izstāde “IZELPA. TELPA”', $e1->title);
        $this->assertEquals('2026-10-09', $e1->startAt->toDateString());
        $this->assertEquals('2027-01-09', $e1->endAt->toDateString());
        $this->assertEquals('Ogres Vēstures un mākslas muzejs', $e1->venueName);
        $this->assertEquals('Māksla un izstādes', $e1->categoryNames[0]);
        $this->assertEquals('exhibition', $e1->entertainmentType);
        $this->assertTrue($e1->isFree);
        $this->assertEquals('https://ogresmuzejs.lv/uploads/izelpa-large.jpg', $e1->imageUrl);

        $e2 = $events->last();
        $this->assertEquals('Reiņa Hofmaņa izstāde “Pierobeža”', $e2->title);
        $this->assertEquals('2026-11-25', $e2->startAt->toDateString());
        $this->assertEquals('2027-01-18', $e2->endAt->toDateString());
        $this->assertEquals('Ogres Vēstures un mākslas muzeja ekspozīcija "Brīvības ceļš"', $e2->venueName);
        $this->assertEquals('Brīvības iela 2, Ogre, LV-5001', $e2->address);
    }
}
