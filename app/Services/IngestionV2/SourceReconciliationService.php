<?php

namespace App\Services\IngestionV2;

use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\OccurrenceSource;
use App\Models\ScrapeLog;
use App\Models\Source;
use Illuminate\Support\Facades\Log;

class SourceReconciliationService
{
    public function __construct(
        protected CanonicalProjectionService $projectionService
    ) {}

    /**
     * Reconcile missing sessions for structured sources with Circuit Breaker and grace runs.
     */
    public function reconcile(Source $source, ScrapeLog $scrapeLog, bool $isFullFeedRun = true): void
    {
        // 1. Preconditions check
        if (!$source->supports_reconciliation || !$isFullFeedRun || $scrapeLog->status !== 'success') {
            $scrapeLog->reconciliation_status = 'disabled';
            $scrapeLog->save();
            return;
        }

        // 2. Cutoff buffer
        $bufferMinutes = (int) config('sodiena.reconciliation_start_buffer_minutes', 120);
        $cutoff = now()->addMinutes($bufferMinutes);

        // 3. Count expected active future sources vs seen in this run
        $baseQuery = OccurrenceSource::where('source_id', $source->id)
            ->whereHas('occurrence', function ($q) use ($cutoff) {
                $q->whereIn('status', ['scheduled', 'postponed'])
                  ->where('start_at', '>=', $cutoff);
            });

        $expectedActiveCount = (clone $baseQuery)->where('is_missing', false)->count();

        $seenCount = (clone $baseQuery)
            ->where('last_scrape_log_id', $scrapeLog->id)
            ->count();

        $ratio = $expectedActiveCount > 0 ? round($seenCount / $expectedActiveCount, 4) : 1.0;
        $minRatio = (float) ($source->reconciliation_min_ratio ?? 0.60);

        // 4. Circuit Breaker evaluation
        if ($expectedActiveCount >= 5 && ($seenCount === 0 || $ratio < $minRatio)) {
            $scrapeLog->reconciliation_status = 'skipped_circuit_breaker';
            $meta = is_array($scrapeLog->summary_meta) ? $scrapeLog->summary_meta : [];
            $meta['reconciliation'] = [
                'expected' => $expectedActiveCount,
                'seen' => $seenCount,
                'ratio' => $ratio,
                'min_ratio' => $minRatio,
            ];
            $scrapeLog->summary_meta = $meta;
            $scrapeLog->save();

            Log::warning("Reconciliation circuit breaker triggered for source '{$source->slug}': seen {$seenCount} of {$expectedActiveCount} (ratio {$ratio} < min {$minRatio})");
            return;
        }

        // 5. Query missing candidates
        $missingCandidates = (clone $baseQuery)
            ->where('is_missing', false)
            ->where(function ($q) use ($scrapeLog) {
                $q->whereNull('last_scrape_log_id')
                  ->orWhere('last_scrape_log_id', '<>', $scrapeLog->id);
            })
            ->with(['occurrence.event'])
            ->get();

        $graceRunsRequired = (int) ($source->reconciliation_grace_runs ?? 1);
        $affectedOccurrenceIds = [];
        $affectedEventIds = [];
        $cancelledOccurrencesCount = 0;
        $missingMarkedCount = 0;

        foreach ($missingCandidates as $cand) {
            $cand->missing_runs_count = $cand->missing_runs_count + 1;
            if (!$cand->missing_since_at) {
                $cand->missing_since_at = now();
            }

            if ($cand->missing_runs_count >= $graceRunsRequired) {
                $cand->is_missing = true;
                $affectedOccurrenceIds[$cand->event_occurrence_id] = true;
                $missingMarkedCount++;
            }

            $cand->save();
        }

        // 6. Re-project affected occurrences and check for cancellation
        foreach (array_keys($affectedOccurrenceIds) as $occId) {
            $occ = EventOccurrence::find($occId);
            if ($occ) {
                $this->projectionService->projectOccurrence($occ);
                if ($occ->status === 'cancelled') {
                    $cancelledOccurrencesCount++;
                }
                $affectedEventIds[$occ->event_id] = true;
            }
        }

        // 7. Re-project affected events
        foreach (array_keys($affectedEventIds) as $eventId) {
            $event = Event::find($eventId);
            if ($event) {
                $this->projectionService->projectEvent($event);
            }
        }

        // 8. Update scrape log
        $scrapeLog->items_cancelled = $cancelledOccurrencesCount;
        $scrapeLog->reconciliation_status = 'completed';
        $meta = is_array($scrapeLog->summary_meta) ? $scrapeLog->summary_meta : [];
        $meta['reconciliation'] = [
            'expected' => $expectedActiveCount,
            'seen' => $seenCount,
            'ratio' => $ratio,
            'missing_marked' => $missingMarkedCount,
            'occurrences_cancelled' => $cancelledOccurrencesCount,
        ];
        $scrapeLog->summary_meta = $meta;
        $scrapeLog->save();
    }
}
