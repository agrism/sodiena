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

class SiguldaTourismScraper extends BaseScraper
{
    protected array $detailCache = [];

    protected array $venueAddressMap = [
        'pils iela 10' => [
            'name' => 'Kultūras centrs "Siguldas DEVONS"',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Pils iela 10, Sigulda, LV-2150',
            'place_type' => 'cultural_center',
        ],
        'pils iela 16' => [
            'name' => 'Siguldas Jaunā pils',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Pils iela 16, Sigulda, LV-2150',
            'place_type' => 'castle',
        ],
        'šveices iela 19' => [
            'name' => 'Koncertzāle "Baltais Flīģelis"',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Šveices iela 19, Sigulda, LV-2150',
            'place_type' => 'concert_hall',
        ],
        'šveices iela 13' => [
            'name' => 'Siguldas Valsts ģimnāzija',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Šveices iela 13, Sigulda, LV-2150',
            'place_type' => 'school',
        ],
        'turaidas iela 10' => [
            'name' => 'Turaidas muzejrezervāts',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Turaidas iela 10, Sigulda, LV-2150',
            'place_type' => 'museum',
        ],
        'turaidas iela 6' => [
            'name' => 'Turaidas baznīca',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Turaidas iela 6, Sigulda, LV-2150',
            'place_type' => 'church',
        ],
        'baznīcas iela 2' => [
            'name' => 'Siguldas Romas katoļu baznīca',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Baznīcas iela 2, Sigulda, LV-2150',
            'place_type' => 'church',
        ],
        'nākotnes iela 5' => [
            'name' => 'Mālpils Kultūras centrs',
            'city' => 'Mālpils',
            'region' => 'Vidzeme',
            'address' => 'Nākotnes iela 5, Mālpils, LV-2152',
            'place_type' => 'cultural_center',
        ],
        'mālpils kultūras centrs' => [
            'name' => 'Mālpils Kultūras centrs',
            'city' => 'Mālpils',
            'region' => 'Vidzeme',
            'address' => 'Nākotnes iela 5, Mālpils, LV-2152',
            'place_type' => 'cultural_center',
        ],
        'pils iela 6, mālpils' => [
            'name' => 'Mālpils muiža',
            'city' => 'Mālpils',
            'region' => 'Vidzeme',
            'address' => 'Pils iela 6, Mālpils, LV-2152',
            'place_type' => 'manor',
        ],
        'mālpils muiža' => [
            'name' => 'Mālpils muiža',
            'city' => 'Mālpils',
            'region' => 'Vidzeme',
            'address' => 'Pils iela 6, Mālpils, LV-2152',
            'place_type' => 'manor',
        ],
        'atmodas iela 1a' => [
            'name' => 'Inčukalna Tautas nams',
            'city' => 'Inčukalns',
            'region' => 'Vidzeme',
            'address' => 'Atmodas iela 1A, Inčukalns, LV-2141',
            'place_type' => 'cultural_center',
        ],
        'atmodas iela 2' => [
            'name' => 'Inčukalna bibliotēka / Tautas nams',
            'city' => 'Inčukalns',
            'region' => 'Vidzeme',
            'address' => 'Atmodas iela 2, Inčukalns, LV-2141',
            'place_type' => 'cultural_center',
        ],
        'emiļa melngaiļa iela 2' => [
            'name' => 'Lēdurgas Kultūras nams',
            'city' => 'Lēdurga',
            'region' => 'Vidzeme',
            'address' => 'Emiļa Melngaiļa iela 2, Lēdurga, LV-4012',
            'place_type' => 'cultural_center',
        ],
        'lēdurga' => [
            'name' => 'Lēdurgas Kultūras nams',
            'city' => 'Lēdurga',
            'region' => 'Vidzeme',
            'address' => 'Emiļa Melngaiļa iela 2, Lēdurga, LV-4012',
            'place_type' => 'cultural_center',
        ],
        'siguldas iela 11' => [
            'name' => 'Mores Tautas nams',
            'city' => 'More',
            'region' => 'Vidzeme',
            'address' => 'Siguldas iela 11, More, LV-2170',
            'place_type' => 'cultural_center',
        ],
        'siguldas iela 13' => [
            'name' => 'Mores Tautas nams',
            'city' => 'More',
            'region' => 'Vidzeme',
            'address' => 'Siguldas iela 13, More, LV-2170',
            'place_type' => 'cultural_center',
        ],
        'līvkalna iela 4a' => [
            'name' => 'Krišjāņa Barona pieminekļa laukums',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Līvkalna iela 4A, Sigulda, LV-2150',
            'place_type' => 'park',
        ],
        'strēlnieku iela 2' => [
            'name' => 'Kino Lora',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Strēlnieku iela 2, Sigulda, LV-2150',
            'place_type' => 'cinema',
        ],
        'lauku iela 9' => [
            'name' => 'Siguldas Mākslu skola "Baltais Flīģelis"',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Lauku iela 9, Sigulda, LV-2150',
            'place_type' => 'school',
        ],
        'mednieku iela 3' => [
            'name' => 'Siguldas novada Jaunrades centrs',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Mednieku iela 3, Sigulda, LV-2150',
            'place_type' => 'cultural_center',
        ],
        'zinātnes iela 7b' => [
            'name' => 'Siguldas Tehnoloģiju izglītības centrs',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Zinātnes iela 7B, Sigulda, LV-2150',
            'place_type' => 'venue',
        ],
        'ausekļa iela 6' => [
            'name' => 'Siguldas dzelzceļa stacija / Laukums',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Ausekļa iela 6, Sigulda, LV-2150',
            'place_type' => 'square',
        ],
    ];

    public function getSlug(): string
    {
        return 'sigulda-tourism';
    }

    public function getName(): string
    {
        return 'Siguldas novada tūrisms';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $baseUrl = $source->url ?: 'https://tourism.sigulda.lv/events/';
        $today = Carbon::today('Europe/Riga');

        $crawler = $this->fetchCrawler($baseUrl);
        if (!$crawler) {
            Log::warning("SiguldaTourismScraper: failed to fetch base URL: {$baseUrl}");
            return $events;
        }

        $cardNodes = $crawler->filter('a.card.card-event');
        if ($cardNodes->count() === 0) {
            Log::warning("SiguldaTourismScraper: no event cards found on {$baseUrl}");
            return $events;
        }

        foreach ($cardNodes as $domElement) {
            try {
                $node = new Crawler($domElement, $baseUrl);
                $dto = $this->parseEventCard($node, $baseUrl, $today);

                if (!$dto) {
                    continue;
                }

                // Strict Scope Rule: Current and upcoming events only
                if ($dto->endAt) {
                    if ($dto->endAt->lt($today)) {
                        continue;
                    }
                } elseif ($dto->startAt->lt($today)) {
                    continue;
                }

                $events->push($dto);
            } catch (\Throwable $e) {
                Log::warning("SiguldaTourismScraper: Failed to parse event card", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $events;
    }

    /**
     * Parse card and fetch detail page
     */
    public function parseEventCard(Crawler $node, string $baseUrl, ?Carbon $today = null): ?ScrapedEventDTO
    {
        $today = $today ?: Carbon::today('Europe/Riga');
        $rawHref = trim($node->attr('href') ?? '');
        if (empty($rawHref)) {
            return null;
        }

        $eventUrl = $this->normalizeUrl($rawHref);

        $cardTitle = $node->filter('h4.card-title')->count()
            ? $this->cleanText($node->filter('h4.card-title')->text())
            : null;

        $cardDate = $node->filter('time')->count()
            ? $this->cleanText($node->filter('time')->text())
            : null;

        $cardFigure = $node->filter('figure');
        $cardImage = $cardFigure->count() ? ($cardFigure->attr('data-bg') ?: $cardFigure->attr('style')) : null;
        if ($cardImage && preg_match('/url\([\'"]?(.*?)[\'"]?\)/', $cardImage, $m)) {
            $cardImage = $m[1];
        }

        // Fetch detail page
        $details = $this->fetchEventDetails($eventUrl);

        $title = $details['title'] ?: $cardTitle;
        if (empty($title)) {
            return null;
        }

        $dateStr = $details['dateText'];
        if ($cardDate && str_contains($cardDate, '-') && (!$dateStr || !str_contains($dateStr, '-'))) {
            $dateStr = $cardDate;
        } elseif (empty($dateStr)) {
            $dateStr = $cardDate;
        }
        $timeStr = $details['timeText'];

        [$startAt, $endAt] = $this->parseDates($dateStr, $timeStr, $today);
        if (!$startAt) {
            return null;
        }

        $imageUrl = $details['imageUrl'] ?: $this->normalizeUrl($cardImage);
        $rawAddress = $details['addressText'];

        $locationMeta = $this->resolveLocationMeta($rawAddress, $title, $details['venueFromContent']);
        if ($details['latitude'] && $details['longitude']) {
            $locationMeta['latitude'] = $details['latitude'];
            $locationMeta['longitude'] = $details['longitude'];
        }

        [$isFree, $priceMin, $priceMax] = $this->parsePrice($details['priceText']);

        $categoryClasses = $details['categoryClasses'] ?? [];
        $categories = $this->resolveCategories(
            $categoryClasses,
            $title,
            $details['description'],
            $locationMeta['name']
        );

        $entertainmentType = $this->resolveEntertainmentType($categories, $title, $locationMeta['name']);

        $externalId = $this->extractExternalId($eventUrl, $details['objectId']);

        return new ScrapedEventDTO(
            title: $title,
            startAt: $startAt,
            endAt: $endAt,
            description: $details['description'],
            shortDescription: $details['shortDescription'],
            venueName: $locationMeta['name'],
            city: $locationMeta['city'],
            region: $locationMeta['region'],
            address: $locationMeta['address'],
            latitude: $locationMeta['latitude'],
            longitude: $locationMeta['longitude'],
            placeType: $locationMeta['place_type'],
            categoryNames: $categories,
            entertainmentType: $entertainmentType,
            isFree: $isFree,
            priceMin: $priceMin,
            priceMax: $priceMax,
            currency: 'EUR',
            ticketUrl: $details['ticketUrl'] ?: $eventUrl,
            imageUrl: $imageUrl,
            sourceUrl: $eventUrl,
            sourceExternalId: $externalId,
            locale: 'lv',
            rawData: [
                'card_title' => $cardTitle,
                'card_date' => $cardDate,
                'details' => $details,
            ]
        );
    }

    /**
     * Fetch detail page attributes
     */
    public function fetchEventDetails(string $url): array
    {
        if (isset($this->detailCache[$url])) {
            return $this->detailCache[$url];
        }

        $fallback = [
            'title' => null,
            'imageUrl' => null,
            'dateText' => null,
            'timeText' => null,
            'addressText' => null,
            'priceText' => null,
            'ticketUrl' => null,
            'description' => null,
            'shortDescription' => null,
            'objectId' => null,
            'latitude' => null,
            'longitude' => null,
            'venueFromContent' => null,
            'categoryClasses' => [],
        ];

        try {
            $response = $this->httpClient->get($url);
            $html = (string) $response->getBody();
            if (empty($html)) {
                $this->detailCache[$url] = $fallback;
                return $fallback;
            }

            $crawler = new Crawler($html, $url);

            // Title
            $titleNode = $crawler->filter('h1.page-title, h1');
            $title = $titleNode->count() ? $this->cleanText($titleNode->text()) : null;

            // Image
            $galleryImg = $crawler->filter('#sigulda-gallery a.media-item.main');
            $imageUrl = null;
            if ($galleryImg->count()) {
                $imageUrl = $galleryImg->attr('data-src') ?: $galleryImg->attr('href');
            }
            if (!$imageUrl) {
                $fbImg = $crawler->filter('figure.main-image img, .entry-content img');
                if ($fbImg->count()) {
                    $imageUrl = $fbImg->attr('data-src') ?: $fbImg->attr('src');
                }
            }
            $imageUrl = $this->normalizeUrl($imageUrl);

            // Date & Time
            $dateNode = $crawler->filter('section.widget.event-date time.main-date');
            $dateText = $dateNode->count() ? $this->cleanText($dateNode->text()) : null;

            $timeNode = $crawler->filter('section.widget.event-date time.second-time');
            $timeText = $timeNode->count() ? $this->cleanText($timeNode->text()) : null;

            // Address
            $addrNode = $crawler->filter('section.widget.contacts.address ul li');
            $addressText = null;
            if ($addrNode->count()) {
                $addressText = $addrNode->first()->attr('title') ?: $this->cleanText($addrNode->first()->text());
                if ($addressText === 'Saņemt norādes' && $addrNode->count() > 1) {
                    $addressText = $addrNode->eq(1)->attr('title') ?: $this->cleanText($addrNode->eq(1)->text());
                }
            }

            // Price
            $priceNode = $crawler->filter('section.widget.entry-price p');
            $priceText = $priceNode->count() ? $this->cleanText($priceNode->text()) : null;

            // Ticket URL
            $ticketNode = $crawler->filter('section.widget.reservation a');
            $ticketUrl = $ticketNode->count() ? trim($ticketNode->first()->attr('href') ?? '') : null;
            $ticketUrl = $this->normalizeUrl($ticketUrl);

            // Description
            $descNode = $crawler->filter('article.entry-content');
            $description = null;
            $shortDescription = null;
            $categoryClasses = [];
            $venueFromContent = null;

            if ($descNode->count()) {
                $article = $descNode->first();
                $classAttr = $article->attr('class') ?? '';
                if (preg_match_all('/event_category-([a-zA-Z0-9-]+)/', $classAttr, $catMatches)) {
                    $categoryClasses = $catMatches[1];
                }

                $venueLink = $article->filter('a[href*="/objects/"]');
                if ($venueLink->count()) {
                    $venueFromContent = $this->cleanText($venueLink->first()->text());
                }

                $description = $this->cleanText($article->html());
                if ($description) {
                    $firstPara = strtok($description, "\n");
                    $shortDescription = Str::limit(strip_tags($firstPara ?: $description), 160);
                }
            }

            // Extract objectId and coordinates from javascript marker if present
            $objectId = null;
            $latitude = null;
            $longitude = null;

            if (preg_match('/objectId:\s*(\d+)/', $html, $mId)) {
                $objectId = $mId[1];
            }
            if (preg_match('/lat:\s*([0-9.]+),\s*lng:\s*([0-9.]+)/', $html, $mCoord)) {
                $latitude = (float) $mCoord[1];
                $longitude = (float) $mCoord[2];
            }

            $res = [
                'title' => $title,
                'imageUrl' => $imageUrl,
                'dateText' => $dateText,
                'timeText' => $timeText,
                'addressText' => $addressText,
                'priceText' => $priceText,
                'ticketUrl' => $ticketUrl,
                'description' => $description,
                'shortDescription' => $shortDescription,
                'objectId' => $objectId,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'venueFromContent' => $venueFromContent,
                'categoryClasses' => $categoryClasses,
            ];

            $this->detailCache[$url] = $res;
            return $res;
        } catch (\Throwable $e) {
            Log::warning("SiguldaTourismScraper: detail fetch failed for {$url}: " . $e->getMessage());
            $this->detailCache[$url] = $fallback;
            return $fallback;
        }
    }

    /**
     * Parse date range or single date with year estimation
     */
    public function parseDates(?string $dateStr, ?string $timeStr, ?Carbon $today = null): array
    {
        if (empty($dateStr)) {
            return [null, null];
        }

        $today = $today ?: Carbon::today('Europe/Riga');
        $currentYear = $today->year;
        $currentMonth = $today->month;

        $startAt = null;
        $endAt = null;

        // 1. Range match: "25. Apr - 31. Dec" or "8. Jan - 10. Jan"
        if (preg_match('/(\d{1,2})\.\s*([A-Za-zāčēģīķļņšūž]+)\s*-\s*(\d{1,2})\.\s*([A-Za-zāčēģīķļņšūž]+)/u', $dateStr, $m)) {
            $sDay = (int) $m[1];
            $sMon = $this->parseMonth($m[2]);
            $eDay = (int) $m[3];
            $eMon = $this->parseMonth($m[4]);

            if ($sMon && $eMon) {
                // Determine end year
                $eYear = ($eMon < $currentMonth - 1) ? ($currentYear + 1) : $currentYear;
                // Determine start year
                $sYear = ($sMon > $eMon) ? ($eYear - 1) : $eYear;

                try {
                    $startAt = Carbon::create($sYear, $sMon, $sDay, 0, 0, 0, 'Europe/Riga');
                    $endAt = Carbon::create($eYear, $eMon, $eDay, 23, 59, 59, 'Europe/Riga');
                } catch (\Throwable $e) {
                    $startAt = null;
                    $endAt = null;
                }
            }
        }
        // 2. Single date match: "31. Oct" or "26.Oct"
        elseif (preg_match('/(\d{1,2})\.\s*([A-Za-zāčēģīķļņšūž]+)/u', $dateStr, $m)) {
            $day = (int) $m[1];
            $mon = $this->parseMonth($m[2]);

            if ($mon) {
                $year = ($mon < $currentMonth - 1) ? ($currentYear + 1) : $currentYear;
                try {
                    $startAt = Carbon::create($year, $mon, $day, 0, 0, 0, 'Europe/Riga');
                } catch (\Throwable $e) {
                    $startAt = null;
                }
            }
        }

        if (!$startAt) {
            return [null, null];
        }

        // Apply time if present
        if (!empty($timeStr)) {
            // E.g. "19.00", "17.30", "18.00 - 23.00", "14.00 – 17.00"
            if (preg_match('/(\d{1,2})[.:](\d{2})(?:\s*[-–]\s*(\d{1,2})[.:](\d{2}))?/', $timeStr, $tm)) {
                $sh = (int) $tm[1];
                $sm = (int) $tm[2];
                $startAt->setTime($sh, $sm, 0);

                if (!empty($tm[3]) && isset($tm[4])) {
                    $eh = (int) $tm[3];
                    $em = (int) $tm[4];
                    if ($endAt) {
                        $endAt->setTime($eh, $em, 0);
                    } else {
                        $candEnd = $startAt->copy()->setTime($eh, $em, 0);
                        if ($candEnd->greaterThan($startAt)) {
                            $endAt = $candEnd;
                        }
                    }
                }
            }
        }

        return [$startAt, $endAt];
    }

    /**
     * Map month string to 1-12
     */
    public function parseMonth(string $str): ?int
    {
        $s = mb_strtolower(trim($str), 'UTF-8');
        $s = mb_substr($s, 0, 3, 'UTF-8');

        $map = [
            'jan' => 1,
            'feb' => 2,
            'mar' => 3,
            'apr' => 4,
            'mai' => 5,
            'may' => 5,
            'jūn' => 6,
            'jun' => 6,
            'jūl' => 7,
            'jul' => 7,
            'aug' => 8,
            'sep' => 9,
            'okt' => 10,
            'oct' => 10,
            'nov' => 11,
            'dec' => 12,
        ];

        return $map[$s] ?? null;
    }

    /**
     * Parse price string
     */
    public function parsePrice(?string $priceText): array
    {
        if (empty($priceText)) {
            return [false, null, null];
        }

        $lower = mb_strtolower($priceText, 'UTF-8');
        if (str_contains($lower, 'bez maksas') || str_contains($lower, 'brīva') || str_contains($lower, 'free')) {
            return [true, 0.0, 0.0];
        }

        preg_match_all('/(\d+(?:[.,]\d+)?)/', $priceText, $matches);
        if (!empty($matches[1])) {
            $nums = array_map(fn ($n) => (float) str_replace(',', '.', $n), $matches[1]);
            $min = min($nums);
            $max = max($nums);
            return [$min === 0.0, $min, $max];
        }

        return [false, null, null];
    }

    /**
     * Resolve location, venue name, and city
     */
    public function resolveLocationMeta(?string $addressRaw, ?string $title = '', ?string $venueFromContent = null): array
    {
        $default = [
            'name' => $venueFromContent ?: 'Sigulda',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => $addressRaw,
            'latitude' => null,
            'longitude' => null,
            'place_type' => 'venue',
        ];

        $addrLower = mb_strtolower(trim($addressRaw ?? ''), 'UTF-8');
        $titleLower = mb_strtolower(trim($title ?? ''), 'UTF-8');

        // Check dictionary lookup
        foreach ($this->venueAddressMap as $key => $meta) {
            if (str_contains($addrLower, $key) || str_contains($titleLower, $key)) {
                return array_merge($default, $meta, [
                    'address' => $addressRaw ?: $meta['address'],
                ]);
            }
        }

        // Town heuristics within Siguldas novads
        if (str_contains($addrLower, 'inčukaln')) {
            $default['city'] = 'Inčukalns';
            $default['name'] = $venueFromContent ?: 'Inčukalna Tautas nams';
            $default['place_type'] = 'cultural_center';
        } elseif (str_contains($addrLower, 'mālpil')) {
            $default['city'] = 'Mālpils';
            $default['name'] = $venueFromContent ?: 'Mālpils Kultūras centrs';
            $default['place_type'] = 'cultural_center';
        } elseif (str_contains($addrLower, 'lēdurg')) {
            $default['city'] = 'Lēdurga';
            $default['name'] = $venueFromContent ?: 'Lēdurgas Kultūras nams';
            $default['place_type'] = 'cultural_center';
        } elseif (str_contains($addrLower, 'morē') || str_contains($addrLower, 'more')) {
            $default['city'] = 'More';
            $default['name'] = $venueFromContent ?: 'Mores Tautas nams';
            $default['place_type'] = 'cultural_center';
        } elseif (str_contains($addrLower, 'krimuld')) {
            $default['city'] = 'Krimulda';
            $default['name'] = $venueFromContent ?: 'Krimuldas muiža';
        } elseif ($venueFromContent) {
            $default['name'] = $venueFromContent;
        } elseif (!empty($addressRaw)) {
            $parts = explode(',', $addressRaw);
            $default['name'] = trim($parts[0]);
        }

        return $default;
    }

    /**
     * Categories resolution adhering strictly to AGENTS.md
     */
    public function resolveCategories(array $categoryClasses, string $title, ?string $description = '', ?string $venueName = ''): array
    {
        $cats = [];
        $titleLower = mb_strtolower($title, 'UTF-8');
        $descLower = mb_strtolower($description ?? '', 'UTF-8');
        $venueLower = mb_strtolower($venueName ?? '', 'UTF-8');
        $fullText = $titleLower . ' ' . $descLower . ' ' . $venueLower;

        // Sanitize out copy-pasted ticketing disclaimers for cinema check
        $cleanFullText = preg_replace('/biļete uz [^.\n]*filmu jāiegādājas[^\n.]*/ui', '', $fullText);

        // Strict Cinema Check (Priority Rule from AGENTS.md)
        $isCinema = (
            in_array('kino', $categoryClasses, true) ||
            str_contains($venueLower, 'kino lora') ||
            str_contains($venueLower, 'k.suns') || str_contains($venueLower, 'k suns') ||
            str_contains($cleanFullText, 'filma') || str_contains($cleanFullText, 'filmas') ||
            str_contains($cleanFullText, 'filmu') || str_contains($cleanFullText, 'filmā') ||
            str_contains($cleanFullText, 'kinoseans') || str_contains($cleanFullText, 'filmas seans') ||
            str_contains($cleanFullText, 'spēlfilma') || str_contains($cleanFullText, 'dokumentālā filma') ||
            str_contains($cleanFullText, 'animācijas filma') || str_contains($cleanFullText, 'kinofestivāl')
        );

        if ($isCinema) {
            $cats[] = 'Kino';
        }

        foreach ($categoryClasses as $c) {
            $slug = str_replace('event_category-', '', $c);
            switch ($slug) {
                case 'koncerts':
                    $cats[] = 'Mūzika';
                    break;
                case 'izstade':
                    $cats[] = 'Izstādes';
                    break;
                case 'izrade':
                    // Strict rule: Never classify cinema/film as Teātris
                    if (!$isCinema) {
                        $cats[] = 'Teātris';
                    }
                    break;
                case 'berniem':
                    $cats[] = 'Bērniem';
                    break;
                case 'sports':
                    $cats[] = 'Sports';
                    break;
                case 'meistarklases':
                    $cats[] = 'Semināri';
                    break;
                case 'baznicu-nakts':
                case 'muzeju-nakts':
                case 'siguldas-svetki':
                    $cats[] = 'Svētki';
                    break;
            }
        }

        // Direct title keywords
        if (str_contains($titleLower, 'koncert') || str_contains($titleLower, 'dziesm') || str_contains($titleLower, 'orķestr') || str_contains($titleLower, 'koris') || str_contains($titleLower, 'džez')) {
            $cats[] = 'Mūzika';
        }
        if (str_contains($titleLower, 'izstād') || str_contains($titleLower, 'ekspozīcij')) {
            $cats[] = 'Izstādes';
        }
        if (!$isCinema && (str_contains($titleLower, 'izrād') || str_contains($titleLower, 'luga') || str_contains($titleLower, 'komēdija'))) {
            $cats[] = 'Teātris';
        }
        if (str_contains($titleLower, 'kauss') || str_contains($titleLower, 'čempionāt') || str_contains($titleLower, 'skrējiens')) {
            $cats[] = 'Sports';
        }

        // If specific categories exist, remove 'Cits'
        if (count($cats) > 1 && in_array('Cits', $cats, true)) {
            $cats = array_diff($cats, ['Cits']);
        }

        if (empty($cats)) {
            $cats[] = 'Cits';
        }

        return array_values(array_unique($cats));
    }

    /**
     * Resolve entertainment type
     */
    public function resolveEntertainmentType(array $categories, string $title, ?string $venueName = ''): string
    {
        $titleLower = mb_strtolower($title, 'UTF-8');
        $venueLower = mb_strtolower($venueName ?? '', 'UTF-8');

        if (in_array('Kino', $categories, true) || str_contains($venueLower, 'kino lora') || str_contains($titleLower, 'filma') || str_contains($titleLower, 'kinoseans')) {
            return 'movie';
        }
        if (in_array('Teātris', $categories, true)) {
            return 'theatre';
        }
        if (in_array('Mūzika', $categories, true)) {
            return 'concert';
        }
        if (in_array('Izstādes', $categories, true)) {
            return 'exhibition';
        }
        if (in_array('Sports', $categories, true)) {
            return 'sports';
        }
        if (in_array('Bērniem', $categories, true)) {
            return 'kids';
        }
        if (in_array('Semināri', $categories, true)) {
            return 'workshop';
        }
        if (in_array('Svētki', $categories, true)) {
            return 'festival';
        }

        return 'event';
    }

    /**
     * Extract external identifier
     */
    public function extractExternalId(string $url, ?string $objectId = null): string
    {
        if (!empty($objectId)) {
            return 'sigulda-' . $objectId;
        }

        $path = trim(parse_url($url, PHP_URL_PATH) ?? '', '/');
        $slug = basename($path);

        return 'sigulda-' . ($slug ?: hash('xxh64', $url));
    }

    /**
     * Prepend base scheme/domain if URL is relative
     */
    protected function normalizeUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return 'https://tourism.sigulda.lv' . (str_starts_with($url, '/') ? '' : '/') . $url;
    }
}
