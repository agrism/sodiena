<?php

namespace App\Services\Api\V2;

use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\OccurrenceSource;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class EventSyncV2Service
{
    /**
     * Encode opaque URL-safe base64 cursor.
     */
    public static function encodeCursor(array $payload): string
    {
        return rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    }

    /**
     * Decode opaque URL-safe base64 cursor.
     */
    public static function decodeCursor(?string $cursor): ?array
    {
        if (empty($cursor)) {
            return null;
        }

        $remainder = strlen($cursor) % 4;
        if ($remainder) {
            $cursor .= str_repeat('=', 4 - $remainder);
        }

        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Validate sync request parameters and return parsed parameters or error message.
     */
    public function parseAndValidateRequest(Request $request): array
    {
        $rawCursor = $request->query('cursor');
        $rawSince = $request->query('since');
        $rawMode = $request->query('mode');
        $rawPerPage = $request->query('per_page', 100);
        $rawRecentDays = $request->query('recent_days');
        $rawUpcomingOnly = $request->query('upcoming_only');
        $rawIncludeLegacyIds = $request->query('include_legacy_ids');
        $city = $request->query('city');
        $locale = $request->query('locale') ?: $request->header('X-Locale', 'lv');

        // 1. Validate locale
        if (!in_array(strtolower($locale), ['lv', 'en', 'ru'], true)) {
            return ['error' => "Unsupported locale '{$locale}'. Allowed: lv, en, ru."];
        }

        // 2. Validate mode
        if ($rawMode !== null && !in_array($rawMode, ['incremental', 'full', 'bridge'], true)) {
            return ['error' => "Invalid mode '{$rawMode}'. Allowed: incremental, full, bridge."];
        }

        // 3. Validate cursor
        $cursor = null;
        if ($rawCursor !== null) {
            $cursor = self::decodeCursor($rawCursor);
            if ($cursor === null || (!isset($cursor['u']) && !isset($cursor['i']))) {
                return ['error' => 'Invalid or malformed cursor. Must be a valid URL-safe base64 JSON cursor.'];
            }
            if (isset($cursor['u'])) {
                try {
                    Carbon::parse($cursor['u']);
                } catch (\Throwable $e) {
                    return ['error' => 'Invalid timestamp format inside cursor.'];
                }
            }
        }

        // 4. Validate since
        $since = null;
        if ($rawSince !== null) {
            try {
                $since = is_numeric($rawSince)
                    ? Carbon::createFromTimestamp((int) $rawSince)
                    : Carbon::parse($rawSince);
            } catch (\Throwable $e) {
                return ['error' => "Invalid since parameter '{$rawSince}'. Must be a valid ISO 8601 or UNIX timestamp."];
            }
        }

        // 5. Determine effective mode
        if ($rawMode !== null) {
            $mode = $rawMode;
        } elseif ($cursor !== null && isset($cursor['u'])) {
            $mode = 'incremental';
        } elseif ($since !== null) {
            $mode = 'incremental';
        } else {
            $mode = 'full';
        }

        // 6. Validate per_page
        if (!is_numeric($rawPerPage) || (int) $rawPerPage < 1 || (int) $rawPerPage > 250) {
            return ['error' => "per_page must be an integer between 1 and 250. Got '{$rawPerPage}'."];
        }
        $perPage = (int) $rawPerPage;

        // 7. Validate recent_days
        $recentDays = 0;
        if ($mode === 'bridge') {
            $recentDays = 90;
        }
        if ($rawRecentDays !== null) {
            if (!is_numeric($rawRecentDays) || (int) $rawRecentDays < 0 || (int) $rawRecentDays > 365) {
                return ['error' => "recent_days must be an integer between 0 and 365. Got '{$rawRecentDays}'."];
            }
            $recentDays = (int) $rawRecentDays;
        }

        // 8. Upcoming only
        $upcomingOnly = $mode === 'full';
        if ($rawUpcomingOnly !== null) {
            $upcomingOnly = filter_var($rawUpcomingOnly, FILTER_VALIDATE_BOOLEAN);
        }

        // 9. Include legacy ids
        $includeLegacyIds = $mode === 'bridge';
        if ($rawIncludeLegacyIds !== null) {
            $includeLegacyIds = filter_var($rawIncludeLegacyIds, FILTER_VALIDATE_BOOLEAN);
        }

        return [
            'mode' => $mode,
            'cursor' => $cursor,
            'raw_cursor' => $rawCursor,
            'since' => $since,
            'per_page' => $perPage,
            'recent_days' => $recentDays,
            'upcoming_only' => $upcomingOnly,
            'include_legacy_ids' => $includeLegacyIds,
            'city' => $city ? trim((string) $city) : null,
            'locale' => strtolower($locale),
        ];
    }

    /**
     * Build sync query based on validated parameters.
     */
    public function buildQuery(array $params): Builder
    {
        $mode = $params['mode'];
        $cursor = $params['cursor'];
        $since = $params['since'];
        $recentDays = $params['recent_days'];
        $upcomingOnly = $params['upcoming_only'];
        $city = $params['city'];

        $today = Carbon::today();

        $query = Event::withTrashed();

        // Never leak unpublished drafts
        $query->where(function (Builder $q) {
            $q->where('status', '!=', 'draft')
              ->orWhereNotNull('published_at');
        });

        // City filter
        if (!empty($city)) {
            $cities = array_filter(array_map('trim', explode(',', $city)));
            if (!empty($cities)) {
                $query->whereHas('location', fn (Builder $lq) => $lq->whereIn('city', $cities));
            }
        }

        // Mode specific filtering
        if ($mode === 'incremental') {
            if ($cursor !== null && isset($cursor['u'])) {
                $u = Carbon::parse($cursor['u']);
                $i = isset($cursor['i']) ? (int) $cursor['i'] : 0;

                $query->where(function (Builder $q) use ($u, $i) {
                    $q->where('updated_at', '>', $u)
                      ->orWhere(function (Builder $sq) use ($u, $i) {
                          $sq->where('updated_at', '=', $u)
                             ->where('id', '>', $i);
                      });
                });
            } elseif ($since !== null) {
                $query->where('updated_at', '>=', $since);
            }

            $query->orderBy('updated_at', 'asc')
                  ->orderBy('id', 'asc');
        } elseif ($mode === 'bridge') {
            $cutoffDate = $today->copy()->subDays($recentDays);
            $recentCutoffTimestamp = now()->subDays($recentDays);

            $query->where(function (Builder $q) use ($today, $cutoffDate, $recentCutoffTimestamp) {
                // 1. Active or cancelled upcoming
                $q->where(function (Builder $sub) use ($today) {
                    $sub->whereNull('deleted_at')
                        ->whereIn('status', ['published', 'cancelled'])
                        ->where(function (Builder $sq) use ($today) {
                            $sq->where('last_end_at', '>=', $today)
                               ->orWhere('next_start_at', '>=', $today)
                               ->orWhere(function (Builder $s2) use ($today) {
                                   $s2->whereNull('next_start_at')
                                      ->where('start_at', '>=', $today);
                               });
                        });
                })
                // 2. Past events in recent_days
                ->orWhere(function (Builder $sub) use ($cutoffDate) {
                    $sub->whereNull('deleted_at')
                        ->whereIn('status', ['published', 'archived', 'cancelled'])
                        ->where(function (Builder $sq) use ($cutoffDate) {
                            $sq->where('last_end_at', '>=', $cutoffDate)
                               ->orWhere('next_start_at', '>=', $cutoffDate)
                               ->orWhere(function (Builder $s2) use ($cutoffDate) {
                                   $s2->whereNull('next_start_at')
                                      ->where('start_at', '>=', $cutoffDate);
                               });
                        });
                })
                // 3. Merged tombstones in recent_days
                ->orWhere(function (Builder $sub) use ($recentCutoffTimestamp) {
                    $sub->where('status', 'merged')
                        ->where('updated_at', '>=', $recentCutoffTimestamp);
                });
            });

            if ($cursor !== null && isset($cursor['i'])) {
                $query->where('id', '>', (int) $cursor['i']);
            }

            $query->orderBy('id', 'asc');
        } else {
            // mode = 'full'
            if ($upcomingOnly) {
                $query->where(function (Builder $q) use ($today, $recentDays) {
                    $q->where(function (Builder $sub) use ($today) {
                        $sub->whereNull('deleted_at')
                            ->whereIn('status', ['published', 'cancelled'])
                            ->where(function (Builder $sq) use ($today) {
                                $sq->where('last_end_at', '>=', $today)
                                   ->orWhere('next_start_at', '>=', $today)
                                   ->orWhere(function (Builder $s2) use ($today) {
                                       $s2->whereNull('next_start_at')
                                          ->where('start_at', '>=', $today);
                                   });
                            });
                    });

                    if ($recentDays > 0) {
                        $recentCutoffTimestamp = now()->subDays($recentDays);
                        $q->orWhere(function (Builder $sub) use ($recentCutoffTimestamp) {
                            $sub->where('updated_at', '>=', $recentCutoffTimestamp);
                        });
                    }
                });
            }

            if ($cursor !== null && isset($cursor['i'])) {
                $query->where('id', '>', (int) $cursor['i']);
            }

            $query->orderBy('id', 'asc');
        }

        $query->limit($params['per_page'] + 1);

        return $query;
    }

    /**
     * Compute snapshot cursor for full or bridge mode before first page query.
     */
    public function getSnapshotCursor(): string
    {
        $maxUpdatedAt = Event::max('updated_at');
        $ts = $maxUpdatedAt ? Carbon::parse($maxUpdatedAt)->toIso8601String() : now()->subSeconds(5)->toIso8601String();
        return self::encodeCursor(['u' => $ts, 'i' => 0]);
    }

    /**
     * Resolve status, reason and target for events in batch.
     *
     * @param Collection<int, Event> $events
     * @return array{statusMap: array<int, array{status: string, reason: ?string, target_id: ?int}>}
     */
    public function resolveEventStatusBatch(Collection $events): array
    {
        $statusMap = [];
        $mergedToTrace = [];

        foreach ($events as $event) {
            if ($event->deleted_at !== null || $event->status === 'deleted') {
                $statusMap[$event->id] = [
                    'status' => 'deleted',
                    'reason' => 'soft_deleted',
                    'target_id' => null,
                ];
            } elseif ($event->status === 'merged') {
                if ($event->merged_into_id) {
                    $mergedToTrace[$event->id] = (int) $event->merged_into_id;
                } else {
                    $statusMap[$event->id] = [
                        'status' => 'deleted',
                        'reason' => 'merged_target_deleted',
                        'target_id' => null,
                    ];
                }
            } elseif ($event->status === 'cancelled') {
                $statusMap[$event->id] = [
                    'status' => 'cancelled',
                    'reason' => 'event_cancelled',
                    'target_id' => null,
                ];
            } elseif ($event->status === 'archived') {
                $statusMap[$event->id] = [
                    'status' => 'archived',
                    'reason' => null,
                    'target_id' => null,
                ];
            } elseif ($event->status === 'draft') {
                if ($event->published_at !== null) {
                    $statusMap[$event->id] = [
                        'status' => 'deleted',
                        'reason' => 'unpublished_draft',
                        'target_id' => null,
                    ];
                } else {
                    $statusMap[$event->id] = [
                        'status' => 'draft',
                        'reason' => 'unreleased_draft',
                        'target_id' => null,
                    ];
                }
            } else {
                $statusMap[$event->id] = [
                    'status' => 'active',
                    'reason' => null,
                    'target_id' => null,
                ];
            }
        }

        // Batch trace merged targets (up to 5 hops with cycle protection)
        if (!empty($mergedToTrace)) {
            $flattenedTargets = $this->flattenMergedTargetIdsBatch($mergedToTrace);
            foreach ($mergedToTrace as $eventId => $firstTargetId) {
                $resolved = $flattenedTargets[$eventId] ?? null;
                $ev = $events->firstWhere('id', $eventId);
                $isMigrated = is_array($ev?->raw_data) && !empty($ev->raw_data['migrated_to_v2']);

                if ($resolved !== null && $resolved['valid']) {
                    $statusMap[$eventId] = [
                        'status' => 'merged',
                        'reason' => $isMigrated ? 'migrated' : 'merged',
                        'target_id' => $resolved['target_id'],
                    ];
                } else {
                    $statusMap[$eventId] = [
                        'status' => 'deleted',
                        'reason' => 'merged_target_deleted',
                        'target_id' => null,
                    ];
                }
            }
        }

        return $statusMap;
    }

    /**
     * Batch flatten merged target IDs up to 5 hops.
     *
     * @param array<int, int> $sourceToTargetMap [eventId => mergedIntoId]
     * @return array<int, array{target_id: ?int, valid: bool}>
     */
    protected function flattenMergedTargetIdsBatch(array $sourceToTargetMap): array
    {
        $allLookup = [];
        $neededIds = array_unique(array_values($sourceToTargetMap));

        // Load up to 5 levels of targets in batch
        for ($hop = 0; $hop < 5 && !empty($neededIds); $hop++) {
            $missing = array_diff($neededIds, array_keys($allLookup));
            if (empty($missing)) {
                break;
            }

            $fetched = Event::withTrashed()
                ->whereIn('id', $missing)
                ->get(['id', 'status', 'deleted_at', 'merged_into_id'])
                ->keyBy('id');

            foreach ($missing as $mId) {
                $allLookup[$mId] = $fetched->get($mId); // might be null if not found
            }

            $nextIds = [];
            foreach ($fetched as $fEvent) {
                if ($fEvent->status === 'merged' && $fEvent->merged_into_id && !isset($allLookup[$fEvent->merged_into_id])) {
                    $nextIds[] = (int) $fEvent->merged_into_id;
                }
            }
            $neededIds = array_unique($nextIds);
        }

        $results = [];
        foreach ($sourceToTargetMap as $srcId => $firstTargetId) {
            $currId = $firstTargetId;
            $visited = [$srcId];
            $hops = 0;
            $finalTarget = null;
            $isValid = false;

            while ($currId && $hops < 5 && !in_array($currId, $visited, true)) {
                $visited[] = $currId;
                $target = $allLookup[$currId] ?? null;

                if (!$target) {
                    $finalTarget = null;
                    $isValid = false;
                    break;
                }

                if ($target->status === 'deleted' || $target->deleted_at !== null) {
                    $finalTarget = null;
                    $isValid = false;
                    break;
                }

                if ($target->status === 'merged' && $target->merged_into_id) {
                    $currId = (int) $target->merged_into_id;
                    $hops++;
                } else {
                    $finalTarget = $target;
                    $isValid = true;
                    break;
                }
            }

            $results[$srcId] = [
                'target_id' => ($isValid && $finalTarget) ? (int) $finalTarget->id : null,
                'valid' => $isValid,
            ];
        }

        return $results;
    }

    /**
     * Batch flatten occurrence merged targets.
     *
     * @param array<int, int> $sourceToTargetMap [occId => mergedIntoId]
     * @return array<int, int> [occId => ultimateTargetId]
     */
    public function flattenOccurrenceMergedTargetsBatch(array $sourceToTargetMap): array
    {
        if (empty($sourceToTargetMap)) {
            return [];
        }

        $allLookup = [];
        $neededIds = array_unique(array_values($sourceToTargetMap));

        for ($hop = 0; $hop < 5 && !empty($neededIds); $hop++) {
            $missing = array_diff($neededIds, array_keys($allLookup));
            if (empty($missing)) {
                break;
            }

            $fetched = EventOccurrence::withTrashed()
                ->whereIn('id', $missing)
                ->get(['id', 'status', 'deleted_at', 'merged_into_id'])
                ->keyBy('id');

            foreach ($missing as $mId) {
                $allLookup[$mId] = $fetched->get($mId);
            }

            $nextIds = [];
            foreach ($fetched as $fOcc) {
                if ($fOcc->status === 'merged' && $fOcc->merged_into_id && !isset($allLookup[$fOcc->merged_into_id])) {
                    $nextIds[] = (int) $fOcc->merged_into_id;
                }
            }
            $neededIds = array_unique($nextIds);
        }

        $results = [];
        foreach ($sourceToTargetMap as $srcId => $firstTargetId) {
            $currId = $firstTargetId;
            $visited = [$srcId];
            $hops = 0;
            $finalTargetId = null;

            while ($currId && $hops < 5 && !in_array($currId, $visited, true)) {
                $visited[] = $currId;
                $target = $allLookup[$currId] ?? null;

                if (!$target) {
                    break;
                }

                if ($target->status === 'merged' && $target->merged_into_id) {
                    $currId = (int) $target->merged_into_id;
                    $hops++;
                } else {
                    $finalTargetId = (int) $target->id;
                    break;
                }
            }

            if ($finalTargetId !== null) {
                $results[$srcId] = $finalTargetId;
            }
        }

        return $results;
    }

    /**
     * Batch load occurrences for active/cancelled/archived events.
     *
     * @param Collection<int, Event> $events
     * @return array<int, Collection<int, EventOccurrence>>
     */
    public function loadOccurrencesBatch(Collection $events, array $params): array
    {
        $activeEventIds = [];
        foreach ($events as $e) {
            $activeEventIds[] = $e->id;
        }

        if (empty($activeEventIds)) {
            return [];
        }

        $mode = $params['mode'];
        $recentDays = $params['recent_days'];
        $since = $params['since'];
        $today = Carbon::today();
        $cutoffDate = $today->copy()->subDays(max(7, $recentDays));

        $occQuery = EventOccurrence::whereIn('event_id', $activeEventIds)
            ->with(['activeSources.source']);

        if ($mode === 'incremental') {
            $occQuery->where(function (Builder $q) use ($today, $since) {
                $q->where(function (Builder $sub) use ($today) {
                    $sub->whereNull('deleted_at')
                        ->where(function (Builder $sq) use ($today) {
                            $sq->where('end_at', '>=', $today->copy()->subDays(7))
                               ->orWhere(function (Builder $s2) use ($today) {
                                   $s2->whereNull('end_at')
                                      ->where('start_at', '>=', $today->copy()->subDays(7));
                               });
                        });
                });

                if ($since !== null) {
                    $q->orWhere('updated_at', '>=', $since);
                }
            });
        } else {
            // full / bridge
            $occQuery->whereNull('deleted_at')
                ->whereIn('status', ['scheduled', 'postponed', 'cancelled', 'completed'])
                ->where(function (Builder $q) use ($cutoffDate) {
                    $q->where('end_at', '>=', $cutoffDate)
                      ->orWhere(function (Builder $sq) use ($cutoffDate) {
                          $sq->whereNull('end_at')
                             ->where('start_at', '>=', $cutoffDate);
                      });
                });
        }

        $allOccs = $occQuery->orderBy('start_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $grouped = [];
        foreach ($activeEventIds as $eid) {
            $grouped[$eid] = collect();
        }

        foreach ($allOccs as $occ) {
            if (isset($grouped[$occ->event_id])) {
                $grouped[$occ->event_id]->push($occ);
            }
        }

        return $grouped;
    }

    /**
     * Batch resolve legacy_event_ids for events on the page.
     *
     * @param Collection<int, Event> $events
     * @param array<int, Collection<int, EventOccurrence>> $occurrencesByEvent
     * @return array<int, array<int>>
     */
    public function resolveLegacyEventIdsBatch(Collection $events, array $occurrencesByEvent): array
    {
        $eventIds = $events->pluck('id')->all();
        $legacyMap = [];

        foreach ($eventIds as $eid) {
            $legacyMap[$eid] = [$eid];
        }

        // 1. Collect from event_occurrences
        foreach ($occurrencesByEvent as $eid => $occs) {
            foreach ($occs as $occ) {
                if ($occ->legacy_event_id) {
                    $legacyMap[$eid][] = (int) $occ->legacy_event_id;
                }
            }
        }

        // 2. Collect from events merged into these events
        $mergedEvents = Event::withTrashed()
            ->whereIn('merged_into_id', $eventIds)
            ->get(['id', 'merged_into_id']);

        foreach ($mergedEvents as $me) {
            if ($me->merged_into_id && isset($legacyMap[$me->merged_into_id])) {
                $legacyMap[$me->merged_into_id][] = (int) $me->id;
            }
        }

        // 3. Deduplicate and sort
        foreach ($legacyMap as $eid => $ids) {
            $clean = array_values(array_unique(array_filter($ids, fn ($id) => is_numeric($id) && (int) $id > 0)));
            sort($clean, SORT_NUMERIC);
            $legacyMap[$eid] = $clean;
        }

        return $legacyMap;
    }
}
