<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'icon',
        'color',
        'description',
        'order',
    ];

    protected static function booted(): void
    {
        static::creating(function ($category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('order');
    }

    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(CategoryTranslation::class);
    }

    public function translation(?string $locale = null): ?CategoryTranslation
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

    public function getDescriptionAttribute(?string $value): ?string
    {
        $trans = $this->translation();
        return ($trans && !empty($trans->description)) ? $trans->description : $value;
    }
}
