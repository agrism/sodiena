<?php

namespace Tests\Unit;

use App\DTO\EventLanguageData;
use App\Enums\EventLanguageMode;
use App\Enums\EventLanguageSource;
use App\Models\Event;
use App\Services\EventLanguageService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventLanguageServiceTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // 1. Language Code Normalization Tests
    // =========================================================================

    public function test_normalizes_null_and_empty_codes(): void
    {
        $this->assertSame([], EventLanguageService::normalizeCodes(null));
        $this->assertSame([], EventLanguageService::normalizeCodes([]));
        $this->assertSame([], EventLanguageService::normalizeCodes(['', '   ']));
    }

    public function test_normalizes_iso_639_1_lowercase(): void
    {
        $input = ['LV', 'En', 'rU'];
        $expected = ['en', 'lv', 'ru']; // Alphabetically sorted
        $this->assertSame($expected, EventLanguageService::normalizeCodes($input));
    }

    public function test_maps_iso_639_2_three_letter_codes_to_iso_639_1(): void
    {
        $input = ['lav', 'lat', 'eng', 'rus', 'deu', 'ger', 'fra', 'fre', 'ita', 'spa', 'ukr', 'lit', 'est', 'pol', 'fin', 'swe', 'nor', 'dan', 'nld', 'dut', 'por', 'ces', 'cze', 'jpn', 'kor', 'zho', 'chi'];
        $result = EventLanguageService::normalizeCodes($input);

        $this->assertContains('lv', $result);
        $this->assertContains('en', $result);
        $this->assertContains('ru', $result);
        $this->assertContains('de', $result);
        $this->assertContains('fr', $result);
        $this->assertContains('it', $result);
        $this->assertContains('es', $result);
        $this->assertContains('uk', $result);
        $this->assertContains('lt', $result);
        $this->assertContains('et', $result);
        $this->assertContains('pl', $result);
        $this->assertContains('fi', $result);
        $this->assertContains('sv', $result);
        $this->assertContains('no', $result);
        $this->assertContains('da', $result);
        $this->assertContains('nl', $result);
        $this->assertContains('pt', $result);
        $this->assertContains('cs', $result);
        $this->assertContains('ja', $result);
        $this->assertContains('ko', $result);
        $this->assertContains('zh', $result);
    }

    public function test_strips_region_suffixes(): void
    {
        $input = ['en-US', 'lv_LV', 'ru-RU', 'de-DE'];
        $expected = ['de', 'en', 'lv', 'ru'];
        $this->assertSame($expected, EventLanguageService::normalizeCodes($input));
    }

    public function test_discards_invalid_values_and_sorts_alphabetically(): void
    {
        $input = ['none', 'unknown', '123', '', 'a', 'abcd', 'lv', 'en', 'lv'];
        $expected = ['en', 'lv']; // duplicates removed, sorted, garbage discarded
        $this->assertSame($expected, EventLanguageService::normalizeCodes($input));
    }

    // =========================================================================
    // 2. Semantic Consistency Tests
    // =========================================================================

    public function test_non_empty_languages_automatically_set_spoken_mode(): void
    {
        $data = new EventLanguageData(
            mode: EventLanguageMode::Unknown,
            spokenLanguages: ['lv'],
            subtitleLanguages: ['en'],
            source: EventLanguageSource::Explicit
        );

        $normalized = EventLanguageService::normalizePayload($data);
        $this->assertSame(EventLanguageMode::Spoken, $normalized->mode);
        $this->assertSame(['lv'], $normalized->spokenLanguages);
        $this->assertSame(['en'], $normalized->subtitleLanguages);
    }

    public function test_language_free_mode_forces_empty_language_arrays(): void
    {
        $data = new EventLanguageData(
            mode: EventLanguageMode::LanguageFree,
            spokenLanguages: ['lv', 'en'],
            subtitleLanguages: ['ru'],
            source: EventLanguageSource::Inferred
        );

        $normalized = EventLanguageService::normalizePayload($data);
        $this->assertSame(EventLanguageMode::LanguageFree, $normalized->mode);
        $this->assertSame([], $normalized->spokenLanguages);
        $this->assertSame([], $normalized->subtitleLanguages);
    }

    public function test_empty_automated_payload_degrades_source_to_unknown(): void
    {
        $data = new EventLanguageData(
            mode: EventLanguageMode::Unknown,
            spokenLanguages: [],
            subtitleLanguages: [],
            source: EventLanguageSource::Explicit
        );

        $normalized = EventLanguageService::normalizePayload($data);
        $this->assertSame(EventLanguageSource::Unknown, $normalized->source);
    }

    public function test_manual_empty_payload_retains_manual_source(): void
    {
        $manual = EventLanguageData::fromManualInput(EventLanguageMode::Unknown, [], []);
        $this->assertSame(EventLanguageSource::Manual, $manual->source);
    }

    public function test_from_scraper_array_never_grants_manual_source(): void
    {
        $payload = [
            'mode' => 'spoken',
            'spoken' => ['en'],
            'source' => 'manual',
        ];

        $data = EventLanguageData::fromScraperArray($payload);
        $this->assertSame(EventLanguageSource::Inferred, $data->source);
        $this->assertSame(['en'], $data->spokenLanguages);
    }

    // =========================================================================
    // 3. Data Fusion Matrix Tests
    // =========================================================================

    public function test_manual_source_is_never_overwritten_by_lower_sources(): void
    {
        $event = Event::create([
            'title' => 'Test Event',
            'start_at' => Carbon::now()->addDays(2),
            'language_mode' => EventLanguageMode::Spoken,
            'spoken_languages' => ['lv'],
            'subtitle_languages' => ['en'],
            'language_source' => EventLanguageSource::Manual,
        ]);

        // Explicit attempt -> must be ignored
        $explicitAttempt = new EventLanguageData(
            mode: EventLanguageMode::Spoken,
            spokenLanguages: ['ru'],
            subtitleLanguages: [],
            source: EventLanguageSource::Explicit
        );
        $changed = EventLanguageService::applyToEvent($event, $explicitAttempt);
        $this->assertFalse($changed);
        $event->refresh();
        $this->assertSame(['lv'], $event->spoken_languages);
        $this->assertSame(EventLanguageSource::Manual, $event->language_source);

        // Inferred attempt -> must be ignored
        $inferredAttempt = new EventLanguageData(
            mode: EventLanguageMode::LanguageFree,
            spokenLanguages: [],
            subtitleLanguages: [],
            source: EventLanguageSource::Inferred
        );
        $changed = EventLanguageService::applyToEvent($event, $inferredAttempt);
        $this->assertFalse($changed);
        $event->refresh();
        $this->assertSame(EventLanguageMode::Spoken, $event->language_mode);
    }

    public function test_manual_source_overwrites_existing_manual(): void
    {
        $event = Event::create([
            'title' => 'Test Event',
            'start_at' => Carbon::now()->addDays(2),
            'language_mode' => EventLanguageMode::Spoken,
            'spoken_languages' => ['lv'],
            'language_source' => EventLanguageSource::Manual,
        ]);

        $newManual = new EventLanguageData(
            mode: EventLanguageMode::Spoken,
            spokenLanguages: ['en', 'lv'],
            subtitleLanguages: ['ru'],
            source: EventLanguageSource::Manual
        );

        $changed = EventLanguageService::applyToEvent($event, $newManual);
        $this->assertTrue($changed);
        $event->refresh();
        $this->assertSame(['en', 'lv'], $event->spoken_languages);
        $this->assertSame(['ru'], $event->subtitle_languages);
    }

    public function test_explicit_overwrites_inferred_and_unknown_but_resists_inferred(): void
    {
        $event = Event::create([
            'title' => 'Test Event',
            'start_at' => Carbon::now()->addDays(2),
            'language_mode' => EventLanguageMode::Spoken,
            'spoken_languages' => ['lv'],
            'language_source' => EventLanguageSource::Inferred,
        ]);

        // Explicit overwrites Inferred
        $explicitData = new EventLanguageData(
            mode: EventLanguageMode::Spoken,
            spokenLanguages: ['en'],
            subtitleLanguages: ['lv'],
            source: EventLanguageSource::Explicit
        );

        $changed = EventLanguageService::applyToEvent($event, $explicitData);
        $this->assertTrue($changed);
        $event->refresh();
        $this->assertSame(['en'], $event->spoken_languages);
        $this->assertSame(['lv'], $event->subtitle_languages);
        $this->assertSame(EventLanguageSource::Explicit, $event->language_source);

        // Subsequent Inferred attempt is rejected
        $inferredData = new EventLanguageData(
            mode: EventLanguageMode::LanguageFree,
            source: EventLanguageSource::Inferred
        );
        $changedAgain = EventLanguageService::applyToEvent($event, $inferredData);
        $this->assertFalse($changedAgain);
        $event->refresh();
        $this->assertSame(EventLanguageSource::Explicit, $event->language_source);
    }

    public function test_explicit_overwrites_previous_explicit(): void
    {
        $event = Event::create([
            'title' => 'Test Event',
            'start_at' => Carbon::now()->addDays(2),
            'language_mode' => EventLanguageMode::Spoken,
            'spoken_languages' => ['en'],
            'language_source' => EventLanguageSource::Explicit,
        ]);

        $newExplicit = new EventLanguageData(
            mode: EventLanguageMode::Spoken,
            spokenLanguages: ['lv'],
            subtitleLanguages: ['en'],
            source: EventLanguageSource::Explicit
        );

        $changed = EventLanguageService::applyToEvent($event, $newExplicit);
        $this->assertTrue($changed);
        $event->refresh();
        $this->assertSame(['lv'], $event->spoken_languages);
        $this->assertSame(['en'], $event->subtitle_languages);
    }

    public function test_inferred_with_inferred_operates_in_enrich_only_mode(): void
    {
        $event = Event::create([
            'title' => 'Test Event',
            'start_at' => Carbon::now()->addDays(2),
            'language_mode' => EventLanguageMode::Spoken,
            'spoken_languages' => ['lv'],
            'subtitle_languages' => [],
            'language_source' => EventLanguageSource::Inferred,
        ]);

        // Incoming inferred tries to change spoken to ['en'] and subtitles to ['ru']
        $incomingInferred = new EventLanguageData(
            mode: EventLanguageMode::Spoken,
            spokenLanguages: ['en'],
            subtitleLanguages: ['ru'],
            source: EventLanguageSource::Inferred
        );

        $changed = EventLanguageService::applyToEvent($event, $incomingInferred);
        $this->assertTrue($changed);
        $event->refresh();

        // spoken ['lv'] remains intact (not overwritten), while empty subtitles gets enriched with ['ru']
        $this->assertSame(['lv'], $event->spoken_languages);
        $this->assertSame(['ru'], $event->subtitle_languages);
    }

    public function test_no_change_returns_false_and_does_not_update(): void
    {
        $event = Event::create([
            'title' => 'Test Event',
            'start_at' => Carbon::now()->addDays(2),
            'language_mode' => EventLanguageMode::Spoken,
            'spoken_languages' => ['lv'],
            'subtitle_languages' => [],
            'language_source' => EventLanguageSource::Explicit,
        ]);

        $identical = new EventLanguageData(
            mode: EventLanguageMode::Spoken,
            spokenLanguages: ['lv'],
            subtitleLanguages: [],
            source: EventLanguageSource::Explicit
        );

        $this->assertFalse(EventLanguageService::applyToEvent($event, $identical));
    }

    // =========================================================================
    // 4. Consolidation & Duplicate Merging Tests
    // =========================================================================

    public function test_consolidation_explicit_duplicate_enriches_inferred_canonical(): void
    {
        $canonical = Event::create([
            'title' => 'Cinema Show',
            'start_at' => Carbon::now()->addDays(1),
            'language_mode' => EventLanguageMode::Unknown,
            'spoken_languages' => [],
            'language_source' => EventLanguageSource::Unknown,
        ]);

        $duplicate = Event::create([
            'title' => 'Cinema Show Duplicate',
            'start_at' => Carbon::now()->addDays(1),
            'language_mode' => EventLanguageMode::Spoken,
            'spoken_languages' => ['en'],
            'subtitle_languages' => ['lv'],
            'language_source' => EventLanguageSource::Explicit,
        ]);

        $merged = EventLanguageService::mergeFromDuplicate($canonical, $duplicate);
        $this->assertTrue($merged);
        $canonical->refresh();

        $this->assertSame(EventLanguageMode::Spoken, $canonical->language_mode);
        $this->assertSame(['en'], $canonical->spoken_languages);
        $this->assertSame(['lv'], $canonical->subtitle_languages);
        $this->assertSame(EventLanguageSource::Explicit, $canonical->language_source);
    }

    public function test_consolidation_inferred_duplicate_does_not_corrupt_explicit_canonical(): void
    {
        $canonical = Event::create([
            'title' => 'Concert',
            'start_at' => Carbon::now()->addDays(1),
            'language_mode' => EventLanguageMode::Spoken,
            'spoken_languages' => ['lv'],
            'language_source' => EventLanguageSource::Explicit,
        ]);

        $duplicate = Event::create([
            'title' => 'Concert Dup',
            'start_at' => Carbon::now()->addDays(1),
            'language_mode' => EventLanguageMode::LanguageFree,
            'spoken_languages' => [],
            'language_source' => EventLanguageSource::Inferred,
        ]);

        $merged = EventLanguageService::mergeFromDuplicate($canonical, $duplicate);
        $this->assertFalse($merged);
        $canonical->refresh();

        $this->assertSame(['lv'], $canonical->spoken_languages);
        $this->assertSame(EventLanguageSource::Explicit, $canonical->language_source);
    }

    public function test_consolidation_retains_canonical_when_both_are_manual(): void
    {
        $canonical = Event::create([
            'title' => 'Festival',
            'start_at' => Carbon::now()->addDays(1),
            'language_mode' => EventLanguageMode::Spoken,
            'spoken_languages' => ['lv'],
            'language_source' => EventLanguageSource::Manual,
        ]);

        $duplicate = Event::create([
            'title' => 'Festival Dup',
            'start_at' => Carbon::now()->addDays(1),
            'language_mode' => EventLanguageMode::Spoken,
            'spoken_languages' => ['en'],
            'language_source' => EventLanguageSource::Manual,
        ]);

        $merged = EventLanguageService::mergeFromDuplicate($canonical, $duplicate);
        $this->assertFalse($merged);
        $canonical->refresh();

        $this->assertSame(['lv'], $canonical->spoken_languages);
    }

    // =========================================================================
    // 5. Query Scope: Event::accessibleInLanguage()
    // =========================================================================

    public function test_scope_accessible_in_language_matches_correct_events(): void
    {
        // 1. Language-free event (e.g. sports or exhibition)
        $freeEvent = Event::create([
            'title' => 'Basketball Game',
            'start_at' => Carbon::now()->addDays(1),
            'language_mode' => EventLanguageMode::LanguageFree,
            'spoken_languages' => [],
            'subtitle_languages' => [],
            'language_source' => EventLanguageSource::Inferred,
        ]);

        // 2. English spoken event
        $englishSpokenEvent = Event::create([
            'title' => 'English Movie',
            'start_at' => Carbon::now()->addDays(1),
            'language_mode' => EventLanguageMode::Spoken,
            'spoken_languages' => ['en'],
            'subtitle_languages' => [],
            'language_source' => EventLanguageSource::Explicit,
        ]);

        // 3. Latvian movie with English subtitles
        $englishSubtitledEvent = Event::create([
            'title' => 'Latvian Film with EN subs',
            'start_at' => Carbon::now()->addDays(1),
            'language_mode' => EventLanguageMode::Spoken,
            'spoken_languages' => ['lv'],
            'subtitle_languages' => ['en'],
            'language_source' => EventLanguageSource::Explicit,
        ]);

        // 4. Russian spoken only event
        $russianEvent = Event::create([
            'title' => 'Russian Play',
            'start_at' => Carbon::now()->addDays(1),
            'language_mode' => EventLanguageMode::Spoken,
            'spoken_languages' => ['ru'],
            'subtitle_languages' => [],
            'language_source' => EventLanguageSource::Explicit,
        ]);

        // 5. Unknown language event
        $unknownEvent = Event::create([
            'title' => 'Unknown Event',
            'start_at' => Carbon::now()->addDays(1),
            'language_mode' => EventLanguageMode::Unknown,
            'spoken_languages' => [],
            'subtitle_languages' => [],
            'language_source' => EventLanguageSource::Unknown,
        ]);

        // Query for 'en'
        $enResults = Event::accessibleInLanguage('en')->pluck('id')->all();
        $this->assertContains($freeEvent->id, $enResults, 'Language-free events must be accessible in any language');
        $this->assertContains($englishSpokenEvent->id, $enResults, 'English spoken event must be accessible in en');
        $this->assertContains($englishSubtitledEvent->id, $enResults, 'English subtitled event must be accessible in en');
        $this->assertNotContains($russianEvent->id, $enResults, 'Russian-only event must NOT be accessible in en');
        $this->assertNotContains($unknownEvent->id, $enResults, 'Unknown language event must NOT be accessible in en');

        // Query for 'ru'
        $ruResults = Event::accessibleInLanguage('ru')->pluck('id')->all();
        $this->assertContains($freeEvent->id, $ruResults);
        $this->assertContains($russianEvent->id, $ruResults);
        $this->assertNotContains($englishSpokenEvent->id, $ruResults);

        // Query for invalid code
        $invalidResults = Event::accessibleInLanguage('invalid_123')->pluck('id')->all();
        $this->assertEmpty($invalidResults, 'Invalid code query must return empty result');
    }

    // =========================================================================
    // 6. Portable Array Export
    // =========================================================================

    public function test_to_portable_array_exports_manual_as_explicit(): void
    {
        $event = Event::create([
            'title' => 'Masterclass',
            'start_at' => Carbon::now()->addDays(1),
            'language_mode' => EventLanguageMode::Spoken,
            'spoken_languages' => ['lv', 'en'],
            'subtitle_languages' => ['ru'],
            'language_source' => EventLanguageSource::Manual,
        ]);

        $exported = EventLanguageService::toPortableArray($event);

        $this->assertSame('spoken', $exported['mode']);
        $this->assertSame(['en', 'lv'], $exported['spoken']); // normalized / sorted
        $this->assertSame(['ru'], $exported['subtitles']);
        $this->assertSame('explicit', $exported['source'], 'Manual source must be exported as explicit');
    }
}
