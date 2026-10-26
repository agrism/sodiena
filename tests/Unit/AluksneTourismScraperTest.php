<?php

namespace Tests\Unit;

use App\Models\Source;
use App\Services\Scrapers\Sources\AluksneTourismScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class AluksneTourismScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_scraper_metadata(): void
    {
        $scraper = new AluksneTourismScraper();
        $this->assertEquals('aluksne-tourism', $scraper->getSlug());
        $this->assertEquals('Visit Alūksne', $scraper->getName());
    }

    public function test_strict_cinema_priority_for_films(): void
    {
        $scraper = new AluksneTourismScraper();

        // AGENTS.md Rule 1: Films MUST ALWAYS be Kino and NEVER Teātris
        $cats1 = $scraper->classifyCategory(
            'V. Kairiša filma “Uļa”',
            '14.novembrī 16.00 Zeltiņu tautas namā V. Kairiša filma “Uļa”. Ieeja: 3,00 EUR.'
        );
        $this->assertEquals(['Kino'], $cats1);

        $cats2 = $scraper->classifyCategory(
            'Aigara Graubas spēlfilma “Jumprava. Lielais notikums”',
            '29.novembrī 14.00 Jaunannas tautas namā Aigara Graubas spēlfilma “Jumprava. Lielais notikums”. Ieeja: 4,00 EUR.'
        );
        $this->assertEquals(['Kino'], $cats2);
    }

    public function test_theatre_classification(): void
    {
        $scraper = new AluksneTourismScraper();

        $cats1 = $scraper->classifyCategory(
            'Izrāde “Tētis pēc 20 gadiem” ar Aināru Ančevski',
            '30.oktobrī 19.00 Alūksnes Kultūras centrā izrāde “Tētis pēc 20 gadiem” ar Aināru Ančevski.'
        );
        $this->assertEquals(['Teātris'], $cats1);

        $cats2 = $scraper->classifyCategory(
            'Komēdijizrāde “Kāzas Latgalē”',
            '7.novembrī 19.00Alūksnes Kultūras centrā komēdijizrāde “Kāzas Latgalē”.'
        );
        $this->assertEquals(['Teātris'], $cats2);

        $cats3 = $scraper->classifyCategory(
            'Stand-up izrāde “Vidējais Latvietis”',
            '21.novembrī 18.00 Alūksnes Kultūras centrā Edgara Bāliņa stand-up izrāde “Vidējais Latvietis”.'
        );
        $this->assertEquals(['Teātris'], $cats3);
    }

    public function test_kids_classification(): void
    {
        $scraper = new AluksneTourismScraper();

        $cats = $scraper->classifyCategory(
            'Leļļu izrāde bērniem “Robotiņš Mi – kurš visu dara citu vietā”',
            '14.novembrī 18.00 Alūksnes Kultūras centrā leļļu izrāde bērniem “Robotiņš Mi – kurš visu dara citu vietā”.'
        );
        $this->assertEquals(['Bērniem'], $cats);
    }

    public function test_exhibition_and_tour_classification(): void
    {
        $scraper = new AluksneTourismScraper();

        $cats1 = $scraper->classifyCategory(
            'Publiska ekskursija izstādē “Meža takās”',
            '7.novembrī 11.00 Viktora Ķirpa Ates muzejā “Vidzemes lauku sēta” publiska ekskursija izstādē “Meža takās”.'
        );
        $this->assertEquals(['Izstādes'], $cats1);

        $cats2 = $scraper->classifyCategory(
            'Keramikas entuziastes Ievas Anžes personālizstādes “Play with clay” atklāšana',
            '28.novembrī 12.00 Alūksnes novada muzejā keramikas entuziastes Ievas Anžes personālizstādes “Play with clay” / “Spēle ar mālu” atklāšana.'
        );
        $this->assertEquals(['Izstādes'], $cats2);
    }

    public function test_sports_classification(): void
    {
        $scraper = new AluksneTourismScraper();

        $cats1 = $scraper->classifyCategory(
            'Malēniešu kāršu spēles PUNS Ceļojošā turnīra 2026./2027. gada sezonas 2. posms',
            '31.oktobrī 11.00 Annas kultūras namā Malēniešu kāršu spēles PUNS Ceļojošā turnīra 2026./2027. gada sezonas 2. posms.'
        );
        $this->assertEquals(['Sports'], $cats1);

        $cats2 = $scraper->classifyCategory(
            'Valsts svētku notikums Vidzemē – “Karogu pārgājiens” apkārt Alūksnes ezeram',
            '18.novembrī 9.00 valsts svētku notikums Vidzemē – “Karogu pārgājiens” apkārt Alūksnes ezeram.'
        );
        $this->assertEquals(['Sports'], $cats2);
    }

    public function test_price_parsing(): void
    {
        $scraper = new AluksneTourismScraper();

        [$free1, $min1, $max1] = $scraper->parsePrice('Ieeja: bez maksas.');
        $this->assertTrue($free1);
        $this->assertEquals(0.0, $min1);
        $this->assertEquals(0.0, $max1);

        [$free2, $min2, $max2] = $scraper->parsePrice('Ieeja: brīva.');
        $this->assertTrue($free2);

        [$free3, $min3, $max3] = $scraper->parsePrice('Ieeja: 4,00 EUR.');
        $this->assertFalse($free3);
        $this->assertEquals(4.0, $min3);
        $this->assertEquals(4.0, $max3);

        [$free4, $min4, $max4] = $scraper->parsePrice('Ieeja: 18,00-30,00 EUR.');
        $this->assertFalse($free4);
        $this->assertEquals(18.0, $min4);
        $this->assertEquals(30.0, $max4);

        [$free5, $min5, $max5] = $scraper->parsePrice('Cena: pieaugušajiem 6,00 EUR, skolēniem 5,00 EUR.');
        $this->assertFalse($free5);
        $this->assertEquals(5.0, $min5);
        $this->assertEquals(6.0, $max5);
    }

    public function test_date_parsing_multiday_and_single_day(): void
    {
        $scraper = new AluksneTourismScraper();
        $today = Carbon::create(2026, 10, 26, 0, 0, 0, 'Europe/Riga');

        [$startAt1, $endAt1] = $scraper->parseDates('26.-31. oktobrī', null, $today);
        $this->assertEquals('2026-10-26 00:00:00', $startAt1->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-10-31 23:59:59', $endAt1->format('Y-m-d H:i:s'));

        [$startAt2, $endAt2] = $scraper->parseDates('30. oktobrī', '19.00', $today);
        $this->assertEquals('2026-10-30 19:00:00', $startAt2->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-10-30 21:00:00', $endAt2->format('Y-m-d H:i:s'));
    }

    public function test_venue_matching(): void
    {
        $scraper = new AluksneTourismScraper();

        $v1 = $scraper->matchVenue('30.oktobrī 19.00 Alūksnes Kultūras centrā izrāde');
        $this->assertEquals('Alūksnes Kultūras centrs', $v1['name']);
        $this->assertEquals('Alūksne', $v1['city']);
        $this->assertEquals('cultural_center', $v1['place_type']);

        $v2 = $scraper->matchVenue('8.novembrī 12.00 Zeltiņu kodolraķešu bāzē publiskā ekskursija');
        $this->assertEquals('Zeltiņu kodolraķešu bāze', $v2['name']);
        $this->assertEquals('Zeltiņi', $v2['city']);
        $this->assertEquals('attraction', $v2['place_type']);

        $v3 = $scraper->matchVenue('14.novembrī 11.00 Viktora Ķirpa Ates muzejā “Vidzemes lauku sēta”');
        $this->assertEquals('Viktora Ķirpa Ates muzejs “Vidzemes lauku sēta”', $v3['name']);
        $this->assertEquals('Kalncempji', $v3['city']);
        $this->assertEquals('museum', $v3['place_type']);
    }

    public function test_past_events_are_skipped(): void
    {
        $scraper = new AluksneTourismScraper();
        $today = Carbon::create(2026, 11, 10, 0, 0, 0, 'Europe/Riga');

        $html = '<p>1.novembrī 14.00 Alsviķu kultūras namā joku luga. Ieeja: 4,00 EUR.</p>';
        $crawler = new Crawler($html);
        $pElement = $crawler->filter('p')->getNode(0);

        $dto = $scraper->parseEventParagraph($pElement, 'https://visitaluksne.lv/pasakumi-un-turisma-aktualitates/', $today);
        $this->assertNull($dto, 'Event ended before reference date must be skipped');
    }

    public function test_parse_event_paragraph_full_dto(): void
    {
        $scraper = new AluksneTourismScraper();
        $today = Carbon::create(2026, 10, 20, 0, 0, 0, 'Europe/Riga');

        $html = '<div>
            <p>30.oktobrī 19.00 Alūksnes Kultūras centrā izrāde “Tētis pēc 20 gadiem” ar Aināru Ančevski. Biļetes: <a href="https://www.bilesuparadize.lv/lv/event/171146?fbclid=12345">ŠEIT.</a></p>
            <figure class="wp-caption aligncenter"><a href="https://visitaluksne.lv/wp-content/uploads/2026/08/Tetis-pec-20-gadiem.jpg"><img src="https://visitaluksne.lv/wp-content/uploads/2026/08/Tetis-pec-20-gadiem.svg" data-tf-src="https://visitaluksne.lv/wp-content/uploads/2026/08/Tetis-pec-20-gadiem.jpg"></a></figure>
        </div>';

        $crawler = new Crawler($html);
        $pElement = $crawler->filter('p')->getNode(0);

        $dto = $scraper->parseEventParagraph($pElement, 'https://visitaluksne.lv/pasakumi-un-turisma-aktualitates/', $today);

        $this->assertNotNull($dto);
        $this->assertEquals('Izrāde “Tētis pēc 20 gadiem” ar Aināru Ančevski', $dto->title);
        $this->assertEquals('Alūksnes Kultūras centrs', $dto->venueName);
        $this->assertEquals('Alūksne', $dto->city);
        $this->assertEquals(['Teātris'], $dto->categoryNames);
        $this->assertEquals('https://www.bilesuparadize.lv/lv/event/171146', $dto->ticketUrl);
        $this->assertEquals('https://visitaluksne.lv/wp-content/uploads/2026/08/Tetis-pec-20-gadiem.jpg', $dto->imageUrl);
        $this->assertStringContainsString('aluksne-2026-10-30', $dto->sourceExternalId);
    }
}
