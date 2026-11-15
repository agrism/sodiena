<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\KulturaJelgavaScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KulturaJelgavaScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata(): void
    {
        $scraper = new KulturaJelgavaScraper();
        $this->assertEquals('kultura-jelgava', $scraper->getSlug());
        $this->assertEquals('Jelgavas kultūras nams', $scraper->getName());
    }

    public function test_location_resolving_known_venues(): void
    {
        $scraper = new KulturaJelgavaScraper();

        // 1. Jelgavas kultūras nams
        $loc1 = $scraper->resolveLocationMeta('Koncerts', '', ['slug' => 'jelgavas-kulturas-nams', 'name' => 'Jelgavas kultūras nams']);
        $this->assertEquals('Jelgavas kultūras nams', $loc1['name']);
        $this->assertEquals('Krišjāņa Barona iela 6, Jelgava, LV-3001', $loc1['address']);
        $this->assertEquals('Zemgale', $loc1['region']);
        $this->assertEquals('cultural_center', $loc1['place_type']);
        $this->assertEquals(56.653913, $loc1['latitude']);
        $this->assertEquals(23.723446, $loc1['longitude']);

        // 2. Brīvdabas koncertzāle "Mītava"
        $loc2 = $scraper->resolveLocationMeta('Mītavas koncerts', '', ['slug' => 'brivdabas-koncertzale-mitava', 'name' => 'BRĪVDABAS KONCERTZĀLE "MĪTAVA"']);
        $this->assertEquals('Brīvdabas koncertzāle "Mītava"', $loc2['name']);
        $this->assertEquals('Pasta sala 3, Jelgava, LV-3001', $loc2['address']);
        $this->assertEquals('concert_hall', $loc2['place_type']);

        // 3. Ā.Alunāna Jelgavas teātra kamerzāle
        $loc3 = $scraper->resolveLocationMeta('Teātra izrāde', '', ['slug' => 'a-alunana-jelgava-teatra-kamerzale', 'name' => 'Ā.ALUNĀNA JELGAVAS TEĀTRA KAMERZĀLE']);
        $this->assertEquals('Ādolfa Alunāna Jelgavas teātra kamerzāle', $loc3['name']);
        $this->assertEquals('theatre', $loc3['place_type']);

        // 4. Pasta sala
        $loc4 = $scraper->resolveLocationMeta('Ledus skulptūru festivāls', '', ['slug' => 'pastasala-jelgava', 'name' => 'Pasta sala']);
        $this->assertEquals('Pasta sala', $loc4['name']);
        $this->assertEquals('park', $loc4['place_type']);

        // 5. Fallback from text
        $loc5 = $scraper->resolveLocationMeta('Festivāls Raiņa parks norises vietā', 'Būs jautri', null);
        $this->assertEquals('Raiņa parks', $loc5['name']);
        $this->assertEquals('Raiņa iela, Jelgava, LV-3001', $loc5['address']);
    }

    public function test_resolve_categories_strict_cinema_rules(): void
    {
        $scraper = new KulturaJelgavaScraper();

        // 1. Film in title with cinema category -> must be Kino, never Teātris
        $cats1 = $scraper->resolveCategories(
            'KINO. Spēlfilma “JUMPRAVA – LIELAIS NOTIKUMS” (2026)',
            'Kinoseanss Jelgavas kultūras namā',
            'Jelgavas kultūras nams',
            ['kino']
        );
        $this->assertContains('Kino', $cats1);
        $this->assertNotContains('Teātris', $cats1);

        // 2. Film screening at Ā.Alunāna teātris -> MUST STILL be Kino, NEVER Teātris!
        $cats2 = $scraper->resolveCategories(
            'Dokumentālā filma par Latvijas vēsturi',
            'Ieeja uz kinoseansu',
            'Ādolfa Alunāna Jelgavas teātra kamerzāle',
            []
        );
        $this->assertContains('Kino', $cats2);
        $this->assertNotContains('Teātris', $cats2);
        $type2 = $scraper->resolveEntertainmentType($cats2, 'Dokumentālā filma', '', []);
        $this->assertEquals('movie', $type2);

        // 3. Animation film for children
        $cats3 = $scraper->resolveCategories(
            'KINO | Animācijas filma “Straume”',
            'Filma bērniem un ģimenēm',
            'Jelgavas kultūras nams',
            ['kino']
        );
        $this->assertContains('Kino', $cats3);
        $this->assertContains('Bērniem', $cats3);
        $this->assertNotContains('Teātris', $cats3);
    }

    public function test_resolve_categories_theatre_music_exhibitions_festivals(): void
    {
        $scraper = new KulturaJelgavaScraper();

        // Ballet -> Teātris, theatre
        $cats1 = $scraper->resolveCategories('balets RIEKSTKODIS', 'Krāšņs balets', 'Jelgavas kultūras nams', ['balets']);
        $this->assertContains('Teātris', $cats1);
        $this->assertEquals('theatre', $scraper->resolveEntertainmentType($cats1, 'balets RIEKSTKODIS', '', ['balets']));

        // Theatre play -> Teātris, theatre
        $cats2 = $scraper->resolveCategories('Ā.Alunāna Jelgavas teātra izrāde “Sirds nepacietība”', 'Izrāde divos cēlienos', 'Ādolfa Alunāna Jelgavas teātra kamerzāle', ['izrade']);
        $this->assertContains('Teātris', $cats2);
        $this->assertEquals('theatre', $scraper->resolveEntertainmentType($cats2, 'Ā.Alunāna Jelgavas teātra izrāde “Sirds nepacietība”', '', ['izrade']));

        // Concert -> Mūzika, concert
        $cats3 = $scraper->resolveCategories('Jelgavas Kamerorķestra Vecgada koncerts', 'Koncertā skanēs klasiskā mūzika', 'Jelgavas kultūras nams', ['koncerts']);
        $this->assertContains('Mūzika', $cats3);
        $this->assertEquals('concert', $scraper->resolveEntertainmentType($cats3, 'Jelgavas Kamerorķestra Vecgada koncerts', '', ['koncerts']));

        // Exhibition -> Izstādes, exhibition
        $cats4 = $scraper->resolveCategories('Mākslinieka Vitālija Špakova personālizstāde', 'Gleznu izstāde', 'Jelgavas kultūras nams', ['izstade']);
        $this->assertContains('Izstādes', $cats4);
        $this->assertEquals('exhibition', $scraper->resolveEntertainmentType($cats4, 'Mākslinieka Vitālija Špakova personālizstāde', '', ['izstade']));

        // Festival -> Cits, festival
        $cats5 = $scraper->resolveCategories('28. Starptautiskais Ledus skulptūru festivāls', 'Pasaules karnevāls Pasta salā', 'Pasta sala', ['festivali']);
        $this->assertEquals('festival', $scraper->resolveEntertainmentType($cats5, '28. Starptautiskais Ledus skulptūru festivāls', '', ['festivali']));
    }

    public function test_skips_non_events(): void
    {
        $scraper = new KulturaJelgavaScraper();

        $this->assertTrue($scraper->isNonEvent('darba laiks'));
        $this->assertTrue($scraper->isNonEvent('kases darba laiks'));
        $this->assertTrue($scraper->isNonEvent('biļešu kases darba laiks'));
        $this->assertTrue($scraper->isNonEvent('kontakti'));
        $this->assertFalse($scraper->isNonEvent('balets RIEKSTKODIS'));
    }

    public function test_clean_description_removes_borlabs_cookie(): void
    {
        $scraper = new KulturaJelgavaScraper();

        $raw = '<p>Lieliska teātra izrāde bērniem.</p>'
            . '<div class="brlbs-cmpnt-container brlbs-cmpnt-content-blocker">'
            . '<div class="brlbs-cmpnt-cb-content">'
            . '<p class="brlbs-cmpnt-cb-description">Jūs pašlaik skatāt viettura saturu no Youtube.</p>'
            . '</div></div>'
            . '<p>Biļetes nopērkamas kasē.</p>';

        $clean = $scraper->cleanDescription($raw);
        $this->assertStringContainsString('Lieliska teātra izrāde bērniem.', $clean);
        $this->assertStringContainsString('Biļetes nopērkamas kasē.', $clean);
        $this->assertStringNotContainsString('viettura saturu', $clean);
        $this->assertStringNotContainsString('brlbs-', $clean);
    }

    public function test_parse_event_and_date_handling(): void
    {
        $scraper = new KulturaJelgavaScraper();
        $today = Carbon::parse('2026-11-15 00:00:00', 'Europe/Riga');

        $locations = [
            50 => [
                'id' => 50,
                'slug' => 'jelgavas-kulturas-nams',
                'name' => 'Jelgavas kultūras nams',
                'address' => 'Krišjāņa Barona iela 6',
                'city' => 'Jelgava',
                'postal_code' => 'LV-3001',
                'latitude' => 56.653913,
                'longitude' => 23.723446,
            ],
        ];

        $categories = [
            55 => ['id' => 55, 'name' => 'KONCERTS', 'slug' => 'koncerts'],
            24 => ['id' => 24, 'name' => 'MAKSAS', 'slug' => 'bezmaksasd'],
        ];

        // 1. Upcoming concert with hide_end=true and 30-min slot -> endAt should be null
        $item1 = [
            'id' => 19941,
            'slug' => 'jelgavas-kamerorkestra-vecgada-koncerts',
            'link' => 'https://kultura.jelgava.lv/stec_event/jelgavas-kamerorkestra-vecgada-koncerts/',
            'title' => ['rendered' => 'Jelgavas Kamerorķestra Vecgada koncerts'],
            'content' => ['rendered' => '<p>Svētku noskaņa vecgada vakarā.</p>'],
            'stec_loc' => [50],
            'stec_cat' => [55, 24],
            'meta' => [
                'start_date' => '2026-12-30T19:00',
                'end_date' => '2026-12-30T19:30',
                'hide_end' => true,
                'all_day' => false,
                'external_link' => [
                    'url' => 'https://www.bilesuparadize.lv/lv/event/181554',
                    'text' => 'Biļetes',
                ],
                'images' => [
                    [
                        'sizes' => [
                            'full' => 'https://kultura.jelgava.lv/wp-content/uploads/2026/11/Kamerorkestis.jpg',
                        ],
                    ],
                ],
            ],
        ];

        $dto1 = $scraper->parseEvent($item1, $locations, $categories, $today);
        $this->assertNotNull($dto1);
        $this->assertEquals('Jelgavas Kamerorķestra Vecgada koncerts', $dto1->title);
        $this->assertEquals('2026-12-30 19:00:00', $dto1->startAt->format('Y-m-d H:i:s'));
        $this->assertNull($dto1->endAt); // Hidden 30 min duration should be null
        $this->assertEquals('Jelgavas kultūras nams', $dto1->venueName);
        $this->assertEquals('Krišjāņa Barona iela 6, Jelgava, LV-3001', $dto1->address);
        $this->assertEquals(56.653913, $dto1->latitude);
        $this->assertEquals(23.723446, $dto1->longitude);
        $this->assertContains('Mūzika', $dto1->categoryNames);
        $this->assertEquals('concert', $dto1->entertainmentType);
        $this->assertFalse($dto1->isFree);
        $this->assertEquals('https://www.bilesuparadize.lv/lv/event/181554', $dto1->ticketUrl);
        $this->assertEquals('https://kultura.jelgava.lv/wp-content/uploads/2026/11/Kamerorkestis.jpg', $dto1->imageUrl);
        $this->assertEquals('kultura-jelgava-19941', $dto1->sourceExternalId);

        // 2. Strict AGENTS.md rule: past event should be skipped
        $itemPast = [
            'id' => 1001,
            'slug' => 'pagatnes-pasakums',
            'link' => 'https://kultura.jelgava.lv/stec_event/pagatnes-pasakums/',
            'title' => ['rendered' => 'Pagātnes pasākums'],
            'content' => ['rendered' => '<p>Jau beidzies.</p>'],
            'stec_loc' => [50],
            'stec_cat' => [55],
            'meta' => [
                'start_date' => '2024-05-10T19:00',
                'end_date' => '2024-05-10T21:00',
                'hide_end' => false,
                'all_day' => false,
            ],
        ];

        $dtoPast = $scraper->parseEvent($itemPast, $locations, $categories, $today);
        $this->assertNull($dtoPast);
    }

    public function test_kultura_jelgava_event_ingestion(): void
    {
        $source = Source::create([
            'name' => 'Jelgavas kultūras nams',
            'slug' => 'kultura-jelgava-test',
            'url' => 'https://kultura.jelgava.lv/pasakumi/',
            'scraper_class' => KulturaJelgavaScraper::class,
            'type' => 'municipality',
            'is_active' => true,
        ]);

        $dto = new ScrapedEventDTO(
            title: 'Jelgavas Kamerorķestra Vecgada koncerts',
            startAt: Carbon::parse('2026-12-30 19:00:00'),
            endAt: null,
            description: 'Svētku noskaņa vecgada vakarā Jelgavas kultūras namā.',
            shortDescription: 'Svētku noskaņa vecgada vakarā...',
            venueName: 'Jelgavas kultūras nams',
            city: 'Jelgava',
            region: 'Zemgale',
            address: 'Krišjāņa Barona iela 6, Jelgava, LV-3001',
            latitude: 56.653913,
            longitude: 23.723446,
            placeType: 'cultural_center',
            categoryNames: ['Mūzika'],
            entertainmentType: 'concert',
            isFree: false,
            priceMin: null,
            priceMax: null,
            currency: 'EUR',
            ticketUrl: 'https://www.bilesuparadize.lv/lv/event/181554',
            imageUrl: 'https://kultura.jelgava.lv/wp-content/uploads/2026/11/Kamerorkestis.jpg',
            sourceUrl: 'https://kultura.jelgava.lv/stec_event/jelgavas-kamerorkestra-vecgada-koncerts/',
            sourceExternalId: 'kultura-jelgava-19941',
            locale: 'lv',
            rawData: ['id' => 19941]
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);
        $this->assertDatabaseHas('events', [
            'source_id' => $source->id,
            'source_external_id' => 'kultura-jelgava-19941',
        ]);

        $event = Event::where('source_external_id', 'kultura-jelgava-19941')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Jelgavas Kamerorķestra Vecgada koncerts', $event->title);
        $this->assertEquals('Jelgava', $event->location->city);
        $this->assertEquals('Zemgale', $event->location->region);
        $this->assertEquals('concert', $event->entertainment_type);
        $this->assertEquals('https://www.bilesuparadize.lv/lv/event/181554', $event->ticket_url);
    }
}
