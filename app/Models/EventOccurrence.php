<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventOccurrence extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'event_id',
        'start_at',
        'end_at',
        'all_day',
        'status',
        'is_free',
        'price_min',
        'price_max',
        'currency',
        'ticket_url',
        'presentation_format',
        'auditorium',
        'spoken_languages',
        'subtitle_languages',
        'language_source',
        'occurrence_fingerprint',
        'merged_into_id',
        'legacy_event_id',
        'cancelled_at',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'all_day' => 'boolean',
        'is_free' => 'boolean',
        'price_min' => 'decimal:2',
        'price_max' => 'decimal:2',
        'spoken_languages' => 'array',
        'subtitle_languages' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (EventOccurrence $model) {
            if ($model->merged_into_id !== null && $model->id !== null && (int) $model->merged_into_id === (int) $model->id) {
                throw new \InvalidArgumentException('An occurrence cannot be merged into itself.');
            }
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(OccurrenceSource::class, 'event_occurrence_id');
    }

    public function activeSources(): HasMany
    {
        return $this->hasMany(OccurrenceSource::class, 'event_occurrence_id')->where('is_missing', false);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(EventAction::class, 'event_occurrence_id');
    }
}
