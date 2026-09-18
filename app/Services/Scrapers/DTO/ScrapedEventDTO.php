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
        public ?string $city = 'Rīga',
        public ?string $region = 'Rīga un Pierīga',
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
}
