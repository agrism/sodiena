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
                if (empty($title)) {
                    return;
                }

                $slugPart = basename(parse_url($cleanUrl, PHP_URL_PATH));
                $externalId = 'mm-' . $slugPart;

                $fullDescription = !empty($detail['description']) ? $detail['description'] : $excerpt;
                $imageUrl = !empty($detail['image']) ? $detail['image'] : $thumbImg;

                // Parse dates and times from text and fallback to publish date
                [$startAt, $endAt] = $this->extractDates($title . ' ' . $fullDescription, $pubDateStr);

                // Ignore events older than 1 year ago unless ongoing
                if ($endAt && $endAt->isPast() && $endAt->diffInDays(now()) > 365) {
                    return;
                }
                if (!$endAt && $startAt->isPast() && $startAt->diffInDays(now()) > 180) {
                    return;
                }

                // Location resolution
                $venueInfo = $this->resolveLocation($title . ' ' . $fullDescription);

                // Categories & entertainment type
                $categories = $this->resolveCategories($title . ' ' . $fullDescription);
                $entertainmentType = $this->resolveEntertainmentType($title . ' ' . $fullDescription);

                // Price detection
                [$isFree, $priceMin] = $this->detectPrice($fullDescription);

                $dto = new ScrapedEventDTO(
                    title: $title,
                    startAt: $startAt,
                    endAt: $endAt,
                    description: $fullDescription,
                    shortDescription: Str::limit(strip_tags($excerpt ?: $fullDescription), 160),
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

            // Text paragraphs
            $paragraphs = [];
            $textNode = $crawler->filter('.text-block div[data-admin-inline-editable="true"], .content-inner');
            if ($textNode->count()) {
                $html = $textNode->first()->html();
                // Convert <br> to newlines
                $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
                $crawlerText = new Crawler($html);
                $rawText = $crawlerText->text();

                // Clean social footer lines
                $cleaned = preg_replace('/Patīk šis raksts.*$/us', '', $rawText);
                $cleaned = preg_replace('/^.*?skatījumi\s+/u', '', $cleaned);

                $lines = array_filter(array_map('trim', explode("\n", $cleaned)));
                $paragraphs = array_values($lines);
            }

            $description = implode("\n\n", $paragraphs);

            return [
                'title' => $title,
                'image' => $image,
                'description' => $description,
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
            return rtrim(strtok($relUrl, '?'), '/');
        }

        $path = rtrim(strtok($relUrl, '?'), '/');
        return 'http://www.madonasmuzejs.lv' . (str_starts_with($path, '/') ? '' : '/') . $path;
    }

    private function extractDates(string $text, string $pubDateStr): array
    {
        $months = [
            'janvār' => 1, 'februār' => 2, 'mart' => 3, 'aprīl' => 4,
            'maij' => 5, 'jūnij' => 6, 'jūlij' => 7, 'august' => 8,
            'septembr' => 9, 'oktobr' => 10, 'novembr' => 11, 'decembr' => 12,
        ];

        $currentYear = (int) date('Y');

        // Pattern 1: "No 2026.gada 12.septembra ... līdz 22.novembrim"
        if (preg_match('/(?:No|no)\s+(\d{4})\.\s*gada\s+(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)[^\.\n]*?(?:līdz|–|-)\s+(?:(\d{4})\.\s*gada\s+)?(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)/iu', $text, $m)) {
            $startYear = (int) $m[1];
            $startDay = (int) $m[2];
            $startMonth = $this->matchMonth($m[3], $months);
            $endYear = !empty($m[4]) ? (int) $m[4] : $startYear;
            $endDay = (int) $m[5];
            $endMonth = $this->matchMonth($m[6], $months);

            $time = $this->extractTime($text) ?? '10:00';
            $startAt = Carbon::create($startYear, $startMonth, $startDay, (int)substr($time, 0, 2), (int)substr($time, 3, 2), 0);
            $endAt = Carbon::create($endYear, $endMonth, $endDay, 18, 0, 0);
            return [$startAt, $endAt];
        }

        // Pattern 2: "No 12.septembra līdz 22.novembrim"
        if (preg_match('/(?:No|no)\s+(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)[^\.\n]*?(?:līdz|–|-)\s+(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:\s+(\d{4}))?/iu', $text, $m)) {
            $year = !empty($m[5]) ? (int) $m[5] : $currentYear;
            $startDay = (int) $m[1];
            $startMonth = $this->matchMonth($m[2], $months);
            $endDay = (int) $m[3];
            $endMonth = $this->matchMonth($m[4], $months);

            $time = $this->extractTime($text) ?? '10:00';
            $startAt = Carbon::create($year, $startMonth, $startDay, (int)substr($time, 0, 2), (int)substr($time, 3, 2), 0);
            $endAt = Carbon::create($year, $endMonth, $endDay, 18, 0, 0);
            return [$startAt, $endAt];
        }

        // Pattern 3: "2026.gada 8.augustā plkst.11.30" or "12.septembrī plkst.13.00"
        if (preg_match('/(?:(\d{4})\.\s*gada\s+)?(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:\s+plkst\.?\s*(\d{1,2}[\.:]\d{2}))?/iu', $text, $m)) {
            $year = !empty($m[1]) ? (int) $m[1] : $currentYear;
            $day = (int) $m[2];
            $month = $this->matchMonth($m[3], $months);
            $time = !empty($m[4]) ? str_replace('.', ':', $m[4]) : ($this->extractTime($text) ?? '12:00');
            $parts = explode(':', $time);

            $startAt = Carbon::create($year, $month, $day, (int)($parts[0] ?? 12), (int)($parts[1] ?? 0), 0);
            return [$startAt, null];
        }

        // Fallback: parse publication date
        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $pubDateStr, $dm)) {
            $startAt = Carbon::create((int)$dm[3], (int)$dm[2], (int)$dm[1], 10, 0, 0);
            return [$startAt, null];
        }

        return [now(), null];
    }

    private function matchMonth(string $name, array $months): int
    {
        $lower = mb_strtolower(trim($name));
        foreach ($months as $prefix => $m) {
            if (str_starts_with($lower, $prefix)) {
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
}
