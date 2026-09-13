<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Location;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fuzzy_location_matching_in_ingestion(): void
    {
        $source = Source::create([
            'name' => 'Test Source',
            'slug' => 'test-source',
            'url' => 'https://example.com',
            'scraper_class' => 'App\Services\Scrapers\Sources\DaugavpilsScraper',
            'is_active' => true,
        ]);

        $ingestionService = app(EventIngestionService::class);

        // First event with full name
        $dto1 = new ScrapedEventDTO(
            title: 'Pasākums Tradīciju mājā 1',
            startAt: Carbon::parse('2026-10-01 18:00:00'),
            venueName: 'Daugavpils Vienības nama Tradīciju māja',
            city: 'Daugavpils',
            address: 'Rīgas iela 22a, Daugavpils',
        );
        $ingestionService->ingestDTO($dto1, $source);

        $this->assertEquals(1, Location::count());
        $loc1 = Location::first();
        $this->assertEquals('Daugavpils Vienības nama Tradīciju māja', $loc1->name);

        // Second event with short / substring name in same city
        $dto2 = new ScrapedEventDTO(
            title: 'Pasākums Tradīciju mājā 2',
            startAt: Carbon::parse('2026-10-02 18:00:00'),
            venueName: 'Tradīciju māja',
            city: 'Daugavpils',
            address: 'Rīgas iela 22a',
        );
        $ingestionService->ingestDTO($dto2, $source);

        // Must still be only 1 location in database!
        $this->assertEquals(1, Location::count());
        $this->assertEquals(2, Event::where('location_id', $loc1->id)->count());

        // Third event with hall suffix (e.g. "Lielā zāle")
        $dto3 = new ScrapedEventDTO(
            title: 'Pasākums Lielajā zālē',
            startAt: Carbon::parse('2026-10-03 18:00:00'),
            venueName: 'Daugavpils Vienības nama Tradīciju māja, Lielā zāle',
            city: 'Daugavpils',
        );
        $ingestionService->ingestDTO($dto3, $source);

        // Must still match the same parent location!
        $this->assertEquals(1, Location::count());
        $this->assertEquals(3, Event::where('location_id', $loc1->id)->count());
    }

    public function test_consolidate_locations_artisan_command(): void
    {
        $loc1 = Location::create([
            'name' => 'Daugavpils Olimpiskais centrs',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Stadiona iela 1, Daugavpils',
        ]);

        $loc2 = Location::create([
            'name' => 'Daugavpils Olimpiskais centrs (Stadiona iela 1)',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Stadiona iela 1',
        ]);

        $this->artisan('locations:consolidate')
            ->assertExitCode(0);

        $this->assertEquals(1, Location::where('city', 'Daugavpils')->count());
        $surviving = Location::where('city', 'Daugavpils')->first();
        $this->assertEquals('Daugavpils Olimpiskais centrs', $surviving->name);
    }
}
