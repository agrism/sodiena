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

class OgresMuzejsScraper extends BaseScraper
{
    public const BASE_URL = 'https://ogresmuzejs.lv';
    public const OFFERS_PATH = '/piedavajums';
    public const DEFAULT_IMAGE = 'https://ogresmuzejs.lv/wp-content/uploads/2025/07/cropped-OM_fav-192x192.png';

    public function getSlug(): string
    {
        return 'ogres-muzejs';
    }

    public function getName(): string
    {
        return 'Ogres Vēstures un mākslas muzejs';
    }

    /**
     * Scrape exhibitions from ogresmuzejs.lv
     */
    public function scrape(Source $source): Collection
    {
        $events = collect();
        $url = self::BASE_URL . self::OFFERS_PATH;

        Log::info("OgresMuzejsScraper: Fetching offers page {$url}");

        try {
            $crawler = $this->fetchCrawler($url);
        } catch (\Throwable $e) {
            Log::error("OgresMuzejsScraper: Failed to fetch {$url}: " . $e->getMessage());
            return $events;
        }

        if (!$crawler) {
            Log::warning("OgresMuzejsScraper: Empty crawler returned from {$url}");
            return $events;
        }

        $items = $this->extractExhibitionCards($crawler);
        Log::info("OgresMuzejsScraper: Found " . count($items) . " potential exhibition cards");

        $today = Carbon::today('Europe/Riga');

        foreach ($items as $itemData) {
            try {
                $dto = $this->processExhibitionItem($itemData, $today);
                if ($dto) {
                    $events->push($dto);
                }
            } catch (\Throwable $e) {
                Log::warning("OgresMuzejsScraper: Failed to parse exhibition item: " . $e->getMessage(), [
                    'url' => $itemData['url'] ?? null,
                    'title' => $itemData['title'] ?? null,
                    'exception' => $e,
                ]);
            }
        }

        Log::info("OgresMuzejsScraper: Successfully extracted {$events->count()} active/upcoming exhibitions");

        return $events;
    }

    /**
     * Extract exhibition cards strictly from "Aktuālās izstādes" and "Gaidāmās izstādes" sections
     */
    public function extractExhibitionCards(Crawler $crawler): array
    {
        $items = [];

        $crawler->filter('h3.section-title, h3')->each(function (Crawler $h) use (&$items) {
            $headingText = trim($h->text());
            if (!str_contains($headingText, 'Aktuālās izstādes') && !str_contains($headingText, 'Gaidāmās izstādes')) {
                return;
            }

            $sectionType = str_contains($headingText, 'Aktuālās') ? 'current' : 'upcoming';
            $row = $h->closest('.row');
            if (!$row || !$row->count()) {
                return;
            }

            $row->filter('.post-item, .box')->each(function (Crawler $box) use (&$items, $sectionType) {
                $link = $box->filter('a[href*="ogresmuzejs.lv/"]');
                if (!$link->count()) {
                    return;
                }

                $url = $link->attr('href');
                if (empty($url) || str_contains($url, '/piedavajums') || isset($items[$url])) {
                    return;
                }

                $titleNode = $box->filter('.post-title, h5, h4, h3, .box-text a');
                $title = $titleNode->count() ? trim($titleNode->text()) : '';
                if (empty($title)) {
                    return;
                }

                $cardText = trim($box->text());
                $img = $box->filter('img');
                $imgSrc = $img->count() ? ($img->attr('data-src') ?: $img->attr('src')) : null;

                $items[$url] = [
                    'section' => $sectionType,
                    'url' => $url,
                    'title' => $title,
                    'card_text' => $cardText,
                    'image' => $imgSrc,
                ];
            });
        });

        return $items;
    }

    /**
     * Process an exhibition item, fetch detail page, extract dates, and build DTO
     */
    public function processExhibitionItem(array $itemData, Carbon $today): ?ScrapedEventDTO
    {
        $url = $itemData['url'];
        $cardText = $itemData['card_text'] ?? '';
        $cardTitle = $itemData['title'] ?? '';

        // Fetch detail page
        $detailCrawler = $this->fetchCrawler($url);
        $detailText = '';
        $mainImage = $itemData['image'] ?? null;
        $fullTitle = $cardTitle;

        if ($detailCrawler) {
            $titleNode = $detailCrawler->filter('.entry-title, h1');
            if ($titleNode->count() && !empty(trim($titleNode->text()))) {
                $fullTitle = trim($titleNode->text());
            }

            $imgNode = $detailCrawler->filter('.entry-image img, .wp-post-image, .entry-content img');
            if ($imgNode->count()) {
                $src = $imgNode->attr('data-src') ?: $imgNode->attr('src');
                if ($src && !str_contains($src, 'fav-') && !str_contains($src, 'logo')) {
                    $mainImage = $src;
                }
            }

            $contentNode = $detailCrawler->filter('.entry-content');
            if ($contentNode->count()) {
                $detailText = $this->cleanDetailText($contentNode->html());
            }
        }

        // Parse exhibition dates (from card text or detail content)
        $dateRange = $this->parseExhibitionDates($cardText, $detailText, $today);

        // Strict Rule: Skip permanent exhibitions without dates (e.g. Brīvības ceļš, Pietura Ogre)
        if (!$dateRange) {
            Log::info("OgresMuzejsScraper: Skipping dateless / permanent exhibition '{$fullTitle}'");
            return null;
        }

        [$startAt, $endAt] = $dateRange;

        // AGENTS.md Rule: Skip past events
        if ($endAt && $endAt < $today) {
            Log::info("OgresMuzejsScraper: Skipping past exhibition '{$fullTitle}' (ended {$endAt->toDateString()})");
            return null;
        }

        // External ID from slug
        $path = trim(parse_url($url, PHP_URL_PATH), '/');
        $slug = basename($path) ?: md5($url);
        $sourceExternalId = "ogresmuzejs-{$slug}";

        // Resolve Venue & Address (Main building vs Brīvības ceļš branch)
        $venueData = $this->resolveVenueAndAddress($fullTitle, $detailText);

        // Pricing
        $isFree = (bool) preg_match('/bezmaksas|bez\s+maksas|brīva\s+ieeja|ieeja\s+brīva/ui', $detailText);
        $priceMin = $isFree ? 0.0 : null;
        $priceMax = $isFree ? 0.0 : null;

        $description = !empty($detailText) ? $detailText : $cardText;
        $shortDescription = mb_strlen($description) <= 220 ? $description : Str::limit($description, 160);

        return new ScrapedEventDTO(
            title: $fullTitle,
            startAt: $startAt,
            endAt: $endAt,
            description: $description,
            shortDescription: $shortDescription,
            venueName: $venueData['name'],
            city: $venueData['city'],
            region: $venueData['region'],
            address: $venueData['address'],
            placeType: $venueData['place_type'],
            categoryNames: ['Māksla un izstādes'],
            entertainmentType: 'exhibition',
            isFree: $isFree,
            priceMin: $priceMin,
            priceMax: $priceMax,
            currency: 'EUR',
            ticketUrl: null,
            imageUrl: $mainImage,
            sourceUrl: $url,
            sourceExternalId: $sourceExternalId,
            locale: 'lv',
            rawData: [
                'slug' => $slug,
                'source' => 'ogresmuzejs.lv',
                'section' => $itemData['section'] ?? null,
            ]
        );
    }

    /**
     * Parse exhibition dates from card text and detail content
     */
    public function parseExhibitionDates(string $cardText, string $detailText, Carbon $referenceDate): ?array
    {
        $months = [
            'janv' => 1, 'febru' => 2, 'mart' => 3, 'aprīl' => 4, 'april' => 4,
            'maij' => 5, 'jūnij' => 6, 'junij' => 6, 'jūlij' => 7, 'julij' => 7,
            'august' => 8, 'septembr' => 9, 'oktobr' => 10, 'novembr' => 11, 'decembr' => 12,
        ];

        $combined = mb_strtolower($cardText . ' ' . $detailText, 'UTF-8');

        // Pattern 1: No [YYYY. gada] D. Mēneša līdz [YYYY. gada] D. Mēnesim
        // Examples:
        // "No 2026. gada 9. oktobra līdz 2027. gada 9. janvārim"
        // "No 25. septembra līdz 27. decembrim"
        // "No 2027. gada 22.janvāra līdz 28.martam"
        $rangePattern = '/(?:no\s+)?(?:(\d{4})\.\s*gad[a-z]*\s+)?(\d{1,2})\.?\s*([a-zāčēģīķļņšūž]+)\s+līdz\s+(?:(\d{4})\.\s*gad[a-z]*\s+)?(\d{1,2})\.?\s*([a-zāčēģīķļņšūž]+)/ui';

        if (preg_match($rangePattern, $combined, $m)) {
            $startY = !empty($m[1]) ? (int) $m[1] : null;
            $startD = (int) $m[2];
            $startMStr = $m[3];

            $endY = !empty($m[4]) ? (int) $m[4] : null;
            $endD = (int) $m[5];
            $endMStr = $m[6];

            $startM = null;
            $endM = null;
            foreach ($months as $prefix => $num) {
                if ($startM === null && str_starts_with($startMStr, $prefix)) $startM = $num;
                if ($endM === null && str_starts_with($endMStr, $prefix)) $endM = $num;
            }

            if ($startM && $endM) {
                if ($endY && !$startY) {
                    $startY = ($startM > $endM) ? ($endY - 1) : $endY;
                } elseif (!$endY && $startY) {
                    $endY = ($endM < $startM) ? ($startY + 1) : $startY;
                } elseif (!$startY && !$endY) {
                    $startY = $referenceDate->year;
                    $endY = ($endM < $startM) ? ($startY + 1) : $startY;
                }

                try {
                    $startAt = Carbon::createSafe($startY, $startM, $startD, 10, 0, 0, 'Europe/Riga');
                    $endAt = Carbon::createSafe($endY, $endM, $endD, 17, 0, 0, 'Europe/Riga');
                    return [$startAt, $endAt];
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        // Pattern 2: līdz [YYYY. gada] D. Mēnesim
        if (preg_match('/līdz\s+(?:(\d{4})\.\s*gad[a-z]*\s+)?(\d{1,2})\.?\s*([a-zāčēģīķļņšūž]+)/ui', $combined, $m)) {
            $endY = !empty($m[1]) ? (int) $m[1] : $referenceDate->year;
            $endD = (int) $m[2];
            $endMStr = $m[3];
            $endM = null;
            foreach ($months as $prefix => $num) {
                if (str_starts_with($endMStr, $prefix)) {
                    $endM = $num;
                    break;
                }
            }
            if ($endM) {
                if ($endM < $referenceDate->month && empty($m[1])) {
                    $endY++;
                }
                try {
                    $endAt = Carbon::createSafe($endY, $endM, $endD, 17, 0, 0, 'Europe/Riga');
                    $startAt = $referenceDate->copy()->startOfDay()->setHour(10);
                    return [$startAt, $endAt];
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * Resolve Venue and Address based on exhibition title and content
     */
    public function resolveVenueAndAddress(string $title, string $description): array
    {
        $text = mb_strtolower($title . ' ' . $description, 'UTF-8');

        // Check if held specifically at Brīvības ceļš branch (Brīvības iela 2)
        if (str_contains($text, 'brīvības ceļ') || str_contains($text, 'brivibas cel') || str_contains($text, 'brīvības ielā 2')) {
            return [
                'name' => 'Ogres Vēstures un mākslas muzeja ekspozīcija "Brīvības ceļš"',
                'city' => 'Ogre',
                'region' => 'Vidzeme',
                'address' => 'Brīvības iela 2, Ogre, LV-5001',
                'place_type' => 'museum',
            ];
        }

        // Check Mazā zāle
        if (str_contains($text, 'mazajā zāl') || str_contains($text, 'mazā zāl')) {
            return [
                'name' => 'Ogres Vēstures un mākslas muzejs, Mazā zāle',
                'city' => 'Ogre',
                'region' => 'Vidzeme',
                'address' => 'Brīvības iela 36, Ogre, LV-5001',
                'place_type' => 'museum',
            ];
        }

        // Default: Main museum building (Brīvības iela 36)
        return [
            'name' => 'Ogres Vēstures un mākslas muzejs',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Brīvības iela 36, Ogre, LV-5001',
            'place_type' => 'museum',
        ];
    }

    /**
     * Clean entry content HTML
     */
    public function cleanDetailText(string $html): string
    {
        // Strip out blog-share social icons and entry meta
        $cleaned = preg_replace('/<div class="blog-share.*?<\/div>\s*<\/div>/si', '', $html);
        $cleaned = preg_replace('/<footer class="entry-meta.*?<\/footer>/si', '', $cleaned);
        $cleaned = preg_replace('/<nav role="navigation".*?<\/nav>/si', '', $cleaned);

        $text = html_entity_decode(strip_tags($cleaned), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\r\n|\r/", "\n", $text);
        $text = preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text);

        return trim($text);
    }
}
