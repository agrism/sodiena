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

class MadonaScraper extends BaseScraper
{
    public const BASE_URL = 'https://madona.lv';
    public const PASAKUMI_PATH = '/lat/pasakumi';
    public const KALENDARS_PATH = '/lat/pasakumu-kalendars';

    /**
     * Geographic coordinates for Madonas novads towns and parishes
     */
    protected array $townCoordinates = [
        'madona' => ['lat' => 56.8533, 'lng' => 26.2167],
        'cesvaine' => ['lat' => 56.9697, 'lng' => 26.3094],
        'ērgļi' => ['lat' => 56.8972, 'lng' => 25.6369],
        'lubāna' => ['lat' => 56.9039, 'lng' => 26.7161],
        'varakļāni' => ['lat' => 56.6083, 'lng' => 26.7533],
        'arona' => ['lat' => 56.8833, 'lng' => 26.0500],
        'barkava' => ['lat' => 56.7456, 'lng' => 26.5956],
        'bērzaune' => ['lat' => 56.7725, 'lng' => 26.1367],
        'dzelzava' => ['lat' => 57.0000, 'lng' => 26.4333],
        'kalsnava' => ['lat' => 56.7167, 'lng' => 25.9333],
        'lazdona' => ['lat' => 56.8333, 'lng' => 26.2500],
        'liezēre' => ['lat' => 57.0167, 'lng' => 26.0333],
        'ļaudona' => ['lat' => 56.7167, 'lng' => 26.1833],
        'mārciena' => ['lat' => 56.7667, 'lng' => 26.1000],
        'mētriena' => ['lat' => 56.6667, 'lng' => 26.4500],
        'ošupe' => ['lat' => 56.7667, 'lng' => 26.7333],
        'prauliena' => ['lat' => 56.8333, 'lng' => 26.3333],
        'sarkaņi' => ['lat' => 56.9333, 'lng' => 26.3167],
        'sausnēja' => ['lat' => 56.8333, 'lng' => 25.6833],
        'vestiena' => ['lat' => 56.8667, 'lng' => 25.8667],
        'jumurda' => ['lat' => 56.9333, 'lng' => 25.7333],
        'indrāni' => ['lat' => 56.8833, 'lng' => 26.7000],
        'murmastiene' => ['lat' => 56.6500, 'lng' => 26.6833],
    ];

    /**
     * Map of known venues in Madonas novads with normalized metadata
     */
    protected array $venueAddressMap = [
        'kinoteātr' => [
            'name' => 'Kinoteātris „Vidzeme”',
            'city' => 'Madona',
            'region' => 'Vidzeme',
            'address' => 'Tirgus iela 5, Madona, Madonas novads, LV-4801',
            'place_type' => 'cinema',
            'lat' => 56.8530,
            'lng' => 26.2190,
        ],
        'madonas novada kultūras centr' => [
            'name' => 'Madonas novada kultūras centrs',
            'city' => 'Madona',
            'region' => 'Vidzeme',
            'address' => 'Raiņa iela 12, Madona, Madonas novads, LV-4801',
            'place_type' => 'cultural_center',
            'lat' => 56.8525,
            'lng' => 26.2160,
        ],
        'madonas kultūras nam' => [
            'name' => 'Madonas novada kultūras centrs',
            'city' => 'Madona',
            'region' => 'Vidzeme',
            'address' => 'Raiņa iela 12, Madona, Madonas novads, LV-4801',
            'place_type' => 'cultural_center',
            'lat' => 56.8525,
            'lng' => 26.2160,
        ],
        'madonas novadpētniecības un mākslas muzej' => [
            'name' => 'Madonas novadpētniecības un mākslas muzejs',
            'city' => 'Madona',
            'region' => 'Vidzeme',
            'address' => 'Skolas iela 10a, Madona, Madonas novads, LV-4801',
            'place_type' => 'museum',
            'lat' => 56.8512,
            'lng' => 26.2155,
        ],
        'cesvaines pil' => [
            'name' => 'Cesvaines pils',
            'city' => 'Cesvaine',
            'region' => 'Vidzeme',
            'address' => 'Pils iela 1, Cesvaine, Madonas novads, LV-4871',
            'place_type' => 'museum',
            'lat' => 56.9697,
            'lng' => 26.3094,
        ],
        'cesvaines kultūras nam' => [
            'name' => 'Cesvaines kultūras nams',
            'city' => 'Cesvaine',
            'region' => 'Vidzeme',
            'address' => 'Pils iela 2, Cesvaine, Madonas novads, LV-4871',
            'place_type' => 'cultural_center',
            'lat' => 56.9690,
            'lng' => 26.3085,
        ],
        'cesvaines multifunkcionāl' => [
            'name' => 'Cesvaines Multifunkcionālais centrs',
            'city' => 'Cesvaine',
            'region' => 'Vidzeme',
            'address' => 'Pils iela 2, Cesvaine, Madonas novads, LV-4871',
            'place_type' => 'community_center',
            'lat' => 56.9690,
            'lng' => 26.3080,
        ],
        'ērgļu saieta nam' => [
            'name' => 'Ērgļu saieta nams',
            'city' => 'Ērgļi',
            'region' => 'Vidzeme',
            'address' => 'Rīgas iela 5, Ērgļi, Madonas novads, LV-4840',
            'place_type' => 'cultural_center',
            'lat' => 56.8972,
            'lng' => 25.6369,
        ],
        'lubānas kultūras nam' => [
            'name' => 'Lubānas kultūras nams',
            'city' => 'Lubāna',
            'region' => 'Vidzeme',
            'address' => 'Tilta iela 11, Lubāna, Madonas novads, LV-4830',
            'place_type' => 'cultural_center',
            'lat' => 56.9039,
            'lng' => 26.7161,
        ],
        'varakļānu kultūras centr' => [
            'name' => 'Varakļānu kultūras centrs',
            'city' => 'Varakļāni',
            'region' => 'Vidzeme',
            'address' => 'Kultūras laukums 2, Varakļāni, Madonas novads, LV-4838',
            'place_type' => 'cultural_center',
            'lat' => 56.6083,
            'lng' => 26.7533,
        ],
        'varakļānu kultūras nam' => [
            'name' => 'Varakļānu kultūras centrs',
            'city' => 'Varakļāni',
            'region' => 'Vidzeme',
            'address' => 'Kultūras laukums 2, Varakļāni, Madonas novads, LV-4838',
            'place_type' => 'cultural_center',
            'lat' => 56.6083,
            'lng' => 26.7533,
        ],
        'varakļānu muiž' => [
            'name' => 'Varakļānu muižas pils',
            'city' => 'Varakļāni',
            'region' => 'Vidzeme',
            'address' => 'Pils iela 29, Varakļāni, Madonas novads, LV-4838',
            'place_type' => 'museum',
            'lat' => 56.6110,
            'lng' => 26.7550,
        ],
        'vestienas tautas nam' => [
            'name' => 'Vestienas tautas nams',
            'city' => 'Vestiena',
            'region' => 'Vidzeme',
            'address' => 'Vestiena, Vestienas pagasts, Madonas novads, LV-4855',
            'place_type' => 'cultural_center',
            'lat' => 56.8667,
            'lng' => 25.8667,
        ],
        'kalnagrav' => [
            'name' => 'Tautas nams „Kalnagravas”',
            'city' => 'Sarkaņi',
            'region' => 'Vidzeme',
            'address' => 'Kalnagravas, Sarkaņu pagasts, Madonas novads, LV-4870',
            'place_type' => 'cultural_center',
            'lat' => 56.9333,
            'lng' => 26.3167,
        ],
        'biksēres muiž' => [
            'name' => 'Biksēres muiža',
            'city' => 'Sarkaņi',
            'region' => 'Vidzeme',
            'address' => 'Biksēre, Sarkaņu pagasts, Madonas novads, LV-4870',
            'place_type' => 'cultural_center',
            'lat' => 56.9333,
            'lng' => 26.3167,
        ],
        'liezēres kultūras nam' => [
            'name' => 'Liezēres kultūras nams',
            'city' => 'Liezēre',
            'region' => 'Vidzeme',
            'address' => 'Liezēre, Liezēres pagasts, Madonas novads, LV-4884',
            'place_type' => 'cultural_center',
            'lat' => 57.0167,
            'lng' => 26.0333,
        ],
        'dzelzavas kultūras nam' => [
            'name' => 'Dzelzavas kultūras nams',
            'city' => 'Dzelzava',
            'region' => 'Vidzeme',
            'address' => 'Kļavu iela 4, Dzelzava, Dzelzavas pagasts, Madonas novads, LV-4873',
            'place_type' => 'cultural_center',
            'lat' => 57.0000,
            'lng' => 26.4333,
        ],
        'barkavas kultūras nam' => [
            'name' => 'Barkavas kultūras nams',
            'city' => 'Barkava',
            'region' => 'Vidzeme',
            'address' => 'Brīvības iela 11, Barkava, Barkavas pagasts, Madonas novads, LV-4867',
            'place_type' => 'cultural_center',
            'lat' => 56.7456,
            'lng' => 26.5956,
        ],
        'mārcienas kultūras nam' => [
            'name' => 'Mārcienas kultūras nams',
            'city' => 'Mārciena',
            'region' => 'Vidzeme',
            'address' => 'Mārciena, Mārcienas pagasts, Madonas novads, LV-4852',
            'place_type' => 'cultural_center',
            'lat' => 56.7667,
            'lng' => 26.1000,
        ],
        'sauleskalna tautas nam' => [
            'name' => 'Sauleskalna tautas nams',
            'city' => 'Bērzaune',
            'region' => 'Vidzeme',
            'address' => 'Sauleskalns, Bērzaunes pagasts, Madonas novads, LV-4853',
            'place_type' => 'cultural_center',
            'lat' => 56.7725,
            'lng' => 26.1367,
        ],
        'mētrienas tautas nam' => [
            'name' => 'Mētrienas tautas nams',
            'city' => 'Mētriena',
            'region' => 'Vidzeme',
            'address' => 'Mētriena, Mētrienas pagasts, Madonas novads, LV-4865',
            'place_type' => 'cultural_center',
            'lat' => 56.6667,
            'lng' => 26.4500,
        ],
        'degumnieku tautas nam' => [
            'name' => 'Degumnieku tautas nams',
            'city' => 'Ošupe',
            'region' => 'Vidzeme',
            'address' => 'Degumnieki, Ošupes pagasts, Madonas novads, LV-4862',
            'place_type' => 'cultural_center',
            'lat' => 56.7667,
            'lng' => 26.7333,
        ],
        'lauteres kultūras nam' => [
            'name' => 'Lauteres kultūras nams',
            'city' => 'Arona',
            'region' => 'Vidzeme',
            'address' => 'Lautere, Aronas pagasts, Madonas novads, LV-4847',
            'place_type' => 'cultural_center',
            'lat' => 56.8833,
            'lng' => 26.0500,
        ],
        'kusas tautas nam' => [
            'name' => 'Kusas tautas nams',
            'city' => 'Arona',
            'region' => 'Vidzeme',
            'address' => 'Kusa, Aronas pagasts, Madonas novads, LV-4847',
            'place_type' => 'cultural_center',
            'lat' => 56.8833,
            'lng' => 26.0500,
        ],
        'praulienas pamatskol' => [
            'name' => 'Praulienas pamatskolas zāle',
            'city' => 'Prauliena',
            'region' => 'Vidzeme',
            'address' => 'Prauliena, Praulienas pagasts, Madonas novads, LV-4861',
            'place_type' => 'education',
            'lat' => 56.8333,
            'lng' => 26.3333,
        ],
        'smeceres sil' => [
            'name' => 'Sporta un atpūtas bāze „Smeceres sils”',
            'city' => 'Madona',
            'region' => 'Vidzeme',
            'address' => 'Smeceres sils, Madonas novads, LV-4801',
            'place_type' => 'sports_facility',
            'lat' => 56.8385,
            'lng' => 26.1950,
        ],
        'kristus karaļa' => [
            'name' => 'Madonas Kristus Karaļa Romas katoļu baznīca',
            'city' => 'Madona',
            'region' => 'Vidzeme',
            'address' => 'Valdemāra bulvāris 26, Madona, Madonas novads, LV-4801',
            'place_type' => 'place_of_worship',
            'lat' => 56.8520,
            'lng' => 26.2180,
        ],
        'madonas pilsētas stadion' => [
            'name' => 'Madonas pilsētas stadions',
            'city' => 'Madona',
            'region' => 'Vidzeme',
            'address' => 'Gaujas iela 13, Madona, Madonas novads, LV-4801',
            'place_type' => 'sports_facility',
            'lat' => 56.8550,
            'lng' => 26.2200,
        ],
    ];

    /**
     * Latvian month names mapping
     */
    protected array $months = [
        'janvāris' => 1, 'janvārī' => 1, 'janvāri' => 1, 'janv' => 1,
        'februāris' => 2, 'februārī' => 2, 'februāri' => 2, 'febr' => 2,
        'marts' => 3, 'martā' => 3, 'martu' => 3, 'mar' => 3,
        'aprīlis' => 4, 'aprīlī' => 4, 'aprīli' => 4, 'apr' => 4,
        'maijs' => 5, 'maijā' => 5, 'maiju' => 5, 'mai' => 5,
        'jūnijs' => 6, 'jūnijā' => 6, 'jūniju' => 6, 'jūn' => 6,
        'jūlijs' => 7, 'jūlijā' => 7, 'jūliju' => 7, 'jūl' => 7,
        'augusts' => 8, 'augustā' => 8, 'augustu' => 8, 'aug' => 8,
        'septembris' => 9, 'septembrī' => 9, 'septembri' => 9, 'sept' => 9,
        'oktobris' => 10, 'oktobrī' => 10, 'oktobri' => 10, 'okt' => 10,
        'novembris' => 11, 'novembrī' => 11, 'novembrī' => 11, 'nov' => 11,
        'decembris' => 12, 'decembrī' => 12, 'decembri' => 12, 'dec' => 12,
    ];

    public function getSlug(): string
    {
        return 'madona';
    }

    public function getName(): string
    {
        return 'Madonas novads';
    }

    /**
     * Scrape events from Madona municipality portal
     *
     * Extracts events directly from listing pages without crawling detail pages.
     */
    public function scrape(Source $source): Collection
    {
        $events = collect();
        $seenExternalIds = [];
        $seenUrls = [];

        $today = Carbon::today('Europe/Riga');
        $primaryUrl = $source->url ?: (self::BASE_URL . self::PASAKUMI_PATH);

        // 1. Scrape municipality calendar first (aggregates novads events with explicit venue metadata)
        $kalendarsUrl = self::BASE_URL . self::KALENDARS_PATH;
        $pageCrawler = $this->scrapeArticleList($kalendarsUrl, $events, $seenExternalIds, $seenUrls, $today);

        // Discover and scrape paginated calendar pages (e.g. ?start=2, ?start=3...)
        if ($pageCrawler) {
            $discoveredPages = [];
            $pageCrawler->filter('.pagdiv a.paglm')->each(function (Crawler $a) use (&$discoveredPages) {
                $href = $a->attr('href');
                if (preg_match('/start=(\d+)/', $href, $m)) {
                    $pageNum = (int) $m[1];
                    if ($pageNum > 1 && $pageNum <= 10) {
                        $discoveredPages[$pageNum] = true;
                    }
                }
            });

            ksort($discoveredPages);
            foreach (array_keys($discoveredPages) as $pageNum) {
                $paginatedUrl = self::BASE_URL . self::KALENDARS_PATH . '?start=' . $pageNum;
                $this->scrapeArticleList($paginatedUrl, $events, $seenExternalIds, $seenUrls, $today);
            }
        }

        // 2. Scrape primary portal page (adds town events and ongoing exhibitions not in calendar)
        $this->scrapeArticleList($primaryUrl, $events, $seenExternalIds, $seenUrls, $today);

        return $events;
    }

    /**
     * Scrape articles from a given Madona listing URL
     */
    public function scrapeArticleList(
        string $url,
        Collection &$events,
        array &$seenExternalIds,
        array &$seenUrls,
        ?Carbon $today = null
    ): ?Crawler {
        $crawler = $this->fetchCrawler($url);
        if (!$crawler) {
            Log::warning("MadonaScraper: Failed to fetch listing page: {$url}");
            return null;
        }

        $today = $today ?: Carbon::today('Europe/Riga');

        $crawler->filter('article')->each(function (Crawler $node) use (&$events, &$seenExternalIds, &$seenUrls, $today, $url) {
            try {
                $titleNode = $node->filter('h3 a');
                if (!$titleNode->count()) {
                    return;
                }

                $title = trim($titleNode->text());
                if (empty($title)) {
                    return;
                }

                $rawHref = trim($titleNode->attr('href'));
                $eventUrl = $this->normalizeUrl($rawHref);

                if (!empty($eventUrl) && isset($seenUrls[$eventUrl])) {
                    return;
                }

                // Extract external ID
                $externalId = $this->generateExternalId($rawHref, $eventUrl);
                if (isset($seenExternalIds[$externalId])) {
                    return;
                }

                // Date parsing from span.kad
                $kadNode = $node->filter('span.kad');
                $rawKad = $kadNode->count() ? trim($kadNode->text()) : '';
                if (empty($rawKad)) {
                    return;
                }

                // Parse posted date for base year inference if present
                $datNode = $node->filter('.rakstshr .dat');
                $rawDat = $datNode->count() ? trim($datNode->text()) : '';
                $baseYear = $today->year;
                if (preg_match('/(\d{4})/', $rawDat, $m)) {
                    $baseYear = (int) $m[1];
                }

                // Split .kad into date part and optional location parts separated by semicolons
                // e.g. "15. novembris 17:00 - 18:28; kinoteātris „Vidzeme” Tirgus ielā 5; Madona"
                $kadParts = array_map('trim', explode(';', $rawKad));
                $rawDatePart = $kadParts[0] ?? '';
                $rawVenuePart = $kadParts[1] ?? null;
                $rawParishPart = $kadParts[2] ?? null;

                [$startAt, $endAt] = $this->parseDates($rawDatePart, $baseYear, $today);
                if (!$startAt) {
                    return;
                }

                // STRICT RULE: No Past Events Modification
                $effectiveEnd = $endAt ?: $startAt;
                if ($effectiveEnd->lt($today)) {
                    return;
                }

                // Description
                $txtNode = $node->filter('span.txt');
                $rawTxt = $txtNode->count() ? trim($txtNode->text()) : '';
                $cleanTitle = $this->cleanText($title) ?? $title;
                $description = !empty($rawTxt) ? $rawTxt : "{$cleanTitle} norisināsies Madonas novadā.";
                $shortDescription = Str::limit($description, 250, '...');

                // Venue & Location resolution
                $locationInfo = $this->resolveLocation($rawVenuePart, $rawParishPart, $title, $description);

                // Category classification complying with AGENTS.md rules
                $categories = $this->inferCategory($title, $description, $locationInfo['name']);

                // Image URL
                $imgNode = $node->filter('span.img img');
                $imageUrl = $imgNode->count() ? $this->normalizeImageUrl($imgNode->attr('src')) : null;

                // Price & Free admission
                $freeBadge = $node->filter('i.free')->count() > 0;
                $priceInfo = $this->extractPricesAndFree($freeBadge, $description, $title);

                $dto = new ScrapedEventDTO(
                    title: $cleanTitle,
                    startAt: $startAt,
                    endAt: $endAt,
                    description: $description,
                    shortDescription: $shortDescription,
                    venueName: $locationInfo['name'],
                    city: $locationInfo['city'],
                    region: 'Vidzeme',
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
                    sourceUrl: $eventUrl ?: $url,
                    sourceExternalId: $externalId,
                    locale: 'lv',
                    rawData: [
                        'raw_kad' => $rawKad,
                        'raw_dat' => $rawDat,
                        'free_badge' => $freeBadge,
                    ]
                );

                if (!empty($eventUrl)) {
                    $seenUrls[$eventUrl] = true;
                }
                $seenExternalIds[$externalId] = true;
                $events->push($dto);
            } catch (\Throwable $e) {
                Log::warning("Error parsing Madona event card: " . $e->getMessage());
            }
        });

        return $crawler;
    }

    /**
     * Parse date range string from Madona portal
     *
     * Handles:
     * - "15. novembris 17:00 - 18:28"
     * - "17. novembris 19:00 - 18. novembris 02:00"
     * - "12. septembris 13:00 - 22. novembris 16:00"
     * - "13. novembris 16:00 - 3. janvāris 16:00" (cross-year rollover)
     * - "15. novembris 17:00"
     * - "15. novembris"
     * - "20. novembris - 3. decembris"
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public function parseDates(string $datePart, ?int $baseYear = null, ?Carbon $today = null): array
    {
        $raw = trim($datePart);
        if (empty($raw)) {
            return [null, null];
        }

        $today = $today ?: Carbon::today('Europe/Riga');
        $baseYear = $baseYear ?: $today->year;

        $parts = explode('-', $raw);

        $extract = function (string $str): array {
            $day = null;
            $month = null;
            $time = null;

            if (preg_match('/(\d{1,2})\.\s*([a-zA-ZāčēģīķļņšūžĀČĒĢĪĶĻŅŠŪŽ]+)/u', $str, $m)) {
                $day = (int) $m[1];
                $mName = mb_strtolower($m[2], 'UTF-8');
                $month = $this->months[$mName] ?? null;
            }

            if (preg_match('/(\d{1,2}:\d{2})/', $str, $m)) {
                $time = $m[1];
            }

            return [$day, $month, $time];
        };

        [$sDay, $sMonth, $sTime] = $extract($parts[0] ?? '');
        if (!$sDay || !$sMonth) {
            return [null, null];
        }

        $sYear = $baseYear;
        // If event month is early in the year and current month is late in the year, roll over
        if ($sMonth < $today->month - 2 && $baseYear === $today->year) {
            $sYear++;
        }

        $sHour = 0;
        $sMin = 0;
        if ($sTime) {
            [$sHour, $sMin] = explode(':', $sTime);
        }

        try {
            $startAt = Carbon::create($sYear, $sMonth, $sDay, (int) $sHour, (int) $sMin, 0, 'Europe/Riga');
        } catch (\Throwable) {
            return [null, null];
        }

        $endAt = null;
        if (isset($parts[1]) && trim($parts[1]) !== '') {
            [$eDay, $eMonth, $eTime] = $extract($parts[1]);
            $eMonth = $eMonth ?: $sMonth;
            $eDay = $eDay ?: $sDay;

            $eYear = $sYear;
            if ($eMonth < $sMonth) {
                $eYear++;
            }

            $eHour = $sHour;
            $eMin = $sMin;
            if ($eTime) {
                [$eHour, $eMin] = explode(':', $eTime);
            }

            try {
                $endAt = Carbon::create($eYear, $eMonth, $eDay, (int) $eHour, (int) $eMin, 0, 'Europe/Riga');
            } catch (\Throwable) {
                $endAt = null;
            }
        }

        return [$startAt, $endAt];
    }

    /**
     * Resolve Location metadata for Madonas novads
     */
    public function resolveLocation(
        ?string $rawVenuePart,
        ?string $rawParishPart,
        string $title,
        string $description = ''
    ): array {
        $venueRaw = trim($rawVenuePart ?? '');
        $parishRaw = trim($rawParishPart ?? '');
        $combined = mb_strtolower($venueRaw . ' ' . $parishRaw . ' ' . $title . ' ' . $description, 'UTF-8');

        // 1. Check known venue map
        foreach ($this->venueAddressMap as $venueKey => $info) {
            if (str_contains($combined, $venueKey)) {
                return $info;
            }
        }

        // 2. Identify town / parish from location components or context
        $townStems = [
            'cesvain' => 'Cesvaine',
            'ērgļ' => 'Ērgļi',
            'lubān' => 'Lubāna',
            'varakļān' => 'Varakļāni',
            'aron' => 'Arona',
            'lauter' => 'Arona',
            'kus' => 'Arona',
            'barkav' => 'Barkava',
            'bērzaun' => 'Bērzaune',
            'sauleskaln' => 'Bērzaune',
            'dzelzav' => 'Dzelzava',
            'kalsnav' => 'Kalsnava',
            'jaunkalsnav' => 'Kalsnava',
            'lazdon' => 'Lazdona',
            'liezēr' => 'Liezēre',
            'ļaudon' => 'Ļaudona',
            'mārcien' => 'Mārciena',
            'mētrien' => 'Mētriena',
            'ošup' => 'Ošupe',
            'degumniek' => 'Ošupe',
            'praulien' => 'Prauliena',
            'sarkaņ' => 'Sarkaņi',
            'biksēr' => 'Sarkaņi',
            'sausnēj' => 'Sausnēja',
            'sidrabiņ' => 'Sausnēja',
            'vestien' => 'Vestiena',
            'jumurd' => 'Jumurda',
            'indrān' => 'Indrāni',
            'murmastien' => 'Murmastiene',
            'madon' => 'Madona',
        ];

        $matchedTown = null;
        if (!empty($parishRaw)) {
            foreach ($townStems as $stem => $canonicalTown) {
                if (preg_match('/\b' . preg_quote($stem, '/') . '[a-zāčēģīķļņšūž]*/ui', $parishRaw)) {
                    $matchedTown = $canonicalTown;
                    break;
                }
            }
        }
        if (!$matchedTown && !empty($venueRaw)) {
            foreach ($townStems as $stem => $canonicalTown) {
                if (preg_match('/\b' . preg_quote($stem, '/') . '[a-zāčēģīķļņšūž]*/ui', $venueRaw)) {
                    $matchedTown = $canonicalTown;
                    break;
                }
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

        $townName = $matchedTown ?: 'Madona';
        $townKey = mb_strtolower($townName, 'UTF-8');
        $coords = $this->townCoordinates[$townKey] ?? ['lat' => 56.8533, 'lng' => 26.2167];

        // Specific venue inference for Madona cinema
        if ($townName === 'Madona' && (empty($venueRaw) || mb_strtolower($venueRaw, 'UTF-8') === 'madona')) {
            if (preg_match('/kinoseans/ui', $combined)) {
                return $this->venueAddressMap['kinoteātr'];
            }
        }

        $venueName = !empty($venueRaw) ? $venueRaw : $townName;
        $address = "{$venueName}, {$townName}, Madonas novads";

        // Infer place type
        $placeType = 'venue';
        $lowerVenue = mb_strtolower($venueName, 'UTF-8');
        if (str_contains($lowerVenue, 'kinoteātris') || str_contains($lowerVenue, 'kino')) {
            $placeType = 'cinema';
        } elseif (str_contains($lowerVenue, 'kultūras nams') || str_contains($lowerVenue, 'tautas nams') || str_contains($lowerVenue, 'saieta nams') || str_contains($lowerVenue, 'kultūras centrs')) {
            $placeType = 'cultural_center';
        } elseif (str_contains($lowerVenue, 'muzejs') || str_contains($lowerVenue, 'pils') || str_contains($lowerVenue, 'muiža')) {
            $placeType = 'museum';
        } elseif (str_contains($lowerVenue, 'bibliotēka')) {
            $placeType = 'library';
        } elseif (str_contains($lowerVenue, 'sporta') || str_contains($lowerVenue, 'stadions') || str_contains($lowerVenue, 'bāze')) {
            $placeType = 'sports_facility';
        } elseif (str_contains($lowerVenue, 'skola') || str_contains($lowerVenue, 'ģimnāzija')) {
            $placeType = 'education';
        } elseif (str_contains($lowerVenue, 'baznīca')) {
            $placeType = 'place_of_worship';
        } elseif (str_contains($lowerVenue, 'parks') || str_contains($lowerVenue, 'estrāde')) {
            $placeType = 'park';
        }

        return [
            'name' => $venueName,
            'city' => $townName,
            'region' => 'Vidzeme',
            'address' => $address,
            'place_type' => $placeType,
            'lat' => $coords['lat'],
            'lng' => $coords['lng'],
        ];
    }

    /**
     * Strict categorization complying with AGENTS.md rules
     */
    public function inferCategory(string $title, string $description = '', ?string $venueName = null): array
    {
        $titleLower = mb_strtolower($title, 'UTF-8');
        $descLower = mb_strtolower($description, 'UTF-8');
        $venueLower = mb_strtolower($venueName ?? '', 'UTF-8');
        $text = $titleLower . ' ' . $descLower . ' ' . $venueLower;

        // 1. Strict Kino rules from AGENTS.md (Priority: Cinema venues or keywords -> ALWAYS Kino, NEVER Teātris)
        if (
            str_contains($venueLower, 'kinoteātris') ||
            str_contains($venueLower, 'kino rio') ||
            str_contains($venueLower, 'k.suns') ||
            str_contains($venueLower, 'kino bize') ||
            str_contains($venueLower, 'splendid palace') ||
            str_contains($venueLower, 'forum cinemas') ||
            str_contains($venueLower, 'apollo kino') ||
            str_contains($venueLower, 'cinamon') ||
            preg_match('/\b(kinoseans[a-zāčēģīķļņšūž]*|film[a-zāčēģīķļņšūž]*|spēlfilm[a-zāčēģīķļņšūž]*|dokumentāl[a-zāčēģīķļņšūž]*|kinofestivāl[a-zāčēģīķļņšūž]*)\b/ui', $text)
        ) {
            return ['Kino'];
        }

        // 2. Strict Theater rules from AGENTS.md (Only actual theater productions/venues, NEVER kinoteātris)
        if (
            !str_contains($venueLower, 'kinoteātris') &&
            preg_match('/\b(izrād[a-zāčēģīķļņšūž]*|lug[a-zāčēģīķļņšūž]*|iestudējum[a-zāčēģīķļņšūž]*|amatierteātr[a-zāčēģīķļņšūž]*|teātr[a-zāčēģīķļņšūž]*|oper[a-zāčēģīķļņšūž]*|balet[a-zāčēģīķļņšūž]*)\b/ui', $text)
        ) {
            return ['Teātris'];
        }

        // 3. Concerts & Music
        if (preg_match('/\b(koncert[a-zāčēģīķļņšūž]*|mūzik[a-zāčēģīķļņšūž]*|muzikāl[a-zāčēģīķļņšūž]*|kor[a-zāčēģīķļņšūž]*|orķestr[a-zāčēģīķļņšūž]*|ball[a-zāčēģīķļņšūž]*|dziesm[a-zāčēģīķļņšūž]*|dzied[a-zāčēģīķļņšūž]*|grup[a-zāčēģīķļņšūž]*|blūz[a-zāčēģīķļņšūž]*|blues)\b/ui', $text)) {
            return ['Koncerts'];
        }

        // 4. Exhibitions
        if (preg_match('/\b(izstād[a-zāčēģīķļņšūž]*|glezn[a-zāčēģīķļņšūž]*|māksl[a-zāčēģīķļņšūž]*|rokdarb[a-zāčēģīķļņšūž]*|ekspozīcij[a-zāčēģīķļņšūž]*|plenēr[a-zāčēģīķļņšūž]*)\b/ui', $text)) {
            return ['Izstāde'];
        }

        // 5. Sports
        if (preg_match('/\b(sport[a-zāčēģīķļņšūž]*|skrējien[a-zāčēģīķļņšūž]*|turnīr[a-zāčēģīķļņšūž]*|basketbol[a-zāčēģīķļņšūž]*|volejbol[a-zāčēģīķļņšūž]*|sacensīb[a-zāčēģīķļņšūž]*|čempionāt[a-zāčēģīķļņšūž]*)\b/ui', $text)) {
            return ['Sports'];
        }

        // 6. Children / Families
        if (preg_match('/\b(bērniem|ģimenēm|leļļu)\b/ui', $text)) {
            return ['Bērniem'];
        }

        // 7. Education / Workshops
        if (preg_match('/\b(meistarklas[a-zāčēģīķļņšūž]*|darbnīc[a-zāčēģīķļņšūž]*|nodarbīb[a-zāčēģīķļņšūž]*|seminār[a-zāčēģīķļņšūž]*|lekcij[a-zāčēģīķļņšūž]*)\b/ui', $text)) {
            return ['Izglītība'];
        }

        // 8. Strict Fallback
        return ['Cits'];
    }

    /**
     * Extract prices and free admission indicators
     */
    public function extractPricesAndFree(bool $isFreeBadge, string $description, string $title): array
    {
        $text = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $combined = mb_strtolower($title . ' ' . $text, 'UTF-8');

        $isFree = $isFreeBadge;
        if (preg_match('/(?:bez\s+maksas|bezmaksas|ieeja\s+brīva|brīva\s+ieeja|par\s+brīvu)/ui', $combined)) {
            $isFree = true;
        }

        $prices = [];
        // Look for price patterns like "3 EUR", "5.00 līdz 10.00 EUR", "€ 4.00", "biļetes: 5-10 €"
        if (preg_match('/(?:€|eur|eiro|maksa|biļet)/ui', $combined)) {
            if (preg_match_all('/(\d+(?:[.,]\d{1,2})?)\s*(?:€|eur|eiro)?/ui', $text, $m)) {
                // Check if price numbers appear near price contexts or currency symbols
                if (preg_match('/(?:(?:ieeja|biļete|maksa)[^.\n]*?)?(\d+(?:[.,]\d{1,2})?)(?:\s*(?:līdz|-)\s*(\d+(?:[.,]\d{1,2})?))?\s*(?:€|eur|eiro)/ui', $text, $rangeMatch)) {
                    if (!empty($rangeMatch[1])) {
                        $prices[] = (float) str_replace(',', '.', $rangeMatch[1]);
                    }
                    if (!empty($rangeMatch[2])) {
                        $prices[] = (float) str_replace(',', '.', $rangeMatch[2]);
                    }
                } elseif (preg_match_all('/(\d+(?:[.,]\d{1,2})?)\s*(?:€|eur|eiro)/ui', $text, $singleMatches)) {
                    foreach ($singleMatches[1] as $pStr) {
                        $val = (float) str_replace(',', '.', $pStr);
                        if ($val >= 0.50 && $val <= 500) {
                            $prices[] = $val;
                        }
                    }
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
     * Normalize relative URL to absolute URL
     */
    public function normalizeUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $url = trim($url);
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim(self::BASE_URL . '/lat', '/') . '/' . ltrim($url, '/');
    }

    /**
     * Normalize image URL to absolute URL
     */
    public function normalizeImageUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $url = trim($url);
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim(self::BASE_URL . '/lat', '/') . '/' . ltrim($url, '/');
    }

    /**
     * Generate deterministic external ID
     */
    public function generateExternalId(?string $rawHref, ?string $eventUrl): string
    {
        $href = $rawHref ?? $eventUrl ?? '';

        if (preg_match('/id=(\d+)/', $href, $m)) {
            $id = $m[1];
            if (str_contains($href, 'sporta-aktualitates')) {
                return "madona-sport-{$id}";
            }
            if (str_contains($href, 'visitmadona.lv')) {
                return "madona-vm-{$id}";
            }
            return "madona-{$id}";
        }

        return 'madona-' . substr(md5($eventUrl ?: $href), 0, 12);
    }

    /**
     * Clean and normalize text
     */
    protected function cleanText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xc2\xa0", '&nbsp;', "\r"], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        $trimmed = trim($text);
        return $trimmed !== '' ? $trimmed : null;
    }
}
