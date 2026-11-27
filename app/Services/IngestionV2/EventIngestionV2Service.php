<?php

namespace App\Services\IngestionV2;

use App\Console\Commands\ConsolidateCategoriesCommand;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventTranslation;
use App\Models\Location;
use App\Models\LocationTranslation;
use App\Models\OccurrenceSource;
use App\Models\ScrapeLog;
use App\Models\Source;
use App\Services\Scrapers\Contracts\EventScraperInterface;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EventIngestionV2Service
{
    public function __construct(
        protected CanonicalProjectionService $projectionService,
        protected SourceReconciliationService $reconciliationService,
        protected EventMergeService $mergeService
    ) {}

    /**
     * Top-level entry point to scrape and ingest a Source using the v2 pipeline.
     */
    public function ingestSource(Source $source, bool $isFullFeedRun = true): ScrapeLog
    {
        if ((int) ini_get('memory_limit') < 512 && ini_get('memory_limit') !== '-1') {
            @ini_set('memory_limit', '512M');
        }
        DB::disableQueryLog();

        $startedAt = now();
        $startTime = microtime(true);

        $scrapeLog = null;
        try {
            $scrapeLog = ScrapeLog::create([
                'source_id' => $source->id,
                'status' => 'started',
                'items_found' => 0,
                'items_created' => 0,
                'items_updated' => 0,
                'items_deduplicated' => 0,
                'items_cancelled' => 0,
                'duration_seconds' => 0,
                'errors' => null,
                'started_at' => $startedAt,
                'completed_at' => null,
            ]);

            $source->update([
                'last_scraped_at' => $startedAt,
                'last_status' => 'started',
                'last_error' => null,
            ]);
        } catch (\Throwable $initEx) {
            Log::warning("IngestionV2: Could not initialize ScrapeLog for {$source->name}: " . $initEx->getMessage());
        }

        $summary = new IngestionSummary();

        try {
            /** @var EventScraperInterface $scraper */
            $scraper = app()->make($source->scraper_class);
            $dtos = $scraper->scrape($source);
            $summary = $this->ingestCollection($dtos, $source, $scrapeLog, $isFullFeedRun);
        } catch (\Throwable $scrapeEx) {
            $summary->errors[] = "Scraper execution failed: " . $scrapeEx->getMessage();
            Log::error("IngestionV2: Source scrape failed for {$source->name}", ['exception' => $scrapeEx]);
        }

        $duration = microtime(true) - $startTime;
        $summary->durationSeconds = round($duration, 2);

        $cleanErrors = !empty($summary->errors)
            ? array_map(fn ($err) => mb_convert_encoding($err, 'UTF-8', 'UTF-8'), array_slice($summary->errors, 0, 15))
            : null;

        $finalStatus = $summary->status();
        $primaryError = !empty($cleanErrors) ? Str::limit($cleanErrors[0], 500) : null;

        try {
            $source->update([
                'last_scraped_at' => now(),
                'last_status' => $finalStatus,
                'last_error' => $primaryError,
                'duplicates_prevented_count' => $summary->itemsDeduplicated,
            ]);
        } catch (\Throwable $updateEx) {
            Log::warning("IngestionV2: Could not update last_status for {$source->name}: " . $updateEx->getMessage());
        }

        if ($scrapeLog && $scrapeLog->exists) {
            $scrapeLog->update([
                'status' => $finalStatus,
                'items_found' => $summary->itemsFound,
                'items_created' => $summary->itemsCreated,
                'items_updated' => $summary->itemsUpdated,
                'items_deduplicated' => $summary->itemsDeduplicated,
                'items_cancelled' => $summary->itemsCancelled,
                'duration_seconds' => $summary->durationSeconds,
                'errors' => $cleanErrors,
                'completed_at' => now(),
            ]);
            return $scrapeLog->fresh();
        }

        return ScrapeLog::create([
            'source_id' => $source->id,
            'items_found' => $summary->itemsFound,
            'items_created' => $summary->itemsCreated,
            'items_updated' => $summary->itemsUpdated,
            'items_deduplicated' => $summary->itemsDeduplicated,
            'items_cancelled' => $summary->itemsCancelled,
            'duration_seconds' => $summary->durationSeconds,
            'status' => $finalStatus,
            'errors' => $cleanErrors,
            'started_at' => $startedAt,
            'completed_at' => now(),
        ]);
    }

    /**
     * Ingest a Collection of ScrapedEventDTOs for a given Source.
     */
    public function ingestCollection(
        Collection $dtos,
        Source $source,
        ScrapeLog $scrapeLog,
        bool $isFullFeedRun = true
    ): IngestionSummary {
        $startTime = microtime(true);
        $summary = new IngestionSummary();
        $summary->itemsFound = $dtos->count();

        foreach ($dtos as $dto) {
            try {
                $result = $this->ingestDTO($dto, $source, $scrapeLog);
                if ($result === 'created') {
                    $summary->itemsCreated++;
                } elseif ($result === 'updated') {
                    $summary->itemsUpdated++;
                } elseif ($result === 'deduplicated') {
                    $summary->itemsDeduplicated++;
                }
            } catch (\Throwable $e) {
                $summary->errors[] = "Error on '{$dto->title}': " . $e->getMessage();
                Log::error("IngestionV2: Failed to ingest event '{$dto->title}' from source {$source->name}", [
                    'source_id' => $source->id,
                    'exception' => $e,
                ]);
            }
        }

        $scrapeLogStatus = empty($summary->errors)
            ? 'success'
            : ($summary->itemsCreated > 0 || $summary->itemsUpdated > 0 || $summary->itemsDeduplicated > 0 ? 'partial' : 'failed');

        $scrapeLog->status = $scrapeLogStatus;
        $scrapeLog->items_found = $summary->itemsFound;
        $scrapeLog->items_created = $summary->itemsCreated;
        $scrapeLog->items_updated = $summary->itemsUpdated;
        $scrapeLog->items_deduplicated = $summary->itemsDeduplicated;
        $scrapeLog->errors = !empty($summary->errors) ? array_slice($summary->errors, 0, 15) : null;
        $scrapeLog->save();

        // End-of-feed Reconciliation for structured sources
        if ($isFullFeedRun) {
            try {
                $this->reconciliationService->reconcile($source, $scrapeLog, $isFullFeedRun);
                $summary->itemsCancelled = (int) $scrapeLog->items_cancelled;
            } catch (\Throwable $recEx) {
                Log::error("IngestionV2: Reconciliation failed for source {$source->slug}: " . $recEx->getMessage(), [
                    'exception' => $recEx,
                ]);
            }
        }

        $summary->durationSeconds = round(microtime(true) - $startTime, 2);
        return $summary;
    }

    /**
     * Ingest a single ScrapedEventDTO into the v2 hierarchy.
     */
    public function ingestDTO(ScrapedEventDTO $dto, Source $source, ScrapeLog $scrapeLog): string
    {
        return DB::transaction(function () use ($dto, $source, $scrapeLog) {
            $locale = $dto->locale ?: 'lv';

            // Step 0: Resolve Location BEFORE deduplication
            $locationId = $this->resolveOrCreateLocation($dto, $locale);

            // Step 1: Look up existing OccurrenceSource ($O(1)$)
            $externalId = $dto->getEffectiveExternalId($source->slug);
            $contentHash = $this->calculateDtoContentHash($dto, $locationId);

            /** @var OccurrenceSource|null $occSource */
            $occSource = OccurrenceSource::where('source_id', $source->id)
                ->where('source_external_id', $externalId)
                ->with(['occurrence.event.location'])
                ->first();

            if ($occSource) {
                $currentOcc = $occSource->occurrence;
                $currentEvent = $currentOcc?->event;

                // Repurposed ID / Venue Drift Guard:
                $isRepurposed = false;
                if ($currentEvent) {
                    if ($currentEvent->location_id && $locationId && $currentEvent->location_id !== $locationId) {
                        $isRepurposed = true;
                    } else {
                        similar_text(
                            mb_strtolower(trim((string) $currentEvent->title), 'UTF-8'),
                            mb_strtolower(trim((string) $dto->title), 'UTF-8'),
                            $titleSim
                        );
                        if ($titleSim < 35.0) {
                            $isRepurposed = true;
                        }
                    }
                }

                if ($isRepurposed) {
                    // Detach from old occurrence and re-project old records
                    $oldOcc = $currentOcc;
                    $oldEvent = $currentEvent;

                    $occSource->event_occurrence_id = null;
                    $occSource->save();

                    if ($oldOcc) {
                        $this->projectionService->projectOccurrence($oldOcc);
                    }
                    if ($oldEvent) {
                        $this->projectionService->projectEvent($oldEvent);
                    }
                    $occSource = null; // Will find or create proper occurrence in steps 2-4
                } else {
                    // Fast-path skip:
                    if (
                        !$occSource->is_missing &&
                        $currentOcc &&
                        !in_array($currentOcc->status, ['merged', 'deleted', 'cancelled'], true) &&
                        $occSource->content_hash === $contentHash
                    ) {
                        $occSource->last_seen_at = now();
                        $occSource->last_scrape_log_id = $scrapeLog->id;
                        $occSource->save();
                        return 'deduplicated';
                    }

                    // Valid occurrence source, but content changed or was missing
                    if ($currentOcc && $dto->startAt && $currentOcc->start_at != $dto->startAt) {
                        $currentOcc->start_at = $dto->startAt;
                        $currentOcc->end_at = $dto->endAt;
                        $currentOcc->save();
                    }

                    $this->populateOccurrenceSource($occSource, $dto, $scrapeLog, $contentHash);
                    $occSource->save();

                    if ($currentOcc) {
                        $this->projectionService->projectOccurrence($currentOcc);
                        if ($currentEvent) {
                            $this->projectionService->projectEvent($currentEvent);
                        }
                    }

                    return 'updated';
                }
            }

            // Step 2: Canonical Event lookup or creation (within same location_id)
            $isCinema = EventIngestionService::isCinemaEvent($dto->title, $dto->venueName, $dto->categoryNames);
            $canonicalTitle = $dto->getCanonicalEventTitle($isCinema);
            $normTitle = EventIngestionService::normalizeTitleForComparison($canonicalTitle);
            $canonicalFp = hash('sha256', $normTitle . '|' . ($locationId ?? 'null'));

            $isNewEvent = false;
            $event = $this->findCanonicalEventCandidate($dto, $locationId, $isCinema, $canonicalFp);

            if (!$event) {
                $event = Event::create([
                    'title' => $canonicalTitle,
                    'slug' => Str::slug($canonicalTitle) . '-' . substr(md5(uniqid('', true)), 0, 6),
                    'location_id' => $locationId,
                    'canonical_fingerprint' => $canonicalFp,
                    'status' => 'published',
                    'published_at' => now(),
                    'start_at' => $dto->startAt,
                    'end_at' => $dto->endAt,
                ]);

                EventTranslation::create([
                    'event_id' => $event->id,
                    'locale' => 'lv',
                    'title' => $event->title,
                    'slug' => Str::slug($event->title) . '-' . substr(md5($event->id . 'lv'), 0, 6),
                    'description' => $dto->description,
                    'short_description' => Str::limit(strip_tags((string) $dto->description), 160),
                ]);

                $isNewEvent = true;
            }

            // Step 3: EventOccurrence lookup or creation under canonical Event
            $fmt = $dto->getPresentationFormat();
            $aud = $dto->getAuditorium();
            $allDay = $dto->isAllDay();
            $startKey = $allDay ? $dto->startAt->format('Y-m-d') : $dto->startAt->format('Y-m-d H:i');
            $normFmt = mb_strtolower(trim((string) $fmt), 'UTF-8');
            $normAud = mb_strtolower(trim((string) $aud), 'UTF-8');
            $occFp = hash('sha256', "{$event->id}|{$startKey}|{$normFmt}|{$normAud}");

            $isNewOccurrence = false;
            $occurrence = $this->findOccurrenceCandidate($event, $dto, $isCinema, $occFp);

            if (!$occurrence) {
                $occurrence = EventOccurrence::create([
                    'event_id' => $event->id,
                    'start_at' => $dto->startAt,
                    'end_at' => $dto->endAt,
                    'all_day' => $allDay,
                    'status' => 'scheduled',
                    'presentation_format' => $fmt,
                    'auditorium' => $aud,
                    'occurrence_fingerprint' => $occFp,
                ]);

                $isNewOccurrence = true;
            }

            // Step 4: OccurrenceSource save & Projection
            if (!$occSource) {
                $occSource = OccurrenceSource::where('source_id', $source->id)
                    ->where('source_external_id', $externalId)
                    ->first() ?? new OccurrenceSource([
                        'source_id' => $source->id,
                        'source_external_id' => $externalId,
                    ]);
            }

            $occSource->event_occurrence_id = $occurrence->id;
            $this->populateOccurrenceSource($occSource, $dto, $scrapeLog, $contentHash);
            $occSource->save();

            $this->projectionService->projectOccurrence($occurrence);
            $this->projectionService->projectEvent($event);

            return ($isNewEvent || $isNewOccurrence) ? 'created' : 'updated';
        });
    }

    /**
     * Find existing canonical Event candidate under the same location within the time window.
     */
    protected function findCanonicalEventCandidate(
        ScrapedEventDTO $dto,
        ?int $locationId,
        bool $isCinema,
        string $canonicalFp
    ): ?Event {
        $maxDays = $isCinema ? 60 : 90;

        // If location is null: only 2a or 2b
        if ($locationId === null) {
            $candidates = Event::whereNull('location_id')
                ->where('canonical_fingerprint', $canonicalFp)
                ->whereNotIn('status', ['merged', 'deleted'])
                ->with(['occurrences'])
                ->get();

            foreach ($candidates as $cand) {
                if ($this->isWithinTimeWindow($cand, $dto->startAt, $maxDays)) {
                    return $cand;
                }
            }

            return $this->findCandidateByTicketPlatform($dto, null, $maxDays);
        }

        // 2a. Direct canonical fingerprint match within location_id
        $fpCandidates = Event::where('location_id', $locationId)
            ->where('canonical_fingerprint', $canonicalFp)
            ->whereNotIn('status', ['merged', 'deleted'])
            ->with(['occurrences'])
            ->get();

        foreach ($fpCandidates as $cand) {
            if ($this->isWithinTimeWindow($cand, $dto->startAt, $maxDays)) {
                return $cand;
            }
        }

        // 2b. Ticketing platform ID match within location_id
        $ticketCandidate = $this->findCandidateByTicketPlatform($dto, $locationId, $maxDays);
        if ($ticketCandidate) {
            return $ticketCandidate;
        }

        // 2c. Strict fuzzy match within location_id
        $locationEvents = Event::where('location_id', $locationId)
            ->whereNotIn('status', ['merged', 'deleted'])
            ->with(['categories', 'occurrences'])
            ->get();

        foreach ($locationEvents as $cand) {
            if (!$this->isWithinTimeWindow($cand, $dto->startAt, $maxDays)) {
                continue;
            }

            if ($this->areEventsFuzzyEquivalent($cand, $dto, $isCinema)) {
                return $cand;
            }
        }

        return null;
    }

    /**
     * Strict fuzzy equivalency evaluation between an existing Event and incoming ScrapedEventDTO.
     */
    protected function areEventsFuzzyEquivalent(Event $cand, ScrapedEventDTO $dto, bool $isCinema): bool
    {
        $candCats = $cand->categories->pluck('slug')->all();
        $dtoCatSlugs = array_map(fn ($c) => ConsolidateCategoriesCommand::mapToCanonicalSlug($c), $dto->categoryNames);
        $candIsSport = in_array('sports', $candCats, true);
        $dtoIsSport = in_array('sports', $dtoCatSlugs, true);

        // 1. Category incompatibility barrier: sports never merges with theatre, cinema, exhibitions
        if (($candIsSport && array_intersect($dtoCatSlugs, ['teatris', 'kino', 'izstades'])) ||
            ($dtoIsSport && array_intersect($candCats, ['teatris', 'kino', 'izstades']))) {
            return false;
        }

        $candTitleLower = mb_strtolower((string) $cand->title, 'UTF-8');
        $dtoTitleLower = mb_strtolower((string) $dto->title, 'UTF-8');

        // 2. Package modifier barrier: vip, biznesa, enbl, abonements
        $packageTerms = ['vip', 'biznesa', 'enbl', 'abonements'];
        foreach ($packageTerms as $term) {
            $candHas = preg_match('/\b' . preg_quote($term, '/') . '\b/u', $candTitleLower);
            $dtoHas = preg_match('/\b' . preg_quote($term, '/') . '\b/u', $dtoTitleLower);
            if ($candHas !== $dtoHas) {
                return false;
            }
        }

        // 3. Number differentiator barrier (e.g. 1. daļa vs 2. daļa)
        preg_match_all('/\b(?:\d+|[IVXLCDM]+)\b/u', $candTitleLower, $candNums);
        preg_match_all('/\b(?:\d+|[IVXLCDM]+)\b/u', $dtoTitleLower, $dtoNums);
        $candNums = array_unique($candNums[0] ?? []);
        $dtoNums = array_unique($dtoNums[0] ?? []);
        if (!empty($candNums) && !empty($dtoNums) && $candNums !== $dtoNums) {
            return false;
        }

        // 4. Sports matchup teams
        $candTeams = EventIngestionService::extractMatchupTeams($cand->title);
        $dtoTeams = EventIngestionService::extractMatchupTeams($dto->title);
        if ($candTeams && $dtoTeams) {
            if (!EventIngestionService::areMatchupTeamsEquivalent($candTeams, $dtoTeams)) {
                return false;
            }
        }

        // 5. Cinema title comparison
        if ($isCinema) {
            $candClean = $this->projectionService->cleanCanonicalTitle($cand->title, true);
            $dtoClean = $dto->getCanonicalEventTitle(true);
            $candNorm = EventIngestionService::normalizeTitleForComparison($candClean);
            $dtoNorm = EventIngestionService::normalizeTitleForComparison($dtoClean);
            if ($candNorm === $dtoNorm) {
                return true;
            }
            similar_text(mb_strtolower($candClean, 'UTF-8'), mb_strtolower($dtoClean, 'UTF-8'), $sim);
            return $sim >= 85.0;
        }

        // 6. General events: Latvian stems overlap >= 60% or similar_text >= 78%
        $candStems = EventIngestionService::getLatvianWordStems($cand->title);
        $dtoStems = EventIngestionService::getLatvianWordStems($dto->title);
        if (!empty($candStems) && !empty($dtoStems)) {
            $intersect = array_intersect($candStems, $dtoStems);
            $overlap = count($intersect) / max(count($candStems), count($dtoStems));
            if ($overlap >= 0.60 || ($overlap >= 0.50 && count($intersect) >= 3)) {
                return true;
            }
        }

        similar_text($candTitleLower, $dtoTitleLower, $sim);
        return $sim >= 78.0;
    }

    /**
     * Find candidate by equivalent ticketing URL/ID within the location.
     */
    protected function findCandidateByTicketPlatform(
        ScrapedEventDTO $dto,
        ?int $locationId,
        int $maxDays
    ): ?Event {
        if (empty($dto->ticketUrl)) {
            return null;
        }

        $meta = EventIngestionService::extractTicketingPlatformMeta($dto->ticketUrl);
        if (!$meta) {
            return null;
        }

        $query = OccurrenceSource::whereNotNull('ticket_url')
            ->where('is_missing', false)
            ->whereHas('occurrence.event', function ($q) use ($locationId) {
                if ($locationId !== null) {
                    $q->where('location_id', $locationId);
                } else {
                    $q->whereNull('location_id');
                }
                $q->whereNotIn('status', ['merged', 'deleted']);
            })
            ->with(['occurrence.event.categories', 'occurrence.event.occurrences']);

        $sources = $query->get();
        foreach ($sources as $os) {
            if (EventIngestionService::areTicketingUrlsEquivalent($dto->ticketUrl, $os->ticket_url)) {
                $candEvent = $os->occurrence?->event;
                if ($candEvent && $this->isWithinTimeWindow($candEvent, $dto->startAt, $maxDays)) {
                    $candCats = $candEvent->categories->pluck('slug')->all();
                    $dtoCatSlugs = array_map(fn ($c) => ConsolidateCategoriesCommand::mapToCanonicalSlug($c), $dto->categoryNames);
                    $candIsSport = in_array('sports', $candCats, true);
                    $dtoIsSport = in_array('sports', $dtoCatSlugs, true);

                    if (!($candIsSport && array_intersect($dtoCatSlugs, ['teatris', 'kino', 'izstades'])) &&
                        !($dtoIsSport && array_intersect($candCats, ['teatris', 'kino', 'izstades']))) {
                        return $candEvent;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Check if an Event's occurrences or dates fall within ±$maxDays of $dtoDate.
     */
    protected function isWithinTimeWindow(Event $event, Carbon $dtoDate, int $maxDays): bool
    {
        $occs = $event->occurrences;
        if ($occs && $occs->isNotEmpty()) {
            $min = $occs->min('start_at');
            $max = $occs->max('start_at');
            if ($min) {
                $diff1 = abs($dtoDate->diffInDays($min));
                if ($diff1 <= $maxDays) return true;
            }
            if ($max) {
                $diff2 = abs($dtoDate->diffInDays($max));
                if ($diff2 <= $maxDays) return true;
            }
            return false;
        }

        $ref = $event->next_start_at ?: $event->start_at;
        if ($ref) {
            return abs($dtoDate->diffInDays($ref)) <= $maxDays;
        }

        return true;
    }

    /**
     * Find matching EventOccurrence under an Event.
     */
    protected function findOccurrenceCandidate(
        Event $event,
        ScrapedEventDTO $dto,
        bool $isCinema,
        string $occFp
    ): ?EventOccurrence {
        // 3a. Direct occurrence_fingerprint
        $direct = EventOccurrence::where('event_id', $event->id)
            ->where('occurrence_fingerprint', $occFp)
            ->whereNotIn('status', ['merged', 'deleted'])
            ->first();

        if ($direct) {
            return $direct;
        }

        // 3b. Time window (< 45 min) on the same date
        $sameDayOccs = EventOccurrence::where('event_id', $event->id)
            ->whereNotIn('status', ['merged', 'deleted'])
            ->whereDate('start_at', $dto->startAt->toDateString())
            ->get();

        if ($sameDayOccs->isEmpty()) {
            return null;
        }

        $dtoFmt = mb_strtolower(trim((string) $dto->getPresentationFormat()), 'UTF-8');
        $dtoAud = mb_strtolower(trim((string) $dto->getAuditorium()), 'UTF-8');

        // Fictitious aggregator time check (00:00 or 10:00)
        $isFictitious = $dto->startAt->format('H:i') === '00:00' || $dto->startAt->format('H:i') === '10:00';
        if ($isFictitious) {
            if ($sameDayOccs->count() === 1) {
                return $sameDayOccs->first();
            }
            return null; // 2+ occurrences exist -> do not guess
        }

        foreach ($sameDayOccs as $cand) {
            if ($isCinema) {
                $candFmt = mb_strtolower(trim((string) $cand->presentation_format), 'UTF-8');
                $candAud = mb_strtolower(trim((string) $cand->auditorium), 'UTF-8');

                if (!empty($candFmt) && !empty($dtoFmt) && $candFmt !== $dtoFmt) {
                    continue; // 2D vs IMAX 2D stay separate
                }
                if (!empty($candAud) && !empty($dtoAud) && $candAud !== $dtoAud) {
                    continue; // Separate halls stay separate
                }
            }

            $diffMinutes = abs($cand->start_at->diffInMinutes($dto->startAt));
            if ($diffMinutes <= 45) {
                return $cand;
            }
        }

        return null;
    }

    /**
     * Resolve or create a Location with strict cinema branch isolation.
     */
    public function resolveOrCreateLocation(ScrapedEventDTO $dto, string $locale = 'lv'): ?int
    {
        if (!$dto->venueName && !$dto->city && !$dto->latitude) {
            return null;
        }

        $rawVenue = trim($dto->venueName ?? '');
        $cityName = trim($dto->city ?: 'Rīga');
        $regionName = $dto->region ?: $this->guessRegionByCity($cityName);

        $locationName = $rawVenue ?: ($cityName . ' centrs');
        $cleanQueryName = $this->cleanLocationName($locationName);

        $rawOrg = is_string($dto->rawData['organizer'] ?? null) ? mb_strtolower($dto->rawData['organizer'], 'UTF-8') : '';
        $isNationalTheatreOrg = str_contains($rawOrg, 'nacionālais teātris') || str_contains($rawOrg, 'nacionalais teatris');
        $isJrtOrg = str_contains($rawOrg, 'jaunais rīgas') || str_contains($rawOrg, 'jaunais rigas');
        $isDailesOrg = str_contains($rawOrg, 'dailes teātris') || str_contains($rawOrg, 'dailes teatris');

        if (preg_match('/^(?:lielā|mazā|jaunā|aktieru|kamer|skatītāju)\s*zāle$/ui', trim($cleanQueryName))) {
            if ($isNationalTheatreOrg) {
                $cleanQueryName = 'Latvijas Nacionālais teātris, ' . $cleanQueryName;
                $locationName = $cleanQueryName;
            } elseif ($isDailesOrg) {
                $cleanQueryName = 'Dailes teātris, ' . $cleanQueryName;
                $locationName = $cleanQueryName;
            } elseif ($isJrtOrg) {
                $cleanQueryName = 'Jaunais Rīgas teātris, ' . $cleanQueryName;
                $locationName = $cleanQueryName;
            }
        }

        // 1. Exact match in the same city
        $location = Location::where(function ($q) use ($locationName, $cleanQueryName) {
            $q->where('name', $locationName)
              ->orWhere('name', $cleanQueryName)
              ->orWhereHas('translations', function ($tq) use ($locationName, $cleanQueryName) {
                  $tq->where('name', $locationName)
                     ->orWhere('name', $cleanQueryName);
              });
        })->where(function ($q) use ($cityName) {
            $q->where('city', $cityName)
              ->orWhereHas('translations', function ($tq) use ($cityName) {
                  $tq->where('city', $cityName);
              });
        })->first();

        // 2. Fuzzy match within same city
        if (!$location) {
            $cityLocations = Location::where(function ($q) use ($cityName) {
                $q->where('city', $cityName)
                  ->orWhereHas('translations', function ($tq) use ($cityName) {
                      $tq->where('city', $cityName);
                  });
            })->get();

            $baseQueryName = $this->getBaseVenueName($cleanQueryName);
            $queryStreet = $this->extractStreetAndNumber($dto->address);
            $queryCinema = $this->classifyCinema($cleanQueryName);

            foreach ($cityLocations as $candidate) {
                $candName = $candidate->name;
                $cleanCandName = $this->cleanLocationName($candName);
                $candLower = mb_strtolower($cleanCandName, 'UTF-8');

                // Cinema branch isolation guard: cinema branches NEVER cross-match
                $candCinema = $this->classifyCinema($cleanCandName);
                if ($queryCinema !== null || $candCinema !== null) {
                    if ($queryCinema === null || $candCinema === null || $queryCinema !== $candCinema) {
                        continue;
                    }
                }

                // Safeguards against theatre organizer cross-contamination
                if ($isNationalTheatreOrg && (str_contains($candLower, 'jaunais rīgas') || str_contains($candLower, 'jaunais rigas') || str_contains($candLower, 'dailes'))) {
                    continue;
                }
                if ($isJrtOrg && (str_contains($candLower, 'nacionāl') || str_contains($candLower, 'nacional') || str_contains($candLower, 'dailes'))) {
                    continue;
                }
                if ($isDailesOrg && (str_contains($candLower, 'nacionāl') || str_contains($candLower, 'nacional') || str_contains($candLower, 'jaunais rīgas') || str_contains($candLower, 'jaunais rigas'))) {
                    continue;
                }

                $baseCandName = $this->getBaseVenueName($cleanCandName);
                $candStreet = $this->extractStreetAndNumber($candidate->address);

                // A. Base venue match
                if (mb_strlen($baseQueryName, 'UTF-8') >= 4 && mb_strtolower($baseQueryName, 'UTF-8') === mb_strtolower($baseCandName, 'UTF-8')) {
                    $location = $candidate;
                    break;
                }

                // B. Substring match
                $normQuery = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($cleanQueryName, 'UTF-8'));
                $normCand = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($cleanCandName, 'UTF-8'));
                $genericHalls = ['liela zale', 'maza zale', 'kamerzale', 'jauna zale', 'aktieru zale', 'skatitaju zale', 'balta zale', 'koncertzale'];
                $isGenericHall = in_array(trim($normQuery), $genericHalls, true);

                if (!$isGenericHall && mb_strlen($normQuery, 'UTF-8') >= 8 && mb_strlen($normCand, 'UTF-8') >= 8) {
                    if (str_contains($normCand, $normQuery) || str_contains($normQuery, $normCand)) {
                        $location = $candidate;
                        break;
                    }
                }

                // C. Street address match
                if ($queryStreet && $candStreet && mb_strtolower($queryStreet, 'UTF-8') === mb_strtolower($candStreet, 'UTF-8')) {
                    $location = $candidate;
                    break;
                }

                // D. Stemmed token overlap
                $queryStems = EventIngestionService::getLatvianWordStems($cleanQueryName);
                $candStems = EventIngestionService::getLatvianWordStems($cleanCandName);
                if (count($queryStems) >= 2 && count($candStems) >= 2) {
                    $common = array_intersect($queryStems, $candStems);
                    $overlap = (count($common) / max(count($queryStems), count($candStems))) * 100;
                    if ($overlap >= 75) {
                        $location = $candidate;
                        break;
                    }
                }
            }
        }

        if (!$location) {
            $address = $dto->address;
            $latitude = $dto->latitude;
            $longitude = $dto->longitude;

            if ($isNationalTheatreOrg && (!$address || str_contains($cleanQueryName, 'Nacionālais teātris') || str_contains($cleanQueryName, 'Nacionālā teātr'))) {
                $address = $address ?: 'Kronvalda bulvāris 2';
                $latitude = $latitude ?: 56.953631;
                $longitude = $longitude ?: 24.104910;
            }

            $location = Location::create([
                'name' => $cleanQueryName,
                'city' => $cityName,
                'region' => $regionName,
                'address' => $address,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'place_type' => $dto->placeType ?: 'venue',
            ]);
        } else {
            $locUpdate = [];
            if (mb_strlen($cleanQueryName, 'UTF-8') > mb_strlen($location->name, 'UTF-8') && !str_contains($cleanQueryName, '(')) {
                $locUpdate['name'] = $cleanQueryName;
            }
            if (empty($location->address) && !empty($dto->address)) {
                $locUpdate['address'] = $dto->address;
            }
            if (empty($location->latitude) && !empty($dto->latitude)) {
                $locUpdate['latitude'] = $dto->latitude;
                $locUpdate['longitude'] = $dto->longitude;
            }
            if (!empty($locUpdate)) {
                $location->update($locUpdate);
            }
        }

        LocationTranslation::updateOrCreate(
            [
                'location_id' => $location->id,
                'locale' => $locale,
            ],
            [
                'name' => $location->name,
                'city' => $cityName,
                'region' => $regionName,
                'address' => $dto->address ?: $location->address,
            ]
        );

        return $location->id;
    }

    /**
     * Populate standard fields on an OccurrenceSource.
     */
    protected function populateOccurrenceSource(
        OccurrenceSource $occSource,
        ScrapedEventDTO $dto,
        ScrapeLog $scrapeLog,
        string $contentHash
    ): void {
        $occSource->source_url = $dto->sourceUrl;
        $occSource->ticket_url = $dto->ticketUrl;
        $occSource->price_min = $dto->priceMin;
        $occSource->price_max = $dto->priceMax;
        $occSource->currency = $dto->currency ?: 'EUR';
        $occSource->is_free = $dto->isFree;
        $occSource->raw_title = $dto->title;
        $occSource->raw_description = $dto->description;
        $occSource->raw_image_url = $dto->imageUrl;
        $occSource->raw_categories = $dto->categoryNames;
        $occSource->raw_payload = $dto->rawData;
        $occSource->content_hash = $contentHash;
        $occSource->last_scrape_log_id = $scrapeLog->id;
        $occSource->last_seen_at = now();
        $occSource->is_missing = false;
        $occSource->missing_runs_count = 0;
        $occSource->missing_since_at = null;
    }

    /**
     * Calculate deterministic content hash for incoming DTO.
     */
    public function calculateDtoContentHash(ScrapedEventDTO $dto, ?int $locationId): string
    {
        $cats = $dto->categoryNames;
        sort($cats, SORT_STRING);

        $payload = [
            'title' => trim((string) $dto->title),
            'description' => trim((string) $dto->description),
            'start_at' => $dto->startAt ? $dto->startAt->format('Y-m-d H:i:s') : null,
            'end_at' => $dto->endAt ? $dto->endAt->format('Y-m-d H:i:s') : null,
            'all_day' => $dto->isAllDay(),
            'location_id' => $locationId,
            'ticket_url' => $dto->ticketUrl ? trim((string) $dto->ticketUrl) : null,
            'price_min' => $dto->priceMin !== null ? (float) $dto->priceMin : null,
            'price_max' => $dto->priceMax !== null ? (float) $dto->priceMax : null,
            'currency' => $dto->currency ?: 'EUR',
            'is_free' => $dto->isFree,
            'image_url' => $dto->imageUrl ? trim((string) $dto->imageUrl) : null,
            'presentation_format' => $dto->getPresentationFormat(),
            'auditorium' => $dto->getAuditorium(),
            'categories' => $cats,
            'language' => $dto->getLanguagePayload(),
        ];

        return hash('sha256', (string) json_encode($payload));
    }

    /**
     * Classify cinema venue name into a canonical slug for branch isolation.
     */
    public function classifyCinema(?string $name): ?string
    {
        if (empty($name)) return null;
        $v = mb_strtolower(trim($name), 'UTF-8');

        if (str_contains($v, 'apollo')) {
            if (str_contains($v, 'plaza')) return 'apollo_plaza';
            if (str_contains($v, 'domina')) return 'apollo_domina';
            if (str_contains($v, 'alfa')) return 'apollo_alfa';
            if (str_contains($v, 'akropole')) return 'apollo_akropole';
            if (str_contains($v, 'onde')) return 'apollo_onde';
            return 'apollo_generic';
        }
        if (str_contains($v, 'forum') || str_contains($v, 'citadele')) return 'forum_cinemas';
        if (str_contains($v, 'bize')) return 'kino_bize';
        if (str_contains($v, 'lora')) return 'kino_lora';
        if (str_contains($v, 'splendid')) return 'splendid_palace';
        if (str_contains($v, 'suns') || str_contains($v, 'k suns')) return 'k_suns';
        if (str_contains($v, 'gaisma')) return 'kino_gaisma';
        if (str_contains($v, 'rio')) return 'kino_rio';
        if (str_contains($v, 'cinamon')) return 'cinamon';
        if (str_contains($v, 'cesis') || str_contains($v, 'cēsis')) return 'cesis_kino';
        if (str_contains($v, 'kuldiga') || str_contains($v, 'kuldīga')) return 'kuldiga_kino';

        return null;
    }

    protected function cleanLocationName(string $name): string
    {
        $clean = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = str_replace(['“', '”', '«', '»', '"', '’', '`'], '', $clean);
        return trim(preg_replace('/\s+/', ' ', $clean));
    }

    protected function getBaseVenueName(string $name): string
    {
        $name = preg_replace('/\s*\([^)]*\)/', '', $name);
        $name = preg_replace('/,?\s*(?:Lielā zāle|Mazā zāle|Jaunā zāle|Kamerzāle|Kora zāle|Koncertzāle|Eksperimentālā skatuve|MAZĀ ZĀLE|LIELĀ ZĀLE|1\.\s*stāvs|2\.\s*stāvs)$/iu', '', $name);
        return trim($name);
    }

    protected function extractStreetAndNumber(?string $address): ?string
    {
        if (empty($address)) return null;
        if (preg_match('/([\p{L}\s]+(?:iela|bulvāris|gatve|prospekts|laukums|krastmala|dambis)\s+\d+[a-z]?)/iu', $address, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    protected function guessRegionByCity(string $city): string
    {
        $c = mb_strtolower(trim($city), 'UTF-8');
        if (in_array($c, ['rīga', 'riga', 'jūrmala', 'jurmala', 'olaine', 'marupe', 'mārupe', 'salaspils'], true)) return 'Rīga un apkārtne';
        if (in_array($c, ['valmiera', 'cēsis', 'cesis', 'sigulda', 'smiltene', 'alka', 'gulbene', 'alūksne'], true)) return 'Vidzeme';
        if (in_array($c, ['liepāja', 'liepaja', 'ventspils', 'kuldīga', 'kuldiga', 'saldus', 'talsi', 'tukums'], true)) return 'Kurzeme';
        if (in_array($c, ['jelgava', 'bauska', 'dobele', 'iecava'], true)) return 'Zemgale';
        if (in_array($c, ['daugavpils', 'rēzekne', 'rezekne', 'krāslava', 'ludza', 'preiļi', 'balvi'], true)) return 'Latgale';
        if (in_array($c, ['jēkabpils', 'jekabpils', 'ogre', 'aizkraukle', 'madona', 'līvāni'], true)) return 'Sēlija un Viduslatvija';
        return 'Latvija';
    }
}
