<?php

namespace App\Console\Commands;

use App\DTO\EventLanguageData;
use App\Enums\EventLanguageMode;
use App\Enums\EventLanguageSource;
use App\Models\Event;
use App\Services\EventLanguageService;
use App\Services\Language\EventLanguageDetector;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class BackfillEventLanguagesCommand extends Command
{
    protected $signature = 'events:backfill-languages
        {--execute : Faktiski saglabāt izmaiņas datubāzē (bez šī karodziņa darbojas dry-run režīmā)}
        {--dry-run : Eksplicīts simulācijas režīms bez izmaiņām DB (noklusējums)}
        {--all : Apstrādāt visus publicētos pasākumus, ieskaitot pagātnes pasākumus (noklusējumā tikai šodienas un gaidāmos)}
        {--include-non-published : Iekļaut arī draft, archived un cancelled statusa pasākumus}
        {--force : Pārvērtēt arī jau \'inferred\' pasākumus (nekad nepārraksta \'manual\' un nepazemina \'explicit\')}
        {--source= : Filtrēt tikai pēc konkrēta source_slug (piem., apollo-kino, bilesu-paradize)}
        {--category= : Filtrēt tikai pēc konkrēta kategorijas slug (piem., teatris, kino)}
        {--chunk=500 : Pakešu izmērs chunkById() iterācijai}
        {--limit= : Maksimālais apstrādājamo pasākumu skaits (noder ātrai pārbaudei)}
        {--no-touch : Nemainīt events.updated_at (paredzēts pagātnes datu backfill, lai neizraisītu Piedzivo.lv delta sync)}
        {--show-samples=10 : Cik paraugu izvadīt tabulā pēc --show-mode filtra (0 = slēpt)}
        {--show-mode= : Filtrēt paraugu tabulu pēc konkrēta rezultāta režīma: unresolved (noklusējums), language_free, spoken_with_lang, spoken_empty, unknown}';

    protected $description = 'Backfills language data (mode, spoken, subtitles, source) for existing events using 3-level deterministic rules';

    private const CANONICAL_CATEGORIES = [
        'sports',
        'izstades',
        'muzika',
        'svetki',
        'teatris',
        'kino',
        'seminari',
        'berniem',
        'citi',
    ];

    public const ALLOWED_SHOW_MODES = [
        'unresolved',
        'language_free',
        'spoken_with_lang',
        'spoken_empty',
        'unknown',
    ];

    private EventLanguageDetector $detector;

    public function __construct(EventLanguageDetector $detector)
    {
        parent::__construct();
        $this->detector = $detector;
    }

    public function handle(): int
    {
        $chunkSize = (int) $this->option('chunk');
        if ($chunkSize < 1 || $chunkSize > 5000) {
            $this->error('The --chunk option must be between 1 and 5000.');
            return self::FAILURE;
        }

        $hasExecute = (bool) $this->option('execute');
        $hasDryRun = (bool) $this->option('dry-run');

        // Safety conflict resolution: if both are specified, --dry-run wins
        if ($hasExecute && $hasDryRun) {
            $this->line('<comment>Both --execute and --dry-run specified. Running in DRY-RUN mode for safety.</comment>');
            $isDryRun = true;
        } else {
            $isDryRun = !$hasExecute;
        }

        $allDates = (bool) $this->option('all');
        $includeNonPublished = (bool) $this->option('include-non-published');
        $force = (bool) $this->option('force');
        $sourceSlug = $this->option('source') ? trim((string) $this->option('source')) : null;
        $categorySlug = $this->option('category') ? trim((string) $this->option('category')) : null;
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $noTouch = (bool) $this->option('no-touch');
        $showSamples = (int) $this->option('show-samples');
        $showMode = $this->option('show-mode') ? trim((string) $this->option('show-mode')) : 'unresolved';

        if (!in_array($showMode, self::ALLOWED_SHOW_MODES, true)) {
            $this->error("The --show-mode option must be one of: " . implode(', ', self::ALLOWED_SHOW_MODES) . ".");
            return self::FAILURE;
        }

        $todayStart = Carbon::now('Europe/Riga')->startOfDay();

        // -------------------------------------------------------------
        // 1. Console Header
        // -------------------------------------------------------------
        $this->info('===============================================================');
        $this->info('           Sodiena.lv Event Languages Backfill Engine          ');
        $this->info('===============================================================');

        if ($isDryRun) {
            $this->warn('MODE:        DRY-RUN (Simulated run. No changes will be saved to DB)');
        } else {
            $this->info('MODE:        EXECUTE (Changes will be written to DB)');
        }

        $this->line('TIME SCOPE:  ' . ($allDates ? 'All dates (including past events)' : "Current & upcoming only (>= {$todayStart->toDateString()})"));
        $this->line('STATUS:      ' . ($includeNonPublished ? 'All statuses (published, draft, archived, cancelled)' : 'Published only'));
        $this->line('FORCE:       ' . ($force ? 'Yes (re-evaluates existing inferred events)' : 'No (processes unknown events only)'));
        $this->line('TIMESTAMPS:  ' . ($noTouch ? 'Disabled (--no-touch, updated_at preserved)' : 'Enabled (updates updated_at on change)'));

        if ($sourceSlug) {
            $this->line('SOURCE:      ' . $sourceSlug);
        }
        if ($categorySlug) {
            $this->line('CATEGORY:    ' . $categorySlug);
        }
        if ($limit !== null) {
            $this->line('LIMIT:       ' . $limit);
        }
        $this->line('SHOW MODE:   ' . $showMode);
        $this->line('CHUNK SIZE:  ' . $chunkSize);
        $this->newLine();

        // -------------------------------------------------------------
        // 2. Query Builder
        // -------------------------------------------------------------
        $query = Event::query()->whereNull('deleted_at');

        if (!$includeNonPublished) {
            $query->where('status', 'published');
        }

        if (!$allDates) {
            $query->where(function ($q) use ($todayStart) {
                $q->where('start_at', '>=', $todayStart)
                    ->orWhere(function ($sub) use ($todayStart) {
                        $sub->whereNotNull('end_at')->where('end_at', '>=', $todayStart);
                    });
            });
        }

        if (!$force) {
            $query->where('language_source', EventLanguageSource::Unknown->value);
        } else {
            // Never re-process manual events even with --force
            $query->where('language_source', '!=', EventLanguageSource::Manual->value);
        }

        if ($sourceSlug) {
            $query->where('source_slug', $sourceSlug);
        }

        if ($categorySlug) {
            $query->whereHas('categories', fn ($q) => $q->where('slug', $categorySlug));
        }

        $query->with(['categories', 'location', 'source']);

        $totalMatching = (clone $query)->count();
        if ($totalMatching === 0) {
            $this->info('No matching events found to process.');
            return self::SUCCESS;
        }

        $totalToProcess = $limit !== null ? min($limit, $totalMatching) : $totalMatching;
        $this->info("Found {$totalMatching} matching events. Processing {$totalToProcess} events...");

        // -------------------------------------------------------------
        // 3. Batch Processing with chunkById()
        // -------------------------------------------------------------
        $scannedCount = 0;
        $updatedCount = 0;
        $unchangedCount = 0;
        $protectedCount = 0;

        $modeStats = [
            'language_free' => 0,
            'spoken_with_lang' => 0,
            'spoken_empty' => 0,
            'unknown' => 0,
        ];

        $sourceStats = [
            'manual' => 0,
            'explicit' => 0,
            'inferred' => 0,
            'unknown' => 0,
        ];

        $categoryStats = [];
        foreach (self::CANONICAL_CATEGORIES as $cat) {
            $categoryStats[$cat] = [
                'total' => 0,
                'language_free' => 0,
                'spoken_with_lang' => 0,
                'spoken_empty' => 0,
                'unknown' => 0,
            ];
        }

        $sourceUnresolvedStats = [];
        $samples = [];

        $startTime = microtime(true);
        $progressBar = $this->output->createProgressBar($totalToProcess);
        $progressBar->start();

        $query->chunkById($chunkSize, function ($events) use (
            &$scannedCount, &$updatedCount, &$unchangedCount, &$protectedCount,
            &$modeStats, &$sourceStats, &$categoryStats, &$sourceUnresolvedStats,
            &$samples, $isDryRun, $force, $noTouch, $limit, $showSamples, $showMode, $progressBar
        ) {
            foreach ($events as $event) {
                if ($limit !== null && $scannedCount >= $limit) {
                    return false; // Stop chunk iteration
                }

                $scannedCount++;
                $progressBar->advance();

                // 1. Check Manual protection (never touched)
                if ($event->language_source === EventLanguageSource::Manual) {
                    $protectedCount++;
                    $unchangedCount++;
                    $this->accumulateStats($event, $modeStats, $sourceStats, $categoryStats, $sourceUnresolvedStats, $samples, $showSamples, $showMode);
                    continue;
                }

                // 2. Run deterministic detection cascade (Level 1 -> Level 2 -> Level 3)
                $detected = $this->detector->detectForEvent($event);

                if ($detected === null) {
                    $detected = new EventLanguageData(
                        mode: EventLanguageMode::Unknown,
                        spokenLanguages: [],
                        subtitleLanguages: [],
                        source: EventLanguageSource::Unknown
                    );
                }

                // 3. Check Explicit protection: inferred result cannot downgrade explicit
                if ($event->language_source === EventLanguageSource::Explicit && $detected->source->isLowerThan(EventLanguageSource::Explicit)) {
                    $protectedCount++;
                    $unchangedCount++;
                    $this->accumulateStats($event, $modeStats, $sourceStats, $categoryStats, $sourceUnresolvedStats, $samples, $showSamples, $showMode);
                    continue;
                }

                // 4. Apply via EventLanguageService in memory ($persist = false)
                $changed = EventLanguageService::applyToEvent($event, $detected, persist: false, force: $force);

                if ($changed) {
                    $updatedCount++;
                    if (!$isDryRun) {
                        if ($noTouch) {
                            $event->timestamps = false;
                            $event->save();
                            $event->timestamps = true;
                        } else {
                            $event->save();
                        }
                    }
                } else {
                    $unchangedCount++;
                }

                // Accumulate statistics based on the resulting event state
                $this->accumulateStats($event, $modeStats, $sourceStats, $categoryStats, $sourceUnresolvedStats, $samples, $showSamples, $showMode);
            }
        });

        $progressBar->finish();
        $this->newLine(2);

        $duration = round(microtime(true) - $startTime, 2);

        // -------------------------------------------------------------
        // 4. Summary Table
        // -------------------------------------------------------------
        $this->info('--- Summary Report ---');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total scanned (Scanned)', $scannedCount],
                [$isDryRun ? 'Would update (Updated)' : 'Successfully updated (Updated)', $updatedCount],
                ['Unchanged / Skipped', $unchangedCount],
                ['Protected (manual / explicit)', $protectedCount],
                ['Remaining unknown', $modeStats['unknown']],
                ['Execution time', $duration . ' s'],
            ]
        );

        // -------------------------------------------------------------
        // 5. Language Mode & Source Breakdown
        // -------------------------------------------------------------
        $this->info('--- Results by Language Mode & Source ---');
        $modeRows = [
            ['Language-free (no language barrier)', $modeStats['language_free'], $this->formatPercent($modeStats['language_free'], $scannedCount)],
            ['Spoken with language (spoken_languages set)', $modeStats['spoken_with_lang'], $this->formatPercent($modeStats['spoken_with_lang'], $scannedCount)],
            ['Spoken without language (spoken: [])', $modeStats['spoken_empty'], $this->formatPercent($modeStats['spoken_empty'], $scannedCount)],
            ['Unknown (mode: unknown)', $modeStats['unknown'], $this->formatPercent($modeStats['unknown'], $scannedCount)],
        ];
        $this->table(['Language Mode', 'Count', 'Share'], $modeRows);

        $srcRows = [
            ['Manual (verified by humans)', $sourceStats['manual'], $this->formatPercent($sourceStats['manual'], $scannedCount)],
            ['Explicit (cinema XML / structured)', $sourceStats['explicit'], $this->formatPercent($sourceStats['explicit'], $scannedCount)],
            ['Inferred (text analysis & category rules)', $sourceStats['inferred'], $this->formatPercent($sourceStats['inferred'], $scannedCount)],
            ['Unknown (unresolved)', $sourceStats['unknown'], $this->formatPercent($sourceStats['unknown'], $scannedCount)],
        ];
        $this->table(['Language Source', 'Count', 'Share'], $srcRows);

        // -------------------------------------------------------------
        // 6. Breakdown by 9 Canonical Categories
        // -------------------------------------------------------------
        $this->info('--- Breakdown by Canonical Category ---');
        $catRows = [];
        foreach (self::CANONICAL_CATEGORIES as $cat) {
            $data = $categoryStats[$cat];
            $total = $data['total'];
            $resolved = $data['language_free'] + $data['spoken_with_lang'];
            $coverage = $total > 0 ? round(($resolved / $total) * 100, 1) . '%' : 'N/A';

            $catRows[] = [
                ucfirst($cat),
                $total,
                $data['language_free'],
                $data['spoken_with_lang'],
                $data['spoken_empty'],
                $data['unknown'],
                $coverage,
            ];
        }
        $this->table(
            ['Category', 'Total', 'Language-free', 'Spoken (with lang)', 'Spoken ([])', 'Unknown', 'Coverage %'],
            $catRows
        );

        // -------------------------------------------------------------
        // 7. Top 10 Sources with Highest Unresolved Count
        // -------------------------------------------------------------
        if (!empty($sourceUnresolvedStats)) {
            uasort($sourceUnresolvedStats, fn ($a, $b) => $b['unresolved'] <=> $a['unresolved']);
            $topSources = array_slice($sourceUnresolvedStats, 0, 10, true);

            $this->info('--- Top 10 Sources with Unresolved Languages (Unknown or Spoken []) ---');
            $sourceRows = [];
            foreach ($topSources as $sSlug => $sData) {
                $sourceRows[] = [
                    $sSlug,
                    $sData['total'],
                    $sData['unknown'],
                    $sData['spoken_empty'],
                    $sData['unresolved'],
                ];
            }
            $this->table(['Source Slug', 'Total Events', 'Unknown', 'Spoken ([])', 'Unresolved Total'], $sourceRows);
        }

        // -------------------------------------------------------------
        // 8. Sample Events Table
        // -------------------------------------------------------------
        if ($showSamples > 0 && !empty($samples)) {
            $modeLabel = match ($showMode) {
                'unresolved' => 'Unresolved',
                'language_free' => 'Language-Free',
                'spoken_with_lang' => 'Spoken (with language)',
                'spoken_empty' => 'Spoken (empty)',
                'unknown' => 'Unknown',
                default => ucfirst($showMode),
            };
            $this->info("--- {$modeLabel} Event Samples (First " . count($samples) . ") ---");
            $sampleRows = [];
            foreach ($samples as $s) {
                $sampleRows[] = [
                    $s['id'],
                    $s['source'],
                    $s['categories'],
                    $s['venue'],
                    $s['mode'],
                    Str::limit($s['title'], 60),
                ];
            }
            $this->table(['ID', 'Source', 'Categories', 'Venue', 'Mode', 'Title'], $sampleRows);
        }

        if ($isDryRun) {
            $this->warn('Finished in DRY-RUN mode. To persist changes to the database, run with --execute.');
        } else {
            $this->info('Finished successfully. All changes have been written to the database.');
        }

        return self::SUCCESS;
    }

    private function accumulateStats(
        Event $event,
        array &$modeStats,
        array &$sourceStats,
        array &$categoryStats,
        array &$sourceUnresolvedStats,
        array &$samples,
        int $showSamples,
        string $showMode = 'unresolved'
    ): void {
        $mode = $event->language_mode instanceof EventLanguageMode
            ? $event->language_mode
            : (EventLanguageMode::tryFrom((string) $event->language_mode) ?? EventLanguageMode::Unknown);

        $source = $event->language_source instanceof EventLanguageSource
            ? $event->language_source
            : (EventLanguageSource::tryFrom((string) $event->language_source) ?? EventLanguageSource::Unknown);

        $spoken = $event->spoken_languages ?? [];
        $isSpokenEmpty = empty($spoken);

        // Classify mode bucket
        if ($mode === EventLanguageMode::LanguageFree) {
            $modeBucket = 'language_free';
        } elseif ($mode === EventLanguageMode::Spoken) {
            $modeBucket = $isSpokenEmpty ? 'spoken_empty' : 'spoken_with_lang';
        } else {
            $modeBucket = 'unknown';
        }

        $modeStats[$modeBucket]++;
        $sourceStats[$source->value]++;

        // Category stats
        $catSlugs = $event->relationLoaded('categories') ? $event->categories->pluck('slug')->all() : [];
        if (empty($catSlugs)) {
            $catSlugs = ['citi'];
        }

        foreach ($catSlugs as $slug) {
            if (isset($categoryStats[$slug])) {
                $categoryStats[$slug]['total']++;
                $categoryStats[$slug][$modeBucket]++;
            }
        }

        // Source unresolved tracking
        $srcSlug = $event->source_slug ?: ($event->relationLoaded('source') ? ($event->source?->slug ?: 'unknown') : 'unknown');
        if (!isset($sourceUnresolvedStats[$srcSlug])) {
            $sourceUnresolvedStats[$srcSlug] = [
                'total' => 0,
                'unknown' => 0,
                'spoken_empty' => 0,
                'unresolved' => 0,
            ];
        }
        $sourceUnresolvedStats[$srcSlug]['total']++;
        if ($modeBucket === 'unknown' || $modeBucket === 'spoken_empty') {
            $sourceUnresolvedStats[$srcSlug][$modeBucket]++;
            $sourceUnresolvedStats[$srcSlug]['unresolved']++;
        }

        // Collect sample if matching requested show-mode
        $matchesShowMode = match ($showMode) {
            'unresolved' => ($modeBucket === 'unknown' || $modeBucket === 'spoken_empty'),
            'language_free' => ($modeBucket === 'language_free'),
            'spoken_with_lang' => ($modeBucket === 'spoken_with_lang'),
            'spoken_empty' => ($modeBucket === 'spoken_empty'),
            'unknown' => ($modeBucket === 'unknown'),
            default => false,
        };

        if ($matchesShowMode && count($samples) < $showSamples) {
            $venueName = $event->relationLoaded('location') ? ($event->location?->name ?: '') : '';
            $samples[] = [
                'id' => $event->id,
                'source' => $srcSlug,
                'categories' => implode(', ', $catSlugs),
                'venue' => $venueName,
                'mode' => $mode->value,
                'title' => (string) $event->title,
            ];
        }
    }

    private function formatPercent(int $count, int $total): string
    {
        if ($total <= 0) {
            return '0.0%';
        }
        return round(($count / $total) * 100, 1) . '%';
    }
}
