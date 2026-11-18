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

class RigaLvScraper extends BaseScraper
{
    public const BASE_URL = 'https://www.riga.lv';
    public const CALENDAR_PATH = '/lv/notikumu-kalendars';

    /**
     * Map of known Riga cultural venues with normalized metadata
     */
    protected array $venueAddressMap = [
        'latvijas nacionālajā vēstures muzej' => [
            'name' => 'Latvijas Nacionālais vēstures muzejs',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Pils laukums 3, Rīga, LV-1050',
            'place_type' => 'museum',
            'lat' => 56.9510,
            'lon' => 24.1010,
        ],
        'rīgas pilī' => [
            'name' => 'Rīgas pils',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Pils laukums 3, Rīga, LV-1050',
            'place_type' => 'historical',
            'lat' => 56.9510,
            'lon' => 24.1010,
        ],
        'rīgas porcelāna muzej' => [
            'name' => 'Rīgas Porcelāna muzejs',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Kalēju iela 9/11, Rīga, LV-1050',
            'place_type' => 'museum',
            'lat' => 56.9475,
            'lon' => 24.1105,
        ],
        'čaka muzej' => [
            'name' => 'Aleksandra Čaka muzejs',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Lāčplēša iela 48, Rīga, LV-1011',
            'place_type' => 'museum',
            'lat' => 56.9535,
            'lon' => 24.1265,
        ],
        'jūgendstila centr' => [
            'name' => 'Muzejs “Rīgas Jūgendstila centrs”',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Alberta iela 12, Rīga, LV-1010',
            'place_type' => 'museum',
            'lat' => 56.9592,
            'lon' => 24.1082,
        ],
        'ziemeļblāzm' => [
            'name' => 'Kultūras pils “Ziemeļblāzma”',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Ziemeļblāzmas iela 36, Rīga, LV-1015',
            'place_type' => 'cultural_center',
            'lat' => 57.0392,
            'lon' => 24.0858,
        ],
        'vef kultūras pil' => [
            'name' => 'VEF Kultūras pils',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Ropažu iela 2, Rīga, LV-1039',
            'place_type' => 'cultural_centre',
            'lat' => 56.9745,
            'lon' => 24.16605,
        ],
        'iļģuciem' => [
            'name' => 'Kultūras centrs “Iļģuciems”',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Lidoņu iela 27 k-2, Rīga, LV-1055',
            'place_type' => 'cultural_center',
            'lat' => 56.9680,
            'lon' => 24.0545,
        ],
        'mazā ģild' => [
            'name' => 'KTMC “Mazā ģilde”',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Amatu iela 5, Rīga, LV-1050',
            'place_type' => 'cultural_center',
            'lat' => 56.9497,
            'lon' => 24.1077,
        ],
        'ritum' => [
            'name' => 'KTMC “Ritums”',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Jauniela 29.5, Rīga, LV-1050',
            'place_type' => 'cultural_center',
            'lat' => 56.9485,
            'lon' => 24.1055,
        ],
        'imant' => [
            'name' => 'Kultūras centrs “Imanta”',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Anniņmuižas bulvāris 29, Rīga, LV-1067',
            'place_type' => 'cultural_center',
            'lat' => 56.9602,
            'lon' => 24.0155,
        ],
        '11. novembra krastmal' => [
            'name' => '11. novembra krastmala',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => '11. novembra krastmala, Rīga',
            'place_type' => 'square',
            'lat' => 56.9465,
            'lon' => 24.1030,
        ],
        'brīvības laukum' => [
            'name' => 'Brīvības laukums',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Brīvības laukums, Rīga',
            'place_type' => 'square',
            'lat' => 56.9515,
            'lon' => 24.1133,
        ],
        'brīvības pieminekl' => [
            'name' => 'Brīvības pieminekļa laukums',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Brīvības laukums, Rīga',
            'place_type' => 'square',
            'lat' => 56.9515,
            'lon' => 24.1133,
        ],
        'vērmanes dārz' => [
            'name' => 'Vērmanes dārzs',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Tērbatas iela 2, Rīga, LV-1050',
            'place_type' => 'park',
            'lat' => 56.9512,
            'lon' => 24.1192,
        ],
        'splendid palace' => [
            'name' => 'Kinoteātris “Splendid Palace”',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Elizabetes iela 61, Rīga, LV-1050',
            'place_type' => 'cinema',
            'lat' => 56.9548,
            'lon' => 24.1195,
        ],
        'k.suns' => [
            'name' => 'Kinoteātris “K.Suns”',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Elizabetes iela 83/85, Rīga, LV-1050',
            'place_type' => 'cinema',
            'lat' => 56.9525,
            'lon' => 24.1235,
        ],
    ];

    public function getSlug(): string
    {
        return 'riga-lv';
    }

    public function getName(): string
    {
        return 'Rīgas valstspilsēta';
    }

    /**
     * Scrape events from riga.lv/lv/notikumu-kalendars
     * Strict rule: Extract directly from calendar cards to avoid heavy detail calls on LVRTC/VTP
     */
    public function scrape(Source $source): Collection
    {
        $events = collect();
        $baseUrl = $source->url ?: (self::BASE_URL . self::CALENDAR_PATH);
        $maxPages = 10;
        $today = Carbon::today('Europe/Riga');
        $seenExternalIds = [];

        Log::info('Starting Rīgas valstspilsēta scraping...', [
            'scraper' => static::class,
            'source' => $source->name,
            'url' => $baseUrl,
        ]);

        for ($page = 0; $page < $maxPages; $page++) {
            if ($page > 0) {
                // Polite delay on LVRTC IP to prevent WAF handshake throttling
                $this->politeDelay(1500, 400);
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
                $externalId = 'riga_' . $slugPart;

                // Dates & times
                $dateStr = $node->filter('span.date-date')->count() ? $this->cleanText($node->filter('span.date-date')->text()) : '';
                $timeStr = $node->filter('span.date-time')->count() ? $this->cleanText($node->filter('span.date-time')->text()) : '';
                [$startAt, $endAt, $allDay] = $this->parseLatvianDates($dateStr, $timeStr);

                if (!$startAt) {
                    return;
                }

                // Append date suffix to external ID if specific date param or start time is known
                if ($startAt) {
                    $externalId .= '_' . $startAt->format('YmdHi');
                }

                if (isset($seenExternalIds[$externalId])) {
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

                // Description: snippet from card
                $description = $node->filter('div.event-content')->count() ? $this->cleanText($node->filter('div.event-content')->text()) : '';
                if (empty($description)) {
                    $description = $title . ' Rīgā.';
                }

                // Location resolution
                $locationRaw = $node->filter('span.describing-field-value')->count() ? $this->cleanText($node->filter('span.describing-field-value')->text()) : '';
                $venueInfo = $this->resolveLocation($locationRaw, $title, $description);

                // Categories (strictly adhering to AGENTS.md)
                $canonicalCategories = $this->resolveCategories($categories, $title, $description, $venueInfo['name']);

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
                    region: $venueInfo['region'],
                    address: $venueInfo['address'],
                    latitude: $venueInfo['lat'] ?? null,
                    longitude: $venueInfo['lon'] ?? null,
                    placeType: $venueInfo['place_type'],
                    categoryNames: $canonicalCategories,
                    isFree: $priceData['is_free'],
                    priceMin: $priceData['price_min'],
                    priceMax: $priceData['price_max'],
                    currency: 'EUR',
                    ticketUrl: $cleanUrl ?: $baseUrl,
                    imageUrl: $thumbImg,
                    sourceUrl: $cleanUrl ?: $baseUrl,
                    sourceExternalId: $externalId,
                    rawData: [
                        'date_raw' => $dateStr,
                        'time_raw' => $timeStr,
                        'location_raw' => $locationRaw,
                        'categories_raw' => $categories,
                        'all_day' => $allDay,
                    ]
                );

                $events->push($dto);
            });

            // If an entire page contains only past events, further pages will only be older
            if ($allPageEventsPast) {
                break;
            }
        }

        Log::info("Rīgas valstspilsēta scraping completed: {$events->count()} events collected", [
            'scraper' => static::class,
        ]);

        return $events;
    }

    /**
     * Resolve venue, address and coordinates for Riga events
     */
    public function resolveLocation(string $locationRaw, string $title, string $description): array
    {
        $raw = trim($locationRaw);
        $combined = mb_strtolower($raw . ' ' . $title . ' ' . $description, 'UTF-8');

        // 1. Direct or stem match in known venues map
        foreach ($this->venueAddressMap as $venueKey => $info) {
            if (str_contains($combined, $venueKey)) {
                return $info;
            }
        }

        // 2. Default to general Riga city venue
        return [
            'name' => 'Rīga',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Rīga, Latvija',
            'place_type' => 'venue',
            'lat' => 56.9496,
            'lon' => 24.1052,
        ];
    }

    /**
     * Categorize event adhering strictly to AGENTS.md rules
     */
    public function resolveCategories(array $categories, string $title, string $description, ?string $venueName = null): array
    {
        $titleLower = mb_strtolower($title, 'UTF-8');
        $descLower = mb_strtolower($description, 'UTF-8');
        $venueLower = mb_strtolower($venueName ?? '', 'UTF-8');
        $combined = $titleLower . ' ' . $descLower . ' ' . $venueLower;

        // 1. Cinema / Kino (Strict & Priority per AGENTS.md)
        $isCinema = (
            str_contains($venueLower, 'splendid palace') ||
            str_contains($venueLower, 'k.suns') || str_contains($venueLower, 'k suns') ||
            preg_match('/\b(?:kinoseanss|filmas seanss|spēlfilma|dokumentālā filma|animācijas filma|kinofestivāls|kino vakars)\b/u', $combined) ||
            preg_match('/\b(?:filma|filmas|filmu|filmā)\b/u', $titleLower) ||
            preg_match('/^kino\b/u', $titleLower)
        );

        if ($isCinema) {
            return ['kino'];
        }

        $mapped = [];

        // Map categories from website
        foreach ($categories as $cat) {
            $catLower = mb_strtolower($cat, 'UTF-8');
            match (true) {
                str_contains($catLower, 'izstād') => $mapped[] = 'izstades',
                str_contains($catLower, 'svētk') => $mapped[] = 'svetki',
                str_contains($catLower, 'koncert') || str_contains($catLower, 'mūzik') => $mapped[] = 'muzika',
                str_contains($catLower, 'teātr') || str_contains($catLower, 'izrād') => $mapped[] = 'teatris',
                str_contains($catLower, 'bērn') || str_contains($catLower, 'ģimen') => $mapped[] = 'berniem',
                str_contains($catLower, 'sport') => $mapped[] = 'sports',
                str_contains($catLower, 'seminār') || str_contains($catLower, 'lekcij') || str_contains($catLower, 'darbnīc') => $mapped[] = 'seminari',
                default => null,
            };
        }

        // Theatre verification per AGENTS.md:
        // Only actual theater productions (*izrāde*, *luga*, *iestudējums*, *opera*, *balets*)
        if (in_array('teatris', $mapped, true)) {
            $isActualTheater = preg_match('/\b(?:izrāde|izrādes|luga|iestudējums|opera|operete|balets|stāvizrāde|stand-up)\b/u', $combined);
            if (!$isActualTheater) {
                $mapped = array_values(array_diff($mapped, ['teatris']));
            }
        }

        // Infer from title / content if no specific category mapped
        if (empty($mapped)) {
            if (preg_match('/\b(?:izstāde|gleznas|grafika|porcelān|keramik|ekspozīcija)\b/u', $combined)) {
                $mapped[] = 'izstades';
            }
            if (preg_match('/\b(?:koncerts|koncertuzvedums|kamermūzika|koris|orķestris|dziesmas|dzied)\b/u', $combined)) {
                $mapped[] = 'muzika';
            }
            if (preg_match('/\b(?:svētki|svinības|svinīgais|salūts|gaismas festivāls|staro rīga)\b/u', $combined)) {
                $mapped[] = 'svetki';
            }
            if (preg_match('/\b(?:izrāde|luga|iestudējums|opera|balets)\b/u', $titleLower)) {
                $mapped[] = 'teatris';
            }
            if (preg_match('/\b(?:bērniem|bērnu|leļļu)\b/u', $titleLower)) {
                $mapped[] = 'berniem';
            }
            if (preg_match('/\b(?:skrējiens|maratons|čempionāts|kauss|turnīrs)\b/u', $combined)) {
                $mapped[] = 'sports';
            }
        }

        $mapped = array_values(array_unique($mapped));

        return !empty($mapped) ? $mapped : ['citi'];
    }

    /**
     * Check if item is an administrative / municipal meeting that shouldn't be in cultural events calendar
     */
    public function isExcludedEvent(array $categories, string $title): bool
    {
        $titleLower = mb_strtolower($title, 'UTF-8');

        $excludePatterns = [
            'domes sēde',
            'domes svinīgā sēde',
            'komitejas sēde',
            'komisijas sēde',
            'ārkārtas sēde',
            'kārtējā sēde',
            'svinīgā sēde',
            'iepirkumu komisij',
            'finanšu un administrācijas lietu komitej',
            'satiksmes un transporta lietu komitej',
            'pilsētas attīstības komitej',
            'sociālo jautājumu komitej',
            'mājokļu un vides komitej',
            'izglītības, kultūras un sporta komitej',
            'nekustamā īpašuma izsol',
            'iedzīvotāju pieņemšan',
        ];

        foreach ($excludePatterns as $pattern) {
            if (str_contains($titleLower, $pattern)) {
                return true;
            }
        }

        if (preg_match('/\bdomes\s+[^\n]*sēde\b/u', $titleLower)) {
            return true;
        }

        foreach ($categories as $cat) {
            $catLower = mb_strtolower($cat, 'UTF-8');
            if (str_contains($catLower, 'sēde') || str_contains($catLower, 'komitej') || str_contains($catLower, 'izsol')) {
                return true;
            }
        }

        return false;
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
            preg_match('/\b(?:bezmaksas|brīva\s+ieeja|ieeja\s+brīva|bez\s+maksas)\b/iu', $content)
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
     * Parse Latvian date and time strings from Drupal view-events format
     *
     * @return array{0: ?Carbon, 1: ?Carbon, 2: bool} [startAt, endAt, allDay]
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

        // Clean redundant "Datums" prefix if present
        $clean = preg_replace('/^Datums\s*/iu', '', trim($dateStr));
        $clean = trim(str_replace(['&#8211;', '&ndash;', '–', '—'], '-', $clean));

        // Time clean
        $timeClean = preg_replace('/^Laiks\s*/iu', '', trim($timeStr));
        $timeClean = trim(str_replace(['&#8211;', '&ndash;', '–', '—'], '-', $timeClean));

        $allDay = false;
        $startTime = '10:00:00';
        $endTime = null;

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

        // Multi-day date range: "15. - 18. novembris, 2026" or "3. novembris, 2026 - 6. decembris, 2026"
        if (str_contains($clean, '-')) {
            $parts = explode('-', $clean, 2);
            $p1 = trim($parts[0]);
            $p2 = trim($parts[1]);

            // Case: "15. - 18. novembris, 2026"
            if (preg_match('/^(\d{1,2})\.?$/u', $p1, $m1) && preg_match('/^(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:,?\s*(\d{4}))?/ui', $p2, $m2)) {
                $day1 = (int) $m1[1];
                $day2 = (int) $m2[1];
                $monthName = mb_strtolower($m2[2], 'UTF-8');
                $year = !empty($m2[3]) ? (int) $m2[3] : Carbon::today('Europe/Riga')->year;

                if (isset($months[$monthName])) {
                    $monthNum = $months[$monthName];
                    $startAt = Carbon::create($year, $monthNum, $day1, 0, 0, 0, 'Europe/Riga');
                    $endAt = Carbon::create($year, $monthNum, $day2, 23, 59, 59, 'Europe/Riga');

                    if ($startTime !== '00:00:00') {
                        [$h, $m] = explode(':', $startTime);
                        $startAt->setTime((int)$h, (int)$m, 0);
                    }
                    if ($endTime && $endTime !== '23:59:59') {
                        [$h, $m] = explode(':', $endTime);
                        $endAt->setTime((int)$h, (int)$m, 0);
                    }

                    return [$startAt, $endAt, $allDay];
                }
            }

            // Case: "3. novembris, 2026 - 6. decembris, 2026" or "3. novembris - 6. decembris, 2026"
            if (
                preg_match('/^(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:,?\s*(\d{4}))?/ui', $p1, $m1) &&
                preg_match('/^(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:,?\s*(\d{4}))?/ui', $p2, $m2)
            ) {
                $day1 = (int) $m1[1];
                $day2 = (int) $m2[1];
                $month1 = mb_strtolower($m1[2], 'UTF-8');
                $month2 = mb_strtolower($m2[2], 'UTF-8');
                $year2 = !empty($m2[3]) ? (int) $m2[3] : Carbon::today('Europe/Riga')->year;
                $year1 = !empty($m1[3]) ? (int) $m1[3] : $year2;

                if (isset($months[$month1], $months[$month2])) {
                    $startAt = Carbon::create($year1, $months[$month1], $day1, 0, 0, 0, 'Europe/Riga');
                    $endAt = Carbon::create($year2, $months[$month2], $day2, 23, 59, 59, 'Europe/Riga');

                    if ($startTime !== '00:00:00') {
                        [$h, $m] = explode(':', $startTime);
                        $startAt->setTime((int)$h, (int)$m, 0);
                    }
                    if ($endTime && $endTime !== '23:59:59') {
                        [$h, $m] = explode(':', $endTime);
                        $endAt->setTime((int)$h, (int)$m, 0);
                    }

                    return [$startAt, $endAt, $allDay];
                }
            }
        }

        // Single date: "18. novembris, 2026"
        if (preg_match('/(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:,?\s*(\d{4}))?/ui', $clean, $m)) {
            $day = (int) $m[1];
            $monthName = mb_strtolower($m[2], 'UTF-8');
            $year = !empty($m[3]) ? (int) $m[3] : Carbon::today('Europe/Riga')->year;

            if (isset($months[$monthName])) {
                $monthNum = $months[$monthName];
                $startAt = Carbon::create($year, $monthNum, $day, 0, 0, 0, 'Europe/Riga');
                $endAt = null;

                if ($startTime) {
                    [$h, $m] = explode(':', $startTime);
                    $startAt->setTime((int)$h, (int)$m, 0);
                }

                if ($endTime) {
                    $endAt = Carbon::create($year, $monthNum, $day, 0, 0, 0, 'Europe/Riga');
                    [$h, $m] = explode(':', $endTime);
                    $endAt->setTime((int)$h, (int)$m, 0);
                }

                return [$startAt, $endAt, $allDay];
            }
        }

        return [null, null, false];
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
            return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'www.riga.lv') . ($parsed['path'] ?? '');
        }

        $path = strtok($relUrl, '?');
        return self::BASE_URL . (str_starts_with($path, '/') ? '' : '/') . $path;
    }
}
