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

class DaugavpilsScraper extends BaseScraper
{
    public function getSlug(): string
    {
        return 'daugavpils-dome';
    }

    public function getName(): string
    {
        return 'Daugavpils valstspilsēta (Afiša)';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $baseUrl = $source->url ?: 'https://www.daugavpils.lv/afisa/';
        $maxPages = 6;
        $seenUrls = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $pageUrl = $page === 1 ? $baseUrl : rtrim($baseUrl, '/') . '/?page=' . $page;
            $crawler = $this->fetchCrawler($pageUrl);

            if (!$crawler) {
                break;
            }

            $cards = $crawler->filter('.afisa-posts .col-xs-6, .afisa-posts > div');
            if ($cards->count() === 0) {
                break;
            }

            $cards->each(function (Crawler $node) use (&$events, &$seenUrls, $baseUrl) {
                $linkNode = $node->filter('a')->first();
                if (!$linkNode->count()) {
                    return;
                }

                $relLink = $linkNode->attr('href');
                $cleanUrl = $this->normalizeUrl($relLink);
                if (empty($cleanUrl) || in_array($cleanUrl, $seenUrls)) {
                    return;
                }
                $seenUrls[] = $cleanUrl;

                $titleNode = $node->filter('.event-text h3, h3');
                $title = $this->cleanText($titleNode->count() ? $titleNode->text() : $linkNode->attr('title'));
                if (empty($title)) {
                    return;
                }

                $slugPart = basename(parse_url($cleanUrl, PHP_URL_PATH));
                $externalId = 'daugavpils-' . $slugPart;

                // Dates & times
                [$startAt, $endAt] = $this->extractDates($node);

                // Location info
                $placeRaw = $node->filter('.event-text p.place, p.place')->count()
                    ? $this->cleanText($node->filter('.event-text p.place, p.place')->text())
                    : 'Daugavpils';
                $locationInfo = $this->resolveLocation($placeRaw);

                // Image
                $imgNode = $node->filter('.event-img img, img.event-pict');
                $imageUrl = $imgNode->count() ? $imgNode->attr('src') : null;
                if ($imageUrl && !str_starts_with($imageUrl, 'http')) {
                    $imageUrl = 'https://www.daugavpils.lv' . (str_starts_with($imageUrl, '/') ? '' : '/') . $imageUrl;
                }

                // Categories & Entertainment Type
                $categories = $this->resolveCategories($title);
                $entertainmentType = $this->resolveEntertainmentType($title);

                // Fetch detail description
                $fullDescription = $this->fetchDetailDescription($cleanUrl);
                $shortDescription = $fullDescription ? Str::limit(strip_tags($fullDescription), 160) : $title;

                $dto = new ScrapedEventDTO(
                    title: $title,
                    startAt: $startAt,
                    endAt: $endAt,
                    description: $fullDescription,
                    shortDescription: $shortDescription,
                    venueName: $locationInfo['name'],
                    city: 'Daugavpils',
                    region: 'Latgale',
                    address: $locationInfo['address'],
                    placeType: 'venue',
                    categoryNames: $categories,
                    entertainmentType: $entertainmentType,
                    isFree: false,
                    imageUrl: $imageUrl,
                    sourceUrl: $cleanUrl,
                    sourceExternalId: $externalId,
                    locale: 'lv',
                    rawData: [
                        'place_raw' => $placeRaw,
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
            return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'www.daugavpils.lv') . ($parsed['path'] ?? '');
        }

        $path = strtok($relUrl, '?');
        return 'https://www.daugavpils.lv' . (str_starts_with($path, '/') ? '' : '/') . $path;
    }

    private function fetchDetailDescription(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        try {
            $crawler = $this->fetchCrawler($url);
            if (!$crawler) {
                return null;
            }

            $paragraphs = [];
            $crawler->filter('article > p')->each(function (Crawler $pNode) use (&$paragraphs) {
                $text = $this->cleanText($pNode->text());
                if (!empty($text)) {
                    $paragraphs[] = $text;
                }
            });

            if (!empty($paragraphs)) {
                return implode("\n\n", $paragraphs);
            }
        } catch (\Throwable $e) {
            Log::debug("DaugavpilsScraper detail fetch error for {$url}: " . $e->getMessage());
        }

        return null;
    }

    public function extractDates(Crawler $node): array
    {
        $months = [
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mai' => 5, 'may' => 5,
            'jun' => 6, 'jūn' => 6, 'jul' => 7, 'jūl' => 7, 'aug' => 8, 'sep' => 9,
            'okt' => 10, 'oct' => 10, 'nov' => 11, 'dec' => 12,
            'janvāris' => 1, 'februāris' => 2, 'marts' => 3, 'aprīlis' => 4,
            'maijs' => 5, 'jūnijs' => 6, 'jūlijs' => 7, 'augusts' => 8,
            'septembris' => 9, 'oktobris' => 10, 'novembris' => 11, 'decembris' => 12,
        ];

        $currentYear = (int) date('Y');
        $currentMonth = (int) date('m');

        // Multi-day format (.date1, .date2)
        if ($node->filter('.date1')->count() && $node->filter('.date2')->count()) {
            $date1Text = trim($node->filter('.date1')->text());
            $time1Text = $node->filter('.time1')->count() ? trim($node->filter('.time1')->text()) : '10:00';
            $date2Text = trim($node->filter('.date2')->text());
            $time2Text = $node->filter('.time2')->count() ? trim($node->filter('.time2')->text()) : '18:00';

            $parseDateStr = function ($dStr, $tStr) use ($months, $currentYear, $currentMonth) {
                if (preg_match('/(\d{1,2})\s*([a-zāčēģīķļņšūž]+)/iu', $dStr, $m)) {
                    $day = (int) $m[1];
                    $mKey = mb_strtolower(trim($m[2]));
                    $mon = $months[$mKey] ?? $currentMonth;
                    $yr = ($mon < $currentMonth - 2) ? $currentYear + 1 : $currentYear;
                    $time = preg_match('/(\d{1,2}):(\d{2})/', $tStr, $tm) ? sprintf('%02d:%02d', $tm[1], $tm[2]) : '10:00';
                    return Carbon::parse(sprintf('%04d-%02d-%02d %s', $yr, $mon, $day, $time));
                }
                return now();
            };

            $start = $parseDateStr($date1Text, $time1Text);
            $end = $parseDateStr($date2Text, $time2Text);
            return [$start, $end];
        }

        // Single day format (.date, .month, .time)
        $dayText = $node->filter('.event-date .date, .date')->count() ? trim($node->filter('.event-date .date, .date')->text()) : date('d');
        $monthText = $node->filter('.event-date .month, .month')->count() ? trim($node->filter('.event-date .month, .month')->text()) : '';
        $timeText = $node->filter('.event-date .time, .time')->count() ? trim($node->filter('.event-date .time, .time')->text()) : '18:00';

        $day = (int) preg_replace('/[^\d]/', '', $dayText) ?: (int) date('d');
        $mKey = mb_strtolower(trim($monthText));
        $mon = $months[$mKey] ?? $currentMonth;
        $yr = ($mon < $currentMonth - 2) ? $currentYear + 1 : $currentYear;

        $startTime = '18:00';
        $endTime = null;
        if (preg_match('/(\d{1,2})[\.:](\d{2})/i', $timeText, $tm)) {
            $startTime = sprintf('%02d:%02d', $tm[1], $tm[2]);
        }

        $start = Carbon::parse(sprintf('%04d-%02d-%02d %s', $yr, $mon, $day, $startTime));
        return [$start, $endTime];
    }

    public function resolveLocation(string $placeRaw): array
    {
        $raw = trim($placeRaw);
        if (empty($raw) || $raw === 'Daugavpils') {
            return [
                'name' => 'Daugavpils',
                'address' => 'Daugavpils',
            ];
        }

        $parts = array_map('trim', explode(',', $raw));

        if (count($parts) >= 2) {
            $venueName = $parts[0];
            $address = implode(', ', array_slice($parts, 1));
            if (!str_contains($address, 'Daugavpils')) {
                $address .= ', Daugavpils';
            }
            return [
                'name' => $venueName,
                'address' => $address,
            ];
        }

        return [
            'name' => $raw,
            'address' => $raw . ', Daugavpils',
        ];
    }

    public function resolveCategories(string $title): array
    {
        $categories = [];
        $text = mb_strtolower($title);

        if (str_contains($text, 'koncert') || str_contains($text, 'mūzik') || str_contains($text, 'dziesm') || str_contains($text, 'koklē')) {
            $categories[] = 'Mūzika & Koncerti';
        }
        if (str_contains($text, 'izstād') || str_contains($text, 'māksl') || str_contains($text, 'glezn') || str_contains($text, 'muzej') || str_contains($text, 'keramik')) {
            $categories[] = 'Māksla & Izstādes';
        }
        if (str_contains($text, 'teātr') || str_contains($text, 'izrāde') || str_contains($text, 'kino') || str_contains($text, 'filma')) {
            $categories[] = 'Teātris & Kino';
        }
        if (str_contains($text, 'sports') || str_contains($text, 'skrēj') || str_contains($text, 'vingro') || str_contains($text, 'aktīv') || str_contains($text, 'veselīb')) {
            $categories[] = 'Sports & Aktīvā atpūta';
        }
        if (str_contains($text, 'bērn') || str_contains($text, 'ģimen') || str_contains($text, 'pasak')) {
            $categories[] = 'Bērniem & Ģimenei';
        }
        if (str_contains($text, 'meistarklas') || str_contains($text, 'nodarbīb') || str_contains($text, 'darbnīc') || str_contains($text, 'lekcij') || str_contains($text, 'kursi')) {
            $categories[] = 'Semināri & Meistarklases';
        }
        if (str_contains($text, 'svētk') || str_contains($text, 'festivāl') || str_contains($text, 'tirg') || str_contains($text, 'dzej') || str_contains($text, 'literār')) {
            $categories[] = 'Kultūra & Tradīcijas';
        }

        if (empty($categories)) {
            $categories[] = 'Kultūra & Tradīcijas';
        }

        return array_values(array_unique($categories));
    }

    public function resolveEntertainmentType(string $title): string
    {
        $text = mb_strtolower($title);

        if (str_contains($text, 'sports') || str_contains($text, 'vingro') || str_contains($text, 'skrēj')) return 'active';
        if (str_contains($text, 'koncert') || str_contains($text, 'mūzik') || str_contains($text, 'koklē')) return 'concert';
        if (str_contains($text, 'bērn') || str_contains($text, 'ģimen')) return 'family';
        if (str_contains($text, 'izstād') || str_contains($text, 'glezn') || str_contains($text, 'muzej')) return 'exhibition';
        if (str_contains($text, 'meistarklas') || str_contains($text, 'nodarbīb') || str_contains($text, 'darbnīc')) return 'workshop';
        if (str_contains($text, 'ball') || str_contains($text, 'festivāl') || str_contains($text, 'disko')) return 'party';

        return 'chill';
    }
}
