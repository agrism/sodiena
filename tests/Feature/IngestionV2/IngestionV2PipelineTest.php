<?php

namespace Tests\Feature\IngestionV2;

use App\Enums\SourceType;
use App\Models\Event;
use App\Models\EventAction;
use App\Models\EventOccurrence;
use App\Models\EventRedirect;
use App\Models\Location;
use App\Models\OccurrenceSource;
use App\Models\ScrapeLog;
use App\Models\Source;
use App\Services\IngestionV2\CanonicalProjectionService;
use App\Services\IngestionV2\EventIngestionV2Service;
use App\Services\IngestionV2\EventMergeService;
use App\Services\IngestionV2\SourceReconciliationService;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class IngestionV2PipelineTest extends TestCase
{
    use RefreshDatabase;

    protected EventIngestionV2Service $ingestionV2;
    protected CanonicalProjectionService $projection;
    protected EventMergeService $mergeService;
    protected SourceReconciliationService $reconciliation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projection = app(CanonicalProjectionService::class);
        $this->mergeService = app(EventMergeService::class);
        $this->reconciliation = app(SourceReconciliationService::class);
        $this->ingestionV2 = app(EventIngestionV2Service::class);

        config(['sodiena.ingestion_v2_enabled' => true]);
    }

    protected function createTestSource(string $slug, string $type = 'venue', array $attrs = []): Source
    {
        return Source::create(array_merge([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'url' => 'https://' . $slug . '.lv',
            'scraper_class' => 'App\\Services\\Scrapers\\Sources\\TestScraper',
            'type' => $type,
            'is_active' => true,
        ], $attrs));
    }

    protected function createTestScrapeLog(Source $source): ScrapeLog
    {
        return ScrapeLog::create([
            'source_id' => $source->id,
            'status' => 'started',
            'items_found' => 0,
            'items_created' => 0,
            'items_updated' => 0,
            'items_deduplicated' => 0,
            'items_cancelled' => 0,
            'duration_seconds' => 0,
            'started_at' => now(),
        ]);
    }

    /**
     * Test 1: Multi-session cinema grouping + venue isolation.
     */
    public function test_multi_session_cinema_grouping_and_venue_isolation(): void
    {
        $apolloSource = $this->createTestSource('apollo-kino', 'venue');
        $forumSource = $this->createTestSource('forum-cinemas', 'venue');

        $logApollo = $this->createTestScrapeLog($apolloSource);
        $logForum = $this->createTestScrapeLog($forumSource);

        $date = Carbon::now()->addDays(3)->setTime(14, 0);

        // 3 sessions for "Kāpa: Otrā daļa" at Apollo Kino Akropole
        $dto1 = new ScrapedEventDTO(
            title: 'Kāpa: Otrā daļa 2D',
            description: 'Zinātniskās fantastikas filma.',
            startAt: $date->copy()->setTime(14, 0),
            endAt: $date->copy()->setTime(16, 45),
            venueName: 'Apollo Kino Akropole Rīga',
            city: 'Rīga',
            ticketUrl: 'https://apollokino.lv/tickets/101',
            categoryNames: ['Kino'],
            sourceExternalId: 'apollo_101',
            rawData: ['presentation_method' => '2D', 'cinema' => ['auditorium' => '3. zāle']]
        );

        $dto2 = new ScrapedEventDTO(
            title: 'Kāpa: Otrā daļa IMAX 2D',
            description: 'Zinātniskās fantastikas filma IMAX formātā.',
            startAt: $date->copy()->setTime(19, 0),
            endAt: $date->copy()->setTime(21, 45),
            venueName: 'Apollo Kino Akropole Rīga',
            city: 'Rīga',
            ticketUrl: 'https://apollokino.lv/tickets/102',
            categoryNames: ['Kino'],
            sourceExternalId: 'apollo_102',
            rawData: ['presentation_method' => 'IMAX 2D', 'cinema' => ['auditorium' => '1. IMAX zāle']]
        );

        $dto3 = new ScrapedEventDTO(
            title: 'Kāpa: Otrā daļa 3D',
            description: 'Zinātniskās fantastikas filma 3D formātā.',
            startAt: $date->copy()->setTime(21, 30),
            endAt: $date->copy()->setTime(23, 55),
            venueName: 'Apollo Kino Akropole Rīga',
            city: 'Rīga',
            ticketUrl: 'https://apollokino.lv/tickets/103',
            categoryNames: ['Kino'],
            sourceExternalId: 'apollo_103',
            rawData: ['presentation_method' => '3D', 'cinema' => ['auditorium' => '2. zāle']]
        );

        $this->ingestionV2->ingestCollection(collect([$dto1, $dto2, $dto3]), $apolloSource, $logApollo, false);

        // Apollo checks: exactly 1 Event, 3 occurrences, 3 occurrence_sources
        $events = Event::where('title', 'like', '%Kāpa%')->get();
        $this->assertCount(1, $events, 'Expected 1 canonical Event for Apollo Akropole');

        $apolloEvent = $events->first();
        $this->assertEquals('Kāpa: Otrā daļa', $apolloEvent->title);
        $this->assertEquals(3, $apolloEvent->occurrences()->count());
        $this->assertEquals(3, OccurrenceSource::where('source_id', $apolloSource->id)->count());

        // Now ingest same film at 19:00 at Forum Cinemas
        $dtoForum = new ScrapedEventDTO(
            title: 'Kāpa: Otrā daļa',
            description: 'Forum Cinemas seanss.',
            startAt: $date->copy()->setTime(19, 0),
            endAt: $date->copy()->setTime(21, 45),
            venueName: 'Forum Cinemas / Kino Citadele',
            city: 'Rīga',
            ticketUrl: 'https://forumcinemas.lv/tickets/201',
            categoryNames: ['Kino'],
            sourceExternalId: 'forum_201',
            rawData: ['presentation_method' => '2D', 'cinema' => ['auditorium' => 'Auditorium 1']]
        );

        $this->ingestionV2->ingestCollection(collect([$dtoForum]), $forumSource, $logForum, false);

        // Forum Cinemas must create a SEPARATE 2nd Event because venues are isolated
        $allEvents = Event::where('title', 'like', '%Kāpa%')->get();
        $this->assertCount(2, $allEvents, 'Expected 2 distinct Events for distinct cinema venues');

        $forumEvent = $allEvents->where('id', '!=', $apolloEvent->id)->first();
        $this->assertNotNull($forumEvent);
        $this->assertNotEquals($apolloEvent->location_id, $forumEvent->location_id);
        $this->assertEquals('https://forumcinemas.lv/tickets/201', $forumEvent->ticket_url);
        $this->assertStringContainsString('apollokino.lv', $apolloEvent->ticket_url);
    }

    /**
     * Test 2: Atomic title + description projection (Anti-Frankenstein).
     */
    public function test_atomic_title_and_description_projection_anti_frankenstein(): void
    {
        $aggSource = $this->createTestSource('aggregator-source', 'aggregator');
        $venueSource = $this->createTestSource('venue-source', 'venue');

        $logAgg = $this->createTestScrapeLog($aggSource);
        $logVenue = $this->createTestScrapeLog($venueSource);

        $start = Carbon::now()->addDays(10)->setTime(19, 0);

        // 1. Ingest via Aggregator first
        $dtoAgg = new ScrapedEventDTO(
            title: 'Prāta Vētra - Koncerts Rīgā',
            description: 'Agregatora apraksts A: liels šovs.',
            startAt: $start,
            venueName: 'Arēna Rīga',
            city: 'Rīga',
            ticketUrl: 'https://aggregator.lv/tickets/1',
            categoryNames: ['Mūzika'],
            sourceExternalId: 'agg_pv_1'
        );
        $this->ingestionV2->ingestCollection(collect([$dtoAgg]), $aggSource, $logAgg, false);

        $event = Event::first();
        $this->assertEquals('Prāta Vētra - Koncerts Rīgā', $event->title);
        $this->assertEquals('Agregatora apraksts A: liels šovs.', $event->description);
        $this->assertEquals($aggSource->id, $event->primary_content_source_id);

        // 2. Ingest via higher priority Venue source
        $dtoVenue = new ScrapedEventDTO(
            title: 'Prāta Vētra Live Arēna Rīga',
            description: 'Oficiālais norises vietas apraksts B.',
            startAt: $start,
            venueName: 'Arēna Rīga',
            city: 'Rīga',
            ticketUrl: 'https://arenariga.com/tickets/1',
            categoryNames: ['Mūzika'],
            sourceExternalId: 'venue_pv_1'
        );
        $this->ingestionV2->ingestCollection(collect([$dtoVenue]), $venueSource, $logVenue, false);

        $event->refresh();
        $this->assertEquals(1, Event::count());
        $this->assertEquals('Prāta Vētra Live Arēna Rīga', $event->title);
        $this->assertEquals('Oficiālais norises vietas apraksts B.', $event->description);
        $this->assertEquals($venueSource->id, $event->primary_content_source_id);

        // 3. Mark Venue source as missing and re-project
        $venueOccSource = OccurrenceSource::where('source_id', $venueSource->id)->first();
        $venueOccSource->update(['is_missing' => true]);

        $this->projection->projectEvent($event);
        $event->refresh();

        // Title and description must cleanly revert to Aggregator without Frankenstein blending
        $this->assertEquals('Prāta Vētra - Koncerts Rīgā', $event->title);
        $this->assertEquals('Agregatora apraksts A: liels šovs.', $event->description);
        $this->assertEquals($aggSource->id, $event->primary_content_source_id);
    }

    /**
     * Test 3: Ticket link cleanliness when source is missing.
     */
    public function test_ticket_link_cleanliness_when_source_is_missing(): void
    {
        $srcA = $this->createTestSource('source-a', 'ticketing');
        $srcB = $this->createTestSource('source-b', 'ticketing');

        $logA = $this->createTestScrapeLog($srcA);
        $logB = $this->createTestScrapeLog($srcB);

        $start = Carbon::now()->addDays(5)->setTime(18, 0);

        $dtoA = new ScrapedEventDTO(
            title: 'Simfoniskais koncerts',
            description: 'Apraksts',
            startAt: $start,
            venueName: 'Lielā Ģilde',
            city: 'Rīga',
            ticketUrl: 'https://bilesuparadize.lv/lv/event/1001',
            categoryNames: ['Mūzika'],
            sourceExternalId: 'src_a_1001'
        );

        $dtoB = new ScrapedEventDTO(
            title: 'Simfoniskais koncerts',
            description: 'Apraksts',
            startAt: $start,
            venueName: 'Lielā Ģilde',
            city: 'Rīga',
            ticketUrl: 'https://bilesuserviss.lv/lat/tickets/2002',
            categoryNames: ['Mūzika'],
            sourceExternalId: 'src_b_2002'
        );

        $this->ingestionV2->ingestCollection(collect([$dtoA]), $srcA, $logA, false);
        $this->ingestionV2->ingestCollection(collect([$dtoB]), $srcB, $logB, false);

        $event = Event::first();
        $rawData = $event->raw_data;
        $this->assertNotEmpty($rawData['ticket_links']);
        $this->assertCount(2, $rawData['ticket_links']);

        // Mark Source B as missing and re-project
        $occSrcB = OccurrenceSource::where('source_id', $srcB->id)->first();
        $occSrcB->update(['is_missing' => true]);

        $this->projection->projectEvent($event);
        $event->refresh();

        $updatedRaw = $event->raw_data;
        $this->assertCount(1, $updatedRaw['ticket_links']);
        $this->assertEquals('https://bilesuparadize.lv/lv/event/1001', $updatedRaw['ticket_links'][0]['url']);
    }

    /**
     * Test 4: Time window check (Anti Cross-Year Merge).
     */
    public function test_anti_cross_year_merge_creates_separate_events(): void
    {
        $source = $this->createTestSource('fest-source', 'organizer');
        $log = $this->createTestScrapeLog($source);

        $dto2026 = new ScrapedEventDTO(
            title: 'Rīgas Ritmi Festivāls',
            description: '2026. gada džeza festivāls.',
            startAt: Carbon::create(2026, 11, 20, 19, 0),
            venueName: 'VEF Kultūras pils',
            city: 'Rīga',
            ticketUrl: 'https://rigasritmi.lv/2026',
            categoryNames: ['Mūzika'],
            sourceExternalId: 'ritmi_2026'
        );

        $dto2027 = new ScrapedEventDTO(
            title: 'Rīgas Ritmi Festivāls',
            description: '2027. gada džeza festivāls.',
            startAt: Carbon::create(2027, 11, 20, 19, 0), // 365 days later (> 90 days)
            venueName: 'VEF Kultūras pils',
            city: 'Rīga',
            ticketUrl: 'https://rigasritmi.lv/2027',
            categoryNames: ['Mūzika'],
            sourceExternalId: 'ritmi_2027'
        );

        $this->ingestionV2->ingestCollection(collect([$dto2026]), $source, $log, false);
        $this->ingestionV2->ingestCollection(collect([$dto2027]), $source, $log, false);

        $events = Event::where('title', 'Rīgas Ritmi Festivāls')->get();
        $this->assertCount(2, $events, 'Events 1 year apart must NOT merge into a single canonical event');
    }

    /**
     * Test 5: Fast-Path Skip (unmodified content does not touch events.updated_at).
     */
    public function test_fast_path_skip_preserves_event_updated_at(): void
    {
        $source = $this->createTestSource('fast-source', 'venue');
        $log1 = $this->createTestScrapeLog($source);

        $start = Carbon::now()->addDays(2)->setTime(15, 0);
        $dto = new ScrapedEventDTO(
            title: 'Stabilitātes pārbaudes pasākums',
            description: 'Nemainīgs apraksts.',
            startAt: $start,
            venueName: 'Mūzikas nams Daile',
            city: 'Rīga',
            ticketUrl: 'https://daile.lv/1',
            categoryNames: ['Mūzika'],
            sourceExternalId: 'daile_stable_1'
        );

        Carbon::setTestNow(Carbon::create(2026, 11, 27, 10, 0, 0));
        $this->ingestionV2->ingestCollection(collect([$dto]), $source, $log1, false);

        $event = Event::first();
        $occSource = OccurrenceSource::first();

        $originalEventUpdatedAt = $event->updated_at->copy();
        $originalSourceSeenAt = $occSource->last_seen_at->copy();

        // Advance 10 seconds and re-ingest identical DTO
        Carbon::setTestNow(Carbon::create(2026, 11, 27, 10, 0, 10));
        $log2 = $this->createTestScrapeLog($source);

        $summary = $this->ingestionV2->ingestCollection(collect([$dto]), $source, $log2, false);

        $this->assertEquals(1, $summary->itemsDeduplicated);

        $event->refresh();
        $occSource->refresh();

        // events.updated_at MUST remain identical
        $this->assertEquals(
            $originalEventUpdatedAt->toIso8601String(),
            $event->updated_at->toIso8601String(),
            'events.updated_at must NOT change on fast-path deduplicated skip'
        );

        // occurrence_sources.last_seen_at MUST be updated
        $this->assertTrue(
            $occSource->last_seen_at->gt($originalSourceSeenAt),
            'occurrence_sources.last_seen_at must be updated on fast-path skip'
        );
        $this->assertEquals($log2->id, $occSource->last_scrape_log_id);

        Carbon::setTestNow();
    }

    /**
     * Test 6: EventMergeService and 301 chain flattening.
     */
    public function test_event_merge_service_and_301_redirect_chain_flattening(): void
    {
        $location = Location::create([
            'name' => 'Dailes Teātris',
            'city' => 'Rīga',
            'region' => 'Rīga un apkārtne',
            'address' => 'Brīvības iela 75',
        ]);

        $eventA = Event::create([
            'title' => 'Lapa A',
            'slug' => 'lapa-a',
            'location_id' => $location->id,
            'status' => 'published',
            'start_at' => now()->addDays(5),
        ]);

        $eventB = Event::create([
            'title' => 'Lapa B',
            'slug' => 'lapa-b',
            'location_id' => $location->id,
            'status' => 'published',
            'start_at' => now()->addDays(5),
        ]);

        $eventC = Event::create([
            'title' => 'Lapa C',
            'slug' => 'lapa-c',
            'location_id' => $location->id,
            'status' => 'published',
            'start_at' => now()->addDays(5),
        ]);

        EventAction::create([
            'event_id' => $eventC->id,
            'action_type' => 'ticket_click',
            'url' => 'https://example.com/tickets',
        ]);

        // 1. Merge C -> B
        $this->mergeService->mergeEvents($eventC, $eventB);

        $this->assertEquals('merged', $eventC->fresh()->status);
        $this->assertEquals($eventB->id, $eventC->fresh()->merged_into_id);

        $redC = EventRedirect::where('old_slug', 'lapa-c')->first();
        $this->assertNotNull($redC);
        $this->assertEquals($eventB->id, $redC->target_event_id);

        // 2. Merge B -> A
        $this->mergeService->mergeEvents($eventB, $eventA);

        $this->assertEquals('merged', $eventB->fresh()->status);
        $this->assertEquals($eventA->id, $eventB->fresh()->merged_into_id);

        // Both C and B redirects must now point directly to A (flattened!)
        $redCUpdated = EventRedirect::where('old_slug', 'lapa-c')->first();
        $redBUpdated = EventRedirect::where('old_slug', 'lapa-b')->first();

        $this->assertEquals($eventA->id, $redCUpdated->target_event_id, 'Redirect for C must flatten to A');
        $this->assertEquals($eventA->id, $redBUpdated->target_event_id, 'Redirect for B must point to A');

        // C merged_into_id must also be flattened to A
        $this->assertEquals($eventA->id, $eventC->fresh()->merged_into_id, 'Tombstone merged_into_id must flatten to A');

        // Action moved to A
        $this->assertEquals($eventA->id, EventAction::first()->event_id);

        // 3. Cross-venue merge without $allowCrossVenue must throw \DomainException
        $locOther = Location::create([
            'name' => 'Cita vieta',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
        ]);
        $eventOther = Event::create([
            'title' => 'Cits pasākums',
            'slug' => 'cits-pasakums',
            'location_id' => $locOther->id,
            'status' => 'published',
            'start_at' => now()->addDays(5),
        ]);

        $this->expectException(\DomainException::class);
        $this->mergeService->mergeEvents($eventOther, $eventA, 'merged', false);
    }

    /**
     * Test 7: SourceReconciliationService + Circuit Breaker + 120m buffer.
     */
    public function test_source_reconciliation_circuit_breaker_and_buffer(): void
    {
        $source = $this->createTestSource('reconciled-cinema', 'venue', [
            'supports_reconciliation' => true,
            'reconciliation_min_ratio' => 0.60,
            'reconciliation_grace_runs' => 1,
        ]);

        $location = Location::create(['name' => 'Kino Zāle', 'city' => 'Rīga', 'region' => 'Rīga un apkārtne']);
        $event = Event::create([
            'title' => 'Reconciliation Kino',
            'slug' => 'reconciliation-kino',
            'location_id' => $location->id,
            'status' => 'published',
        ]);

        // Create 10 occurrences far in the future (> 120 min)
        $futureOccurrences = [];
        for ($i = 1; $i <= 10; $i++) {
            $occ = EventOccurrence::create([
                'event_id' => $event->id,
                'start_at' => Carbon::now()->addHours(5 + $i),
                'status' => 'scheduled',
                'occurrence_fingerprint' => hash('sha256', "rec_{$i}"),
            ]);

            OccurrenceSource::create([
                'source_id' => $source->id,
                'source_external_id' => "ext_{$i}",
                'event_occurrence_id' => $occ->id,
                'is_missing' => false,
                'content_hash' => hash('sha256', "content_{$i}"),
            ]);

            $futureOccurrences[] = $occ;
        }

        // Scenario A: Circuit Breaker triggers if only 2 of 10 seen (ratio 0.20 < 0.60)
        $logBroken = $this->createTestScrapeLog($source);
        $logBroken->status = 'success';
        $logBroken->save();

        // Mark only 2 seen
        OccurrenceSource::whereIn('source_external_id', ['ext_1', 'ext_2'])
            ->update(['last_scrape_log_id' => $logBroken->id]);

        $this->reconciliation->reconcile($source, $logBroken, true);
        $logBroken->refresh();

        $this->assertEquals('skipped_circuit_breaker', $logBroken->reconciliation_status);
        $this->assertEquals(0, OccurrenceSource::where('is_missing', true)->count(), 'Circuit breaker must prevent marking missing');

        // Scenario B: Normal reconciliation (9 of 10 seen -> ratio 0.90 >= 0.60)
        $logNormal = $this->createTestScrapeLog($source);
        $logNormal->status = 'success';
        $logNormal->save();

        // Mark 9 seen (ext_10 is missing)
        OccurrenceSource::whereIn('source_external_id', [
            'ext_1', 'ext_2', 'ext_3', 'ext_4', 'ext_5', 'ext_6', 'ext_7', 'ext_8', 'ext_9'
        ])->update(['last_scrape_log_id' => $logNormal->id]);

        $this->reconciliation->reconcile($source, $logNormal, true);
        $logNormal->refresh();

        $this->assertEquals('completed', $logNormal->reconciliation_status);
        $this->assertEquals(1, $logNormal->items_cancelled);

        $missingOccSource = OccurrenceSource::where('source_external_id', 'ext_10')->first();
        $this->assertTrue($missingOccSource->is_missing);

        $cancelledOcc = EventOccurrence::find($missingOccSource->event_occurrence_id);
        $this->assertEquals('cancelled', $cancelledOcc->status);

        // Scenario C: Buffer protection: session starting in 30 minutes (< 120 min) is NOT cancelled
        $nearOcc = EventOccurrence::create([
            'event_id' => $event->id,
            'start_at' => Carbon::now()->addMinutes(30), // Under 120 min buffer!
            'status' => 'scheduled',
            'occurrence_fingerprint' => hash('sha256', 'near_occ'),
        ]);
        $nearOccSource = OccurrenceSource::create([
            'source_id' => $source->id,
            'source_external_id' => 'ext_near',
            'event_occurrence_id' => $nearOcc->id,
            'is_missing' => false,
            'content_hash' => hash('sha256', 'content_near'),
        ]);

        $logBuffer = $this->createTestScrapeLog($source);
        $logBuffer->status = 'success';
        $logBuffer->save();

        // Run reconciliation without ext_near being seen
        $this->reconciliation->reconcile($source, $logBuffer, true);

        $nearOccSource->refresh();
        $nearOcc->refresh();

        $this->assertFalse($nearOccSource->is_missing, 'Sessions starting within buffer must NOT be marked missing');
        $this->assertEquals('scheduled', $nearOcc->status, 'Sessions starting within buffer must NOT be cancelled');
    }

    /**
     * Test 8: Full regression check when INGESTION_V2_ENABLED=false.
     */
    public function test_v1_pipeline_active_when_feature_flag_is_false(): void
    {
        config(['sodiena.ingestion_v2_enabled' => false]);

        $source = $this->createTestSource('v1-source', 'organizer');
        $start = Carbon::now()->addDays(3)->setTime(18, 0);

        $dto = new ScrapedEventDTO(
            title: 'V1 Regresijas pasākums',
            description: 'Klasiskais v1 apraksts.',
            startAt: $start,
            venueName: 'Melngalvju nams',
            city: 'Rīga',
            ticketUrl: 'https://v1.lv/ticket',
            categoryNames: ['Mūzika'],
            sourceExternalId: 'v1_reg_1'
        );

        $v1Service = app(EventIngestionService::class);
        $result = $v1Service->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);
        $this->assertDatabaseHas('events', [
            'title' => 'V1 Regresijas pasākums',
            'source_id' => $source->id,
        ]);
    }

    /**
     * Test 9: Apollo Kino DTO with explicit language metadata ingestion in V2.
     */
    public function test_apollo_kino_dto_with_explicit_language_ingestion_v2(): void
    {
        $apolloSource = $this->createTestSource('apollo-kino', 'venue', [
            'supports_reconciliation' => true,
        ]);

        $log = $this->createTestScrapeLog($apolloSource);
        $start = Carbon::now()->addDays(2)->setTime(19, 30);

        $dto = new ScrapedEventDTO(
            title: 'Kāpa: Otrā daļa (IMAX 2D)',
            startAt: $start,
            venueName: 'Apollo Kino Akropole Rīga',
            city: 'Rīga',
            categoryNames: ['Kino', 'Piedzīvojumu filma'],
            sourceExternalId: 'apollo_dune_123',
            rawData: [
                'language' => [
                    'mode' => 'spoken',
                    'spoken_languages' => ['en'],
                    'subtitle_languages' => ['lv', 'ru'],
                    'source' => 'explicit',
                ],
                'presentation_method' => 'IMAX 2D',
                'auditorium' => '1. Auditorija',
            ]
        );

        $summary = $this->ingestionV2->ingestCollection(collect([$dto]), $apolloSource, $log, true);

        $this->assertEquals(0, count($summary->errors), 'Ingestion must not throw any errors (e.g. Class EventLanguageData not found)');
        $this->assertEquals(1, $summary->itemsCreated);

        $occ = EventOccurrence::whereHas('activeSources', fn ($q) => $q->where('source_external_id', 'apollo_dune_123'))->first();
        $this->assertNotNull($occ);
        $this->assertEquals(['en'], $occ->spoken_languages);
        $this->assertEquals(['lv', 'ru'], $occ->subtitle_languages);
        $this->assertEquals('explicit', $occ->language_source);
        $this->assertEquals('IMAX 2D', $occ->presentation_format);
        $this->assertEquals('1. Auditorija', $occ->auditorium);

        $log->refresh();
        $this->assertEquals('completed', $log->reconciliation_status);
    }

    /**
     * Test 10: Reconciliation status reflects failure vs disabled vs completed accurately.
     */
    public function test_reconciliation_status_behavior_on_failure_and_disabled(): void
    {
        $source = $this->createTestSource('structured-cinema', 'venue', [
            'supports_reconciliation' => true,
        ]);

        // Scenario 1: ScrapeLog status is 'failed' -> reconciliation_status must be 'failed'
        $failedLog = $this->createTestScrapeLog($source);
        $failedLog->status = 'failed';
        $failedLog->save();

        $this->reconciliation->reconcile($source, $failedLog, true);
        $failedLog->refresh();
        $this->assertEquals('failed', $failedLog->reconciliation_status);

        // Scenario 2: Source supports_reconciliation is false -> reconciliation_status must be 'disabled'
        $disabledSource = $this->createTestSource('unsupported-source', 'organizer', [
            'supports_reconciliation' => false,
        ]);
        $successLog = $this->createTestScrapeLog($disabledSource);
        $successLog->status = 'success';
        $successLog->save();

        $this->reconciliation->reconcile($disabledSource, $successLog, true);
        $successLog->refresh();
        $this->assertEquals('disabled', $successLog->reconciliation_status);

        // Scenario 3: Source supports_reconciliation is true and scrape succeeded -> 'completed'
        $successLog2 = $this->createTestScrapeLog($source);
        $successLog2->status = 'success';
        $successLog2->save();

        $this->reconciliation->reconcile($source, $successLog2, true);
        $successLog2->refresh();
        $this->assertEquals('completed', $successLog2->reconciliation_status);
    }
}
