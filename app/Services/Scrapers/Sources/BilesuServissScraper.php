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
        $description = null;
        if ($promoterName) {
            $description = "Organizators: {$promoterName}";
        }

        return new ScrapedEventDTO(
            title: $title,
            startAt: $startAt,
            endAt: $endAt,
            description: $description,
            shortDescription: $description,
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
}
