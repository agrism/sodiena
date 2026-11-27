<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventAction;
use App\Models\EventOccurrence;
use App\Models\EventRedirect;
use App\Models\Location;
use App\Models\OccurrenceSource;
use App\Models\ScrapeLog;
use App\Models\Source;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DbV2SchemaAndModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_event_occurrences_allows_null_and_empty_array_languages_and_handles_relations(): void
    {
        $source = Source::create([
            'name' => 'Test Source',
            'slug' => 'test-source',
            'url' => 'https://example.com',
            'scraper_class' => 'App\\Services\\Scrapers\\Sources\\TestScraper',
            'type' => 'venue',
            'is_active' => true,
        ]);

        $event = Event::create([
            'title' => 'Test Event',
            'source_id' => $source->id,
            'source_slug' => $source->slug,
            'start_at' => now(),
            'status' => 'published',
        ]);

        // 1. Spoken languages = null, subtitle languages = null
        $occNull = EventOccurrence::create([
            'event_id' => $event->id,
            'start_at' => now()->addDay(),
            'status' => 'scheduled',
            'occurrence_fingerprint' => hash('sha256', 'test1'),
            'spoken_languages' => null,
            'subtitle_languages' => null,
            'price_min' => 10.50,
            'price_max' => 25.00,
            'presentation_format' => 'IMAX 3D',
            'auditorium' => '1. IMAX zāle',
        ]);

        $this->assertNull($occNull->spoken_languages);
        $this->assertNull($occNull->subtitle_languages);
        $this->assertEquals('10.50', (string) $occNull->price_min);
        $this->assertEquals('25.00', (string) $occNull->price_max);
        $this->assertEquals($event->id, $occNull->event->id);

        // 2. Spoken languages = [], subtitle languages = ['lv', 'en']
        $occArr = EventOccurrence::create([
            'event_id' => $event->id,
            'start_at' => now()->addDays(2),
            'status' => 'scheduled',
            'occurrence_fingerprint' => hash('sha256', 'test2'),
            'spoken_languages' => [],
            'subtitle_languages' => ['lv', 'en'],
        ]);

        $this->assertIsArray($occArr->spoken_languages);
        $this->assertEmpty($occArr->spoken_languages);
        $this->assertEquals(['lv', 'en'], $occArr->subtitle_languages);

        // 3. Soft deletes
        $occArr->delete();
        $this->assertSoftDeleted('event_occurrences', ['id' => $occArr->id]);
        $this->assertEquals(1, EventOccurrence::count());
        $this->assertEquals(2, EventOccurrence::withTrashed()->count());
    }

    public function test_occurrence_sources_enforces_unique_source_and_external_id(): void
    {
        $source = Source::create([
            'name' => 'Source 1',
            'slug' => 'src-1',
            'url' => 'https://example.com/src1',
            'scraper_class' => 'App\\Services\\Scrapers\\Sources\\TestScraper',
            'type' => 'ticketing',
            'is_active' => true,
        ]);

        $event = Event::create([
            'title' => 'Event 1',
            'source_id' => $source->id,
            'source_slug' => $source->slug,
            'start_at' => now(),
            'status' => 'published',
        ]);

        $occ = EventOccurrence::create([
            'event_id' => $event->id,
            'start_at' => now()->addDay(),
            'status' => 'scheduled',
            'occurrence_fingerprint' => hash('sha256', 'test_occ'),
        ]);

        $occSource = OccurrenceSource::create([
            'event_occurrence_id' => $occ->id,
            'source_id' => $source->id,
            'source_external_id' => 'ext_12345',
            'price_min' => 15.00,
            'price_max' => 20.00,
            'is_missing' => false,
            'raw_categories' => ['Koncerts', 'Roks'],
            'raw_payload' => ['venue' => 'Arēna Rīga'],
        ]);

        $this->assertEquals($occ->id, $occSource->occurrence->id);
        $this->assertEquals($source->id, $occSource->source->id);
        $this->assertEquals(['Koncerts', 'Roks'], $occSource->raw_categories);

        // Attempt duplicate insert with same (source_id, source_external_id)
        $this->expectException(QueryException::class);
        OccurrenceSource::create([
            'event_occurrence_id' => $occ->id,
            'source_id' => $source->id,
            'source_external_id' => 'ext_12345',
        ]);
    }

    public function test_event_redirects_enforces_unique_locale_and_old_slug(): void
    {
        $event = Event::create([
            'title' => 'Canonical Event',
            'start_at' => now(),
            'status' => 'published',
        ]);

        $redirect = EventRedirect::create([
            'old_event_id' => 9999,
            'locale' => 'lv',
            'old_slug' => 'vecais-pasakums-123456',
            'target_event_id' => $event->id,
            'reason' => 'merged',
        ]);

        $this->assertEquals($event->id, $redirect->targetEvent->id);
        $this->assertEquals(1, $event->redirects()->count());

        // Attempt duplicate insert with same (locale, old_slug)
        $this->expectException(QueryException::class);
        EventRedirect::create([
            'locale' => 'lv',
            'old_slug' => 'vecais-pasakums-123456',
            'target_event_id' => $event->id,
            'reason' => 'migrated',
        ]);
    }

    public function test_events_and_occurrences_reject_self_merge_at_model_level(): void
    {
        $event = Event::create([
            'title' => 'Sample Event',
            'start_at' => now(),
            'status' => 'published',
        ]);

        $occ = EventOccurrence::create([
            'event_id' => $event->id,
            'start_at' => now()->addDay(),
            'status' => 'scheduled',
            'occurrence_fingerprint' => hash('sha256', 'sample'),
        ]);

        // Event self-merge rejection
        try {
            $event->merged_into_id = $event->id;
            $event->save();
            $this->fail('Expected InvalidArgumentException for Event self-merge.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot be merged into itself', $e->getMessage());
        }

        // EventOccurrence self-merge rejection
        try {
            $occ->merged_into_id = $occ->id;
            $occ->save();
            $this->fail('Expected InvalidArgumentException for EventOccurrence self-merge.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot be merged into itself', $e->getMessage());
        }
    }

    public function test_events_v2_columns_softening_and_relations(): void
    {
        $source = Source::create([
            'name' => 'Venue Source',
            'slug' => 'venue-src',
            'url' => 'https://example.com/venue',
            'scraper_class' => 'App\\Services\\Scrapers\\Sources\\TestScraper',
            'type' => 'venue',
            'is_active' => true,
            'supports_reconciliation' => true,
            'reconciliation_min_ratio' => 0.75,
            'reconciliation_grace_runs' => 2,
        ]);

        // In v2, canonical events can be created with nullable start_at and fingerprint
        $canonicalEvent = Event::create([
            'title' => 'V2 Canonical Event Without Date Yet',
            'start_at' => null,
            'fingerprint' => null,
            'canonical_fingerprint' => hash('sha256', 'canonical|test'),
            'primary_content_source_id' => $source->id,
            'status' => 'published',
            'next_start_at' => now()->addDay(),
            'last_end_at' => now()->addDays(2),
            'occurrences_count' => 3,
            'raw_categories' => ['Teātris', 'Drāma'],
            'content_hash' => hash('sha256', 'content_v2'),
        ]);

        $this->assertNull($canonicalEvent->start_at);
        $this->assertNotNull($canonicalEvent->canonical_fingerprint);
        $this->assertEquals($source->id, $canonicalEvent->primaryContentSource->id);
        $this->assertEquals(3, $canonicalEvent->occurrences_count);
        $this->assertEquals(['Teātris', 'Drāma'], $canonicalEvent->raw_categories);

        // Occurrence relation test
        $occ1 = EventOccurrence::create([
            'event_id' => $canonicalEvent->id,
            'start_at' => now()->addDay(),
            'status' => 'scheduled',
            'occurrence_fingerprint' => hash('sha256', 'c_occ1'),
        ]);
        $occ2 = EventOccurrence::create([
            'event_id' => $canonicalEvent->id,
            'start_at' => now()->addDays(2),
            'status' => 'cancelled',
            'occurrence_fingerprint' => hash('sha256', 'c_occ2'),
        ]);

        $this->assertCount(2, $canonicalEvent->occurrences);
        $this->assertCount(1, $canonicalEvent->activeOccurrences);
        $this->assertEquals($occ1->id, $canonicalEvent->activeOccurrences->first()->id);
    }

    public function test_scrape_logs_and_event_actions_v2_columns_and_relations(): void
    {
        $source = Source::create([
            'name' => 'Log Source',
            'slug' => 'log-src',
            'url' => 'https://example.com/log',
            'scraper_class' => 'App\\Services\\Scrapers\\Sources\\TestScraper',
            'type' => 'municipality',
            'is_active' => true,
        ]);

        $log = ScrapeLog::create([
            'source_id' => $source->id,
            'status' => 'success',
            'items_found' => 50,
            'items_created' => 10,
            'items_updated' => 35,
            'items_deduplicated' => 5,
            'items_merged' => 2,
            'items_cancelled' => 3,
            'reconciliation_status' => 'completed',
            'summary_meta' => ['active_count' => 45, 'circuit_breaker' => 'passed'],
            'duration_seconds' => 4.5,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);

        $this->assertEquals(2, $log->items_merged);
        $this->assertEquals(3, $log->items_cancelled);
        $this->assertEquals('completed', $log->reconciliation_status);
        $this->assertEquals('passed', $log->summary_meta['circuit_breaker']);

        $event = Event::create([
            'title' => 'Tracked Event',
            'start_at' => now(),
            'status' => 'published',
        ]);

        $occ = EventOccurrence::create([
            'event_id' => $event->id,
            'start_at' => now()->addDay(),
            'status' => 'scheduled',
            'occurrence_fingerprint' => hash('sha256', 'occ_track'),
        ]);

        $action = EventAction::create([
            'event_id' => $event->id,
            'event_occurrence_id' => $occ->id,
            'action_type' => 'ticket_click',
            'url' => 'https://example.com/tickets',
            'created_at' => now(),
        ]);

        $this->assertEquals($event->id, $action->event->id);
        $this->assertEquals($occ->id, $action->occurrence->id);
        $this->assertCount(1, $occ->actions);
    }

    public function test_mysql_check_constraints_when_running_on_mysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-specific CHECK constraints are only validated on MySQL connections.');
        }

        // Test check_eo_status
        $this->expectException(QueryException::class);
        DB::statement("INSERT INTO event_occurrences (event_id, start_at, status, occurrence_fingerprint, created_at, updated_at) VALUES (1, NOW(), 'invalid_status', 'fp1', NOW(), NOW())");
    }
}
