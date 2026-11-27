<?php

namespace Tests\Feature\MigrationV2;

use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRedirect;
use App\Models\EventTranslation;
use App\Models\Location;
use App\Models\OccurrenceSource;
use App\Models\Source;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrateEventsToV2CommandTest extends TestCase
{
    use RefreshDatabase;

    protected Location $apolloPlaza;
    protected Location $forumCinemas;
    protected Source $apolloSource;
    protected Source $forumSource;
    protected Category $kinoCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kinoCategory = Category::create([
            'name' => 'Kino',
            'slug' => 'kino',
            'icon' => 'film',
            'color' => '#e11d48',
            'order' => 1,
        ]);

        $this->apolloPlaza = Location::create([
            'name' => 'Apollo Kino Plaza',
            'city' => 'Rīga',
            'region' => 'Rīga un apkārtne',
            'address' => 'Mūkusalas iela 71',
            'place_type' => 'cinema',
        ]);

        $this->forumCinemas = Location::create([
            'name' => 'Forum Cinemas / Kino Citadele',
            'city' => 'Rīga',
            'region' => 'Rīga un apkārtne',
            'address' => '13. janvāra iela 8',
            'place_type' => 'cinema',
        ]);

        $this->apolloSource = Source::create([
            'name' => 'Apollo Kino',
            'slug' => 'apollo-kino',
            'url' => 'https://apollokino.lv',
            'type' => 'venue',
            'scraper_class' => 'App\\Services\\Scrapers\\Sources\\TestScraper',
            'is_active' => true,
        ]);

        $this->forumSource = Source::create([
            'name' => 'Forum Cinemas',
            'slug' => 'forum-cinemas',
            'url' => 'https://forumcinemas.lv',
            'type' => 'venue',
            'scraper_class' => 'App\\Services\\Scrapers\\Sources\\TestScraper',
            'is_active' => true,
        ]);
    }

    /**
     * Test 1: Grouping & cinema venue isolation during migration.
     */
    public function test_grouping_and_cinema_isolation_during_migration(): void
    {
        $date = Carbon::now()->addDays(5)->setTime(14, 0);

        // 4 sessions at Apollo Plaza
        $ap1 = Event::create([
            'title' => 'Dune (2D)',
            'slug' => 'dune-2d-101',
            'location_id' => $this->apolloPlaza->id,
            'source_id' => $this->apolloSource->id,
            'source_slug' => 'apollo-kino',
            'source_external_id' => 'ap_101',
            'start_at' => $date->copy()->setTime(14, 0),
            'status' => 'published',
            'views_count' => 100, // Winner candidate!
            'ticket_url' => 'https://apollokino.lv/tickets/101',
            'raw_data' => ['presentation_method' => '2D', 'cinema' => ['auditorium' => 'Zāle 1']],
        ]);
        $ap1->categories()->sync([$this->kinoCategory->id]);

        $ap2 = Event::create([
            'title' => 'Dune (IMAX 2D)',
            'slug' => 'dune-imax-2d-102',
            'location_id' => $this->apolloPlaza->id,
            'source_id' => $this->apolloSource->id,
            'source_slug' => 'apollo-kino',
            'source_external_id' => 'ap_102',
            'start_at' => $date->copy()->setTime(17, 30),
            'status' => 'published',
            'views_count' => 50,
            'ticket_url' => 'https://apollokino.lv/tickets/102',
            'raw_data' => ['presentation_method' => 'IMAX 2D', 'cinema' => ['auditorium' => 'IMAX']],
        ]);
        $ap2->categories()->sync([$this->kinoCategory->id]);

        $ap3 = Event::create([
            'title' => 'Dune (3D)',
            'slug' => 'dune-3d-103',
            'location_id' => $this->apolloPlaza->id,
            'source_id' => $this->apolloSource->id,
            'source_slug' => 'apollo-kino',
            'source_external_id' => 'ap_103',
            'start_at' => $date->copy()->setTime(20, 45),
            'status' => 'published',
            'views_count' => 30,
            'ticket_url' => 'https://apollokino.lv/tickets/103',
            'raw_data' => ['presentation_method' => '3D', 'cinema' => ['auditorium' => 'Zāle 2']],
        ]);
        $ap3->categories()->sync([$this->kinoCategory->id]);

        $ap4 = Event::create([
            'title' => 'Dune (2D) krievu valodā',
            'slug' => 'dune-2d-ru-104',
            'location_id' => $this->apolloPlaza->id,
            'source_id' => $this->apolloSource->id,
            'source_slug' => 'apollo-kino',
            'source_external_id' => 'ap_104',
            'start_at' => $date->copy()->setTime(22, 15),
            'status' => 'published',
            'views_count' => 10,
            'ticket_url' => 'https://apollokino.lv/tickets/104',
            'raw_data' => ['presentation_method' => '2D', 'cinema' => ['auditorium' => 'Zāle 3']],
        ]);
        $ap4->categories()->sync([$this->kinoCategory->id]);

        // 2 sessions at Forum Cinemas
        $fc1 = Event::create([
            'title' => 'Dune',
            'slug' => 'dune-fc-201',
            'location_id' => $this->forumCinemas->id,
            'source_id' => $this->forumSource->id,
            'source_slug' => 'forum-cinemas',
            'source_external_id' => 'fc_201',
            'start_at' => $date->copy()->setTime(18, 0),
            'status' => 'published',
            'views_count' => 80,
            'ticket_url' => 'https://forumcinemas.lv/tickets/201',
            'raw_data' => ['presentation_method' => '2D', 'cinema' => ['auditorium' => 'Auditorium 1']],
        ]);
        $fc1->categories()->sync([$this->kinoCategory->id]);

        $fc2 = Event::create([
            'title' => 'Dune (LUX)',
            'slug' => 'dune-fc-202',
            'location_id' => $this->forumCinemas->id,
            'source_id' => $this->forumSource->id,
            'source_slug' => 'forum-cinemas',
            'source_external_id' => 'fc_202',
            'start_at' => $date->copy()->setTime(21, 15),
            'status' => 'published',
            'views_count' => 20,
            'ticket_url' => 'https://forumcinemas.lv/tickets/202',
            'raw_data' => ['presentation_method' => 'LUX', 'cinema' => ['auditorium' => 'LUX Suite']],
        ]);
        $fc2->categories()->sync([$this->kinoCategory->id]);

        // Run migration command with --execute
        $this->artisan('events:migrate-to-v2', ['--execute' => true])
            ->assertSuccessful();

        // Verification: Exactly 2 active canonical events remaining
        $activeEvents = Event::whereNotIn('status', ['merged', 'deleted'])->get();
        $this->assertCount(2, $activeEvents, 'Expected exactly 2 active Events (1 for Apollo, 1 for Forum)');

        $apolloCanonical = $activeEvents->firstWhere('location_id', $this->apolloPlaza->id);
        $forumCanonical = $activeEvents->firstWhere('location_id', $this->forumCinemas->id);

        $this->assertNotNull($apolloCanonical);
        $this->assertNotNull($forumCanonical);
        $this->assertEquals($ap1->id, $apolloCanonical->id, 'ap1 with highest views_count should be the winner');
        $this->assertEquals($fc1->id, $forumCanonical->id, 'fc1 with highest views_count should be the winner');

        // Check occurrences count
        $this->assertEquals(4, $apolloCanonical->occurrences()->count());
        $this->assertEquals(2, $forumCanonical->occurrences()->count());
        $this->assertEquals(6, EventOccurrence::count());

        // Check legacy_event_id bridge
        $apolloLegacyIds = $apolloCanonical->occurrences->pluck('legacy_event_id')->all();
        sort($apolloLegacyIds);
        $this->assertEquals([$ap1->id, $ap2->id, $ap3->id, $ap4->id], $apolloLegacyIds);

        // Check merged status and tombstones
        $this->assertEquals('merged', $ap2->fresh()->status);
        $this->assertEquals('merged', $ap3->fresh()->status);
        $this->assertEquals('merged', $ap4->fresh()->status);
        $this->assertEquals($apolloCanonical->id, $ap2->fresh()->merged_into_id);
        $this->assertEquals('merged', $fc2->fresh()->status);
        $this->assertEquals($forumCanonical->id, $fc2->fresh()->merged_into_id);

        // Check redirects created
        $this->assertDatabaseHas('event_redirects', [
            'old_slug' => 'dune-imax-2d-102',
            'target_event_id' => $apolloCanonical->id,
            'reason' => 'migrated',
        ]);
        $this->assertDatabaseHas('event_redirects', [
            'old_slug' => 'dune-fc-202',
            'target_event_id' => $forumCanonical->id,
            'reason' => 'migrated',
        ]);
    }

    /**
     * Test 2: Anti Cross-Year Merge during migration.
     */
    public function test_anti_cross_year_merge_during_migration(): void
    {
        $ev2025 = Event::create([
            'title' => 'Rīgas Festivāls',
            'slug' => 'rigas-festivals-2025',
            'location_id' => $this->apolloPlaza->id,
            'start_at' => Carbon::create(2025, 11, 15, 19, 0),
            'status' => 'published',
        ]);

        $ev2026 = Event::create([
            'title' => 'Rīgas Festivāls',
            'slug' => 'rigas-festivals-2026',
            'location_id' => $this->apolloPlaza->id,
            'start_at' => Carbon::create(2026, 11, 15, 19, 0), // 365 days later (> 90 days)
            'status' => 'published',
        ]);

        $this->artisan('events:migrate-to-v2', ['--execute' => true])
            ->assertSuccessful();

        $events = Event::where('title', 'like', '%Rīgas Festivāls%')
            ->whereNotIn('status', ['merged', 'deleted'])
            ->get();

        $this->assertCount(2, $events, 'Events 1 year apart must remain 2 separate canonical events');
        $this->assertNull($ev2025->fresh()->merged_into_id);
        $this->assertNull($ev2026->fresh()->merged_into_id);
    }

    /**
     * Test 3: Idempotence of migration command.
     */
    public function test_migration_is_idempotent(): void
    {
        $ev = Event::create([
            'title' => 'Unikāls pasākums',
            'slug' => 'unikals-pasakums',
            'location_id' => $this->apolloPlaza->id,
            'source_id' => $this->apolloSource->id,
            'start_at' => now()->addDays(2),
            'status' => 'published',
        ]);

        // First execution
        $this->artisan('events:migrate-to-v2', ['--execute' => true])
            ->assertSuccessful();
        $occCount1 = EventOccurrence::count();
        $srcCount1 = OccurrenceSource::count();
        $this->assertEquals(1, $occCount1);
        $this->assertEquals(1, $srcCount1);

        // Second execution
        $this->artisan('events:migrate-to-v2', ['--execute' => true])
            ->expectsOutput('✅ Visi atlasītie pasākumi jau ir izmigrēti uz v2!')
            ->assertSuccessful();

        $this->assertEquals($occCount1, EventOccurrence::count());
        $this->assertEquals($srcCount1, OccurrenceSource::count());
    }

    /**
     * Test 4: Translations preserved and missing locales copied to winner.
     */
    public function test_translations_preserved_and_missing_locales_copied(): void
    {
        // Winner has LV and EN
        $winner = Event::create([
            'title' => 'Koncerts A',
            'slug' => 'koncerts-a',
            'location_id' => $this->apolloPlaza->id,
            'start_at' => now()->addDays(3)->setTime(19, 0),
            'status' => 'published',
            'views_count' => 200,
        ]);
        EventTranslation::create([
            'event_id' => $winner->id,
            'locale' => 'lv',
            'title' => 'Koncerts A',
            'slug' => 'koncerts-a-lv',
            'description' => 'Apraksts LV',
        ]);
        EventTranslation::create([
            'event_id' => $winner->id,
            'locale' => 'en',
            'title' => 'Concert A',
            'slug' => 'concert-a-en',
            'description' => 'Description in English for Concert A',
        ]);

        // Duplicate has RU translation with valuable text
        $dup = Event::create([
            'title' => 'Koncerts A',
            'slug' => 'koncerts-a-dup',
            'location_id' => $this->apolloPlaza->id,
            'start_at' => now()->addDays(3)->setTime(19, 0),
            'status' => 'published',
            'views_count' => 10,
        ]);
        EventTranslation::create([
            'event_id' => $dup->id,
            'locale' => 'ru',
            'title' => 'Концерт А',
            'slug' => 'koncert-a-ru',
            'description' => 'Описание концерта на русском языке',
        ]);

        $this->artisan('events:migrate-to-v2', ['--execute' => true])
            ->assertSuccessful();

        $winner->refresh();
        $this->assertEquals('koncerts-a', $winner->slug, 'Winner slug must remain intact');

        // Winner now has LV, EN, and copied RU!
        $this->assertEquals(3, $winner->translations()->count());
        $this->assertDatabaseHas('event_translations', [
            'event_id' => $winner->id,
            'locale' => 'en',
            'description' => 'Description in English for Concert A',
        ]);
        $this->assertDatabaseHas('event_translations', [
            'event_id' => $winner->id,
            'locale' => 'ru',
            'description' => 'Описание концерта на русском языке',
        ]);
    }

    /**
     * Test 5: Dry-run mode does not make changes to DB.
     */
    public function test_dry_run_does_not_modify_database(): void
    {
        Event::create([
            'title' => 'Dry Run Pasākums',
            'slug' => 'dry-run-pasakums',
            'location_id' => $this->apolloPlaza->id,
            'start_at' => now()->addDays(4),
            'status' => 'published',
        ]);

        // Run WITHOUT --execute
        $this->artisan('events:migrate-to-v2')
            ->assertSuccessful();

        $this->assertEquals(0, EventOccurrence::count(), 'Dry-run must not create occurrences');
        $this->assertEquals(0, OccurrenceSource::count(), 'Dry-run must not create occurrence_sources');
        $this->assertEquals(0, EventRedirect::count(), 'Dry-run must not create redirects');
    }

    /**
     * Test 6: Configure structured sources reconciliation command.
     */
    public function test_configure_structured_sources_reconciliation_command(): void
    {
        // Enable reconciliation
        $this->artisan('sources:configure-reconciliation')
            ->assertSuccessful();

        $this->apolloSource->refresh();
        $this->assertTrue((bool) $this->apolloSource->supports_reconciliation);
        $this->assertEquals(0.60, (float) $this->apolloSource->reconciliation_min_ratio);
        $this->assertEquals(1, (int) $this->apolloSource->reconciliation_grace_runs);

        // Disable reconciliation with --disable
        $this->artisan('sources:configure-reconciliation', ['--disable' => true])
            ->assertSuccessful();

        $this->apolloSource->refresh();
        $this->assertFalse((bool) $this->apolloSource->supports_reconciliation);
    }
}
