<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\VisitDaugavpilsScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisitDaugavpilsScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata(): void
    {
        $scraper = new VisitDaugavpilsScraper();
        $this->assertEquals('visitdaugavpils', $scraper->getSlug());
        $this->assertEquals('Visit Daugavpils', $scraper->getName());
    }

    public function test_parse_dates_range(): void
    {
        $scraper = new VisitDaugavpilsScraper();

        [$startAt, $endAt] = $scraper->parseDates('06.11.2026 - 31.01.2027');

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-11-06 00:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2027-01-31 23:59:59', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_single_date_with_time_range(): void
    {
        $scraper = new VisitDaugavpilsScraper();

        [$startAt, $endAt] = $scraper->parseDates('21.11.2026', '09:00 - 15:00');

        $this->assertNotNull($startAt);
        $this->assertNotNull($endAt);
        $this->assertEquals('2026-11-21 09:00:00', $startAt->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-21 15:00:00', $endAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_single_date_with_single_time(): void
    {
        $scraper = new VisitDaugavpilsScraper();

        [$startAt, $endAt] = $scraper->parseDates('20.11.2026', '18:00');

        $this->assertNotNull($startAt);
        $this->assertNull($endAt);
        $this->assertEquals('2026-11-20 18:00:00', $startAt->format('Y-m-d H:i:s'));
    }

    public function test_parse_dates_single_date_without_time(): void
    {
        $scraper = new VisitDaugavpilsScraper();

        [$startAt, $endAt] = $scraper->parseDates('31.12.2026', '');

        $this->assertNotNull($startAt);
        $this->assertNull($endAt);
        $this->assertEquals('2026-12-31 00:00:00', $startAt->format('Y-m-d H:i:s'));
    }

    public function test_location_resolving_known_venues(): void
    {
        $scraper = new VisitDaugavpilsScraper();

        // 1. Rotko muzejs
        $loc1 = $scraper->resolveLocationMeta('Nodarbība bērniem Rotko muzejā');
        $this->assertEquals('Rotko muzejs', $loc1['name']);
        $this->assertEquals('Mihaila iela 3, Daugavpils, LV-5401', $loc1['address']);
        $this->assertEquals('museum', $loc1['place_type']);

        // 2. Daugavpils teātris
        $loc2 = $scraper->resolveLocationMeta('Izrāde Daugavpils teātrī');
        $this->assertEquals('Daugavpils teātris', $loc2['name']);
        $this->assertEquals('theatre', $loc2['place_type']);

        // 3. Daugavpils Kultūras pils
        $loc3 = $scraper->resolveLocationMeta('Koncerts', 'Pasākums notiks Daugavpils Kultūras pilī');
        $this->assertEquals('Daugavpils Kultūras pils', $loc3['name']);
        $this->assertEquals('cultural_center', $loc3['place_type']);

        // 4. Ledus halle
        $loc4 = $scraper->resolveLocationMeta('Ledus šovs Daugavpils Ledus hallē');
        $this->assertEquals('Daugavpils Ledus halle', $loc4['name']);
        $this->assertEquals('sports_complex', $loc4['place_type']);

        // 5. Park Hotel Latgola
        $loc5 = $scraper->resolveLocationMeta('Izstāde viesnīcā “Park Hotel Latgola”');
        $this->assertEquals('Viesnīca "Park Hotel Latgola"', $loc5['name']);
        $this->assertEquals('hotel', $loc5['place_type']);
    }

    public function test_resolve_categories_strict_cinema_rules(): void
    {
        $scraper = new VisitDaugavpilsScraper();

        // 1. Film in title MUST be Kino and NEVER Teātris (AGENTS.md)
        $cats1 = $scraper->resolveCategories('Animācijas filma bērniem', 'Kino seanss Kultūras pilī');
        $this->assertContains('Kino', $cats1);
        $this->assertNotContains('Teātris', $cats1);

        // 2. Kinoseanss MUST be Kino
        $cats2 = $scraper->resolveCategories('Dokumentālās filmas kinoseanss');
        $this->assertContains('Kino', $cats2);
        $this->assertNotContains('Teātris', $cats2);
    }

    public function test_resolve_categories_theatre_music_sports_exhibitions(): void
    {
        $scraper = new VisitDaugavpilsScraper();

        // 1. Theatre
        $cats1 = $scraper->resolveCategories('Baleta izrāde “Apburtā princese”', 'Uz Daugavpils Kultūras Pils skatuves');
        $this->assertContains('Teātris', $cats1);
        $this->assertEquals('theatre', $scraper->resolveEntertainmentType($cats1, 'Baleta izrāde “Apburtā princese”'));

        // 2. Music
        $cats2 = $scraper->resolveCategories('MUZIKĀLĀ BANKA 2026 / FINĀLŠOVS', 'Latvijas Radio dziesmu aptaujas finālkoncerts');
        $this->assertContains('Mūzika', $cats2);
        $this->assertEquals('concert', $scraper->resolveEntertainmentType($cats2, 'MUZIKĀLĀ BANKA'));

        // 3. Sports
        $cats3 = $scraper->resolveCategories('Publiskā slidošana Daugavpils Ledus hallē', 'Slidošana visai ģimenei');
        $this->assertContains('Sports', $cats3);
        $this->assertEquals('sports', $scraper->resolveEntertainmentType($cats3, 'Publiskā slidošana'));

        // 4. Exhibitions
        $cats4 = $scraper->resolveCategories('Veltas Loces gleznu personālizstāde “Rudens burvība”', 'Apskatāma izstāde');
        $this->assertContains('Izstādes', $cats4);
        $this->assertEquals('exhibition', $scraper->resolveEntertainmentType($cats4, 'Gleznu personālizstāde'));

        // 5. Seminars
        $cats5 = $scraper->resolveCategories('Meistarklase "Uzkodu plate"', 'Praktiska nodarbība un meistarklase');
        $this->assertContains('Semināri', $cats5);

        // 6. Kids
        $cats6 = $scraper->resolveCategories('Nodarbība bērniem “Kas ir stājglezniecība”', 'Aicinām bērnus');
        $this->assertContains('Bērniem', $cats6);

        // 7. Celebrations
        $cats7 = $scraper->resolveCategories('Adventes vainaga pirmās sveces iedegšana', 'Ziemassvētku gaidīšanas svētki');
        $this->assertContains('Svētki', $cats7);
    }

    public function test_skips_non_events(): void
    {
        $scraper = new VisitDaugavpilsScraper();

        $this->assertTrue($scraper->isNonEvent('Daugavpils teātra izrāžu kalendārs'));
        $this->assertTrue($scraper->isNonEvent('Kultūras pasākumi Daugavpils Kultūras pilī'));
        $this->assertTrue($scraper->isNonEvent('Daugavpils sporta pasākumu kalendārs'));
        $this->assertTrue($scraper->isNonEvent('Mākslas centra "Arka" pasākumu kalendārs'));
        $this->assertTrue($scraper->isNonEvent('Multimediju zāles “Daugavpils Kino” kino seansu repertuārs'));

        // Real events should not be skipped
        $this->assertFalse($scraper->isNonEvent('Baleta izrāde “Apburtā princese”'));
        $this->assertFalse($scraper->isNonEvent('Ledus šovs "Sapņu zemes burvis"'));
        $this->assertFalse($scraper->isNonEvent('MUZIKĀLĀ BANKA 2026 / FINĀLŠOVS'));
    }

    public function test_visitdaugavpils_event_ingestion(): void
    {
        $source = Source::create([
            'name' => 'Visit Daugavpils',
            'slug' => 'visitdaugavpils',
            'url' => 'https://www.visitdaugavpils.lv/pasakumi/',
            'scraper_class' => VisitDaugavpilsScraper::class,
            'is_active' => true,
        ]);

        $dto = new ScrapedEventDTO(
            title: 'Ledus šovs "Sapņu zemes burvis"',
            startAt: Carbon::create(2026, 12, 25, 17, 0, 0, 'Europe/Riga'),
            endAt: null,
            description: 'Daugavpils Ledus hallē notiks krāšņs ledus šovs visai ģimenei “Sapņu zemes burvis”.',
            shortDescription: 'Ledus šovs visai ģimenei.',
            venueName: 'Daugavpils Ledus halle',
            city: 'Daugavpils',
            region: 'Latgale',
            address: 'Stacijas iela 45A, Daugavpils, LV-5401',
            placeType: 'sports_complex',
            categoryNames: ['Sports', 'Bērniem'],
            entertainmentType: 'sports',
            isFree: false,
            imageUrl: 'https://www.visitdaugavpils.lv/wp-content/uploads/2026/11/show.jpg',
            sourceUrl: 'https://www.visitdaugavpils.lv/pasakumi/ledus-sovs-sapnu-zemes-burvis/',
            sourceExternalId: 'visitdaugavpils-ledus-sovs-sapnu-zemes-burvis',
            locale: 'lv',
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_external_id', 'visitdaugavpils-ledus-sovs-sapnu-zemes-burvis')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Ledus šovs "Sapņu zemes burvis"', $event->title);
        $this->assertEquals('visitdaugavpils.lv', $event->origin_host);
        $this->assertEquals('sports', $event->entertainment_type);
        $this->assertEquals('Daugavpils', $event->location->city);
        $this->assertEquals('Latgale', $event->location->region);
    }
}
