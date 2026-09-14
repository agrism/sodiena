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

class BezRindasScraper extends BaseScraper
{
    protected array $cityMap = [
        'riga' => 'Rīga',
        'jurmala' => 'Jūrmala',
        'liepaja' => 'Liepāja',
        'daugavpils' => 'Daugavpils',
        'jelgava' => 'Jelgava',
        'ventspils' => 'Ventspils',
        'rezekne' => 'Rēzekne',
        'valmiera' => 'Valmiera',
        'jekabpils' => 'Jēkabpils',
        'ogre' => 'Ogre',
        'tukums' => 'Tukums',
        'cesis' => 'Cēsis',
        'salaspils' => 'Salaspils',
        'kuldiga' => 'Kuldīga',
        'olaine' => 'Olaine',
        'saldus' => 'Saldus',
        'talsi' => 'Talsi',
        'sigulda' => 'Sigulda',
        'bauska' => 'Bauska',
        'aluksne' => 'Alūksne',
        'kraslava' => 'Krāslava',
        'limbazi' => 'Limbaži',
        'ludza' => 'Ludza',
        'madona' => 'Madona',
        'aizkraukle' => 'Aizkraukle',
        'gulbene' => 'Gulbene',
        'preili' => 'Preiļi',
        'balvi' => 'Balvi',
        'smiltene' => 'Smiltene',
        'valka' => 'Valka',
        'kegums' => 'Ķegums',
        'lielvarde' => 'Lielvārde',
        'iecava' => 'Iecava',
        'balozi' => 'Baloži',
        'ikskile' => 'Ikšķile',
        'saulkrasti' => 'Saulkrasti',
        'auce' => 'Auce',
        'kandava' => 'Kandava',
        'marupe' => 'Mārupe',
        'adazi' => 'Ādaži',
        'carnikava' => 'Carnikava',
        'kekava' => 'Ķekava',
        'baldone' => 'Baldone',
        'dobele' => 'Dobele',
    ];

    public function getSlug(): string
    {
        return 'bezrindas';
    }

    public function getName(): string
    {
        return 'BezRindas.lv (Visi pasākumi)';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $listUrl = $source->url ?: 'https://www.bezrindas.lv/lv/visi-pasakumi';

        $crawler = $this->fetchCrawler($listUrl);
        if (!$crawler) {
            Log::warning("BezRindasScraper: Failed to fetch event listing from {$listUrl}");
            return $events;
        }

        // 1. Collect all event cards from the main list
        $eventCards = [];
        $crawler->filter('article.event-item')->each(function (Crawler $node) use (&$eventCards) {
            $a = $node->filter('a.event-item-content');
            if (!$a->count()) {
                return;
            }

            $url = $a->attr('href');
            if (empty($url)) {
                return;
            }

            $title = $this->cleanText($node->filter('.event-item-title, h2')->first()->text(''));
            $place = $this->cleanText($node->filter('.event-item-place')->first()->text(''));
            $dateText = $this->cleanText($node->filter('.event-item-date')->first()->text(''));

            $style = $node->filter('.event-item-image')->count() ? $node->filter('.event-item-image')->attr('style') : '';
            $img = null;
            if (!empty($style) && preg_match('/url\([\'"]?(https?:\/\/[^\'"]+)[\'"]?\)/i', $style, $m)) {
                $img = $m[1];
            }

            $eventCards[$url] = [
                'url' => $url,
                'title' => $title,
                'place' => $place,
                'dateText' => $dateText,
                'img' => $img,
            ];
        });

        Log::info("BezRindasScraper: Found " . count($eventCards) . " unique event URLs on {$listUrl}");

        // 2. Parse each event page
        foreach ($eventCards as $eventUrl => $card) {
            try {
                $pageCrawler = $this->fetchCrawler($eventUrl);
                if (!$pageCrawler) {
                    continue;
                }

                $this->parseEventPage($pageCrawler, $eventUrl, $card, $events);
            } catch (\Throwable $e) {
                Log::warning("BezRindasScraper: Error parsing {$eventUrl}: " . $e->getMessage());
            }
        }

        return $events;
    }

    /**
     * Parse an individual event page and push ScrapedEventDTO(s) to the collection
     */
    public function parseEventPage(Crawler $pageCrawler, string $eventUrl, array $card, Collection &$events): void
    {
        $title = $this->cleanText($pageCrawler->filter('h1.title-text')->first()->text($card['title'] ?? ''));
        if (empty($title)) {
            return;
        }

        // Clean trailing spaces and weird characters in title
        $title = trim(preg_replace('/\s+/u', ' ', $title));

        // Poster image
        $imageUrl = $card['img'] ?? null;
        if ($pageCrawler->filter('.event-info-poster img, .image img')->count()) {
            $src = $pageCrawler->filter('.event-info-poster img, .image img')->first()->attr('src');
            if (!empty($src) && str_starts_with($src, 'http')) {
                $imageUrl = $src;
            }
        }

        // Description
        $description = null;
        if ($pageCrawler->filter('.description')->count()) {
            $description = $this->cleanText($pageCrawler->filter('.description')->first()->text(''));
        }

        // Category and Entertainment type inference
        [$categories, $entertainmentType] = $this->detectCategoryAndType($title, $description);

        // Extract base event ID from URL (e.g., https://www.bezrindas.lv/lv/stand-up-kandidats/16283/ -> 16283)
        $eventId = '';
        if (preg_match('/\/(\d+)\/?$/', $eventUrl, $m)) {
            $eventId = $m[1];
        }

        // Performance boxes
        $boxes = $pageCrawler->filter('.box-group .unit.box');

        if ($boxes->count() > 0) {
            // Cap performance count per continuous tour/exhibition (e.g. max 20 upcoming instances)
            $boxCount = 0;
            $maxBoxesPerEvent = 20;

            $boxes->each(function (Crawler $box) use (
                &$events,
                &$boxCount,
                $maxBoxesPerEvent,
                $title,
                $description,
                $imageUrl,
                $eventUrl,
                $eventId,
                $card,
                $categories,
                $entertainmentType
            ) {
                if ($boxCount >= $maxBoxesPerEvent) {
                    return;
                }

                $dto = $this->parsePerformanceBox(
                    $box,
                    $title,
                    $description,
                    $imageUrl,
                    $eventUrl,
                    $eventId,
                    $card,
                    $categories,
                    $entertainmentType
                );

                if ($dto) {
                    $events->push($dto);
                    $boxCount++;
                }
            });
        } else {
            // Fallback for events without unit boxes: parse date from card or page
            $dto = $this->createFallbackEventDTO(
                $pageCrawler,
                $title,
                $description,
                $imageUrl,
                $eventUrl,
                $eventId,
                $card,
                $categories,
                $entertainmentType
            );

            if ($dto) {
                $events->push($dto);
            }
        }
    }

    /**
     * Parse single performance box on event page
     */
    protected function parsePerformanceBox(
        Crawler $box,
        string $title,
        ?string $description,
        ?string $imageUrl,
        string $eventUrl,
        string $eventId,
        array $card,
        array $categories,
        string $entertainmentType
    ): ?ScrapedEventDTO {
        $dateFrom = $box->attr('data-eventfrom'); // YYYYMMDD
        $dateTo = $box->attr('data-eventto');     // YYYYMMDD

        $calText = '';
        $locName = '';
        $locHref = '';

        $box->filter('.event-info-oneliner')->each(function (Crawler $line) use (&$calText, &$locName, &$locHref) {
            if ($line->filter('.icon-calendar')->count()) {
                $calText = $this->cleanText($line->text(''));
            }
            if ($line->filter('.icon-location')->count()) {
                $locName = $this->cleanText($line->filter('b a, b')->first()->text(''));
                if ($line->filter('a')->count()) {
                    $locHref = $line->filter('a')->first()->attr('href');
                }
            }
        });

        if (empty($locName) && !empty($card['place'])) {
            $locName = $card['place'];
        }

        // Parse start date & time
        $startAt = $this->parseDateTime($dateFrom, $calText);
        if (!$startAt) {
            return null;
        }

        // Parse end date & time if applicable
        $endAt = null;
        if (!empty($dateTo) && $dateTo !== $dateFrom) {
            try {
                $endAt = Carbon::createFromFormat('Ymd', $dateTo, 'Europe/Riga')->endOfDay();
            } catch (\Throwable $e) {
                $endAt = null;
            }
        }

        // Location & City
        $city = $this->detectCity($locName, $locHref);

        // Price
        $priceText = $box->filter('.max_price')->count() ? $this->cleanText($box->filter('.max_price')->first()->text('')) : null;
        [$isFree, $priceMin, $priceMax] = $this->parsePrice($priceText);

        // Ticket URL
        $buyLink = $eventUrl;
        $performanceId = '';
        if ($box->filter('a#event-details-link')->count()) {
            $href = $box->filter('a#event-details-link')->first()->attr('href');
            if (!empty($href)) {
                $buyLink = $href;
                if (preg_match('/\/(\d+)\/?$/', $href, $m)) {
                    $performanceId = $m[1];
                }
            }
        }

        // Unique external ID
        $extId = "bezrindas-{$eventId}" . ($performanceId ? "-{$performanceId}" : "-{$dateFrom}");

        return new ScrapedEventDTO(
            title: $title,
            startAt: $startAt,
            endAt: $endAt,
            description: $description,
            shortDescription: $description ? Str::limit($description, 180) : null,
            venueName: $locName ?: 'Rīga',
            city: $city,
            categoryNames: $categories,
            entertainmentType: $entertainmentType,
            isFree: $isFree,
            priceMin: $priceMin,
            priceMax: $priceMax,
            ticketUrl: $buyLink,
            imageUrl: $imageUrl,
            sourceUrl: $eventUrl,
            sourceExternalId: $extId,
            locale: 'lv',
            rawData: [
                'event_id' => $eventId,
                'performance_id' => $performanceId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'cal_text' => $calText,
                'location_href' => $locHref,
            ]
        );
    }

    /**
     * Fallback for events with non-standard layout
     */
    protected function createFallbackEventDTO(
        Crawler $pageCrawler,
        string $title,
        ?string $description,
        ?string $imageUrl,
        string $eventUrl,
        string $eventId,
        array $card,
        array $categories,
        string $entertainmentType
    ): ?ScrapedEventDTO {
        $dateText = $card['dateText'] ?? '';
        $startAt = $this->parseDateFromText($dateText);
        if (!$startAt) {
            $startAt = now()->addDays(2)->setTime(19, 0);
        }

        $locName = $card['place'] ?? 'Rīga';
        $city = $this->detectCity($locName, '');

        return new ScrapedEventDTO(
            title: $title,
            startAt: $startAt,
            description: $description,
            shortDescription: $description ? Str::limit($description, 180) : null,
            venueName: $locName,
            city: $city,
            categoryNames: $categories,
            entertainmentType: $entertainmentType,
            isFree: false,
            ticketUrl: $eventUrl,
            imageUrl: $imageUrl,
            sourceUrl: $eventUrl,
            sourceExternalId: "bezrindas-{$eventId}",
            locale: 'lv',
            rawData: [
                'event_id' => $eventId,
                'card_date' => $dateText,
            ]
        );
    }

    /**
     * Parse date and time from YYYYMMDD string and calendar text
     */
    protected function parseDateTime(?string $dateFrom, string $calText): ?Carbon
    {
        if (empty($dateFrom) && empty($calText)) {
            return null;
        }

        $date = null;
        if (!empty($dateFrom) && preg_match('/^\d{8}$/', $dateFrom)) {
            try {
                $date = Carbon::createFromFormat('Ymd', $dateFrom, 'Europe/Riga')->startOfDay();
            } catch (\Throwable $e) {
                $date = null;
            }
        }

        if (!$date && !empty($calText)) {
            $date = $this->parseDateFromText($calText);
        }

        if (!$date) {
            return null;
        }

        // Extract time (HH:MM)
        if (preg_match('/\b([012]?\d:[0-5]\d)\b/', $calText, $timeMatch)) {
            [$hour, $minute] = explode(':', $timeMatch[1]);
            $date->setTime((int)$hour, (int)$minute, 0);
        } else {
            $date->setTime(19, 0, 0);
        }

        return $date;
    }

    /**
     * Parse Latvian natural date text e.g., '14. septembris, 20:30' or '25. oktobris'
     */
    protected function parseDateFromText(string $text): ?Carbon
    {
        $months = [
            'janvār' => 1, 'janv' => 1,
            'februār' => 2, 'febr' => 2,
            'mart' => 3,
            'aprīl' => 4, 'apr' => 4,
            'maij' => 5,
            'jūnij' => 6, 'jūn' => 6,
            'jūlij' => 7, 'jūl' => 7,
            'august' => 8, 'aug' => 8,
            'septembr' => 9, 'sept' => 9,
            'oktobr' => 10, 'okt' => 10,
            'novembr' => 11, 'nov' => 11,
            'decembr' => 12, 'dec' => 12,
        ];

        $lower = mb_strtolower($text, 'UTF-8');
        if (preg_match('/(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)/u', $lower, $m)) {
            $day = (int)$m[1];
            $monthWord = $m[2];

            $month = null;
            foreach ($months as $stem => $num) {
                if (str_starts_with($monthWord, $stem)) {
                    $month = $num;
                    break;
                }
            }

            if ($month) {
                $year = (int)now()->format('Y');
                // If month is before current month by 6+ months, it might be next year
                $currentMonth = (int)now()->format('n');
                if ($month < $currentMonth - 2) {
                    $year++;
                }

                try {
                    return Carbon::createFromDate($year, $month, $day, 'Europe/Riga')->startOfDay();
                } catch (\Throwable $e) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * Detect city from location name and location href slug
     */
    protected function detectCity(string $locName, string $locHref): string
    {
        if (!empty($locHref)) {
            $slug = basename(parse_url($locHref, PHP_URL_PATH));
            $tokens = explode('-', strtolower($slug));

            foreach ($tokens as $token) {
                if (isset($this->cityMap[$token])) {
                    return $this->cityMap[$token];
                }
            }
        }

        foreach ($this->cityMap as $slug => $proper) {
            if (mb_stripos($locName, $proper) !== false || mb_stripos($locName, $slug) !== false) {
                return $proper;
            }
        }

        return 'Rīga';
    }

    /**
     * Parse price string into min/max floats and free boolean
     */
    protected function parsePrice(?string $priceText): array
    {
        if (empty($priceText)) {
            return [false, null, null];
        }

        $lower = mb_strtolower($priceText, 'UTF-8');
        if (str_contains($lower, 'bezmaksas') || str_contains($lower, 'brīva') || str_contains($lower, 'free')) {
            return [true, 0.0, 0.0];
        }

        preg_match_all('/(\d+([.,]\d+)?)/', $priceText, $matches);
        if (!empty($matches[1])) {
            $nums = array_map(fn ($n) => (float)str_replace(',', '.', $n), $matches[1]);
            $min = min($nums);
            $max = max($nums);
            return [$min == 0.0, $min, $max];
        }

        return [false, null, null];
    }

    /**
     * Detect category and entertainment type from text
     */
    protected function detectCategoryAndType(string $title, ?string $desc): array
    {
        $text = mb_strtolower($title . ' ' . ($desc ?? ''), 'UTF-8');

        if (preg_match('/(koncert|mūzik|muzika|jazz|džezs|orķestr|koris|dziesm|festivāl|grupa|solist|vokāl|ģitār|klavier)/u', $text)) {
            return [['Mūzika & Koncerti'], 'concert'];
        }

        if (preg_match('/(teātr|izrāde|kino|filma|komēdij|stand up|stand-up|humor|aktier|drāma|pirmizrād)/u', $text)) {
            return [['Teātris & Kino'], 'chill'];
        }

        if (preg_match('/(bērn|ģimen|pasaka|leļļu|atrakcij|animācij)/u', $text)) {
            return [['Ģimenēm & Bērniem'], 'family'];
        }

        if (preg_match('/(izstāde|muzej|māksl|ekspozīcij|glezn|tūre|tour|guided|vēstur)/u', $text)) {
            return [['Kultūra & Māksla'], 'exhibition'];
        }

        if (preg_match('/(meistarklas|seminār|lekcij|darbnīc|kursi|apmācīb|diskusij)/u', $text)) {
            return [['Izglītība & Semināri'], 'workshop'];
        }

        if (preg_match('/(sports|maratons|skrējiens|turnīrs|čempionāts|futbols|basketbols|hokejs|joga)/u', $text)) {
            return [['Sports & Pārgājieni'], 'active'];
        }

        if (preg_match('/(party|ballīte|disko|klubs|nakts|dejas)/u', $text)) {
            return [['Festivāli & Svētki'], 'party'];
        }

        return [['Kultūra & Māksla'], 'chill'];
    }
}
