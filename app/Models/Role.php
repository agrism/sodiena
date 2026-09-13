<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use HasFactory;

    public const ADMIN = 'admin';
    public const REGULAR = 'regular';

    protected $fillable = [
        'name',
        'slug',
        'description',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function getLocalizedNameAttribute(): string
    {
        $locale = app()->getLocale();

        $names = [
            self::ADMIN => [
                'lv' => 'Administrators',
                'en' => 'Administrator',
                'ru' => 'Администратор',
            ],
            self::REGULAR => [
                'lv' => 'Lietotājs',
                'en' => 'User',
                'ru' => 'Пользователь',
            ],
        ];

        return $names[$this->slug][$locale] ?? $names[$this->slug]['lv'] ?? $this->name;
    }
}
