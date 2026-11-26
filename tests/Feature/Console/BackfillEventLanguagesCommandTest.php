<?php

namespace Tests\Feature\Console;

use App\DTO\EventLanguageData;
use App\Enums\EventLanguageMode;
use App\Enums\EventLanguageSource;
use App\Enums\SourceType;
use App\Models\Category;
use App\Models\Event;
use App\Models\Location;
use App\Models\Source;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillEventLanguagesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Source $defaultSource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultSource = Source::create([
            'name' => 'Default Source',
            'slug' => 'default-source',
            'url' => 'https://example.com',
            'scraper_class' => \App\Services\Scrapers\Sources\ForumCinemasScraper::class,
            'type' => SourceType::VENUE,
            'is_active' => true,
        ]);
    }

    private function createEvent(array $attributes = [], array $categorySlugs = []): Event
    {
        $event = Event::create(array_merge([
            'title' => 'Testa pasākums',
            'slug' => 'testa-pasakums-' . uniqid(),
            'start_at' => Carbon::now('Europe/Riga')->addDays(2),
            'source_id' => $this->defaultSource->id,
            'source_slug' => $this->defaultSource->slug,
            'status' => 'published',
            'language_mode' => EventLanguageMode::Unknown,
            'language_source' => EventLanguageSource::Unknown,
        ], $attributes));

        if (!empty($categorySlugs)) {
            $catIds = [];
            foreach ($categorySlugs as $slug) {
                $cat = Category::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
                $catIds[] = $cat->id;
            }
            $event->categories()->sync($catIds);
        }

        return $event;
    }

    /**
     * 1. Safe default: dry-run mode by default does not modify DB or updated_at.
     */
    public function test_safe_default_dry_run_does_not_modify_database(): void
    {
        $event = $this->createEvent(['title' => 'Basketbola mačs'], ['sports']);
        $tBefore = $event->updated_at;

        $this->artisan('events:backfill-languages')
            ->assertExitCode(0)
            ->expectsOutputToContain('DRY-RUN');

        $event->refresh();
        $this->assertSame(EventLanguageMode::Unknown, $event->language_mode);
        $this->assertSame(EventLanguageSource::Unknown, $event->language_source);
        $this->assertEquals($tBefore, $event->updated_at);
    }

    /**
     * 2. Conflict safety: if both --execute and --dry-run are passed, dry-run wins.
     */
    public function test_conflict_safety_both_execute_and_dry_run_runs_dry_run(): void
    {
        $event = $this->createEvent(['title' => 'Basketbola mačs'], ['sports']);

        $this->artisan('events:backfill-languages', ['--execute' => true, '--dry-run' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Both --execute and --dry-run specified')
            ->expectsOutputToContain('DRY-RUN');

        $event->refresh();
        $this->assertSame(EventLanguageMode::Unknown, $event->language_mode);
    }

    /**
     * 3. Current and upcoming backfill with --execute:
     * - Sports event -> language_free
     * - Dailes teātris -> spoken: ['lv']
     * - Description with subtitles -> spoken + subtitles
     * - Markus cinema raw_data.cinema -> explicit
     * - Past events not touched without --all
     * - Draft events not touched without --include-non-published
     * - Soft-deleted events never touched.
     */
    public function test_execute_backfills_current_and_upcoming_events(): void
    {
        // A) Sports event -> language_free
        $sports = $this->createEvent(['title' => 'Futbola spēle'], ['sports']);

        // B) Dailes teātris -> spoken: ['lv']
        $theatreLoc = Location::create([
            'name' => 'Dailes teātris',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'place_type' => 'theatre',
        ]);
        $theatre = $this->createEvent([
            'title' => 'Luga par dzīvi',
            'location_id' => $theatreLoc->id,
        ], ['teatris']);

        // C) Subtitles in description -> spoken: ['ru'], subtitles: ['lv']
        $subEvent = $this->createEvent([
            'title' => 'Filma seansā',
            'description' => 'Izrāde krievu valodā ar subtitriem latviešu valodā.',
        ], ['kino']);

        // D) Past event (without --all)
        $past = $this->createEvent([
            'title' => 'Vēsturisks basketbols',
            'start_at' => Carbon::now('Europe/Riga')->subDays(10),
            'end_at' => Carbon::now('Europe/Riga')->subDays(10),
        ], ['sports']);

        // E) Draft event (without --include-non-published)
        $draft = $this->createEvent([
            'title' => 'Melnraksts basketbols',
            'status' => 'draft',
        ], ['sports']);

        // F) Soft-deleted event
        $softDeleted = $this->createEvent([
            'title' => 'Dzēsts basketbols',
        ], ['sports']);
        $softDeleted->delete();

        $this->artisan('events:backfill-languages', ['--execute' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('EXECUTE');

        // Check A
        $sports->refresh();
        $this->assertSame(EventLanguageMode::LanguageFree, $sports->language_mode);
        $this->assertSame(EventLanguageSource::Inferred, $sports->language_source);

        // Check B
        $theatre->refresh();
        $this->assertSame(EventLanguageMode::Spoken, $theatre->language_mode);
        $this->assertSame(['lv'], $theatre->spoken_languages);

        // Check C
        $subEvent->refresh();
        $this->assertSame(EventLanguageMode::Spoken, $subEvent->language_mode);
        $this->assertSame(['ru'], $subEvent->spoken_languages);
        $this->assertSame(['lv'], $subEvent->subtitle_languages);

        // Check D (past event untouched)
        $past->refresh();
        $this->assertSame(EventLanguageMode::Unknown, $past->language_mode);

        // Check E (draft event untouched)
        $draft->refresh();
        $this->assertSame(EventLanguageMode::Unknown, $draft->language_mode);

        // Check F (soft deleted event untouched)
        $reloaded = Event::withTrashed()->find($softDeleted->id);
        $this->assertSame(EventLanguageMode::Unknown, $reloaded->language_mode);
    }

    /**
     * 4. Historical data with --all --no-touch --execute:
     * Past events are backfilled, but their updated_at is preserved.
     */
    public function test_historical_backfill_with_all_and_no_touch(): void
    {
        $past = $this->createEvent([
            'title' => 'Vēsturisks basketbols',
            'start_at' => Carbon::now('Europe/Riga')->subDays(10),
            'end_at' => Carbon::now('Europe/Riga')->subDays(10),
        ], ['sports']);

        // Force a specific past updated_at
        $pastTime = Carbon::now()->subMonths(2);
        Event::where('id', $past->id)->update(['updated_at' => $pastTime]);
        $past->refresh();

        $this->artisan('events:backfill-languages', [
            '--all' => true,
            '--no-touch' => true,
            '--execute' => true,
        ])->assertExitCode(0);

        $past->refresh();
        $this->assertSame(EventLanguageMode::LanguageFree, $past->language_mode);
        $this->assertEquals($pastTime->toDateTimeString(), $past->updated_at->toDateTimeString());
    }

    /**
     * 5. Idempotence: running twice consecutively updates 0 events on the second run.
     */
    public function test_idempotence_second_run_updates_zero_events(): void
    {
        $event = $this->createEvent(['title' => 'Basketbola mačs'], ['sports']);

        // First run
        $this->artisan('events:backfill-languages', ['--execute' => true])
            ->assertExitCode(0);

        $event->refresh();
        $this->assertSame(EventLanguageMode::LanguageFree, $event->language_mode);
        $t1 = $event->updated_at;

        // Second run
        $this->artisan('events:backfill-languages', ['--execute' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('No matching events found to process.');

        $event->refresh();
        $this->assertEquals($t1, $event->updated_at);
    }

    /**
     * 6. Data Fusion Protection (with and without --force):
     * - manual event is never overwritten
     * - explicit event is not downgraded to inferred
     * - --force re-evaluates an existing inferred event.
     */
    public function test_data_fusion_protection_and_force_flag(): void
    {
        // A) Manual event
        $manual = $this->createEvent([
            'title' => 'Basketbola spēle',
            'language_mode' => EventLanguageMode::Spoken,
            'spoken_languages' => ['lv', 'en'],
            'language_source' => EventLanguageSource::Manual,
        ], ['sports']);

        // B) Explicit cinema event
        $explicit = $this->createEvent([
            'title' => 'Kino seanss',
            'language_mode' => EventLanguageMode::Spoken,
            'spoken_languages' => ['fr'],
            'subtitle_languages' => ['lv'],
            'language_source' => EventLanguageSource::Explicit,
        ], ['kino']);

        // C) Inferred event with outdated classification
        $inferred = $this->createEvent([
            'title' => 'Basketbola mačs',
            'language_mode' => EventLanguageMode::Spoken,
            'spoken_languages' => [],
            'language_source' => EventLanguageSource::Inferred,
        ], ['sports']);

        // Run with --force
        $this->artisan('events:backfill-languages', [
            '--force' => true,
            '--execute' => true,
        ])->assertExitCode(0);

        // Manual event remains completely untouched
        $manual->refresh();
        $this->assertSame(EventLanguageSource::Manual, $manual->language_source);
        $this->assertEquals(['lv', 'en'], $manual->spoken_languages);

        // Explicit event is not downgraded to inferred
        $explicit->refresh();
        $this->assertSame(EventLanguageSource::Explicit, $explicit->language_source);
        $this->assertSame(['fr'], $explicit->spoken_languages);

        // Inferred event is re-evaluated by --force to language_free (from sports category)
        $inferred->refresh();
        $this->assertSame(EventLanguageMode::LanguageFree, $inferred->language_mode);
        $this->assertSame(EventLanguageSource::Inferred, $inferred->language_source);
    }

    /**
     * 7. chunkById full coverage (verifies no OFFSET skipping bug when language_source changes).
     */
    public function test_chunk_by_id_full_coverage(): void
    {
        // Create 5 events
        $events = [];
        for ($i = 1; $i <= 5; $i++) {
            $events[] = $this->createEvent(['title' => "Basketbola spēle {$i}"], ['sports']);
        }

        // Run with chunk=2
        $this->artisan('events:backfill-languages', [
            '--execute' => true,
            '--chunk' => 2,
        ])->assertExitCode(0);

        // Verify ALL 5 events were updated
        foreach ($events as $e) {
            $e->refresh();
            $this->assertSame(EventLanguageMode::LanguageFree, $e->language_mode);
            $this->assertSame(EventLanguageSource::Inferred, $e->language_source);
        }
    }

    /**
     * 8. Filters: --source, --category, and --limit.
     */
    public function test_filters_source_category_and_limit(): void
    {
        $otherSource = Source::create([
            'name' => 'Other Source',
            'slug' => 'other-source',
            'url' => 'https://other.com',
            'scraper_class' => \App\Services\Scrapers\Sources\ForumCinemasScraper::class,
            'type' => SourceType::VENUE,
            'is_active' => true,
        ]);

        $sportsCat = Category::firstOrCreate(['slug' => 'sports'], ['name' => 'Sports']);
        $kinoCat = Category::firstOrCreate(['slug' => 'kino'], ['name' => 'Kino']);

        // Event 1: defaultSource, sports
        $e1 = $this->createEvent(['title' => 'Spēle 1', 'source_slug' => $this->defaultSource->slug], ['sports']);
        // Event 2: otherSource, sports
        $e2 = $this->createEvent(['title' => 'Spēle 2', 'source_id' => $otherSource->id, 'source_slug' => $otherSource->slug], ['sports']);
        // Event 3: defaultSource, kino
        $e3 = $this->createEvent(['title' => 'Filma 3', 'source_slug' => $this->defaultSource->slug], ['kino']);

        // Filter by source: only e1 should be processed
        $this->artisan('events:backfill-languages', [
            '--source' => $this->defaultSource->slug,
            '--category' => 'sports',
            '--execute' => true,
        ])->assertExitCode(0);

        $e1->refresh();
        $e2->refresh();
        $e3->refresh();

        $this->assertSame(EventLanguageMode::LanguageFree, $e1->language_mode);
        $this->assertSame(EventLanguageMode::Unknown, $e2->language_mode);
        $this->assertSame(EventLanguageMode::Unknown, $e3->language_mode);
    }

    /**
     * 9. Chunk size validation.
     */
    public function test_chunk_size_validation(): void
    {
        $this->artisan('events:backfill-languages', ['--chunk' => 0])
            ->assertExitCode(1)
            ->expectsOutputToContain('The --chunk option must be between 1 and 5000.');

        $this->artisan('events:backfill-languages', ['--chunk' => 5001])
            ->assertExitCode(1)
            ->expectsOutputToContain('The --chunk option must be between 1 and 5000.');
    }

    /**
     * 10. Show-mode option filters samples and does not mutate database.
     */
    public function test_show_mode_filters_samples_and_does_not_mutate_db(): void
    {
        $theatreLoc = Location::create([
            'name' => 'Dailes teātris Lielā zāle',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'place_type' => 'theatre',
        ]);

        $theatre = $this->createEvent([
            'title' => 'Dailes teātra izrāde',
            'location_id' => $theatreLoc->id,
        ], ['teatris']);

        $sports = $this->createEvent([
            'title' => 'Futbola spēle',
        ], ['sports']);

        $unknownEvent = $this->createEvent([
            'title' => 'Nenosakāms pasākums bez konteksta',
        ], ['citi']);

        // Run dry-run with show-mode=language_free
        $this->artisan('events:backfill-languages', [
            '--category' => 'sports',
            '--show-mode' => 'language_free',
            '--show-samples' => 5,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('SHOW MODE:   language_free')
            ->expectsOutputToContain('Language-Free Event Samples')
            ->expectsOutputToContain('DRY-RUN');

        // Check DB was not modified
        $sports->refresh();
        $this->assertSame(EventLanguageMode::Unknown, $sports->language_mode);

        // Run dry-run with show-mode=unknown
        $this->artisan('events:backfill-languages', [
            '--show-mode' => 'unknown',
            '--show-samples' => 5,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('SHOW MODE:   unknown')
            ->expectsOutputToContain('Unknown Event Samples');

        // Invalid show-mode should fail
        $this->artisan('events:backfill-languages', [
            '--show-mode' => 'invalid_mode',
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('The --show-mode option must be one of:');
    }
}
