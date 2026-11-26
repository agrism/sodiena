<?php

namespace Tests\Feature;

use App\DTO\EventLanguageData;
use App\Enums\EventLanguageMode;
use App\Enums\EventLanguageSource;
use App\Models\Category;
use App\Models\Event;
use App\Models\Location;
use App\Services\EventLanguageService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class EventLanguageApiTest extends TestCase
{
    use RefreshDatabase;

    protected string $token = 'test_secret_bearer_token';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('api.bearer_token', $this->token);
    }

    // =========================================================================
    // 1. Structure across all endpoints
    // =========================================================================

    public function test_all_api_endpoints_return_canonical_language_object(): void
    {
        $event = Event::create([
            'title' => 'Sample Concert',
            'status' => 'published',
            'published_at' => now(),
            'start_at' => Carbon::now()->addDays(2),
            'language_mode' => EventLanguageMode::Spoken,
            'spoken_languages' => ['en'],
            'subtitle_languages' => ['lv', 'ru'],
            'language_source' => EventLanguageSource::Explicit,
            'is_promoted' => true,
        ]);

        $defaultEvent = Event::create([
            'title' => 'Unknown Language Event',
            'status' => 'published',
            'published_at' => now(),
            'start_at' => Carbon::now()->addDays(3),
            'language_mode' => EventLanguageMode::Unknown,
            'spoken_languages' => [],
            'subtitle_languages' => [],
            'language_source' => EventLanguageSource::Unknown,
        ]);

        // 1. GET /api/v1/events/sync
        $resSync = $this->withToken($this->token)->getJson('/api/v1/events/sync');
        $resSync->assertStatus(200);
        $syncItems = $resSync->json('data');
        $this->assertNotEmpty($syncItems);
        $syncEvent1 = collect($syncItems)->firstWhere('id', $event->id);
        $this->assertNotNull($syncEvent1);
        $this->assertSame('spoken', $syncEvent1['language']['mode']);
        $this->assertSame(['en'], $syncEvent1['language']['spoken']);
        $this->assertSame(['lv', 'ru'], $syncEvent1['language']['subtitles']);
        $this->assertSame('explicit', $syncEvent1['language']['source']);

        $syncDefault = collect($syncItems)->firstWhere('id', $defaultEvent->id);
        $this->assertSame('unknown', $syncDefault['language']['mode']);
        $this->assertSame([], $syncDefault['language']['spoken']);
        $this->assertSame([], $syncDefault['language']['subtitles']);
        $this->assertSame('unknown', $syncDefault['language']['source']);

        // 2. GET /api/v1/events
        $resIndex = $this->withToken($this->token)->getJson('/api/v1/events');
        $resIndex->assertStatus(200);
        $indexItems = $resIndex->json('data');
        $indexEvent1 = collect($indexItems)->firstWhere('id', $event->id);
        $this->assertNotNull($indexEvent1);
        $this->assertSame('spoken', $indexEvent1['language']['mode']);
        $this->assertSame(['en'], $indexEvent1['language']['spoken']);
        $this->assertSame(['lv', 'ru'], $indexEvent1['language']['subtitles']);
        $this->assertSame('explicit', $indexEvent1['language']['source']);

        // 3. GET /api/v1/events/{id}
        $resShow = $this->withToken($this->token)->getJson("/api/v1/events/{$event->id}");
        $resShow->assertStatus(200);
        $showData = $resShow->json('data');
        $this->assertSame('spoken', $showData['language']['mode']);
        $this->assertSame(['en'], $showData['language']['spoken']);
        $this->assertSame(['lv', 'ru'], $showData['language']['subtitles']);
        $this->assertSame('explicit', $showData['language']['source']);

        // 4. GET /api/v1/events/promoted
        $resPromoted = $this->withToken($this->token)->getJson('/api/v1/events/promoted');
        $resPromoted->assertStatus(200);
        $promotedItems = $resPromoted->json('data');
        $promotedEvent = collect($promotedItems)->firstWhere('id', $event->id);
        $this->assertNotNull($promotedEvent);
        $this->assertArrayHasKey('language', $promotedEvent);
        $this->assertSame('spoken', $promotedEvent['language']['mode']);
        $this->assertSame(['en'], $promotedEvent['language']['spoken']);

        // Also test unversioned aliases
        $resAliasShow = $this->withToken($this->token)->getJson("/api/events/{$event->id}");
        $resAliasShow->assertStatus(200);
        $this->assertSame('spoken', $resAliasShow->json('data.language.mode'));
    }

    // =========================================================================
    // 2. Security Mapping: manual -> explicit in API
    // =========================================================================

    public function test_manual_language_source_is_exported_as_explicit(): void
    {
        $event = Event::create([
            'title' => 'Manually Verified Festival',
            'status' => 'published',
            'published_at' => now(),
            'start_at' => Carbon::now()->addDays(2),
            'language_mode' => EventLanguageMode::Spoken,
            'spoken_languages' => ['lv'],
            'subtitle_languages' => ['en'],
            'language_source' => EventLanguageSource::Manual, // DB has 'manual'
        ]);

        // GET /api/v1/events/{id}
        $resShow = $this->withToken($this->token)->getJson("/api/v1/events/{$event->id}");
        $resShow->assertStatus(200);
        $this->assertSame('explicit', $resShow->json('data.language.source'), 'Manual DB source must be exposed as explicit in API');

        // GET /api/v1/events/sync
        $resSync = $this->withToken($this->token)->getJson("/api/v1/events/sync");
        $resSync->assertStatus(200);
        $syncItem = collect($resSync->json('data'))->firstWhere('id', $event->id);
        $this->assertSame('explicit', $syncItem['language']['source']);
    }

    // =========================================================================
    // 3. Query Filter: ?language=
    // =========================================================================

    public function test_filter_by_language_on_index_and_sync(): void
    {
        // 1. Language-free event (e.g. sports or exhibition) -> accessible in any language
        $freeEvent = Event::create([
            'title' => 'Basketball Game',
            'status' => 'published',
            'published_at' => now(),
            'start_at' => Carbon::now()->addDays(2),
            'language_mode' => EventLanguageMode::LanguageFree,
            'spoken_languages' => [],
            'subtitle_languages' => [],
            'language_source' => EventLanguageSource::Inferred,
        ]);

        // 2. English audio event
        $enAudioEvent = Event::create([
            'title' => 'English Movie',
            'status' => 'published',
            'published_at' => now(),
            'start_at' => Carbon::now()->addDays(2),
            'language_mode' => EventLanguageMode::Spoken,
            'spoken_languages' => ['en'],
            'subtitle_languages' => [],
            'language_source' => EventLanguageSource::Explicit,
        ]);

        // 3. Latvian audio with English subtitles event
        $enSubEvent = Event::create([
            'title' => 'Latvian Movie EN Subtitles',
            'status' => 'published',
            'published_at' => now(),
            'start_at' => Carbon::now()->addDays(2),
            'language_mode' => EventLanguageMode::Spoken,
            'spoken_languages' => ['lv'],
            'subtitle_languages' => ['en'],
            'language_source' => EventLanguageSource::Explicit,
        ]);

        // 4. Latvian audio only event (no EN)
        $lvOnlyEvent = Event::create([
            'title' => 'Latvian Play Only',
            'status' => 'published',
            'published_at' => now(),
            'start_at' => Carbon::now()->addDays(2),
            'language_mode' => EventLanguageMode::Spoken,
            'spoken_languages' => ['lv'],
            'subtitle_languages' => [],
            'language_source' => EventLanguageSource::Explicit,
        ]);

        // 5. Unknown language event
        $unknownEvent = Event::create([
            'title' => 'Unknown Language',
            'status' => 'published',
            'published_at' => now(),
            'start_at' => Carbon::now()->addDays(2),
            'language_mode' => EventLanguageMode::Unknown,
            'spoken_languages' => [],
            'subtitle_languages' => [],
            'language_source' => EventLanguageSource::Unknown,
        ]);

        // Query ?language=en on /events
        $resIndex = $this->withToken($this->token)->getJson('/api/v1/events?language=en');
        $resIndex->assertStatus(200);
        $indexIds = collect($resIndex->json('data'))->pluck('id')->all();

        $this->assertContains($freeEvent->id, $indexIds, 'Language-free must be included for EN');
        $this->assertContains($enAudioEvent->id, $indexIds, 'EN audio must be included');
        $this->assertContains($enSubEvent->id, $indexIds, 'EN subtitles must be included');
        $this->assertNotContains($lvOnlyEvent->id, $indexIds, 'LV-only must NOT be included');
        $this->assertNotContains($unknownEvent->id, $indexIds, 'Unknown language must NOT be included');

        // Query ?language=en on /events/sync
        $resSync = $this->withToken($this->token)->getJson('/api/v1/events/sync?language=en');
        $resSync->assertStatus(200);
        $syncIds = collect($resSync->json('data'))->pluck('id')->all();

        $this->assertContains($freeEvent->id, $syncIds);
        $this->assertContains($enAudioEvent->id, $syncIds);
        $this->assertContains($enSubEvent->id, $syncIds);
        $this->assertNotContains($lvOnlyEvent->id, $syncIds);
        $this->assertNotContains($unknownEvent->id, $syncIds);
    }

    // =========================================================================
    // 4. Query Filter: ?language_mode= and combinations
    // =========================================================================

    public function test_filter_by_language_mode_and_combinations(): void
    {
        $freeEvent = Event::create([
            'title' => 'Exhibition',
            'status' => 'published',
            'published_at' => now(),
            'start_at' => Carbon::now()->addDays(2),
            'language_mode' => EventLanguageMode::LanguageFree,
            'spoken_languages' => [],
            'language_source' => EventLanguageSource::Inferred,
        ]);

        $spokenEvent = Event::create([
            'title' => 'English Lecture',
            'status' => 'published',
            'published_at' => now(),
            'start_at' => Carbon::now()->addDays(2),
            'language_mode' => EventLanguageMode::Spoken,
            'spoken_languages' => ['en'],
            'language_source' => EventLanguageSource::Explicit,
        ]);

        // 1. ?language_mode=language_free
        $resMode = $this->withToken($this->token)->getJson('/api/v1/events?language_mode=language_free');
        $resMode->assertStatus(200);
        $modeIds = collect($resMode->json('data'))->pluck('id')->all();
        $this->assertContains($freeEvent->id, $modeIds);
        $this->assertNotContains($spokenEvent->id, $modeIds);

        // 2. Combined: ?language=en&language_mode=spoken
        // Must include spoken EN, but EXCLUDE language_free
        $resCombo = $this->withToken($this->token)->getJson('/api/v1/events?language=en&language_mode=spoken');
        $resCombo->assertStatus(200);
        $comboIds = collect($resCombo->json('data'))->pluck('id')->all();
        $this->assertContains($spokenEvent->id, $comboIds);
        $this->assertNotContains($freeEvent->id, $comboIds, 'Combined with language_mode=spoken must exclude language_free');
    }

    // =========================================================================
    // 5. Validation Errors (422)
    // =========================================================================

    public function test_invalid_language_and_language_mode_returns_422(): void
    {
        // 1. Invalid language code on /events
        $res1 = $this->withToken($this->token)->getJson('/api/v1/events?language=invalid_code_123');
        $res1->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid language code for "language". Please use a valid ISO 639-1 code (e.g. lv, en, ru).',
            ]);

        // 2. Invalid language code on /events/sync
        $res2 = $this->withToken($this->token)->getJson('/api/v1/events/sync?language=xyz999');
        $res2->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);

        // 3. Invalid language_mode on /events
        $res3 = $this->withToken($this->token)->getJson('/api/v1/events?language_mode=bogus_mode');
        $res3->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid value for "language_mode". Allowed values: spoken, language_free, unknown.',
            ]);

        // 4. Invalid language_mode on /events/sync
        $res4 = $this->withToken($this->token)->getJson('/api/v1/events/sync?language_mode=unknown_foo');
        $res4->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
    }

    // =========================================================================
    // 6. Incremental Synchronization (?since=)
    // =========================================================================

    public function test_incremental_sync_detects_language_updates(): void
    {
        // Create event initially at T0 (2 hours ago)
        $t0 = Carbon::now()->subHours(2);
        $event = Event::create([
            'title' => 'Concert Waiting for Language Info',
            'status' => 'published',
            'published_at' => $t0,
            'start_at' => Carbon::now()->addDays(5),
            'language_mode' => EventLanguageMode::Unknown,
            'spoken_languages' => [],
            'language_source' => EventLanguageSource::Unknown,
        ]);
        $event->timestamps = false;
        $event->created_at = $t0;
        $event->updated_at = $t0;
        $event->save();
        $event->timestamps = true;

        // T1 checkpoint is 1 hour ago
        $t1 = Carbon::now()->subHour();
        $sinceParam = urlencode($t1->toIso8601String());

        // Query before update -> event was updated at T0 (< T1), so it shouldn't appear
        $resBefore = $this->withToken($this->token)->getJson("/api/v1/events/sync?since={$sinceParam}");
        $resBefore->assertStatus(200);
        $idsBefore = collect($resBefore->json('data'))->pluck('id')->all();
        $this->assertNotContains($event->id, $idsBefore);

        // Now, update language using EventLanguageService::applyToEvent()
        $langUpdate = new EventLanguageData(
            mode: EventLanguageMode::Spoken,
            spokenLanguages: ['lv'],
            subtitleLanguages: ['en'],
            source: EventLanguageSource::Explicit
        );

        $changed = EventLanguageService::applyToEvent($event, $langUpdate);
        $this->assertTrue($changed);

        // Query after update -> event's updated_at is now >= T1, so it MUST appear in sync
        $resAfter = $this->withToken($this->token)->getJson("/api/v1/events/sync?since={$sinceParam}");
        $resAfter->assertStatus(200);
        $syncItemsAfter = $resAfter->json('data');
        $syncedEvent = collect($syncItemsAfter)->firstWhere('id', $event->id);

        $this->assertNotNull($syncedEvent, 'Event with updated language must be returned in incremental sync');
        $this->assertSame('spoken', $syncedEvent['language']['mode']);
        $this->assertSame(['lv'], $syncedEvent['language']['spoken']);
        $this->assertSame(['en'], $syncedEvent['language']['subtitles']);
        $this->assertSame('explicit', $syncedEvent['language']['source']);

        // Check that redundant applyToEvent does NOT touch updated_at
        $updatedAtPrior = $event->fresh()->updated_at;
        $redundantAttempt = new EventLanguageData(
            mode: EventLanguageMode::Spoken,
            spokenLanguages: ['lv'],
            subtitleLanguages: ['en'],
            source: EventLanguageSource::Explicit
        );
        $changedRedundant = EventLanguageService::applyToEvent($event, $redundantAttempt);
        $this->assertFalse($changedRedundant, 'Redundant language application must return false');
        $this->assertEquals($updatedAtPrior, $event->fresh()->updated_at, 'Redundant application must not touch updated_at');
    }
}
