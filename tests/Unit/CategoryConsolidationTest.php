<?php

namespace Tests\Unit;

use App\Console\Commands\ConsolidateCategoriesCommand;
use App\Models\Category;
use App\Models\Event;
use App\Models\Location;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryConsolidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mapping_to_canonical_slugs(): void
    {
        $this->assertEquals('kino', ConsolidateCategoriesCommand::mapToCanonicalSlug('Filmas & Kino'));
        $this->assertEquals('kino', ConsolidateCategoriesCommand::mapToCanonicalSlug('Kino'));
        $this->assertEquals('kino', ConsolidateCategoriesCommand::mapToCanonicalSlug('Movies & Cinema'));

        $this->assertEquals('teatris', ConsolidateCategoriesCommand::mapToCanonicalSlug('Teātris'));
        $this->assertEquals('teatris', ConsolidateCategoriesCommand::mapToCanonicalSlug('Teātris & Izrādes'));
        $this->assertEquals('teatris', ConsolidateCategoriesCommand::mapToCanonicalSlug('Stand-up comedy'));
        $this->assertEquals('teatris', ConsolidateCategoriesCommand::mapToCanonicalSlug('Opera un balets'));

        $this->assertEquals('muzika', ConsolidateCategoriesCommand::mapToCanonicalSlug('Mūzika & Koncerti'));
        $this->assertEquals('muzika', ConsolidateCategoriesCommand::mapToCanonicalSlug('Mūzika'));
        $this->assertEquals('muzika', ConsolidateCategoriesCommand::mapToCanonicalSlug('Klasiskā mūzika'));
        $this->assertEquals('muzika', ConsolidateCategoriesCommand::mapToCanonicalSlug('Latviešu Mūzika'));

        $this->assertEquals('izstades', ConsolidateCategoriesCommand::mapToCanonicalSlug('Kultūra & Māksla'));
        $this->assertEquals('izstades', ConsolidateCategoriesCommand::mapToCanonicalSlug('Izstādes & Māksla'));
        $this->assertEquals('izstades', ConsolidateCategoriesCommand::mapToCanonicalSlug('Muzeju ekspozīcijas'));

        $this->assertEquals('berniem', ConsolidateCategoriesCommand::mapToCanonicalSlug('Ģimenēm & Bērniem'));
        $this->assertEquals('berniem', ConsolidateCategoriesCommand::mapToCanonicalSlug('Bērniem & Ģimenei'));

        $this->assertEquals('sports', ConsolidateCategoriesCommand::mapToCanonicalSlug('Sports & Aktīvā atpūta'));
        $this->assertEquals('sports', ConsolidateCategoriesCommand::mapToCanonicalSlug('Daba & Pārgājieni'));
        $this->assertEquals('sports', ConsolidateCategoriesCommand::mapToCanonicalSlug('Velobrauciens un skriešana'));

        $this->assertEquals('seminari', ConsolidateCategoriesCommand::mapToCanonicalSlug('Izglītība & Semināri'));
        $this->assertEquals('seminari', ConsolidateCategoriesCommand::mapToCanonicalSlug('Semināri & Meistarklases'));
        $this->assertEquals('seminari', ConsolidateCategoriesCommand::mapToCanonicalSlug('Bizness & Izglītība'));

        $this->assertEquals('svetki', ConsolidateCategoriesCommand::mapToCanonicalSlug('Festivāli & Svētki'));
        $this->assertEquals('svetki', ConsolidateCategoriesCommand::mapToCanonicalSlug('Gastronomija & Tirgi'));
        $this->assertEquals('svetki', ConsolidateCategoriesCommand::mapToCanonicalSlug('Naktsdzīve & Ballītes'));

        $this->assertEquals('citi', ConsolidateCategoriesCommand::mapToCanonicalSlug('Pilnīgi nezināma lieta 12345'));
    }

    public function test_categories_consolidate_artisan_command(): void
    {
        // Create obsolete category
        $oldCat = Category::create([
            'name' => 'Kultūra & Tradīcijas',
            'slug' => 'kultura-tradicijas',
            'icon' => 'tag',
            'color' => 'slate',
        ]);

        $source = Source::create([
            'name' => 'Test Source',
            'slug' => 'test-source',
            'url' => 'https://example.com',
            'scraper_class' => \App\Services\Scrapers\BezRindasScraper::class,
            'is_active' => true,
        ]);

        $location = Location::create([
            'name' => 'Test Vieta',
            'city' => 'Rīga',
            'region' => 'Rīga',
            'address' => 'Brīvības iela 1',
        ]);

        $event = Event::create([
            'source_id' => $source->id,
            'source_slug' => $source->slug,
            'location_id' => $location->id,
            'title' => 'Mākslas izstāde Rīgā',
            'slug' => 'makslas-izstade-riga',
            'start_at' => now()->addDays(2),
            'status' => 'published',
            'published_at' => now(),
        ]);

        $event->categories()->attach($oldCat->id);

        $this->assertEquals(1, $oldCat->events()->count());

        // Run consolidation command
        $this->artisan('categories:consolidate')->assertSuccessful();

        // Verify only 9 canonical categories exist
        $this->assertEquals(9, Category::count());
        $this->assertDatabaseMissing('categories', ['slug' => 'kultura-tradicijas']);
        $this->assertDatabaseHas('categories', ['slug' => 'izstades', 'name' => 'Izstādes']);
        $this->assertDatabaseHas('categories', ['slug' => 'kino', 'name' => 'Kino']);
        $this->assertDatabaseHas('categories', ['slug' => 'teatris', 'name' => 'Teātris']);
        $this->assertDatabaseHas('categories', ['slug' => 'citi', 'name' => 'Cits']);

        // Verify event was remapped to 'izstades'
        $izstadesCat = Category::where('slug', 'izstades')->first();
        $this->assertTrue($event->fresh()->categories->contains('id', $izstadesCat->id));
    }

    public function test_ingestion_service_maps_new_categories_strictly_to_canonical(): void
    {
        $service = app(EventIngestionService::class);

        $source = Source::create([
            'name' => 'Test Ingestion Source',
            'slug' => 'test-ingestion-source',
            'url' => 'https://example.com',
            'scraper_class' => \App\Services\Scrapers\BezRindasScraper::class,
            'is_active' => true,
        ]);

        $dto = new ScrapedEventDTO(
            title: 'Jauns kino vakars',
            description: 'Kino seanss brīvā dabā',
            startAt: now()->addDays(3),
            endAt: null,
            isFree: true,
            priceMin: 0,
            priceMax: 0,
            currency: 'EUR',
            categoryNames: ['Kino un filmas jaunumi'],
            venueName: 'Kino Teātris Splendid',
            address: 'Elizabetes iela 61',
            city: 'Rīga',
            imageUrl: null,
            ticketUrl: null,
            sourceUrl: 'https://example.com/event-1',
            sourceExternalId: 'ext-123'
        );

        $service->ingestDTO($dto, $source);

        $dtoUnmapped = new ScrapedEventDTO(
            title: 'Neatpazīts notikums',
            description: 'Dažāds apraksts',
            startAt: now()->addDays(4),
            endAt: null,
            isFree: true,
            priceMin: 0,
            priceMax: 0,
            currency: 'EUR',
            categoryNames: ['Neatpazīts žanrs xyz'],
            venueName: 'Kaut kur',
            address: 'Brīvības 1',
            city: 'Rīga',
            imageUrl: null,
            ticketUrl: null,
            sourceUrl: 'https://example.com/event-2',
            sourceExternalId: 'ext-456'
        );

        $service->ingestDTO($dtoUnmapped, $source);

        $event = Event::where('source_external_id', 'ext-123')->first();
        $this->assertNotNull($event);
        $categorySlugs = $event->categories->pluck('slug')->toArray();
        $this->assertContains('kino', $categorySlugs);
        $this->assertNotContains('citi', $categorySlugs);

        $eventUnmapped = Event::where('source_external_id', 'ext-456')->first();
        $this->assertNotNull($eventUnmapped);
        $unmappedCategorySlugs = $eventUnmapped->categories->pluck('slug')->toArray();
        $this->assertContains('citi', $unmappedCategorySlugs);

        // No random custom category slug should exist in categories table
        $this->assertDatabaseMissing('categories', ['slug' => 'kino-un-filmas-jaunumi']);
        $this->assertDatabaseMissing('categories', ['slug' => 'neatpazits-zanrs-xyz']);
    }
}
