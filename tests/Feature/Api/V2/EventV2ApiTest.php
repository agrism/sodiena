<?php

namespace Tests\Feature\Api\V2;

use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Location;
use App\Models\OccurrenceSource;
use App\Models\Source;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EventV2ApiTest extends TestCase
{
    use RefreshDatabase;

    protected string $token = 'test-secret-bearer-token';
    protected Location $venue;
    protected Source $source;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        config(['api.bearer_token' => $this->token]);

        $this->venue = Location::create([
            'name' => 'Apollo Kino Akropole Rīga',
            'city' => 'Rīga',
            'region' => 'Rīga un apkārtne',
            'address' => 'Latgales iela 257',
            'place_type' => 'cinema',
        ]);

        $this->source = Source::create([
            'name' => 'Apollo Kino',
            'slug' => 'apollo-kino',
            'url' => 'https://apollokino.lv',
            'type' => 'venue',
            'scraper_class' => 'App\\Services\\Scrapers\\Sources\\TestScraper',
            'supports_reconciliation' => true,
            'is_active' => true,
        ]);

        $this->category = Category::create([
            'name' => 'Kino',
            'slug' => 'kino',
            'icon' => 'film',
            'color' => '#e11d48',
            'order' => 1,
        ]);
    }

    protected function authHeaders(array $extra = []): array
    {
        return array_merge([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ], $extra);
    }

    /**
     * Test 1: Authentication and query parameter validation.
     */
    public function test_authentication_and_parameter_validation(): void
    {
        // 1. Without Bearer token -> 401
        $resNoAuth = $this->getJson('/api/v2/events/sync');
        $resNoAuth->assertStatus(401);

        // 2. Invalid Bearer token -> 401
        $resBadAuth = $this->getJson('/api/v2/events/sync', ['Authorization' => 'Bearer wrong-token']);
        $resBadAuth->assertStatus(401);

        // 3. Valid Bearer token -> 200
        $resOk = $this->getJson('/api/v2/events/sync', $this->authHeaders());
        $resOk->assertStatus(200);

        // 4. Malformed cursor -> 422
        $resBadCursor = $this->getJson('/api/v2/events/sync?cursor=not-a-valid-cursor!', $this->authHeaders());
        $resBadCursor->assertStatus(422)
            ->assertJson(['success' => false]);

        // 5. Malformed since -> 422
        $resBadSince = $this->getJson('/api/v2/events/sync?since=invalid-date-format', $this->authHeaders());
        $resBadSince->assertStatus(422);

        // 6. Invalid mode -> 422
        $resBadMode = $this->getJson('/api/v2/events/sync?mode=unknown_mode', $this->authHeaders());
        $resBadMode->assertStatus(422);

        // 7. Invalid per_page -> 422
        $resBadPerPage = $this->getJson('/api/v2/events/sync?per_page=999', $this->authHeaders());
        $resBadPerPage->assertStatus(422);

        // 8. Invalid recent_days -> 422
        $resBadRecentDays = $this->getJson('/api/v2/events/sync?recent_days=500', $this->authHeaders());
        $resBadRecentDays->assertStatus(422);
    }

    /**
     * Test 2: Tombstone protocol and status mapping matrix.
     */
    public function test_tombstone_protocol_and_status_mapping(): void
    {
        $today = Carbon::today();

        // 1. Published active event
        $active = Event::create([
            'title' => 'Kāpa: Otrā daļa',
            'slug' => 'kapa-otra-dala',
            'location_id' => $this->venue->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
            'start_at' => $today->copy()->addDays(2)->setTime(18, 0),
        ]);
        EventOccurrence::create([
            'event_id' => $active->id,
            'start_at' => $today->copy()->addDays(2)->setTime(18, 0),
            'status' => 'scheduled',
            'occurrence_fingerprint' => 'fp_act',
        ]);

        // 2. Chained merged: C -> B -> A
        $targetA = Event::create([
            'title' => 'Koncerts Arēnā',
            'slug' => 'koncerts-arena',
            'location_id' => $this->venue->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
            'start_at' => $today->copy()->addDays(5),
        ]);
        $stepB = Event::create([
            'title' => 'Koncerts Arēnā (B)',
            'slug' => 'koncerts-b',
            'location_id' => $this->venue->id,
            'status' => 'merged',
            'merged_into_id' => $targetA->id,
            'published_at' => now()->subDay(),
            'start_at' => $today->copy()->addDays(5),
        ]);
        $stepC = Event::create([
            'title' => 'Koncerts Arēnā (C)',
            'slug' => 'koncerts-c',
            'location_id' => $this->venue->id,
            'status' => 'merged',
            'merged_into_id' => $stepB->id,
            'published_at' => now()->subDay(),
            'start_at' => $today->copy()->addDays(5),
        ]);

        // 3. Merged into deleted target
        $deletedTarget = Event::create([
            'title' => 'Izdzēsts mērķis',
            'slug' => 'izdzests-merkis',
            'location_id' => $this->venue->id,
            'status' => 'deleted',
            'deleted_at' => now(),
            'published_at' => now()->subDay(),
            'start_at' => $today->copy()->addDays(2),
        ]);
        $mergedToDeleted = Event::create([
            'title' => 'Sapludināts ar dzēstu',
            'slug' => 'sapludinats-ar-dzestu',
            'location_id' => $this->venue->id,
            'status' => 'merged',
            'merged_into_id' => $deletedTarget->id,
            'published_at' => now()->subDay(),
            'start_at' => $today->copy()->addDays(2),
        ]);

        // 4. Cancelled event
        $cancelled = Event::create([
            'title' => 'Atcelts šovs',
            'slug' => 'atcelts-sovs',
            'location_id' => $this->venue->id,
            'status' => 'cancelled',
            'published_at' => now()->subDay(),
            'start_at' => $today->copy()->addDays(3),
        ]);

        // 5. Soft-deleted event
        $softDeleted = Event::create([
            'title' => 'Dzēsts pasākums',
            'slug' => 'dzests-pasakums',
            'location_id' => $this->venue->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
            'start_at' => $today->copy()->addDays(4),
        ]);
        $softDeleted->delete();

        // 6. Unreleased draft (must NEVER leak)
        $unreleasedDraft = Event::create([
            'title' => 'Melnraksts nepublicēts',
            'slug' => 'melnraksts-nepublicets',
            'location_id' => $this->venue->id,
            'status' => 'draft',
            'published_at' => null,
            'start_at' => $today->copy()->addDays(5),
        ]);

        // 7. Unpublished draft (was previously published)
        $unpublishedDraft = Event::create([
            'title' => 'Kādreiz publicēts melnraksts',
            'slug' => 'kadreiz-publicets-melnraksts',
            'location_id' => $this->venue->id,
            'status' => 'draft',
            'published_at' => now()->subDays(10),
            'start_at' => $today->copy()->addDays(5),
        ]);

        // Execute incremental sync query
        $res = $this->getJson('/api/v2/events/sync?mode=incremental', $this->authHeaders());
        $res->assertStatus(200);

        $data = collect($res->json('data'));

        // Assert unreleased draft is NOT present
        $this->assertFalse($data->contains('id', $unreleasedDraft->id));

        // Assert active event has full payload + occurrences
        $actPayload = $data->firstWhere('id', $active->id);
        $this->assertNotNull($actPayload);
        $this->assertEquals('active', $actPayload['sync_status']);
        $this->assertArrayHasKey('occurrences', $actPayload);
        $this->assertCount(1, $actPayload['occurrences']);

        // Assert chained merged C resolves directly to A (flattened) and is compact
        $cPayload = $data->firstWhere('id', $stepC->id);
        $this->assertNotNull($cPayload);
        $this->assertEquals('merged', $cPayload['sync_status']);
        $this->assertEquals($targetA->id, $cPayload['merged_into_id']);
        $this->assertArrayNotHasKey('occurrences', $cPayload);
        $this->assertArrayNotHasKey('description', $cPayload);

        // Assert merged to deleted target becomes deleted
        $mDelPayload = $data->firstWhere('id', $mergedToDeleted->id);
        $this->assertNotNull($mDelPayload);
        $this->assertEquals('deleted', $mDelPayload['sync_status']);
        $this->assertEquals('merged_target_deleted', $mDelPayload['sync_status_reason']);

        // Assert cancelled event
        $cancPayload = $data->firstWhere('id', $cancelled->id);
        $this->assertNotNull($cancPayload);
        $this->assertEquals('cancelled', $cancPayload['sync_status']);

        // Assert soft-deleted event
        $delPayload = $data->firstWhere('id', $softDeleted->id);
        $this->assertNotNull($delPayload);
        $this->assertEquals('deleted', $delPayload['sync_status']);
        $this->assertEquals('soft_deleted', $delPayload['sync_status_reason']);

        // Assert unpublished draft
        $unpubPayload = $data->firstWhere('id', $unpublishedDraft->id);
        $this->assertNotNull($unpubPayload);
        $this->assertEquals('deleted', $unpubPayload['sync_status']);
        $this->assertEquals('unpublished_draft', $unpubPayload['sync_status_reason']);
    }

    /**
     * Test 3: Keyset cursor pagination (updated_at, id) with identical timestamps.
     */
    public function test_keyset_cursor_pagination_with_identical_updated_at(): void
    {
        $exactTime = now()->subMinutes(10)->startOfSecond();

        $events = [];
        for ($k = 1; $k <= 5; $k++) {
            $e = Event::create([
                'title' => "Pasākums {$k}",
                'slug' => "pasakums-{$k}",
                'location_id' => $this->venue->id,
                'status' => 'published',
                'published_at' => $exactTime,
                'start_at' => now()->addDays($k),
            ]);
            // Force exact identical updated_at
            DB::table('events')->where('id', $e->id)->update(['updated_at' => $exactTime]);
            $events[] = $e->fresh();
        }

        // Page 1 (per_page = 2)
        $res1 = $this->getJson('/api/v2/events/sync?mode=incremental&per_page=2', $this->authHeaders());
        $res1->assertStatus(200);
        $this->assertTrue($res1->json('meta.has_more'));
        $this->assertCount(2, $res1->json('data'));
        $cursor1 = $res1->json('meta.next_cursor');
        $this->assertNotNull($cursor1);

        $page1Ids = collect($res1->json('data'))->pluck('id')->all();

        // Page 2 (per_page = 2) with cursor
        $res2 = $this->getJson("/api/v2/events/sync?cursor={$cursor1}&per_page=2", $this->authHeaders());
        $res2->assertStatus(200);
        $this->assertTrue($res2->json('meta.has_more'));
        $this->assertCount(2, $res2->json('data'));
        $cursor2 = $res2->json('meta.next_cursor');
        $this->assertNotNull($cursor2);

        $page2Ids = collect($res2->json('data'))->pluck('id')->all();

        // Page 3 (per_page = 2) with cursor
        $res3 = $this->getJson("/api/v2/events/sync?cursor={$cursor2}&per_page=2", $this->authHeaders());
        $res3->assertStatus(200);
        $this->assertFalse($res3->json('meta.has_more'));
        $this->assertNull($res3->json('meta.next_cursor'));
        $this->assertCount(1, $res3->json('data'));
        $resumeCursor = $res3->json('meta.resume_cursor');
        $this->assertNotNull($resumeCursor);

        $page3Ids = collect($res3->json('data'))->pluck('id')->all();

        // Verify all 5 events were fetched uniquely across the 3 pages without skips or duplicates
        $allIds = array_merge($page1Ids, $page2Ids, $page3Ids);
        $expectedIds = collect($events)->pluck('id')->all();
        $this->assertCount(5, $allIds);
        $this->assertEquals($expectedIds, $allIds);

        // Next request using resume_cursor should return empty data
        $resEmpty = $this->getJson("/api/v2/events/sync?cursor={$resumeCursor}&per_page=2", $this->authHeaders());
        $resEmpty->assertStatus(200);
        $this->assertEmpty($resEmpty->json('data'));
        $this->assertFalse($resEmpty->json('meta.has_more'));

        // Now touch 1 event
        $touchedEvent = $events[2];
        $touchedEvent->updated_at = now()->addMinute();
        $touchedEvent->save();

        // Request again with resume_cursor -> returns exactly this 1 touched event!
        $resTouched = $this->getJson("/api/v2/events/sync?cursor={$resumeCursor}&per_page=2", $this->authHeaders());
        $resTouched->assertStatus(200);
        $this->assertCount(1, $resTouched->json('data'));
        $this->assertEquals($touchedEvent->id, $resTouched->json('data.0.id'));
    }

    /**
     * Test 4: Strict null vs empty array language semantics in occurrences.
     */
    public function test_occurrence_language_null_vs_empty_array_semantics(): void
    {
        $event = Event::create([
            'title' => 'Filma ar valodām',
            'slug' => 'filma-valodas',
            'location_id' => $this->venue->id,
            'status' => 'published',
            'published_at' => now(),
            'start_at' => now()->addDays(2),
        ]);

        // Occurrence 1: null spoken and null subtitles (means "inherit from parent event")
        $occNull = EventOccurrence::create([
            'event_id' => $event->id,
            'start_at' => now()->addDays(2)->setTime(14, 0),
            'status' => 'scheduled',
            'spoken_languages' => null,
            'subtitle_languages' => null,
            'language_source' => null,
            'occurrence_fingerprint' => 'fp_null',
        ]);

        // Occurrence 2: explicit ["lv"] spoken and [] subtitles (means "explicitly no subtitles")
        $occExplicit = EventOccurrence::create([
            'event_id' => $event->id,
            'start_at' => now()->addDays(2)->setTime(18, 0),
            'status' => 'scheduled',
            'spoken_languages' => ['lv'],
            'subtitle_languages' => [],
            'language_source' => 'explicit',
            'occurrence_fingerprint' => 'fp_expl',
        ]);

        $res = $this->getJson("/api/v2/events/{$event->id}", $this->authHeaders());
        $res->assertStatus(200);

        $occs = $res->json('data.occurrences');
        $this->assertCount(2, $occs);

        $payloadNull = collect($occs)->firstWhere('id', $occNull->id);
        $this->assertNull($payloadNull['language']['spoken'], 'Must be strictly null when spoken_languages is null');
        $this->assertNull($payloadNull['language']['subtitles'], 'Must be strictly null when subtitle_languages is null');

        $payloadExpl = collect($occs)->firstWhere('id', $occExplicit->id);
        $this->assertEquals(['lv'], $payloadExpl['language']['spoken']);
        $this->assertSame([], $payloadExpl['language']['subtitles'], 'Must be strictly empty array [] when subtitle_languages is []');
    }

    /**
     * Test 5: Bridge mode and legacy_event_ids array.
     */
    public function test_bridge_mode_and_legacy_event_ids(): void
    {
        $winner = Event::create([
            'title' => 'Kanonisks Pasākums',
            'slug' => 'kanonisks-pasakums-bridge',
            'location_id' => $this->venue->id,
            'status' => 'published',
            'published_at' => now()->subDays(2),
            'start_at' => now()->addDays(3),
        ]);

        // Occurrence with legacy_event_id
        EventOccurrence::create([
            'event_id' => $winner->id,
            'legacy_event_id' => 8881,
            'start_at' => now()->addDays(3),
            'status' => 'scheduled',
            'occurrence_fingerprint' => 'fp_br_1',
        ]);

        // Merged event pointing to winner
        $dup = Event::create([
            'title' => 'Dublikāts 8882',
            'slug' => 'dublikats-8882',
            'location_id' => $this->venue->id,
            'status' => 'merged',
            'merged_into_id' => $winner->id,
            'published_at' => now()->subDays(2),
            'start_at' => now()->addDays(3),
        ]);

        $res = $this->getJson('/api/v2/events/sync?mode=bridge', $this->authHeaders());
        $res->assertStatus(200);

        $winnerPayload = collect($res->json('data'))->firstWhere('id', $winner->id);
        $this->assertNotNull($winnerPayload);
        $this->assertArrayHasKey('legacy_event_ids', $winnerPayload);

        $legacyIds = $winnerPayload['legacy_event_ids'];
        $this->assertContains($winner->id, $legacyIds);
        $this->assertContains(8881, $legacyIds);
        $this->assertContains($dup->id, $legacyIds);
    }

    /**
     * Test 6: Single event show and stats endpoints.
     */
    public function test_single_event_show_and_stats_endpoints(): void
    {
        $winner = Event::create([
            'title' => 'Galīgais Uzvarētājs',
            'slug' => 'galigais-uzvaretajs',
            'location_id' => $this->venue->id,
            'status' => 'published',
            'published_at' => now(),
            'start_at' => now()->addDays(5),
        ]);

        $merged = Event::create([
            'title' => 'Sapludinātais Biedrs',
            'slug' => 'sapludinatais-biedrs',
            'location_id' => $this->venue->id,
            'status' => 'merged',
            'merged_into_id' => $winner->id,
            'published_at' => now(),
            'start_at' => now()->addDays(5),
        ]);

        // GET /api/v2/events/{mergedId} returns 200 with tombstone
        $resMerged = $this->getJson("/api/v2/events/{$merged->id}", $this->authHeaders());
        $resMerged->assertStatus(200)
            ->assertJson([
                'success' => true,
                'schema_version' => 2,
                'data' => [
                    'id' => $merged->id,
                    'sync_status' => 'merged',
                    'merged_into_id' => $winner->id,
                ],
            ]);

        // GET /api/v2/events/stats
        $resStats = $this->getJson('/api/v2/events/stats', $this->authHeaders());
        $resStats->assertStatus(200)
            ->assertJson([
                'success' => true,
                'schema_version' => 2,
            ])
            ->assertJsonStructure([
                'events' => [
                    'active_upcoming',
                    'active_total',
                    'cancelled_upcoming',
                    'merged_total',
                    'deleted_total',
                ],
                'occurrences' => [
                    'scheduled_upcoming',
                    'postponed_upcoming',
                    'cancelled_upcoming',
                    'total_active_or_future',
                ],
                'reconciliation' => [
                    'enabled_sources_count',
                    'last_24h_cancelled_occurrences',
                    'last_24h_circuit_breaker_trips',
                ],
            ]);
    }

    /**
     * Test 7: Zero N+1 queries protection and /api/v1 backward compatibility.
     */
    public function test_n_plus_one_protection_and_v1_backward_compatibility(): void
    {
        // Create 10 events with occurrences
        for ($i = 1; $i <= 10; $i++) {
            $ev = Event::create([
                'title' => "Koncerts {$i}",
                'slug' => "koncerts-{$i}",
                'location_id' => $this->venue->id,
                'source_id' => $this->source->id,
                'status' => 'published',
                'published_at' => now(),
                'start_at' => now()->addDays(2),
            ]);
            $occ = EventOccurrence::create([
                'event_id' => $ev->id,
                'start_at' => now()->addDays(2)->setTime(19, 0),
                'status' => 'scheduled',
                'occurrence_fingerprint' => "fp_n1_{$i}",
            ]);
            OccurrenceSource::create([
                'event_occurrence_id' => $occ->id,
                'source_id' => $this->source->id,
                'source_external_id' => "ext_{$i}",
                'ticket_url' => "https://apollokino.lv/tickets/{$i}",
                'content_hash' => "ch_{$i}",
                'last_seen_at' => now(),
            ]);
        }

        // Measure query count for /api/v2/events/sync
        DB::flushQueryLog();
        DB::enableQueryLog();

        $resV2 = $this->getJson('/api/v2/events/sync?per_page=10', $this->authHeaders());
        $resV2->assertStatus(200);

        $queriesV2 = DB::getQueryLog();
        DB::disableQueryLog();

        // Must be a bounded, fixed query count (<= 12 queries)
        $this->assertLessThanOrEqual(12, count($queriesV2), 'N+1 detected on /api/v2/events/sync: query count exceeded 12 queries');

        // Test /api/v1/events/sync backward compatibility
        $resV1Sync = $this->getJson('/api/v1/events/sync', $this->authHeaders());
        $resV1Sync->assertStatus(200);

        // Test /api/v1/events backward compatibility
        $resV1Index = $this->getJson('/api/v1/events', $this->authHeaders());
        $resV1Index->assertStatus(200);
    }
}
