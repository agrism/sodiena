<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TicketshopScraper extends BaseScraper
{
    public const BASE_URL = 'https://ticketshop.lv';
    public const API_BASE_URL = 'https://bpi.ticketshop.lv';
    public const CDN_BASE_URL = 'https://cdn.ticketshop.lv';

    public function getSlug(): string
    {
        return 'ticketshop';
    }

    public function getName(): string
    {
        return 'Ticketshop.lv';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $offset = 0;
        $limit = 100;
        $maxOffset = 1000;
        $today = Carbon::today('Europe/Riga');
        $seenExternalIds = [];

        while ($offset < $maxOffset) {
            if ($offset > 0) {
                $this->politeDelay(500, 150);
            }

            $listUrl = sprintf('%s/api/events?offset=%d&limit=%d', self::API_BASE_URL, $offset, $limit);
            $response = $this->fetchJson($listUrl);

            if (!$response || empty($response['data']) || !is_array($response['data'])) {
                break;
            }

            $items = $response['data'];
            $total = (int) ($response['meta']['total'] ?? 0);

            foreach ($items as $item) {
                $id = $item['id'] ?? null;
                if (!$id) {
                    continue;
                }

                $externalId = 'ticketshop-' . $id;
                if (isset($seenExternalIds[$externalId])) {
                    continue;
                }

                // 1. Skip cancelled events
                if ($this->isCancelledEvent($item)) {
                    continue;
                }

                // 2. Date parsing
                $dateFrom = $item['date_from'] ?? null;
                if (empty($dateFrom)) {
                    continue;
                }

                try {
                    $startAt = Carbon::parse($dateFrom)->setTimezone('Europe/Riga');
                } catch (\Throwable $e) {
                    continue;
                }

                // Strict AGENTS.md rule: Do not process past events
                if ($startAt->lt($today)) {
                    continue;
                }

                // 3. Fetch single event detail JSON for full rich description, location & price
                $detail = $this->fetchJson(sprintf('%s/api/event/%d', self::API_BASE_URL, $id)) ?? [];

                // Re-verify cancellation in detail
                if (!empty($detail['label']) && $this->isCancelledEvent($detail)) {
                    continue;
                }

                // Title
                $title = trim($detail['name'] ?? $item['name'] ?? '');
                if (empty($title)) {
                    continue;
                }

                // Description: full rich description with preserved paragraphs
                $rawDesc = $detail['description'] ?? null;
                $description = !empty($rawDesc) ? $this->cleanText($rawDesc) : $title;
                $shortDescription = Str::limit(strip_tags($description), 160);

                // End date: prefer detail date_to, fallback to startAt + 2 hours
                $dateTo = $detail['date_to'] ?? null;
                $endAt = null;
                if (!empty($dateTo)) {
                    try {
                        $endAt = Carbon::parse($dateTo)->setTimezone('Europe/Riga');
                    } catch (\Throwable $e) {
                        $endAt = $startAt->copy()->addHours(2);
                    }
                } else {
                    $endAt = $startAt->copy()->addHours(2);
                }

                // Prices
                $lowestPrice = $detail['lowest_price'] ?? $item['lowest_price'] ?? null;
                $highestPrice = $detail['highest_price'] ?? null;
                $currency = $detail['currency'] ?? $item['currency'] ?? 'EUR';

                if ($lowestPrice === null || $lowestPrice === '' || (float) $lowestPrice === 0.0) {
                    $isFree = true;
                    $priceMin = 0.0;
                    $priceMax = 0.0;
                } else {
                    $isFree = false;
                    $priceMin = (float) $lowestPrice;
                    $priceMax = (!empty($highestPrice) && (float) $highestPrice > 0.0)
                        ? (float) $highestPrice
                        : $priceMin;
                }

                // Poster Image
                $imageUrl = $this->resolveImageUrl($id, $detail, $item);

                // Source URL & Ticket URL
                $sourceUrl = sprintf('%s/lv/events/%d', self::BASE_URL, $id);
                $ticketUrl = $sourceUrl;

                // Location resolution
                $locationData = $this->resolveLocation($detail, $item);

                // Category & EntertainmentType strictly adhering to AGENTS.md
                $categoryId = (int) ($detail['category_id'] ?? $item['category_id'] ?? 0);
                [$categoryNames, $entertainmentType] = $this->resolveCategories(
                    $categoryId,
                    $title,
                    $description,
                    $locationData['venue_name'],
                    $detail['tags'] ?? []
                );

                $seenExternalIds[$externalId] = true;

                $events->push(new ScrapedEventDTO(
                    title: $title,
                    startAt: $startAt,
                    endAt: $endAt,
                    description: $description,
                    shortDescription: $shortDescription,
                    venueName: $locationData['venue_name'],
                    city: $locationData['city'],
                    region: $locationData['region'],
                    address: $locationData['address'],
                    latitude: $locationData['latitude'],
                    longitude: $locationData['longitude'],
                    placeType: $locationData['place_type'],
                    categoryNames: $categoryNames,
                    entertainmentType: $entertainmentType,
                    isFree: $isFree,
                    priceMin: $priceMin,
                    priceMax: $priceMax,
                    currency: $currency,
                    ticketUrl: $ticketUrl,
                    imageUrl: $imageUrl,
                    sourceUrl: $sourceUrl,
                    sourceExternalId: $externalId,
                    locale: 'lv',
                    rawData: array_filter([
                        'ticketshop_id' => $id,
                        'category_id' => $categoryId,
                        'sales_from' => $item['sales_from'] ?? null,
                        'soldout' => $item['soldout'] ?? $detail['soldout'] ?? null,
                        'label' => $item['label'] ?? $detail['label'] ?? null,
                        'org' => $detail['org']['name'] ?? null,
                    ]),
                ));
            }

            $offset += count($items);
            if ($offset >= $total || count($items) < $limit) {
                break;
            }
        }

        return $events;
    }

    /**
     * Check if event is marked as cancelled
     */
    public function isCancelledEvent(array $item): bool
    {
        if (isset($item['label']) && is_array($item['label'])) {
            $type = mb_strtolower($item['label']['type'] ?? '', 'UTF-8');
            $caption = mb_strtolower($item['label']['caption'] ?? '', 'UTF-8');
            if ($type === 'cancelled' || str_contains($caption, 'atcelts') || str_contains($caption, 'otmenen')) {
                return true;
            }
        }

        $status = (string) ($item['status'] ?? '');
        if ($status === '0' || $status === 'cancelled') {
            return true;
        }

        return false;
    }

    /**
     * Resolve high quality poster image URL from detail/item avatars and banners
     */
    public function resolveImageUrl(int $id, array $detail, array $item): ?string
    {
        $avatar = $detail['avatar'] ?? $item['avatar'] ?? null;
        if (!empty($avatar)) {
            return sprintf('%s/event/%d/%s/403x570.jpg', self::CDN_BASE_URL, $id, $avatar);
        }

        $banner = $detail['banner'] ?? $item['banner'] ?? null;
        if (!empty($banner)) {
            return sprintf('%s/event/%d/%s/1000x500.jpg', self::CDN_BASE_URL, $id, $banner);
        }

        return null;
    }

    /**
     * Resolve venue, address, coordinates, city, region and place type
     */
    public function resolveLocation(array $detail, array $item): array
    {
        $loc = $detail['location'] ?? [];
        $addr = $loc['address'] ?? [];

        $venueName = trim($loc['name'] ?? $loc['title'] ?? $item['location_name'] ?? 'Rīga');
        if (empty($venueName)) {
            $venueName = 'Rīga';
        }

        // City
        $cityRaw = trim($addr['city'] ?? '');
        $city = $this->normalizeCity($cityRaw, $venueName);

        // Address
        $address = trim($addr['address'] ?? '');
        if (empty($address)) {
            $address = $venueName . ', ' . $city;
        }

        // GPS coordinates
        $latitude = isset($loc['lat']) && is_numeric($loc['lat']) ? (float) $loc['lat'] : null;
        $longitude = isset($loc['lng']) && is_numeric($loc['lng']) ? (float) $loc['lng'] : null;

        // Region
        $region = $this->resolveRegion($city);

        // Place Type
        $placeType = $this->resolvePlaceType($venueName);

        return [
            'venue_name' => $venueName,
            'city' => $city,
            'region' => $region,
            'address' => $address,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'place_type' => $placeType,
        ];
    }

    /**
     * Normalize city names with proper Latvian diacritics
     */
    public function normalizeCity(string $city, string $venueName): string
    {
        $c = mb_strtolower($city, 'UTF-8');
        $v = mb_strtolower($venueName, 'UTF-8');

        if (str_contains($c, 'jelgav') || str_contains($v, 'balerija') || str_contains($v, 'jelgava')) {
            return 'Jelgava';
        }
        if (str_contains($c, 'liepāj') || str_contains($c, 'liepaja') || str_contains($v, 'fontaine') || str_contains($v, 'liepāja')) {
            return 'Liepāja';
        }
        if (str_contains($c, 'daugavpil') || str_contains($v, 'daugavpils')) {
            return 'Daugavpils';
        }
        if (str_contains($c, 'ventspil') || str_contains($v, 'ventspils')) {
            return 'Ventspils';
        }
        if (str_contains($c, 'valmier') || str_contains($v, 'valmiera')) {
            return 'Valmiera';
        }
        if (str_contains($c, 'cēs') || str_contains($c, 'cesis') || str_contains($v, 'cēsis')) {
            return 'Cēsis';
        }
        if (str_contains($c, 'jūrmal') || str_contains($c, 'jurmala') || str_contains($v, 'dzintari') || str_contains($v, 'jūrmala')) {
            return 'Jūrmala';
        }
        if (str_contains($c, 'siguld') || str_contains($v, 'sigulda')) {
            return 'Sigulda';
        }
        if (str_contains($c, 'ogre') || str_contains($v, 'ogre')) {
            return 'Ogre';
        }

        if (empty($city) || $c === 'riga' || $c === 'rīga' || str_contains($c, 'latvija')) {
            return 'Rīga';
        }

        return $city;
    }

    /**
     * Resolve Latvian geographic region by city
     */
    public function resolveRegion(string $city): string
    {
        $c = mb_strtolower(trim($city), 'UTF-8');

        return match (true) {
            str_contains($c, 'rīga') || str_contains($c, 'riga') || str_contains($c, 'jūrmal') || str_contains($c, 'mārup') || str_contains($c, 'ādaž') || str_contains($c, 'siguld') || str_contains($c, 'salaspil') || str_contains($c, 'olain') || str_contains($c, 'ķekav') || str_contains($c, 'ropaž') => 'Rīga un Pierīga',
            str_contains($c, 'jelgav') || str_contains($c, 'bausk') || str_contains($c, 'dobel') || str_contains($c, 'aizkraukl') || str_contains($c, 'jēkabpil') => 'Zemgale',
            str_contains($c, 'liepāj') || str_contains($c, 'ventspil') || str_contains($c, 'kuldīg') || str_contains($c, 'sald') || str_contains($c, 'tals') => 'Kurzeme',
            str_contains($c, 'valmier') || str_contains($c, 'cēs') || str_contains($c, 'smilten') || str_contains($c, 'limbaž') || str_contains($c, 'gulben') || str_contains($c, 'alūksn') || str_contains($c, 'madon') => 'Vidzeme',
            str_contains($c, 'daugavpil') || str_contains($c, 'rēzekn') || str_contains($c, 'ludz') || str_contains($c, 'krāslav') || str_contains($c, 'preiļ') || str_contains($c, 'līvān') || str_contains($c, 'balv') => 'Latgale',
            default => 'Rīga un Pierīga',
        };
    }

    /**
     * Resolve venue place type
     */
    public function resolvePlaceType(string $venueName): string
    {
        $v = mb_strtolower($venueName, 'UTF-8');

        if (str_contains($v, 'kino') || str_contains($v, 'suns')) {
            return 'cinema';
        }
        if (str_contains($v, 'teātr')) {
            return 'theatre';
        }
        if (str_contains($v, 'klubs') || str_contains($v, 'piektdiena') || str_contains($v, 'depo') || str_contains($v, 'koncert') || str_contains($v, 'halle') || str_contains($v, 'palace')) {
            return 'concert_hall';
        }
        if (str_contains($v, 'aula')) {
            return 'hall';
        }
        if (str_contains($v, 'muzej')) {
            return 'museum';
        }

        return 'venue';
    }

    /**
     * Resolve categories & entertainmentType strictly adhering to AGENTS.md
     */
    public function resolveCategories(
        int $categoryId,
        string $title,
        string $description,
        string $venueName,
        array $tags = []
    ): array {
        $canonical = [];
        $entertainmentType = null;

        $textToAnalyze = implode(' ', array_merge([$title, $description, $venueName], $tags));
        $combinedText = mb_strtolower($textToAnalyze, 'UTF-8');
        $venueLower = mb_strtolower($venueName, 'UTF-8');

        // 1. Strict Cinema / Kino rule (AGENTS.md):
        // Venues: K. Suns (or K.Suns, K Suns), Splendid Palace, etc. are ALWAYS KINO and NEVER TEĀTRIS!
        // Content: Any event with filma, filmas, filmu, filmā, kinoseanss, filmas seanss,
        // spēlfilma, dokumentālā filma, animācijas filma, kinofestivāls is ALWAYS Kino and NEVER Teātris!
        if ($this->isCinemaEvent($title, $venueName, $description)) {
            $canonical[] = 'Kino';
            $entertainmentType = 'kino';
        }

        // 2. Standup check (category_id = 4 or keywords)
        if ($categoryId === 4 || str_contains($combinedText, 'standup') || str_contains($combinedText, 'stand-up') || str_contains($combinedText, 'stendaps')) {
            $canonical[] = 'Teātris';
            $entertainmentType = 'standup';
        }

        // 3. Theatre rules (AGENTS.md):
        // Only actual theatre venues (Jaunatnes teātris, Leļļu teātris, Ģertrūdes, Zirgu pasts, Alunāna)
        // and actual theatre productions (izrāde, luga, iestudējums, opera, balets)
        if ($categoryId === 2 || $this->isTheatreEvent($combinedText, $venueLower)) {
            $canonical[] = 'Teātris';
            $entertainmentType = $entertainmentType ?: 'theatre';

            // Check if specifically for children
            if (str_contains($combinedText, 'leļļu') || str_contains($combinedText, 'pasaciņa') || str_contains($combinedText, 'bērniem') || str_contains($combinedText, 'bērnu')) {
                $canonical[] = 'Bērniem';
                $entertainmentType = 'kids';
            }
        }

        // 4. Exhibitions
        if (str_contains($combinedText, 'izstāde') || str_contains($combinedText, 'izstādes') || str_contains($combinedText, 'exhibition')) {
            $canonical[] = 'Izstādes';
            $entertainmentType = $entertainmentType ?: 'exhibition';
        }

        // 5. Festivals (category_id = 3 or festival keywords)
        if ($categoryId === 3 || str_contains($combinedText, 'festivāls') || str_contains($combinedText, 'festivals')) {
            $canonical[] = 'Svētki';
            $entertainmentType = $entertainmentType ?: 'festival';
        }

        // 6. Music / Concerts (category_id = 1 or music keywords/venues)
        if ($categoryId === 1 || $this->isMusicEvent($combinedText, $venueLower)) {
            $canonical[] = 'Mūzika';
            $entertainmentType = $entertainmentType ?: 'concert';
        }

        // 7. Kids / Family
        if (str_contains($combinedText, 'bērniem') || str_contains($combinedText, 'ģimenei') || str_contains($combinedText, 'jauniešiem') || str_contains($combinedText, 'lego')) {
            $canonical[] = 'Bērniem';
            $entertainmentType = $entertainmentType ?: 'kids';
        }

        // STRICT RULE (AGENTS.md): If event is in a cinema venue or marked Kino, it MUST NEVER be classified as Teātris
        if (in_array('Kino', $canonical, true)) {
            $canonical = array_values(array_filter($canonical, fn($c) => $c !== 'Teātris'));
        }

        // Fallback: If no category matched -> default to Cits (citi)
        if (empty($canonical)) {
            $canonical[] = 'Cits';
            $entertainmentType = $entertainmentType ?: 'other';
        }

        return [array_values(array_unique($canonical)), $entertainmentType];
    }

    public function isCinemaEvent(string $title, string $venueName, string $description = ''): bool
    {
        // 1. Strict venue rule (AGENTS.md)
        $cinemaVenues = ['k. suns', 'k.suns', 'k suns', 'kino rio', 'forum cinemas', 'apollo kino', 'cinamon', 'splendid palace', 'kino bize', 'kino lora', 'kino gaisma'];
        $venueLower = mb_strtolower($venueName, 'UTF-8');
        foreach ($cinemaVenues as $cv) {
            if (str_contains($venueLower, $cv)) {
                return true;
            }
        }

        // 2. Strict content keywords on title
        $titleLower = mb_strtolower($title, 'UTF-8');
        if (preg_match('/\b(kinoseanss|kino seanss|filmas seanss|spēlfilma|dokumentālā filma|animācijas filma|kinofestivāls|filmu vakars|kino vakars)\b/ui', $titleLower)) {
            return true;
        }

        if (preg_match('/\b(filma|filmas|filmā|filmu)\b/ui', $titleLower) && !preg_match('/\b(dziesm|mūzik|soundtrack|koncerts|tūre|festivāls)\b/ui', $titleLower)) {
            return true;
        }

        return false;
    }

    protected function isTheatreEvent(string $text, string $venueName): bool
    {
        // 1. Known theater venues
        $theatreVenues = [
            'teātris', 'teātra', 'ģertrūdes', 'zirgu pasts', 'alunāna',
            'brīvo aktieru sabiedrība', 'ciemos pie pasaciņas',
        ];

        foreach ($theatreVenues as $tv) {
            if (str_contains($venueName, $tv)) {
                return true;
            }
        }

        // 2. Production keywords
        $theatreKeywords = [
            'izrāde', 'izrādes', 'izrādi', 'izrādē',
            'iestudējums', 'luga', 'lugā', 'komēdija',
            'monodrāma', 'viesizrāde', 'leļļu teātris',
            'opera', 'balets',
        ];

        foreach ($theatreKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return true;
            }
        }

        return false;
    }

    protected function isMusicEvent(string $text, string $venueName): bool
    {
        // 1. Music venues
        $musicVenues = [
            'melnā piektdiena', 'depo', 'ready', 'fontaine',
            'melno cepurīšu balerija', 'palladium', 'vagonu halle',
            'aula', 'first club', 'art ambar',
        ];

        foreach ($musicVenues as $mv) {
            if (str_contains($venueName, $mv)) {
                return true;
            }
        }

        // 2. Music keywords
        $musicKeywords = [
            'koncerts', 'koncertā', 'koncertu', 'mūzika', 'mūzikas',
            'dziesma', 'dziesmas', 'orķestris', 'koris', 'ansamblis',
            'grupa', 'kvartets', 'metal', 'rock', 'jazz', 'punk',
            'dzied', 'albuma prezentācija', 'tūre', 'tour',
        ];

        foreach ($musicKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return true;
            }
        }

        return false;
    }
}
