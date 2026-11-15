<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\MadonaScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MadonaScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata(): void
    {
        $scraper = new MadonaScraper();
        $this->assertEquals('madona', $scraper->getSlug());
        $this->assertEquals('Madonas novads', $scraper->getName());
        $this->assertEquals('https://madona.lv', MadonaScraper::BASE_URL);
        $this->assertEquals('/lat/pasakumi', MadonaScraper::PASAKUMI_PATH);
        $this->assertEquals('/lat/pasakumu-kalendars', MadonaScraper::KALENDARS_PATH);
    }

    public function test_parse_dates(): void
    {
        $scraper = new MadonaScraper();
        $today = Carbon::create(2026, 11, 15, 10, 0, 0, 'Europe/Riga');

        // 1. Same-day start and end time
        [$s1, $e1] = $scraper->parseDates('15. novembris 17:00 - 18:28', 2026, $today);
        $this->assertNotNull($s1);
        $this->assertNotNull($e1);
        $this->assertEquals('2026-11-15 17:00:00', $s1->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-15 18:28:00', $e1->format('Y-m-d H:i:s'));

        // 2. Overnight / multi-day range with times
        [$s2, $e2] = $scraper->parseDates('17. novembris 19:00 - 18. novembris 02:00', 2026, $today);
        $this->assertNotNull($s2);
        $this->assertNotNull($e2);
        $this->assertEquals('2026-11-17 19:00:00', $s2->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-18 02:00:00', $e2->format('Y-m-d H:i:s'));

        // 3. Multi-month exhibition
        [$s3, $e3] = $scraper->parseDates('12. septembris 13:00 - 22. novembris 16:00', 2026, $today);
        $this->assertNotNull($s3);
        $this->assertNotNull($e3);
        $this->assertEquals('2026-09-12 13:00:00', $s3->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-22 16:00:00', $e3->format('Y-m-d H:i:s'));

        // 4. Cross-year rollover (November 2026 to January 2027)
        [$s4, $e4] = $scraper->parseDates('13. novembris 16:00 - 3. janvāris 16:00', 2026, $today);
        $this->assertNotNull($s4);
        $this->assertNotNull($e4);
        $this->assertEquals('2026-11-13 16:00:00', $s4->format('Y-m-d H:i:s'));
        $this->assertEquals('2027-01-03 16:00:00', $e4->format('Y-m-d H:i:s'));

        // 5. Single date with time
        [$s5, $e5] = $scraper->parseDates('25. novembris 17:00', 2026, $today);
        $this->assertNotNull($s5);
        $this->assertNull($e5);
        $this->assertEquals('2026-11-25 17:00:00', $s5->format('Y-m-d H:i:s'));

        // 6. Multi-day range without times
        [$s6, $e6] = $scraper->parseDates('20. novembris - 3. decembris', 2026, $today);
        $this->assertNotNull($s6);
        $this->assertNotNull($e6);
        $this->assertEquals('2026-11-20 00:00:00', $s6->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-12-03 00:00:00', $e6->format('Y-m-d H:i:s'));
    }

    public function test_location_resolving_known_venues_and_parishes(): void
    {
        $scraper = new MadonaScraper();

        // 1. Kinoteātris „Vidzeme” in Madona
        $loc1 = $scraper->resolveLocation('kinoteātris „Vidzeme” Tirgus ielā 5', 'Madona', 'Kinoseanss');
        $this->assertEquals('Kinoteātris „Vidzeme”', $loc1['name']);
        $this->assertEquals('Madona', $loc1['city']);
        $this->assertEquals('Vidzeme', $loc1['region']);
        $this->assertEquals('Tirgus iela 5, Madona, Madonas novads, LV-4801', $loc1['address']);
        $this->assertEquals('cinema', $loc1['place_type']);
        $this->assertEquals(56.8530, $loc1['lat']);

        // 2. Madonas novada kultūras centrs
        $loc2 = $scraper->resolveLocation('Madonas novada kultūras centrs', 'Madona', 'Valsts svētku koncerts');
        $this->assertEquals('Madonas novada kultūras centrs', $loc2['name']);
        $this->assertEquals('Madona', $loc2['city']);
        $this->assertEquals('cultural_center', $loc2['place_type']);

        // 3. Madonas novadpētniecības un mākslas muzejs
        $loc3 = $scraper->resolveLocation(null, null, 'Gleznu izstāde', 'Madonas novadpētniecības un mākslas muzejā');
        $this->assertEquals('Madonas novadpētniecības un mākslas muzejs', $loc3['name']);
        $this->assertEquals('Madona', $loc3['city']);
        $this->assertEquals('museum', $loc3['place_type']);

        // 4. Cesvaines pils
        $loc4 = $scraper->resolveLocation('Cesvaines pils', 'Cesvaine', 'Ekskursija pilī');
        $this->assertEquals('Cesvaines pils', $loc4['name']);
        $this->assertEquals('Cesvaine', $loc4['city']);
        $this->assertEquals('museum', $loc4['place_type']);

        // 5. Cesvaines kultūras nams
        $loc5 = $scraper->resolveLocation('Cesvaines kultūras nams', 'Cesvaine', 'Koncerts');
        $this->assertEquals('Cesvaines kultūras nams', $loc5['name']);
        $this->assertEquals('Cesvaine', $loc5['city']);
        $this->assertEquals('cultural_center', $loc5['place_type']);

        // 6. Ērgļu saieta nams
        $loc6 = $scraper->resolveLocation('Ērgļu saieta nams', 'Ērgļi', 'Filmas seanss');
        $this->assertEquals('Ērgļu saieta nams', $loc6['name']);
        $this->assertEquals('Ērgļi', $loc6['city']);
        $this->assertEquals('cultural_center', $loc6['place_type']);

        // 7. Lubānas kultūras nams
        $loc7 = $scraper->resolveLocation('Lubānas kultūras nams', 'Lubāna', 'Tautas deju festivāls');
        $this->assertEquals('Lubānas kultūras nams', $loc7['name']);
        $this->assertEquals('Lubāna', $loc7['city']);
        $this->assertEquals('cultural_center', $loc7['place_type']);

        // 8. Varakļānu kultūras centrs
        $loc8 = $scraper->resolveLocation('Varakļānu kultūras centrs', 'Varakļāni', 'Balle ar grupu Galaktika');
        $this->assertEquals('Varakļānu kultūras centrs', $loc8['name']);
        $this->assertEquals('Varakļāni', $loc8['city']);
        $this->assertEquals('cultural_center', $loc8['place_type']);

        // 9. Vestienas tautas nams
        $loc9 = $scraper->resolveLocation('Vestienas tautas nams', 'Vestiena', 'Filma Tumšā robeža');
        $this->assertEquals('Vestienas tautas nams', $loc9['name']);
        $this->assertEquals('Vestiena', $loc9['city']);
        $this->assertEquals('cultural_center', $loc9['place_type']);

        // 10. Tautas nams „Kalnagravas”
        $loc10 = $scraper->resolveLocation('Tautas nams "Kalnagravas"', 'Sarkaņi', 'Svētku balle');
        $this->assertEquals('Tautas nams „Kalnagravas”', $loc10['name']);
        $this->assertEquals('Sarkaņi', $loc10['city']);
        $this->assertEquals('cultural_center', $loc10['place_type']);

        // 11. Liezēres kultūras nams
        $loc11 = $scraper->resolveLocation('Liezēres kultūras nams', 'Liezēre', 'Valsts svētku koncerts');
        $this->assertEquals('Liezēres kultūras nams', $loc11['name']);
        $this->assertEquals('Liezēre', $loc11['city']);

        // 12. Dzelzavas kultūras nams
        $loc12 = $scraper->resolveLocation('Dzelzavas kultūras nams', 'Dzelzava', 'Koncerts');
        $this->assertEquals('Dzelzavas kultūras nams', $loc12['name']);
        $this->assertEquals('Dzelzava', $loc12['city']);

        // 13. Barkavas kultūras nams
        $loc13 = $scraper->resolveLocation('Barkavas kultūras nams', 'Barkava', 'Svētku koncerts');
        $this->assertEquals('Barkavas kultūras nams', $loc13['name']);
        $this->assertEquals('Barkava', $loc13['city']);

        // 14. Lauteres kultūras nams (Aronas pagasts)
        $loc14 = $scraper->resolveLocation('Lauteres kultūras nams', 'Arona', 'Koncerts');
        $this->assertEquals('Lauteres kultūras nams', $loc14['name']);
        $this->assertEquals('Arona', $loc14['city']);

        // 15. Smeceres sils
        $loc15 = $scraper->resolveLocation('Smeceres sils', 'Madona', 'Slēpošanas sacensības');
        $this->assertEquals('Sporta un atpūtas bāze „Smeceres sils”', $loc15['name']);
        $this->assertEquals('Madona', $loc15['city']);
        $this->assertEquals('sports_facility', $loc15['place_type']);

        // 16. Kristus Karaļa baznīca
        $loc16 = $scraper->resolveLocation(null, 'Madona', 'Ekumeniskais svētbrīdis', 'Madonas Kristus Karaļa Romas katoļu baznīcā');
        $this->assertEquals('Madonas Kristus Karaļa Romas katoļu baznīca', $loc16['name']);
        $this->assertEquals('Madona', $loc16['city']);
        $this->assertEquals('place_of_worship', $loc16['place_type']);

        // 17. Generic parish match from string
        $loc17 = $scraper->resolveLocation(null, 'Kalsnava', 'Skrējiens', 'Jaunkalsnavā');
        $this->assertEquals('Kalsnava', $loc17['name']);
        $this->assertEquals('Kalsnava', $loc17['city']);
        $this->assertEquals('Vidzeme', $loc17['region']);
    }

    public function test_strict_categorization(): void
    {
        $scraper = new MadonaScraper();

        // 1. STRICT RULE: Kino venue is ALWAYS Kino, NEVER Teātris
        $c1 = $scraper->inferCategory('Kinoseanss "Klajumā aiz meža"', 'filma kinoteātrī', 'Kinoteātris „Vidzeme”');
        $this->assertEquals(['Kino'], $c1);

        // 2. Movie keywords -> Kino
        $c2 = $scraper->inferCategory('Filma „Tumšā robeža” Vestienā', 'Andreja Ēķa vēsturiska filma', 'Vestienas tautas nams');
        $this->assertEquals(['Kino'], $c2);

        $c3 = $scraper->inferCategory('Filma "VISIEM VAJAG NAUDU" Ērgļos', 'spēlfilmas seanss', 'Ērgļu saieta nams');
        $this->assertEquals(['Kino'], $c3);

        // 3. STRICT RULE: Teātris only for actual theater productions
        $c4 = $scraper->inferCategory('Madonas novada amatierteātru skate', 'amatierteātru izrādes un skate', 'Madonas novada kultūras centrs');
        $this->assertEquals(['Teātris'], $c4);

        $c5 = $scraper->inferCategory('R. Blaumaņa teātra izrāde "Kvēli ilgotā"', 'teātra iestudējums', 'Madonas novada kultūras centrs');
        $this->assertEquals(['Teātris'], $c5);

        // 4. Concerts
        $c6 = $scraper->inferCategory('Valsts svētku koncerts "Domu zīmes" Madonā', 'svētku koncerts', 'Madonas novada kultūras centrs');
        $this->assertEquals(['Koncerts'], $c6);

        $c7 = $scraper->inferCategory('Balle ar grupu „Galaktika” Varakļānos', 'svētku deju balle ar dzīvo mūziku', 'Varakļānu kultūras centrs');
        $this->assertEquals(['Koncerts'], $c7);

        // 5. Exhibitions
        $c8 = $scraper->inferCategory('Tev Nebūs Samierināt Monstrus', 'mākslinieka darbu izstāde muzejā', 'Madonas novadpētniecības un mākslas muzejs');
        $this->assertEquals(['Izstāde'], $c8);

        $c9 = $scraper->inferCategory('Madonas novada audēju izstāde „Rakstu tilti”', 'tautas lietišķās mākslas izstāde', 'Madonas novadpētniecības un mākslas muzejs');
        $this->assertEquals(['Izstāde'], $c9);

        // 6. Sports
        $c10 = $scraper->inferCategory('Aronas pagasta atklātie turnīri basketbolā un volejbolā', 'sporta sacensības', 'Arona');
        $this->assertEquals(['Sports'], $c10);

        $c11 = $scraper->inferCategory('Skrējiens "Apkārt Jaunkalsnavai 2026"', 'tradicionālais skrējiens sportistiem', 'Kalsnava');
        $this->assertEquals(['Sports'], $c11);

        // 7. Fallback to Cits
        $c12 = $scraper->inferCategory('Donoru diena Cesvainē', 'asins ziedošanas pasākums', 'Cesvaines kultūras nams');
        $this->assertEquals(['Cits'], $c12);

        $c13 = $scraper->inferCategory('Ekumeniskais svētbrīdis', 'Latvijas proklamēšanas dienai veltīts dievkalpojums', 'Madonas baznīca');
        $this->assertEquals(['Cits'], $c13);
    }

    public function test_extract_prices_and_free(): void
    {
        $scraper = new MadonaScraper();

        // 1. Free badge
        $p1 = $scraper->extractPricesAndFree(true, 'Ieeja brīva ikvienam interesentam', 'Koncerts');
        $this->assertTrue($p1['is_free']);
        $this->assertEquals(0.0, $p1['price_min']);
        $this->assertEquals(0.0, $p1['price_max']);

        // 2. Free text
        $p2 = $scraper->extractPricesAndFree(false, 'Pasākums ir bez maksas', 'Lekcija');
        $this->assertTrue($p2['is_free']);
        $this->assertEquals(0.0, $p2['price_min']);

        // 3. Paid text with EUR
        $p3 = $scraper->extractPricesAndFree(false, 'Ieejas maksa: 3 EUR.', 'Filma Tumšā robeža');
        $this->assertFalse($p3['is_free']);
        $this->assertEquals(3.0, $p3['price_min']);
        $this->assertEquals(3.0, $p3['price_max']);

        // 4. Paid text with price range
        $p4 = $scraper->extractPricesAndFree(false, 'Biļetes cena 5.00 līdz 10.00 EUR', 'Koncerts');
        $this->assertFalse($p4['is_free']);
        $this->assertEquals(5.0, $p4['price_min']);
        $this->assertEquals(10.0, $p4['price_max']);
    }

    public function test_url_normalization_and_external_id(): void
    {
        $scraper = new MadonaScraper();

        // Normalizing relative URLs
        $this->assertEquals(
            'https://madona.lv/lat/pasakumi?fu=read&id=9213',
            $scraper->normalizeUrl('pasakumi?fu=read&id=9213')
        );
        $this->assertEquals(
            'https://www.madona.lv/lat/pasakumi?fu=read&id=9213',
            $scraper->normalizeUrl('https://www.madona.lv/lat/pasakumi?fu=read&id=9213')
        );

        // Normalizing image URLs
        $this->assertEquals(
            'https://madona.lv/lat/box/pasakumi/9018.jpg',
            $scraper->normalizeImageUrl('box/pasakumi/9018.jpg')
        );
        $this->assertEquals(
            'https://www.madona.lv/lat/thumbs/r350/pasakumi/9213.jpg',
            $scraper->normalizeImageUrl('//www.madona.lv/lat/thumbs/r350/pasakumi/9213.jpg')
        );

        // Deterministic external IDs
        $this->assertEquals('madona-9213', $scraper->generateExternalId('pasakumi?fu=read&id=9213', null));
        $this->assertEquals('madona-sport-16503', $scraper->generateExternalId('https://www.madona.lv/lat/sporta-aktualitates?fu=read&id=16503', null));
        $this->assertEquals('madona-vm-1926', $scraper->generateExternalId('https://visitmadona.lv/lv/jaunumi?fu=read&id=1926', null));
    }

    public function test_mock_scrape_returns_dtos(): void
    {
        $futureDateStr = Carbon::tomorrow('Europe/Riga')->format('d.m.Y');

        $htmlKalendars = <<<HTML
<!DOCTYPE html>
<html>
<head><base href="https://madona.lv/lat/"></head>
<body>
    <article>
        <div class="raksts baged">
            <span class="img"><img src="//www.madona.lv/lat/thumbs/r350/pasakumi/9991.jpg"></span>
            <h3><a href="https://www.madona.lv/lat/pasakumi?fu=read&id=9991">Valsts svētku koncerts Liezērē</a></h3>
            <span class="kad">25. novembris 19:00 - 20:30; Liezēres kultūras nams; Liezēre</span>
            <span class="txt">Aicinām visus uz skaistu svētku koncertu Liezēres kultūras namā. Ieeja brīva.</span>
        </div>
        <div class="rakstshr"><span class="dat">iesūtīts: 10.11.2026</span><a class="rmore" href="https://www.madona.lv/lat/pasakumi?fu=read&id=9991">lasīt tālāk</a></div>
    </article>
</body>
</html>
HTML;

        $htmlPasakumi = <<<HTML
<!DOCTYPE html>
<html>
<head><base href="https://madona.lv/lat/"></head>
<body>
    <article>
        <div class="raksts">
            <span class="img"><img src="box/pasakumi/9992.jpg"></span>
            <h3><a href="pasakumi?fu=read&id=9992">Zigfrīda Sapieša mākslas izstāde</a></h3>
            <span class="kad">20. novembris 13:00 - 30. novembris 16:00</span>
            <span class="txt">Madonas novadpētniecības un mākslas muzejā skatāma Zigfrīda Sapieša izstāde.</span>
        </div>
        <div class="rakstshr"><span class="dat">iesūtīts: 01.11.2026</span><a class="rmore" href="pasakumi?fu=read&id=9992">lasīt tālāk</a></div>
    </article>
</body>
</html>
HTML;

        $mock = new MockHandler([
            new Response(200, [], $htmlKalendars),
            new Response(200, [], $htmlPasakumi),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $source = new Source([
            'name' => 'Madonas novads',
            'slug' => 'madona',
            'url' => 'https://madona.lv/lat/pasakumi',
            'scraper_class' => MadonaScraper::class,
            'type' => 'municipality',
            'is_active' => true,
        ]);

        $scraper = new MadonaScraper();
        $scraper->setClient($client);

        $events = $scraper->scrape($source);

        $this->assertCount(2, $events);

        // Event 1 from calendar
        $ev1 = $events->first();
        $this->assertEquals('Valsts svētku koncerts Liezērē', $ev1->title);
        $this->assertEquals('madona-9991', $ev1->sourceExternalId);
        $this->assertEquals('Liezēres kultūras nams', $ev1->venueName);
        $this->assertEquals('Liezēre', $ev1->city);
        $this->assertEquals('Vidzeme', $ev1->region);
        $this->assertEquals(['Koncerts'], $ev1->categoryNames);
        $this->assertTrue($ev1->isFree);
        $this->assertEquals('https://www.madona.lv/lat/thumbs/r350/pasakumi/9991.jpg', $ev1->imageUrl);

        // Event 2 from primary listing
        $ev2 = $events->last();
        $this->assertEquals('Zigfrīda Sapieša mākslas izstāde', $ev2->title);
        $this->assertEquals('madona-9992', $ev2->sourceExternalId);
        $this->assertEquals('Madonas novadpētniecības un mākslas muzejs', $ev2->venueName);
        $this->assertEquals('Madona', $ev2->city);
        $this->assertEquals('Vidzeme', $ev2->region);
        $this->assertEquals(['Izstāde'], $ev2->categoryNames);
        $this->assertEquals('https://madona.lv/lat/box/pasakumi/9992.jpg', $ev2->imageUrl);
    }

    public function test_ingest_into_database_with_event_ingestion_service(): void
    {
        $htmlKalendars = <<<HTML
<!DOCTYPE html>
<html>
<head><base href="https://madona.lv/lat/"></head>
<body>
    <article>
        <div class="raksts baged">
            <span class="img"><img src="//www.madona.lv/lat/thumbs/r350/pasakumi/9993.jpg"></span>
            <h3><a href="https://www.madona.lv/lat/pasakumi?fu=read&id=9993">Filma „Tumšā robeža” Vestienā</a></h3>
            <span class="kad">25. novembris 17:00 - 19:00; Vestienas tautas nams; Vestiena</span>
            <span class="txt">Aicinām uz Andreja Ēķa vēsturiska asa sižeta drāmu “Tumšā robeža”. Ieejas maksa: 3 EUR.</span>
        </div>
        <div class="rakstshr"><span class="dat">iesūtīts: 10.11.2026</span><a class="rmore" href="https://www.madona.lv/lat/pasakumi?fu=read&id=9993">lasīt tālāk</a></div>
    </article>
</body>
</html>
HTML;

        $htmlPasakumi = <<<HTML
<!DOCTYPE html>
<html>
<head><base href="https://madona.lv/lat/"></head>
<body>
</body>
</html>
HTML;

        $mock = new MockHandler([
            new Response(200, [], $htmlKalendars),
            new Response(200, [], $htmlPasakumi),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $source = Source::create([
            'name' => 'Madonas novads',
            'slug' => 'madona',
            'url' => 'https://madona.lv/lat/pasakumi',
            'scraper_class' => MadonaScraper::class,
            'type' => 'municipality',
            'is_active' => true,
        ]);

        $scraper = new MadonaScraper();
        $scraper->setClient($client);
        $this->app->instance(MadonaScraper::class, $scraper);

        $ingestionService = app(EventIngestionService::class);
        $log = $ingestionService->ingest($source);

        $this->assertEquals('success', $log->status);
        $this->assertEquals(1, $log->items_found);
        $this->assertEquals(1, $log->items_created);

        $event = Event::where('source_id', $source->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('Filma „Tumšā robeža” Vestienā', $event->title);
        $this->assertEquals('madona-9993', $event->source_external_id);
        $this->assertEquals('https://www.madona.lv/lat/thumbs/r350/pasakumi/9993.jpg', $event->image_url);
        $this->assertEquals(3.0, $event->price_min);
        $this->assertEquals(3.0, $event->price_max);

        // Location verification
        $this->assertNotNull($event->location);
        $this->assertEquals('Vestienas tautas nams', $event->location->name);
        $this->assertEquals('Vestiena', $event->location->city);
        $this->assertEquals('Vidzeme', $event->location->region);
    }
}

