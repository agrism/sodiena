<?php

namespace App\Models;

use App\Enums\SourceType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'url',
        'scraper_class',
        'type',
        'is_active',
        'last_scraped_at',
        'last_status',
        'last_error',
        'duplicates_prevented_count',
        'settings',
        'supports_reconciliation',
        'reconciliation_min_ratio',
        'reconciliation_grace_runs',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_scraped_at' => 'datetime',
        'duplicates_prevented_count' => 'integer',
        'settings' => 'array',
        'supports_reconciliation' => 'boolean',
        'reconciliation_min_ratio' => 'decimal:2',
        'reconciliation_grace_runs' => 'integer',
    ];

    protected function type(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => ($value instanceof SourceType ? $value : SourceType::tryFrom((string) $value)) ?? SourceType::OTHER,
            set: fn ($value) => $value instanceof SourceType ? $value->value : (is_string($value) ? $value : null),
        );
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function occurrenceSources(): HasMany
    {
        return $this->hasMany(OccurrenceSource::class);
    }

    public function scrapeLogs(): HasMany
    {
        return $this->hasMany(ScrapeLog::class)->orderByDesc('started_at');
    }

    public function latestScrapeLog(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ScrapeLog::class)->latestOfMany('started_at');
    }

    public function scopeOrderByPriority($query)
    {
        return $query->orderByRaw("
            CASE type
                WHEN 'venue' THEN 90
                WHEN 'sports' THEN 85
                WHEN 'ticketing' THEN 80
                WHEN 'thematic' THEN 75
                WHEN 'municipality' THEN 70
                WHEN 'aggregator' THEN 50
                ELSE 40
            END DESC, name ASC
        ");
    }
}
