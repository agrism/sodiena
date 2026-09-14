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
        $description = $this->extractFormattedDescription($pageCrawler);

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
            shortDescription: $this->extractShortDescription($description),
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
            shortDescription: $this->extractShortDescription($description),
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
     * Extract structured description from event page, preserving paragraphs and formatting.
     */
    public function extractFormattedDescription(Crawler $pageCrawler): ?string
    {
        // 1. Check for main description container
        $descNodes = $pageCrawler->filter('.description');
        if ($descNodes->count() === 0) {
            return null;
        }

        // The first .description is the main text (subsequent .description often contain prohibited items icon list)
        $mainNode = $descNodes->first();

        // Extract embedded YouTube trailer if present
        $youtubeUrl = null;
        if ($mainNode->filter('iframe')->count()) {
            $iframeSrc = $mainNode->filter('iframe')->first()->attr('src');
            if (!empty($iframeSrc) && (str_contains($iframeSrc, 'youtube') || str_contains($iframeSrc, 'youtu.be'))) {
                if (preg_match('/(?:embed\/|watch\?v=|youtu\.be\/)([a-zA-Z0-9_\-]{11})/i', $iframeSrc, $ym)) {
                    $youtubeUrl = "https://www.youtube.com/watch?v={$ym[1]}";
                }
            }
        }

        // Get raw HTML
        $rawHtml = $mainNode->html('');
        if (empty(trim($rawHtml))) {
            return null;
        }

        // Decode HTML entities
        $html = html_entity_decode($rawHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Convert breaks & block closing tags to newlines
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/(?:p|div|li|tr|h[1-6])>/i', "\n\n", $html);

        // Strip HTML tags
        $text = strip_tags($html);

        // Clean non-breaking spaces & tabs
        $text = str_replace(["\xC2\xA0", "&nbsp;", "\t"], ' ', $text);
        $text = preg_replace('/[^\S\r\n]+/u', ' ', $text);

        // Split into lines and filter empty / boilerplate lines
        $lines = preg_split('/\r?\n/', $text);
        $cleanedLines = [];

        foreach ($lines as $line) {
            $line = trim($line, " \t\n\r\0\x0B\xC2\xA0");
            if ($line === '' || $line === '•' || $line === '.' || $line === '-') {
                continue;
            }
            // Ignore boilerplate prohibited items header
            if (mb_stripos($line, 'Pasākumā aizliegts ienest') !== false) {
                continue;
            }
            $cleanedLines[] = $line;
        }

        // Extract organizer from .description-table if present
        if ($pageCrawler->filter('.description-table')->count()) {
            $orgText = $this->cleanText($pageCrawler->filter('.description-table')->first()->text(''));
            if (!empty($orgText) && preg_match('/organizators:\s*(.+)$/ui', $orgText, $om)) {
                $orgName = trim($om[1]);
                $alreadyInDesc = false;
                foreach ($cleanedLines as $cl) {
                    if (mb_stripos($cl, $orgName) !== false) {
                        $alreadyInDesc = true;
                        break;
                    }
                }
                if (!$alreadyInDesc && !empty($orgName)) {
                    $cleanedLines[] = "Organizators: {$orgName}";
                }
            }
        }

        // Append YouTube URL if found and not already in text
        if ($youtubeUrl) {
            $hasYt = false;
            foreach ($cleanedLines as $cl) {
                if (str_contains($cl, $youtubeUrl)) {
                    $hasYt = true;
                    break;
                }
            }
            if (!$hasYt) {
                $cleanedLines[] = $youtubeUrl;
            }
        }

        return !empty($cleanedLines) ? implode("\n\n", $cleanedLines) : null;
    }

    /**
     * Extract a concise summary snippet from the narrative text, ignoring metadata headers.
     */
    public function extractShortDescription(?string $description): ?string
    {
        if (empty($description)) {
            return null;
        }

        $paragraphs = preg_split('/\r?\n+/', trim($description));
        $metaPrefixes = [
            'režisors', 'režisore', 'aktieri', 'lomās', 'valsts', 'garums', 'ilgums',
            'žanrs', 'žanri', 'vecuma ierobežojums', 'vecums', 'pasākuma valoda', 'valoda',
            'organizators', 'rīkotājs', 'ieeja', 'biļetes', 'biļešu cena', 'cena', 'http'
        ];

        foreach ($paragraphs as $paragraph) {
            $p = trim($paragraph);
            if (mb_strlen($p) < 40) {
                continue;
            }

            $isMeta = false;
            $lower = mb_strtolower($p, 'UTF-8');
            foreach ($metaPrefixes as $prefix) {
                if (str_starts_with($lower, $prefix)) {
                    $isMeta = true;
                    break;
                }
            }

            if (!$isMeta) {
                return Str::limit($p, 190);
            }
        }

        return Str::limit($description, 180);
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

        // 1. Kids & Family (explicit family films, kids events, fairy tales, puppet theatre)
        if (preg_match('/(ģimenēm|ģimenes\s+(?:filma|pasākum|kino|dien|svētk)|bērniem|bērnu\s+(?:izrāde|pasākum|koncert|rīts|darbnīc)|leļļu\s+teātr|pasaka|pasakas|multfilma|karuselis|bumbu\s+basein)/u', $text)) {
            if (preg_match('/(filma|kino|kinoteātr)/u', $text)) {
                return [['Ģimenēm & Bērniem', 'Filmas & Kino'], 'family'];
            }
            if (preg_match('/(izrāde|teātr)/u', $text)) {
                return [['Ģimenēm & Bērniem', 'Teātris & Kino'], 'family'];
            }
            return [['Ģimenēm & Bērniem'], 'family'];
        }

        // 2. Cinema & Movies
        if (preg_match('/(kino|filma|kinoteātr|seanss|kinofestivāl|un poeta)/u', $text)) {
            return [['Filmas & Kino'], 'chill'];
        }

        // 3. Theatre, Stage Plays & Stand-up Comedy
        if (preg_match('/(teātr|izrāde|komēdij|stand up|stand-up|humor|aktier|drāma|pirmizrād)/u', $text)) {
            return [['Teātris & Kino'], 'chill'];
        }

        // 3. Music & Concerts
        if (preg_match('/(koncert|mūzik|muzika|jazz|džezs|orķestr|koris|dziesm|dzied|grupa|solist|vokāl|ģitār|klavier|simfonij|filharmon|oper|operet|roks|pops|dziesmu|jam session|soundtrack)/u', $text)) {
            return [['Mūzika & Koncerti'], 'concert'];
        }

        // 4. Exhibitions, Art & Museums
        if (preg_match('/(izstāde|muzej|māksl|ekspozīcij|glezn|tūre|tour|guided|vēstur|ekskursij|galerij|glezniecīb|keramik|tēlniecīb)/u', $text)) {
            return [['Kultūra & Māksla'], 'exhibition'];
        }

        // 5. Education, Workshops, Masterclasses
        if (preg_match('/(meistarklas|seminār|lekcij|darbnīc|kursi|apmācīb|diskusij|konferenc)/u', $text)) {
            return [['Izglītība & Semināri'], 'workshop'];
        }

        // 6. Sports & Active
        if (preg_match('/(sports|maratons|skrējiens|turnīrs|čempionāts|futbols|basketbols|hokejs|joga|pārgājiens|velobrauciens|orientēšan)/u', $text)) {
            return [['Sports & Aktīvā atpūta'], 'active'];
        }

        // 7. Nightlife, Parties & Clubs
        if (preg_match('/(party|ballīte|disko|klubs|nakts|dejas|dīdžej|\bdj\b)/u', $text)) {
            return [['Naktsdzīve & Ballītes'], 'party'];
        }

        // 8. Festivals & Celebrations
        if (preg_match('/(festivāl|svētki|svinīb|gadskārt|jāņi|līgo)/u', $text)) {
            return [['Festivāli & Svētki'], 'party'];
        }

        // 9. Food & Markets
        if (preg_match('/(tirgus|tirdziņš|degustācij|gastronom|vīna|alus|street food|ēdien|kulinār)/u', $text)) {
            return [['Gastronomija & Tirgi'], 'chill'];
        }

        return [['Kultūra & Māksla'], 'chill'];
    }
}
