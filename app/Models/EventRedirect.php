<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRedirect extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'old_event_id',
        'locale',
        'old_slug',
        'target_event_id',
        'reason',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function targetEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'target_event_id');
    }
}
