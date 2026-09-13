<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\BilesuServissScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BilesuServissScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_bilesu_serviss_scraper_metadata(): void
    {
        $scraper = new BilesuServissScraper();
        $this->assertEquals('bilesu-serviss', $scraper->getSlug());
        $this->assertEquals('Biļešu Serviss', $scraper->getName());
    }

    public function test_bilesu_serviss_parse_item_success(): void
    {
        $scraper = new BilesuServissScraper();

        $sampleItem = [
            'id' => 'GTD3BDTO36',
            'seriesId' => 'WLNO5SP4WP',
            'status' => 'ON_SALE',
            'name' => 'Art&Wine radošā meistarklase: Interjera gleznas',
            'sluggedName' => 'art-wine-radosa-meistarklase-interjera-gleznas',
            'imageData' => [
                [
                    'type' => 'DESKTOP',
                    'imageId' => 'c6b16923-491a-4001-ae7a-acab91dcd779'
                ]
            ],
            'eventStartAt' => '2026-09-18T16:00:00Z',
            'eventEndAt' => '2026-09-18T19:00:00Z',
            'publishedAt' => '2026-08-24T12:41:19.024Z',
            'promoter' => [
                'id' => '21-69-6310',
                'companyName' => 'Studija Art and Wine SIA',
            ],
            'categories' => [
                [
                    'key' => 'workshops',
                    'name' => 'Meistarklases',
                    'attachedAs' => 'MAIN'
                ]
            ],
            'venue' => [
                'name' => 'Art and Wine studija',
                'nameOverride' => 'Art and Wine studija',
                'country' => 'LV',
                'city' => 'Rīga',
            ]
        ];

        $dto = $scraper->parseEventItem($sampleItem);

        $this->assertNotNull($dto);
        $this->assertEquals('Art&Wine radošā meistarklase: Interjera gleznas', $dto->title);
        $this->assertEquals('Art and Wine studija', $dto->venueName);
        $this->assertEquals('Rīga', $dto->city);
        $this->assertEquals('bilesuserviss-GTD3BDTO36', $dto->sourceExternalId);
        $this->assertEquals('https://www.bilesuserviss.lv/biletes/GTD3BDTO36/art-wine-radosa-meistarklase-interjera-gleznas', $dto->ticketUrl);
        $this->assertEquals('https://www.bilesuserviss.lv/i/height=600/images/c6b16923-491a-4001-ae7a-acab91dcd779', $dto->imageUrl);
        $this->assertContains('Meistarklases', $dto->categoryNames);
        $this->assertStringContainsString('Studija Art and Wine SIA', $dto->description);
    }

    public function test_bilesu_serviss_skips_cancelled_events(): void
    {
        $scraper = new BilesuServissScraper();

        $item = [
            'id' => 'CANCELLED123',
            'status' => 'CANCELLED',
            'name' => 'Atcelts pasākums',
            'eventStartAt' => '2026-09-20T18:00:00Z',
            'venue' => ['country' => 'LV', 'city' => 'Rīga']
        ];

        $dto = $scraper->parseEventItem($item);
        $this->assertNull($dto);
    }

    public function test_bilesu_serviss_skips_foreign_countries(): void
    {
        $scraper = new BilesuServissScraper();

        $item = [
            'id' => 'ESTONIA123',
            'status' => 'ON_SALE',
            'name' => 'Tallinn Concert',
            'eventStartAt' => '2026-09-20T18:00:00Z',
            'venue' => ['country' => 'EE', 'city' => 'Tallinn']
        ];

        $dto = $scraper->parseEventItem($item);
        $this->assertNull($dto);
    }

    public function test_bilesu_serviss_event_ingestion(): void
    {
        $source = Source::create([
            'name' => 'Biļešu Serviss',
            'slug' => 'bilesu-serviss',
            'url' => 'https://www.bilesuserviss.lv/biletes/visi',
            'scraper_class' => BilesuServissScraper::class,
            'is_active' => true,
        ]);

        $scraper = new BilesuServissScraper();
        $sampleItem = [
            'id' => 'TESTBS999',
            'status' => 'ON_SALE',
            'name' => 'Simfoniskais orķestris Doma baznīcā',
            'sluggedName' => 'simfoniskais-orkestris-doma-baznica',
            'imageData' => [
                ['type' => 'DESKTOP', 'imageId' => 'test-img-uuid-123']
            ],
            'eventStartAt' => '2026-09-25T19:00:00Z',
            'promoter' => ['companyName' => 'Latvijas Koncerti VSIA'],
            'categories' => [['name' => 'Mūzika']],
            'venue' => [
                'name' => 'Rīgas Doms',
                'country' => 'LV',
                'city' => 'Rīga',
            ]
        ];

        $dto = $scraper->parseEventItem($sampleItem);
        $this->assertNotNull($dto);

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_external_id', 'bilesuserviss-TESTBS999')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Simfoniskais orķestris Doma baznīcā', $event->title);
        $this->assertEquals('bilesuserviss.lv', $event->origin_host);
        $this->assertEquals('https://www.bilesuserviss.lv/biletes/TESTBS999/simfoniskais-orkestris-doma-baznica', $event->ticket_url);
        $this->assertEquals('Rīgas Doms', $event->location->name);
        $this->assertEquals('Rīga', $event->location->city);
    }
}
