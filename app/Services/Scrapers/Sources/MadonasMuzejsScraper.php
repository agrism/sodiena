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

class MadonasMuzejsScraper extends BaseScraper
{
    public function getSlug(): string
    {
        return 'madonas-muzejs';
    }

    public function getName(): string
    {
        return 'Madonas novadpētniecības un mākslas muzejs';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $feedUrl = 'http://www.madonasmuzejs.lv/lv/aktualitātes';
        $calendarUrl = $source->url ?: 'http://www.madonasmuzejs.lv/lv/izstāžu-un-pasākumu-kalendārs';

        $crawler = $this->fetchCrawler($feedUrl);

        if (!$crawler) {
            Log::warning("MadonasMuzejsScraper: Failed to fetch crawler for {$feedUrl}");
            return $events;
        }

        try {
            $seenUrls = [];

            $crawler->filter('.post')->each(function (Crawler $node) use (&$events, &$seenUrls, $calendarUrl) {
                $linkNode = $node->filter('a');
                if (!$linkNode->count()) {
                    return;
                }

                $relLink = $linkNode->first()->attr('href');
                $cleanUrl = $this->normalizeUrl($relLink);

                if (empty($cleanUrl) || in_array($cleanUrl, $seenUrls)) {
                    return;
                }

                // Filter out non-event archive categories
                if (str_contains($cleanUrl, 'aktualitātes-_arhīvs_') || str_contains($cleanUrl, 'unesco')) {
                    return;
                }

                $seenUrls[] = $cleanUrl;

                $excerptNode = $node->filter('.excerpt');
                $excerpt = $excerptNode->count() ? $this->cleanText($excerptNode->text()) : '';

                $dateNode = $node->filter('.date');
                $pubDateStr = $dateNode->count() ? $this->cleanText($dateNode->text()) : '';

                $thumbImg = $node->filter('img')->count() ? $node->filter('img')->attr('src') : null;

                // Open individual article/event page
                $detail = $this->fetchEventDetail($cleanUrl);

                $title = !empty($detail['title']) ? $detail['title'] : $this->cleanText($linkNode->text());
                $title = $this->formatTitle($title);
                if (empty($title)) {
                    return;
                }

                $fullDescription = !empty($detail['description']) ? $detail['description'] : $excerpt;

                // Skip non-event posts (obituaries, memorial notes)
                if (!$this->isEventPost($title, $fullDescription)) {
                    return;
                }

                $slugPart = basename(parse_url($cleanUrl, PHP_URL_PATH));
                $slugPart = trim($slugPart, '-');
                $externalId = 'mm-' . $slugPart;

                $imageUrl = !empty($detail['image']) ? $detail['image'] : $thumbImg;

                // Parse dates and times from text and fallback to publish date
                [$startAt, $endAt] = $this->extractDates($title . ' ' . $fullDescription, $pubDateStr);

                // Ignore events older than 1 year ago unless ongoing
                if ($endAt && $endAt->isPast() && $endAt->diffInDays(now()) > 365) {
                    return;
                }
                if (!$endAt && $startAt->isPast() && $startAt->diffInDays(now()) > 365) {
                    return;
                }

                // Location resolution
                $venueInfo = $this->resolveLocation($title . ' ' . $fullDescription);

                // Opening hours for branch
                $openingHours = $this->resolveOpeningHours($venueInfo['name']);
                if (!str_contains(mb_strtolower($fullDescription), 'darba laiks')) {
                    $hoursBlock = "\n\nDarba laiks:";
                    foreach ($openingHours as $day => $time) {
                        $hoursBlock .= "\n• {$day}: {$time}";
                    }
                    $fullDescription .= $hoursBlock;
                }

                // Categories & entertainment type
                $categories = $this->resolveCategories($title . ' ' . $fullDescription);
                $entertainmentType = $this->resolveEntertainmentType($title . ' ' . $fullDescription);

                // Price detection
                [$isFree, $priceMin] = $this->detectPrice($fullDescription);

                $leadExcerpt = !empty($detail['excerpt']) ? $detail['excerpt'] : $excerpt;

                $dto = new ScrapedEventDTO(
                    title: $title,
                    startAt: $startAt,
                    endAt: $endAt,
                    description: $fullDescription,
                    shortDescription: !empty($leadExcerpt) ? Str::limit(strip_tags($leadExcerpt), 320) : Str::limit(strip_tags($fullDescription), 240),
                    venueName: $venueInfo['name'],
                    city: $venueInfo['city'],
                    region: $venueInfo['region'],
                    address: $venueInfo['address'],
                    latitude: $venueInfo['lat'],
                    longitude: $venueInfo['lng'],
                    placeType: $venueInfo['placeType'],
                    categoryNames: $categories,
                    entertainmentType: $entertainmentType,
                    isFree: $isFree,
                    priceMin: $priceMin,
                    priceMax: null,
                    imageUrl: $imageUrl,
                    sourceUrl: $cleanUrl,
                    sourceExternalId: $externalId,
                    locale: 'lv',
                    rawData: [
                        'pub_date' => $pubDateStr,
                        'opening_hours' => $openingHours,
                        'opening_hours_source' => 'https://www.madonasmuzejs.lv/lv/darba-laiks',
                    ]
                );

                $events->push($dto);
            });
        } catch (\Throwable $e) {
            Log::error("MadonasMuzejsScraper parsing error: " . $e->getMessage(), [
                'exception' => $e,
            ]);
        }

        return $events;
    }

    private function isEventPost(string $title, string $text): bool
    {
        $lower = mb_strtolower($title . ' ' . mb_substr($text, 0, 300));

        if (str_contains($lower, 'mūžībā devies') || str_contains($lower, 'piemiņai')) {
            return false;
        }

        return true;
    }

    private function formatTitle(string $title): string
    {
        $title = $this->cleanText($title) ?? '';
        $title = trim($title, " \t\n\r\0\x0B\xC2\xA0-–—");

        if (empty($title)) {
            return '';
        }

        $title = mb_convert_encoding($title, 'UTF-8', 'UTF-8');

        // Normalize ALL-CAPS titles into title case
        $lettersOnly = preg_replace('/[^\p{L}]+/u', '', $title);
        if (!empty($lettersOnly) && mb_strtoupper($lettersOnly, 'UTF-8') === $lettersOnly && mb_strlen($lettersOnly, 'UTF-8') > 4) {
            $lower = mb_strtolower($title, 'UTF-8');
            $words = explode(' ', $lower);
            $casedWords = [];
            $minorWords = ['un', 'ar', 'par', 'pie', 'uz', 'no', 'vai', 'kā', 'pret', 'līdz', 'pa', 'pēc', 'zem', 'virs', 'pretī', 'š.g.'];
            foreach ($words as $i => $word) {
                if ($i === 0 || !in_array(trim($word, " \t\n\r\0\x0B\xC2\xA0-–—.,!?:;\"'«»“”"), $minorWords)) {
                    $firstChar = mb_substr($word, 0, 1, 'UTF-8');
                    $rest = mb_substr($word, 1, null, 'UTF-8');
                    $casedWords[] = mb_strtoupper($firstChar, 'UTF-8') . $rest;
                } else {
                    $casedWords[] = $word;
                }
            }
            $formatted = implode(' ', $casedWords);
            $formatted = preg_replace_callback('/([\.\!\?\:\“\”\«\»\|\-]\s*)([a-zāčēģīķļņšūž])/u', function ($m) {
                return $m[1] . mb_strtoupper($m[2], 'UTF-8');
            }, $formatted);
            $formatted = preg_replace_callback('/([“«\x22])([a-zāčēģīķļņšūž])/u', function ($m) {
                return $m[1] . mb_strtoupper($m[2], 'UTF-8');
            }, $formatted);
            $title = $formatted;
        }

        return mb_convert_encoding($title, 'UTF-8', 'UTF-8');
    }

    private function fetchEventDetail(string $url): array
    {
        try {
            $crawler = $this->fetchCrawler($url);
            if (!$crawler) {
                return [];
            }

            // Title
            $title = '';
            if ($crawler->filter('h1')->count()) {
                $title = $this->cleanText($crawler->filter('h1')->first()->text());
            }

            // High resolution image
            $image = null;
            if ($crawler->filter('a[data-fullsizeimage]')->count()) {
                $image = $crawler->filter('a[data-fullsizeimage]')->first()->attr('data-fullsizeimage');
            } elseif ($crawler->filter('.image-inner img, .content img')->count()) {
                $image = $crawler->filter('.image-inner img, .content img')->first()->attr('src');
            }

            if ($image) {
                $image = $this->normalizeUrl($image);
            }

            // Lead excerpt
            $excerpt = '';
            if ($crawler->filter('.post .excerpt')->count()) {
                $excerpt = $this->cleanText($crawler->filter('.post .excerpt')->first()->text());
            }

            // Text paragraphs
            $paragraphs = [];
            $textNodes = $crawler->filter('.post .text-block div[data-admin-inline-editable="true"]');
            if (!$textNodes->count()) {
                $textNodes = $crawler->filter('.text-block div[data-admin-inline-editable="true"]');
            }
            if (!$textNodes->count()) {
                $textNodes = $crawler->filter('.content-inner');
            }

            if ($textNodes->count()) {
                $textNodes->each(function (Crawler $node) use (&$paragraphs) {
                    $html = $node->html();

                    // Clean social footer, navigation and admin artifacts
                    $html = preg_replace('/<div[^>]*class="(?:social|navigation|disqus-comments)"[^>]*>.*?<\/div>/si', '', $html);
                    $html = preg_replace('/Patīk šis raksts.*$/us', '', $html);

                    // Convert block tags and list items to proper newlines
                    $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
                    $html = preg_replace('/<\/(p|div|h1|h2|h3|h4|h5|h6)>/i', "\n\n", $html);
                    $html = preg_replace('/<li\b[^>]*>/i', "\n• ", $html);
                    $html = preg_replace('/<\/li>/i', "\n", $html);
                    $html = preg_replace('/<\/(ul|ol)>/i', "\n\n", $html);
                    $html = preg_replace('/<(?:strong|b)\b[^>]*>(.*?)<\/(?:strong|b)>/iu', "\n\n$1\n", $html);

                    $rawText = strip_tags($html);
                    $rawText = html_entity_decode($rawText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $rawText = preg_replace('/^.*?skatījumi\s+/u', '', $rawText);
                    $rawText = preg_replace('/[^\S\r\n]+/u', ' ', $rawText);
                    $rawText = preg_replace('/\n{3,}/', "\n\n", $rawText);

                    $lines = array_filter(array_map('trim', explode("\n", $rawText)), function ($line) {
                        $clean = trim($line, " \t\n\r\0\x0B\xC2\xA0");
                        $lower = mb_strtolower($clean);
                        if (preg_match('/^\d+\s*patīk$/u', $lower) || $lower === 'padalīties' || $lower === 'iepriekšējs' || $lower === 'nākamais' || $lower === 'patīk') {
                            return false;
                        }
                        return $clean !== '';
                    });

                    foreach ($lines as $line) {
                        $paragraphs[] = trim($line, " \t\n\r\0\x0B\xC2\xA0");
                    }
                });
            }

            if (!empty($excerpt)) {
                $cleanExcerpt = trim($excerpt, " \t\n\r\0\x0B\xC2\xA0");
                $firstPara = !empty($paragraphs) ? trim($paragraphs[0], " \t\n\r\0\x0B\xC2\xA0") : '';
                if ($firstPara === '' || !str_contains($firstPara, mb_substr($cleanExcerpt, 0, min(40, mb_strlen($cleanExcerpt))))) {
                    array_unshift($paragraphs, $cleanExcerpt);
                }
            }

            $description = implode("\n\n", $paragraphs);

            return [
                'title' => $title,
                'image' => $image,
                'description' => $description,
                'excerpt' => $excerpt,
            ];
        } catch (\Throwable $e) {
            Log::debug("MadonasMuzejsScraper detail fetch error for {$url}: " . $e->getMessage());
            return [];
        }
    }

    private function normalizeUrl(?string $relUrl): ?string
    {
        if (empty($relUrl)) {
            return null;
        }

        if (str_starts_with($relUrl, 'http://') || str_starts_with($relUrl, 'https://')) {
            $url = preg_replace('/^http:\/\//i', 'https://', $relUrl);
            return rtrim(strtok($url, '?'), '/');
        }

        $path = rtrim(strtok($relUrl, '?'), '/');
        return 'https://www.madonasmuzejs.lv' . (str_starts_with($path, '/') ? '' : '/') . $path;
    }

    private function extractDates(string $text, string $pubDateStr): array
    {
        $months = [
            'janv' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
            'mai' => 5, 'jūn' => 6, 'jun' => 6, 'jūl' => 7, 'jul' => 7, 'aug' => 8,
            'sep' => 9, 'okt' => 10, 'nov' => 11, 'dec' => 12,
        ];

        $currentYear = (int) date('Y');
        $startAt = null;
        $endAt = null;

        // Pattern 1: "No 2026.gada 12.septembra ... līdz 22.novembrim"
        if (preg_match('/(?:No|no)\s+(\d{4})\.\s*gada\s+(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)[^\.\n]*?(?:līdz|–|-)\s+(?:(\d{4})\.\s*gada\s+)?(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)/iu', $text, $m)) {
            $startYear = (int) $m[1];
            $startDay = (int) $m[2];
            $startMonth = $this->matchMonth($m[3], $months);
            $endYear = !empty($m[4]) ? (int) $m[4] : $startYear;
            $endDay = (int) $m[5];
            $endMonth = $this->matchMonth($m[6], $months);

            if ($startYear >= 2024 && $startMonth >= 1 && $startMonth <= 12 && $startDay >= 1 && $startDay <= 31) {
                $time = $this->extractTime($text) ?? '10:00';
                $startAt = Carbon::create($startYear, $startMonth, $startDay, (int)substr($time, 0, 2), (int)substr($time, 3, 2), 0);
                if ($endMonth >= 1 && $endMonth <= 12 && $endDay >= 1 && $endDay <= 31) {
                    $endAt = Carbon::create($endYear, $endMonth, $endDay, 18, 0, 0);
                }
            }
        }

        // Pattern 2: "No 12.septembra līdz 22.novembrim"
        if (!$startAt && preg_match('/(?:No|no)\s+(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)[^\.\n]*?(?:līdz|–|-)\s+(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:\s+(\d{4}))?/iu', $text, $m)) {
            $year = !empty($m[5]) ? (int) $m[5] : $currentYear;
            $startDay = (int) $m[1];
            $startMonth = $this->matchMonth($m[2], $months);
            $endDay = (int) $m[3];
            $endMonth = $this->matchMonth($m[4], $months);

            if ($year >= 2024 && $startMonth >= 1 && $startMonth <= 12 && $startDay >= 1 && $startDay <= 31) {
                $time = $this->extractTime($text) ?? '10:00';
                $startAt = Carbon::create($year, $startMonth, $startDay, (int)substr($time, 0, 2), (int)substr($time, 3, 2), 0);
                if ($endMonth >= 1 && $endMonth <= 12 && $endDay >= 1 && $endDay <= 31) {
                    $endAt = Carbon::create($year, $endMonth, $endDay, 18, 0, 0);
                }
            }
        }

        // Pattern 3: "2026.gada 8.augustā plkst.11.30" or "12.septembrī plkst.13.00"
        if (!$startAt && preg_match('/(?:(\d{4})\.\s*gada\s+)?(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:\s+plkst\.?\s*(\d{1,2}[\.:]\d{2}))?/iu', $text, $m)) {
            $year = !empty($m[1]) ? (int) $m[1] : $currentYear;
            $day = (int) $m[2];
            $month = $this->matchMonth($m[3], $months);
            $time = !empty($m[4]) ? str_replace('.', ':', $m[4]) : ($this->extractTime($text) ?? '12:00');
            $parts = explode(':', $time);

            if ($year >= 2024 && $month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                $startAt = Carbon::create($year, $month, $day, (int)($parts[0] ?? 12), (int)($parts[1] ?? 0), 0);
            }
        }

        // Fallback: parse publication date
        if (!$startAt && preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $pubDateStr, $dm)) {
            $startAt = Carbon::create((int)$dm[3], (int)$dm[2], (int)$dm[1], 10, 0, 0);
        }

        if (!$startAt) {
            $startAt = now();
        }

        // Check if there is an explicit end date in text (e.g. "līdz š.g. 31.maijam" or "līdz 20.septembrim")
        if (!$endAt && preg_match('/(?:līdz|–|-)\s+(?:š\.g\.\s*|(\d{4})\.\s*gada\s+)?(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)/iu', $text, $em)) {
            $eYear = !empty($em[1]) ? (int)$em[1] : $startAt->year;
            $eDay = (int)$em[2];
            $eMonth = $this->matchMonth($em[3], $months);
            if ($eMonth >= 1 && $eMonth <= 12 && $eDay >= 1 && $eDay <= 31) {
                $candidateEnd = Carbon::create($eYear, $eMonth, $eDay, 18, 0, 0);
                if ($candidateEnd->greaterThanOrEqualTo($startAt)) {
                    $endAt = $candidateEnd;
                }
            }
        }

        return [$startAt, $endAt];
    }

    private function matchMonth(string $name, array $months): int
    {
        $clean = preg_replace('/[^a-zāčēģīķļņšūž]+/iu', '', mb_strtolower(trim($name)));
        foreach ($months as $prefix => $m) {
            if (str_starts_with($clean, $prefix)) {
                return $m;
            }
        }
        return 9;
    }

    private function extractTime(string $text): ?string
    {
        if (preg_match('/plkst\.?\s*(\d{1,2})[\.:](\d{2})/iu', $text, $m)) {
            return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
        }
        return null;
    }

    private function detectPrice(string $text): array
    {
        $lower = mb_strtolower($text);

        if (preg_match('/dalības maksa:?\s*(\d+[\.,]?\d*)\s*(?:eiro|eur|€)/iu', $lower, $m)) {
            $price = (float) str_replace(',', '.', $m[1]);
            return [false, $price];
        }

        if (str_contains($lower, 'bez maksas') || str_contains($lower, 'ieeja brīva') || str_contains($lower, 'brīva ieeja')) {
            return [true, null];
        }

        return [true, null];
    }

    private function resolveLocation(string $text): array
    {
        $lower = mb_strtolower($text);

        if (str_contains($lower, 'haralda medņa') || str_contains($lower, 'dziesmusvētku skol')) {
            return [
                'name' => 'Haralda Medņa Dziesmusvētku skola',
                'address' => 'Dziesmusvētku skola, Praulienas pagasts, Madonas novads',
                'city' => 'Madona',
                'region' => 'Vidzeme',
                'lat' => 56.8480,
                'lng' => 26.3120,
                'placeType' => 'museum',
            ];
        }

        if (str_contains($lower, 'sarkaņ') || str_contains($lower, 'sarkani')) {
            return [
                'name' => 'Etnogrāfijas un sadzīves priekšmetu krātuve Sarkaņos',
                'address' => 'Sarkaņu pagasts, Madonas novads',
                'city' => 'Madona',
                'region' => 'Vidzeme',
                'lat' => 56.8920,
                'lng' => 26.2890,
                'placeType' => 'museum',
            ];
        }

        if (str_contains($lower, 'izstāžu zāl') || str_contains($lower, 'izstāžu zāles')) {
            return [
                'name' => 'Madonas muzeja Izstāžu zāles',
                'address' => 'Skolas iela 10a, Madona',
                'city' => 'Madona',
                'region' => 'Vidzeme',
                'lat' => 56.8532,
                'lng' => 26.2198,
                'placeType' => 'museum',
            ];
        }

        return [
            'name' => 'Madonas novadpētniecības un mākslas muzejs',
            'address' => 'Skolas iela 12, Madona',
            'city' => 'Madona',
            'region' => 'Vidzeme',
            'lat' => 56.8532,
            'lng' => 26.2198,
            'placeType' => 'museum',
        ];
    }

    private function resolveCategories(string $text): array
    {
        $lower = mb_strtolower($text);

        if (str_contains($lower, 'koncert') || str_contains($lower, 'mūzik') || str_contains($lower, 'dziesm')) {
            return ['Mūzika & Koncerti', 'Kultūra & Tradīcijas'];
        }
        if (str_contains($lower, 'bērn') || str_contains($lower, 'ģimen') || str_contains($lower, 'skolēn')) {
            return ['Ģimenēm & Bērniem', 'Kultūra & Tradīcijas'];
        }
        if (str_contains($lower, 'plenēr') || str_contains($lower, 'meistardarbnīc') || str_contains($lower, 'lekcij')) {
            return ['Kultūra & Tradīcijas', 'Izstādes & Māksla'];
        }
        if (str_contains($lower, 'nakts') || str_contains($lower, 'svētk')) {
            return ['Festivāli & Svētki', 'Kultūra & Tradīcijas'];
        }

        return ['Izstādes & Māksla', 'Kultūra & Tradīcijas'];
    }

    private function resolveEntertainmentType(string $text): string
    {
        $lower = mb_strtolower($text);

        if (str_contains($lower, 'koncert') || str_contains($lower, 'dziesm')) {
            return 'concert';
        }
        if (str_contains($lower, 'bērn') || str_contains($lower, 'ģimen')) {
            return 'family';
        }
        if (str_contains($lower, 'plenēr') || str_contains($lower, 'meistarklas') || str_contains($lower, 'lekcij')) {
            return 'workshop';
        }
        if (str_contains($lower, 'nakts') || str_contains($lower, 'svētk')) {
            return 'party';
        }

        return 'exhibition';
    }

    public function resolveOpeningHours(string $venueName): array
    {
        $lower = mb_strtolower($venueName);

        if (str_contains($lower, 'dziesmusvētku') || str_contains($lower, 'medņa') || str_contains($lower, 'praulien')) {
            return [
                'Pirmdiena' => 'Slēgts',
                'Otrdiena' => 'Pēc pieteikuma (+371 28080668)',
                'Trešdiena – Piektdiena' => '10:00 – 17:00',
                'Sestdiena' => '10:00 – 16:00',
                'Svētdiena' => 'Pēc pieteikuma (+371 28080668)',
            ];
        }

        if (str_contains($lower, 'sarkaņ') || str_contains($lower, 'sarkani')) {
            return [
                'Pirmdiena' => 'Slēgts',
                'Otrdiena – Piektdiena' => '10:00 – 17:00',
                'Sestdiena, Svētdiena' => 'Pēc pieteikuma (+371 26579716)',
            ];
        }

        if (str_contains($lower, 'krājum') && !str_contains($lower, 'izstāžu')) {
            return [
                'Pirmdiena – Piektdiena' => '08:00 – 17:00',
                'Sestdiena, Svētdiena' => 'Slēgts',
            ];
        }

        // Madonas muzeja Izstāžu zāles (Skolas iela 10a) & galvenā ēka
        return [
            'Pirmdiena' => 'Slēgts',
            'Otrdiena' => '10:00 – 17:00',
            'Trešdiena' => '10:00 – 18:00',
            'Ceturtdiena' => '10:00 – 17:00',
            'Piektdiena' => '10:00 – 17:00',
            'Sestdiena' => '10:00 – 16:00',
            'Svētdiena' => '10:00 – 16:00',
        ];
    }
}
