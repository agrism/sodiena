<?php

namespace App\Models;

use App\Enums\SourceType;
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
        'settings',
    ];

    protected $casts = [
        'type' => SourceType::class,
        'is_active' => 'boolean',
        'last_scraped_at' => 'datetime',
        'settings' => 'array',
    ];

    public function getTypeAttribute($value): SourceType
    {
        if ($value instanceof SourceType) {
            return $value;
        }

        return SourceType::tryFrom((string) $value) ?? SourceType::OTHER;
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function scrapeLogs(): HasMany
    {
        return $this->hasMany(ScrapeLog::class)->orderByDesc('started_at');
    }
}
