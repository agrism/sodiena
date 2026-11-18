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

class JelgavasValstspilsetaScraper extends BaseScraper
{
    public const BASE_URL = 'https://www.jelgava.lv';
    public const CALENDAR_PATH = '/pasakumi/';

    /**
     * Map of known Jelgava venues with canonical names, addresses, coordinates, place types
     */
    protected array $venueMap = [
        'jelgavas sv. trīsvienības baznīcas tornis' => [
            'name' => 'Jelgavas Sv. Trīsvienības baznīcas tornis',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Akadēmijas iela 1, Jelgava, LV-3001',
            'place_type' => 'museum',
            'latitude' => 56.652411,
            'longitude' => 23.729189,
        ],
        'uz jelgavas sv. trīsvienības baznīcas torņa' => [
            'name' => 'Jelgavas Sv. Trīsvienības baznīcas tornis',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Akadēmijas iela 1, Jelgava, LV-3001',
            'place_type' => 'museum',
            'latitude' => 56.652411,
            'longitude' => 23.729189,
        ],
        'tornis' => [
            'name' => 'Jelgavas Sv. Trīsvienības baznīcas tornis',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Akadēmijas iela 1, Jelgava, LV-3001',
            'place_type' => 'museum',
            'latitude' => 56.652411,
            'longitude' => 23.729189,
        ],
        'hercoga jēkaba laukums' => [
            'name' => 'Hercoga Jēkaba laukums',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Hercoga Jēkaba laukums, Jelgava, LV-3001',
            'place_type' => 'square',
            'latitude' => 56.652644,
            'longitude' => 23.725299,
        ],
        'pasta sala' => [
            'name' => 'Pasta sala',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Pasta sala, Jelgava, LV-3001',
            'place_type' => 'park',
            'latitude' => 56.650037,
            'longitude' => 23.735292,
        ],
        'brīvdabas koncertzāle "mītava"' => [
            'name' => 'Brīvdabas koncertzāle "Mītava"',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Pasta sala 3, Jelgava, LV-3001',
            'place_type' => 'concert_hall',
            'latitude' => 56.651329,
            'longitude' => 23.735166,
        ],
        'brīvdabas koncertzāle mītava' => [
            'name' => 'Brīvdabas koncertzāle "Mītava"',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Pasta sala 3, Jelgava, LV-3001',
            'place_type' => 'concert_hall',
            'latitude' => 56.651329,
            'longitude' => 23.735166,
        ],
        'mītavas tilts' => [
            'name' => 'Mītavas tilts',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Mītavas tilts, Jelgava, LV-3001',
            'place_type' => 'bridge',
            'latitude' => 56.6520,
            'longitude' => 23.7320,
        ],
        'jelgavas kultūras nams' => [
            'name' => 'Jelgavas kultūras nams',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Krišjāņa Barona iela 6, Jelgava, LV-3001',
            'place_type' => 'cultural_center',
            'latitude' => 56.653913,
            'longitude' => 23.723446,
        ],
        'kultūras nams' => [
            'name' => 'Jelgavas kultūras nams',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Krišjāņa Barona iela 6, Jelgava, LV-3001',
            'place_type' => 'cultural_center',
            'latitude' => 56.653913,
            'longitude' => 23.723446,
        ],
        'kultūras nams, 1. stāva galerija' => [
            'name' => 'Jelgavas kultūras nams (1. stāva galerija)',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Krišjāņa Barona iela 6, Jelgava, LV-3001',
            'place_type' => 'gallery',
            'latitude' => 56.653913,
            'longitude' => 23.723446,
        ],
        'kultūras nams, 2. stāva galerija' => [
            'name' => 'Jelgavas kultūras nams (2. stāva galerija)',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Krišjāņa Barona iela 6, Jelgava, LV-3001',
            'place_type' => 'gallery',
            'latitude' => 56.653913,
            'longitude' => 23.723446,
        ],
        'ādolfa alunāna jelgavas teātris' => [
            'name' => 'Ādolfa Alunāna Jelgavas teātris',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Krišjāņa Barona iela 6, Jelgava, LV-3001',
            'place_type' => 'theatre',
            'latitude' => 56.653913,
            'longitude' => 23.723446,
        ],
        'ādolfa alunāna memoriālais muzejs' => [
            'name' => 'Ādolfa Alunāna memoriālais muzejs',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Filozofu iela 3, Jelgava, LV-3001',
            'place_type' => 'museum',
            'latitude' => 56.644277,
            'longitude' => 23.710440,
        ],
        'ģ. eliasa jelgavas vēstures un mākslas muzejs' => [
            'name' => 'Ģ. Eliasa Jelgavas vēstures un mākslas muzejs',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Akadēmijas iela 10, Jelgava, LV-3001',
            'place_type' => 'museum',
            'latitude' => 56.650700,
            'longitude' => 23.729700,
        ],
        'ģ. eliasa jelgavas vēstures un muzejs' => [
            'name' => 'Ģ. Eliasa Jelgavas vēstures un mākslas muzejs',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Akadēmijas iela 10, Jelgava, LV-3001',
            'place_type' => 'museum',
            'latitude' => 56.650700,
            'longitude' => 23.729700,
        ],
        'jelgavas pilsētas bibliotēka' => [
            'name' => 'Jelgavas Pilsētas bibliotēka',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Akadēmijas iela 26, Jelgava, LV-3001',
            'place_type' => 'library',
            'latitude' => 56.646495,
            'longitude' => 23.730233,
        ],
        'jelgavas pilsētas bibliotēkas lasītava' => [
            'name' => 'Jelgavas Pilsētas bibliotēka',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Akadēmijas iela 26, Jelgava, LV-3001',
            'place_type' => 'library',
            'latitude' => 56.646495,
            'longitude' => 23.730233,
        ],
        'pārlielupes bibliotēka' => [
            'name' => 'Pārlielupes bibliotēka',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Loka maģistrāle 17, Jelgava, LV-3004',
            'place_type' => 'library',
            'latitude' => 56.666100,
            'longitude' => 23.754800,
        ],
        'bērnu un jauniešu bibliotēka "zinītis"' => [
            'name' => 'Bērnu un jauniešu bibliotēka "Zinītis"',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Zemgales prospekts 7, Jelgava, LV-3001',
            'place_type' => 'library',
            'latitude' => 56.648900,
            'longitude' => 23.728900,
        ],
        'zemgales reģiona kompetenču attīstības centrs' => [
            'name' => 'Zemgales reģiona Kompetenču attīstības centrs',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Svētes iela 33, Jelgava, LV-3001',
            'place_type' => 'educational',
            'latitude' => 56.648200,
            'longitude' => 23.714500,
        ],
        'jauniešu centrs "pakāpiens"' => [
            'name' => 'Jauniešu centrs "Pakāpiens"',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Loka maģistrāle 25, Jelgava, LV-3004',
            'place_type' => 'community_center',
            'latitude' => 56.666900,
            'longitude' => 23.756200,
        ],
        'zemgales olimpiskais centrs' => [
            'name' => 'Zemgales Olimpiskais centrs',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Kronvalda iela 24, Jelgava, LV-3004',
            'place_type' => 'sports_complex',
            'latitude' => 56.661360,
            'longitude' => 23.754437,
        ],
        'jelgavas pils parks' => [
            'name' => 'Jelgavas pils parks',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Lielā iela 2, Jelgava, LV-3001',
            'place_type' => 'park',
            'latitude' => 56.655847,
            'longitude' => 23.733911,
        ],
        'jelgavas pils pagalms' => [
            'name' => 'Jelgavas pils pagalms',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Lielā iela 2, Jelgava, LV-3001',
            'place_type' => 'historical_site',
            'latitude' => 56.655858,
            'longitude' => 23.733162,
        ],
        'lbtu aula' => [
            'name' => 'LBTU Aula',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Lielā iela 2, Jelgava, LV-3001',
            'place_type' => 'hall',
            'latitude' => 56.655849,
            'longitude' => 23.733923,
        ],
        'pie j. čakstes pieminekļa' => [
            'name' => 'Jāņa Čakstes piemineklis',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Lielās ielas un Akadēmijas ielas krustojums, Jelgava, LV-3001',
            'place_type' => 'monument',
            'latitude' => 56.652000,
            'longitude' => 23.728500,
        ],
        'jelgavas sv. annas prokatedrāle' => [
            'name' => 'Jelgavas Sv. Annas evaņģēliski luteriskā baznīca',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Lielā iela 22A, Jelgava, LV-3001',
            'place_type' => 'church',
            'latitude' => 56.650745,
            'longitude' => 23.715797,
        ],
        'jelgavas sv. annas baznīca' => [
            'name' => 'Jelgavas Sv. Annas evaņģēliski luteriskā baznīca',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Lielā iela 22A, Jelgava, LV-3001',
            'place_type' => 'church',
            'latitude' => 56.650745,
            'longitude' => 23.715797,
        ],
        'jelgavas sv. jāņa baznīca' => [
            'name' => 'Jelgavas Sv. Jāņa evaņģēliski luteriskā baznīca',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Jāņa iela 1, Jelgava, LV-3001',
            'place_type' => 'church',
            'latitude' => 56.644480,
            'longitude' => 23.728634,
        ],
        'uzvaras parks' => [
            'name' => 'Uzvaras parks',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Uzvaras iela, Jelgava, LV-3001',
            'place_type' => 'park',
            'latitude' => 56.658827,
            'longitude' => 23.717307,
        ],
        'raiņa parks' => [
            'name' => 'Raiņa parks',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Raiņa iela, Jelgava, LV-3001',
            'place_type' => 'park',
            'latitude' => 56.647480,
            'longitude' => 23.723152,
        ],
        'jelgavas lielupes vidusskola' => [
            'name' => 'Jelgavas Lielupes pamatskola',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Loka maģistrāle 29, Jelgava, LV-3004',
            'place_type' => 'school',
            'latitude' => 56.668500,
            'longitude' => 23.758200,
        ],
        'deju nams' => [
            'name' => 'Deju nams',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Cukura iela 22, Jelgava, LV-3002',
            'place_type' => 'cultural_center',
            'latitude' => 56.651862,
            'longitude' => 23.749060,
        ],
    ];

    public function getSlug(): string
    {
        return 'jelgavas-valstspilseta';
    }

    public function getName(): string
    {
        return 'Jelgavas valstspilsētas pašvaldība';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $baseUrl = self::BASE_URL . self::CALENDAR_PATH;
        $maxPages = 15;
        $today = Carbon::today('Europe/Riga');
        $seenExternalIds = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            if ($page > 1) {
                $this->politeDelay(1000, 300);
            }

            $pageUrl = $page === 1 ? $baseUrl : $baseUrl . 'page/' . $page . '/';
            $crawler = $this->fetchCrawler($pageUrl);

            if (!$crawler) {
                break;
            }

            $items = $crawler->filter('.events-items-wrap');
            if ($items->count() === 0) {
                break;
            }

            $allPageEventsPast = true;

            $items->each(function (Crawler $node) use (&$events, &$seenExternalIds, &$allPageEventsPast, $today, $baseUrl) {
                $titleNode = $node->filter('.events-title a, .events-title');
                $title = $this->cleanText($titleNode->count() ? $titleNode->first()->text() : '');
                if (empty($title)) {
                    return;
                }

                // Link to event
                $linkNode = $node->filter('.events-title a');
                $relLink = $linkNode->count() ? $linkNode->first()->attr('href') : '';
                $cleanUrl = $this->normalizeUrl($relLink);

                $slugPart = $cleanUrl ? basename(rtrim(parse_url($cleanUrl, PHP_URL_PATH), '/')) : Str::slug($title);
                $externalId = 'jelgava-' . $slugPart;

                if (isset($seenExternalIds[$externalId])) {
                    return;
                }

                // Exclude administrative & council meetings
                if ($this->isExcludedEvent([], $title)) {
                    return;
                }

                // Dates & times from card
                $dateStr = $node->filter('.events-dates')->count() ? trim($node->filter('.events-dates')->text()) : '';
                $timeStr = $node->filter('.events-times')->count() ? trim($node->filter('.events-times')->text()) : '';

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

                // Card image
                $cardImgNode = $node->filter('.event-image-wrap img');
                $cardImg = $cardImgNode->count() ? $cardImgNode->first()->attr('src') : null;

                // Card venue
                $cardPlace = $node->filter('.events-places')->count() ? $this->cleanText($node->filter('.events-places')->text()) : '';

                // Card price
                $cardPrice = $node->filter('.events-price')->count() ? $this->cleanText($node->filter('.events-price')->text()) : '';

                // Fetch detail page for full rich description, large image, categories, tickets
                $detailData = $cleanUrl ? $this->fetchDetailPage($cleanUrl) : [];

                // Description: full text from detail page, fallback to title
                $description = !empty($detailData['description']) ? $detailData['description'] : $title;

                // Image: prefer detail page high-res image, fallback to card image
                $imageUrl = !empty($detailData['imageUrl']) ? $detailData['imageUrl'] : $cardImg;

                // Categories from detail page
                $categories = $detailData['categories'] ?? [];

                // Ticket URL
                $ticketUrl = $detailData['ticketUrl'] ?? null;

                // Price parsing
                $priceData = $this->parsePrice($cardPrice);

                // Location resolution
                $venueInfo = $this->resolveLocation($cardPlace, $title);

                // Category & Entertainment Type resolution adhering strictly to AGENTS.md
                [$canonicalCategories, $entertainmentType] = $this->resolveCategories($categories, $title, $description, $venueInfo['name']);

                $seenExternalIds[$externalId] = true;

                $events->push(new ScrapedEventDTO(
                    title: $title,
                    startAt: $startAt,
                    endAt: $endAt,
                    description: $description,
                    shortDescription: Str::limit(strip_tags($description), 160),
                    venueName: $venueInfo['name'],
                    city: $venueInfo['city'],
                    region: $venueInfo['region'],
                    address: $venueInfo['address'],
                    latitude: $venueInfo['latitude'],
                    longitude: $venueInfo['longitude'],
                    placeType: $venueInfo['place_type'],
                    categoryNames: $canonicalCategories,
                    entertainmentType: $entertainmentType,
                    isFree: $priceData['is_free'],
                    priceMin: $priceData['price_min'],
                    priceMax: $priceData['price_max'],
                    currency: 'EUR',
                    ticketUrl: $ticketUrl,
                    imageUrl: $imageUrl,
                    sourceUrl: $cleanUrl ?: $baseUrl,
                    sourceExternalId: $externalId,
                    locale: 'lv',
                    rawData: array_filter([
                        'original_categories' => $categories,
                        'original_place' => $cardPlace,
                        'price_raw' => $cardPrice,
                        'organizer' => $detailData['organizer'] ?? null,
                    ]),
                ));
            });

            // If an entire page contains only past events, break pagination
            if ($allPageEventsPast && $items->count() > 0) {
                break;
            }
        }

        return $events;
    }

    /**
     * Fetch single event detail page and extract full description, large image, categories, tickets
     */
    public function fetchDetailPage(string $url): array
    {
        $crawler = $this->fetchCrawler($url);
        if (!$crawler) {
            return [];
        }

        // Full description
        $desc = '';
        $descNode = $crawler->filter('.block-article-open .row .col-24.col-md-14 .block-content-padding');
        if (!$descNode->count()) {
            $descNode = $crawler->filter('.block-article-open .block-content-padding');
        }

        if ($descNode->count()) {
            // Remove back buttons or script tags
            $descCrawler = new Crawler($descNode->first()->html());
            $descCrawler->filter('a.button, script, style')->each(function (Crawler $crawler) {
                foreach ($crawler as $node) {
                    $node->parentNode?->removeChild($node);
                }
            });

            $desc = $this->cleanText($descCrawler->html());
        }

        // Categories from meta links & breadcrumbs
        $categories = [];
        $crawler->filter('.article-open-meta a, .block-breadcrumbs a.events-categories, .block-breadcrumbs span[property="name"]')
            ->each(function (Crawler $c) use (&$categories) {
                $txt = trim($c->text());
                if (!empty($txt) && !in_array($txt, ['Sākumlapa', 'Pasākumi', 'LV'], true)) {
                    $categories[] = $txt;
                }
            });
        $categories = array_values(array_unique($categories));

        // Featured large image
        $imageUrl = null;
        $imgNode = $crawler->filter('.block-article-open img');
        if ($imgNode->count()) {
            $imageUrl = $imgNode->first()->attr('src');
        }

        // Ticket link (check for BP, Biļešu serviss, Ticketshop, Bezrindas, Fienta or any buy link)
        $ticketUrl = null;
        $crawler->filter('.block-article-open a')->each(function (Crawler $link) use (&$ticketUrl) {
            if ($ticketUrl) {
                return;
            }
            $href = $link->attr('href');
            $txt = mb_strtolower($link->text(), 'UTF-8');
            if (empty($href) || str_starts_with($href, 'javascript:') || str_contains($href, 'jelgava.lv/pasakumu-kategorija')) {
                return;
            }

            if (preg_match('/bilesuparadize\.lv|bilesuserviss\.lv|ticketshop\.lv|bezrindas\.lv|fienta\.com/i', $href)) {
                $ticketUrl = $href;
            } elseif (str_contains($txt, 'biļet') || str_contains($txt, 'pirkt') || str_contains($txt, 'iegādāt')) {
                $ticketUrl = $href;
            }
        });

        // Organizer
        $organizer = null;
        $orgNode = $crawler->filter('.event-organizer');
        if ($orgNode->count()) {
            $organizer = $this->cleanText($orgNode->first()->text());
        }

        return [
            'description' => $desc,
            'categories' => $categories,
            'imageUrl' => $imageUrl,
            'ticketUrl' => $ticketUrl,
            'organizer' => $organizer,
        ];
    }

    /**
     * Check if event is an administrative meeting, council session or public consultation
     */
    public function isExcludedEvent(array $categories, string $title): bool
    {
        $titleLower = mb_strtolower($title, 'UTF-8');

        $administrativeKeywords = [
            'komitejas sēde',
            'komiteju sēde',
            'domes sēde',
            'padomes sēde',
            'ārkārtas sēde',
            'kārtējā sēde',
            'pieņem iedzīvotājus',
            'iedzīvotāju pieņemšana',
            'iedzīvotāju sapulce',
            'publiskā apspriešana',
            'sabiedriskā apspriešana',
            'apspriešana',
            'lokālplānojuma',
        ];

        foreach ($administrativeKeywords as $keyword) {
            if (str_contains($titleLower, $keyword)) {
                return true;
            }
        }

        foreach ($categories as $cat) {
            $catLower = mb_strtolower($cat, 'UTF-8');
            foreach ($administrativeKeywords as $keyword) {
                if (str_contains($catLower, $keyword)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Parse Latvian date string (e.g. "18.11.2026", "No 16.11.2026 līdz 20.11.2026", "22.09.2026 - 22.11.2026")
     * combined with optional time string (e.g. "18:00 - 22:00", "16:00").
     */
    public function parseLatvianDates(string $dateStr, string $timeStr = ''): array
    {
        $dateStr = trim($dateStr);
        $timeStr = trim($timeStr);

        if (empty($dateStr)) {
            return [null, null, true];
        }

        $startTime = '00:00';
        $endTime = null;
        $allDay = true;

        if (!empty($timeStr)) {
            if (preg_match('/(\d{1,2}[:.]\d{2})\s*(?:-|–|līdz)\s*(\d{1,2}[:.]\d{2})/ui', $timeStr, $tm)) {
                $startTime = str_replace('.', ':', $tm[1]);
                $endTime = str_replace('.', ':', $tm[2]);
                $allDay = false;
            } elseif (preg_match('/(\d{1,2}[:.]\d{2})/ui', $timeStr, $tm)) {
                $startTime = str_replace('.', ':', $tm[1]);
                $allDay = false;
            }
        }

        // Case 1: Date range e.g. "No 16.11.2026 līdz 20.11.2026" or "16.11.2026 - 20.11.2026"
        if (preg_match('/(?:no\s+)?(\d{1,2})\.(\d{1,2})\.(\d{4})\.?\s*(?:līdz|-|–)\s*(\d{1,2})\.(\d{1,2})\.(\d{4})\.?/ui', $dateStr, $m)) {
            try {
                $startAt = Carbon::createFromFormat('d.m.Y H:i', sprintf('%02d.%02d.%04d %s', $m[1], $m[2], $m[3], $startTime), 'Europe/Riga');
                $endAtTime = $endTime ?: '23:59';
                $endAt = Carbon::createFromFormat('d.m.Y H:i', sprintf('%02d.%02d.%04d %s', $m[4], $m[5], $m[6], $endAtTime), 'Europe/Riga');
                return [$startAt, $endAt, $allDay];
            } catch (\Throwable $e) {
                // fallback
            }
        }

        // Case 2: Single date e.g. "18.11.2026"
        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/ui', $dateStr, $m)) {
            try {
                $startAt = Carbon::createFromFormat('d.m.Y H:i', sprintf('%02d.%02d.%04d %s', $m[1], $m[2], $m[3], $startTime), 'Europe/Riga');
                $endAt = null;
                if ($endTime) {
                    $endAt = Carbon::createFromFormat('d.m.Y H:i', sprintf('%02d.%02d.%04d %s', $m[1], $m[2], $m[3], $endTime), 'Europe/Riga');
                } elseif (!$allDay) {
                    $endAt = $startAt->copy()->addHours(2);
                }
                return [$startAt, $endAt, $allDay];
            } catch (\Throwable $e) {
                // fallback
            }
        }

        return [null, null, true];
    }

    /**
     * Parse price string into is_free, price_min, price_max
     */
    public function parsePrice(string $priceStr): array
    {
        $priceStr = trim($priceStr);
        if (empty($priceStr)) {
            return ['is_free' => false, 'price_min' => null, 'price_max' => null];
        }

        $lower = mb_strtolower($priceStr, 'UTF-8');
        if (str_contains($lower, 'bez maksas') || str_contains($lower, 'brīvbiļete') || str_contains($lower, 'brivbilete')) {
            return ['is_free' => true, 'price_min' => 0.0, 'price_max' => 0.0];
        }

        // Clean currency words and symbols
        $clean = str_ireplace(['eiro', 'eur', '€', 'euro'], '', $priceStr);
        $clean = trim($clean);

        // Normalize range separators (–, —, līdz, -)
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:-|–|—|līdz)\s*(\d+(?:[.,]\d+)?)/ui', $clean, $m)) {
            $p1 = (float) str_replace(',', '.', $m[1]);
            $p2 = (float) str_replace(',', '.', $m[2]);
            $min = min($p1, $p2);
            $max = max($p1, $p2);
            return [
                'is_free' => ($min === 0.0 && $max === 0.0),
                'price_min' => $min,
                'price_max' => $max,
            ];
        }

        // Match single number e.g. "3.00", "15.00", "0.00", "5,6"
        if (preg_match('/(\d+(?:[.,]\d+)?)/ui', $clean, $m)) {
            $val = (float) str_replace(',', '.', $m[1]);
            return [
                'is_free' => ($val === 0.0),
                'price_min' => $val,
                'price_max' => $val,
            ];
        }

        return ['is_free' => false, 'price_min' => null, 'price_max' => null];
    }

    /**
     * Resolve location name, address, coordinates, place type from raw venue string & title
     */
    public function resolveLocation(string $rawPlace, string $title): array
    {
        $cleanPlace = trim(preg_replace('/\s+/u', ' ', $rawPlace));
        $lowerPlace = mb_strtolower($cleanPlace, 'UTF-8');

        // Check curated venue map
        foreach ($this->venueMap as $key => $info) {
            if (!empty($lowerPlace) && str_contains($lowerPlace, $key)) {
                return $info;
            }
        }

        // Check if title mentions known venue
        $lowerTitle = mb_strtolower($title, 'UTF-8');
        foreach ($this->venueMap as $key => $info) {
            if (str_contains($lowerTitle, $key)) {
                return $info;
            }
        }

        // Fallback: If place string contains address parts (e.g. "Pārlielupes bibliotēka, Loka maģistrāle 17, Jelgava")
        if (!empty($cleanPlace)) {
            $parts = array_map('trim', explode(',', $cleanPlace));
            $venueName = $parts[0];
            $address = $cleanPlace;
            $city = 'Jelgava';

            if (!str_contains(mb_strtolower($address, 'UTF-8'), 'jelgava')) {
                $address .= ', Jelgava';
            }

            $placeType = 'venue';
            $lower = mb_strtolower($venueName, 'UTF-8');
            if (str_contains($lower, 'bibliotēk')) {
                $placeType = 'library';
            } elseif (str_contains($lower, 'muzej') || str_contains($lower, 'tornis')) {
                $placeType = 'museum';
            } elseif (str_contains($lower, 'skol') || str_contains($lower, 'centrs') || str_contains($lower, 'zrkac')) {
                $placeType = 'educational';
            } elseif (str_contains($lower, 'teātr')) {
                $placeType = 'theatre';
            } elseif (str_contains($lower, 'parks') || str_contains($lower, 'laukums')) {
                $placeType = 'park';
            } elseif (str_contains($lower, 'sport') || str_contains($lower, 'zoc')) {
                $placeType = 'sports_complex';
            }

            return [
                'name' => $venueName,
                'city' => $city,
                'region' => 'Zemgale',
                'address' => $address,
                'place_type' => $placeType,
                'latitude' => null,
                'longitude' => null,
            ];
        }

        // Default Jelgava fallback
        return [
            'name' => 'Jelgava',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Jelgava, LV-3001',
            'place_type' => 'city',
            'latitude' => 56.6511,
            'longitude' => 23.7214,
        ];
    }

    /**
     * Resolve categories & entertainmentType strictly adhering to AGENTS.md
     */
    public function resolveCategories(array $categories, string $title, string $description, string $venueName): array
    {
        $canonical = [];
        $entertainmentType = null;

        $textToAnalyze = implode(' ', array_merge($categories, [$title, $description, $venueName]));
        $combinedText = mb_strtolower($textToAnalyze, 'UTF-8');

        // 1. Strict Cinema / Kino rule (AGENTS.md):
        // Content with filma, filmas, filmu, filmā, kinoseanss, filmas seanss, spēlfilma,
        // dokumentālā filma, animācijas filma, kinofestivāls is ALWAYS Kino and NEVER Teātris!
        if ($this->isCinemaEvent($combinedText)) {
            $canonical[] = 'Kino';
            $entertainmentType = 'kino';
            return [array_values(array_unique($canonical)), $entertainmentType];
        }

        // 2. Theatre rules (AGENTS.md):
        // Only actual theatre productions or venues (Ādolfa Alunāna Jelgavas teātris, izrāde, luga, iestudējums)
        if ($this->isTheatreEvent($combinedText)) {
            $canonical[] = 'Teātris';
            $entertainmentType = $entertainmentType ?: 'theatre';
        }

        // 3. Concerts & Music
        if ($this->isMusicEvent($combinedText)) {
            $canonical[] = 'Mūzika';
            $entertainmentType = $entertainmentType ?: 'concert';
        }

        // 4. Exhibitions
        if ($this->isExhibitionEvent($combinedText)) {
            $canonical[] = 'Izstādes';
            $entertainmentType = $entertainmentType ?: 'exhibition';
        }

        // 5. Sports
        if ($this->isSportsEvent($combinedText)) {
            $canonical[] = 'Sports';
            $entertainmentType = $entertainmentType ?: 'sports';
        }

        // 6. Kids & Family
        if ($this->isKidsEvent($combinedText)) {
            $canonical[] = 'Bērniem';
            $entertainmentType = $entertainmentType ?: 'kids';
        }

        // 7. Educational & Workshops
        if ($this->isEducationEvent($combinedText)) {
            $canonical[] = 'Izglītība';
            $entertainmentType = $entertainmentType ?: 'educational';
        }

        // 8. Festivals & Celebrations
        if ($this->isFestivalEvent($combinedText)) {
            $canonical[] = 'Svētki';
            $entertainmentType = $entertainmentType ?: 'celebration';
        }

        // Fallback: If no category matched -> default to Cits (citi)
        if (empty($canonical)) {
            $canonical[] = 'Cits';
            $entertainmentType = $entertainmentType ?: 'other';
        }

        return [array_values(array_unique($canonical)), $entertainmentType];
    }

    protected function isCinemaEvent(string $text): bool
    {
        $kinoKeywords = [
            'filma', 'filmas', 'filmu', 'filmā', 'filmai',
            'kinoseanss', 'kino seanss', 'filmas seanss',
            'kinoteātr', 'spēlfilma', 'dokumentālā filma',
            'animācijas filma', 'kinofestivāls', 'filmu vakars',
            'kino vakars', 'kino:', 'kino ',
        ];

        foreach ($kinoKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return true;
            }
        }

        return false;
    }

    protected function isTheatreEvent(string $text): bool
    {
        $theatreKeywords = [
            'izrāde', 'izrādes', 'izrādi', 'izrādē',
            'teātris', 'teātra', 'teātrī',
            'iestudējums', 'luga', 'lugā',
            'uzvedums', 'uzvedumā', 'uzvedumu',
            'alunāna teātr', 'dramaturģij',
        ];

        foreach ($theatreKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return true;
            }
        }

        return false;
    }

    protected function isMusicEvent(string $text): bool
    {
        $musicKeywords = [
            'koncerts', 'koncertā', 'koncertu', 'muzicē', 'mūzika', 'mūzikas',
            'dziesma', 'dziesmas', 'orķestris', 'orķestra', 'koris', 'kora',
            'ansamblis', 'dziedātāj', 'bigbends', 'grupa', 'kvartets',
            'kamermūzika', 'ģitāra', 'klavier',
        ];

        foreach ($musicKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return true;
            }
        }

        return false;
    }

    protected function isExhibitionEvent(string $text): bool
    {
        $exhibitionKeywords = [
            'izstāde', 'izstādes', 'izstādē', 'izstādi',
            'gleznas', 'gleznu', 'glezniecīb', 'ekspozīcija',
            'plenērs', 'mākslas darbi', 'keramika', 'fotogrāfij',
        ];

        foreach ($exhibitionKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return true;
            }
        }

        return false;
    }

    protected function isSportsEvent(string $text): bool
    {
        $sportsKeywords = [
            'sports', 'sporta', 'čempionāts', 'turnīrs', 'spēle',
            'futbols', 'futzāls', 'basketbols', 'skrējiens', 'maratons',
            'orientēšanās', 'nūjošana', 'treniņš', 'kardio', 'pilates',
            'galda teniss',
        ];

        foreach ($sportsKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return true;
            }
        }

        return false;
    }

    protected function isKidsEvent(string $text): bool
    {
        $kidsKeywords = [
            'bērniem', 'bērnu', 'ģimenei', 'ģimenes', 'jauniešiem',
            'pasaka', 'leļļu', 'rotaļas', 'multfilma',
        ];

        foreach ($kidsKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return true;
            }
        }

        return false;
    }

    protected function isEducationEvent(string $text): bool
    {
        $educationKeywords = [
            'nodarbība', 'nodarbības', 'lekcija', 'lekcijas', 'seminārs',
            'darbnīca', 'darbnīcas', 'meistarklase', 'kurss', 'mācības',
            'sarunvalodas', 'novadpētniecība', 'laboratorija',
        ];

        foreach ($educationKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return true;
            }
        }

        return false;
    }

    protected function isFestivalEvent(string $text): bool
    {
        $festivalKeywords = [
            'svētki', 'svētkos', 'svētku', 'svinības', 'festivāls',
            'gadadiena', 'gadadienai', 'gājiens', 'lāpu gājiens', 'egles iedegšana',
            'ugunskurs', 'salūts', 'gadumija', 'sveču', 'sveces', 'gaismas projekcija',
        ];

        foreach ($festivalKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        return self::BASE_URL . '/' . ltrim($url, '/');
    }
}
