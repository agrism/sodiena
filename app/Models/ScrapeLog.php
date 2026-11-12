<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapeLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_id',
        'status',
        'items_found',
        'items_created',
        'items_updated',
        'duration_seconds',
        'errors',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'errors' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration_seconds' => 'float',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function isStarted(): bool
    {
        return in_array($this->status, ['started', 'running'], true);
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    public function isPartial(): bool
    {
        return $this->status === 'partial';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'started', 'running' => 'Uzsākts / Procesā',
            'success' => 'Veiksmīgs',
            'partial' => 'Daļējs',
            'failed' => 'Kļūda',
            default => ucfirst((string) $this->status),
        };
    }

    public function statusBadgeClasses(): string
    {
        return match ($this->status) {
            'started', 'running' => 'bg-blue-50 text-blue-700 border-blue-200',
            'success' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'partial' => 'bg-amber-50 text-amber-700 border-amber-200',
            'failed' => 'bg-rose-50 text-rose-700 border-rose-200',
            default => 'bg-slate-100 text-slate-700 border-slate-200',
        };
    }

    public function statusIcon(): string
    {
        return match ($this->status) {
            'started', 'running' => 'loader-2',
            'success' => 'check-circle-2',
            'partial' => 'alert-triangle',
            'failed' => 'alert-circle',
            default => 'help-circle',
        };
    }

    public function getPrimaryError(): ?string
    {
        if (is_array($this->errors) && !empty($this->errors)) {
            return (string) $this->errors[0];
        }
        if (is_string($this->errors) && !empty($this->errors)) {
            return $this->errors;
        }
        return null;
    }

    public function getFormattedDuration(): string
    {
        if ($this->isStarted()) {
            return 'Procesā...';
        }
        $sec = (float) $this->duration_seconds;
        if ($sec >= 60) {
            $min = floor($sec / 60);
            $remSec = round(fmod($sec, 60));
            return "{$min}m {$remSec}s";
        }
        return "{$sec}s";
    }

    public function scopeForDate($query, string $date)
    {
        return $query->whereDate('started_at', $date);
    }
}
