<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class BilesuServissScraper extends BaseScraper
{
    public function getSlug(): string
    {
        return 'bilesu-serviss';
    }

    public function getName(): string
    {
        return 'Biļešu Serviss';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $apiBaseUrl = 'https://www.bilesuserviss.lv/api/_internal/events/search';
        $pageSize = 100;
        $maxPages = 6; // Up to 600 events per scrape run

        for ($page = 1; $page <= $maxPages; $page++) {
            $apiUrl = "{$apiBaseUrl}?language=lv&page={$page}&pageSize={$pageSize}&sortBy=date&sortOrder=ASC";
            
            $items = $this->fetchJson($apiUrl);

            if (!is_array($items) || empty($items)) {
                break;
            }

            foreach ($items as $item) {
                try {
                    $dto = $this->parseEventItem($item);
                    if ($dto) {
                        $events->push($dto);
                    }
                } catch (\Throwable $e) {
                    Log::warning("Biļešu Serviss: Failed to parse item " . ($item['id'] ?? 'unknown'), [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (count($items) < $pageSize) {
                // Last page reached
                break;
            }
        }

        return $events;
    }

    /**
     * Parse single event array from bilesuserviss search API
     */
    public function parseEventItem(array $item): ?ScrapedEventDTO
    {
        $title = $this->cleanText($item['name'] ?? null);
        if (empty($title)) {
            return null;
        }

        // Status filter: skip cancelled events
        $status = strtoupper($item['status'] ?? '');
        if ($status === 'CANCELLED') {
            return null;
        }

        // Country check: only ingest Latvian events or events without foreign country tag
        $venueData = $item['venue'] ?? [];
        $country = strtoupper(trim($venueData['country'] ?? 'LV'));
        if (!empty($country) && $country !== 'LV') {
            return null;
        }

        // Dates
        $startStr = $item['eventStartAt'] ?? null;
        if (empty($startStr)) {
            return null;
        }

        try {
            $startAt = Carbon::parse($startStr)->setTimezone('Europe/Riga');
        } catch (\Throwable $e) {
            return null;
        }

        $endAt = null;
        if (!empty($item['eventEndAt'])) {
            try {
                $parsedEnd = Carbon::parse($item['eventEndAt'])->setTimezone('Europe/Riga');
                if ($parsedEnd->greaterThan($startAt)) {
                    $endAt = $parsedEnd;
                }
            } catch (\Throwable $e) {
                // Ignore end date parse error
            }
        }

        // Venue & Location
        $venueName = $this->cleanText($venueData['name'] ?? $venueData['nameOverride'] ?? null);
        $city = $this->cleanText($venueData['city'] ?? 'Rīga');
        if (empty($city)) {
            $city = 'Rīga';
        }

        // Categories
        $categoryNames = [];
        if (!empty($item['categories']) && is_array($item['categories'])) {
            foreach ($item['categories'] as $cat) {
                $catName = $this->cleanText($cat['name'] ?? null);
                if (!empty($catName) && !in_array($catName, $categoryNames)) {
                    $categoryNames[] = $catName;
                }
            }
        }

        // Image URL
        $imageUrl = null;
        if (!empty($item['imageData']) && is_array($item['imageData'])) {
            $selectedImageId = null;
            foreach ($item['imageData'] as $img) {
                if (($img['type'] ?? '') === 'DESKTOP' && !empty($img['imageId'])) {
                    $selectedImageId = $img['imageId'];
                    break;
                }
            }
            if (!$selectedImageId && !empty($item['imageData'][0]['imageId'])) {
                $selectedImageId = $item['imageData'][0]['imageId'];
            }
            if ($selectedImageId) {
                $imageUrl = "https://www.bilesuserviss.lv/i/height=600/images/{$selectedImageId}";
            }
        }

        // Ticket & Source URL
        $eventId = $item['id'] ?? '';
        $slug = $item['sluggedName'] ?? '';
        $eventUrl = "https://www.bilesuserviss.lv/biletes/{$eventId}/{$slug}";

        // Promoter / Organizer
        $promoterName = $this->cleanText($item['promoter']['companyName'] ?? null);

        // Fetch full description & priceInfo from event page
        $details = $this->fetchEventPageDetails($eventUrl);
        $description = $details['description'] ?? null;
        $priceInfo = $details['priceInfo'] ?? null;

        $sections = array_filter([
            $description,
            $priceInfo,
            $promoterName ? "Organizators: {$promoterName}" : null,
        ]);

        $fullDescription = !empty($sections) ? implode("\n\n", $sections) : ($promoterName ? "Organizators: {$promoterName}" : null);
        $shortDescription = $description ? \Illuminate\Support\Str::limit(strip_tags($description), 160) : ($promoterName ? "Organizators: {$promoterName}" : null);

        return new ScrapedEventDTO(
            title: $title,
            startAt: $startAt,
            endAt: $endAt,
            description: $fullDescription,
            shortDescription: $shortDescription,
            venueName: $venueName,
            city: $city,
            categoryNames: $categoryNames,
            ticketUrl: $eventUrl,
            imageUrl: $imageUrl,
            sourceUrl: $eventUrl,
            sourceExternalId: "bilesuserviss-{$eventId}",
            locale: 'lv',
            rawData: $item
        );
    }

    protected array $eventDetailsCache = [];

    public function fetchEventPageDetails(string $url): ?array
    {
        if (isset($this->eventDetailsCache[$url])) {
            return $this->eventDetailsCache[$url];
        }

        try {
            $response = $this->httpClient->get($url, ['timeout' => 15]);
            $html = (string) $response->getBody();

            if (preg_match_all('/<script[^>]*>(.*?)<\/script>/is', $html, $scripts)) {
                foreach ($scripts[1] as $s) {
                    if (!str_contains($s, 'event:lv:') && !str_contains($s, 'ShallowReactive')) {
                        continue;
                    }
                    $nuxt = json_decode($s, true);
                    if (!is_array($nuxt)) {
                        continue;
                    }

                    $resolve = function ($val) use ($nuxt) {
                        if ($val === null) return null;
                        if (is_int($val) && array_key_exists($val, $nuxt)) return $nuxt[$val];
                        return $val;
                    };

                    foreach ($nuxt as $item) {
                        if (is_array($item) && isset($item['description']) && isset($item['sluggedName'])) {
                            $descRaw = $resolve($item['description']);
                            $priceInfoRaw = isset($item['priceInfo']) ? $resolve($item['priceInfo']) : null;
                            
                            $desc = is_string($descRaw) ? $this->cleanText($descRaw) : null;
                            $priceInfo = is_string($priceInfoRaw) ? $this->cleanText($priceInfoRaw) : null;

                            $res = [
                                'description' => $desc,
                                'priceInfo' => $priceInfo,
                            ];
                            $this->eventDetailsCache[$url] = $res;
                            return $res;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fail gracefully
        }

        $this->eventDetailsCache[$url] = null;
        return null;
    }
}
