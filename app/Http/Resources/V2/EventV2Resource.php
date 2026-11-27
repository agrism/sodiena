<?php

namespace App\Http\Resources\V2;

use App\Enums\SourceType;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Services\EventLanguageService;
use App\Services\LocaleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @mixin Event
 */
class EventV2Resource extends JsonResource
{
    protected string $syncStatus;
    protected ?string $syncStatusReason;
    protected ?int $flattenedMergedIntoId;
    protected ?array $legacyEventIds;
    protected bool $includeLegacyIds;
    protected ?Collection $occurrencesCollection;
    protected ?array $occurrenceMergedLookup;

    public function __construct(
        $resource,
        string $syncStatus = 'active',
        ?string $syncStatusReason = null,
        ?int $flattenedMergedIntoId = null,
        ?array $legacyEventIds = null,
        bool $includeLegacyIds = false,
        ?Collection $occurrencesCollection = null,
        ?array $occurrenceMergedLookup = null
    ) {
        parent::__construct($resource);
        $this->syncStatus = $syncStatus;
        $this->syncStatusReason = $syncStatusReason;
        $this->flattenedMergedIntoId = $flattenedMergedIntoId;
        $this->legacyEventIds = $legacyEventIds;
        $this->includeLegacyIds = $includeLegacyIds;
        $this->occurrencesCollection = $occurrencesCollection;
        $this->occurrenceMergedLookup = $occurrenceMergedLookup;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // 1. Compact Tombstone Payload for merged or deleted events
        if (in_array($this->syncStatus, ['merged', 'deleted'], true)) {
            $mergedAt = $this->merged_at
                ? $this->merged_at->toIso8601String()
                : ($this->syncStatus === 'merged' ? ($this->updated_at?->toIso8601String() ?? now()->toIso8601String()) : null);

            $tombstone = [
                'id' => (int) $this->id,
                'sync_status' => $this->syncStatus,
                'sync_status_reason' => $this->syncStatusReason,
                'merged_into_id' => $this->syncStatus === 'merged' ? $this->flattenedMergedIntoId : null,
                'merged_at' => $mergedAt,
                'deleted_at' => $this->deleted_at?->toIso8601String(),
                'updated_at' => $this->updated_at?->toIso8601String(),
            ];

            return $tombstone;
        }

        // 2. Full Payload for active, cancelled, archived events
        $locale = app()->getLocale() ?: 'lv';

        // Content source
        $primarySource = null;
        $src = $this->primaryContentSource ?? $this->source;
        if ($src) {
            $srcType = $src->type instanceof SourceType ? $src->type->value : (string) $src->type;
            $primarySource = [
                'id' => (int) $src->id,
                'slug' => (string) $src->slug,
                'name' => (string) $src->name,
                'type' => $srcType,
            ];
        }

        // Location payload
        $locationPayload = null;
        if ($this->location) {
            $loc = $this->location;
            $locCity = $loc->city ?: '';
            $locationPayload = [
                'id' => (int) $loc->id,
                'name' => (string) $loc->name,
                'slug' => (string) $loc->slug,
                'city' => (string) $locCity,
                'city_slug' => !empty($locCity) ? Str::slug($locCity) : null,
                'region' => $loc->region ? (string) $loc->region : null,
                'address' => $loc->address ? (string) $loc->address : null,
                'latitude' => $loc->latitude !== null ? (float) $loc->latitude : null,
                'longitude' => $loc->longitude !== null ? (float) $loc->longitude : null,
                'place_type' => $loc->place_type ? (string) $loc->place_type : null,
            ];
        }

        // Categories payload
        $categoriesPayload = [];
        if ($this->relationLoaded('categories')) {
            foreach ($this->categories as $cat) {
                $categoriesPayload[] = [
                    'id' => (int) $cat->id,
                    'name' => (string) $cat->name,
                    'slug' => (string) $cat->slug,
                ];
            }
        }

        // Raw categories array
        $rawCategories = is_array($this->raw_categories)
            ? array_values(array_filter($this->raw_categories))
            : null;

        // Language payload
        $languagePayload = EventLanguageService::toPortableArray($this->resource);

        // Occurrences collection mapping
        $occs = $this->occurrencesCollection ?? ($this->relationLoaded('occurrences') ? $this->occurrences : collect());
        $occurrencesPayload = [];
        foreach ($occs as $occ) {
            $occMergedInto = $this->occurrenceMergedLookup[$occ->id] ?? ($occ->merged_into_id ? (int) $occ->merged_into_id : null);
            $occurrencesPayload[] = (new OccurrenceV2Resource($occ, $occMergedInto, $this->includeLegacyIds))->toArray($request);
        }

        // Web URL
        $localizedSlug = $this->localized_slug ?: $this->slug;
        $baseUrl = rtrim(config('app.url', 'https://sodiena.lv'), '/');
        $webUrl = LocaleService::url($locale, "{$baseUrl}/events/{$localizedSlug}");

        // Full payload array
        $result = [
            'id' => (int) $this->id,
            'sync_status' => $this->syncStatus,
            'sync_status_reason' => $this->syncStatusReason,
            'merged_into_id' => null,
            'merged_at' => null,
            'deleted_at' => null,
            'content_hash' => (string) ($this->content_hash ?: hash('sha256', "{$this->id}_{$this->updated_at}")),
        ];

        if ($this->includeLegacyIds) {
            $result['legacy_event_ids'] = $this->legacyEventIds ?? [(int) $this->id];
        }

        $result = array_merge($result, [
            'title' => (string) $this->title,
            'slug' => (string) $localizedSlug,
            'short_description' => $this->short_description ? (string) $this->short_description : null,
            'description' => $this->description ? (string) $this->description : null,
            'seo_description' => $this->seo_description ? (string) $this->seo_description : null,
            'next_start_at' => $this->next_start_at?->toIso8601String() ?? $this->start_at?->toIso8601String(),
            'last_end_at' => $this->last_end_at?->toIso8601String() ?? ($this->end_at?->toIso8601String() ?? $this->start_at?->toIso8601String()),
            'occurrences_count' => count($occurrencesPayload),
            'is_free' => (bool) $this->is_free,
            'price_min' => $this->price_min !== null ? (float) $this->price_min : null,
            'price_max' => $this->price_max !== null ? (float) $this->price_max : null,
            'currency' => (string) ($this->currency ?: 'EUR'),
            'entertainment_type' => $this->entertainment_type ? (string) $this->entertainment_type : null,
            'image_url' => $this->display_image_url ? (string) $this->display_image_url : null,
            'original_image_url' => $this->image_url ? (string) $this->image_url : null,
            'source_url' => $this->source_url ? (string) $this->source_url : null,
            'web_url' => $webUrl,
            'is_featured' => (bool) $this->is_featured,
            'is_promoted' => (bool) $this->is_promoted,
            'views_count' => (int) ($this->views_count ?? 0),
            'primary_source' => $primarySource,
            'organizer' => null,
            'location' => $locationPayload,
            'categories' => $categoriesPayload,
            'raw_source_categories' => !empty($rawCategories) ? $rawCategories : null,
            'language' => $languagePayload,
            'occurrences' => $occurrencesPayload,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ]);

        return $result;
    }
}
