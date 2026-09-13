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
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
