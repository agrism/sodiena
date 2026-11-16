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

class DknScraper extends BaseScraper
{
    public const BASE_URL = 'https://www.dkn.lv';
    public const CALENDAR_PATH = '/lv/notikumu-kalendars';

    /**
     * Map of known Dienvidkurzemes novads venues with normalized metadata
     */
    protected array $venueAddressMap = [
        // Aizpute
        'aizputes kultūras nams' => [
            'name' => 'Aizputes kultūras nams',
            'city' => 'Aizpute',
            'region' => 'Kurzeme',
            'address' => 'Atmodas iela 16, Aizpute, Dienvidkurzemes novads, LV-3456',
            'place_type' => 'cultural_center',
        ],
        'aizputes novadpētniecības muzejs' => [
            'name' => 'Aizputes novadpētniecības muzejs',
            'city' => 'Aizpute',
            'region' => 'Kurzeme',
            'address' => 'Skolas iela 1, Aizpute, Dienvidkurzemes novads, LV-3456',
            'place_type' => 'museum',
        ],
        'aizputes tūrisma informācijas centrs' => [
            'name' => 'Aizputes Tūrisma informācijas centrs',
            'city' => 'Aizpute',
            'region' => 'Kurzeme',
            'address' => 'Atmodas iela 16, Aizpute, Dienvidkurzemes novads, LV-3456',
            'place_type' => 'visitor_center',
        ],
        'aizputes pilsētas bibliotēka' => [
            'name' => 'Aizputes pilsētas bibliotēka',
            'city' => 'Aizpute',
            'region' => 'Kurzeme',
            'address' => 'Atmodas iela 24, Aizpute, Dienvidkurzemes novads, LV-3456',
            'place_type' => 'library',
        ],
        'aizputes sporta centrs' => [
            'name' => 'Aizputes sporta centrs "Lejaskurzeme"',
            'city' => 'Aizpute',
            'region' => 'Kurzeme',
            'address' => 'Ziedu iela 7, Aizpute, Dienvidkurzemes novads, LV-3456',
            'place_type' => 'sports_facility',
        ],
        'mārtiņa freimaņa piemiņas istaba' => [
            'name' => 'Mārtiņa Freimaņa piemiņas istaba',
            'city' => 'Aizpute',
            'region' => 'Kurzeme',
            'address' => 'Skolas iela 1, Aizpute, Dienvidkurzemes novads, LV-3456',
            'place_type' => 'museum',
        ],

        // Grobiņa
        'grobiņas kultūras nams' => [
            'name' => 'Grobiņas kultūras nams "Robežnieki"',
            'city' => 'Grobiņa',
            'region' => 'Kurzeme',
            'address' => 'Lielā iela 54, Grobiņa, Dienvidkurzemes novads, LV-3430',
            'place_type' => 'cultural_center',
        ],
        'grobiņas pilsdrupas' => [
            'name' => 'Grobiņas viduslaiku pilsdrupas',
            'city' => 'Grobiņa',
            'region' => 'Kurzeme',
            'address' => 'Lielā iela 54, Grobiņa, Dienvidkurzemes novads, LV-3430',
            'place_type' => 'historical',
        ],
        'grobiņas sporta centrs' => [
            'name' => 'Grobiņas Sporta centrs',
            'city' => 'Grobiņa',
            'region' => 'Kurzeme',
            'address' => 'M. Namiķa iela 2b, Grobiņa, Dienvidkurzemes novads, LV-3430',
            'place_type' => 'sports_facility',
        ],
        'grobiņas bibliotēka' => [
            'name' => 'Grobiņas pilsētas bibliotēka',
            'city' => 'Grobiņa',
            'region' => 'Kurzeme',
            'address' => 'Lielā iela 76, Grobiņa, Dienvidkurzemes novads, LV-3430',
            'place_type' => 'library',
        ],

        // Pāvilosta
        'pāvilostas pilsētas kultūras nams' => [
            'name' => 'Pāvilostas pilsētas kultūras nams',
            'city' => 'Pāvilosta',
            'region' => 'Kurzeme',
            'address' => 'Dzintaru iela 47, Pāvilosta, Dienvidkurzemes novads, LV-3466',
            'place_type' => 'cultural_center',
        ],
        'pāvilostas kultūras nams' => [
            'name' => 'Pāvilostas pilsētas kultūras nams',
            'city' => 'Pāvilosta',
            'region' => 'Kurzeme',
            'address' => 'Dzintaru iela 47, Pāvilosta, Dienvidkurzemes novads, LV-3466',
            'place_type' => 'cultural_center',
        ],
        'pāvilostas novadpētniecības muzejs' => [
            'name' => 'Pāvilostas novadpētniecības muzejs',
            'city' => 'Pāvilosta',
            'region' => 'Kurzeme',
            'address' => 'Dzintaru iela 1, Pāvilosta, Dienvidkurzemes novads, LV-3466',
            'place_type' => 'museum',
        ],
        'upesmuižas parka estrāde' => [
            'name' => 'Upesmuižas parka estrāde',
            'city' => 'Pāvilosta',
            'region' => 'Kurzeme',
            'address' => 'Upesmuižas parks, Pāvilosta, Dienvidkurzemes novads, LV-3466',
            'place_type' => 'park',
        ],

        // Priekule
        'priekules kultūras nams' => [
            'name' => 'Priekules kultūras nams',
            'city' => 'Priekule',
            'region' => 'Kurzeme',
            'address' => 'Peldu iela 1, Priekule, Dienvidkurzemes novads, LV-3434',
            'place_type' => 'cultural_center',
        ],
        'brīnumlāde' => [
            'name' => 'Svētku telpa "Brīnumlāde"',
            'city' => 'Priekule',
            'region' => 'Kurzeme',
            'address' => 'Raiņa iela 3-5, Priekule, Dienvidkurzemes novads, LV-3434',
            'place_type' => 'cultural_center',
        ],

        // Durbe
        'durbes kultūras nams' => [
            'name' => 'Durbes kultūras nams',
            'city' => 'Durbe',
            'region' => 'Kurzeme',
            'address' => 'Skolas iela 5, Durbe, Dienvidkurzemes novads, LV-3440',
            'place_type' => 'cultural_center',
        ],

        // Vaiņode
        'vaiņodes kultūras nams' => [
            'name' => 'Vaiņodes kultūras nams',
            'city' => 'Vaiņode',
            'region' => 'Kurzeme',
            'address' => 'Tirgoņu iela 23, Vaiņode, Dienvidkurzemes novads, LV-3435',
            'place_type' => 'cultural_center',
        ],

        // Kazdanga
        'kazdangas kultūras nams' => [
            'name' => 'Kazdangas kultūras nams',
            'city' => 'Kazdanga',
            'region' => 'Kurzeme',
            'address' => 'Pils gatve 4, Kazdanga, Dienvidkurzemes novads, LV-3457',
            'place_type' => 'cultural_center',
        ],
        'kazdangas pils' => [
            'name' => 'Kazdangas pils',
            'city' => 'Kazdanga',
            'region' => 'Kurzeme',
            'address' => 'Jaunatnes gatve 1, Kazdanga, Dienvidkurzemes novads, LV-3457',
            'place_type' => 'historical',
        ],

        // Cīrava
        'cīravas kultūras nams' => [
            'name' => 'Cīravas kultūras nams',
            'city' => 'Cīrava',
            'region' => 'Kurzeme',
            'address' => 'Gaismas iela 1, Cīrava, Dienvidkurzemes novads, LV-3453',
            'place_type' => 'cultural_center',
        ],

        // Nīca
        'nīcas kultūras nams' => [
            'name' => 'Nīcas kultūras nams',
            'city' => 'Nīca',
            'region' => 'Kurzeme',
            'address' => 'Skolas iela 4, Nīca, Dienvidkurzemes novads, LV-3473',
            'place_type' => 'cultural_center',
        ],

        // Rucava
        'rucavas kultūras nams' => [
            'name' => 'Rucavas kultūras nams',
            'city' => 'Rucava',
            'region' => 'Kurzeme',
            'address' => 'Pagastmāja, Rucava, Dienvidkurzemes novads, LV-3477',
            'place_type' => 'cultural_center',
        ],

        // Vērgale
        'vērgales kultūras nams' => [
            'name' => 'Vērgales kultūras nams',
            'city' => 'Vērgale',
            'region' => 'Kurzeme',
            'address' => 'Vērgale, Vērgales pagasts, Dienvidkurzemes novads, LV-3463',
            'place_type' => 'cultural_center',
        ],

        // Dunalka
        'dunalkas kultūras nams' => [
            'name' => 'Dunalkas kultūras nams',
            'city' => 'Dunalka',
            'region' => 'Kurzeme',
            'address' => 'Dunalka, Dunalkas pagasts, Dienvidkurzemes novads, LV-3454',
            'place_type' => 'cultural_center',
        ],

        // Gavieze
        'gaviezes kultūras nams' => [
            'name' => 'Gaviezes kultūras nams',
            'city' => 'Gavieze',
            'region' => 'Kurzeme',
            'address' => 'Gavieze, Gaviezes pagasts, Dienvidkurzemes novads, LV-3432',
            'place_type' => 'cultural_center',
        ],

        // Bārta
        'bārtas kultūras nams' => [
            'name' => 'Bārtas kultūras nams',
            'city' => 'Bārta',
            'region' => 'Kurzeme',
            'address' => 'Bārta, Bārtas pagasts, Dienvidkurzemes novads, LV-3482',
            'place_type' => 'cultural_center',
        ],

        // Gramzda
        'gramzdas tautas nams' => [
            'name' => 'Gramzdas tautas nams',
            'city' => 'Gramzda',
            'region' => 'Kurzeme',
            'address' => 'Gramzda, Gramzdas pagasts, Dienvidkurzemes novads, LV-3484',
            'place_type' => 'cultural_center',
        ],

        // Kalēti
        'kalētu tautas nams' => [
            'name' => 'Kalētu tautas nams',
            'city' => 'Kalēti',
            'region' => 'Kurzeme',
            'address' => 'Kalēti, Kalētu pagasts, Dienvidkurzemes novads, LV-3486',
            'place_type' => 'cultural_center',
        ],

        // Kalvene
        'kalvenes kultūras nams' => [
            'name' => 'Kalvenes kultūras nams',
            'city' => 'Kalvene',
            'region' => 'Kurzeme',
            'address' => 'Kalvene, Kalvenes pagasts, Dienvidkurzemes novads, LV-3458',
            'place_type' => 'cultural_center',
        ],

        // Medze
        'medzes kultūras nams' => [
            'name' => 'Medzes kultūras nams',
            'city' => 'Medze',
            'region' => 'Kurzeme',
            'address' => 'Kapsēde, Medzes pagasts, Dienvidkurzemes novads, LV-3461',
            'place_type' => 'cultural_center',
        ],

        // Otaņķi
        'otaņķu tautas nams' => [
            'name' => 'Otaņķu tautas nams',
            'city' => 'Otaņķi',
            'region' => 'Kurzeme',
            'address' => 'Rude, Otaņķu pagasts, Dienvidkurzemes novads, LV-3475',
            'place_type' => 'cultural_center',
        ],

        // Virga
        'virgas saieta nams' => [
            'name' => 'Virgas saieta nams',
            'city' => 'Virga',
            'region' => 'Kurzeme',
            'address' => 'Paplaka, Virgas pagasts, Dienvidkurzemes novads, LV-3436',
            'place_type' => 'cultural_center',
        ],

        // Embūte
        'embūtes saieta nams' => [
            'name' => 'Embūtes saieta nams',
            'city' => 'Embūte',
            'region' => 'Kurzeme',
            'address' => 'Vībiņi, Embūtes pagasts, Dienvidkurzemes novads, LV-3437',
            'place_type' => 'cultural_center',
        ],

        // Vecpils
        'vecpils kultūras nams' => [
            'name' => 'Vecpils kultūras nams',
            'city' => 'Vecpils',
            'region' => 'Kurzeme',
            'address' => 'Vecpils, Vecpils pagasts, Dienvidkurzemes novads, LV-3452',
            'place_type' => 'cultural_center',
        ],

        // Bunka
        'bunkas kultūras nams' => [
            'name' => 'Bunkas kultūras nams',
            'city' => 'Bunka',
            'region' => 'Kurzeme',
            'address' => 'Bunka, Bunkas pagasts, Dienvidkurzemes novads, LV-3444',
            'place_type' => 'cultural_center',
        ],
    ];

    public function getSlug(): string
    {
        return 'dkn';
    }

    public function getName(): string
    {
        return 'Dienvidkurzemes novada pašvaldība';
    }

    /**
     * Scrape events from dkn.lv/lv/notikumu-kalendars
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
            if ($page > 0) {
                $this->politeDelay(1000, 300);
            }
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
                $externalId = 'dkn-' . $slugPart;

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
                    region: 'Kurzeme',
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
     * Resolve Location metadata for Dienvidkurzemes novads
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
            // Check stem (e.g. "priekules kultūras nam" matches "priekules kultūras namā")
            $stem = preg_replace('/(s|ā|u|am|i|a|e|ē)$/u', '', $venueKey);
            if (mb_strlen($stem) >= 6 && str_contains($combined, $stem)) {
                return $info;
            }
        }

        // 2. Identify town / parish from location or combined context
        $townStems = [
            'saraiķ' => 'Saraiķi',
            'ziemup' => 'Ziemupe',
            'kapsēd' => 'Kapsēde',
            'krot' => 'Krote',
            'apriķ' => 'Apriķi',
            'rud' => 'Rude',
            'bernāt' => 'Bernāti',
            'vībiņ' => 'Vībiņi',
            'pāvilost' => 'Pāvilosta',
            'aizput' => 'Aizpute',
            'priekul' => 'Priekule',
            'durb' => 'Durbe',
            'vaiņod' => 'Vaiņode',
            'nīc' => 'Nīca',
            'rucav' => 'Rucava',
            'kazdang' => 'Kazdanga',
            'cīrav' => 'Cīrava',
            'vērgal' => 'Vērgale',
            'dunalk' => 'Dunalka',
            'gaviez' => 'Gavieze',
            'vecpils' => 'Vecpils',
            'bunk' => 'Bunka',
            'bārt' => 'Bārta',
            'gramzd' => 'Gramzda',
            'kalēt' => 'Kalēti',
            'kalven' => 'Kalvene',
            'medz' => 'Medze',
            'otaņķ' => 'Otaņķi',
            'virg' => 'Virga',
            'embūt' => 'Embūte',
            'grobiņ' => 'Grobiņa',
            'sak' => 'Saka',
            'tadaik' => 'Tadaiķi',
            'asīt' => 'Asīte',
            'dunik' => 'Dunika',
            'laž' => 'Laža',
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

        $city = $matchedTown ?: 'Grobiņa';

        // 3. Extract venue name and address
        $venueName = $city;
        $address = "{$city}, Dienvidkurzemes novads";

        if (!empty($raw)) {
            $parts = array_map('trim', explode(',', $raw));

            // Clean trailing country/municipality redundant tokens
            $cleanParts = array_filter($parts, function ($part) {
                $p = mb_strtolower($part, 'UTF-8');
                return $p !== 'latvija' && $p !== 'latvia' && $p !== 'dienvidkurzemes novads' && $p !== 'dienvidkurzeme';
            });

            if (!empty($cleanParts)) {
                $venueName = reset($cleanParts);
                $address = implode(', ', $cleanParts) . ', Dienvidkurzemes novads';
            }
        }

        // 4. Infer place type
        $placeType = 'venue';
        $lowerVenue = mb_strtolower($venueName, 'UTF-8');
        if (str_contains($lowerVenue, 'kultūras nams') || str_contains($lowerVenue, 'tautas nams') || str_contains($lowerVenue, 'saieta nams')) {
            $placeType = 'cultural_center';
        } elseif (str_contains($lowerVenue, 'muzejs') || str_contains($lowerVenue, 'senlietu krātuve') || str_contains($lowerVenue, 'piemiņas istaba')) {
            $placeType = 'museum';
        } elseif (str_contains($lowerVenue, 'muiža') || str_contains($lowerVenue, 'pils') || str_contains($lowerVenue, 'drupas')) {
            $placeType = 'historical';
        } elseif (str_contains($lowerVenue, 'bibliotēka')) {
            $placeType = 'library';
        } elseif (str_contains($lowerVenue, 'sporta') || str_contains($lowerVenue, 'stadions')) {
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
            'region' => 'Kurzeme',
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
        $sportsKeywords = ['sports', 'skrējiens', 'maratons', 'basketbols', 'futbols', 'hokejs', 'slidošana', 'turnīrs', 'nūjošana'];
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
        if (str_contains($text, 'festivāls') || str_contains($text, 'svētki') || str_contains($text, 'tirdziņš')) {
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
            return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'www.dkn.lv') . ($parsed['path'] ?? '');
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
