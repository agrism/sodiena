<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class KekavaScraper extends BaseScraper
{
    public const BASE_URL = 'https://kekava.lv';
    public const CALENDAR_PATH = '/notikumi/';

    /**
     * Map of known Ķekavas novads venues with normalized metadata
     */
    protected array $venueAddressMap = [
        'ķekavas kultūras nams' => [
            'name' => 'Ķekavas kultūras nams',
            'city' => 'Ķekava',
            'region' => 'Rīga un Pierīga',
            'address' => 'Gaismas iela 17, Ķekava, LV-2123',
            'place_type' => 'cultural_center',
            'lat' => 56.8285,
            'lng' => 24.2325,
        ],
        'ķekavas novadpētniecības muzejs' => [
            'name' => 'Ķekavas novadpētniecības muzejs',
            'city' => 'Ķekava',
            'region' => 'Rīga un Pierīga',
            'address' => 'Rīgas iela 26, Ķekava, LV-2123',
            'place_type' => 'museum',
            'lat' => 56.8260,
            'lng' => 24.2270,
        ],
        'ķekavas sporta klubs' => [
            'name' => 'Ķekavas sporta klubs',
            'city' => 'Ķekava',
            'region' => 'Rīga un Pierīga',
            'address' => 'Gaismas iela 19, Ķekava, LV-2123',
            'place_type' => 'sports_facility',
            'lat' => 56.8290,
            'lng' => 24.2330,
        ],
        'ķekavas sporta komplekss' => [
            'name' => 'Ķekavas sporta komplekss',
            'city' => 'Ķekava',
            'region' => 'Rīga un Pierīga',
            'address' => 'Gaismas iela 19, Ķekava, LV-2123',
            'place_type' => 'sports_facility',
            'lat' => 56.8290,
            'lng' => 24.2330,
        ],
        'ķekavas pagasta bibliotēka' => [
            'name' => 'Ķekavas pagasta bibliotēka',
            'city' => 'Ķekava',
            'region' => 'Rīga un Pierīga',
            'address' => 'Gaismas iela 17, Ķekava, LV-2123',
            'place_type' => 'library',
            'lat' => 56.8285,
            'lng' => 24.2325,
        ],
        'doles tautas nams' => [
            'name' => 'Doles Tautas nams',
            'city' => 'Ķekava',
            'region' => 'Rīga un Pierīga',
            'address' => 'Rīgas iela 26, Ķekava, LV-2123',
            'place_type' => 'cultural_center',
            'lat' => 56.8260,
            'lng' => 24.2270,
        ],
        'baložu pilsētas kultūras nams' => [
            'name' => 'Baložu pilsētas kultūras nams',
            'city' => 'Baloži',
            'region' => 'Rīga un Pierīga',
            'address' => 'Skolas iela 4, Baloži, LV-2128',
            'place_type' => 'cultural_center',
            'lat' => 56.8770,
            'lng' => 24.1190,
        ],
        'baložu pilsētas kultūras nama skvērs' => [
            'name' => 'Baložu pilsētas kultūras nama skvērs',
            'city' => 'Baloži',
            'region' => 'Rīga un Pierīga',
            'address' => 'Skolas iela 4, Baloži, LV-2128',
            'place_type' => 'park',
            'lat' => 56.8770,
            'lng' => 24.1190,
        ],
        'baložu pilsētas bibliotēka' => [
            'name' => 'Baložu pilsētas bibliotēka',
            'city' => 'Baloži',
            'region' => 'Rīga un Pierīga',
            'address' => 'Skolas iela 4, Baloži, LV-2128',
            'place_type' => 'library',
            'lat' => 56.8770,
            'lng' => 24.1190,
        ],
        'baložu vidusskola' => [
            'name' => 'Baložu vidusskola',
            'city' => 'Baloži',
            'region' => 'Rīga un Pierīga',
            'address' => 'Skolas iela 6, Baloži, LV-2128',
            'place_type' => 'education',
            'lat' => 56.8775,
            'lng' => 24.1195,
        ],
        'baldones muzejs' => [
            'name' => 'Baldones muzejs',
            'city' => 'Baldone',
            'region' => 'Rīga un Pierīga',
            'address' => 'Mercendarbes muiža, Mercendarbe, Baldones pagasts, LV-2125',
            'place_type' => 'museum',
            'lat' => 56.7645,
            'lng' => 24.4230,
        ],
        'mercendarbes muiža' => [
            'name' => 'Mercendarbes muiža',
            'city' => 'Baldone',
            'region' => 'Rīga un Pierīga',
            'address' => 'Mercendarbes muiža, Mercendarbe, Baldones pagasts, LV-2125',
            'place_type' => 'historical',
            'lat' => 56.7645,
            'lng' => 24.4230,
        ],
        'baldones bibliotēka' => [
            'name' => 'Baldones bibliotēka',
            'city' => 'Baldone',
            'region' => 'Rīga un Pierīga',
            'address' => 'Daugavas iela 2A, Baldone, LV-2125',
            'place_type' => 'library',
            'lat' => 56.7445,
            'lng' => 24.3940,
        ],
        'baldones vidusskolas stadions' => [
            'name' => 'Baldones vidusskolas stadions',
            'city' => 'Baldone',
            'region' => 'Rīga un Pierīga',
            'address' => 'Iecavas iela 6, Baldone, LV-2125',
            'place_type' => 'sports_facility',
            'lat' => 56.7420,
            'lng' => 24.3980,
        ],
        'baldones sporta komplekss' => [
            'name' => 'Baldones sporta komplekss',
            'city' => 'Baldone',
            'region' => 'Rīga un Pierīga',
            'address' => 'Iecavas iela 2, Baldone, LV-2125',
            'place_type' => 'sports_facility',
            'lat' => 56.7425,
            'lng' => 24.3970,
        ],
        'katlakalna tautas nams' => [
            'name' => 'Katlakalna Tautas nams',
            'city' => 'Katlakalns',
            'region' => 'Rīga un Pierīga',
            'address' => 'Priežu iela 7, Katlakalns, Ķekavas pagasts, LV-2111',
            'place_type' => 'cultural_center',
            'lat' => 56.8995,
            'lng' => 24.1850,
        ],
        'katlalana tautas nams' => [
            'name' => 'Katlakalna Tautas nams',
            'city' => 'Katlakalns',
            'region' => 'Rīga un Pierīga',
            'address' => 'Priežu iela 7, Katlakalns, Ķekavas pagasts, LV-2111',
            'place_type' => 'cultural_center',
            'lat' => 56.8995,
            'lng' => 24.1850,
        ],
        'daugmales multifunkcionālais centrs' => [
            'name' => 'Daugmales multifunkcionālais centrs',
            'city' => 'Daugmale',
            'region' => 'Rīga un Pierīga',
            'address' => '"Salnas", Daugmale, Daugmales pagasts, LV-2124',
            'place_type' => 'cultural_center',
            'lat' => 56.8120,
            'lng' => 24.3960,
        ],
        'valdlauči' => [
            'name' => 'Valdlauči',
            'city' => 'Valdlauči',
            'region' => 'Rīga un Pierīga',
            'address' => 'Valdlauči, Ķekavas pagasts, LV-1076',
            'place_type' => 'venue',
            'lat' => 56.8990,
            'lng' => 24.1480,
        ],
    ];

    /**
     * Map of Ķekavas novads towns and coordinates
     */
    protected array $townCoordinates = [
        'ķekava' => ['lat' => 56.8285, 'lng' => 24.2325],
        'baldone' => ['lat' => 56.7445, 'lng' => 24.3940],
        'baloži' => ['lat' => 56.8770, 'lng' => 24.1190],
        'katlakalns' => ['lat' => 56.8995, 'lng' => 24.1850],
        'daugmale' => ['lat' => 56.8120, 'lng' => 24.3960],
        'valdlauči' => ['lat' => 56.8990, 'lng' => 24.1480],
        'rāmava' => ['lat' => 56.8850, 'lng' => 24.1500],
    ];

    public function getSlug(): string
    {
        return 'kekava';
    }

    public function getName(): string
    {
        return 'Ķekavas novads';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $targetUrl = $source->url ?: (self::BASE_URL . self::CALENDAR_PATH);

        // Ensure list view parameter is requested to get all upcoming events on a single page
        if (!str_contains($targetUrl, 'gme_view=')) {
            $targetUrl .= (str_contains($targetUrl, '?') ? '&' : '?') . 'gme_view=list';
        }

        $crawler = $this->fetchCrawler($targetUrl);
        if (!$crawler) {
            Log::warning("Could not fetch Kekava events page: {$targetUrl}");
            return $events;
        }

        $today = Carbon::today('Europe/Riga');
        $seenExternalIds = [];

        $articles = $crawler->filter('article.gme-events-calendar-list__event');

        $articles->each(function (Crawler $node) use (&$events, $today, &$seenExternalIds, $targetUrl) {
            try {
                // 1. Title & URL
                $titleLink = $node->filter('a.gme-events-calendar-list__event-title-link');
                if (!$titleLink->count()) {
                    return;
                }
                $title = trim($titleLink->text());
                if (empty($title)) {
                    return;
                }

                $eventUrl = $titleLink->attr('href');
                if (!empty($eventUrl) && !str_starts_with($eventUrl, 'http')) {
                    $eventUrl = rtrim(self::BASE_URL, '/') . '/' . ltrim($eventUrl, '/');
                }

                // Generate external ID from URL slug or title hash
                $slug = '';
                if (!empty($eventUrl)) {
                    $pathParts = explode('/', trim(parse_url($eventUrl, PHP_URL_PATH) ?? '', '/'));
                    $slug = end($pathParts);
                }
                $externalId = !empty($slug) ? $slug : ('kekava-' . md5($title . ($eventUrl ?? '')));

                if (isset($seenExternalIds[$externalId])) {
                    return;
                }
                $seenExternalIds[$externalId] = true;

                // 2. Dates & Times
                $dateNode = $node->filter('time.gme-events-calendar-list__event-datetime');
                $rawDate = $dateNode->count() ? trim($dateNode->text()) : '';

                $dateTagNode = $node->filter('time.gme-events-calendar-list__event-date-tag-datetime');
                $fallbackDateStr = $dateTagNode->count() ? $dateTagNode->attr('datetime') : null;

                [$startAt, $endAt] = $this->parseDates($rawDate, $fallbackDateStr);
                if (!$startAt) {
                    return;
                }

                // STRICT RULE: No Past Events Modification
                $effectiveEnd = $endAt ?: $startAt;
                if ($effectiveEnd->lt($today)) {
                    return;
                }

                // 3. Venue & Location
                $venueNode = $node->filter('span.gme-events-calendar-list__event-venue-title');
                $rawVenue = $venueNode->count() ? trim($venueNode->text()) : null;
                $locationInfo = $this->resolveLocation($rawVenue, $title);

                // 4. Category Badge & Strict Inference
                $categoryBadgeNode = $node->filter('span.gme-event-category-badge');
                $categoryBadge = $categoryBadgeNode->count() ? trim($categoryBadgeNode->text()) : null;
                $categories = $this->inferCategory($title, $categoryBadge, $locationInfo['name']);

                // 5. Featured Image
                $imgNode = $node->filter('img.gme-events-calendar-list__event-featured-image');
                $imageUrl = $imgNode->count() ? $imgNode->attr('src') : null;

                // 6. Cost & Free Admission
                $costNode = $node->filter('.gme-events-calendar-list__event-cost');
                $rawCost = $costNode->count() ? trim($costNode->text()) : null;
                $priceInfo = $this->extractPricesAndFree($rawCost, $title);

                // 7. Descriptions
                $cleanTitle = $this->cleanText($title) ?? $title;
                $description = "{$cleanTitle} norisināsies {$locationInfo['name']} ({$locationInfo['city']}).";
                $shortDescription = Str::limit($description, 250, '...');

                $dto = new ScrapedEventDTO(
                    title: $cleanTitle,
                    startAt: $startAt,
                    endAt: $endAt,
                    description: $description,
                    shortDescription: $shortDescription,
                    venueName: $locationInfo['name'],
                    city: $locationInfo['city'],
                    region: 'Rīga un Pierīga',
                    address: $locationInfo['address'],
                    latitude: $locationInfo['lat'],
                    longitude: $locationInfo['lng'],
                    placeType: $locationInfo['place_type'],
                    categoryNames: $categories,
                    entertainmentType: null,
                    isFree: $priceInfo['is_free'],
                    priceMin: $priceInfo['price_min'],
                    priceMax: $priceInfo['price_max'],
                    currency: 'EUR',
                    ticketUrl: null,
                    imageUrl: $imageUrl,
                    sourceUrl: $eventUrl ?: $targetUrl,
                    sourceExternalId: $externalId,
                    locale: 'lv',
                    rawData: [
                        'raw_date' => $rawDate,
                        'raw_venue' => $rawVenue,
                        'category_badge' => $categoryBadge,
                        'raw_cost' => $rawCost,
                    ]
                );

                $events->push($dto);
            } catch (\Throwable $e) {
                Log::warning("Error parsing Kekava event card: " . $e->getMessage());
            }
        });

        return $events;
    }

    /**
     * Parse date and time string from Ķekava events list
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public function parseDates(?string $rawDate, ?string $fallbackDateStr = null): array
    {
        if (empty($rawDate) && empty($fallbackDateStr)) {
            return [null, null];
        }

        $clean = html_entity_decode(strip_tags($rawDate ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = str_replace(["\xc2\xa0", '&nbsp;'], ' ', $clean);
        $clean = preg_replace('/\s+/u', ' ', trim($clean));

        // 1. Full date range: e.g. "19.09.2026 10:00 – 03.12.2026 17:00" or "01.10.2026 00:00 – 30.12.2026 00:00"
        if (preg_match('/^(\d{2}\.\d{2}\.\d{4})(?:\s+(\d{1,2}:\d{2}))?\s*(?:-|–|—)\s*(\d{2}\.\d{2}\.\d{4})(?:\s+(\d{1,2}:\d{2}))?$/u', $clean, $m)) {
            $startDateStr = $m[1];
            $startTimeStr = $m[2] ?? '00:00';
            $endDateStr = $m[3];
            $endTimeStr = $m[4] ?? '00:00';

            try {
                $start = Carbon::createFromFormat('d.m.Y H:i', "{$startDateStr} {$startTimeStr}", 'Europe/Riga');
                $end = Carbon::createFromFormat('d.m.Y H:i', "{$endDateStr} {$endTimeStr}", 'Europe/Riga');
                if ($startTimeStr === '00:00' && $endTimeStr === '00:00') {
                    $end = $end->endOfDay();
                }
                return [$start, $end];
            } catch (\Throwable) {
                // fall through
            }
        }

        // 2. Single date with time range: e.g. "18.11.2026 10:00 - 11:30"
        if (preg_match('/^(\d{2}\.\d{2}\.\d{4})\s+(\d{1,2}:\d{2})\s*(?:-|–|—)\s*(\d{1,2}:\d{2})$/u', $clean, $m)) {
            $dateStr = $m[1];
            $startTimeStr = $m[2];
            $endTimeStr = $m[3];

            try {
                $start = Carbon::createFromFormat('d.m.Y H:i', "{$dateStr} {$startTimeStr}", 'Europe/Riga');
                $end = Carbon::createFromFormat('d.m.Y H:i', "{$dateStr} {$endTimeStr}", 'Europe/Riga');
                if ($end->lt($start)) {
                    $end->addDay();
                }
                return [$start, $end];
            } catch (\Throwable) {
                // fall through
            }
        }

        // 3. Single date with single time: e.g. "18.11.2026 18:00"
        if (preg_match('/^(\d{2}\.\d{2}\.\d{4})(?:\s+(\d{1,2}:\d{2}))?$/u', $clean, $m)) {
            $dateStr = $m[1];
            $timeStr = $m[2] ?? '00:00';

            try {
                $start = Carbon::createFromFormat('d.m.Y H:i', "{$dateStr} {$timeStr}", 'Europe/Riga');
                return [$start, null];
            } catch (\Throwable) {
                // fall through
            }
        }

        // 4. Fallback to datetime attribute on date tag (e.g. "2026-09-19")
        if (!empty($fallbackDateStr)) {
            try {
                $start = Carbon::parse($fallbackDateStr, 'Europe/Riga')->startOfDay();
                return [$start, null];
            } catch (\Throwable) {
                // fall through
            }
        }

        return [null, null];
    }

    /**
     * Resolve Location metadata for Ķekavas novads
     */
    public function resolveLocation(?string $rawVenue, string $title): array
    {
        $raw = trim($rawVenue ?? '');
        $combined = mb_strtolower($raw . ' ' . $title, 'UTF-8');

        // 1. Check known venue map
        foreach ($this->venueAddressMap as $venueKey => $info) {
            if (str_contains($combined, $venueKey)) {
                return $info;
            }
        }

        // 2. Identify town / settlement from location or context
        $townStems = [
            'katlakaln' => 'katlakalns',
            'katlalan' => 'katlakalns',
            'baldon' => 'baldone',
            'balož' => 'baloži',
            'daugmal' => 'daugmale',
            'valdlauč' => 'valdlauči',
            'rāmav' => 'rāmava',
            'ķekav' => 'ķekava',
        ];

        $matchedTown = null;
        foreach ($townStems as $stem => $canonicalTown) {
            if (preg_match('/\b' . preg_quote($stem, '/') . '[a-zāčēģīķļņšūž]*/ui', $raw)) {
                $matchedTown = $canonicalTown;
                break;
            }
        }
        if (!$matchedTown) {
            foreach ($townStems as $stem => $canonicalTown) {
                if (preg_match('/\b' . preg_quote($stem, '/') . '[a-zāčēģīķļņšūž]*/ui', $combined)) {
                    $matchedTown = $canonicalTown;
                    break;
                }
            }
        }

        $townName = $matchedTown ? Str::title($matchedTown) : 'Ķekava';
        $coords = $matchedTown ? ($this->townCoordinates[$matchedTown] ?? ['lat' => 56.8285, 'lng' => 24.2325]) : ['lat' => 56.8285, 'lng' => 24.2325];

        $venueName = !empty($raw) ? $raw : $townName;
        $address = "{$venueName}, {$townName}, Ķekavas novads";

        // Infer place type
        $placeType = 'venue';
        $lowerVenue = mb_strtolower($venueName, 'UTF-8');
        if (str_contains($lowerVenue, 'kultūras nams') || str_contains($lowerVenue, 'tautas nams')) {
            $placeType = 'cultural_center';
        } elseif (str_contains($lowerVenue, 'muzejs') || str_contains($lowerVenue, 'muiža')) {
            $placeType = 'museum';
        } elseif (str_contains($lowerVenue, 'bibliotēka')) {
            $placeType = 'library';
        } elseif (str_contains($lowerVenue, 'sporta') || str_contains($lowerVenue, 'stadions')) {
            $placeType = 'sports_facility';
        } elseif (str_contains($lowerVenue, 'skola') || str_contains($lowerVenue, 'ģimnāzija')) {
            $placeType = 'education';
        } elseif (str_contains($lowerVenue, 'parks') || str_contains($lowerVenue, 'skvērs')) {
            $placeType = 'park';
        }

        return [
            'name' => $venueName,
            'city' => $townName,
            'region' => 'Rīga un Pierīga',
            'address' => $address,
            'place_type' => $placeType,
            'lat' => $coords['lat'],
            'lng' => $coords['lng'],
        ];
    }

    /**
     * Extract prices and free admission indicators
     */
    public function extractPricesAndFree(?string $costText, string $title): array
    {
        $text = html_entity_decode($costText ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xc2\xa0", '&nbsp;', "'", '&#039;'], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', trim($text));

        $combined = mb_strtolower($title . ' ' . $text, 'UTF-8');
        $isFree = false;

        if (preg_match('/(?:bez\s+maksas|bezmaksas|ieeja\s+brīva|briva\s+ieeja|par\s+brīvu)/ui', $combined)) {
            $isFree = true;
        }

        $prices = [];
        // Handle "€10:00" typo as €10.00
        $normalizedText = preg_replace('/(\d+):(\d{2})\b/', '$1.$2', $text);
        if (preg_match_all('/(\d+(?:[.,]\d+)?)/u', $normalizedText, $m)) {
            foreach ($m[1] as $pStr) {
                $val = (float) str_replace(',', '.', $pStr);
                if ($val >= 0.50 && $val <= 500) {
                    $prices[] = $val;
                }
            }
        }

        $priceMin = !empty($prices) ? min($prices) : ($isFree ? 0.0 : null);
        $priceMax = !empty($prices) ? max($prices) : ($isFree ? 0.0 : null);

        return [
            'is_free' => $isFree && empty($prices),
            'price_min' => $priceMin,
            'price_max' => $priceMax,
        ];
    }

    /**
     * Strict categorization complying with AGENTS.md rules
     */
    public function inferCategory(string $title, ?string $badge = null, ?string $venueName = null): array
    {
        $badgeLower = mb_strtolower(trim($badge ?? ''), 'UTF-8');
        $titleLower = mb_strtolower($title, 'UTF-8');
        $venueLower = mb_strtolower($venueName ?? '', 'UTF-8');
        $text = $titleLower . ' ' . $badgeLower . ' ' . $venueLower;

        // 1. Strict Kino rules from AGENTS.md (Priority: Cinema venues or keywords -> ALWAYS Kino, NEVER Teātris)
        if (
            $badgeLower === 'filma' ||
            str_contains($venueLower, 'kino rio') ||
            str_contains($venueLower, 'k.suns') ||
            str_contains($venueLower, 'k suns') ||
            str_contains($venueLower, 'forum cinemas') ||
            str_contains($venueLower, 'apollo kino') ||
            str_contains($venueLower, 'cinamon') ||
            str_contains($venueLower, 'splendid palace') ||
            str_contains($venueLower, 'kino bize') ||
            str_contains($venueLower, 'kino lora') ||
            str_contains($venueLower, 'kino gaisma') ||
            preg_match('/\bkinoteātr[a-zāčēģīķļņšūž]*/ui', $venueLower) ||
            preg_match('/\bkinoteātr[a-zāčēģīķļņšūž]*/ui', $titleLower) ||
            preg_match('/\b(kinoseans[a-zāčēģīķļņšūž]*|film[a-zāčēģīķļņšūž]*|filmu vakars|spēlfilm[a-zāčēģīķļņšūž]*|dokumentāl[a-zāčēģīķļņšūž]* film[a-zāčēģīķļņšūž]*|animācij[a-zāčēģīķļņšūž]* film[a-zāčēģīķļņšūž]*|kinofestivāl[a-zāčēģīķļņšūž]*)\b/ui', $titleLower) ||
            preg_match('/\b(kinoseans[a-zāčēģīķļņšūž]*|filmas seans[a-zāčēģīķļņšūž]*|spēlfilm[a-zāčēģīķļņšūž]*|dokumentāl[a-zāčēģīķļņšūž]* film[a-zāčēģīķļņšūž]*|animācij[a-zāčēģīķļņšūž]* film[a-zāčēģīķļņšūž]*)\b/ui', $text)
        ) {
            return ['Kino'];
        }

        // 2. Strict Theatre rules from AGENTS.md (Only actual theater productions or theater venues, never kinoteātris)
        if (
            !preg_match('/\bkinoteātr/ui', $text) && (
                $badgeLower === 'izrāde' ||
                preg_match('/\b(izrād[a-zāčēģīķļņšūž]*|teātr[a-zāčēģīķļņšūž]*|amatierteātr[a-zāčēģīķļņšūž]*|lug[a-zāčēģīķļņšūž]*|iestudējum[a-zāčēģīķļņšūž]*|oper[a-zāčēģīķļņšūž]*|balet[a-zāčēģīķļņšūž]*)\b/ui', $titleLower) ||
                preg_match('/\b(teātra izrād[a-zāčēģīķļņšūž]*|amatierteātra izrād[a-zāčēģīķļņšūž]*|pirmizrād[a-zāčēģīķļņšūž]*|leļļu izrād[a-zāčēģīķļņšūž]*)\b/ui', $text)
            )
        ) {
            return ['Teātris'];
        }

        // 3. Music & Concerts
        if (
            $badgeLower === 'koncerts' ||
            $badgeLower === 'balle' ||
            $badgeLower === 'danči' ||
            preg_match('/\b(koncert[a-zāčēģīķļņšūž]*|dzejkoncert[a-zāčēģīķļņšūž]*|koru|koris|korim|orķestr[a-zāčēģīķļņšūž]*|akustisk[a-zāčēģīķļņšūž]*|dziesm[a-zāčēģīķļņšūž]*|ball[a-zāčēģīķļņšūž]*|danč[a-zāčēģīķļņšūž]*|zaļumball[a-zāčēģīķļņšūž]*|muzikāl[a-zāčēģīķļņšūž]*|mūzik[a-zāčēģīķļņšūž]*|muzicē|grupa|ansambl[a-zāčēģīķļņšūž]*|dziedātāj[a-zāčēģīķļņšūž]*|solist[a-zāčēģīķļņšūž]*)\b/ui', $text)
        ) {
            return ['Mūzika'];
        }

        // 4. Sports & Active
        if (
            $badgeLower === 'sports' ||
            preg_match('/\b(sports|sporta|sportisk[a-zāčēģīķļņšūž]*|sacensīb[a-zāčēģīķļņšūž]*|čempionāt[a-zāčēģīķļņšūž]*|turnīr[a-zāčēģīķļņšūž]*|skrējien[a-zāčēģīķļņšūž]*|maraton[a-zāčēģīķļņšūž]*|pārgājien[a-zāčēģīķļņšūž]*|velobraucien[a-zāčēģīķļņšūž]*|orientēšan[a-zāčēģīķļņšūž]*|basketbol[a-zāčēģīķļņšūž]*|futbol[a-zāčēģīķļņšūž]*|volejbol[a-zāčēģīķļņšūž]*|florbol[a-zāčēģīķļņšūž]*|hokej[a-zāčēģīķļņšūž]*|nūjošan[a-zāčēģīķļņšūž]*|šahs|cornhole)\b/ui', $text)
        ) {
            return ['Sports'];
        }

        // 5. Exhibitions & Art
        if (
            $badgeLower === 'izstāde' ||
            preg_match('/\b(fotoizstād[a-zāčēģīķļņšūž]*|mākslas izstād[a-zāčēģīķļņšūž]*|izstād[a-zāčēģīķļņšūž]*|ekspozīcij[a-zāčēģīķļņšūž]*|glezn[a-zāčēģīķļņšūž]*|fotogrāfij[a-zāčēģīķļņšūž]*|fotomāksl[a-zāčēģīķļņšūž]*|māksl[a-zāčēģīķļņšūž]*|keramik[a-zāčēģīķļņšūž]*|tēlniecīb[a-zāčēģīķļņšūž]*|simpozij[a-zāčēģīķļņšūž]*|porcelān[a-zāčēģīķļņšūž]*)\b/ui', $text)
        ) {
            return ['Izstādes'];
        }

        // 6. Kids & Family
        if (preg_match('/\b(bērniem|bērnu|ģimenēm|leļļu|radošā darbnīca bērniem|rotaļas|animācija|grāmatu tārpiņ[a-zāčēģīķļņšūž]*)\b/ui', $text)) {
            return ['Bērniem'];
        }

        // 7. Education & Workshops
        if (
            $badgeLower === 'lekcija' ||
            $badgeLower === 'darbnīca' ||
            $badgeLower === 'lasītāju klubs' ||
            preg_match('/\b(meistarklas[a-zāčēģīķļņšūž]*|seminār[a-zāčēģīķļņšūž]*|lekcij[a-zāčēģīķļņšūž]*|nodarbīb[a-zāčēģīķļņšūž]*|darbnīc[a-zāčēģīķļņšūž]*|diskusij[a-zāčēģīķļņšūž]*|sarunu vakars|stāstu vakars|viktorīn[a-zāčēģīķļņšūž]*|konferenc[a-zāčēģīķļņšūž]*|lasītāju klub[a-zāčēģīķļņšūž]*|tikšanās)\b/ui', $text)
        ) {
            return ['Izglītība'];
        }

        // 8. Holidays & Culture
        if (
            $badgeLower === 'svētku pasākums' ||
            $badgeLower === 'tradīcijas' ||
            preg_match('/\b(svētk[a-zāčēģīķļņšūž]*|svinīb[a-zāčēģīķļņšūž]*|lāčplēš[a-zāčēģīķļņšūž]*|valsts svētk[a-zāčēģīķļņšūž]*|mārtiņdien[a-zāčēģīķļņšūž]*|ziemassvētk[a-zāčēģīķļņšūž]*|egles iedegšan[a-zāčēģīķļņšūž]*|jaunais gads|lieldien[a-zāčēģīķļņšūž]*|saulgriež[a-zāčēģīķļņšūž]*|jāņi)\b/ui', $text)
        ) {
            return ['Kultūra'];
        }

        return ['Cits'];
    }
}
