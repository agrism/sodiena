<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\BasketLvScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BasketLvScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_basket_scraper_metadata(): void
    {
        $scraper = new BasketLvScraper();
        $this->assertEquals('basket-lv', $scraper->getSlug());
        $this->assertEquals('Latvijas Basketbola Savienība (basket.lv)', $scraper->getName());
        $this->assertEquals('https://sodiena.hel1.your-objectstorage.com/leagues/ramirent-nbl.webp', BasketLvScraper::DEFAULT_IMAGE_NBL);
        $this->assertEquals('https://sodiena.hel1.your-objectstorage.com/leagues/sbbl.webp', BasketLvScraper::DEFAULT_IMAGE_SBBL);
    }

    public function test_parse_fixture_nbl(): void
    {
        $futureDate = Carbon::now('Europe/Riga')->addDays(5)->setTime(18, 0, 0);

        $fixture = [
            'fixtureId' => 'test-nbl-123',
            'startTimeLocal' => $futureDate->format('Y-m-d\TH:i:s'),
            'startTimeUTC' => (clone $futureDate)->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
            'status' => [
                'label' => 'Scheduled',
                'value' => 'SCHEDULED',
            ],
            'competitors' => [
                [
                    'name' => 'OBS/BK Ogre',
                    'isHome' => true,
                ],
                [
                    'name' => 'Jēkabpils BA',
                    'isHome' => false,
                ],
            ],
            'venue' => 'Sporta Arēna Ogre',
            'link' => '&~w=f~abc123fixture',
        ];

        $scraper = new BasketLvScraper();
        $dto = $scraper->parseFixture($fixture, 'nbl', BasketLvScraper::LEAGUES['nbl']);

        $this->assertNotNull($dto);
        $this->assertEquals('OBS/BK Ogre vs Jēkabpils BA', $dto->title);
        $this->assertEquals($futureDate->format('d.m.Y H:i'), $dto->startAt->format('d.m.Y H:i'));
        $this->assertEquals($futureDate->copy()->addMinutes(105)->format('d.m.Y H:i'), $dto->endAt->format('d.m.Y H:i'));
        $this->assertEquals('Arēna Ogre', $dto->venueName);
        $this->assertEquals('Ogre', $dto->city);
        $this->assertEquals('Vidzeme', $dto->region);
        $this->assertEquals('Gunāra Astras iela 1, Ogre', $dto->address);
        $this->assertEqualsWithDelta(56.81525, $dto->latitude, 0.0001);
        $this->assertEqualsWithDelta(24.61903, $dto->longitude, 0.0001);
        $this->assertEquals('sports', $dto->entertainmentType);
        $this->assertContains('Sports', $dto->categoryNames);
        $this->assertEquals(BasketLvScraper::DEFAULT_IMAGE_NBL, $dto->imageUrl);
        $this->assertEquals('https://nbl.basket.lv/kalendars/?~w=f~abc123fixture', $dto->sourceUrl);
        $this->assertEquals('basket_nbl_test-nbl-123', $dto->sourceExternalId);
    }

    public function test_parse_fixture_sbbl_daugavpils_ksc(): void
    {
        $futureDate = now()->addDays(12)->setTime(19, 0, 0);

        $fixture = [
            'fixtureId' => 'test-sbbl-456',
            'startTimeLocal' => $futureDate->format('Y-m-d\TH:i:s'),
            'startTimeUTC' => (clone $futureDate)->setTimezone('UTC')->format('Y-m-d\TH:i:s'),
            'status' => [
                'label' => 'Scheduled',
                'value' => 'SCHEDULED',
            ],
            'competitors' => [
                [
                    'name' => 'Daugavpils Universitāte',
                    'isHome' => true,
                ],
                [
                    'name' => 'Kaunas r. Atletas',
                    'isHome' => false,
                ],
            ],
            // User requested: Daugavpils KSC īstenībā ir Daugavpils kultūras pils sporta zāle
            'venue' => 'Daugavpils KSC',
            'link' => '&~w=f~xyz789fixture',
        ];

        $scraper = new BasketLvScraper();
        $dto = $scraper->parseFixture($fixture, 'sbbl', BasketLvScraper::LEAGUES['sbbl']);

        $this->assertNotNull($dto);
        $this->assertEquals('Daugavpils Universitāte vs Kaunas r. Atletas', $dto->title);
        $this->assertEquals('Daugavpils kultūras pils sporta zāle', $dto->venueName);
        $this->assertEquals('Daugavpils', $dto->city);
        $this->assertEquals('Latgale', $dto->region);
        $this->assertEquals('Smilšu iela 92, Daugavpils', $dto->address);
        $this->assertEqualsWithDelta(55.88738, $dto->latitude, 0.0001);
        $this->assertEqualsWithDelta(26.55463, $dto->longitude, 0.0001);
        $this->assertEquals(BasketLvScraper::DEFAULT_IMAGE_SBBL, $dto->imageUrl);
        $this->assertEquals('https://sbblb.basket.lv/kalendars/?~w=f~xyz789fixture', $dto->sourceUrl);
        $this->assertEquals('basket_sbbl_test-sbbl-456', $dto->sourceExternalId);
    }

    public function test_parse_fixture_sbbl_riga_kssh(): void
    {
        $futureDate = now()->addDays(15)->setTime(12, 0, 0);

        $fixture = [
            'fixtureId' => 'test-sbbl-789',
            'startTimeLocal' => $futureDate->format('Y-m-d\TH:i:s'),
            'status' => [
                'label' => 'Scheduled',
                'value' => 'SCHEDULED',
            ],
            'competitors' => [
                [
                    'name' => 'TTT Rīga Kadetes',
                    'isHome' => true,
                ],
                [
                    'name' => 'RSU-2',
                    'isHome' => false,
                ],
            ],
            // User requested: Rīga, KSSH īstenībā ir Komandu sporta spēļu halle Rīgā
            'venue' => 'Rīga, KSSH',
        ];

        $scraper = new BasketLvScraper();
        $dto = $scraper->parseFixture($fixture, 'sbbl', BasketLvScraper::LEAGUES['sbbl']);

        $this->assertNotNull($dto);
        $this->assertEquals('TTT Rīga Kadetes vs RSU-2', $dto->title);
        $this->assertEquals('Komandu sporta spēļu halle Rīgā', $dto->venueName);
        $this->assertEquals('Rīga', $dto->city);
        $this->assertEquals('Rīga un Pierīga', $dto->region);
        $this->assertEquals('Krišjāņa Barona iela 99C, Rīga', $dto->address);
        $this->assertEqualsWithDelta(56.96352, $dto->latitude, 0.0001);
        $this->assertEqualsWithDelta(24.14134, $dto->longitude, 0.0001);
    }

    public function test_parse_fixture_sbbl_skips_foreign_venues(): void
    {
        $futureDate = now()->addDays(5)->setTime(14, 0, 0);

        $foreignVenues = [
            'Druskininki',
            'Kristiine Spordimaja',
            'Ring Sports & Saunad sports hall',
            'Savanoiriu sporto arena',
            'Tartu Ulikooli Spordihoone',
            'Žalgiris Basketball academy',
        ];

        $scraper = new BasketLvScraper();

        foreach ($foreignVenues as $v) {
            $fixture = [
                'fixtureId' => 'test-foreign-' . md5($v),
                'startTimeLocal' => $futureDate->format('Y-m-d\TH:i:s'),
                'status' => ['value' => 'SCHEDULED'],
                'competitors' => [
                    ['name' => 'Tartu Ülikool', 'isHome' => true],
                    ['name' => 'TTT Rīga Kadetes', 'isHome' => false],
                ],
                'venue' => $v,
            ];

            $dto = $scraper->parseFixture($fixture, 'sbbl', BasketLvScraper::LEAGUES['sbbl']);
            $this->assertNull($dto, "Should skip foreign venue: {$v}");
        }
    }

    public function test_parse_fixture_skips_past_and_finished(): void
    {
        $pastDate = now()->subDays(2)->setTime(18, 0, 0);

        $fixturePast = [
            'fixtureId' => 'test-past',
            'startTimeLocal' => $pastDate->format('Y-m-d\TH:i:s'),
            'status' => ['value' => 'SCHEDULED'],
            'competitors' => [
                ['name' => 'BK Bauska', 'isHome' => true],
                ['name' => 'BK Grobiņa', 'isHome' => false],
            ],
            'venue' => 'Bauskas SH',
        ];

        $scraper = new BasketLvScraper();
        $this->assertNull($scraper->parseFixture($fixturePast, 'nbl', BasketLvScraper::LEAGUES['nbl']));

        $futureDate = now()->addDays(2)->setTime(18, 0, 0);
        $fixtureFinished = [
            'fixtureId' => 'test-fin',
            'startTimeLocal' => $futureDate->format('Y-m-d\TH:i:s'),
            'status' => ['value' => 'FINISHED'],
            'competitors' => [
                ['name' => 'BK Bauska', 'isHome' => true],
                ['name' => 'BK Grobiņa', 'isHome' => false],
            ],
            'venue' => 'Bauskas SH',
        ];
        $this->assertNull($scraper->parseFixture($fixtureFinished, 'nbl', BasketLvScraper::LEAGUES['nbl']));
    }

    public function test_resolve_location_known_venues_and_fallbacks(): void
    {
        $scraper = new BasketLvScraper();

        $locDaugavpils = $scraper->resolveLocation('Daugavpils KSC', 'Daugavpils Universitāte');
        $this->assertEquals('Daugavpils kultūras pils sporta zāle', $locDaugavpils['venue']);
        $this->assertEquals('Daugavpils', $locDaugavpils['city']);
        $this->assertTrue($locDaugavpils['in_latvia']);

        $locKssh = $scraper->resolveLocation('KSSH 1', 'Barsy Atirau');
        $this->assertEquals('Komandu sporta spēļu halle Rīgā', $locKssh['venue']);
        $this->assertTrue($locKssh['in_latvia']);

        $locTartu = $scraper->resolveLocation('Tartu Ulikooli Spordihoone', 'Tartu Ülikool');
        $this->assertFalse($locTartu['in_latvia']);

        $locFallback = $scraper->resolveLocation('Nezināma zāle', 'BK Saldus');
        $this->assertEquals('Saldus', $locFallback['city']);
        $this->assertEquals('Kurzeme', $locFallback['region']);
        $this->assertTrue($locFallback['in_latvia']);
    }

    public function test_ingest_basket_events_into_database(): void
    {
        Category::firstOrCreate(['slug' => 'sports'], ['name' => 'Sports']);

        $source = Source::create([
            'slug' => 'basket-lv',
            'name' => 'Latvijas Basketbola Savienība (basket.lv)',
            'url' => 'https://nbl.basket.lv/kalendars/',
            'scraper_class' => BasketLvScraper::class,
            'is_active' => true,
        ]);

        $futureDate = now()->addDays(8)->setTime(19, 30, 0);

        $fixtureNbl = [
            'fixtureId' => 'fixture-db-test-nbl',
            'startTimeLocal' => $futureDate->format('Y-m-d\TH:i:s'),
            'status' => ['value' => 'SCHEDULED'],
            'competitors' => [
                ['name' => 'BK Jelgava', 'isHome' => true],
                ['name' => 'BK Saldus', 'isHome' => false],
            ],
            'venue' => 'Jelgavas SH',
        ];

        $fixtureSbbl = [
            'fixtureId' => 'fixture-db-test-sbbl',
            'startTimeLocal' => $futureDate->copy()->addDays(1)->format('Y-m-d\TH:i:s'),
            'status' => ['value' => 'SCHEDULED'],
            'competitors' => [
                ['name' => 'Daugavpils Universitāte', 'isHome' => true],
                ['name' => 'TTT Rīga Kadetes', 'isHome' => false],
            ],
            'venue' => 'Daugavpils KSC',
        ];

        $scraper = new BasketLvScraper();
        $dto1 = $scraper->parseFixture($fixtureNbl, 'nbl', BasketLvScraper::LEAGUES['nbl']);
        $dto2 = $scraper->parseFixture($fixtureSbbl, 'sbbl', BasketLvScraper::LEAGUES['sbbl']);

        $service = app(EventIngestionService::class);
        $status1 = $service->ingestDTO($dto1, $source);
        $status2 = $service->ingestDTO($dto2, $source);

        $this->assertEquals('created', $status1);
        $this->assertEquals('created', $status2);
        $this->assertEquals(2, Event::where('source_id', $source->id)->count());

        $event1 = Event::where('source_external_id', 'basket_nbl_fixture-db-test-nbl')->first();
        $this->assertNotNull($event1);
        $this->assertEquals('BK Jelgava vs BK Saldus', $event1->title);
        $this->assertEquals(BasketLvScraper::DEFAULT_IMAGE_NBL, $event1->image_url);
        $this->assertEquals('Jelgavas sporta halle', $event1->location->name);
        $this->assertEquals('Jelgava', $event1->location->city);

        $event2 = Event::where('source_external_id', 'basket_sbbl_fixture-db-test-sbbl')->first();
        $this->assertNotNull($event2);
        $this->assertEquals('Daugavpils Universitāte vs TTT Rīga Kadetes', $event2->title);
        $this->assertEquals(BasketLvScraper::DEFAULT_IMAGE_SBBL, $event2->image_url);
        $this->assertEquals('Daugavpils kultūras pils sporta zāle', $event2->location->name);
        $this->assertEquals('Daugavpils', $event2->location->city);
    }
}
