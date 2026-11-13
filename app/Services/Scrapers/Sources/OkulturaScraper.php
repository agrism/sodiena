<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class OkulturaScraper extends BaseScraper
{
    public const BASE_URL = 'https://www.okultura.lv';
    public const EVENTS_PATH = '/lv/pasakumi';
    public const DEFAULT_IMAGE = 'https://www.okultura.lv/images/userfiles/svg/default.svg';

    /**
     * Known venue metadata mapping
     */
    protected array $venueMap = [
        'ogres novada kultūras centra lielā zāle' => [
            'name' => 'Ogres novada Kultūras centrs, Lielā zāle',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Brīvības iela 15, Ogre, LV-5001',
            'place_type' => 'cultural_center',
        ],
        'ogres novada kultūras centra mazā zāle' => [
            'name' => 'Ogres novada Kultūras centrs, Mazā zāle',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Brīvības iela 15, Ogre, LV-5001',
            'place_type' => 'cultural_center',
        ],
        'ogres novada kultūras centra radošā telpa' => [
            'name' => 'Ogres novada Kultūras centrs, Radošā telpa',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Brīvības iela 15, Ogre, LV-5001',
            'place_type' => 'cultural_center',
        ],
        'ogres novada kultūras centra deju zāle' => [
            'name' => 'Ogres novada Kultūras centrs, Deju zāle',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Brīvības iela 15, Ogre, LV-5001',
            'place_type' => 'cultural_center',
        ],
        'ogres novada kultūras centrs' => [
            'name' => 'Ogres novada Kultūras centrs',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Brīvības iela 15, Ogre, LV-5001',
            'place_type' => 'cultural_center',
        ],
        'ogres pilsētvide' => [
            'name' => 'Ogres pilsētvide',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Brīvības iela, Ogre, LV-5001',
            'place_type' => 'public_space',
        ],
        'ogres pilsētas skvērs' => [
            'name' => 'Ogres pilsētas skvērs',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Brīvības ielas un Bērzu alejas krustojums, Ogre, LV-5001',
            'place_type' => 'park',
        ],
        'ogres novada pašvaldības domes ēka' => [
            'name' => 'Ogres novada pašvaldības domes ēka',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Brīvības iela 33, Ogre, LV-5001',
            'place_type' => 'municipal',
        ],
        'ogresgala tautas nams' => [
            'name' => 'Ogresgala Tautas nams',
            'city' => 'Ogresgals',
            'region' => 'Vidzeme',
            'address' => 'Bumbieru iela 9, Ogresgals, Ogres nov., LV-5041',
            'place_type' => 'cultural_center',
        ],
        'pie ogresgala tautas nama' => [
            'name' => 'Pie Ogresgala Tautas nama',
            'city' => 'Ogresgals',
            'region' => 'Vidzeme',
            'address' => 'Bumbieru iela 9, Ogresgals, Ogres nov., LV-5041',
            'place_type' => 'cultural_center',
        ],
        'ciemupes tautas nams' => [
            'name' => 'Ciemupes Tautas nams',
            'city' => 'Ciemupe',
            'region' => 'Vidzeme',
            'address' => 'Liepu gatve 12, Ciemupe, Ogres nov., LV-5030',
            'place_type' => 'cultural_center',
        ],
    ];

    public function getSlug(): string
    {
        return 'okultura';
    }

    public function getName(): string
    {
        return 'Ogres novada Kultūras centrs';
    }

    public function fetchHtml(string $url): string
    {
        $response = $this->httpClient->get($url);
        return (string) $response->getBody();
    }

    /**
     * Scrape events from okultura.lv
     */
    public function scrape(Source $source): Collection
    {
        $events = collect();
        $url = self::BASE_URL . self::EVENTS_PATH;

        Log::info("OkulturaScraper: Fetching main events page {$url}");

        try {
            $crawler = $this->fetchCrawler($url);
        } catch (\Throwable $e) {
            Log::error("OkulturaScraper: Failed to fetch {$url}: " . $e->getMessage());
            return $events;
        }

        if (!$crawler) {
            Log::warning("OkulturaScraper: Empty crawler returned from {$url}");
            return $events;
        }

        $items = $crawler->filter('.event-list-item');

        Log::info("OkulturaScraper: Found {$items->count()} event items on list page");

        $items->each(function (Crawler $node) use ($events) {
            try {
                $dto = $this->parseEventItem($node);
                if ($dto) {
                    $events->push($dto);
                }
            } catch (\Throwable $e) {
                Log::warning("OkulturaScraper: Error parsing event item: " . $e->getMessage(), [
                    'html_preview' => mb_substr($node->text(), 0, 150),
                    'exception' => $e,
                ]);
            }
        });

        Log::info("OkulturaScraper: Successfully extracted {$events->count()} events");

        return $events;
    }

    /**
     * Parse an event item card from okultura.lv
     */
    public function parseEventItem(Crawler $node): ?ScrapedEventDTO
    {
        // 1. External ID & Title
        $rawId = $node->attr('id');
        $id = $rawId ? preg_replace('/^event-/', '', $rawId) : null;

        $titleNode = $node->filter('h2 a');
        if (!$titleNode->count()) {
            return null;
        }

        $title = trim(preg_replace('/\s+/', ' ', $titleNode->text()));
        if (empty($title)) {
            return null;
        }

        $relativeUrl = $titleNode->attr('href');
        $eventUrl = $relativeUrl ? (str_starts_with($relativeUrl, 'http') ? $relativeUrl : self::BASE_URL . $relativeUrl) : self::BASE_URL . self::EVENTS_PATH;

        if (!$id && preg_match('/-(\d+)\/?$/', $eventUrl, $m)) {
            $id = $m[1];
        }
        $eventId = $id ?: md5($eventUrl . $title);
        $sourceExternalId = "okultura-{$eventId}";

        // 2. Date and Time Extraction
        $dmNode = $node->filter('.event-day-month');
        $dmText = $dmNode->count() ? trim($dmNode->text()) : '';

        $timeNode = $node->filter('.event-time');
        $timeText = $timeNode->count() ? trim($timeNode->text()) : '';

        // 3. Clean description and metadata extraction
        $descrNode = $node->filter('.event-more-descr');
        $descrHtml = $descrNode->count() ? $descrNode->html() : '';

        $details = $this->extractDetailsMetadata($node);
        $cleanDescription = $this->extractCleanDescription($descrHtml);
        $shortDescription = mb_strlen($cleanDescription) <= 220 ? $cleanDescription : Str::limit($cleanDescription, 160);

        // 4. Postponed event detection (override date if event moved)
        $postponedDate = $this->detectPostponedDate($cleanDescription, $timeText);
        $today = Carbon::today();

        if ($postponedDate) {
            $startAt = $postponedDate;
        } elseif (preg_match('/(\d{1,2})\.(\d{1,2})/', $dmText, $dm)) {
            $day = (int) $dm[1];
            $month = (int) $dm[2];

            // Year rollover calculation
            $year = ($month < $today->month) ? ($today->year + 1) : $today->year;

            $hour = 19;
            $min = 0;
            if (preg_match('/(\d{1,2})[\.:](\d{2})/', $timeText, $tm)) {
                $hour = (int) $tm[1];
                $min = (int) $tm[2];
            }

            try {
                $startAt = Carbon::createSafe($year, $month, $day, $hour, $min, 0, 'Europe/Riga');
            } catch (\Throwable $e) {
                Log::warning("OkulturaScraper: Invalid date components {$year}-{$month}-{$day}: " . $e->getMessage());
                return null;
            }
        } else {
            Log::warning("OkulturaScraper: Could not parse date from '{$dmText}' for event {$title}");
            return null;
        }

        // AGENTS.md Rule: No past events modification / skip past events
        if ($startAt < $today) {
            return null;
        }

        // 5. Image Extraction (High-res gallery image preferred)
        $imageUrl = null;
        $galleryNode = $node->filter('.event-list-image a.gallery-item');
        if ($galleryNode->count()) {
            $href = $galleryNode->attr('href');
            if ($href && !str_contains($href, 'default.svg')) {
                $imageUrl = $this->normalizeImageUrl($href);
            }
        }

        if (!$imageUrl) {
            $imgNode = $node->filter('.event-list-image img');
            if ($imgNode->count()) {
                $src = $imgNode->attr('data-src') ?: $imgNode->attr('src');
                if ($src && !str_contains($src, 'default.svg')) {
                    $imageUrl = $this->normalizeImageUrl($src);
                }
            }
        }

        // 6. Venue, Address, City resolution
        $rawVenue = $details['Norises vieta'] ?? '';
        $rawAddress = $details['Adrese'] ?? '';
        $venueData = $this->resolveVenueAndAddress($rawVenue, $rawAddress, $title);

        // 7. Pricing resolution
        $rawPrice = $details['Biļešu cenas'] ?? ($details['Biļetes'] ?? null);
        [$priceMin, $priceMax, $isFree] = $this->parsePrice($rawPrice, $cleanDescription);

        // 8. Ticket URL resolution
        $ticketUrl = $this->extractTicketUrl($node);

        // 9. Categories & Entertainment Type per strict AGENTS.md rules
        $categoryNames = $this->resolveCategories($title, $cleanDescription, $venueData['name']);
        $entertainmentType = $this->resolveEntertainmentType($title, $categoryNames);

        return new ScrapedEventDTO(
            title: $title,
            startAt: $startAt,
            endAt: null,
            description: $cleanDescription,
            shortDescription: $shortDescription,
            venueName: $venueData['name'],
            city: $venueData['city'],
            region: $venueData['region'],
            address: $venueData['address'],
            placeType: $venueData['place_type'],
            categoryNames: $categoryNames,
            entertainmentType: $entertainmentType,
            isFree: $isFree,
            priceMin: $priceMin,
            priceMax: $priceMax,
            currency: 'EUR',
            ticketUrl: $ticketUrl,
            imageUrl: $imageUrl,
            sourceUrl: $eventUrl,
            sourceExternalId: $sourceExternalId,
            locale: 'lv',
            rawData: [
                'event_id' => $eventId,
                'source' => 'okultura.lv',
                'raw_venue' => $rawVenue,
                'raw_address' => $rawAddress,
                'raw_price' => $rawPrice,
                'is_postponed' => (bool) $postponedDate,
            ]
        );
    }

    /**
     * Extract structured details from .event-details ul.contacts li
     */
    public function extractDetailsMetadata(Crawler $node): array
    {
        $details = [];
        $node->filter('.event-details ul.contacts li')->each(function (Crawler $li) use (&$details) {
            $lblNode = $li->filter('label');
            if ($lblNode->count()) {
                $label = trim($lblNode->text());
                $val = trim(str_replace($label, '', $li->text()));
                $val = preg_replace('/\s+/', ' ', $val);
                $details[$label] = $val;
            }
        });

        return $details;
    }

    /**
     * Clean HTML description by stripping out event-details, share buttons, and iframes
     */
    public function extractCleanDescription(string $html): string
    {
        if (empty(trim($html))) {
            return '';
        }

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);
        foreach ($xpath->query('//div[contains(@class,"event-details")] | //div[contains(@class,"share-btn")] | //iframe') as $el) {
            $el->parentNode->removeChild($el);
        }

        $cleanHtml = trim($doc->saveHTML());
        $cleanText = html_entity_decode(strip_tags($cleanHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cleanText = preg_replace("/\r\n|\r/", "\n", $cleanText);
        $cleanText = preg_replace("/\n\s*\n\s*\n+/", "\n\n", $cleanText);

        return trim($cleanText);
    }

    /**
     * Detect postponed event date from description text
     */
    public function detectPostponedDate(string $text, ?string $fallbackTimeText = null): ?Carbon
    {
        $months = [
            'janv' => 1, 'febru' => 2, 'mart' => 3, 'aprīl' => 4, 'april' => 4,
            'maij' => 5, 'jūnij' => 6, 'junij' => 6, 'jūlij' => 7, 'julij' => 7,
            'august' => 8, 'septembr' => 9, 'oktobr' => 10, 'novembr' => 11, 'decembr' => 12,
        ];

        // Format 1: pārcelts uz 2027. gada 5. maiju [plkst. 19.00]
        if (preg_match('/pārcelts\s+uz\s+(\d{4})\.\s*gada\s*(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:\s+plkst\.?\s*(\d{1,2})[\.:](\d{2}))?/ui', $text, $m)) {
            $year = (int) $m[1];
            $day = (int) $m[2];
            $monthStr = mb_strtolower($m[3], 'UTF-8');
            $month = null;
            foreach ($months as $prefix => $num) {
                if (str_starts_with($monthStr, $prefix)) {
                    $month = $num;
                    break;
                }
            }

            if ($month) {
                $hour = isset($m[4]) ? (int) $m[4] : $this->parseHour($fallbackTimeText);
                $min = isset($m[5]) ? (int) $m[5] : $this->parseMinute($fallbackTimeText);

                try {
                    return Carbon::createSafe($year, $month, $day, $hour, $min, 0, 'Europe/Riga');
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        // Format 2: pārcelts uz DD.MM.YYYY
        if (preg_match('/pārcelts\s+uz\s+(\d{1,2})\.(\d{1,2})\.(\d{4})(?:\s+plkst\.?\s*(\d{1,2})[\.:](\d{2}))?/ui', $text, $m)) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];
            $hour = isset($m[4]) ? (int) $m[4] : $this->parseHour($fallbackTimeText);
            $min = isset($m[5]) ? (int) $m[5] : $this->parseMinute($fallbackTimeText);

            try {
                return Carbon::createSafe($year, $month, $day, $hour, $min, 0, 'Europe/Riga');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Resolve Venue and Address metadata
     */
    public function resolveVenueAndAddress(string $venue, string $address, string $title): array
    {
        $normalizedVenue = mb_strtolower(trim($venue), 'UTF-8');

        if (isset($this->venueMap[$normalizedVenue])) {
            return $this->venueMap[$normalizedVenue];
        }

        foreach ($this->venueMap as $pattern => $data) {
            if (str_contains($normalizedVenue, $pattern) || str_contains($pattern, $normalizedVenue)) {
                return $data;
            }
        }

        // Analyze address for city
        $city = 'Ogre';
        $region = 'Vidzeme';
        $finalAddress = $address;

        if (str_contains($address, 'Ciemupe')) {
            $city = 'Ciemupe';
        } elseif (str_contains($address, 'Ogresgals')) {
            $city = 'Ogresgals';
        }

        $venueName = !empty($venue) ? $venue : 'Ogres novada Kultūras centrs';
        if (empty($finalAddress)) {
            $finalAddress = "{$venueName}, {$city}, Ogres novads";
        }

        return [
            'name' => $venueName,
            'city' => $city,
            'region' => $region,
            'address' => $finalAddress,
            'place_type' => 'cultural_center',
        ];
    }

    /**
     * Parse pricing information
     */
    public function parsePrice(?string $priceText, ?string $description = null): array
    {
        $isFree = false;
        $min = null;
        $max = null;

        $combined = ($priceText ?? '') . ' ' . ($description ?? '');

        if ($priceText && preg_match('/bezmaksas|bez\s+maksas|brivbiļet|brīvbiļet|ielūgum/ui', $priceText) && !str_contains($priceText, '€') && !str_contains($priceText, 'EUR')) {
            return [0.0, 0.0, true];
        }

        if ($priceText) {
            $numbers = [];

            // Pattern 1: Range like € 25,00 - 35,00 or 25 - 35 €
            if (preg_match('/(?:€\s*)?(\d+(?:[.,]\d{1,2})?)\s*-\s*(\d+(?:[.,]\d{1,2})?)\s*(?:€|EUR)?/u', $priceText, $m)) {
                $n1 = (float) str_replace(',', '.', $m[1]);
                $n2 = (float) str_replace(',', '.', $m[2]);
                if ($n1 > 0 && $n2 > 0) {
                    $numbers[] = $n1;
                    $numbers[] = $n2;
                }
            }

            // Pattern 2: Individual € amounts
            if (preg_match_all('/(?:€\s*(\d+(?:[.,]\d{1,2})?)|(\d+(?:[.,]\d{1,2})?)\s*(?:€|EUR))/u', $priceText, $matches)) {
                foreach (array_merge($matches[1], $matches[2]) as $numStr) {
                    if ($numStr !== '') {
                        $val = (float) str_replace(',', '.', $numStr);
                        if ($val > 0 && $val < 1000) {
                            $numbers[] = $val;
                        }
                    }
                }
            }

            if (!empty($numbers)) {
                $min = min($numbers);
                $max = max($numbers);
                $isFree = false;
            }
        }

        if ($min === null) {
            if (preg_match('/bezmaksas|bez\s+maksas|brivbiļet|brīvbiļet/ui', $combined)) {
                $isFree = true;
                $min = 0.0;
                $max = 0.0;
            }
        }

        return [$min, $max, $isFree];
    }

    /**
     * Extract direct ticketing URL
     */
    public function extractTicketUrl(Crawler $node): ?string
    {
        // 1. Direct button inside .event-details
        $btnNode = $node->filter('.event-details a.btn[href]');
        if ($btnNode->count()) {
            $url = $btnNode->attr('href');
            if ($url && preg_match('/bilesuparadize|bilesuserviss|aula\.lv|bezrindas/i', $url)) {
                return $url;
            }
        }

        // 2. Any ticketing link in card
        $ticketNode = $node->filter('a[href*="bilesuparadize.lv"], a[href*="bilesuserviss.lv"], a[href*="aula.lv"], a[href*="bezrindas.lv"]');
        if ($ticketNode->count()) {
            return $ticketNode->attr('href');
        }

        return null;
    }

    /**
     * Resolve categories adhering strictly to AGENTS.md rules
     */
    public function resolveCategories(string $title, string $description, string $venue): array
    {
        $titleLower = mb_strtolower($title, 'UTF-8');
        $text = mb_strtolower($title . ' ' . $description . ' ' . $venue, 'UTF-8');

        // National holidays / Festivities in title take priority over incidental film mentions in description
        $isHoliday = (
            str_contains($titleLower, 'lāčplēš') ||
            str_contains($titleLower, 'lacples') ||
            str_contains($titleLower, 'valsts svētk') ||
            str_contains($titleLower, 'lāpu gājiens') ||
            str_contains($titleLower, 'lapu gajiens') ||
            str_contains($titleLower, 'uguņošana') ||
            str_contains($titleLower, 'egles iedegšan')
        ) && !str_contains($titleLower, 'filma') && !str_contains($titleLower, 'spēlfilma');

        if ($isHoliday) {
            return ['Festivāli & Svētki'];
        }

        // Fairs / Markets in title
        if (str_contains($titleLower, 'tirdziņ') || str_contains($titleLower, 'gadatirg')) {
            return ['Gadatirgi & Amatniecība'];
        }

        // Rule 1: Cinema / Kino (Strict & Priority per AGENTS.md)
        $isCinema = (
            str_contains($titleLower, 'filma') ||
            str_contains($titleLower, 'spēlfilma') ||
            str_contains($titleLower, 'dokumentālā filma') ||
            str_contains($titleLower, 'kinoseans') ||
            str_contains($titleLower, 'kinofestivāl') ||
            str_contains($text, 'filmas seans') ||
            str_contains($text, 'kinoseanss') ||
            str_contains($text, 'kinoteātr') ||
            (str_contains($text, 'spēlfilma') && !str_contains($titleLower, 'koncerts'))
        );

        if ($isCinema) {
            return ['Kino'];
        }

        // Rule 2: Theaters (Strict theater productions)
        $isTheatre = (
            str_contains($text, 'ogres teātr') ||
            str_contains($text, 'teātra izrāde') ||
            str_contains($text, 'teātra iestudējum') ||
            str_contains($text, 'teātris') ||
            str_contains($text, 'izrāde') ||
            str_contains($text, 'izrade') ||
            str_contains($text, 'luga') ||
            str_contains($text, 'iestudējums') ||
            str_contains($text, 'komēdija') ||
            str_contains($text, 'komedija') ||
            str_contains($text, 'opera') ||
            str_contains($text, 'balets')
        ) && !str_contains($text, 'filma');

        if ($isTheatre) {
            return ['Teātris'];
        }

        // Rule 3: Music / Concerts
        if (
            str_contains($text, 'koncerts') ||
            str_contains($text, 'dziesma') ||
            str_contains($text, 'dziesmas') ||
            str_contains($text, 'dziesmu') ||
            str_contains($text, 'koris') ||
            str_contains($text, 'orķestr') ||
            str_contains($text, 'orkestr') ||
            str_contains($text, 'mūzika') ||
            str_contains($text, 'muzika') ||
            str_contains($text, 'balle') ||
            str_contains($text, 'ansambl') ||
            str_contains($text, 'dziedātāj') ||
            str_contains($text, 'akustiski') ||
            str_contains($text, 'bigbends')
        ) {
            return ['Mūzika'];
        }

        // Festivals / Holidays
        if (
            str_contains($text, 'svētki') ||
            str_contains($text, 'svetki') ||
            str_contains($text, 'valsts svētk') ||
            str_contains($text, 'lāčplēš') ||
            str_contains($text, 'lacples') ||
            str_contains($text, '18. novembr') ||
            str_contains($text, 'lāpu gājiens') ||
            str_contains($text, 'lapu gajiens') ||
            str_contains($text, 'svinīgais pasākums') ||
            str_contains($text, 'ziemassvētku') ||
            str_contains($text, 'jaunais gads') ||
            str_contains($text, 'egles iedegšan')
        ) {
            return ['Festivāli & Svētki'];
        }

        // Exhibitions / Workshops / Art
        if (
            str_contains($text, 'izstāde') ||
            str_contains($text, 'izstade') ||
            str_contains($text, 'ekspozīcij') ||
            str_contains($text, 'māksla') ||
            str_contains($text, 'glezn') ||
            str_contains($text, 'meistarklase') ||
            str_contains($text, 'darbnīca') ||
            str_contains($text, 'muzej')
        ) {
            return ['Māksla un izstādes'];
        }

        // Children / Family
        if (
            str_contains($text, 'bērniem') ||
            str_contains($text, 'berniem') ||
            str_contains($text, 'ģimenei') ||
            str_contains($text, 'gimenei') ||
            str_contains($text, 'leļļu') ||
            str_contains($text, 'pasaka')
        ) {
            return ['Bērniem'];
        }

        // Fairs / Markets
        if (str_contains($text, 'tirdziņš') || str_contains($text, 'tirdzins') || str_contains($text, 'gadatirgus')) {
            return ['Gadatirgi & Amatniecība'];
        }

        // Sports
        if (
            str_contains($text, 'sports') ||
            str_contains($text, 'čempionāt') ||
            str_contains($text, 'turnīrs') ||
            str_contains($text, 'skrējiens') ||
            str_contains($text, 'futbol') ||
            str_contains($text, 'basketbol')
        ) {
            return ['Sports'];
        }

        // Rule 4: Fallback to Cits
        return ['Cits'];
    }

    /**
     * Resolve entertainment type
     */
    public function resolveEntertainmentType(string $title, array $categories): string
    {
        $primary = $categories[0] ?? 'Cits';

        return match ($primary) {
            'Kino' => 'movie',
            'Teātris' => 'performance',
            'Mūzika' => 'concert',
            'Festivāli & Svētki' => 'festival',
            'Māksla un izstādes' => 'exhibition',
            'Sports' => 'sports',
            'Bērniem' => 'family',
            'Gadatirgi & Amatniecība' => 'market',
            default => 'chill',
        };
    }

    protected function normalizeImageUrl(string $url): string
    {
        // Fix double slashes e.g. https://www.okultura.lv//images/...
        $url = preg_replace('#(?<!:)//+#', '/', $url);
        if (str_starts_with($url, '/')) {
            $url = self::BASE_URL . $url;
        }

        return $url;
    }

    protected function parseHour(?string $timeText): int
    {
        if ($timeText && preg_match('/(\d{1,2})[\.:](\d{2})/', $timeText, $m)) {
            return (int) $m[1];
        }
        return 19;
    }

    protected function parseMinute(?string $timeText): int
    {
        if ($timeText && preg_match('/(\d{1,2})[\.:](\d{2})/', $timeText, $m)) {
            return (int) $m[2];
        }
        return 0;
    }
}
