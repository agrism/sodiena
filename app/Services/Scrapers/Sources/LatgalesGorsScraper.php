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

class LatgalesGorsScraper extends BaseScraper
{
    public function getSlug(): string
    {
        return 'latgales-gors';
    }

    public function getName(): string
    {
        return 'Latgales vēstniecība GORS';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $baseUrl = $source->url ?: 'https://www.latgalesgors.lv/lv/notikumi';
        $maxPages = 8;

        for ($page = 0; $page < $maxPages; $page++) {
            $pageUrl = $page === 0 ? $baseUrl : $baseUrl . '?page=' . $page;
            $crawler = $this->fetchCrawler($pageUrl);

            if (!$crawler) {
                break;
            }

            $nodes = $crawler->filter('#block-system-main .view-events .view-content > div');
            if ($nodes->count() === 0) {
                break;
            }

            $nodes->each(function (Crawler $node) use (&$events, $page, $baseUrl) {
                $titleNode = $node->filter('.wrapper > a')->first();
                $title = $this->cleanText($titleNode->count() ? $titleNode->text() : '');
                if (empty($title)) {
                    return;
                }

                $relLink = $titleNode->count() ? $titleNode->attr('href') : '';
                $cleanUrl = $this->normalizeUrl($relLink);

                $slugPart = $cleanUrl ? basename(parse_url($cleanUrl, PHP_URL_PATH)) : Str::slug($title);
                $externalId = 'gors-' . $slugPart;

                // Dates & times
                $dayStr = $node->filter('span.event-block-date')->count() ? $this->cleanText($node->filter('span.event-block-date')->text()) : '';
                $monthStr = $node->filter('span.event-block-month')->count() ? $this->cleanText($node->filter('span.event-block-month')->text()) : '';
                $timeStr = $node->filter('span.event-block-time')->count() ? $this->cleanText($node->filter('span.event-block-time')->text()) : '';
                $startAt = $this->parseGorsDate($dayStr, $monthStr, $timeStr, $page);

                // Image
                $imgNode = $node->filter('picture img, img.img-responsive');
                $imageUrl = $imgNode->count() ? $imgNode->attr('src') : null;

                // Short content preview
                $shortContent = $node->filter('.field-body')->count() ? $this->cleanText($node->filter('.field-body')->text()) : '';

                // Ticket URL
                $ticketUrl = null;
                $ticketNode = $node->filter('a.buy-seatmap-tickets-button, a[href*="bilesu"], a[href*="biletes"]');
                if ($ticketNode->count()) {
                    $rawTicket = $ticketNode->first()->attr('href');
                    if (!empty($rawTicket) && !str_contains($rawTicket, 'destination=')) {
                        $ticketUrl = $this->normalizeUrl($rawTicket);
                    }
                }

                // Categories & Entertainment type
                $categories = $this->resolveCategories($title, $shortContent);
                $entertainmentType = $this->resolveEntertainmentType($title, $shortContent);

                $dto = new ScrapedEventDTO(
                    title: $title,
                    startAt: $startAt,
                    endAt: null,
                    description: $shortContent,
                    shortDescription: Str::limit(strip_tags($shortContent), 160),
                    venueName: 'Latgales vēstniecība GORS',
                    city: 'Rēzekne',
                    region: 'Latgale',
                    address: 'Pils iela 4, Rēzekne, LV-4601',
                    latitude: 56.5028,
                    longitude: 27.3323,
                    placeType: 'concert_hall',
                    categoryNames: $categories,
                    entertainmentType: $entertainmentType,
                    isFree: false,
                    priceMin: null,
                    priceMax: null,
                    currency: 'EUR',
                    ticketUrl: $ticketUrl,
                    imageUrl: $imageUrl,
                    sourceUrl: $cleanUrl ?: $baseUrl,
                    sourceExternalId: $externalId,
                    locale: 'lv',
                    rawData: [
                        'day_raw' => $dayStr,
                        'month_raw' => $monthStr,
                        'time_raw' => $timeStr,
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
            return $relUrl;
        }

        $path = strtok($relUrl, '?');
        return 'https://www.latgalesgors.lv' . (str_starts_with($path, '/') ? '' : '/') . $path;
    }

    private function parseGorsDate(string $dayStr, string $monthStr, string $timeStr, int $page): Carbon
    {
        $months = [
            'jan' => 1, 'janv' => 1, 'janvāris' => 1,
            'feb' => 2, 'febr' => 2, 'februāris' => 2,
            'mar' => 3, 'marts' => 3,
            'apr' => 4, 'aprīlis' => 4,
            'mai' => 5, 'maijs' => 5,
            'jūn' => 6, 'jun' => 6, 'jūnijs' => 6,
            'jūl' => 7, 'jul' => 7, 'jūlijs' => 7,
            'aug' => 8, 'augusts' => 8,
            'sep' => 9, 'sept' => 9, 'septembris' => 9,
            'okt' => 10, 'oktobris' => 10,
            'nov' => 11, 'novembris' => 11,
            'dec' => 12, 'decembris' => 12,
        ];

        $currentYear = (int) date('Y');
        $currentMonth = (int) date('n');

        $day = (int) preg_replace('/\D/', '', $dayStr) ?: 1;
        $monthClean = mb_strtolower(preg_replace('/[^a-zāčēģīķļņšūž]/iu', '', $monthStr));
        $month = $months[$monthClean] ?? $currentMonth;

        // If month is earlier in the year than current month and we are on later pages, it rolls into next year
        $year = ($month < $currentMonth && $page >= 2) ? $currentYear + 1 : $currentYear;

        $hour = 19;
        $minute = 0;
        if (preg_match('/(\d{1,2}):(\d{2})/', $timeStr, $tm)) {
            $hour = (int) $tm[1];
            $minute = (int) $tm[2];
        }

        return Carbon::create($year, $month, $day, $hour, $minute, 0);
    }

    private function resolveCategories(string $title, string $desc): array
    {
        $lower = mb_strtolower($title . ' ' . $desc);

        if (str_contains($lower, 'koncert') || str_contains($lower, 'dziesm') || str_contains($lower, 'orķestr') || str_contains($lower, 'grupa') || str_contains($lower, 'mūzik')) {
            return ['Mūzika & Koncerti', 'Kultūra & Tradīcijas'];
        }
        if (str_contains($lower, 'teātr') || str_contains($lower, 'izrāde') || str_contains($lower, 'komēdij') || str_contains($lower, 'opera') || str_contains($lower, 'rokopera')) {
            return ['Teātris & Izrādes', 'Kultūra & Tradīcijas'];
        }
        if (str_contains($lower, 'kino') || str_contains($lower, 'filma') || str_contains($lower, 'īsfilm') || str_contains($lower, 'festivāls')) {
            return ['Kino & Filmas', 'Festivāli & Svētki'];
        }
        if (str_contains($lower, 'bērn') || str_contains($lower, 'ģimen')) {
            return ['Ģimenēm & Bērniem', 'Teātris & Izrādes'];
        }
        if (str_contains($lower, 'izstād') || str_contains($lower, 'māksl')) {
            return ['Izstādes & Māksla', 'Kultūra & Tradīcijas'];
        }

        return ['Mūzika & Koncerti', 'Kultūra & Tradīcijas'];
    }

    private function resolveEntertainmentType(string $title, string $desc): string
    {
        $lower = mb_strtolower($title . ' ' . $desc);

        if (str_contains($lower, 'koncert') || str_contains($lower, 'dziesm') || str_contains($lower, 'melo-m')) {
            return 'concert';
        }
        if (str_contains($lower, 'izrāde') || str_contains($lower, 'teātr') || str_contains($lower, 'komēdij')) {
            return 'theatre';
        }
        if (str_contains($lower, 'filma') || str_contains($lower, 'kino') || str_contains($lower, 'drāma') || str_contains($lower, 'īsfilm')) {
            return 'movie';
        }
        if (str_contains($lower, 'bērn') || str_contains($lower, 'ģimen')) {
            return 'family';
        }

        return 'concert';
    }
}
