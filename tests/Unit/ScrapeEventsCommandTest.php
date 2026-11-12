<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\Contracts\EventScraperInterface;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class CrashingScraperTestStub implements EventScraperInterface
{
    public function getSlug(): string
    {
        return 'crashing-stub';
    }

    public function getName(): string
    {
        return 'Crashing Stub';
    }

    public function scrape(Source $source): Collection
    {
        throw new \RuntimeException('Simulated catastrophic fatal crash during scraping!');
    }
}

class SuccessfulScraperTestStub implements EventScraperInterface
{
    public function getSlug(): string
    {
        return 'successful-stub';
    }

    public function getName(): string
    {
        return 'Successful Stub';
    }

    public function scrape(Source $source): Collection
    {
        return collect([
            new ScrapedEventDTO(
                title: 'Veiksmīgs pasākums no avota B',
                startAt: Carbon::now('Europe/Riga')->addDays(2),
                endAt: Carbon::now('Europe/Riga')->addDays(2)->addHours(2),
                description: 'Apraksts veiksmīgam pasākumam',
                shortDescription: 'Īss apraksts',
                venueName: 'Test Hall',
                city: 'Rīga',
                region: 'Rīga un Pierīga',
                address: 'Brīvības iela 1',
                latitude: 56.95,
                longitude: 24.11,
                placeType: 'venue',
                categoryNames: ['Sports'],
                entertainmentType: 'sports',
                isFree: true,
                priceMin: null,
                priceMax: null,
                imageUrl: 'https://example.com/image.jpg',
                sourceUrl: 'https://example.com/event',
                sourceExternalId: 'stub_b_1',
                locale: 'lv'
            )
        ]);
    }
}

class ScrapeEventsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_continues_to_next_source_when_one_source_crashes(): void
    {
        Category::updateOrCreate(['slug' => 'sports'], ['name' => 'Sports']);

        $sourceA = Source::create([
            'name' => 'Source A (Crashing)',
            'slug' => 'source-a',
            'url' => 'https://crash-a.example.com',
            'scraper_class' => CrashingScraperTestStub::class,
            'is_active' => true,
        ]);

        $sourceB = Source::create([
            'name' => 'Source B (Successful)',
            'slug' => 'source-b',
            'url' => 'https://success-b.example.com',
            'scraper_class' => SuccessfulScraperTestStub::class,
            'is_active' => true,
        ]);

        $this->artisan('events:scrape', ['--all' => true])
            ->expectsOutputToContain('Source A (Crashing)')
            ->expectsOutputToContain('Source B (Successful)')
            ->expectsOutputToContain('PABEIGTS')
            ->assertExitCode(0);

        // Verify Source A was marked as failed
        $this->assertEquals('failed', $sourceA->fresh()->last_status);

        // Verify Source B succeeded and ingested the event despite Source A crashing right before it
        $this->assertEquals('success', $sourceB->fresh()->last_status);
        $this->assertEquals(1, Event::where('source_id', $sourceB->id)->count());
        $this->assertEquals('Veiksmīgs pasākums no avota B', Event::where('source_id', $sourceB->id)->first()->title);
    }
}

