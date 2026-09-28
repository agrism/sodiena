<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->localized_slug ?: $this->slug,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'seo_description' => $this->seo_description,
            'start_at' => $this->start_at?->toIso8601String(),
            'end_at' => $this->end_at?->toIso8601String(),
            'all_day' => (bool) $this->all_day,
            'formatted_date' => $this->formatted_date,
            'is_free' => (bool) $this->is_free,
            'price_min' => $this->price_min !== null ? (float) $this->price_min : null,
            'price_max' => $this->price_max !== null ? (float) $this->price_max : null,
            'currency' => $this->currency ?: 'EUR',
            'formatted_price' => $this->formatted_price,
            'entertainment_type' => $this->entertainment_type,
            'localized_entertainment_type' => $this->localized_entertainment_type,
            'image_url' => $this->display_image_url,
            'original_image_url' => $this->image_url,
            'ticket_url' => $this->ticket_url,
            'source_url' => $this->source_url,
            'ticket_links' => $this->ticket_links ?: [],
            'display_venue' => $this->display_venue,
            'organizer' => [
                'name' => $this->organizer_display_name,
                'url' => $this->organizer_url,
            ],
            'location' => $this->location ? new LocationResource($this->location) : null,
            'categories' => CategoryResource::collection($this->whenLoaded('categories', $this->categories, fn () => $this->categories)),
            'source_slug' => $this->source_slug,
            'is_featured' => (bool) $this->is_featured,
            'views_count' => (int) $this->views_count,
            'web_url' => $this->localized_url,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
