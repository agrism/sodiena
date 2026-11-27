<?php

namespace App\Console\Commands;

use App\Enums\EventLanguageSource;
use App\Enums\SourceType;
use App\Models\Event;
use App\Models\EventAction;
use App\Models\EventOccurrence;
use App\Models\EventRedirect;
use App\Models\EventTranslation;
use App\Models\Location;
use App\Models\OccurrenceSource;
use App\Models\Source;
use App\Services\IngestionV2\CanonicalProjectionService;
use App\Services\Scrapers\EventIngestionService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MigrateEventsToV2Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:migrate-to-v2
        {--execute : Faktiski veikt izmaiņas DB (bez šī karodziņa darbojas dry-run režīmā)}
        {--location-id= : Migrēt tikai vienas konkrētas norises vietas pasākumus (testēšanai)}
        {--limit= : Maksimālais apstrādājamo grupu skaits (testēšanai)}
        {--chunk=500 : Pakešu izmērs}
        {--show-samples=20 : Izvadīt konsolē paraugus lielākajām sapludinātajām grupām un anomālijām}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate legacy flat events to v2 locations -> events -> event_occurrences -> occurrence_sources hierarchy';

    protected CanonicalProjectionService $projectionService;

    public function __construct(CanonicalProjectionService $projectionService)
    {
        parent::__construct();
        $this->projectionService = $projectionService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isExecute = (bool) $this->option('execute');
        $locationFilter = $this->option('location-id') !== null ? (int) $this->option('location-id') : null;
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $chunkSize = max(50, (int) $this->option('chunk'));
        $showSamples = max(5, (int) $this->option('show-samples'));

        $this->info("=== Sodiena.lv DB v2 Datu Migrācija ===" . ($isExecute ? ' [EXECUTE MODE]' : ' [DRY-RUN MODE]'));

        // 1. Query unmigrated candidate events (idempotence)
        $baseQuery = Event::query()
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['merged', 'deleted'])
            ->whereDoesntHave('occurrences', function ($q) {
                $q->whereNotNull('legacy_event_id');
            });

        if ($locationFilter !== null) {
            $baseQuery->where('location_id', $locationFilter);
            $this->info("Filtrējam pēc location_id: {$locationFilter}");
        }

        $totalCandidates = $baseQuery->count();
        if ($totalCandidates === 0) {
            $this->info("✅ Visi atlasītie pasākumi jau ir izmigrēti uz v2!");
            return self::SUCCESS;
        }

        $this->info("Kopā atrasti {$totalCandidates} nemigrēti pasākumi.");

        // Stats tracking
        $stats = [
            'total_candidates' => $totalCandidates,
            'canonical_events' => 0,
            'merged_events' => 0,
            'occurrences_created' => 0,
            'sources_created' => 0,
            'redirects_created' => 0,
            'null_location_events' => 0,
            'collisions' => 0,
            'groups_processed' => 0,
        ];

        $sampleGroups = [];

        // 2. Process events with location_id IS NULL (strictly 1:1)
        if ($locationFilter === null) {
            $nullLocationQuery = (clone $baseQuery)->whereNull('location_id');
            $nullCount = $nullLocationQuery->count();
            $stats['null_location_events'] = $nullCount;

            if ($nullCount > 0) {
                $this->info("Apstrādājam {$nullCount} pasākumus bez location_id (1:1 kartējums)...");
                $nullLocationQuery->with(['translations', 'categories', 'source'])
                    ->chunkById($chunkSize, function (Collection $events) use ($isExecute, &$stats, $limit) {
                        foreach ($events as $event) {
                            if ($limit !== null && $stats['groups_processed'] >= $limit) {
                                return false;
                            }

                            if ($isExecute) {
                                DB::transaction(fn () => $this->migrateSingleEvent1to1($event, $stats));
                            } else {
                                $stats['canonical_events']++;
                                $stats['occurrences_created']++;
                                if ($event->source_id) {
                                    $stats['sources_created']++;
                                }
                            }
                            $stats['groups_processed']++;
                        }
                    });
            }
        }

        // 3. Process events with location_id IS NOT NULL (grouped by location)
        $locationsQuery = (clone $baseQuery)->whereNotNull('location_id');
        $locationIds = $locationsQuery->distinct()->pluck('location_id');

        $this->info("Grupējam un migrējam pasākumus {$locationIds->count()} norises vietās...");
        $bar = $this->output->createProgressBar($locationIds->count());
        $bar->start();

        foreach ($locationIds as $locId) {
            if ($limit !== null && $stats['groups_processed'] >= $limit) {
                break;
            }

            $locEvents = (clone $baseQuery)
                ->where('location_id', $locId)
                ->with(['translations', 'categories', 'source', 'location'])
                ->get();

            if ($locEvents->isEmpty()) {
                $bar->advance();
                continue;
            }

            // Cluster events within this location
            $clusters = $this->clusterEventsAtLocation($locEvents);

            foreach ($clusters as $cluster) {
                if ($limit !== null && $stats['groups_processed'] >= $limit) {
                    break;
                }

                // Sliding window split (Anti Cross-Year Merge)
                $subgroups = $this->splitClusterBySlidingWindow($cluster);

                foreach ($subgroups as $subgroup) {
                    if ($limit !== null && $stats['groups_processed'] >= $limit) {
                        break;
                    }

                    $stats['groups_processed']++;

                    if ($subgroup->count() === 1) {
                        $single = $subgroup->first();
                        if ($isExecute) {
                            DB::transaction(fn () => $this->migrateSingleEvent1to1($single, $stats));
                        } else {
                            $stats['canonical_events']++;
                            $stats['occurrences_created']++;
                            if ($single->source_id) {
                                $stats['sources_created']++;
                            }
                        }
                    } else {
                        // Multi-member cluster
                        $winner = $this->pickSubgroupWinner($subgroup);
                        $memberCount = $subgroup->count();
                        $mergedCount = $memberCount - 1;

                        $stats['canonical_events']++;
                        $stats['merged_events'] += $mergedCount;

                        if (count($sampleGroups) < $showSamples || $memberCount > ($sampleGroups[count($sampleGroups) - 1]['count'] ?? 0)) {
                            $minDate = $subgroup->min('start_at')?->format('Y-m-d') ?? '-';
                            $maxDate = $subgroup->max('start_at')?->format('Y-m-d') ?? '-';
                            $sampleGroups[] = [
                                'title' => Str::limit($winner->title, 35),
                                'venue' => Str::limit($winner->location?->name ?? 'Nav vietas', 28),
                                'count' => $memberCount,
                                'dates' => $minDate === $maxDate ? $minDate : "{$minDate} .. {$maxDate}",
                                'winner_id' => $winner->id,
                            ];
                            usort($sampleGroups, fn ($a, $b) => $b['count'] <=> $a['count']);
                            if (count($sampleGroups) > $showSamples) {
                                array_pop($sampleGroups);
                            }
                        }

                        if ($isExecute) {
                            DB::transaction(fn () => $this->migrateSubgroup($winner, $subgroup, $stats));
                        } else {
                            $stats['occurrences_created'] += $memberCount;
                            $stats['sources_created'] += $subgroup->whereNotNull('source_id')->count();
                            $stats['redirects_created'] += $mergedCount * 3; // lv, en, ru
                        }
                    }
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // 4. Output Summary Table
        $this->table(
            ['Metrika', 'Vērtība'],
            [
                ['Režīms', $isExecute ? 'EXECUTE (izmaiņas veiktas DB)' : 'DRY-RUN (tikai pārskats)'],
                ['Kopā kandidātu pasākumu', $stats['total_candidates']],
                ['Pasākumi bez vietas (1:1)', $stats['null_location_events']],
                ['Kanoniskie pasākumi pēc migrācijas', $stats['canonical_events']],
                ['Sapludinātie pasākumi (status = merged)', $stats['merged_events']],
                ['Izveidotās norises (event_occurrences)', $stats['occurrences_created']],
                ['Izveidotie avotu ieraksti (occurrence_sources)', $stats['sources_created']],
                ['Izveidotie 301 redirekti (event_redirects)', $stats['redirects_created']],
                ['Ārējo ID kolīziju skaits (safe fallback)', $stats['collisions']],
            ]
        );

        if (!empty($sampleGroups)) {
            $this->newLine();
            $this->info("Top apvienotās pasākumu grupas (paraugi vizuālai pārbaudei):");
            $this->table(
                ['Kanoniskais Virsraksts', 'Norises vieta', 'Seansi/Skaits', 'Datumu Diapazons', 'Uzvarētāja ID'],
                array_map(fn ($g) => [$g['title'], $g['venue'], $g['count'], $g['dates'], $g['winner_id']], $sampleGroups)
            );
        }

        if (!$isExecute) {
            $this->newLine();
            $this->warn("⚠️  Šis bija DRY-RUN režīms. Lai faktiski izpildītu migrāciju DB, palaidiet ar karodziņu --execute:");
            $this->line("   php artisan events:migrate-to-v2 --execute");
        } else {
            $this->info("🎉 Migrācija veiksmīgi pabeigta!");
        }

        return self::SUCCESS;
    }

    /**
     * Migrate a 1:1 event (no other duplicates in group).
     */
    protected function migrateSingleEvent1to1(Event $event, array &$stats): void
    {
        $status = $this->determineOccurrenceStatus($event);
        $format = $this->extractPresentationFormat($event);
        $auditorium = $this->extractAuditorium($event);

        $startKey = $event->all_day
            ? ($event->start_at ? $event->start_at->format('Y-m-d') : '')
            : ($event->start_at ? $event->start_at->format('Y-m-d H:i') : '');
        $normFmt = mb_strtolower(trim((string) $format), 'UTF-8');
        $normAud = mb_strtolower(trim((string) $auditorium), 'UTF-8');
        $occFp = hash('sha256', "{$event->id}|{$startKey}|{$normFmt}|{$normAud}");

        $occ = EventOccurrence::create([
            'event_id' => $event->id,
            'legacy_event_id' => $event->id,
            'start_at' => $event->start_at,
            'end_at' => $event->end_at,
            'all_day' => (bool) $event->all_day,
            'status' => $status,
            'is_free' => (bool) $event->is_free,
            'price_min' => $event->price_min,
            'price_max' => $event->price_max,
            'currency' => $event->currency ?: 'EUR',
            'ticket_url' => $event->ticket_url,
            'presentation_format' => $format,
            'auditorium' => $auditorium,
            'spoken_languages' => $event->spoken_languages,
            'subtitle_languages' => $event->subtitle_languages,
            'language_source' => $event->language_source instanceof EventLanguageSource ? $event->language_source->value : (string) $event->language_source,
            'occurrence_fingerprint' => $occFp,
        ]);
        $stats['occurrences_created']++;

        if ($event->source_id) {
            $this->createOccurrenceSourceForMember($event, $occ, $stats);
        }

        // Move unassigned event actions
        EventAction::where('event_id', $event->id)
            ->whereNull('event_occurrence_id')
            ->update(['event_occurrence_id' => $occ->id]);

        $rawTitle = $event->translations->firstWhere('locale', 'lv')?->title ?: ($event->getRawOriginal('title') ?: $event->title);
        $canonicalTitle = $this->cleanTitleForCinema($rawTitle, EventIngestionService::isCinemaEvent($rawTitle, $event->location?->name, $event->categories->pluck('name')->all()));
        $canonicalTitle = mb_substr(trim($canonicalTitle), 0, 255, 'UTF-8');
        $canonicalFp = hash('sha256', EventIngestionService::normalizeTitleForComparison($canonicalTitle) . '|' . ($event->location_id ?? 'null'));

        $event->canonical_fingerprint = $canonicalFp;
        $event->occurrences_count = ($status === 'scheduled' || $status === 'postponed') && ($event->end_at ?? $event->start_at) >= now() ? 1 : 0;
        $event->next_start_at = $event->start_at;
        $event->last_end_at = $event->end_at ?? $event->start_at;
        $event->save();

        $stats['canonical_events']++;
    }

    /**
     * Migrate a cluster subgroup with multiple members into $winner.
     */
    protected function migrateSubgroup(Event $winner, Collection $subgroup, array &$stats): void
    {
        $rawTitle = $winner->translations->firstWhere('locale', 'lv')?->title ?: ($winner->getRawOriginal('title') ?: $winner->title);
        $isCinema = EventIngestionService::isCinemaEvent($rawTitle, $winner->location?->name, $winner->categories->pluck('name')->all());
        $canonicalTitle = $this->cleanTitleForCinema($rawTitle, $isCinema);
        $canonicalTitle = mb_substr(trim($canonicalTitle), 0, 255, 'UTF-8');
        $canonicalFp = hash('sha256', EventIngestionService::normalizeTitleForComparison($canonicalTitle) . '|' . $winner->location_id);

        // 1. Preserve missing translations on winner from other members before merging
        $this->copyMissingTranslationsToWinner($winner, $subgroup);

        // 2. Map existing created occurrences to prevent identical duplicate slots
        $createdOccurrences = [];

        // Put winner first, then members
        $orderedMembers = collect([$winner])->merge($subgroup->where('id', '!=', $winner->id));

        foreach ($orderedMembers as $member) {
            $format = $this->extractPresentationFormat($member);
            $auditorium = $this->extractAuditorium($member);
            $status = $this->determineOccurrenceStatus($member);

            // Find matching occurrence in subgroup (< 45 min, same format, same auditorium)
            $matchingOcc = null;
            foreach ($createdOccurrences as $existingOcc) {
                if ($this->areOccurrencesEquivalentForMigration($existingOcc, $member->start_at, $format, $auditorium)) {
                    $matchingOcc = $existingOcc;
                    break;
                }
            }

            if ($matchingOcc) {
                $occ = $matchingOcc;
            } else {
                $startKey = $member->all_day
                    ? ($member->start_at ? $member->start_at->format('Y-m-d') : '')
                    : ($member->start_at ? $member->start_at->format('Y-m-d H:i') : '');
                $normFmt = mb_strtolower(trim((string) $format), 'UTF-8');
                $normAud = mb_strtolower(trim((string) $auditorium), 'UTF-8');
                $occFp = hash('sha256', "{$winner->id}|{$startKey}|{$normFmt}|{$normAud}");

                // Session languages
                $spoken = null;
                $subtitles = null;
                $langSource = null;
                if ($isCinema || $member->language_source === 'explicit' || $member->language_source === EventLanguageSource::Explicit) {
                    $spoken = $member->spoken_languages;
                    $subtitles = $member->subtitle_languages;
                    $langSource = 'explicit';
                }

                $occ = EventOccurrence::create([
                    'event_id' => $winner->id,
                    'legacy_event_id' => $member->id,
                    'start_at' => $member->start_at,
                    'end_at' => $member->end_at,
                    'all_day' => (bool) $member->all_day,
                    'status' => $status,
                    'is_free' => (bool) $member->is_free,
                    'price_min' => $member->price_min,
                    'price_max' => $member->price_max,
                    'currency' => $member->currency ?: 'EUR',
                    'ticket_url' => $member->ticket_url,
                    'presentation_format' => $format,
                    'auditorium' => $auditorium,
                    'spoken_languages' => $spoken,
                    'subtitle_languages' => $subtitles,
                    'language_source' => $langSource,
                    'occurrence_fingerprint' => $occFp,
                ]);
                $createdOccurrences[] = $occ;
                $stats['occurrences_created']++;
            }

            // Create OccurrenceSource for member
            if ($member->source_id) {
                $this->createOccurrenceSourceForMember($member, $occ, $stats);
            }

            // If this is a non-winner member, merge it into winner
            if ($member->id !== $winner->id) {
                // Move actions
                EventAction::where('event_id', $member->id)->update([
                    'event_id' => $winner->id,
                    'event_occurrence_id' => $occ->id,
                ]);

                // Merge categories onto winner
                if ($member->categories->isNotEmpty()) {
                    $winner->categories()->syncWithoutDetaching($member->categories->pluck('id')->all());
                }

                // Transfer manual language
                if ($member->language_source === EventLanguageSource::Manual && $winner->language_source !== EventLanguageSource::Manual) {
                    $winner->language_mode = $member->language_mode;
                    $winner->spoken_languages = $member->spoken_languages;
                    $winner->subtitle_languages = $member->subtitle_languages;
                    $winner->language_source = EventLanguageSource::Manual;
                }

                // Create event redirects for primary slug and all translations
                $this->createRedirectsForMember($member, $winner, $stats);

                // Tombstone member
                $isOld = $member->start_at && $member->start_at->lt(now()->subDays(90));
                if ($isOld) {
                    $member->timestamps = false;
                }
                $member->status = 'merged';
                $member->merged_into_id = $winner->id;
                $member->merged_at = now();
                $member->occurrences_count = 0;
                if (!$isOld) {
                    $member->updated_at = now();
                }
                $member->save();
            }
        }

        // Finalize winner
        $winnerOcc = $createdOccurrences[0] ?? null;
        if ($winnerOcc) {
            EventAction::where('event_id', $winner->id)
                ->whereNull('event_occurrence_id')
                ->update(['event_occurrence_id' => $winnerOcc->id]);
        }

        $winner->title = mb_substr(trim($winner->title), 0, 255, 'UTF-8');
        $winner->canonical_fingerprint = $canonicalFp;
        $winner->save();

        // Project winner in preservation mode
        $this->projectionService->projectEvent($winner, preserveExistingTranslations: true);
    }

    /**
     * Create redirects for a merged member across all its locales.
     */
    protected function createRedirectsForMember(Event $member, Event $winner, array &$stats): void
    {
        $slugs = [];
        if (!empty($member->slug)) {
            $slugs[] = ['locale' => 'lv', 'slug' => $member->slug];
        }
        foreach ($member->translations as $t) {
            if (!empty($t->slug)) {
                $slugs[] = ['locale' => $t->locale, 'slug' => $t->slug];
            }
        }

        foreach ($slugs as $item) {
            EventRedirect::updateOrCreate(
                [
                    'locale' => $item['locale'],
                    'old_slug' => $item['slug'],
                ],
                [
                    'old_event_id' => $member->id,
                    'target_event_id' => $winner->id,
                    'reason' => 'migrated',
                ]
            );
            $stats['redirects_created']++;
        }

        // Flatten redirect chains
        EventRedirect::where('target_event_id', $member->id)->update(['target_event_id' => $winner->id]);
    }

    /**
     * Create OccurrenceSource record for an event member.
     */
    protected function createOccurrenceSourceForMember(Event $member, EventOccurrence $occ, array &$stats): void
    {
        $extId = filled($member->source_external_id)
            ? (string) $member->source_external_id
            : "legacy_ev_{$member->id}";

        $exists = OccurrenceSource::where('source_id', $member->source_id)
            ->where('source_external_id', $extId)
            ->exists();

        if ($exists) {
            $extId = "legacy_ev_{$member->id}";
            $stats['collisions']++;
        }

        OccurrenceSource::create([
            'event_occurrence_id' => $occ->id,
            'source_id' => $member->source_id,
            'source_external_id' => $extId,
            'source_url' => $member->source_url,
            'ticket_url' => $member->ticket_url,
            'price_min' => $member->price_min,
            'price_max' => $member->price_max,
            'currency' => $member->currency ?: 'EUR',
            'is_free' => (bool) $member->is_free,
            'raw_title' => $member->getRawOriginal('title') ?: $member->title,
            'raw_description' => $member->getRawOriginal('description') ?: $member->description,
            'raw_image_url' => $member->image_url,
            'raw_categories' => $member->categories->pluck('name')->all(),
            'raw_payload' => $member->raw_data,
            'content_hash' => hash('sha256', "legacy_{$member->id}_" . json_encode($member->raw_data)),
            'last_seen_at' => $member->updated_at ?? $member->created_at ?? now(),
            'is_missing' => false,
            'missing_runs_count' => 0,
        ]);
        $stats['sources_created']++;

        // Carry over merged external IDs as lightweight rows
        if (!empty($member->raw_data['merged_external_ids']) && is_array($member->raw_data['merged_external_ids'])) {
            foreach ($member->raw_data['merged_external_ids'] as $slug => $mExtId) {
                if (empty($mExtId)) continue;
                $secSource = Source::where('slug', $slug)->first();
                if ($secSource) {
                    $secExists = OccurrenceSource::where('source_id', $secSource->id)
                        ->where('source_external_id', (string) $mExtId)
                        ->exists();

                    if (!$secExists) {
                        OccurrenceSource::create([
                            'event_occurrence_id' => $occ->id,
                            'source_id' => $secSource->id,
                            'source_external_id' => (string) $mExtId,
                            'source_url' => null,
                            'ticket_url' => null,
                            'price_min' => null,
                            'price_max' => null,
                            'currency' => 'EUR',
                            'is_free' => false,
                            'raw_title' => null,
                            'raw_description' => null,
                            'raw_image_url' => null,
                            'raw_categories' => null,
                            'raw_payload' => ['merged_from_legacy_id' => $member->id],
                            'content_hash' => hash('sha256', "legacy_sec_{$member->id}_{$slug}_{$mExtId}"),
                            'last_seen_at' => $member->updated_at ?? now(),
                            'is_missing' => false,
                            'missing_runs_count' => 0,
                        ]);
                        $stats['sources_created']++;
                    }
                }
            }
        }
    }

    /**
     * Copy missing en and ru translations to winner.
     */
    protected function copyMissingTranslationsToWinner(Event $winner, Collection $subgroup): void
    {
        $existingLocales = $winner->translations->pluck('locale')->all();

        foreach (['en', 'ru'] as $loc) {
            if (!in_array($loc, $existingLocales, true)) {
                $candidate = $subgroup->first(function (Event $m) use ($loc) {
                    $t = $m->translations->firstWhere('locale', $loc);
                    return $t && !empty(trim((string) $t->description));
                });

                if ($candidate) {
                    $t = $candidate->translations->firstWhere('locale', $loc);
                    EventTranslation::create([
                        'event_id' => $winner->id,
                        'locale' => $loc,
                        'title' => $t->title,
                        'slug' => $t->slug,
                        'description' => $t->description,
                        'short_description' => $t->short_description,
                    ]);
                    $winner->unsetRelation('translations');
                }
            }
        }
    }

    /**
     * Pick winner for a subgroup.
     */
    protected function pickSubgroupWinner(Collection $subgroup): Event
    {
        return $subgroup->sort(function (Event $a, Event $b) {
            // 1. Published status over others
            $aPub = $a->status === 'published' ? 1 : 0;
            $bPub = $b->status === 'published' ? 1 : 0;
            if ($aPub !== $bPub) {
                return $bPub <=> $aPub;
            }

            // 2. Largest views_count
            $aViews = (int) $a->views_count;
            $bViews = (int) $b->views_count;
            if ($aViews !== $bViews) {
                return $bViews <=> $aViews;
            }

            // 3. Source priority
            $aType = $a->source?->type instanceof SourceType ? $a->source->type : SourceType::OTHER;
            $bType = $b->source?->type instanceof SourceType ? $b->source->type : SourceType::OTHER;
            $priA = $aType->priority();
            $priB = $bType->priority();
            if ($priA !== $priB) {
                return $priB <=> $priA;
            }

            // 4. Description length (uncontaminated)
            $city = $a->location?->city;
            $aClean = !empty($a->description) && !EventIngestionService::isDescriptionContaminated($a->description, $city);
            $bClean = !empty($b->description) && !EventIngestionService::isDescriptionContaminated($b->description, $city);
            if ($aClean !== $bClean) {
                return $bClean ? 1 : -1;
            }
            $lenA = mb_strlen((string) $a->description);
            $lenB = mb_strlen((string) $b->description);
            if ($lenA !== $lenB) {
                return $lenB <=> $lenA;
            }

            // 5. Smallest (oldest) ID
            return $a->id <=> $b->id;
        })->first();
    }

    /**
     * Cluster events at a single location into canonical groups.
     *
     * @return Collection<string, Collection<int, Event>>
     */
    protected function clusterEventsAtLocation(Collection $events): Collection
    {
        return $events->groupBy(function (Event $e) {
            $rawTitle = $e->translations->firstWhere('locale', 'lv')?->title ?: ($e->getRawOriginal('title') ?: $e->title);
            $isCinema = EventIngestionService::isCinemaEvent($rawTitle, $e->location?->name, $e->categories->pluck('name')->all());
            $canonicalTitle = $this->cleanTitleForCinema($rawTitle, $isCinema);
            $normTitle = EventIngestionService::normalizeTitleForComparison($canonicalTitle);
            $canonicalFp = hash('sha256', "{$normTitle}|{$e->location_id}");

            $incomp = 'other';
            if ($isCinema) {
                $incomp = 'kino';
            } elseif ($e->categories->contains(fn ($c) => $c->slug === 'sports')) {
                $incomp = 'sports';
            }

            return "{$canonicalFp}|{$incomp}";
        });
    }

    /**
     * Split a cluster by sliding window if gap between consecutive events exceeds maxDays.
     *
     * @return Collection<int, Collection<int, Event>>
     */
    protected function splitClusterBySlidingWindow(Collection $cluster): Collection
    {
        if ($cluster->count() <= 1) {
            return collect([$cluster]);
        }

        $sorted = $cluster->sortBy(fn (Event $e) => $e->start_at?->getTimestamp() ?? PHP_INT_MAX)->values();

        $first = $sorted->first();
        $isCinema = EventIngestionService::isCinemaEvent($first->title, $first->location?->name, $first->categories->pluck('name')->all());
        $maxDays = $isCinema ? 60 : 90;

        $subgroups = collect();
        $currentGroup = collect([$sorted[0]]);

        for ($i = 1; $i < $sorted->count(); $i++) {
            $prev = $sorted[$i - 1];
            $curr = $sorted[$i];

            $gap = 0;
            if ($prev->start_at && $curr->start_at) {
                $gap = abs($curr->start_at->diffInDays($prev->start_at));
            }

            if ($gap > $maxDays) {
                $subgroups->push($currentGroup);
                $currentGroup = collect([$curr]);
            } else {
                $currentGroup->push($curr);
            }
        }

        if ($currentGroup->isNotEmpty()) {
            $subgroups->push($currentGroup);
        }

        return $subgroups;
    }

    protected function areOccurrencesEquivalentForMigration(
        EventOccurrence $occ,
        ?Carbon $targetStart,
        ?string $targetFmt,
        ?string $targetAud
    ): bool {
        if (!$occ->start_at || !$targetStart) {
            return false;
        }

        if (abs($occ->start_at->diffInMinutes($targetStart)) > 45) {
            return false;
        }

        $oFmt = mb_strtolower(trim((string) $occ->presentation_format), 'UTF-8');
        $tFmt = mb_strtolower(trim((string) $targetFmt), 'UTF-8');
        if (!empty($oFmt) && !empty($tFmt) && $oFmt !== $tFmt) {
            return false;
        }

        $oAud = mb_strtolower(trim((string) $occ->auditorium), 'UTF-8');
        $tAud = mb_strtolower(trim((string) $targetAud), 'UTF-8');
        if (!empty($oAud) && !empty($tAud) && $oAud !== $tAud) {
            return false;
        }

        return true;
    }

    protected function determineOccurrenceStatus(Event $event): string
    {
        if ($event->status === 'cancelled') {
            return 'cancelled';
        }
        $end = $event->end_at ?? $event->start_at;
        if ($end && $end->lt(now())) {
            return 'completed';
        }
        return 'scheduled';
    }

    protected function extractPresentationFormat(Event $event): ?string
    {
        $raw = $event->raw_data['presentation_method']
            ?? $event->raw_data['cinema']['presentation_method']
            ?? null;

        if (empty($raw) || !is_string($raw)) {
            // Also check title suffixes
            $title = $event->title;
            if (preg_match('/\b(IMAX\s*3D|IMAX\s*2D|IMAX|3D|2D|LUXE?|Dolby\s*Atmos)\b/ui', $title, $m)) {
                $raw = $m[1];
            } else {
                return null;
            }
        }

        $upper = mb_strtoupper(trim((string) $raw), 'UTF-8');
        if (str_contains($upper, 'IMAX') && str_contains($upper, '3D')) return 'IMAX 3D';
        if (str_contains($upper, 'IMAX')) return 'IMAX 2D';
        if (str_contains($upper, '3D')) return '3D';
        if (str_contains($upper, 'LUX') || str_contains($upper, 'LUXE')) return 'LUX';
        if (str_contains($upper, 'ATMOS')) return 'Dolby Atmos';
        if (str_contains($upper, '2D')) return '2D';

        return trim((string) $raw);
    }

    protected function extractAuditorium(Event $event): ?string
    {
        $raw = $event->raw_data['cinema']['auditorium']
            ?? $event->raw_data['auditorium']
            ?? null;

        if (empty($raw) || !is_string($raw)) {
            return null;
        }

        return mb_substr(trim((string) $raw), 0, 120, 'UTF-8');
    }

    protected function cleanTitleForCinema(?string $title, bool $isCinema = false): string
    {
        $raw = trim((string) $title);
        if (!$isCinema || empty($raw)) {
            return $raw;
        }

        $cleaned = $raw;

        do {
            $before = $cleaned;
            // 1. Language notes in parentheses or trailing
            $cleaned = preg_replace('/\s*\(\s*(?:lv|en|ru|lat|eng|rus|dub|sub)\s*\)\s*$/ui', '', $cleaned);
            $cleaned = preg_replace('/\s*(?:\(|\s)*(?:latviešu|krievu|angļu)\s*valodā\)?\s*$/ui', '', $cleaned);
            $cleaned = preg_replace('/\s*(?:\(|\s)*(?:ar\s+subtitriem|ar\s+titriem|bez\s+subtitriem)[^)]*\)?\s*$/ui', '', $cleaned);

            // 2. Presentation formats in parentheses or standalone
            $cleaned = preg_replace('/\s*(?:\(|\s)+(?:IMAX\s*3D|IMAX\s*2D|IMAX|3D|2D|LUXE?|Dolby\s*Atmos)\)?\s*$/ui', '', $cleaned);

            // 3. Trailing delimiters (UTF-8 safe regex)
            $cleaned = preg_replace('/^[\s\-–—:;,.|«»„“”•]+|[\s\-–—:;,.|«»„“”•]+$/u', '', $cleaned);
        } while ($cleaned !== $before);

        return !empty($cleaned) ? $cleaned : $raw;
    }
}
