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

class DzejasDienasScraper extends BaseScraper
{
    public function getSlug(): string
    {
        return 'dzejas-dienas';
    }

    public function getName(): string
    {
        return 'Dzejas dienas (Programma)';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $targetUrl = $source->url ?: 'https://www.dzejasdienas.com/programma/';

        $crawler = $this->fetchCrawler($targetUrl);

        if (!$crawler) {
            Log::warning("DzejasDienasScraper: Failed to fetch crawler for {$targetUrl}");
            return $events;
        }

        try {
            $currentDateHeading = '';

            $crawler->filter('.event-summary-list > *')->each(function (Crawler $node) use (&$events, &$currentDateHeading, $targetUrl) {
                if ($node->nodeName() === 'h3') {
                    $currentDateHeading = $this->cleanText($node->text(''));
                    return;
                }

                if ($node->nodeName() === 'div' && $node->filter('a.event-summary')->count()) {
                    $node->filter('a.event-summary')->each(function (Crawler $itemNode) use (&$events, $currentDateHeading, $targetUrl) {
                        $titleNode = $itemNode->filter('.event-summary__title');
                        $title = $this->cleanText($titleNode->count() ? $titleNode->text() : '');
                        if (empty($title)) {
                            return;
                        }

                        $relUrl = $itemNode->attr('href');
                        $cleanUrl = $this->normalizeUrl($relUrl);

                        $slugPart = $cleanUrl ? basename(parse_url($cleanUrl, PHP_URL_PATH)) : Str::slug($title);
                        $externalId = 'dzed-' . $slugPart;

                        // Time & Date parsing
                        $timeStr = $itemNode->filter('.event-summary__time')->count() ? $this->cleanText($itemNode->filter('.event-summary__time')->text()) : '';
                        $startAt = $this->parseDzejasDienasDate($currentDateHeading, $timeStr);

                        // Short text
                        $shortContent = $itemNode->filter('.event-summary__text')->count() ? $this->cleanText($itemNode->filter('.event-summary__text')->text()) : '';

                        // Location resolution
                        $venueInfo = $this->resolveLocation($title, $shortContent);

                        // Category & entertainment type
                        $categories = ['Kultūra & Tradīcijas', 'Izstādes & Māksla'];
                        $entertainmentType = 'culture';

                        if (str_contains(mb_strtolower($title . ' ' . $shortContent), 'bērn') || str_contains(mb_strtolower($title), 'ģimen')) {
                            $categories = ['Ģimenēm & Bērniem', 'Kultūra & Tradīcijas'];
                            $entertainmentType = 'family';
                        } elseif (str_contains(mb_strtolower($title . ' ' . $shortContent), 'koncert') || str_contains(mb_strtolower($title), 'dzied')) {
                            $categories = ['Mūzika & Koncerti', 'Kultūra & Tradīcijas'];
                            $entertainmentType = 'concert';
                        }

                        $dto = new ScrapedEventDTO(
                            title: $title,
                            startAt: $startAt,
                            endAt: null,
                            description: $shortContent,
                            shortDescription: Str::limit(strip_tags($shortContent), 160),
                            venueName: $venueInfo['name'],
                            city: $venueInfo['city'],
                            region: $venueInfo['region'],
                            address: $venueInfo['address'],
                            latitude: $venueInfo['lat'],
                            longitude: $venueInfo['lng'],
                            placeType: 'culture_centre',
                            categoryNames: $categories,
                            entertainmentType: $entertainmentType,
                            isFree: true,
                            imageUrl: 'https://www.dzejasdienas.com/wp-content/uploads/2019/09/aplis-default-og-img.jpg',
                            sourceUrl: $cleanUrl ?: $targetUrl,
                            sourceExternalId: $externalId,
                            locale: 'lv',
                            rawData: [
                                'date_heading' => $currentDateHeading,
                                'time_raw' => $timeStr,
                            ]
                        );

                        $events->push($dto);
                    });
                }
            });
        } catch (\Throwable $e) {
            Log::error("DzejasDienasScraper parsing error: " . $e->getMessage(), [
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
            return rtrim(strtok($relUrl, '?'), '/');
        }

        $path = rtrim(strtok($relUrl, '?'), '/');
        return 'https://www.dzejasdienas.com' . (str_starts_with($path, '/') ? '' : '/') . $path;
    }

    private function parseDzejasDienasDate(string $dateHeading, string $timeStr): Carbon
    {
        $months = [
            'janvāris' => 1, 'februāris' => 2, 'marts' => 3, 'aprīlis' => 4,
            'maijs' => 5, 'jūnijs' => 6, 'jūlijs' => 7, 'augusts' => 8,
            'septembris' => 9, 'oktobris' => 10, 'novembris' => 11, 'decembris' => 12,
        ];

        $currentYear = (int) date('Y');
        $day = 13;
        $month = 9;

        if (preg_match('/(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)/iu', $dateHeading, $m)) {
            $day = (int) $m[1];
            $monthName = mb_strtolower($m[2]);
            $month = $months[$monthName] ?? 9;
        }

        $hour = 12;
        $minute = 0;
        if (preg_match('/(\d{1,2})[\.:](\d{2})/', $timeStr, $tm)) {
            $hour = (int) $tm[1];
            $minute = (int) $tm[2];
        }

        return Carbon::create($currentYear, $month, $day, $hour, $minute, 0);
    }

    private function resolveLocation(string $title, string $content): array
    {
        $text = mb_strtolower($title . ' ' . $content);

        if (str_contains($text, 'buldur') || str_contains($text, 'kaugur') || str_contains($text, 'jūrmal') || str_contains($text, 'jurmal') || str_contains($text, 'dubult') || str_contains($text, 'major')) {
            $venue = 'Bulduru lapene pie jūras';
            if (str_contains($text, 'kaugur')) {
                $venue = 'Kauguru parks';
            } elseif (str_contains($text, 'bibliotēk')) {
                $venue = 'Jūrmalas Centrālā bibliotēka';
            }

            return [
                'name' => $venue,
                'city' => 'Jūrmala',
                'region' => 'Rīga un Pierīga',
                'address' => 'Bulduri, Jūrmala',
                'lat' => 56.9839,
                'lng' => 23.8569,
            ];
        }

        if (str_contains($text, 'liepāj') || str_contains($text, 'liepaja')) {
            return [
                'name' => 'Pegaza pagalms / Liepājas bibliotēka',
                'city' => 'Liepāja',
                'region' => 'Kurzeme',
                'address' => 'Kuršu iela 20, Liepāja',
                'lat' => 56.5047,
                'lng' => 21.0108,
            ];
        }

        if (str_contains($text, 'ventspil')) {
            return [
                'name' => 'Ventspils Galvenā bibliotēka',
                'city' => 'Ventspils',
                'region' => 'Kurzeme',
                'address' => 'Akmeņu iela 2, Ventspils',
                'lat' => 57.3958,
                'lng' => 21.5683,
            ];
        }

        if (str_contains($text, 'tals')) {
            return [
                'name' => 'Talsu Galvenā bibliotēka',
                'city' => 'Talsi',
                'region' => 'Kurzeme',
                'address' => 'Brīvības iela 17A, Talsi',
                'lat' => 57.2458,
                'lng' => 22.5894,
            ];
        }

        if (str_contains($text, 'kuldīg') || str_contains($text, 'kuldiga')) {
            return [
                'name' => 'Kuldīgas pilsētas dārzs & bibliotēka',
                'city' => 'Kuldīga',
                'region' => 'Kurzeme',
                'address' => 'Kuldīga',
                'lat' => 56.9678,
                'lng' => 21.9705,
            ];
        }

        if (str_contains($text, 'gulben')) {
            return [
                'name' => 'Gulbenes bibliotēka',
                'city' => 'Gulbene',
                'region' => 'Vidzeme',
                'address' => 'Gulbene',
                'lat' => 57.1786,
                'lng' => 26.7533,
            ];
        }

        // Default Riga
        return [
            'name' => 'Esplanāde & Rīgas kultūrtelpas',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Esplanāde, Rīga',
            'lat' => 56.9538,
            'lng' => 24.1162,
        ];
    }
}
