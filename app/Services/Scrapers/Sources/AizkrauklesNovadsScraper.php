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

class AizkrauklesNovadsScraper extends BaseScraper
{
    public function getSlug(): string
    {
        return 'aizkraukles-novads';
    }

    public function getName(): string
    {
        return 'Aizkraukles novada pašvaldība';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $baseUrl = $source->url ?: 'https://www.aizkraukle.lv/lv/notikumu-kalendars';
        $maxPages = 10;

        for ($page = 0; $page < $maxPages; $page++) {
            $pageUrl = $page === 0 ? $baseUrl : $baseUrl . '?page=' . $page;
            $crawler = $this->fetchCrawler($pageUrl);

            if (!$crawler) {
                break;
            }

            $nodes = $crawler->filter('div.event');
            if ($nodes->count() === 0) {
                break;
            }

            $nodes->each(function (Crawler $node) use (&$events, $baseUrl) {
                $titleNode = $node->filter('h2.event-title a, .event-title a, h2.event-title');
                $title = $this->cleanText($titleNode->count() ? $titleNode->first()->text() : '');
                if (empty($title)) {
                    return;
                }

                $linkNode = $node->filter('h2.event-title a, a[href*="/notikums/"]');
                $relLink = $linkNode->count() ? $linkNode->first()->attr('href') : '';
                $cleanUrl = $this->normalizeUrl($relLink);

                $slugPart = $cleanUrl ? basename(parse_url($cleanUrl, PHP_URL_PATH)) : Str::slug($title);
                $externalId = 'aizkraukle-' . $slugPart;

                // Dates & times
                $dateStr = $node->filter('span.date-date')->count() ? $this->cleanText($node->filter('span.date-date')->text()) : '';
                $timeStr = $node->filter('span.date-time')->count() ? $this->cleanText($node->filter('span.date-time')->text()) : '';
                [$startAt, $endAt] = $this->parseLatvianDates($dateStr, $timeStr);

                // Location info
                $locationRaw = $node->filter('span.describing-field-value')->count() ? $this->cleanText($node->filter('span.describing-field-value')->text()) : '';
                $venueInfo = $this->resolveLocation($locationRaw);

                // Categories & entertainment type
                $catRaw = $node->filter('div.event-categories a, .event-categories')->count() ? $this->cleanText($node->filter('div.event-categories a, .event-categories')->text()) : '';
                $categories = $this->resolveCategories($catRaw, $title);
                $entertainmentType = $this->resolveEntertainmentType($catRaw, $title);

                // Image
                $imageUrl = $node->filter('div.event-image img')->count() ? $node->filter('div.event-image img')->attr('src') : null;
                if ($imageUrl && !str_starts_with($imageUrl, 'http')) {
                    $imageUrl = 'https://www.aizkraukle.lv' . (str_starts_with($imageUrl, '/') ? '' : '/') . $imageUrl;
                }

                // Short content preview from calendar
                $shortContent = $node->filter('div.event-content')->count() ? $this->cleanText($node->filter('div.event-content')->text()) : '';

                // Try fetching full description from individual event page
                $fullDescription = $this->fetchDetailDescription($cleanUrl) ?: $shortContent;

                $dto = new ScrapedEventDTO(
                    title: $title,
                    startAt: $startAt,
                    endAt: $endAt,
                    description: $fullDescription,
                    shortDescription: Str::limit(strip_tags($shortContent ?: $fullDescription), 160),
                    venueName: $venueInfo['name'],
                    city: $venueInfo['city'],
                    region: 'Zemgale',
                    address: $venueInfo['address'],
                    placeType: 'venue',
                    categoryNames: $categories,
                    entertainmentType: $entertainmentType,
                    isFree: true,
                    imageUrl: $imageUrl,
                    sourceUrl: $cleanUrl ?: $baseUrl,
                    sourceExternalId: $externalId,
                    locale: 'lv',
                    rawData: [
                        'date_raw' => $dateStr,
                        'time_raw' => $timeStr,
                        'location_raw' => $locationRaw,
                        'category_raw' => $catRaw,
                    ]
                );

                $events->push($dto);
            });
        }

        return $events;
    }

    private function normalizeUrl(?string $relUrl): ?string
    {
        if (empty($relUrl)) {
            return null;
        }

        if (str_starts_with($relUrl, 'http://') || str_starts_with($relUrl, 'https://')) {
            $parsed = parse_url($relUrl);
            return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'www.aizkraukle.lv') . ($parsed['path'] ?? '');
        }

        $path = strtok($relUrl, '?');
        return 'https://www.aizkraukle.lv' . (str_starts_with($path, '/') ? '' : '/') . $path;
    }

    private function fetchDetailDescription(?string $url): ?string
    {
        if (empty($url) || !str_contains($url, '/notikums/')) {
            return null;
        }

        try {
            $crawler = $this->fetchCrawler($url);
            if (!$crawler) {
                return null;
            }

            if ($crawler->filter('.cke_editable, .text__text-content, .event-content')->count()) {
                $text = $crawler->filter('.cke_editable, .text__text-content, .event-content')->first()->text('');
                return $this->cleanText($text);
            }
        } catch (\Throwable $e) {
            Log::debug("AizkrauklesNovadsScraper detail fetch error for {$url}: " . $e->getMessage());
        }

        return null;
    }

    public function resolveLocation(string $locationRaw): array
    {
        $raw = trim($locationRaw);
        if (empty($raw)) {
            return [
                'city' => 'Aizkraukle',
                'name' => 'Aizkraukles novads',
                'address' => 'Aizkraukles novads',
            ];
        }

        if (mb_stripos($raw, 'attālināti') !== false || mb_stripos($raw, 'attalinati') !== false) {
            return [
                'city' => 'Aizkraukle',
                'name' => 'Attālināti / Tiešsaistē',
                'address' => 'Tiešsaistē',
            ];
        }

        $knownPlaces = [
            'Aizkraukle' => 'Aizkraukle',
            'Koknese' => 'Koknese',
            'Pļaviņas' => 'Pļaviņas',
            'Plavinas' => 'Pļaviņas',
            'Jaunjelgava' => 'Jaunjelgava',
            'Skrīveri' => 'Skrīveri',
            'Skriveri' => 'Skrīveri',
            'Nereta' => 'Nereta',
            'Bebri' => 'Bebru pagasts',
            'Bebru' => 'Bebru pagasts',
            'Irši' => 'Iršu pagasts',
            'Iršu' => 'Iršu pagasts',
            'Daudzese' => 'Daudzeses pagasts',
            'Daudzeses' => 'Daudzeses pagasts',
            'Sece' => 'Seces pagasts',
            'Seces' => 'Seces pagasts',
            'Sērene' => 'Sērenes pagasts',
            'Serene' => 'Sērenes pagasts',
            'Staburags' => 'Staburaga pagasts',
            'Staburaga' => 'Staburaga pagasts',
            'Sunākste' => 'Sunākstes pagasts',
            'Sunakste' => 'Sunākstes pagasts',
            'Zalve' => 'Zalves pagasts',
            'Zalves' => 'Zalves pagasts',
            'Mazzalve' => 'Mazzalves pagasts',
            'Mazzalves' => 'Mazzalves pagasts',
            'Pilskalne' => 'Pilskalnes pagasts',
            'Pilskalnes' => 'Pilskalnes pagasts',
            'Aiviekste' => 'Aiviekstes pagasts',
            'Aiviekstes' => 'Aiviekstes pagasts',
            'Klintaine' => 'Klintaines pagasts',
            'Klintaines' => 'Klintaines pagasts',
            'Vietalva' => 'Vietalvas pagasts',
            'Vietalvas' => 'Vietalvas pagasts',
        ];

        $parts = array_map('trim', explode(',', $raw));

        $detectedCity = 'Aizkraukle';
        foreach ($knownPlaces as $needle => $placeName) {
            if (mb_stripos($raw, $needle) !== false) {
                $detectedCity = $placeName;
                break;
            }
        }

        if (count($parts) >= 3) {
            $venueName = $parts[1];
            $address = implode(', ', array_slice($parts, 2)) . ', ' . $parts[0];
            return [
                'city' => $detectedCity,
                'name' => $venueName,
                'address' => $address,
            ];
        } elseif (count($parts) === 2) {
            $venueName = $parts[1];
            $address = $parts[1] . ', ' . $parts[0];
            return [
                'city' => $detectedCity,
                'name' => $venueName,
                'address' => $address,
            ];
        }

        return [
            'city' => $detectedCity,
            'name' => $raw,
            'address' => $raw . ', Aizkraukles novads',
        ];
    }

    public function parseLatvianDates(string $dateStr, string $timeStr = ''): array
    {
        $months = [
            'janvāris' => 1, 'janvārī' => 1, 'janvāris,' => 1, 'janvāra' => 1, 'janv' => 1,
            'februāris' => 2, 'februārī' => 2, 'februāris,' => 2, 'februāra' => 2, 'febr' => 2,
            'marts' => 3, 'martā' => 3, 'marts,' => 3, 'marta' => 3, 'mar' => 3,
            'aprīlis' => 4, 'aprīlī' => 4, 'aprīlis,' => 4, 'aprīļa' => 4, 'apr' => 4,
            'maijs' => 5, 'maijā' => 5, 'maijs,' => 5, 'maija' => 5,
            'jūnijs' => 6, 'jūnijā' => 6, 'jūnijs,' => 6, 'jūnija' => 6, 'jūn' => 6,
            'jūlijs' => 7, 'jūlijā' => 7, 'jūlijs,' => 7, 'jūlija' => 7, 'jūl' => 7,
            'augusts' => 8, 'augustā' => 8, 'augusts,' => 8, 'augusta' => 8, 'aug' => 8,
            'septembris' => 9, 'septembrī' => 9, 'septembris,' => 9, 'septembra' => 9, 'sept' => 9,
            'oktobris' => 10, 'oktobrī' => 10, 'oktobris,' => 10, 'oktobra' => 10, 'okt' => 10,
            'novembris' => 11, 'novembrī' => 11, 'novembris,' => 11, 'novembra' => 11, 'nov' => 11,
            'decembris' => 12, 'decembrī' => 12, 'decembris,' => 12, 'decembra' => 12, 'dec' => 12,
        ];

        $clean = trim(str_replace(['&#8211;', '&ndash;', '–', '—'], '-', $dateStr));
        $parts = explode('-', $clean);

        $parseSingle = function (string $p, ?int $fallbackYear = null, ?int $fallbackMonth = null) use ($months) {
            $p = trim($p);

            // Format: dd.mm.yyyy e.g. 07.09.2026
            if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $p, $m)) {
                return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
            }

            // Format: "29. septembris, 2026" or "14. septembris" or "7. septembrī"
            if (preg_match('/(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:[,\s]+(\d{4}))?/iu', $p, $m)) {
                $day = (int) $m[1];
                $monthName = mb_strtolower(trim($m[2]));
                $month = $months[$monthName] ?? ($fallbackMonth ?? (int) date('n'));
                $year = !empty($m[3]) ? (int) $m[3] : ($fallbackYear ?? (int) date('Y'));
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }

            // Format: day-only prefix in range e.g. "7." or "7"
            if (preg_match('/^(\d{1,2})\.?$/', $p, $m)) {
                $day = (int) $m[1];
                $month = $fallbackMonth ?? (int) date('n');
                $year = $fallbackYear ?? (int) date('Y');
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }

            // ISO format YYYY-MM-DD
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $p)) {
                return $p;
            }

            return null;
        };

        $startTime = '10:00';
        $endTime = null;
        if (!empty($timeStr)) {
            $timeClean = trim(str_replace(['&#8211;', '&ndash;', '–', '—'], '-', $timeStr));
            $tParts = explode('-', $timeClean);
            if (preg_match('/(\d{1,2})[\.:](\d{2})/i', $tParts[0], $tm)) {
                $startTime = sprintf('%02d:%02d', $tm[1], $tm[2]);
            }
            if (isset($tParts[1]) && preg_match('/(\d{1,2})[\.:](\d{2})/i', $tParts[1], $tm2)) {
                $endTime = sprintf('%02d:%02d', $tm2[1], $tm2[2]);
            }
        }

        if (count($parts) === 2) {
            $endRaw = $parseSingle($parts[1]);
            $endYear = $endRaw ? (int) substr($endRaw, 0, 4) : (int) date('Y');
            $endMonth = $endRaw ? (int) substr($endRaw, 5, 2) : (int) date('n');
            $startRaw = $parseSingle($parts[0], $endYear, $endMonth);

            $start = $startRaw ? Carbon::parse("{$startRaw} {$startTime}") : ($endRaw ? Carbon::parse("{$endRaw} {$startTime}") : now());
            $end = $endRaw ? Carbon::parse("{$endRaw} " . ($endTime ?: '18:00')) : null;
            return [$start, $end];
        } else {
            $startRaw = $parseSingle($parts[0]);
            $start = $startRaw ? Carbon::parse("{$startRaw} {$startTime}") : now();
            $end = $endTime && $startRaw ? Carbon::parse("{$startRaw} {$endTime}") : null;
            return [$start, $end];
        }
    }

    private function resolveCategories(string $catRaw, string $title): array
    {
        $categories = [];
        $text = mb_strtolower($catRaw . ' ' . $title);

        if (str_contains($text, 'sports') || str_contains($text, 'aktīv') || str_contains($text, 'vingro') || str_contains($text, 'skrieš') || str_contains($text, 'futbol')) {
            $categories[] = 'Sports & Aktīvā atpūta';
        }
        if (str_contains($text, 'koncert') || str_contains($text, 'mūzik') || str_contains($text, 'kor') || str_contains($text, 'orķestr')) {
            $categories[] = 'Mūzika & Koncerti';
        }
        if (str_contains($text, 'izstād') || str_contains($text, 'māksl') || str_contains($text, 'muzej') || str_contains($text, 'glezn')) {
            $categories[] = 'Māksla & Izstādes';
        }
        if (str_contains($text, 'teātr') || str_contains($text, 'kino') || str_contains($text, 'filma') || str_contains($text, 'izrāde')) {
            $categories[] = 'Teātris & Kino';
        }
        if (str_contains($text, 'bērn') || str_contains($text, 'ģimen') || str_contains($text, 'jaunieš')) {
            $categories[] = 'Bērniem & Ģimenei';
        }
        if (str_contains($text, 'seminār') || str_contains($text, 'lekcij') || str_contains($text, 'meistarklas') || str_contains($text, 'izglītīb')) {
            $categories[] = 'Semināri & Meistarklases';
        }
        if (str_contains($text, 'svētk') || str_contains($text, 'festivāl') || str_contains($text, 'tirg') || str_contains($text, 'pašvaldīb') || str_contains($text, 'kultūr')) {
            $categories[] = 'Kultūra & Tradīcijas';
        }

        if (empty($categories)) {
            $categories[] = 'Kultūra & Tradīcijas';
        }

        return array_values(array_unique($categories));
    }

    private function resolveEntertainmentType(string $catRaw, string $title): string
    {
        $text = mb_strtolower($catRaw . ' ' . $title);

        if (str_contains($text, 'sports') || str_contains($text, 'vingro') || str_contains($text, 'skrēj')) return 'active';
        if (str_contains($text, 'koncert') || str_contains($text, 'mūzik')) return 'concert';
        if (str_contains($text, 'bērn') || str_contains($text, 'ģimen')) return 'family';
        if (str_contains($text, 'izstād') || str_contains($text, 'glezn') || str_contains($text, 'muzej')) return 'exhibition';
        if (str_contains($text, 'meistarklas') || str_contains($text, 'seminār') || str_contains($text, 'kursi')) return 'workshop';
        if (str_contains($text, 'ball') || str_contains($text, 'festivāl') || str_contains($text, 'disko')) return 'party';

        return 'chill';
    }
}
