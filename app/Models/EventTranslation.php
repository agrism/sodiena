<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EventTranslation extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'locale',
        'title',
        'slug',
        'description',
        'short_description',
    ];

    protected static function booted(): void
    {
        static::creating(function ($translation) {
            if (empty($translation->slug)) {
                $translation->slug = Str::slug($translation->title) . '-' . Str::random(6);
            }
        });

        static::saving(function ($translation) {
            if ($translation->title !== null) {
                $translation->title = mb_substr(trim($translation->title), 0, 255, 'UTF-8');
            }
        });
    }

    public function setTitleAttribute(?string $value): void
    {
        $this->attributes['title'] = $value !== null ? mb_substr(trim($value), 0, 255, 'UTF-8') : null;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
