<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class KuldigasNovadsScraper extends BaseScraper
{
    public const BASE_URL = 'https://kalendars.kuldigasnovads.lv';
    public const CALENDAR_PATH = '/event/';

    /**
     * Map of known Kuldīgas novads venues with normalized metadata
     */
    protected array $venueAddressMap = [
        // Kuldīga pilsēta
        'kuldīgas kultūras centrs' => [
            'name' => 'Kuldīgas Kultūras centrs',
            'city' => 'Kuldīga',
            'region' => 'Kurzeme',
            'address' => 'Piltenes iela 32, Kuldīga, Kuldīgas novads, LV-3301',
            'place_type' => 'cultural_center',
        ],
        'kuldīgas kc' => [
            'name' => 'Kuldīgas Kultūras centrs',
            'city' => 'Kuldīga',
            'region' => 'Kurzeme',
            'address' => 'Piltenes iela 32, Kuldīga, Kuldīgas novads, LV-3301',
            'place_type' => 'cultural_center',
        ],
        'kuldīgas mākslas nams' => [
            'name' => 'Kuldīgas Mākslas nams',
            'city' => 'Kuldīga',
            'region' => 'Kurzeme',
            'address' => '1905. gada iela 6, Kuldīga, Kuldīgas novads, LV-3301',
            'place_type' => 'museum',
        ],
        'kuldīgas novada muzejs' => [
            'name' => 'Kuldīgas novada muzejs',
            'city' => 'Kuldīga',
            'region' => 'Kurzeme',
            'address' => 'Pils iela 5, Kuldīga, Kuldīgas novads, LV-3301',
            'place_type' => 'museum',
        ],
        'kuldīgas muzejs' => [
            'name' => 'Kuldīgas novada muzejs',
            'city' => 'Kuldīga',
            'region' => 'Kurzeme',
            'address' => 'Pils iela 5, Kuldīga, Kuldīgas novads, LV-3301',
            'place_type' => 'museum',
        ],
        'kuldīgas galvenā bibliotēka' => [
            'name' => 'Kuldīgas Galvenā bibliotēka',
            'city' => 'Kuldīga',
            'region' => 'Kurzeme',
            'address' => '1905. gada iela 6, Kuldīga, Kuldīgas novads, LV-3301',
            'place_type' => 'library',
        ],
        'kuldīgas adatu fabrika' => [
            'name' => 'Kuldīgas Adatu fabrika',
            'city' => 'Kuldīga',
            'region' => 'Kurzeme',
            'address' => 'Kalpaka iela 4, Kuldīga, Kuldīgas novads, LV-3301',
            'place_type' => 'cultural_center',
        ],
        'adatu fabrika' => [
            'name' => 'Kuldīgas Adatu fabrika',
            'city' => 'Kuldīga',
            'region' => 'Kurzeme',
            'address' => 'Kalpaka iela 4, Kuldīga, Kuldīgas novads, LV-3301',
            'place_type' => 'cultural_center',
        ],
        'mākslinieku rezidences galerija' => [
            'name' => 'Mākslinieku rezidences galerija',
            'city' => 'Kuldīga',
            'region' => 'Kurzeme',
            'address' => 'Pils iela 2, Kuldīga, Kuldīgas novads, LV-3301',
            'place_type' => 'museum',
        ],
        'mākslas nams' => [
            'name' => 'Kuldīgas Mākslas nams',
            'city' => 'Kuldīga',
            'region' => 'Kurzeme',
            'address' => '1905. gada iela 6, Kuldīga, Kuldīgas novads, LV-3301',
            'place_type' => 'museum',
        ],
        'kuldīgas jauniešu māja' => [
            'name' => 'Kuldīgas Jauniešu māja',
            'city' => 'Kuldīga',
            'region' => 'Kurzeme',
            'address' => 'Jelgavas iela 26, Kuldīga, Kuldīgas novads, LV-3301',
            'place_type' => 'community_center',
        ],
        'kuldīgas estrāde' => [
            'name' => 'Kuldīgas estrāde',
            'city' => 'Kuldīga',
            'region' => 'Kurzeme',
            'address' => 'Pilsētas dārzs, Kuldīga, Kuldīgas novads, LV-3301',
            'place_type' => 'park',
        ],
        'pilsētas dārzs' => [
            'name' => 'Kuldīgas Pilsētas dārzs',
            'city' => 'Kuldīga',
            'region' => 'Kurzeme',
            'address' => 'Pilsētas laukums, Kuldīga, Kuldīgas novads, LV-3301',
            'place_type' => 'park',
        ],
        'kuldīgas sporta halle' => [
            'name' => 'Kuldīgas Sporta halle',
            'city' => 'Kuldīga',
            'region' => 'Kurzeme',
            'address' => 'Virka iela 13, Kuldīga, Kuldīgas novads, LV-3301',
            'place_type' => 'sports_facility',
        ],
        'kuldīgas vieglatlētikas manēža' => [
            'name' => 'Kuldīgas Vieglatlētikas manēža',
            'city' => 'Kuldīga',
            'region' => 'Kurzeme',
            'address' => 'Dzirnavu iela 13, Kuldīga, Kuldīgas novads, LV-3301',
            'place_type' => 'sports_facility',
        ],
        'kuldīgas sv. katrīnas baznīca' => [
            'name' => 'Kuldīgas Sv. Katrīnas evaņģēliski luteriskā baznīca',
            'city' => 'Kuldīga',
            'region' => 'Kurzeme',
            'address' => 'Baznīcas iela 31, Kuldīga, Kuldīgas novads, LV-3301',
            'place_type' => 'religious',
        ],
        'kuldīgas sv. annas baznīca' => [
            'name' => 'Kuldīgas Sv. Annas evaņģēliski luteriskā baznīca',
            'city' => 'Kuldīga',
            'region' => 'Kurzeme',
            'address' => 'Dzirnavu iela 12, Kuldīga, Kuldīgas novads, LV-3301',
            'place_type' => 'religious',
        ],
        'ventas rumba' => [
            'name' => 'Ventas rumba',
            'city' => 'Kuldīga',
            'region' => 'Kurzeme',
            'address' => 'Riežupes iela, Kuldīga, Kuldīgas novads, LV-3301',
            'place_type' => 'nature',
        ],

        // Skrunda
        'skrundas kultūras nams' => [
            'name' => 'Skrundas Kultūras nams',
            'city' => 'Skrunda',
            'region' => 'Kurzeme',
            'address' => 'Lielā iela 1, Skrunda, Kuldīgas novads, LV-3330',
            'place_type' => 'cultural_center',
        ],
        'skrundas kn' => [
            'name' => 'Skrundas Kultūras nams',
            'city' => 'Skrunda',
            'region' => 'Kurzeme',
            'address' => 'Lielā iela 1, Skrunda, Kuldīgas novads, LV-3330',
            'place_type' => 'cultural_center',
        ],
        'skrundas bibliotēka' => [
            'name' => 'Skrundas bibliotēka',
            'city' => 'Skrunda',
            'region' => 'Kurzeme',
            'address' => 'Rīgas iela 11, Skrunda, Kuldīgas novads, LV-3330',
            'place_type' => 'library',
        ],

        // Alsunga
        'alsungas kultūras nams' => [
            'name' => 'Alsungas Kultūras nams',
            'city' => 'Alsunga',
            'region' => 'Kurzeme',
            'address' => 'Ziedu iela 2, Alsunga, Alsungas pagasts, Kuldīgas novads, LV-3306',
            'place_type' => 'cultural_center',
        ],
        'alsungas kn' => [
            'name' => 'Alsungas Kultūras nams',
            'city' => 'Alsunga',
            'region' => 'Kurzeme',
            'address' => 'Ziedu iela 2, Alsunga, Alsungas pagasts, Kuldīgas novads, LV-3306',
            'place_type' => 'cultural_center',
        ],
        'alsungas pils' => [
            'name' => 'Alsungas Livonijas ordeņa pils',
            'city' => 'Alsunga',
            'region' => 'Kurzeme',
            'address' => 'Pils iela 1, Alsunga, Alsungas pagasts, Kuldīgas novads, LV-3306',
            'place_type' => 'historical',
        ],

        // Pagasti
        'ēdoles kultūras nams' => [
            'name' => 'Ēdoles Kultūras nams',
            'city' => 'Ēdole',
            'region' => 'Kurzeme',
            'address' => 'Dārza iela 6, Ēdole, Ēdoles pagasts, Kuldīgas novads, LV-3310',
            'place_type' => 'cultural_center',
        ],
        'ēdoles pils' => [
            'name' => 'Ēdoles pils',
            'city' => 'Ēdole',
            'region' => 'Kurzeme',
            'address' => 'Pils iela 1, Ēdole, Ēdoles pagasts, Kuldīgas novads, LV-3310',
            'place_type' => 'historical',
        ],
        'kabiles tautas nams' => [
            'name' => 'Kabiles Tautas nams',
            'city' => 'Kabile',
            'region' => 'Kurzeme',
            'address' => 'Kabile, Kabiles pagasts, Kuldīgas novads, LV-3314',
            'place_type' => 'cultural_center',
        ],
        'pelču tautas nams' => [
            'name' => 'Pelču Tautas nams',
            'city' => 'Pelči',
            'region' => 'Kurzeme',
            'address' => 'Pelči, Pelču pagasts, Kuldīgas novads, LV-3322',
            'place_type' => 'cultural_center',
        ],
        'pelču pils' => [
            'name' => 'Pelču pils',
            'city' => 'Pelči',
            'region' => 'Kurzeme',
            'address' => 'Pelči, Pelču pagasts, Kuldīgas novads, LV-3322',
            'place_type' => 'historical',
        ],
        'rendas kultūras nams' => [
            'name' => 'Rendas Kultūras nams',
            'city' => 'Renda',
            'region' => 'Kurzeme',
            'address' => 'Renda, Rendas pagasts, Kuldīgas novads, LV-3319',
            'place_type' => 'cultural_center',
        ],
        'snēpeles tautas nams' => [
            'name' => 'Snēpeles Tautas nams',
            'city' => 'Snēpele',
            'region' => 'Kurzeme',
            'address' => 'Snēpele, Snēpeles pagasts, Kuldīgas novads, LV-3328',
            'place_type' => 'cultural_center',
        ],
        'snēpeles muiža' => [
            'name' => 'Snēpeles muiža',
            'city' => 'Snēpele',
            'region' => 'Kurzeme',
            'address' => 'Snēpele, Snēpeles pagasts, Kuldīgas novads, LV-3328',
            'place_type' => 'historical',
        ],
        'turlavas kultūras nams' => [
            'name' => 'Turlavas Kultūras nams',
            'city' => 'Turlava',
            'region' => 'Kurzeme',
            'address' => 'Gundegas, Turlava, Turlavas pagasts, Kuldīgas novads, LV-3329',
            'place_type' => 'cultural_center',
        ],
        'vārmes sporta halle' => [
            'name' => 'Vārmes sporta halle',
            'city' => 'Vārme',
            'region' => 'Kurzeme',
            'address' => 'Vārme, Vārmes pagasts, Kuldīgas novads, LV-3333',
            'place_type' => 'sports_facility',
        ],
        'vārmes saieta nams' => [
            'name' => 'Vārmes saieta nams',
            'city' => 'Vārme',
            'region' => 'Kurzeme',
            'address' => 'Vārme, Vārmes pagasts, Kuldīgas novads, LV-3333',
            'place_type' => 'cultural_center',
        ],
        'padures muiža' => [
            'name' => 'Padures muiža',
            'city' => 'Padure',
            'region' => 'Kurzeme',
            'address' => 'Padures muiža, Padures pagasts, Kuldīgas novads, LV-3321',
            'place_type' => 'historical',
        ],
        'padures tautas nams' => [
            'name' => 'Padures Tautas nams',
            'city' => 'Padure',
            'region' => 'Kurzeme',
            'address' => 'Padure, Padures pagasts, Kuldīgas novads, LV-3321',
            'place_type' => 'cultural_center',
        ],
    ];

    public function getSlug(): string
    {
        return 'kuldigas-novads';
    }

    public function getName(): string
    {
        return 'Kuldīgas novada pašvaldība';
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

            $items = $crawler->filter('.ovaev-content');
            if ($items->count() === 0) {
                break;
            }

            $allPageEventsPast = true;

            $items->each(function (Crawler $node) use (&$events, &$seenExternalIds, &$allPageEventsPast, $today) {
                $titleNode = $node->filter('.event_title a, .event_title');
                $title = $this->cleanText($titleNode->count() ? $titleNode->first()->text() : '');
                if (empty($title)) {
                    return;
                }

                // Link to event
                $linkNode = $node->filter('.event_title a');
                $relLink = $linkNode->count() ? $linkNode->first()->attr('href') : '';
                $cleanUrl = $this->normalizeUrl($relLink);

                $slugPart = $cleanUrl ? basename(parse_url($cleanUrl, PHP_URL_PATH)) : Str::slug($title);
                $externalId = 'kuldigasnovads-' . $slugPart;

                if (isset($seenExternalIds[$externalId])) {
                    return;
                }

                // Extract Categories from card
                $categories = [];
                $node->filter('.post_cat a, .event_type')
                    ->each(function (Crawler $c) use (&$categories) {
                        $catText = trim($c->text());
                        if (!empty($catText)) {
                            $categories[] = $catText;
                        }
                    });

                // Exclude administrative meetings & council sessions
                if ($this->isExcludedEvent($categories, $title)) {
                    return;
                }

                // Fetch detail page
                $detailData = $cleanUrl ? $this->fetchDetailPage($cleanUrl) : [];

                // Dates & times: prefer detail page, fallback to card
                $dateStr = $detailData['dateStr'] ?? '';
                if (empty($dateStr)) {
                    $cardDate = $node->filter('.date-event .date')->count() ? trim($node->filter('.date-event .date')->text()) : '';
                    $cardMonth = $node->filter('.date-event .month')->count() ? trim($node->filter('.date-event .month')->text()) : '';
                    $cardYear = $node->filter('.date-event .year')->count() ? trim($node->filter('.date-event .year')->text()) : '';
                    $timeDate = $node->filter('.time.equal-date')->count() ? trim($node->filter('.time.equal-date')->text()) : '';
                    
                    if (!empty($timeDate) && str_contains($timeDate, '.')) {
                        $dateStr = $timeDate;
                    } elseif (!empty($cardDate) && !empty($cardMonth)) {
                        $dateStr = "{$cardDate} {$cardMonth} {$cardYear}";
                    }
                }

                $timeStr = $detailData['timeStr'] ?? '';

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

                // Description: full text from detail page
                $description = !empty($detailData['description']) ? $detailData['description'] : $title;

                // Categories: combine card & detail categories
                if (!empty($detailData['categories'])) {
                    $categories = array_values(array_unique(array_merge($categories, $detailData['categories'])));
                }

                // Location resolution
                $cardVenue = $node->filter('.venue .number')->count() ? $this->cleanText($node->filter('.venue .number')->text()) : '';
                $detailVenue = $detailData['venue'] ?? '';
                $venueRaw = !empty($detailVenue) ? $detailVenue : $cardVenue;
                $addressRaw = $detailData['address'] ?? '';
                $latRaw = $detailData['lat'] ?? null;
                $lngRaw = $detailData['lng'] ?? null;

                $venueInfo = $this->resolveLocation($venueRaw, $addressRaw, $title, $description, $latRaw, $lngRaw);

                // Categories & Entertainment Type strictly following AGENTS.md rules
                $canonicalCategories = $this->resolveCategories($categories, $title, $description, $venueInfo['name']);
                $entertainmentType = $this->resolveEntertainmentType($canonicalCategories, $title, $description);

                // Price & Free check
                $priceData = $this->parsePrice($detailData['priceStr'] ?? '', $description);

                // Image
                $imageUrl = $detailData['imageUrl'] ?? null;
                if (!$imageUrl && $node->filter('.event-thumbnail img')->count()) {
                    $imageUrl = $node->filter('.event-thumbnail img')->attr('src');
                }

                // Ticket URL
                $ticketUrl = $detailData['ticketUrl'] ?? null;
                if (!$ticketUrl) {
                    $ticketUrl = $this->findTicketUrl($description);
                }

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
                        'original_venue' => $venueRaw,
                        'original_address' => $addressRaw,
                        'price_raw' => $detailData['priceStr'] ?? null,
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
     * Fetch single event detail page and extract rich structured attributes
     */
    public function fetchDetailPage(string $url): array
    {
        $crawler = $this->fetchCrawler($url);
        if (!$crawler) {
            return [];
        }

        $dateStr = $crawler->filter('.ovaev-event-date')->count()
            ? $this->cleanText($crawler->filter('.ovaev-event-date')->text())
            : '';

        $timeStr = $crawler->filter('.ovaev-event-time')->count()
            ? $this->cleanText($crawler->filter('.ovaev-event-time')->text())
            : '';

        $venue = $crawler->filter('.ovaev-event-location')->count()
            ? $this->cleanText($crawler->filter('.ovaev-event-location')->text())
            : '';

        $priceStr = $crawler->filter('.ovaev-price-widget__text, .ovaev-price-widget')->count()
            ? $this->cleanText($crawler->filter('.ovaev-price-widget__text, .ovaev-price-widget')->first()->text())
            : '';

        $address = null;
        $lat = null;
        $lng = null;
        $locNode = $crawler->filter('[data-address]');
        if ($locNode->count()) {
            $address = $locNode->attr('data-address');
            $lat = $locNode->attr('data-lat') ? (float) $locNode->attr('data-lat') : null;
            $lng = $locNode->attr('data-lng') ? (float) $locNode->attr('data-lng') : null;
        }

        // Full description with preserved paragraphs
        $desc = '';
        $descNode = $crawler->filter('.ovaev-event-content');
        if ($descNode->count()) {
            $html = $descNode->first()->html();
            $formatted = preg_replace('/<\s*br\s*\/?>/ui', "\n", $html);
            $formatted = preg_replace('/<\/(p|div|li|h[1-6])>/ui', "\n\n", $formatted);
            $clean = strip_tags($formatted);
            $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $clean = preg_replace("/[ \t]+/u", " ", $clean);
            $clean = preg_replace("/\n{3,}/u", "\n\n", $clean);
            $desc = trim($clean);
        }

        // Ticket link
        $ticketUrl = null;
        $bookingNode = $crawler->filter('.ovaev-booking-btn a');
        if ($bookingNode->count()) {
            $ticketUrl = trim($bookingNode->first()->attr('href'));
        }

        // Featured image
        $imageUrl = null;
        $imgNode = $crawler->filter('.ovaev-event-thumbnail img');
        if ($imgNode->count()) {
            $imageUrl = $imgNode->first()->attr('src');
        }

        // Categories on detail page
        $categories = [];
        $crawler->filter('.ovaev-event-categories a')->each(function (Crawler $c) use (&$categories) {
            $txt = trim($c->text());
            if (!empty($txt)) {
                $categories[] = $txt;
            }
        });

        return [
            'dateStr' => $dateStr,
            'timeStr' => $timeStr,
            'venue' => $venue,
            'priceStr' => $priceStr,
            'address' => $address,
            'lat' => $lat,
            'lng' => $lng,
            'description' => $desc,
            'ticketUrl' => $ticketUrl,
            'imageUrl' => $imageUrl,
            'categories' => $categories,
        ];
    }

    /**
     * Check if event is an excluded municipal council or administrative session
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
     * Parse Latvian date string (e.g. "19.11.2026", "12.06.2026 - 10.01.2027", "19. novembris 2026")
     * combined with optional time string (e.g. "19:00", "10:00 - 18:00").
     */
    public function parseLatvianDates(string $dateStr, string $timeStr = ''): array
    {
        $dateStr = trim($dateStr);
        $timeStr = trim($timeStr);

        if (empty($dateStr)) {
            return [null, null, true];
        }

        // Clean icon artifacts or leading/trailing separators
        $dateStr = preg_replace('/^[^\d]+/u', '', $dateStr);

        $startTime = '00:00';
        $endTime = null;
        $allDay = true;

        if (!empty($timeStr)) {
            if (preg_match('/(\d{1,2}[:.]\d{2})\s*(?:-|līdz)\s*(\d{1,2}[:.]\d{2})/ui', $timeStr, $tm)) {
                $startTime = str_replace('.', ':', $tm[1]);
                $endTime = str_replace('.', ':', $tm[2]);
                $allDay = false;
            } elseif (preg_match('/(\d{1,2}[:.]\d{2})/ui', $timeStr, $tm)) {
                $startTime = str_replace('.', ':', $tm[1]);
                $allDay = false;
            }
        }

        // Case 1: Range "12.06.2026 - 10.01.2027" or "12.06.2026. - 10.01.2027."
        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})\.?\s*(?:-|–|līdz)\s*(\d{1,2})\.(\d{1,2})\.(\d{4})\.?/ui', $dateStr, $m)) {
            try {
                $startAt = Carbon::createFromFormat('d.m.Y H:i', sprintf('%02d.%02d.%04d %s', $m[1], $m[2], $m[3], $startTime), 'Europe/Riga');
                $endAtTime = $endTime ?: '23:59';
                $endAt = Carbon::createFromFormat('d.m.Y H:i', sprintf('%02d.%02d.%04d %s', $m[4], $m[5], $m[6], $endAtTime), 'Europe/Riga');
                return [$startAt, $endAt, $allDay];
            } catch (\Throwable $e) {
                // fallback
            }
        }

        // Case 2: Single date "19.11.2026" or "19.11.2026."
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

        // Case 3: Latvian month name "19. novembris 2026"
        $months = [
            'janvāris' => 1, 'janvārī' => 1, 'janv' => 1,
            'februāris' => 2, 'februārī' => 2, 'febr' => 2,
            'marts' => 3, 'martā' => 3,
            'aprīlis' => 4, 'aprīlī' => 4, 'apr' => 4,
            'maijs' => 5, 'maijā' => 5,
            'jūnijs' => 6, 'jūnijā' => 6, 'jūn' => 6,
            'jūlijs' => 7, 'jūlijā' => 7, 'jūl' => 7,
            'augusts' => 8, 'augustā' => 8, 'aug' => 8,
            'septembris' => 9, 'septembrī' => 9, 'sept' => 9,
            'oktobris' => 10, 'oktobrī' => 10, 'okt' => 10,
            'novembris' => 11, 'novembrī' => 11, 'nov' => 11,
            'decembris' => 12, 'decembrī' => 12, 'dec' => 12,
        ];

        if (preg_match('/(\d{1,2})\.?\s+([a-zēūīāšģķļžčņ]+)\s+(\d{4})/ui', mb_strtolower($dateStr, 'UTF-8'), $m)) {
            $monthNum = $months[$m[2]] ?? null;
            if ($monthNum) {
                try {
                    $startAt = Carbon::createFromDate((int)$m[3], $monthNum, (int)$m[1], 'Europe/Riga')->setTimeFromTimeString($startTime);
                    $endAt = null;
                    if ($endTime) {
                        $endAt = Carbon::createFromDate((int)$m[3], $monthNum, (int)$m[1], 'Europe/Riga')->setTimeFromTimeString($endTime);
                    } elseif (!$allDay) {
                        $endAt = $startAt->copy()->addHours(2);
                    }
                    return [$startAt, $endAt, $allDay];
                } catch (\Throwable $e) {
                    // fallback
                }
            }
        }

        return [null, null, true];
    }

    /**
     * Resolve location, city and address in Kuldīgas novads
     */
    public function resolveLocation(string $venueRaw, string $addressRaw, string $title, string $description, ?float $lat = null, ?float $lng = null): array
    {
        $venueNormalized = mb_strtolower(trim($venueRaw), 'UTF-8');
        $fullText = mb_strtolower($venueRaw . ' ' . $addressRaw . ' ' . $title . ' ' . $description, 'UTF-8');

        // 1. Check known venue address map
        foreach ($this->venueAddressMap as $key => $data) {
            if (str_contains($venueNormalized, $key) || str_contains($fullText, $key)) {
                return [
                    'name' => $data['name'],
                    'city' => $data['city'],
                    'region' => $data['region'],
                    'address' => $addressRaw ?: $data['address'],
                    'place_type' => $data['place_type'],
                    'latitude' => $lat,
                    'longitude' => $lng,
                ];
            }
        }

        // 2. Identify city / parish from text
        $city = 'Kuldīga';
        $parishes = [
            'skrunda' => 'Skrunda',
            'alsunga' => 'Alsunga',
            'ēdole' => 'Ēdole',
            'edole' => 'Ēdole',
            'kabile' => 'Kabile',
            'pelči' => 'Pelči',
            'pelci' => 'Pelči',
            'renda' => 'Renda',
            'snēpele' => 'Snēpele',
            'snepele' => 'Snēpele',
            'turlava' => 'Turlava',
            'vārme' => 'Vārme',
            'varme' => 'Vārme',
            'laidi' => 'Laidi',
            'padure' => 'Padure',
            'kurmāle' => 'Kurmāle',
            'kurmale' => 'Kurmāle',
            'gudenieki' => 'Gudenieki',
            'nīkrāce' => 'Nīkrāce',
            'nikrace' => 'Nīkrāce',
            'raņķi' => 'Raņķi',
            'ranki' => 'Raņķi',
            'rudbārži' => 'Rudbārži',
            'rudbarzi' => 'Rudbārži',
            'īvande' => 'Īvande',
            'ivande' => 'Īvande',
            'rumba' => 'Rumbas pagasts',
        ];

        foreach ($parishes as $search => $parishCity) {
            if (str_contains($fullText, $search)) {
                $city = $parishCity;
                break;
            }
        }

        $venueName = !empty($venueRaw) ? $venueRaw : ($city !== 'Kuldīga' ? "{$city} kultūras centrs" : 'Kuldīga');
        $address = !empty($addressRaw) ? $addressRaw : "{$city}, Kuldīgas novads, Latvija";

        $placeType = 'venue';
        if (str_contains($venueNormalized, 'baznīc')) {
            $placeType = 'religious';
        } elseif (str_contains($venueNormalized, 'muzej') || str_contains($venueNormalized, 'mākslas nams') || str_contains($venueNormalized, 'galerij')) {
            $placeType = 'museum';
        } elseif (str_contains($venueNormalized, 'bibliotēk')) {
            $placeType = 'library';
        } elseif (str_contains($venueNormalized, 'halle') || str_contains($venueNormalized, 'manēž') || str_contains($venueNormalized, 'stadion') || str_contains($venueNormalized, 'sports')) {
            $placeType = 'sports_facility';
        } elseif (str_contains($venueNormalized, 'parks') || str_contains($venueNormalized, 'estrād') || str_contains($venueNormalized, 'dārzs') || str_contains($venueNormalized, 'rumba')) {
            $placeType = 'park';
        } elseif (str_contains($venueNormalized, 'pils') || str_contains($venueNormalized, 'muiž')) {
            $placeType = 'historical';
        } elseif (str_contains($venueNormalized, 'centrs') || str_contains($venueNormalized, 'nams')) {
            $placeType = 'cultural_center';
        }

        return [
            'name' => $venueName,
            'city' => $city,
            'region' => 'Kurzeme',
            'address' => $address,
            'place_type' => $placeType,
            'latitude' => $lat,
            'longitude' => $lng,
        ];
    }

    /**
     * Resolve categories strictly adhering to AGENTS.md rules
     * Cinema rules: any movie event MUST be KINO and NEVER TEATRIS!
     */
    public function resolveCategories(array $rawCategories, string $title, string $description = '', string $venue = ''): array
    {
        $categories = [];
        $titleLower = mb_strtolower($title, 'UTF-8');
        $descLower = mb_strtolower($description, 'UTF-8');
        $venueLower = mb_strtolower($venue, 'UTF-8');
        $fullText = $titleLower . ' ' . $descLower . ' ' . $venueLower;

        // Strict Cinema Check (AGENTS.md)
        $isCinema = (
            str_starts_with($titleLower, 'kino:') ||
            str_starts_with($titleLower, 'kino ') ||
            str_contains($titleLower, 'kinoseans') ||
            str_contains($titleLower, 'filmas seans') ||
            str_contains($titleLower, 'spēlfilma') ||
            str_contains($titleLower, 'dokumentālā filma') ||
            str_contains($titleLower, 'animācijas filma') ||
            str_contains($titleLower, 'kinofestivāl') ||
            str_contains($fullText, 'kinoteātr') ||
            str_contains($fullText, 'kinoseanss') ||
            in_array('Kino', $rawCategories, true) ||
            in_array('kino', $rawCategories, true) ||
            (str_contains($fullText, 'filma') && !str_contains($fullText, 'filmas mūzika') && !str_contains($fullText, 'filmu mūzik'))
        );

        if ($isCinema) {
            return ['Kino'];
        }

        // Theatre productions
        $isTheatre = (
            str_contains($titleLower, 'izrāde') ||
            str_contains($titleLower, 'iestudējums') ||
            str_contains($titleLower, 'teātris') ||
            str_contains($titleLower, 'teātra') ||
            str_contains($titleLower, 'komēdija') ||
            str_contains($titleLower, 'opera') ||
            str_contains($titleLower, 'balets') ||
            str_contains($titleLower, 'luga') ||
            in_array('Teātris', $rawCategories, true) ||
            in_array('teatris', $rawCategories, true)
        );

        if ($isTheatre) {
            return ['Teātris'];
        }

        // Music / Concerts
        $isMusic = (
            str_contains($titleLower, 'koncerts') ||
            str_contains($titleLower, 'koncertprogramma') ||
            str_contains($titleLower, 'dziesm') ||
            str_contains($titleLower, 'orķestr') ||
            str_contains($titleLower, 'koris') ||
            str_contains($titleLower, 'kora') ||
            str_contains($titleLower, 'akustiskais') ||
            str_contains($titleLower, 'mūzika') ||
            str_contains($titleLower, 'grupa') ||
            str_contains($titleLower, 'balle') ||
            str_contains($titleLower, 'dancis') ||
            str_contains($titleLower, 'danči') ||
            str_contains($titleLower, 'sadziedāšanās') ||
            in_array('Mūzika', $rawCategories, true) ||
            in_array('muzika', $rawCategories, true)
        );

        if ($isMusic) {
            return ['Mūzika'];
        }

        // Exhibitions
        $isExhibition = (
            str_contains($titleLower, 'izstāde') ||
            str_contains($titleLower, 'ekspozīcija') ||
            str_contains($titleLower, 'glezn') ||
            str_contains($titleLower, 'akvareļ') ||
            str_contains($titleLower, 'keramik') ||
            str_contains($titleLower, 'fotogrāfij') ||
            str_contains($titleLower, 'mākslas darbi') ||
            in_array('Izstādes', $rawCategories, true) ||
            in_array('izstades', $rawCategories, true)
        );

        if ($isExhibition) {
            return ['Izstādes'];
        }

        // Sports
        $isSports = (
            str_contains($titleLower, 'čempionāt') ||
            str_contains($titleLower, 'turnīr') ||
            str_contains($titleLower, 'sacensīb') ||
            str_contains($titleLower, 'skrējiens') ||
            str_contains($titleLower, 'volejbol') ||
            str_contains($titleLower, 'basketbol') ||
            str_contains($titleLower, 'futbol') ||
            str_contains($titleLower, 'mačs') ||
            str_contains($titleLower, 'spēle') ||
            in_array('Sports', $rawCategories, true) ||
            in_array('sports', $rawCategories, true)
        );

        if ($isSports) {
            return ['Sports'];
        }

        // Kids / Family
        $isKids = (
            str_contains($titleLower, 'bērniem') ||
            str_contains($titleLower, 'ģimenēm') ||
            str_contains($titleLower, 'leļļu') ||
            str_contains($titleLower, 'pasaka') ||
            str_contains($titleLower, 'rotaļ') ||
            str_contains($titleLower, 'eglīte')
        );

        if ($isKids) {
            return ['Bērniem'];
        }

        // Fallback per AGENTS.md: default to Cits
        return ['Cits'];
    }

    /**
     * Resolve entertainment type
     */
    public function resolveEntertainmentType(array $categories, string $title, string $description = ''): string
    {
        if (in_array('Kino', $categories, true)) {
            return 'kino';
        }
        if (in_array('Teātris', $categories, true)) {
            return 'teatris';
        }
        if (in_array('Mūzika', $categories, true)) {
            return 'koncerts';
        }
        if (in_array('Izstādes', $categories, true)) {
            return 'izstade';
        }
        if (in_array('Sports', $categories, true)) {
            return 'sports';
        }
        if (in_array('Bērniem', $categories, true)) {
            return 'berniem';
        }

        return 'citi';
    }

    /**
     * Parse price string
     */
    public function parsePrice(string $priceStr, string $description = ''): array
    {
        $clean = trim($priceStr);
        $fullText = $clean . ' ' . $description;

        if (empty($clean) || preg_match('/\b(?:bezmaksas|brīva ieeja|ieeja bez maksas|ieeja brīva|free)\b/ui', $fullText)) {
            return [
                'is_free' => true,
                'price_min' => null,
                'price_max' => null,
            ];
        }

        // Range "5.00 - 10.00"
        if (preg_match('/(\d+(?:[.,]\d{1,2})?)\s*(?:-|līdz)\s*(\d+(?:[.,]\d{1,2})?)/ui', $clean, $m)) {
            $min = (float) str_replace(',', '.', $m[1]);
            $max = (float) str_replace(',', '.', $m[2]);
            return [
                'is_free' => $min == 0 && $max == 0,
                'price_min' => $min > 0 ? $min : null,
                'price_max' => $max > 0 ? $max : null,
            ];
        }

        // Single price "4.20" or "4,20 €"
        if (preg_match('/(\d+(?:[.,]\d{1,2})?)/ui', $clean, $m)) {
            $val = (float) str_replace(',', '.', $m[1]);
            if ($val > 0) {
                return [
                    'is_free' => false,
                    'price_min' => $val,
                    'price_max' => $val,
                ];
            }
        }

        return [
            'is_free' => false,
            'price_min' => null,
            'price_max' => null,
        ];
    }

    /**
     * Look for ticket ticketing links in text
     */
    protected function findTicketUrl(string $text): ?string
    {
        preg_match_all('/https?:\/\/[^\s\)\"\'<>]+/i', $text, $matches);
        $ticketPlatforms = [
            'bilesuparadize.lv',
            'bilesuserviss.lv',
            'bezrindas.lv',
            'ticketshop.lv',
            'fienta.com',
            'aula.lv',
            'ekase.lv',
            'passportix.eu',
        ];

        foreach ($matches[0] as $rawUrl) {
            $clean = EventIngestionService::canonicalizeTicketUrl($rawUrl) ?? rtrim($rawUrl, '?&.,;:\'\"');
            foreach ($ticketPlatforms as $platform) {
                if (str_contains($clean, $platform)) {
                    return $clean;
                }
            }
        }

        return null;
    }

    /**
     * Clean text helper
     */
    protected function cleanText(?string $text): string
    {
        if ($text === null) {
            return '';
        }
        $clean = preg_replace('/\s+/u', ' ', $text);
        return trim($clean);
    }

    /**
     * Normalize URL
     */
    protected function normalizeUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        return self::BASE_URL . (str_starts_with($url, '/') ? '' : '/') . $url;
    }
}
