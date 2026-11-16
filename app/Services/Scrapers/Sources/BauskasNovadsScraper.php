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

class BauskasNovadsScraper extends BaseScraper
{
    public const BASE_URL = 'https://www.bauskasnovads.lv';
    public const CALENDAR_PATH = '/lv/notikumu-kalendars';

    /**
     * Map of known Bauskas novads venues with normalized metadata
     */
    protected array $venueAddressMap = [
        // Bauska
        'bauskas kultūras centrs' => [
            'name' => 'Bauskas Kultūras centrs',
            'city' => 'Bauska',
            'region' => 'Zemgale',
            'address' => 'Kalna iela 18, Bauska, Bauskas novads, LV-3901',
            'place_type' => 'cultural_center',
        ],
        'bauskas kc' => [
            'name' => 'Bauskas Kultūras centrs',
            'city' => 'Bauska',
            'region' => 'Zemgale',
            'address' => 'Kalna iela 18, Bauska, Bauskas novads, LV-3901',
            'place_type' => 'cultural_center',
        ],
        'bauskas pils' => [
            'name' => 'Bauskas pils muzejs',
            'city' => 'Bauska',
            'region' => 'Zemgale',
            'address' => 'Pilskalns, Bauska, Bauskas novads, LV-3901',
            'place_type' => 'historical',
        ],
        'bauskas pilsdrupas' => [
            'name' => 'Bauskas pilsdrupu estrāde',
            'city' => 'Bauska',
            'region' => 'Zemgale',
            'address' => 'Pilskalns, Bauska, Bauskas novads, LV-3901',
            'place_type' => 'park',
        ],
        'bauskas pils parks' => [
            'name' => 'Bauskas pilskalna parks',
            'city' => 'Bauska',
            'region' => 'Zemgale',
            'address' => 'Pilskalns, Bauska, Bauskas novads, LV-3901',
            'place_type' => 'park',
        ],
        'bauskas pilskalna estrāde' => [
            'name' => 'Bauskas pilskalna estrāde',
            'city' => 'Bauska',
            'region' => 'Zemgale',
            'address' => 'Pilskalns, Bauska, Bauskas novads, LV-3901',
            'place_type' => 'park',
        ],
        'bauskas rātsnams' => [
            'name' => 'Bauskas Rātsnams',
            'city' => 'Bauska',
            'region' => 'Zemgale',
            'address' => 'Rātslaukums 1, Bauska, Bauskas novads, LV-3901',
            'place_type' => 'historical',
        ],
        'bauskas muzejs' => [
            'name' => 'Bauskas muzejs',
            'city' => 'Bauska',
            'region' => 'Zemgale',
            'address' => 'Kalna iela 6, Bauska, Bauskas novads, LV-3901',
            'place_type' => 'museum',
        ],
        'bauskas motormuzejs' => [
            'name' => 'Rīgas Motormuzeja Bauskas filiāle',
            'city' => 'Bauska',
            'region' => 'Zemgale',
            'address' => 'Sarkanā muiža 6, Bauska, Bauskas novads, LV-3901',
            'place_type' => 'museum',
        ],
        'bauskas centrālā bibliotēka' => [
            'name' => 'Bauskas Centrālā bibliotēka',
            'city' => 'Bauska',
            'region' => 'Zemgale',
            'address' => 'Kalna iela 18, Bauska, Bauskas novads, LV-3901',
            'place_type' => 'library',
        ],
        'bauskas bērnu bibliotēka' => [
            'name' => 'Bauskas Bērnu bibliotēka',
            'city' => 'Bauska',
            'region' => 'Zemgale',
            'address' => 'Kalna iela 18, Bauska, Bauskas novads, LV-3901',
            'place_type' => 'library',
        ],
        'bauskas novada bērnu un jauniešu centrs' => [
            'name' => 'Bauskas Bērnu un jauniešu centrs',
            'city' => 'Bauska',
            'region' => 'Zemgale',
            'address' => 'Rīgas iela 8, Bauska, Bauskas novads, LV-3901',
            'place_type' => 'cultural_center',
        ],
        'bauskas peldbaseins' => [
            'name' => 'Bauskas peldbaseins',
            'city' => 'Bauska',
            'region' => 'Zemgale',
            'address' => 'Dārza iela 22b, Bauska, Bauskas novads, LV-3901',
            'place_type' => 'sports_facility',
        ],
        'bauskas sporta halle' => [
            'name' => 'Bauskas sporta halle',
            'city' => 'Bauska',
            'region' => 'Zemgale',
            'address' => 'Pilskalna iela 26, Bauska, Bauskas novads, LV-3901',
            'place_type' => 'sports_facility',
        ],
        'bauskas stadions' => [
            'name' => 'Bauskas pilsētas stadions',
            'city' => 'Bauska',
            'region' => 'Zemgale',
            'address' => 'Pilskalna iela 26, Bauska, Bauskas novads, LV-3901',
            'place_type' => 'sports_facility',
        ],

        // Rundāle / Pilsrundāle
        'rundāles pils' => [
            'name' => 'Rundāles pils muzejs',
            'city' => 'Pilsrundāle',
            'region' => 'Zemgale',
            'address' => 'Pilsrundāle, Rundāles pagasts, Bauskas novads, LV-3921',
            'place_type' => 'historical',
        ],
        'rundāles multifunkcionālais centrs' => [
            'name' => 'Rundāles multifunkcionālais centrs',
            'city' => 'Pilsrundāle',
            'region' => 'Zemgale',
            'address' => 'Pilsrundāle 1, Rundāles pagasts, Bauskas novads, LV-3921',
            'place_type' => 'cultural_center',
        ],
        'svitenes tautas nams' => [
            'name' => 'Svitenes Tautas nams',
            'city' => 'Svitene',
            'region' => 'Zemgale',
            'address' => '"Svitene", Svitenes pagasts, Bauskas novads, LV-3917',
            'place_type' => 'cultural_center',
        ],
        'svitenes muiža' => [
            'name' => 'Svitenes muiža',
            'city' => 'Svitene',
            'region' => 'Zemgale',
            'address' => 'Svitenes muiža, Svitenes pagasts, Bauskas novads, LV-3917',
            'place_type' => 'historical',
        ],

        // Iecava
        'iecavas kultūras nams' => [
            'name' => 'Iecavas kultūras nams',
            'city' => 'Iecava',
            'region' => 'Zemgale',
            'address' => 'Rīgas iela 18, Iecava, Bauskas novads, LV-3913',
            'place_type' => 'cultural_center',
        ],
        'iecavas kn' => [
            'name' => 'Iecavas kultūras nams',
            'city' => 'Iecava',
            'region' => 'Zemgale',
            'address' => 'Rīgas iela 18, Iecava, Bauskas novads, LV-3913',
            'place_type' => 'cultural_center',
        ],
        'iecavas estrāde' => [
            'name' => 'Iecavas parka estrāde',
            'city' => 'Iecava',
            'region' => 'Zemgale',
            'address' => 'Iecavas parks, Iecava, Bauskas novads, LV-3913',
            'place_type' => 'park',
        ],
        'iecavas parks' => [
            'name' => 'Iecavas parks',
            'city' => 'Iecava',
            'region' => 'Zemgale',
            'address' => 'Iecavas parks, Iecava, Bauskas novads, LV-3913',
            'place_type' => 'park',
        ],
        'iecavas sporta nams dartija' => [
            'name' => 'Iecavas sporta nams "Dartija"',
            'city' => 'Iecava',
            'region' => 'Zemgale',
            'address' => 'Grāfa laukums 7, Iecava, Bauskas novads, LV-3913',
            'place_type' => 'sports_facility',
        ],
        'iecavas bibliotēka' => [
            'name' => 'Iecavas pilsētas bibliotēka',
            'city' => 'Iecava',
            'region' => 'Zemgale',
            'address' => 'Grāfa laukums 1, Iecava, Bauskas novads, LV-3913',
            'place_type' => 'library',
        ],

        // Vecumnieki & apkārtne
        'vecumnieku tautas nams' => [
            'name' => 'Vecumnieku Tautas nams',
            'city' => 'Vecumnieki',
            'region' => 'Zemgale',
            'address' => 'Bauskas iela 4, Vecumnieki, Bauskas novads, LV-3933',
            'place_type' => 'cultural_center',
        ],
        'vecumnieku mūzikas un mākslas skola' => [
            'name' => 'Vecumnieku Mūzikas un mākslas skola',
            'city' => 'Vecumnieki',
            'region' => 'Zemgale',
            'address' => 'Bauskas iela 4, Vecumnieki, Bauskas novads, LV-3933',
            'place_type' => 'cultural_center',
        ],
        'skaistkalnes tautas nams' => [
            'name' => 'Skaistkalnes Tautas nams',
            'city' => 'Skaistkalne',
            'region' => 'Zemgale',
            'address' => 'Skolas iela 1, Skaistkalne, Skaistkalnes pagasts, Bauskas novads, LV-3924',
            'place_type' => 'cultural_center',
        ],
        'stelpes tautas nams' => [
            'name' => 'Stelpes Tautas nams',
            'city' => 'Stelpe',
            'region' => 'Zemgale',
            'address' => 'Stelpe, Stelpes pagasts, Bauskas novads, LV-3925',
            'place_type' => 'cultural_center',
        ],
        'stelpes estrāde' => [
            'name' => 'Stelpes estrāde',
            'city' => 'Stelpe',
            'region' => 'Zemgale',
            'address' => 'Stelpe, Stelpes pagasts, Bauskas novads, LV-3925',
            'place_type' => 'park',
        ],
        'valles saieta nams' => [
            'name' => 'Valles saieta nams',
            'city' => 'Valle',
            'region' => 'Zemgale',
            'address' => 'Liepu gatve 1, Valle, Valles pagasts, Bauskas novads, LV-5106',
            'place_type' => 'cultural_center',
        ],
        'bārbeles tautas nams' => [
            'name' => 'Bārbeles Tautas nams',
            'city' => 'Bārbele',
            'region' => 'Zemgale',
            'address' => 'Bārbele, Bārbeles pagasts, Bauskas novads, LV-3905',
            'place_type' => 'cultural_center',
        ],
        'kurmenes tautas nams' => [
            'name' => 'Kurmenes Tautas nams',
            'city' => 'Kurmene',
            'region' => 'Zemgale',
            'address' => 'Kurmene, Kurmenes pagasts, Bauskas novads, LV-5115',
            'place_type' => 'cultural_center',
        ],
        'misas tautas nams' => [
            'name' => 'Misas Tautas nams',
            'city' => 'Misa',
            'region' => 'Zemgale',
            'address' => 'Misa, Vecumnieku pagasts, Bauskas novads, LV-3906',
            'place_type' => 'cultural_center',
        ],

        // Bauskas pagasti
        'īslīces kultūras nams' => [
            'name' => 'Īslīces Kultūras nams',
            'city' => 'Bērzkalni',
            'region' => 'Zemgale',
            'address' => 'Rītausmas, Īslīces pagasts, Bauskas novads, LV-3901',
            'place_type' => 'cultural_center',
        ],
        'mežotnes tautas nams' => [
            'name' => 'Mežotnes Tautas nams',
            'city' => 'Mežotne',
            'region' => 'Zemgale',
            'address' => 'Mežotne, Mežotnes pagasts, Bauskas novads, LV-3918',
            'place_type' => 'cultural_center',
        ],
        'mežotnes pils' => [
            'name' => 'Mežotnes pils',
            'city' => 'Mežotne',
            'region' => 'Zemgale',
            'address' => 'Pils iela 1, Mežotne, Mežotnes pagasts, Bauskas novads, LV-3918',
            'place_type' => 'historical',
        ],
        'codes tautas nams' => [
            'name' => 'Codes Tautas nams',
            'city' => 'Code',
            'region' => 'Zemgale',
            'address' => '"Dārznieki", Code, Codes pagasts, Bauskas novads, LV-3901',
            'place_type' => 'cultural_center',
        ],
        'ceraukstes tautas nams' => [
            'name' => 'Ceraukstes Tautas nams',
            'city' => 'Ceraukste',
            'region' => 'Zemgale',
            'address' => 'Ceraukste, Ceraukstes pagasts, Bauskas novads, LV-3908',
            'place_type' => 'cultural_center',
        ],
        'gailīšu tautas nams' => [
            'name' => 'Gailīšu Tautas nams',
            'city' => 'Uzvara',
            'region' => 'Zemgale',
            'address' => 'Uzvara, Gailīšu pagasts, Bauskas novads, LV-3931',
            'place_type' => 'cultural_center',
        ],
        'brunavas tautas nams' => [
            'name' => 'Brunavas Tautas nams',
            'city' => 'Ērgļi',
            'region' => 'Zemgale',
            'address' => 'Ērgļi, Brunavas pagasts, Bauskas novads, LV-3907',
            'place_type' => 'cultural_center',
        ],
        'vecsaules tautas nams' => [
            'name' => 'Vecsaules Tautas nams',
            'city' => 'Vecsaule',
            'region' => 'Zemgale',
            'address' => 'Vecsaule, Vecsaules pagasts, Bauskas novads, LV-3932',
            'place_type' => 'cultural_center',
        ],
        'dāviņu tautas nams' => [
            'name' => 'Dāviņu Tautas nams',
            'city' => 'Dāviņi',
            'region' => 'Zemgale',
            'address' => 'Dāviņi, Dāviņu pagasts, Bauskas novads, LV-3936',
            'place_type' => 'cultural_center',
        ],
    ];

    public function getSlug(): string
    {
        return 'bauskas-novads';
    }

    public function getName(): string
    {
        return 'Bauskas novada pašvaldība';
    }

    /**
     * Scrape events from bauskasnovads.lv/lv/notikumu-kalendars
     * Strict rule: NO crawling into detail pages - everything extracted from calendar cards directly!
     */
    public function scrape(Source $source): Collection
    {
        $events = collect();
        $baseUrl = $source->url ?: (self::BASE_URL . self::CALENDAR_PATH);
        $maxPages = 10;
        $today = Carbon::today('Europe/Riga');
        $seenExternalIds = [];

        for ($page = 0; $page < $maxPages; $page++) {
            $pageUrl = $page === 0 ? $baseUrl : $baseUrl . '?page=' . $page;
            $crawler = $this->fetchCrawler($pageUrl);

            if (!$crawler) {
                break;
            }

            $items = $crawler->filter('div.event');
            if ($items->count() === 0) {
                break;
            }

            $allPageEventsPast = true;

            $items->each(function (Crawler $node) use (&$events, &$seenExternalIds, &$allPageEventsPast, $baseUrl, $today) {
                $titleNode = $node->filter('h2.event-title a, .event-title a, h2.event-title');
                $title = $this->cleanText($titleNode->count() ? $titleNode->first()->text() : '');
                if (empty($title)) {
                    return;
                }

                // Extract Categories
                $categories = [];
                $node->filter('.event-categories a, .event-categories')
                    ->each(function (Crawler $c) use (&$categories) {
                        $catText = trim($c->text());
                        if (!empty($catText)) {
                            $categories[] = $catText;
                        }
                    });
                $categories = array_values(array_unique($categories));

                // Exclude committee meetings, council sessions, etc.
                if ($this->isExcludedEvent($categories, $title)) {
                    return;
                }

                // Link to event
                $linkNode = $node->filter('h2.event-title a, a[href*="/notikums/"]');
                $relLink = $linkNode->count() ? $linkNode->first()->attr('href') : '';
                $cleanUrl = $this->normalizeUrl($relLink);

                $slugPart = $cleanUrl ? basename(parse_url($cleanUrl, PHP_URL_PATH)) : Str::slug($title);
                $externalId = 'bauskasnovads-' . $slugPart;

                if (isset($seenExternalIds[$externalId])) {
                    return;
                }

                // Dates & times
                $dateStr = $node->filter('span.date-date')->count() ? $this->cleanText($node->filter('span.date-date')->text()) : '';
                $timeStr = $node->filter('span.date-time')->count() ? $this->cleanText($node->filter('span.date-time')->text()) : '';
                [$startAt, $endAt, $allDay] = $this->parseLatvianDates($dateStr, $timeStr);

                if (!$startAt) {
                    return;
                }

                // Strict AGENTS.md rule: Do not process past events
                if ($endAt && $endAt->lt($today)) {
                    return;
                }
                if (!$endAt && $startAt->lt($today)) {
                    return;
                }

                $allPageEventsPast = false;

                // Description directly from card (strictly NO crawling into detail pages)
                $description = $node->filter('div.event-content')->count() ? $this->cleanText($node->filter('div.event-content')->text()) : $title;
                if (empty($description)) {
                    $description = $title;
                }

                // Location
                $locationRaw = $node->filter('span.describing-field-value')->count() ? $this->cleanText($node->filter('span.describing-field-value')->text()) : '';
                $venueInfo = $this->resolveLocation($locationRaw, $title, $description);

                // Categories & Entertainment Type (adhering strictly to AGENTS.md)
                $canonicalCategories = $this->resolveCategories($categories, $title, $description);
                $entertainmentType = $this->resolveEntertainmentType($canonicalCategories, $title, $description);

                // Price & Free check
                $priceData = $this->parsePrice($description);

                // Image
                $thumbImg = $node->filter('div.event-image img')->count() ? $node->filter('div.event-image img')->attr('src') : null;
                if ($thumbImg && !str_starts_with($thumbImg, 'http')) {
                    $thumbImg = self::BASE_URL . (str_starts_with($thumbImg, '/') ? '' : '/') . $thumbImg;
                }

                $seenExternalIds[$externalId] = true;

                $dto = new ScrapedEventDTO(
                    title: $title,
                    startAt: $startAt,
                    endAt: $endAt,
                    description: $description,
                    shortDescription: Str::limit(strip_tags($description), 160),
                    venueName: $venueInfo['name'],
                    city: $venueInfo['city'],
                    region: 'Zemgale',
                    address: $venueInfo['address'],
                    placeType: $venueInfo['place_type'],
                    categoryNames: $canonicalCategories,
                    entertainmentType: $entertainmentType,
                    isFree: $priceData['is_free'],
                    priceMin: $priceData['price_min'],
                    priceMax: $priceData['price_max'],
                    currency: 'EUR',
                    ticketUrl: $cleanUrl ?: $baseUrl,
                    imageUrl: $thumbImg,
                    sourceUrl: $cleanUrl ?: $baseUrl,
                    sourceExternalId: $externalId,
                    locale: 'lv',
                    rawData: [
                        'date_raw' => $dateStr,
                        'time_raw' => $timeStr,
                        'location_raw' => $locationRaw,
                        'categories_raw' => $categories,
                    ]
                );

                $events->push($dto);
            });

            if ($allPageEventsPast && $items->count() > 0) {
                // If all events on this page are in the past, stop paginating
                break;
            }
        }

        return $events;
    }

    /**
     * Resolve Location metadata for Bauskas novads
     */
    public function resolveLocation(string $locationRaw, string $title = '', string $description = ''): array
    {
        $raw = trim($locationRaw);
        $combined = mb_strtolower($raw . ' ' . $title . ' ' . $description, 'UTF-8');

        // 1. Direct or stem match in known venues map
        foreach ($this->venueAddressMap as $venueKey => $info) {
            if (str_contains($combined, $venueKey)) {
                return $info;
            }
            // Check stem (e.g. "bauskas kultūras centr" matches "bauskas kultūras centrā")
            $stem = preg_replace('/(s|ā|u|am|i|a|e|ē)$/u', '', $venueKey);
            if (mb_strlen($stem) >= 6 && str_contains($combined, $stem)) {
                return $info;
            }
        }

        // 2. Identify town / parish from location or combined context
        $townStems = [
            'rundāl' => 'Pilsrundāle',
            'pilsrundāl' => 'Pilsrundāle',
            'iecav' => 'Iecava',
            'vecumniek' => 'Vecumnieki',
            'skaistkaln' => 'Skaistkalne',
            'stelp' => 'Stelpe',
            'vall' => 'Valle',
            'bārbel' => 'Bārbele',
            'kurmen' => 'Kurmene',
            'mis' => 'Misa',
            'sviten' => 'Svitene',
            'īslīc' => 'Bērzkalni',
            'bērzkaln' => 'Bērzkalni',
            'mežotn' => 'Mežotne',
            'cod' => 'Code',
            'ceraukst' => 'Ceraukste',
            'gailīš' => 'Uzvara',
            'uzvar' => 'Uzvara',
            'brunav' => 'Ērgļi',
            'ērgl' => 'Ērgļi',
            'vecsaul' => 'Vecsaule',
            'dāviņ' => 'Dāviņi',
            'viestur' => 'Viesturi',
            'bausk' => 'Bauska',
        ];

        $matchedTown = null;
        // Check raw location string first
        foreach ($townStems as $stem => $canonicalTown) {
            if (preg_match('/\b' . preg_quote($stem, '/') . '[a-zāčēģīķļņšūž]*/ui', $raw)) {
                $matchedTown = $canonicalTown;
                break;
            }
        }

        // If not found in raw, check combined context
        if (!$matchedTown) {
            foreach ($townStems as $stem => $canonicalTown) {
                if (preg_match('/\b' . preg_quote($stem, '/') . '[a-zāčēģīķļņšūž]*/ui', $combined)) {
                    $matchedTown = $canonicalTown;
                    break;
                }
            }
        }

        $city = $matchedTown ?: 'Bauska';

        // 3. Extract venue name and address
        $venueName = $city;
        $address = "{$city}, Bauskas novads";

        if (!empty($raw)) {
            $parts = array_map('trim', explode(',', $raw));

            // Clean trailing country/municipality redundant tokens
            $cleanParts = array_filter($parts, function ($part) {
                $p = mb_strtolower($part, 'UTF-8');
                return $p !== 'latvija' && $p !== 'latvia' && $p !== 'bauskas novads' && $p !== 'bauska';
            });

            if (!empty($cleanParts)) {
                $venueName = reset($cleanParts);
                $address = implode(', ', $cleanParts) . ', Bauskas novads';
            }
        }

        // 4. Infer place type
        $placeType = 'venue';
        $lowerVenue = mb_strtolower($venueName, 'UTF-8');
        if (str_contains($lowerVenue, 'kultūras centrs') || str_contains($lowerVenue, 'kultūras nams') || str_contains($lowerVenue, 'tautas nams') || str_contains($lowerVenue, 'saieta nams')) {
            $placeType = 'cultural_center';
        } elseif (str_contains($lowerVenue, 'muzejs') || str_contains($lowerVenue, 'motormuzejs')) {
            $placeType = 'museum';
        } elseif (str_contains($lowerVenue, 'pils') || str_contains($lowerVenue, 'drupas') || str_contains($lowerVenue, 'muiža') || str_contains($lowerVenue, 'rātsnams')) {
            $placeType = 'historical';
        } elseif (str_contains($lowerVenue, 'bibliotēka')) {
            $placeType = 'library';
        } elseif (str_contains($lowerVenue, 'sporta') || str_contains($lowerVenue, 'halle') || str_contains($lowerVenue, 'stadions') || str_contains($lowerVenue, 'baseins')) {
            $placeType = 'sports_facility';
        } elseif (str_contains($lowerVenue, 'parks') || str_contains($lowerVenue, 'estrāde') || str_contains($lowerVenue, 'dārzs')) {
            $placeType = 'park';
        } elseif (str_contains($lowerVenue, 'skola') || str_contains($lowerVenue, 'ģimnāzija')) {
            $placeType = 'education';
        } elseif (str_contains($lowerVenue, 'baznīca')) {
            $placeType = 'religious';
        }

        return [
            'name' => $venueName,
            'city' => $city,
            'region' => 'Zemgale',
            'address' => $address,
            'place_type' => $placeType,
        ];
    }

    /**
     * Resolve categories following strict AGENTS.md rules
     */
    public function resolveCategories(array $rawCategories, string $title, string $description = ''): array
    {
        $text = mb_strtolower(implode(' ', $rawCategories) . ' ' . $title . ' ' . $description, 'UTF-8');

        // STRICT RULE 1: Cinema / Kino (Priority)
        // Kino venues or keywords: ALWAYS KINO, NEVER TEATRIS
        $cinemaKeywords = [
            'filma', 'filmas', 'filmu', 'filmā',
            'kinoseanss', 'filmas seanss', 'spēlfilma',
            'dokumentālā filma', 'animācijas filma', 'kinofestivāls',
            'kino seanss', 'kinoteātr', 'kino:', 'kino |',
        ];
        $isCinema = in_array('Kino', $rawCategories, true);
        if (!$isCinema) {
            foreach ($cinemaKeywords as $kw) {
                if (str_contains($text, $kw)) {
                    $isCinema = true;
                    break;
                }
            }
        }

        if ($isCinema) {
            $categories = ['Kino'];
            if (str_contains($text, 'bērniem') || str_contains($text, 'animācija') || str_contains($text, 'multfilma')) {
                $categories[] = 'Bērniem';
            }
            return array_unique($categories);
        }

        // STRICT RULE 2: Theaters
        $theatreKeywords = [
            'teātra izrāde', 'izrāde', 'izrādē', 'izrādi', 'izrādes', 'iestudējums',
            'luga', 'lugā', 'komēdija', 'traģēdija', 'stāvizrāde',
            'stand-up', 'standup', 'leļļu izrāde', 'balets', 'opera',
        ];
        $isTheatre = in_array('Teātris', $rawCategories, true) || in_array('Izrāde', $rawCategories, true);
        if (!$isTheatre) {
            foreach ($theatreKeywords as $kw) {
                if (str_contains($text, $kw)) {
                    $isTheatre = true;
                    break;
                }
            }
        }

        if ($isTheatre) {
            $categories = ['Teātris'];
            if (str_contains($text, 'bērniem') || str_contains($text, 'ģimenēm') || str_contains($text, 'leļļu')) {
                $categories[] = 'Bērniem';
            }
            return array_unique($categories);
        }

        // Music & Concerts
        $musicKeywords = [
            'koncerts', 'koncertā', 'koncertprogramma', 'dziedātājs',
            'koris', 'koru', 'orķestris', 'orķestra', 'kvartets',
            'akustiskā tūre', 'dziesmas', 'muzikāls', 'džezs', 'roks', 'balle',
        ];
        $isMusic = in_array('Koncerts', $rawCategories, true) || in_array('Mūzika', $rawCategories, true);
        if (!$isMusic) {
            foreach ($musicKeywords as $kw) {
                if (str_contains($text, $kw)) {
                    $isMusic = true;
                    break;
                }
            }
        }

        if ($isMusic) {
            $categories = ['Mūzika'];
            if (str_contains($text, 'bērniem') || str_contains($text, 'ģimenēm')) {
                $categories[] = 'Bērniem';
            }
            return array_unique($categories);
        }

        // Exhibitions & Art
        $exhibitionKeywords = [
            'izstāde', 'izstādē', 'izstādi', 'ekspozīcija',
            'personālizstāde', 'gleznas', 'glezniecība', 'gleznu',
            'tēlniecība', 'fotogrāfiju izstāde',
        ];
        $isExhibition = in_array('Izstāde', $rawCategories, true);
        if (!$isExhibition) {
            foreach ($exhibitionKeywords as $kw) {
                if (str_contains($text, $kw)) {
                    $isExhibition = true;
                    break;
                }
            }
        }

        if ($isExhibition) {
            return ['Izstādes'];
        }

        // Sports
        $sportsKeywords = ['sports', 'skrējiens', 'maratons', 'basketbols', 'futbols', 'hokejs', 'slidošana', 'turnīrs', 'nūjošana', 'peldēšana'];
        foreach ($sportsKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return ['Sports'];
            }
        }

        // Children
        if (str_contains($text, 'bērniem') || str_contains($text, 'leļļu') || in_array('Bērniem', $rawCategories, true)) {
            return ['Bērniem'];
        }

        // Fallback: Cits (Strict AGENTS.md rule)
        return ['Cits'];
    }

    /**
     * Resolve entertainment type
     */
    public function resolveEntertainmentType(array $categories, string $title, string $description = ''): string
    {
        $text = mb_strtolower($title . ' ' . $description, 'UTF-8');

        if (in_array('Kino', $categories, true)) {
            return 'movie';
        }
        if (in_array('Mūzika', $categories, true)) {
            return 'concert';
        }
        if (in_array('Teātris', $categories, true)) {
            return 'theatre';
        }
        if (in_array('Izstādes', $categories, true)) {
            return 'exhibition';
        }
        if (in_array('Sports', $categories, true)) {
            return 'sports';
        }
        if (str_contains($text, 'festivāls') || str_contains($text, 'svētki') || str_contains($text, 'tirdziņš') || str_contains($text, 'gadatirgus')) {
            return 'festival';
        }

        return 'event';
    }

    /**
     * Parse Latvian dates from "span.date-date" and "span.date-time"
     */
    public function parseLatvianDates(string $dateStr, string $timeStr = ''): array
    {
        $months = [
            'janvāris' => 1, 'janvārī' => 1, 'janvāra' => 1, 'janv' => 1,
            'februāris' => 2, 'februārī' => 2, 'februāra' => 2, 'febr' => 2,
            'marts' => 3, 'martā' => 3, 'marta' => 3, 'mar' => 3,
            'aprīlis' => 4, 'aprīlī' => 4, 'aprīļa' => 4, 'apr' => 4,
            'maijs' => 5, 'maijā' => 5, 'maija' => 5, 'mai' => 5,
            'jūnijs' => 6, 'jūnijā' => 6, 'jūnija' => 6, 'jūn' => 6,
            'jūlijs' => 7, 'jūlijā' => 7, 'jūlija' => 7, 'jūl' => 7,
            'augusts' => 8, 'augustā' => 8, 'augusta' => 8, 'aug' => 8,
            'septembris' => 9, 'septembrī' => 9, 'septembra' => 9, 'sept' => 9,
            'oktobris' => 10, 'oktobrī' => 10, 'oktobra' => 10, 'okt' => 10,
            'novembris' => 11, 'novembrī' => 11, 'novembra' => 11, 'nov' => 11,
            'decembris' => 12, 'decembrī' => 12, 'decembra' => 12, 'dec' => 12,
        ];

        $clean = trim(str_replace(['&#8211;', '&ndash;', '–', '—'], '-', $dateStr));
        $allDay = false;

        // Parse time string e.g. "18.00", "18:00", "18.00 - 20.00", "Visu dienu"
        $startTime = '10:00:00';
        $endTime = null;

        $timeClean = trim(str_replace(['&#8211;', '&ndash;', '–', '—'], '-', $timeStr));
        if (mb_stripos($timeClean, 'visu dienu') !== false) {
            $allDay = true;
            $startTime = '00:00:00';
            $endTime = '23:59:59';
        } elseif (preg_match('/(\d{1,2})[.:](\d{2})\s*-\s*(\d{1,2})[.:](\d{2})/u', $timeClean, $tm)) {
            $startTime = sprintf('%02d:%02d:00', (int)$tm[1], (int)$tm[2]);
            $endTime = sprintf('%02d:%02d:00', (int)$tm[3], (int)$tm[4]);
        } elseif (preg_match('/(\d{1,2})[.:](\d{2})/u', $timeClean, $tm)) {
            $startTime = sprintf('%02d:%02d:00', (int)$tm[1], (int)$tm[2]);
        }

        // Check for date range e.g. "16. - 20. novembris, 2026" or "16. novembris - 5. decembris, 2026"
        if (str_contains($clean, '-')) {
            $parts = explode('-', $clean, 2);
            $p1 = trim($parts[0]);
            $p2 = trim($parts[1]);

            // Case: "16. - 20. novembris, 2026"
            if (preg_match('/^(\d{1,2})\.?$/u', $p1, $m1) && preg_match('/^(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:,?\s*(\d{4}))?/ui', $p2, $m2)) {
                $day1 = (int) $m1[1];
                $day2 = (int) $m2[1];
                $monthName = mb_strtolower($m2[2], 'UTF-8');
                $month = $months[$monthName] ?? (int) date('n');
                $year = !empty($m2[3]) ? (int) $m2[3] : (int) date('Y');

                $startAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year, $month, $day1, $startTime), 'Europe/Riga');
                $endAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year, $month, $day2, $endTime ?: '22:00:00'), 'Europe/Riga');

                return [$startAt, $endAt, $allDay];
            }

            // Case: "16. novembris - 5. decembris, 2026"
            if (preg_match('/(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:,?\s*(\d{4}))?/ui', $p1, $m1) &&
                preg_match('/(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:,?\s*(\d{4}))?/ui', $p2, $m2)) {
                $day1 = (int) $m1[1];
                $mName1 = mb_strtolower($m1[2], 'UTF-8');
                $month1 = $months[$mName1] ?? (int) date('n');

                $day2 = (int) $m2[1];
                $mName2 = mb_strtolower($m2[2], 'UTF-8');
                $month2 = $months[$mName2] ?? (int) date('n');

                $year2 = !empty($m2[3]) ? (int) $m2[3] : (int) date('Y');
                $year1 = !empty($m1[3]) ? (int) $m1[3] : $year2;

                $startAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year1, $month1, $day1, $startTime), 'Europe/Riga');
                $endAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year2, $month2, $day2, $endTime ?: '22:00:00'), 'Europe/Riga');

                return [$startAt, $endAt, $allDay];
            }
        }

        // Single date e.g. "18. novembris, 2026" or "18. novembris"
        if (preg_match('/(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:,?\s*(\d{4}))?/ui', $clean, $m)) {
            $day = (int) $m[1];
            $monthName = mb_strtolower($m[2], 'UTF-8');
            $month = $months[$monthName] ?? (int) date('n');
            $year = !empty($m[3]) ? (int) $m[3] : (int) date('Y');

            $startAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year, $month, $day, $startTime), 'Europe/Riga');
            $endAt = $endTime ? Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year, $month, $day, $endTime), 'Europe/Riga') : null;

            return [$startAt, $endAt, $allDay];
        }

        // Standard numeric date: "18.11.2026"
        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $clean, $m)) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];

            $startAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year, $month, $day, $startTime), 'Europe/Riga');
            $endAt = $endTime ? Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year, $month, $day, $endTime), 'Europe/Riga') : null;

            return [$startAt, $endAt, $allDay];
        }

        return [null, null, false];
    }

    /**
     * Parse price and free status
     */
    public function parsePrice(string $content): array
    {
        $isFree = true;
        $priceMin = null;
        $priceMax = null;

        if (
            preg_match('/(?:ieeja|biļet[a-z]*|maksa)\s*[-–—:]?\s*(?:bez\s+maksas|brīva)/iu', $content) ||
            preg_match('/\b(?:bezmaksas|brīva\s+ieeja|ieeja\s+brīva)\b/iu', $content)
        ) {
            $isFree = true;
            $priceMin = 0.0;
            $priceMax = 0.0;
        } elseif (preg_match('/(?:ieeja|biļet[a-z]*|cena|maksa)\s*[-–—:]?\s*(\d+(?:[.,]\d{1,2})?)\s*(?:€|eur)/iu', $content, $pm)) {
            $val = (float) str_replace(',', '.', $pm[1]);
            $isFree = false;
            $priceMin = $val;
            $priceMax = $val;
        } elseif (preg_match('/(\d+(?:[.,]\d{1,2})?)\s*(?:€|eur)/iu', $content, $pm)) {
            $val = (float) str_replace(',', '.', $pm[1]);
            if ($val > 0 && $val <= 500) {
                $isFree = false;
                $priceMin = $val;
                $priceMax = $val;
            }
        }

        return [
            'is_free' => $isFree,
            'price_min' => $priceMin,
            'price_max' => $priceMax,
        ];
    }

    /**
     * Normalize relative or absolute event URLs
     */
    public function normalizeUrl(?string $relUrl): ?string
    {
        if (empty($relUrl)) {
            return null;
        }

        if (str_starts_with($relUrl, 'http://') || str_starts_with($relUrl, 'https://')) {
            $parsed = parse_url($relUrl);
            return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'www.bauskasnovads.lv') . ($parsed['path'] ?? '');
        }

        $path = strtok($relUrl, '?');
        return self::BASE_URL . (str_starts_with($path, '/') ? '' : '/') . $path;
    }

    /**
     * Check if item is an administrative / municipal meeting that shouldn't be in cultural events calendar
     */
    public function isExcludedEvent(array $categories, string $title): bool
    {
        $categoriesLower = array_map(fn ($c) => mb_strtolower($c, 'UTF-8'), $categories);
        foreach ($categoriesLower as $cat) {
            if (
                str_contains($cat, 'komitejas sēde') ||
                str_contains($cat, 'domes sēde') ||
                str_contains($cat, 'ārkārtas sēde') ||
                str_contains($cat, 'iedzīvotāju pieņemšana')
            ) {
                return true;
            }
        }

        $titleLower = mb_strtolower($title, 'UTF-8');
        if (
            str_contains($titleLower, 'komitejas sēde') ||
            str_contains($titleLower, 'domes sēde') ||
            str_contains($titleLower, 'ārkārtas sēde') ||
            str_contains($titleLower, 'pieņem iedzīvotājus') ||
            str_contains($titleLower, 'deputātu tikšanās')
        ) {
            return true;
        }

        return false;
    }
}
