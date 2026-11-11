<?php

namespace App\Enums;

enum SourceType: string
{
    case TICKETING = 'ticketing';
    case VENUE = 'venue';
    case SPORTS = 'sports';
    case MUNICIPALITY = 'municipality';
    case THEMATIC = 'thematic';
    case AGGREGATOR = 'aggregator';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::TICKETING => 'Biļešu tirgotājs',
            self::VENUE => 'Norises vieta / Muzejs',
            self::SPORTS => 'Sporta federācija / Līga',
            self::MUNICIPALITY => 'Pašvaldība / Tūrisms',
            self::THEMATIC => 'Festivāls / Tematiskais',
            self::AGGREGATOR => 'Valsts reģistrs / Agregators',
            self::OTHER => 'Cits avots',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::TICKETING => 'ticket',
            self::VENUE => 'landmark',
            self::SPORTS => 'trophy',
            self::MUNICIPALITY => 'building-2',
            self::THEMATIC => 'sparkles',
            self::AGGREGATOR => 'database',
            self::OTHER => 'globe',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::TICKETING => 'bg-amber-50 text-amber-700 border-amber-200',
            self::VENUE => 'bg-purple-50 text-purple-700 border-purple-200',
            self::SPORTS => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            self::MUNICIPALITY => 'bg-blue-50 text-blue-700 border-blue-200',
            self::THEMATIC => 'bg-rose-50 text-rose-700 border-rose-200',
            self::AGGREGATOR => 'bg-slate-50 text-slate-700 border-slate-200',
            self::OTHER => 'bg-slate-50 text-slate-600 border-slate-200',
        };
    }

    /**
     * Overall deduplication trust and quality priority score (1 - 100)
     */
    public function priority(): int
    {
        return match ($this) {
            self::VENUE => 90,        // Primary source of truth for physical venue, hall, local program
            self::SPORTS => 85,       // Primary source of truth for match schedule & teams
            self::TICKETING => 80,    // Primary source of truth for commercial tickets & prices
            self::THEMATIC => 75,     // Direct festival organizer
            self::MUNICIPALITY => 70, // Local municipal / tourism portal
            self::AGGREGATOR => 50,   // National re-distributor / secondary aggregator
            self::OTHER => 40,
        };
    }

    /**
     * Priority for ticketing URL and pricing fields
     */
    public function ticketPriority(): int
    {
        return match ($this) {
            self::TICKETING => 100,
            self::VENUE => 70,
            self::THEMATIC => 60,
            self::SPORTS => 50,
            self::MUNICIPALITY => 40,
            self::AGGREGATOR => 30,
            self::OTHER => 20,
        };
    }

    /**
     * Priority for location / venue / address resolution
     */
    public function locationPriority(): int
    {
        return match ($this) {
            self::VENUE => 100,
            self::SPORTS => 80,
            self::MUNICIPALITY => 70,
            self::THEMATIC => 60,
            self::TICKETING => 40,
            self::AGGREGATOR => 30,
            self::OTHER => 20,
        };
    }
}

