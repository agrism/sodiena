<?php

namespace App\Enums;

enum EventLanguageSource: string
{
    case Manual = 'manual';
    case Explicit = 'explicit';
    case Inferred = 'inferred';
    case Unknown = 'unknown';

    public function priority(): int
    {
        return match ($this) {
            self::Manual => 40,
            self::Explicit => 30,
            self::Inferred => 20,
            self::Unknown => 10,
        };
    }

    public function isHigherThan(self $other): bool
    {
        return $this->priority() > $other->priority();
    }

    public function isAtLeast(self $other): bool
    {
        return $this->priority() >= $other->priority();
    }

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manuāli labots (Manual)',
            self::Explicit => 'Eksplicīts avota lauks (Explicit)',
            self::Inferred => 'Secināts pēc noteikumiem (Inferred)',
            self::Unknown => 'Nav zināms (Unknown)',
        };
    }
}
