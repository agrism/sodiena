<?php

namespace App\Services\IngestionV2;

use App\Enums\EventLanguageSource;
use App\Models\Event;
use App\Models\EventAction;
use App\Models\EventOccurrence;
use App\Models\EventRedirect;
use App\Models\OccurrenceSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EventMergeService
{
    public function __construct(
        protected CanonicalProjectionService $projectionService
    ) {}

    /**
     * Transactionally merge $sourceEvent into $targetEvent with 301 redirects and tombstone tracking.
     */
    public function mergeEvents(
        Event $sourceEvent,
        Event $targetEvent,
        string $reason = 'merged',
        bool $allowCrossVenue = false
    ): Event {
        return DB::transaction(function () use ($sourceEvent, $targetEvent, $reason, $allowCrossVenue) {
            // 1. Resolve ultimate root target if target was already merged
            $resolvedTarget = $targetEvent;
            while ($resolvedTarget->merged_into_id) {
                $parent = Event::find($resolvedTarget->merged_into_id);
                if (!$parent || $parent->id === $resolvedTarget->id) {
                    break;
                }
                $resolvedTarget = $parent;
            }

            if ($sourceEvent->id === $resolvedTarget->id) {
                throw new \InvalidArgumentException("Cannot merge event [{$sourceEvent->id}] into itself.");
            }

            // 2. Lock both events in deterministic ID order to prevent deadlocks
            $firstId = min($sourceEvent->id, $resolvedTarget->id);
            $secondId = max($sourceEvent->id, $resolvedTarget->id);
            Event::whereIn('id', [$firstId, $secondId])->lockForUpdate()->get();
            $sourceEvent->refresh();
            $resolvedTarget->refresh();

            // 3. Cross-venue guard
            if (!$allowCrossVenue && $sourceEvent->location_id && $resolvedTarget->location_id && $sourceEvent->location_id !== $resolvedTarget->location_id) {
                throw new \DomainException("Cross-venue merge forbidden between event [{$sourceEvent->id}] (venue: {$sourceEvent->location_id}) and [{$resolvedTarget->id}] (venue: {$resolvedTarget->location_id})");
            }

            // 4. Move or merge occurrences
            $sourceOccurrences = $sourceEvent->occurrences()
                ->whereNotIn('status', ['merged', 'deleted'])
                ->get();

            $targetOccurrences = $resolvedTarget->occurrences()
                ->whereNotIn('status', ['merged', 'deleted'])
                ->get();

            foreach ($sourceOccurrences as $srcOcc) {
                // Find matching occurrence in target
                $matchingDstOcc = $targetOccurrences->first(function (EventOccurrence $dstOcc) use ($srcOcc) {
                    return $this->areOccurrencesEquivalent($srcOcc, $dstOcc);
                });

                if ($matchingDstOcc) {
                    $this->mergeOccurrences($srcOcc, $matchingDstOcc);
                } else {
                    $srcOcc->event_id = $resolvedTarget->id;
                    $startKey = $srcOcc->all_day
                        ? $srcOcc->start_at->format('Y-m-d')
                        : $srcOcc->start_at->format('Y-m-d H:i');
                    $normFmt = mb_strtolower(trim((string) $srcOcc->presentation_format), 'UTF-8');
                    $normAud = mb_strtolower(trim((string) $srcOcc->auditorium), 'UTF-8');
                    $srcOcc->occurrence_fingerprint = hash('sha256', "{$resolvedTarget->id}|{$startKey}|{$normFmt}|{$normAud}");
                    $srcOcc->save();
                    $targetOccurrences->push($srcOcc);
                }
            }

            // 5. Move event actions
            EventAction::where('event_id', $sourceEvent->id)->update(['event_id' => $resolvedTarget->id]);

            // 6. Merge categories
            if ($sourceEvent->categories()->exists()) {
                $sourceCategoryIds = $sourceEvent->categories->pluck('id')->all();
                $resolvedTarget->categories()->syncWithoutDetaching($sourceCategoryIds);
            }

            // 7. Manual language preservation
            if ($sourceEvent->language_source === EventLanguageSource::Manual && $resolvedTarget->language_source !== EventLanguageSource::Manual) {
                $resolvedTarget->language_mode = $sourceEvent->language_mode;
                $resolvedTarget->spoken_languages = $sourceEvent->spoken_languages;
                $resolvedTarget->subtitle_languages = $sourceEvent->subtitle_languages;
                $resolvedTarget->language_source = EventLanguageSource::Manual;
            }

            // 8. Create event redirects for primary slug and all translations
            $slugsToRedirect = [];
            if (!empty($sourceEvent->slug)) {
                $slugsToRedirect[] = ['locale' => 'lv', 'slug' => $sourceEvent->slug];
            }
            foreach ($sourceEvent->translations as $t) {
                if (!empty($t->slug)) {
                    $slugsToRedirect[] = ['locale' => $t->locale, 'slug' => $t->slug];
                }
            }

            foreach ($slugsToRedirect as $item) {
                EventRedirect::updateOrCreate(
                    [
                        'locale' => $item['locale'],
                        'old_slug' => $item['slug'],
                    ],
                    [
                        'old_event_id' => $sourceEvent->id,
                        'target_event_id' => $resolvedTarget->id,
                        'reason' => $reason,
                    ]
                );
            }

            // Flatten redirect chains & merged_into_id pointers
            EventRedirect::where('target_event_id', $sourceEvent->id)
                ->update(['target_event_id' => $resolvedTarget->id]);

            Event::where('merged_into_id', $sourceEvent->id)
                ->update(['merged_into_id' => $resolvedTarget->id]);

            // 9. Tombstone on $sourceEvent
            $sourceEvent->status = 'merged';
            $sourceEvent->merged_into_id = $resolvedTarget->id;
            $sourceEvent->merged_at = now();
            $sourceEvent->occurrences_count = 0;
            $sourceEvent->updated_at = now();
            $sourceEvent->save();

            // 10. Re-project target event
            $this->projectionService->projectEvent($resolvedTarget);
            $resolvedTarget->touch();

            return $resolvedTarget->fresh();
        });
    }

    /**
     * Merge $sourceOcc into $targetOcc, re-linking occurrence_sources and actions.
     */
    public function mergeOccurrences(EventOccurrence $sourceOcc, EventOccurrence $targetOcc): EventOccurrence
    {
        if ($sourceOcc->id === $targetOcc->id) {
            return $targetOcc;
        }

        // Follow merged chain on target if any
        $resolvedTarget = $targetOcc;
        while ($resolvedTarget->merged_into_id) {
            $parent = EventOccurrence::find($resolvedTarget->merged_into_id);
            if (!$parent || $parent->id === $resolvedTarget->id) break;
            $resolvedTarget = $parent;
        }

        if ($sourceOcc->id === $resolvedTarget->id) {
            return $resolvedTarget;
        }

        // Lock both occurrences
        $firstId = min($sourceOcc->id, $resolvedTarget->id);
        $secondId = max($sourceOcc->id, $resolvedTarget->id);
        EventOccurrence::whereIn('id', [$firstId, $secondId])->lockForUpdate()->get();
        $sourceOcc->refresh();
        $resolvedTarget->refresh();

        // Move or consolidate occurrence_sources
        $srcSources = OccurrenceSource::where('event_occurrence_id', $sourceOcc->id)->get();
        $existingTargetSources = OccurrenceSource::where('event_occurrence_id', $resolvedTarget->id)->get()->keyBy('source_id');

        foreach ($srcSources as $srcSource) {
            if ($existingTargetSources->has($srcSource->source_id)) {
                $existing = $existingTargetSources->get($srcSource->source_id);
                // Keep the one with newest last_seen_at
                $srcSeen = $srcSource->last_seen_at ? $srcSource->last_seen_at->getTimestamp() : 0;
                $dstSeen = $existing->last_seen_at ? $existing->last_seen_at->getTimestamp() : 0;

                if ($srcSeen > $dstSeen) {
                    $existing->update([
                        'source_external_id' => $srcSource->source_external_id,
                        'source_url' => $srcSource->source_url ?: $existing->source_url,
                        'ticket_url' => $srcSource->ticket_url ?: $existing->ticket_url,
                        'price_min' => $srcSource->price_min ?? $existing->price_min,
                        'price_max' => $srcSource->price_max ?? $existing->price_max,
                        'currency' => $srcSource->currency ?: $existing->currency,
                        'is_free' => $srcSource->is_free ?? $existing->is_free,
                        'raw_title' => $srcSource->raw_title ?: $existing->raw_title,
                        'raw_description' => $srcSource->raw_description ?: $existing->raw_description,
                        'raw_image_url' => $srcSource->raw_image_url ?: $existing->raw_image_url,
                        'raw_categories' => $srcSource->raw_categories ?: $existing->raw_categories,
                        'raw_payload' => array_merge($existing->raw_payload ?? [], $srcSource->raw_payload ?? []),
                        'content_hash' => $srcSource->content_hash ?: $existing->content_hash,
                        'last_scrape_log_id' => $srcSource->last_scrape_log_id ?: $existing->last_scrape_log_id,
                        'last_seen_at' => $srcSource->last_seen_at,
                        'is_missing' => $srcSource->is_missing,
                    ]);
                }
                $srcSource->delete();
            } else {
                $srcSource->update(['event_occurrence_id' => $resolvedTarget->id]);
            }
        }

        // Move event actions
        EventAction::where('event_occurrence_id', $sourceOcc->id)->update(['event_occurrence_id' => $resolvedTarget->id]);

        // Flatten occurrence merged chains
        EventOccurrence::where('merged_into_id', $sourceOcc->id)->update(['merged_into_id' => $resolvedTarget->id]);

        // Mark source occurrence as merged
        $sourceOcc->status = 'merged';
        $sourceOcc->merged_into_id = $resolvedTarget->id;
        $sourceOcc->updated_at = now();
        $sourceOcc->save();

        // Project target occurrence
        $this->projectionService->projectOccurrence($resolvedTarget);

        return $resolvedTarget->fresh();
    }

    /**
     * Determine if two occurrences represent the same session (time, format, hall).
     */
    protected function areOccurrencesEquivalent(EventOccurrence $a, EventOccurrence $b): bool
    {
        if ($a->id === $b->id) return true;

        if ($a->start_at->toDateString() !== $b->start_at->toDateString()) {
            return false;
        }

        // Time check: if both non-all_day, must be within 45 minutes
        if (!$a->all_day && !$b->all_day) {
            $minA = $a->start_at->hour * 60 + $a->start_at->minute;
            $minB = $b->start_at->hour * 60 + $b->start_at->minute;
            if (abs($minA - $minB) > 45) {
                return false;
            }
        }

        // Format check: if both have explicit formats, they must match
        $fmtA = mb_strtoupper(trim((string) $a->presentation_format), 'UTF-8');
        $fmtB = mb_strtoupper(trim((string) $b->presentation_format), 'UTF-8');
        if (!empty($fmtA) && !empty($fmtB) && $fmtA !== $fmtB) {
            return false;
        }

        // Auditorium check: if both have explicit non-empty halls, they must match
        $audA = mb_strtolower(trim((string) $a->auditorium), 'UTF-8');
        $audB = mb_strtolower(trim((string) $b->auditorium), 'UTF-8');
        if (!empty($audA) && !empty($audB) && $audA !== $audB && !str_contains($audA, $audB) && !str_contains($audB, $audA)) {
            return false;
        }

        return true;
    }
}
