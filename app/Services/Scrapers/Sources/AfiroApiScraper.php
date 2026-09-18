<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AfiroApiScraper extends BaseScraper
{
    private string $apiBase = 'https://api.afiro.lv';

    public function getSlug(): string
    {
        return 'afiro-api';
    }

    public function getName(): string
    {
        return 'Afiro Pasākumu API (Latvija & Rīga)';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $locales = ['lv', 'en', 'ru'];

        foreach ($locales as $locale) {
            $cursor = null;
            $page = 0;
            $maxPages = 30; // 30 * 100 = up to 3000 events per locale

            do {
                $page++;
                $url = "{$this->apiBase}/events?limit=100" . ($cursor ? "&cursor={$cursor}" : '');
                
                $headers = [
                    'Accept' => 'application/json, text/plain, */*',
                    'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Origin' => 'https://afiro.lv',
                    'Referer' => 'https://afiro.lv/',
                    'x-lang' => $locale,
                ];

                $json = $this->fetchJsonWithHeaders($url, $headers);
                if (!$json || empty($json['data'])) {
                    break;
                }

                foreach ($json['data'] as $item) {
                    try {
                        $dto = $this->mapItemToDTO($item, $source, $locale);
                        if ($dto) {
                            $events->push($dto);
                        }
                    } catch (\Throwable $e) {
                        Log::warning("Afiro event mapping failed for item: " . ($item['id'] ?? 'unknown'), [
                            'locale' => $locale,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $cursor = $json['nextCursor'] ?? null;
            } while ($cursor && $page < $maxPages);
        }

        return $events;
    }

    private function fetchJsonWithHeaders(string $url, array $headers): ?array
    {
        try {
            $response = $this->httpClient->get($url, [
                'headers' => $headers,
                'timeout' => 15,
            ]);
            $json = (string) $response->getBody();
            return json_decode($json, true);
        } catch (\Throwable $e) {
            Log::error("Scraper fetch error for URL {$url}: " . $e->getMessage());
            return null;
        }
    }

    private function mapItemToDTO(array $item, Source $source, string $locale = 'lv'): ?ScrapedEventDTO
    {
        $title = $this->cleanText($item['title'] ?? '');
        if (empty($title)) {
            return null;
        }

        $startAt = !empty($item['startAt']) ? Carbon::parse($item['startAt'])->setTimezone('Europe/Riga') : now();
        $endAt = !empty($item['endAt']) ? Carbon::parse($item['endAt'])->setTimezone('Europe/Riga') : null;

        $locationData = $item['location'] ?? [];
        $venueName = $this->cleanText($locationData['line1'] ?? null);
        $address = $this->cleanText($locationData['line2'] ?? null);
        $lat = isset($locationData['lat']) ? (float)$locationData['lat'] : null;
        $lng = isset($locationData['lng']) ? (float)$locationData['lng'] : null;
        $city = $this->resolveCity($locationData['citySlug'] ?? null, $address, $venueName);

        $categories = $this->resolveCategories($item['categories'] ?? []);
        $isFree = (bool)($item['isFree'] ?? false);
        $priceMin = isset($item['priceFrom']) && is_numeric($item['priceFrom']) ? (float)$item['priceFrom'] : null;
        $priceMax = isset($item['priceTo']) && is_numeric($item['priceTo']) ? (float)$item['priceTo'] : null;
        $imageUrl = $item['imageUrl'] ?? null;
        $externalId = $item['id'] ?? null;
        $description = $this->cleanText($item['description'] ?? '');
        if ($isFree) {
            $description = preg_replace('/\b(?:Biļetes|Biļešu cenas|Biļešu cena):\s*(?=https?:\/\/)/ui', "Papildu informācija: ", $description);
        } else {
            $description = preg_replace('/\b(?:Biļetes|Biļešu cenas|Biļešu cena):\s*(?=https?:\/\/(?:www\.)?(?:liveriga\.com|afiro\.lv|riga\.lv|latvia\.travel))/ui', "Papildu informācija: ", $description);
        }

        // Extract real ticketing and official event URLs instead of aggregator links
        $extractedUrls = $this->extractRealUrls($description, $item);

        return new ScrapedEventDTO(
            title: $title,
            startAt: $startAt,
            endAt: $endAt,
            description: $description,
            shortDescription: \Illuminate\Support\Str::limit(strip_tags($description), 160),
            venueName: $venueName ?: ($city . ' centrs'),
            city: $city,
            region: $this->resolveRegion($city),
            address: $address,
            latitude: $lat,
            longitude: $lng,
            placeType: ($item['locationType'] ?? 'indoor') === 'outdoor' ? 'outdoor' : 'venue',
            categoryNames: $categories,
            entertainmentType: $this->resolveEntertainmentType($item['categories'] ?? [], $title),
            isFree: $isFree,
            priceMin: $priceMin,
            priceMax: $priceMax,
            currency: $item['currency'] ?? 'EUR',
            ticketUrl: $extractedUrls['ticketUrl'],
            imageUrl: $imageUrl,
            sourceUrl: $extractedUrls['sourceUrl'],
            sourceExternalId: $externalId,
            locale: $locale,
            rawData: array_filter([
                'amenities' => $item['amenities'] ?? [],
                'audience' => $item['audience'] ?? [],
                'originalLocale' => $item['originalLocale'] ?? 'lv',
                'cta' => $item['cta'] ?? null,
                'organizer' => $item['organizer'] ?? null,
                'contacts' => $item['contacts'] ?? [],
            ])
        );
    }

    private function extractRealUrls(string $description, array $item): array
    {
        $textToSearch = $description . ' ' . ($item['location']['onlineUrl'] ?? '') . ' ' . json_encode($item['slots'] ?? []) . ' ' . json_encode($item['cta'] ?? []) . ' ' . json_encode($item['contacts'] ?? []) . ' ' . json_encode($item['organizer'] ?? []);
        preg_match_all('/https?:\/\/[^\s\)\"\'<>]+/i', $textToSearch, $matches);

        $ticketPlatforms = [
            'bilesuparadize.lv', 'bilesuserviss.lv', 'bezrindas.lv', 'ticketshop.lv',
            'aula.lv', 'fienta.com', 'apollokino.lv', 'forumcinemas.lv', 'splendidpalace.lv',
            'cinamonkino.com', 'opera.lv', 'passportix.eu', 'ticketbest.eu', 'ticketly.eu',
            'forms.gle', 'docs.google.com/forms', 'tally.so', 'distantrace.com', 'play.fiba3x3.com', 'cuescore.com'
        ];

        $foundTicket = null;
        $foundOfficial = null;

        foreach ($matches[0] as $rawUrl) {
            $cleanUrl = preg_replace('/(\?|\&)utm_[a-zA-Z0-9_]+=[^&]*/', '', $rawUrl);
            $cleanUrl = rtrim($cleanUrl, '?&.,;:\'\"');

            if (str_contains($cleanUrl, 'afiro.lv') || str_contains($cleanUrl, 'imagekit.io')) {
                continue;
            }

            foreach ($ticketPlatforms as $platform) {
                if (str_contains($cleanUrl, $platform)) {
                    $foundTicket = $cleanUrl;
                    break;
                }
            }

            if (!$foundOfficial && !str_contains($cleanUrl, 'youtube.com') && !str_contains($cleanUrl, 'youtu.be') && !str_contains($cleanUrl, 'tiktok.com')) {
                $foundOfficial = $cleanUrl;
            }
        }

        return [
            'ticketUrl' => $foundTicket ?: null,
            'sourceUrl' => $foundOfficial ?: ($foundTicket ?: null),
        ];
    }

    private function resolveCity(?string $citySlug, ?string $address, ?string $venue): string
    {
        $map = [
            'riga' => 'Rīga',
            'jurmala' => 'Jūrmala',
            'liepaja' => 'Liepāja',
            'daugavpils' => 'Daugavpils',
            'ventspils' => 'Ventspils',
            'jelgava' => 'Jelgava',
            'cesis' => 'Cēsis',
            'valmiera' => 'Valmiera',
            'sigulda' => 'Sigulda',
            'kuldiga' => 'Kuldīga',
            'ogre' => 'Ogre',
            'rezekne' => 'Rēzekne',
            'tukums' => 'Tukums',
            'bauska' => 'Bauska',
            'saldus' => 'Saldus',
            'talsi' => 'Talsi',
            'madona' => 'Madona',
        ];

        if ($citySlug && isset($map[strtolower($citySlug)])) {
            return $map[strtolower($citySlug)];
        }

        $searchIn = ($address ?? '') . ' ' . ($venue ?? '');
        foreach ($map as $k => $city) {
            if (stripos($searchIn, $city) !== false) {
                return $city;
            }
        }

        return 'Rīga';
    }

    private function resolveRegion(string $city): string
    {
        $map = [
            'Rīga' => 'Rīga un Pierīga',
            'Jūrmala' => 'Rīga un Pierīga',
            'Sigulda' => 'Vidzeme',
            'Cēsis' => 'Vidzeme',
            'Valmiera' => 'Vidzeme',
            'Madona' => 'Vidzeme',
            'Ogre' => 'Vidzeme',
            'Liepāja' => 'Kurzeme',
            'Ventspils' => 'Kurzeme',
            'Kuldīga' => 'Kurzeme',
            'Talsi' => 'Kurzeme',
            'Saldus' => 'Kurzeme',
            'Tukums' => 'Kurzeme',
            'Jelgava' => 'Zemgale',
            'Bauska' => 'Zemgale',
            'Dobele' => 'Zemgale',
            'Daugavpils' => 'Latgale',
            'Rēzekne' => 'Latgale',
        ];

        return $map[$city] ?? 'Latvija';
    }

    private function resolveCategories(array $categories): array
    {
        $result = [];
        $map = [
            'art' => 'Kultūra & Māksla',
            'exhibitions' => 'Kultūra & Māksla',
            'music' => 'Mūzika & Koncerti',
            'concerts' => 'Mūzika & Koncerti',
            'theatre' => 'Teātris & Kino',
            'cinema' => 'Filmas & Kino',
            'movies' => 'Filmas & Kino',
            'sports' => 'Sports & Aktīvā atpūta',
            'active' => 'Sports & Aktīvā atpūta',
            'family' => 'Ģimenēm & Bērniem',
            'kids' => 'Ģimenēm & Bērniem',
            'children' => 'Ģimenēm & Bērniem',
            'nature' => 'Daba & Pārgājieni',
            'outdoors' => 'Daba & Pārgājieni',
            'food' => 'Gastronomija & Tirgi',
            'gastronomy' => 'Gastronomija & Tirgi',
            'markets' => 'Gastronomija & Tirgi',
            'festivals' => 'Festivāli & Svētki',
            'nightlife' => 'Naktsdzīve & Ballītes',
            'party' => 'Naktsdzīve & Ballītes',
            'education' => 'Izglītība & Semināri',
            'workshops' => 'Izglītība & Semināri',
        ];

        foreach ($categories as $cat) {
            $lower = strtolower(trim($cat));
            if (isset($map[$lower])) {
                $result[] = $map[$lower];
            }
        }

        if (empty($result)) {
            $result[] = 'Kultūra & Māksla';
        }

        return array_unique($result);
    }

    private function resolveEntertainmentType(array $categories, string $title): string
    {
        $cats = array_map('strtolower', $categories);
        $t = mb_strtolower($title);

        if (in_array('kids', $cats) || in_array('family', $cats) || str_contains($t, 'bērn') || str_contains($t, 'ģimen')) return 'family';
        if (in_array('music', $cats) || in_array('concerts', $cats) || str_contains($t, 'koncert')) return 'concert';
        if (in_array('sports', $cats) || in_array('active', $cats) || str_contains($t, 'skrējiens') || str_contains($t, 'vel')) return 'active';
        if (in_array('nightlife', $cats) || in_array('party', $cats) || str_contains($t, 'ballīt') || str_contains($t, 'disko')) return 'party';
        if (in_array('workshops', $cats) || in_array('education', $cats) || str_contains($t, 'meistarklas')) return 'workshop';
        if (in_array('exhibitions', $cats) || in_array('art', $cats) || str_contains($t, 'izstād')) return 'exhibition';

        return 'chill';
    }
}
