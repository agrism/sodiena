<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\V2\EventV2Resource;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\ScrapeLog;
use App\Models\Source;
use App\Services\Api\V2\EventSyncV2Service;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EventV2ApiController extends Controller
{
    protected EventSyncV2Service $syncService;

    public function __construct(EventSyncV2Service $syncService)
    {
        $this->syncService = $syncService;
    }

    /**
     * Primary 1:1 synchronization endpoint for Piedzivo.lv (and other v2 clients).
     * Endpoint: GET /api/v2/events/sync
     */
    public function sync(Request $request): JsonResponse
    {
        $parsed = $this->syncService->parseAndValidateRequest($request);
        if (isset($parsed['error'])) {
            return response()->json([
                'success' => false,
                'message' => $parsed['error'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $syncTimestamp = now()->toIso8601String();
        $snapshotCursor = null;
        if (in_array($parsed['mode'], ['full', 'bridge'], true)) {
            $snapshotCursor = $this->syncService->getSnapshotCursor();
        }

        $query = $this->syncService->buildQuery($parsed);

        // Eager load relationships for the page batch
        $items = $query->with([
            'location.translations',
            'categories.translations',
            'translations',
            'primaryContentSource',
            'source',
        ])->get();

        $perPage = $parsed['per_page'];
        $hasMore = $items->count() > $perPage;

        if ($hasMore) {
            $items = $items->slice(0, $perPage);
        }

        // Batch resolve event statuses & merged targets
        $statusMap = $this->syncService->resolveEventStatusBatch($items);

        // Filter events that require occurrences
        $eventsRequiringOccurrences = $items->filter(function (Event $e) use ($statusMap) {
            $st = $statusMap[$e->id]['status'] ?? 'deleted';
            return in_array($st, ['active', 'cancelled', 'archived'], true);
        });

        // Batch load occurrences
        $occurrencesByEvent = $this->syncService->loadOccurrencesBatch($eventsRequiringOccurrences, $parsed);

        // Batch flatten occurrence merged targets
        $occMergedMap = [];
        foreach ($occurrencesByEvent as $eid => $occs) {
            foreach ($occs as $occ) {
                if ($occ->status === 'merged' && $occ->merged_into_id) {
                    $occMergedMap[$occ->id] = (int) $occ->merged_into_id;
                }
            }
        }
        $flattenedOccTargets = $this->syncService->flattenOccurrenceMergedTargetsBatch($occMergedMap);

        // Batch resolve legacy event IDs if requested
        $legacyIdsMap = [];
        if ($parsed['include_legacy_ids']) {
            $legacyIdsMap = $this->syncService->resolveLegacyEventIdsBatch($items, $occurrencesByEvent);
        }

        // Counts in page
        $countsInPage = [
            'active' => 0,
            'merged' => 0,
            'cancelled' => 0,
            'deleted' => 0,
            'archived' => 0,
        ];

        // Format data
        $data = [];
        foreach ($items as $event) {
            $stInfo = $statusMap[$event->id] ?? [
                'status' => 'deleted',
                'reason' => 'soft_deleted',
                'target_id' => null,
            ];

            $syncStatus = $stInfo['status'];
            $syncReason = $stInfo['reason'];
            $mergedTargetId = $stInfo['target_id'];

            if (isset($countsInPage[$syncStatus])) {
                $countsInPage[$syncStatus]++;
            }

            $eventOccs = $occurrencesByEvent[$event->id] ?? collect();
            $eventLegacyIds = $legacyIdsMap[$event->id] ?? null;

            $resource = new EventV2Resource(
                resource: $event,
                syncStatus: $syncStatus,
                syncStatusReason: $syncReason,
                flattenedMergedIntoId: $mergedTargetId,
                legacyEventIds: $eventLegacyIds,
                includeLegacyIds: $parsed['include_legacy_ids'],
                occurrencesCollection: $eventOccs,
                occurrenceMergedLookup: $flattenedOccTargets
            );

            $data[] = $resource->toArray($request);
        }

        // Cursors calculation
        $nextCursor = null;
        $resumeCursor = null;

        if ($hasMore) {
            $lastItem = $items->last();
            if ($lastItem) {
                if ($parsed['mode'] === 'incremental') {
                    $nextCursor = EventSyncV2Service::encodeCursor([
                        'u' => $lastItem->updated_at->toIso8601String(),
                        'i' => (int) $lastItem->id,
                    ]);
                } else {
                    $nextCursor = EventSyncV2Service::encodeCursor([
                        'i' => (int) $lastItem->id,
                    ]);
                }
            }
        }

        if ($parsed['mode'] === 'incremental') {
            if ($items->isNotEmpty()) {
                $lastItem = $items->last();
                $resumeCursor = EventSyncV2Service::encodeCursor([
                    'u' => $lastItem->updated_at->toIso8601String(),
                    'i' => (int) $lastItem->id,
                ]);
            } else {
                $resumeCursor = !empty($parsed['raw_cursor'])
                    ? $parsed['raw_cursor']
                    : EventSyncV2Service::encodeCursor(['u' => $syncTimestamp, 'i' => 0]);
            }
        } else {
            $resumeCursor = $snapshotCursor;
        }

        return response()->json([
            'success' => true,
            'schema_version' => 2,
            'mode' => $parsed['mode'],
            'sync_timestamp' => $syncTimestamp,
            'data' => $data,
            'meta' => [
                'per_page' => $perPage,
                'returned_count' => count($data),
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor,
                'resume_cursor' => $resumeCursor,
                'counts_in_page' => $countsInPage,
            ],
        ]);
    }

    /**
     * Diagnostic and data parity check endpoint.
     * Endpoint: GET /api/v2/events/stats
     */
    public function stats(Request $request): JsonResponse
    {
        $today = Carbon::today();
        $since24h = now()->subHours(24);

        // 1. Events parity metrics
        $activeUpcoming = Event::withoutTrashed()
            ->published()
            ->where(function ($q) use ($today) {
                $q->where('last_end_at', '>=', $today)
                  ->orWhere('next_start_at', '>=', $today)
                  ->orWhere(function ($sq) use ($today) {
                      $sq->whereNull('next_start_at')
                         ->where('start_at', '>=', $today);
                  });
            })
            ->count();

        $activeTotal = Event::withoutTrashed()
            ->published()
            ->count();

        $cancelledUpcoming = Event::withoutTrashed()
            ->where('status', 'cancelled')
            ->where(function ($q) use ($today) {
                $q->where('last_end_at', '>=', $today)
                  ->orWhere('next_start_at', '>=', $today)
                  ->orWhere(function ($sq) use ($today) {
                      $sq->whereNull('next_start_at')
                         ->where('start_at', '>=', $today);
                  });
            })
            ->count();

        $mergedTotal = Event::withTrashed()
            ->where('status', 'merged')
            ->count();

        $deletedTotal = Event::onlyTrashed()->count()
            + Event::withoutTrashed()->where('status', 'deleted')->count();

        // 2. Occurrences parity metrics
        $scheduledUpcoming = EventOccurrence::whereNull('deleted_at')
            ->where('status', 'scheduled')
            ->where('start_at', '>=', $today)
            ->count();

        $postponedUpcoming = EventOccurrence::whereNull('deleted_at')
            ->where('status', 'postponed')
            ->where('start_at', '>=', $today)
            ->count();

        $cancelledUpcomingOcc = EventOccurrence::whereNull('deleted_at')
            ->where('status', 'cancelled')
            ->where('start_at', '>=', $today)
            ->count();

        $totalActiveOrFuture = EventOccurrence::whereNull('deleted_at')
            ->whereIn('status', ['scheduled', 'postponed', 'cancelled'])
            ->where('start_at', '>=', $today)
            ->count();

        // 3. Reconciliation metrics
        $enabledSourcesCount = Source::where('supports_reconciliation', true)->count();

        $cancelled24h = EventOccurrence::where('status', 'cancelled')
            ->where('cancelled_at', '>=', $since24h)
            ->count();

        $circuitBreakerTrips = ScrapeLog::where('reconciliation_status', 'skipped_circuit_breaker')
            ->where('created_at', '>=', $since24h)
            ->count();

        return response()->json([
            'success' => true,
            'schema_version' => 2,
            'generated_at' => now()->toIso8601String(),
            'events' => [
                'active_upcoming' => $activeUpcoming,
                'active_total' => $activeTotal,
                'cancelled_upcoming' => $cancelledUpcoming,
                'merged_total' => $mergedTotal,
                'deleted_total' => $deletedTotal,
            ],
            'occurrences' => [
                'scheduled_upcoming' => $scheduledUpcoming,
                'postponed_upcoming' => $postponedUpcoming,
                'cancelled_upcoming' => $cancelledUpcomingOcc,
                'total_active_or_future' => $totalActiveOrFuture,
            ],
            'reconciliation' => [
                'enabled_sources_count' => $enabledSourcesCount,
                'last_24h_cancelled_occurrences' => $cancelled24h,
                'last_24h_circuit_breaker_trips' => $circuitBreakerTrips,
            ],
        ]);
    }

    /**
     * Single canonical event or tombstone fetch by ID.
     * Endpoint: GET /api/v2/events/{id}
     */
    public function show(Request $request, int|string $id): JsonResponse
    {
        if (!is_numeric($id) || (int) $id <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $event = Event::withTrashed()
            ->with([
                'location.translations',
                'categories.translations',
                'translations',
                'primaryContentSource',
                'source',
            ])
            ->find((int) $id);

        if (!$event) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        // Never leak unreleased drafts
        if ($event->status === 'draft' && $event->published_at === null) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $includeLegacyIds = filter_var($request->query('include_legacy_ids', false), FILTER_VALIDATE_BOOLEAN);

        // Resolve status & merged target
        $statusMap = $this->syncService->resolveEventStatusBatch(collect([$event]));
        $stInfo = $statusMap[$event->id] ?? [
            'status' => 'deleted',
            'reason' => 'soft_deleted',
            'target_id' => null,
        ];

        $syncStatus = $stInfo['status'];
        $syncReason = $stInfo['reason'];
        $mergedTargetId = $stInfo['target_id'];

        $occurrences = collect();
        $flattenedOccTargets = [];
        $legacyIds = null;

        if (in_array($syncStatus, ['active', 'cancelled', 'archived'], true)) {
            $occurrences = $event->occurrences()
                ->whereNull('deleted_at')
                ->with(['activeSources.source'])
                ->orderBy('start_at', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            $occMergedMap = [];
            foreach ($occurrences as $occ) {
                if ($occ->status === 'merged' && $occ->merged_into_id) {
                    $occMergedMap[$occ->id] = (int) $occ->merged_into_id;
                }
            }
            $flattenedOccTargets = $this->syncService->flattenOccurrenceMergedTargetsBatch($occMergedMap);
        }

        if ($includeLegacyIds) {
            $legacyIdsMap = $this->syncService->resolveLegacyEventIdsBatch(collect([$event]), [$event->id => $occurrences]);
            $legacyIds = $legacyIdsMap[$event->id] ?? [(int) $event->id];
        }

        $resource = new EventV2Resource(
            resource: $event,
            syncStatus: $syncStatus,
            syncStatusReason: $syncReason,
            flattenedMergedIntoId: $mergedTargetId,
            legacyEventIds: $legacyIds,
            includeLegacyIds: $includeLegacyIds,
            occurrencesCollection: $occurrences,
            occurrenceMergedLookup: $flattenedOccTargets
        );

        return response()->json([
            'success' => true,
            'schema_version' => 2,
            'data' => $resource->toArray($request),
        ]);
    }
}
