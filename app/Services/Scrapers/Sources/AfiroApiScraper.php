<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
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
        return 'Afiro Pasākumu API';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $cursor = null;
        $page = 0;
        $maxPages = 30; // 30 * 100 = up to 3000 events

        do {
            $page++;
            $url = "{$this->apiBase}/events?limit=100" . ($cursor ? "&cursor={$cursor}" : '');
            
            $headers = [
                'Accept' => 'application/json, text/plain, */*',
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Origin' => 'https://afiro.lv',
                'Referer' => 'https://afiro.lv/',
                'x-lang' => 'lv',
            ];

            $json = $this->fetchJsonWithHeaders($url, $headers);
            if (!$json || empty($json['data'])) {
                break;
            }

            foreach ($json['data'] as $item) {
                try {
                    $dto = $this->mapItemToDTO($item, $source, 'lv');
                    if ($dto) {
                        $events->push($dto);
                    }
                } catch (\Throwable $e) {
                    Log::warning("Afiro event mapping failed for item: " . ($item['id'] ?? 'unknown'), [
                        'locale' => 'lv',
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $cursor = $json['nextCursor'] ?? null;
        } while ($cursor && $page < $maxPages);

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

        $categories = $this->resolveCategories($item['categories'] ?? [], $title);
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

        // Filter out floorball league calendar stubs (FloorballLvScraper handles actual matches directly from floorball.lv)
        $titleLower = mb_strtolower($title, 'UTF-8');
        $descLower = mb_strtolower($description, 'UTF-8');
        $sourceLower = strtolower($extractedUrls['sourceUrl'] ?? '');
        $ticketLower = strtolower($extractedUrls['ticketUrl'] ?? '');

        if (
            str_contains($descLower, 'floorball.lv') ||
            str_contains($sourceLower, 'floorball.lv') ||
            str_contains($ticketLower, 'floorball.lv') ||
            preg_match('/\b(?:florbola\s+čempionāts|elvi\s+florbola|latvijas\s+florbola)\b/ui', $titleLower)
        ) {
            return null;
        }

        // Filter out multiplex cinema stubs (ApolloKinoScraper and ForumCinemasScraper handle all shows directly with exact schedules, halls and prices)
        $isCinema = EventIngestionService::isCinemaEvent($title, $venueName, $categories) ||
            in_array('kino', $categories, true) ||
            in_array('cinema', $item['categories'] ?? [], true) ||
            in_array('movies', $item['categories'] ?? [], true) ||
            preg_match('/\b(?:filma|filmas|filmā|kinoseanss|kinoteātr|kinofestivāls|animācijas\s+filma|dokumentālā\s+filma|spēlfilma)\b/ui', $titleLower . ' ' . $descLower);

        $multiplexDomains = ['apollokino.lv', 'forumcinemas.lv'];
        $hasApolloOrForum = false;

        foreach ($multiplexDomains as $domain) {
            if (str_contains($sourceLower, $domain) || str_contains($ticketLower, $domain)) {
                $hasApolloOrForum = true;
                break;
            }
        }

        if (!$hasApolloOrForum && !empty($item['cta']['links']) && is_array($item['cta']['links'])) {
            foreach ($item['cta']['links'] as $ctaLink) {
                $linkUrl = strtolower($ctaLink['url'] ?? '');
                foreach ($multiplexDomains as $domain) {
                    if (str_contains($linkUrl, $domain)) {
                        $hasApolloOrForum = true;
                        break 2;
                    }
                }
            }
        }

        $organizerName = mb_strtolower($item['organizer']['name'] ?? $item['user']['fullName'] ?? '', 'UTF-8');
        if (!$hasApolloOrForum) {
            if (str_contains($organizerName, 'apollo kino') || str_contains($organizerName, 'forum cinemas')) {
                $hasApolloOrForum = true;
            }
        }

        if (!$hasApolloOrForum && (str_contains($descLower, 'apollokino.lv') || str_contains($descLower, 'forumcinemas.lv'))) {
            $hasApolloOrForum = true;
        }

        if ($isCinema && $hasApolloOrForum) {
            Log::info("Skipping Afiro multiplex cinema stub for '{$title}' (covered by dedicated cinema scraper)");
            return null;
        }

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
                'ticket_links' => $extractedUrls['ticketLinks'] ?? [],
            ])
        );
    }

    private function extractRealUrls(string $description, array $item): array
    {
        $ticketPlatforms = [
            'bilesuparadize.lv', 'bilesuserviss.lv', 'bezrindas.lv', 'ticketshop.lv',
            'aula.lv', 'fienta.com', 'apollokino.lv', 'forumcinemas.lv', 'splendidpalace.lv',
            'cinamonkino.com', 'opera.lv', 'passportix.eu', 'ticketbest.eu', 'ticketbest.lv', 'ticketly.eu', 'ekase.lv',
            'forms.gle', 'docs.google.com/forms', 'tally.so', 'distantrace.com', 'play.fiba3x3.com', 'cuescore.com'
        ];

        // 1. Collect candidate URLs in order of priority:
        // First: CTA links (explicit user actions chosen by event creator)
        $rawCandidateUrls = [];
        $ticketLinks = [];

        if (!empty($item['cta']['links']) && is_array($item['cta']['links'])) {
            foreach ($item['cta']['links'] as $link) {
                if (!empty($link['url']) && is_string($link['url'])) {
                    $rawCandidateUrls[] = [
                        'url' => trim($link['url']),
                        'title' => $link['title'] ?? null,
                        'is_cta' => true,
                    ];
                }
            }
        }

        // Second: onlineUrl
        if (!empty($item['location']['onlineUrl'])) {
            $rawCandidateUrls[] = [
                'url' => trim($item['location']['onlineUrl']),
                'title' => null,
                'is_cta' => false,
            ];
        }

        // Third: URLs from description and other text
        $textToSearch = $description . ' ' . json_encode($item['slots'] ?? []) . ' ' . json_encode($item['contacts'] ?? []) . ' ' . json_encode($item['organizer'] ?? []);
        preg_match_all('/https?:\/\/[^\s\)\"\'<>]+/i', $textToSearch, $matches);
        foreach ($matches[0] as $rawUrl) {
            $rawCandidateUrls[] = [
                'url' => $rawUrl,
                'title' => null,
                'is_cta' => false,
            ];
        }

        $allTicketUrls = [];
        $neutralOfficialUrls = [];

        foreach ($rawCandidateUrls as $candidate) {
            $rawUrl = $candidate['url'];
            $cleanUrl = EventIngestionService::canonicalizeTicketUrl($rawUrl) ?? rtrim($rawUrl, '?&.,;:\'\"');

            if (empty($cleanUrl) || str_contains($cleanUrl, 'afiro.lv') || str_contains($cleanUrl, 'imagekit.io')) {
                continue;
            }

            if (str_contains($cleanUrl, 'youtube.com') || str_contains($cleanUrl, 'youtu.be') || str_contains($cleanUrl, 'tiktok.com')) {
                continue;
            }

            $matchedPlatform = null;
            foreach ($ticketPlatforms as $platform) {
                if (str_contains($cleanUrl, $platform)) {
                    $matchedPlatform = $platform;
                    break;
                }
            }

            if ($matchedPlatform) {
                $canonicalTicket = EventIngestionService::canonicalizeTicketUrl($cleanUrl) ?: $cleanUrl;
                if (!in_array($canonicalTicket, array_column($allTicketUrls, 'url'), true)) {
                    $allTicketUrls[] = [
                        'url' => $canonicalTicket,
                        'platform' => $matchedPlatform,
                        'title' => $candidate['title'],
                    ];
                    $ticketLinks[] = [
                        'url' => $canonicalTicket,
                        'title' => $candidate['title'] ?? $matchedPlatform,
                    ];
                }
            } else {
                if (!in_array($cleanUrl, $neutralOfficialUrls, true)) {
                    $neutralOfficialUrls[] = $cleanUrl;
                }
            }
        }

        $foundTicket = null;
        $foundOfficial = null;

        if (!empty($allTicketUrls)) {
            // First ticket link in priority order
            $primaryTicket = $allTicketUrls[0];
            $foundTicket = $primaryTicket['url'];

            // For official source URL:
            // If there's a neutral official site (e.g. liveriga.com, festival homepage), use it.
            // If not, or if all candidates are ticketing platforms, sourceUrl MUST be from the SAME platform as ticketUrl.
            // NEVER pair competing ticketing/cinema platforms (e.g. Apollo Kino ticket with Forum Cinemas source)!
            $foundOfficial = $neutralOfficialUrls[0] ?? $foundTicket;
        } else {
            $foundOfficial = $neutralOfficialUrls[0] ?? null;
        }

        return [
            'ticketUrl' => $foundTicket ?: null,
            'sourceUrl' => $foundOfficial ?: ($foundTicket ?: null),
            'ticketLinks' => $ticketLinks,
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

    private function resolveCategories(array $categories, string $title = ''): array
    {
        $result = [];
        $titleLower = mb_strtolower($title, 'UTF-8');
        $isExcursionOrCulture = str_contains($titleLower, 'ekskursij') || str_contains($titleLower, 'gida pavadīb') || str_contains($titleLower, 'kūrorta parks') || str_contains($titleLower, 'parks');
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
            if (($lower === 'nature' || $lower === 'outdoors') && $isExcursionOrCulture) {
                $result[] = 'Kultūra & Māksla';
                continue;
            }
            if (isset($map[$lower])) {
                $result[] = $map[$lower];
            }
        }

        // Title checks if categories empty or missing music, theatre, sports
        $isMusicTitle = (
            str_contains($titleLower, 'koncerts') || str_contains($titleLower, 'dziesm') ||
            str_contains($titleLower, 'orķestr') || str_contains($titleLower, 'koris') ||
            str_contains($titleLower, 'grupa ') || str_contains($titleLower, 'jubilejas koncerts')
        ) && !preg_match('/\b(?:vs|pret|čempionāts|kauss|virslīga|fiba|turnīrs)\b/ui', $titleLower);
        if ($isMusicTitle && !str_contains($titleLower, 'izstād')) {
            $result[] = 'Mūzika & Koncerti';
        }

        $isSportsTitle = preg_match('/\b(?:sports?|sporta|turnīr|čempionāt|kauss|mačs|futbol|basketbol|hokej|florbol|volejbol|handbol|padel|basketball|championship|tournament|handball|virslīg|skrējiens|maratons|fiba)\b/ui', $titleLower);
        if ($isSportsTitle && !preg_match('/\b(?:izrāde|luga|teātr|koncert|izstād|muzej|lekcij)\b/ui', $titleLower)) {
            $result[] = 'Sports & Aktīvā atpūta';
        }

        if (empty($result)) {
            $result[] = 'Kultūra & Māksla';
        }

        if (in_array('Mūzika & Koncerti', $result) && !str_contains($titleLower, 'izstād') && !str_contains($titleLower, 'māksl')) {
            $result = array_diff($result, ['Kultūra & Māksla']);
        }
        if (in_array('Sports & Aktīvā atpūta', $result)) {
            $result = array_diff($result, ['Kultūra & Māksla']);
        }

        return array_values(array_unique($result));
    }

    private function resolveEntertainmentType(array $categories, string $title): string
    {
        $cats = array_map('strtolower', $categories);
        $t = mb_strtolower($title);

        if (in_array('kids', $cats) || in_array('family', $cats) || str_contains($t, 'bērn') || str_contains($t, 'ģimen')) return 'family';
        if (in_array('music', $cats) || in_array('concerts', $cats) || str_contains($t, 'koncert')) return 'concert';
        if (
            in_array('sports', $cats) || in_array('active', $cats) ||
            str_contains($t, 'skrējiens') || str_contains($t, 'vel') ||
            preg_match('/\b(?:sports?|turnīr|čempionāt|kauss|mačs|futbol|basketbol|hokej|florbol|volejbol|handbol|padel|basketball|championship|tournament|handball|virslīg|fiba)\b/ui', $t)
        ) return 'active';
        if (in_array('nightlife', $cats) || in_array('party', $cats) || str_contains($t, 'ballīt') || str_contains($t, 'disko')) return 'party';
        if (in_array('workshops', $cats) || in_array('education', $cats) || str_contains($t, 'meistarklas')) return 'workshop';
        if (in_array('exhibitions', $cats) || in_array('art', $cats) || str_contains($t, 'izstād')) return 'exhibition';

        return 'chill';
    }
}
