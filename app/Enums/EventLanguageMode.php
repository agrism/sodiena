<?php

namespace App\Enums;

enum EventLanguageMode: string
{
    case Spoken = 'spoken';
    case LanguageFree = 'language_free';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Spoken => 'Valoda ir būtiska (Spoken)',
            self::LanguageFree => 'Bez valodas barjeras (Language-free)',
            self::Unknown => 'Nav noteikts (Unknown)',
        };
    }
}
