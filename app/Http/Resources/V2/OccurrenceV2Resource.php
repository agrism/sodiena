<?php

namespace App\Http\Resources\V2;

use App\Enums\SourceType;
use App\Models\EventOccurrence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventOccurrence
 */
class OccurrenceV2Resource extends JsonResource
{
    protected ?int $flattenedMergedIntoId;
    protected bool $includeLegacyIds;

    public function __construct($resource, ?int $flattenedMergedIntoId = null, bool $includeLegacyIds = false)
    {
        parent::__construct($resource);
        $this->flattenedMergedIntoId = $flattenedMergedIntoId;
        $this->includeLegacyIds = $includeLegacyIds;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // 1. Language semantics: strict null vs []
        // null means "inherit from parent event"
        // [] means "explicitly no spoken or subtitle language"
        $spoken = $this->spoken_languages === null ? null : (array) $this->spoken_languages;
        $subtitles = $this->subtitle_languages === null ? null : (array) $this->subtitle_languages;
        $langSource = $this->language_source ? (string) $this->language_source : null;

        // 2. Ticket offers gathered from activeSources
        $ticketOffers = [];
        $seenUrls = [];

        if ($this->relationLoaded('activeSources')) {
            foreach ($this->activeSources as $os) {
                if (empty($os->ticket_url) || $os->is_missing) {
                    continue;
                }
                $normUrl = rtrim(strtolower(trim($os->ticket_url)), '/');
                if (isset($seenUrls[$normUrl])) {
                    continue;
                }
                $seenUrls[$normUrl] = true;

                $source = $os->source;
                $sourceType = $source?->type instanceof SourceType ? $source->type : SourceType::OTHER;
                $providerName = $os->provider_name ?? \App\Services\Tickets\TicketProviderResolver::resolve(
                    $os->ticket_url,
                    $source?->slug,
                    $source?->name
                );

                $ticketOffers[] = [
                    'source_slug' => $source?->slug ?: 'source',
                    'provider_name' => $providerName,
                    'url' => $os->ticket_url,
                    'price_min' => $os->price_min !== null ? (float) $os->price_min : null,
                    'price_max' => $os->price_max !== null ? (float) $os->price_max : null,
                    'currency' => $os->currency ?: 'EUR',
                    '_priority' => $sourceType->ticketPriority(),
                ];
            }
        }

        // Fallback: If no offers from activeSources but occurrence has ticket_url
        if (empty($ticketOffers) && !empty($this->ticket_url)) {
            $src = $this->event?->primaryContentSource ?? $this->event?->source;
            $providerName = \App\Services\Tickets\TicketProviderResolver::resolve(
                $this->ticket_url,
                $src?->slug,
                $src?->name
            );

            $ticketOffers[] = [
                'source_slug' => $src?->slug ?? 'direct',
                'provider_name' => $providerName,
                'url' => $this->ticket_url,
                'price_min' => $this->price_min !== null ? (float) $this->price_min : null,
                'price_max' => $this->price_max !== null ? (float) $this->price_max : null,
                'currency' => $this->currency ?: 'EUR',
                '_priority' => 50,
            ];
        }

        // Sort ticket offers by priority descending
        if (!empty($ticketOffers)) {
            usort($ticketOffers, fn ($a, $b) => $b['_priority'] <=> $a['_priority']);
            $ticketOffers = array_map(function ($offer) {
                unset($offer['_priority']);
                return $offer;
            }, $ticketOffers);
        }

        $mergedIntoId = $this->flattenedMergedIntoId !== null
            ? $this->flattenedMergedIntoId
            : ($this->status === 'merged' && $this->merged_into_id ? (int) $this->merged_into_id : null);

        $data = [
            'id' => (int) $this->id,
            'status' => (string) $this->status,
            'merged_into_id' => $mergedIntoId,
            'legacy_event_id' => $this->legacy_event_id ? (int) $this->legacy_event_id : null,
            'start_at' => $this->start_at?->toIso8601String(),
            'end_at' => $this->end_at?->toIso8601String(),
            'all_day' => (bool) $this->all_day,
            'is_free' => (bool) $this->is_free,
            'price_min' => $this->price_min !== null ? (float) $this->price_min : null,
            'price_max' => $this->price_max !== null ? (float) $this->price_max : null,
            'currency' => $this->currency ?: 'EUR',
            'ticket_url' => $this->ticket_url,
            'ticket_offers' => $ticketOffers,
            'presentation_format' => $this->presentation_format,
            'auditorium' => $this->auditorium,
            'language' => [
                'spoken' => $spoken,
                'subtitles' => $subtitles,
                'source' => $langSource,
            ],
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        return $data;
    }
}
