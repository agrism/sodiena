<?php

namespace Tests\Feature\Console;

use App\Enums\SourceType;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\OccurrenceSource;
use App\Models\Source;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NormalizeTicketProvidersCommandTest extends TestCase
{
    use RefreshDatabase;

    private Source $afiroSource;
    private Source $venueSource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->afiroSource = Source::create([
            'name' => 'Afiro Pasākumu API',
            'slug' => 'afiro-api',
            'url' => 'https://api.afiro.eu',
            'scraper_class' => \App\Services\Scrapers\Sources\AfiroApiScraper::class,
            'type' => SourceType::AGGREGATOR,
            'is_active' => true,
        ]);

        $this->venueSource = Source::create([
            'name' => 'Dailes teātris',
            'slug' => 'dailes-teatris',
            'url' => 'https://www.dailesteatris.lv',
            'scraper_class' => \App\Services\Scrapers\Sources\ForumCinemasScraper::class,
            'type' => SourceType::VENUE,
            'is_active' => true,
        ]);
    }

    public function test_dry_run_reports_changes_without_mutating_database(): void
    {
        $event = Event::create([
            'title' => 'Nākotnes pasākums',
            'slug' => 'nakotnes-pasakums-' . uniqid(),
            'start_at' => Carbon::now()->addDays(3),
            'end_at' => Carbon::now()->addDays(3)->addHours(2),
            'source_id' => $this->afiroSource->id,
            'source_slug' => $this->afiroSource->slug,
            'status' => 'published',
            'updated_at' => Carbon::now()->subDays(5),
        ]);

        $occurrence = EventOccurrence::create([
            'event_id' => $event->id,
            'occurrence_fingerprint' => hash('sha256', 'occ_1'),
            'start_at' => Carbon::now()->addDays(3),
            'end_at' => Carbon::now()->addDays(3)->addHours(2),
            'status' => 'scheduled',
        ]);

        $occSource = OccurrenceSource::create([
            'event_occurrence_id' => $occurrence->id,
            'source_id' => $this->afiroSource->id,
            'source_external_id' => 'afiro_101',
            'ticket_url' => 'https://www.bilesuparadize.lv/lv/event/99999',
            'provider_name' => 'Afiro Pasākumu API',
        ]);

        $originalUpdatedAt = $event->fresh()->updated_at;

        $this->artisan('tickets:normalize-providers', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('DRY-RUN (tikai parādīt, bez saglabāšanas)')
            ->expectsOutputToContain('Biļešu Paradīze');

        $this->assertEquals('Afiro Pasākumu API', $occSource->fresh()->provider_name);
        $this->assertEquals($originalUpdatedAt->toDateTimeString(), $event->fresh()->updated_at->toDateTimeString());
    }

    public function test_live_run_normalizes_afiro_to_merchant_and_touches_event(): void
    {
        $pastDate = Carbon::now()->subDays(2);
        $event = Event::create([
            'title' => 'Simfoniskais koncerts',
            'slug' => 'simfoniskais-koncerts-' . uniqid(),
            'start_at' => Carbon::now()->addDays(5),
            'end_at' => Carbon::now()->addDays(5)->addHours(2),
            'source_id' => $this->afiroSource->id,
            'source_slug' => $this->afiroSource->slug,
            'status' => 'published',
            'updated_at' => $pastDate,
        ]);

        $occurrence = EventOccurrence::create([
            'event_id' => $event->id,
            'occurrence_fingerprint' => hash('sha256', 'occ_2'),
            'start_at' => Carbon::now()->addDays(5),
            'end_at' => Carbon::now()->addDays(5)->addHours(2),
            'status' => 'scheduled',
        ]);

        $occSourceMerchant = OccurrenceSource::create([
            'event_occurrence_id' => $occurrence->id,
            'source_id' => $this->afiroSource->id,
            'source_external_id' => 'afiro_102',
            'ticket_url' => 'https://bilesuserviss.lv/lat/biletes/12345',
            'provider_name' => 'Afiro Pasākumu API',
        ]);

        $occSourceUnknown = OccurrenceSource::create([
            'event_occurrence_id' => $occurrence->id,
            'source_id' => $this->afiroSource->id,
            'source_external_id' => 'afiro_103',
            'ticket_url' => 'https://mans-privatais-pasakums.lv/bilete',
            'provider_name' => 'Afiro Pasākumu API',
        ]);

        $this->artisan('tickets:normalize-providers')
            ->assertSuccessful();

        $this->assertEquals('Biļešu Serviss', $occSourceMerchant->fresh()->provider_name);
        $this->assertNull($occSourceUnknown->fresh()->provider_name);

        // Verify event updated_at was touched
        $this->assertTrue($event->fresh()->updated_at->gt($pastDate));
    }

    public function test_past_events_are_skipped_by_default(): void
    {
        $pastTime = Carbon::now()->subDays(10);
        $pastEvent = new Event([
            'title' => 'Vēsturisks pasākums',
            'slug' => 'vesturisks-pasakums-' . uniqid(),
            'start_at' => $pastTime,
            'end_at' => $pastTime->copy()->addHours(2),
            'source_id' => $this->afiroSource->id,
            'source_slug' => $this->afiroSource->slug,
            'status' => 'published',
        ]);
        $pastEvent->timestamps = false;
        $pastEvent->created_at = $pastTime;
        $pastEvent->updated_at = $pastTime;
        $pastEvent->save();

        $pastOccurrence = EventOccurrence::create([
            'event_id' => $pastEvent->id,
            'occurrence_fingerprint' => hash('sha256', 'occ_past'),
            'start_at' => $pastTime,
            'end_at' => $pastTime->copy()->addHours(2),
            'status' => 'completed',
        ]);

        $pastOccSource = OccurrenceSource::create([
            'event_occurrence_id' => $pastOccurrence->id,
            'source_id' => $this->afiroSource->id,
            'source_external_id' => 'afiro_past',
            'ticket_url' => 'https://www.bilesuparadize.lv/lv/event/past',
            'provider_name' => 'Afiro Pasākumu API',
        ]);

        $this->artisan('tickets:normalize-providers')
            ->assertSuccessful();

        // Past event should NOT have been touched
        $this->assertEquals('Afiro Pasākumu API', $pastOccSource->fresh()->provider_name);
        $this->assertEquals($pastTime->toDateTimeString(), $pastEvent->fresh()->updated_at->toDateTimeString());
    }

    public function test_event_raw_data_ticket_links_are_cleaned(): void
    {
        $event = Event::create([
            'title' => 'Pasākums ar vecajiem raw_data linkiem',
            'slug' => 'pasakums-ar-raw-' . uniqid(),
            'start_at' => Carbon::now()->addDays(2),
            'end_at' => Carbon::now()->addDays(2)->addHours(2),
            'source_id' => $this->afiroSource->id,
            'source_slug' => $this->afiroSource->slug,
            'status' => 'published',
            'raw_data' => [
                'ticket_links' => [
                    [
                        'url' => 'https://www.bilesuparadize.lv/lv/event/111',
                        'title' => 'Afiro Pasākumu API',
                        'provider_name' => 'Afiro Pasākumu API',
                    ],
                    [
                        'url' => 'https://nezinams-domens.lv/pirkt',
                        'title' => 'Afiro Pasākumu API',
                        'provider_name' => 'Afiro Pasākumu API',
                    ],
                ],
            ],
        ]);

        $this->artisan('tickets:normalize-providers')
            ->assertSuccessful();

        $freshEvent = $event->fresh();
        $cleanedLinks = $freshEvent->raw_data['ticket_links'];

        $this->assertEquals('Biļešu Paradīze', $cleanedLinks[0]['provider_name']);
        $this->assertEquals('Biļešu Paradīze', $cleanedLinks[0]['title']);

        $this->assertNull($cleanedLinks[1]['provider_name']);
        $this->assertEquals('Biļetes', $cleanedLinks[1]['title']);
    }
}
