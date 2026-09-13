<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'city',
        'region',
        'address',
        'latitude',
        'longitude',
        'place_type',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(function ($location) {
            if (empty($location->slug)) {
                $location->slug = Str::slug($location->name . '-' . $location->city);
            }
        });
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(LocationTranslation::class);
    }

    public function translation(?string $locale = null): ?LocationTranslation
    {
        $locale = $locale ?: app()->getLocale();

        if ($this->relationLoaded('translations')) {
            return $this->translations->firstWhere('locale', $locale)
                ?: $this->translations->firstWhere('locale', 'lv')
                ?: $this->translations->first();
        }

        return $this->translations()->where('locale', $locale)->first()
            ?: $this->translations()->where('locale', 'lv')->first()
            ?: $this->translations()->first();
    }

    public function getNameAttribute(?string $value): string
    {
        $trans = $this->translation();
        return ($trans && !empty($trans->name)) ? $trans->name : ($value ?? '');
    }

    public function getCityAttribute(?string $value): string
    {
        $trans = $this->translation();
        return ($trans && !empty($trans->city)) ? $trans->city : ($value ?? '');
    }

    public function getRegionAttribute(?string $value): string
    {
        $trans = $this->translation();
        return ($trans && !empty($trans->region)) ? $trans->region : ($value ?? '');
    }

    public function getAddressAttribute(?string $value): ?string
    {
        $trans = $this->translation();
        return ($trans && !empty($trans->address)) ? $trans->address : $value;
    }
}
