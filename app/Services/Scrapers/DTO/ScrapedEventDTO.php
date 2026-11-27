<?php

namespace App\Services\Scrapers\DTO;

use Carbon\Carbon;

class ScrapedEventDTO
{
    public function __construct(
        public string $title,
        public Carbon $startAt,
        public ?Carbon $endAt = null,
        public ?string $description = null,
        public ?string $shortDescription = null,
        public ?string $venueName = null,
        public ?string $city = null,
        public ?string $region = null,
        public ?string $address = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?string $placeType = 'venue',
        public array $categoryNames = [],
        public ?string $entertainmentType = null,
        public bool $isFree = false,
        public ?float $priceMin = null,
        public ?float $priceMax = null,
        public string $currency = 'EUR',
        public ?string $ticketUrl = null,
        public ?string $imageUrl = null,
        public ?string $sourceUrl = null,
        public ?string $sourceExternalId = null,
        public string $locale = 'lv',
        public array $rawData = []
    ) {}

    public function getFingerprint(): string
    {
        $normTitle = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $this->title ?? ''));
        $date = ($this->startAt && $this->startAt->format('H:i') !== '00:00') ? $this->startAt->format('Y-m-d H:i') : ($this->startAt ? $this->startAt->format('Y-m-d') : '');
        $city = mb_strtolower(trim($this->city ?? ''));
        return hash('sha256', "{$normTitle}|{$date}|{$city}");
    }

    public function isAllDay(): bool
    {
        return !empty($this->rawData['all_day'])
            || !empty($this->rawData['is_all_day'])
            || ($this->startAt && $this->startAt->format('H:i:s') === '00:00:00' && ($this->endAt === null || $this->endAt->format('H:i:s') === '23:59:59' || $this->endAt->format('H:i:s') === '00:00:00'));
    }

    public function getPresentationFormat(): ?string
    {
        $raw = $this->rawData['presentation_method'] 
            ?? $this->rawData['cinema']['presentation_method'] 
            ?? null;

        if (empty($raw) || !is_string($raw)) {
            return null;
        }

        $raw = trim($raw);
        $upper = mb_strtoupper($raw, 'UTF-8');

        if (str_contains($upper, 'IMAX') && str_contains($upper, '3D')) {
            return 'IMAX 3D';
        }
        if (str_contains($upper, 'IMAX')) {
            return 'IMAX 2D';
        }
        if (str_contains($upper, '3D')) {
            return '3D';
        }
        if (str_contains($upper, 'LUX') || str_contains($upper, 'LUXE')) {
            return 'LUX';
        }
        if (str_contains($upper, 'ATMOS')) {
            return 'Dolby Atmos';
        }
        if (str_contains($upper, '2D')) {
            return '2D';
        }

        return $raw;
    }

    public function getAuditorium(): ?string
    {
        $aud = $this->rawData['cinema']['auditorium'] 
            ?? $this->rawData['auditorium'] 
            ?? null;

        if (empty($aud) || !is_string($aud)) {
            return null;
        }

        return mb_substr(trim($aud), 0, 120, 'UTF-8');
    }

    public function getLanguagePayload(): ?array
    {
        $lang = $this->rawData['language'] ?? null;
        return is_array($lang) ? $lang : null;
    }

    public function getEffectiveExternalId(string $sourceSlug): string
    {
        if (!empty($this->sourceExternalId) && is_string($this->sourceExternalId)) {
            return trim($this->sourceExternalId);
        }

        $dateStr = $this->startAt ? $this->startAt->format('Y-m-d H:i') : '';
        $normTitle = mb_strtolower(trim($this->title ?? ''));
        $normVenue = mb_strtolower(trim($this->venueName ?? ''));
        $sourceUrl = $this->sourceUrl ?? '';

        $seed = "{$sourceSlug}|{$sourceUrl}|{$normTitle}|{$dateStr}|{$normVenue}";
        return 'synth_' . substr(hash('sha256', $seed), 0, 32);
    }

    public function getCanonicalEventTitle(bool $isCinema = false): string
    {
        $title = trim($this->title ?? '');
        if (!$isCinema) {
            return $title;
        }

        $cleaned = $title;

        do {
            $before = $cleaned;
            // Strip trailing language notes in parentheses or standalone
            $cleaned = preg_replace('/\s*\(\s*(?:lv|en|ru|lat|eng|rus|dub|sub)\s*\)\s*$/ui', '', $cleaned);
            $cleaned = preg_replace('/\s*(?:\(|\s)*(?:latviešu|krievu|angļu)\s*valodā\)?\s*$/ui', '', $cleaned);
            $cleaned = preg_replace('/\s*(?:\(|\s)*(?:ar\s+subtitriem|ar\s+titriem|bez\s+subtitriem)[^)]*\)?\s*$/ui', '', $cleaned);

            // Strip trailing formats: IMAX 2D, IMAX 3D, IMAX, 2D, 3D, LUX, LUXE, Dolby Atmos
            $cleaned = preg_replace('/\s*(?:\(|\s)+(?:IMAX\s*3D|IMAX\s*2D|IMAX|3D|2D|LUXE?|Dolby\s*Atmos)\)?\s*$/ui', '', $cleaned);

            // Strip trailing delimiters
            $cleaned = trim($cleaned, " \t\n\r\0\x0B-–—|•");
        } while ($cleaned !== $before);

        return !empty($cleaned) ? $cleaned : $title;
    }
}
