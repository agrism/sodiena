<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OccurrenceSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_occurrence_id',
        'source_id',
        'source_external_id',
        'source_url',
        'ticket_url',
        'price_min',
        'price_max',
        'currency',
        'is_free',
        'raw_title',
        'raw_description',
        'raw_image_url',
        'raw_categories',
        'raw_payload',
        'content_hash',
        'last_scrape_log_id',
        'last_seen_at',
        'is_missing',
        'missing_runs_count',
        'missing_since_at',
    ];

    protected $casts = [
        'price_min' => 'decimal:2',
        'price_max' => 'decimal:2',
        'is_free' => 'boolean',
        'is_missing' => 'boolean',
        'missing_runs_count' => 'integer',
        'raw_categories' => 'array',
        'raw_payload' => 'array',
        'last_seen_at' => 'datetime',
        'missing_since_at' => 'datetime',
    ];

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(EventOccurrence::class, 'event_occurrence_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id');
    }

    public function lastScrapeLog(): BelongsTo
    {
        return $this->belongsTo(ScrapeLog::class, 'last_scrape_log_id');
    }
}
