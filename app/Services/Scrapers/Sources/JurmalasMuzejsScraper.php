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

class JurmalasMuzejsScraper extends BaseScraper
{
    public function getSlug(): string
    {
        return 'jurmalas-muzejs';
    }

    public function getName(): string
    {
        return 'Jūrmalas muzejs (Notikumu kalendārs)';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $targetUrl = $source->url ?: 'https://www.jurmalasmuzejs.lv/lv/notikumu-kalendars';

        $crawler = $this->fetchCrawler($targetUrl);

        if (!$crawler) {
            Log::warning("JurmalasMuzejsScraper: Failed to fetch crawler for {$targetUrl}");
            return $events;
        }

        try {
            $crawler->filter('div.event')->each(function (Crawler $node) use (&$events, $targetUrl) {
                $titleNode = $node->filter('h2.event-title a, .event-title');
                $title = $this->cleanText($titleNode->count() ? $titleNode->text() : '');
                if (empty($title)) {
                    return;
                }

                $linkNode = $node->filter('h2.event-title a, a[href*="/notikums/"]');
                $relLink = $linkNode->count() ? $linkNode->first()->attr('href') : '';
                $cleanUrl = $this->normalizeUrl($relLink);

                $slugPart = $cleanUrl ? basename(parse_url($cleanUrl, PHP_URL_PATH)) : Str::slug($title);
                $externalId = 'jm-' . $slugPart;

                // Dates & times
                $dateStr = $node->filter('span.date-date')->count() ? $this->cleanText($node->filter('span.date-date')->text()) : '';
                $timeStr = $node->filter('span.date-time')->count() ? $this->cleanText($node->filter('span.date-time')->text()) : '';
                [$startAt, $endAt] = $this->parseLatvianDates($dateStr, $timeStr);

                // Location info
                $locationRaw = $node->filter('span.describing-field-value')->count() ? $this->cleanText($node->filter('span.describing-field-value')->text()) : '';
                $venueInfo = $this->resolveVenue($locationRaw);

                // Categories & entertainment type
                $catRaw = $node->filter('div.event-categories a, .event-categories')->count() ? $this->cleanText($node->filter('div.event-categories a, .event-categories')->text()) : '';
                $categories = $this->resolveCategories($catRaw, $title);
                $entertainmentType = $this->resolveEntertainmentType($catRaw, $title);

                // Image
                $imageUrl = $node->filter('div.event-image img')->count() ? $node->filter('div.event-image img')->attr('src') : null;

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
                    city: 'Jūrmala',
                    region: 'Rīga un Pierīga',
                    address: $venueInfo['address'],
                    latitude: $venueInfo['lat'],
                    longitude: $venueInfo['lng'],
                    placeType: 'museum',
                    categoryNames: $categories,
                    entertainmentType: $entertainmentType,
                    isFree: true,
                    imageUrl: $imageUrl,
                    sourceUrl: $cleanUrl ?: $targetUrl,
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
        } catch (\Throwable $e) {
            Log::error("JurmalasMuzejsScraper parsing error: " . $e->getMessage(), [
                'exception' => $e,
            ]);
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
            return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'www.jurmalasmuzejs.lv') . ($parsed['path'] ?? '');
        }

        $path = strtok($relUrl, '?');
        return 'https://www.jurmalasmuzejs.lv' . (str_starts_with($path, '/') ? '' : '/') . $path;
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

            // Target the rich text editor container
            if ($crawler->filter('.cke_editable, .text__text-content')->count()) {
                $text = $crawler->filter('.cke_editable, .text__text-content')->first()->text('');
                return $this->cleanText($text);
            }
        } catch (\Throwable $e) {
            Log::debug("JurmalasMuzejs detail fetch error for {$url}: " . $e->getMessage());
        }

        return null;
    }

    private function parseLatvianDates(string $dateStr, string $timeStr = ''): array
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

        $parseSingle = function (string $p, ?int $fallbackYear = null) use ($months) {
            $p = trim($p);
            if (preg_match('/(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:[,\s]+(\d{4}))?/iu', $p, $m)) {
                $day = (int) $m[1];
                $monthName = mb_strtolower(trim($m[2]));
                $month = $months[$monthName] ?? 1;
                $year = !empty($m[3]) ? (int) $m[3] : ($fallbackYear ?? (int) date('Y'));
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
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
            $startRaw = $parseSingle($parts[0], $endYear);

            $start = $startRaw ? Carbon::parse("{$startRaw} {$startTime}") : now();
            $end = $endRaw ? Carbon::parse("{$endRaw} " . ($endTime ?: '18:00')) : null;
            return [$start, $end];
        } else {
            $startRaw = $parseSingle($parts[0]);
            $start = $startRaw ? Carbon::parse("{$startRaw} {$startTime}") : now();
            $end = $endTime && $startRaw ? Carbon::parse("{$startRaw} {$endTime}") : null;
            return [$start, $end];
        }
    }

    private function resolveVenue(string $locationRaw): array
    {
        $branches = [
            'aspazij' => [
                'name' => 'Aspazijas māja',
                'address' => 'Z. Meierovica prospekts 18/20, Jūrmala',
                'lat' => 56.9691,
                'lng' => 23.7742,
            ],
            'buldur' => [
                'name' => 'Bulduru Izstāžu nams',
                'address' => 'Muižas iela 6, Jūrmala',
                'lat' => 56.9839,
                'lng' => 23.8569,
            ],
            'brīvdab' => [
                'name' => 'Jūrmalas Brīvdabas muzejs',
                'address' => 'Tīklu iela 1A, Jūrmala',
                'lat' => 56.9856,
                'lng' => 23.8864,
            ],
            'brivdab' => [
                'name' => 'Jūrmalas Brīvdabas muzejs',
                'address' => 'Tīklu iela 1A, Jūrmala',
                'lat' => 56.9856,
                'lng' => 23.8864,
            ],
        ];

        $lower = mb_strtolower($locationRaw);
        foreach ($branches as $keyword => $info) {
            if (str_contains($lower, $keyword)) {
                return $info;
            }
        }

        return [
            'name' => 'Jūrmalas muzejs',
            'address' => 'Tirgoņu iela 29, Jūrmala',
            'lat' => 56.9723,
            'lng' => 23.7997,
        ];
    }

    private function resolveCategories(string $catRaw, string $title): array
    {
        $lower = mb_strtolower($catRaw . ' ' . $title);

        if (str_contains($lower, 'izstād') || str_contains($lower, 'māksl') || str_contains($lower, 'glezn') || str_contains($lower, 'tēlniec')) {
            return ['Izstādes & Māksla', 'Kultūra & Tradīcijas'];
        }
        if (str_contains($lower, 'lekcij') || str_contains($lower, 'konferenc') || str_contains($lower, 'diskusij')) {
            return ['Bizness & Izglītība', 'Kultūra & Tradīcijas'];
        }
        if (str_contains($lower, 'amatniek') || str_contains($lower, 'svētk') || str_contains($lower, 'diena')) {
            return ['Festivāli & Svētki', 'Ģimenēm & Bērniem'];
        }

        return ['Izstādes & Māksla', 'Kultūra & Tradīcijas'];
    }

    private function resolveEntertainmentType(string $catRaw, string $title): string
    {
        $lower = mb_strtolower($catRaw . ' ' . $title);

        if (str_contains($lower, 'izstād') || str_contains($lower, 'ekspozīcij')) {
            return 'exhibition';
        }
        if (str_contains($lower, 'lekcij') || str_contains($lower, 'konferenc')) {
            return 'education';
        }
        if (str_contains($lower, 'festivāl') || str_contains($lower, 'svētk') || str_contains($lower, 'diena')) {
            return 'family';
        }

        return 'exhibition';
    }
}
