<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class KulturaJelgavaScraper extends BaseScraper
{
    public const BASE_URL = 'https://kultura.jelgava.lv';

    /**
     * Map of known Jelgava venues to normalized names, addresses, region, place types, and coordinates
     */
    protected array $venueMap = [
        'jelgavas-kulturas-nams' => [
            'name' => 'Jelgavas kultūras nams',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Krišjāņa Barona iela 6, Jelgava, LV-3001',
            'place_type' => 'cultural_center',
            'latitude' => 56.653913,
            'longitude' => 23.723446,
        ],
        'jelgavas-kulturas-nama-maza-zale' => [
            'name' => 'Jelgavas kultūras nams, Mazā zāle',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Krišjāņa Barona iela 6, Jelgava, LV-3001',
            'place_type' => 'cultural_center',
            'latitude' => 56.653832,
            'longitude' => 23.723441,
        ],
        'jelgavas-kulturas-nams-1-stava-galerija' => [
            'name' => 'Jelgavas kultūras nams (1. stāva galerija)',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Krišjāņa Barona iela 6, Jelgava, LV-3001',
            'place_type' => 'gallery',
            'latitude' => 56.653913,
            'longitude' => 23.723446,
        ],
        'jelgavas-kulturas-nams-2-stava-galerija' => [
            'name' => 'Jelgavas kultūras nams (2. stāva galerija)',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Krišjāņa Barona iela 6, Jelgava, LV-3001',
            'place_type' => 'gallery',
            'latitude' => 56.653913,
            'longitude' => 23.723446,
        ],
        'a-alunana-jelgava-teatra-kamerzale' => [
            'name' => 'Ādolfa Alunāna Jelgavas teātra kamerzāle',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Krišjāņa Barona iela 6, Jelgava, LV-3001',
            'place_type' => 'theatre',
            'latitude' => 56.653913,
            'longitude' => 23.723446,
        ],
        'adolfa-alunana-memorialais-muzejs' => [
            'name' => 'Ādolfa Alunāna memoriālais muzejs',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Filozofu iela 3, Jelgava, LV-3001',
            'place_type' => 'museum',
            'latitude' => 56.644277,
            'longitude' => 23.710440,
        ],
        'brivdabas-koncertzale-mitava' => [
            'name' => 'Brīvdabas koncertzāle "Mītava"',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Pasta sala 3, Jelgava, LV-3001',
            'place_type' => 'concert_hall',
            'latitude' => 56.651329,
            'longitude' => 23.735166,
        ],
        'pastasala-jelgava' => [
            'name' => 'Pasta sala',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Pasta sala, Jelgava, LV-3001',
            'place_type' => 'park',
            'latitude' => 56.650037,
            'longitude' => 23.735292,
        ],
        'deju-nams' => [
            'name' => 'Deju nams',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Cukura iela 22, Jelgava, LV-3002',
            'place_type' => 'cultural_center',
            'latitude' => 56.651862,
            'longitude' => 23.749060,
        ],
        'hercoga-jekaba-laukums' => [
            'name' => 'Hercoga Jēkaba laukums',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Hercoga Jēkaba laukums, Jelgava, LV-3001',
            'place_type' => 'square',
            'latitude' => 56.652644,
            'longitude' => 23.725299,
        ],
        'uzvaras-parks' => [
            'name' => 'Uzvaras parks',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Uzvaras iela, Jelgava, LV-3001',
            'place_type' => 'park',
            'latitude' => 56.658827,
            'longitude' => 23.717307,
        ],
        'raina-parks' => [
            'name' => 'Raiņa parks',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Raiņa iela, Jelgava, LV-3001',
            'place_type' => 'park',
            'latitude' => 56.647480,
            'longitude' => 23.723152,
        ],
        'gubernatora-salina' => [
            'name' => 'Gubernatora saliņa',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Gubernatora saliņa, Jelgava, LV-3001',
            'place_type' => 'park',
            'latitude' => 56.654779,
            'longitude' => 23.731394,
        ],
        'lielupes-plava-preti-pilij' => [
            'name' => 'Lielupes pļava pretī Jelgavas pilij',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Pils sala, Jelgava, LV-3001',
            'place_type' => 'park',
            'latitude' => 56.655678,
            'longitude' => 23.736917,
        ],
        'jelgavas-pils-parks-2' => [
            'name' => 'Jelgavas pils parks',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Lielā iela 2, Jelgava, LV-3001',
            'place_type' => 'park',
            'latitude' => 56.655847,
            'longitude' => 23.733911,
        ],
        'jelgavas-pils-pagalms' => [
            'name' => 'Jelgavas pils pagalms',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Lielā iela 2, Jelgava, LV-3001',
            'place_type' => 'historical_site',
            'latitude' => 56.655858,
            'longitude' => 23.733162,
        ],
        'lbtu-aula' => [
            'name' => 'LBTU Aula',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Lielā iela 2, Jelgava, LV-3001',
            'place_type' => 'hall',
            'latitude' => 56.655849,
            'longitude' => 23.733923,
        ],
        'kn-rota' => [
            'name' => 'Kultūras nams "Rota"',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Garozas iela 15, Jelgava, LV-3004',
            'place_type' => 'cultural_center',
            'latitude' => 56.656460,
            'longitude' => 23.750568,
        ],
        'zemgales-olimpiskais-centrs' => [
            'name' => 'Zemgales olimpiskais centrs',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Kronvalda iela 24, Jelgava, LV-3004',
            'place_type' => 'sports_complex',
            'latitude' => 56.661360,
            'longitude' => 23.754437,
        ],
        'jelgavas-pilsetas-biblioteka' => [
            'name' => 'Jelgavas Pilsētas bibliotēka',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Akadēmijas iela 26, Jelgava, LV-3001',
            'place_type' => 'library',
            'latitude' => 56.646495,
            'longitude' => 23.730233,
        ],
        'jelgavas-sv-trisvienibas-baznicas-tornis' => [
            'name' => 'Jelgavas Sv. Trīsvienības baznīcas tornis',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Akadēmijas iela 1, Jelgava, LV-3001',
            'place_type' => 'museum',
            'latitude' => 56.652411,
            'longitude' => 23.729189,
        ],
        'jelgavas-sv-annas-baznica' => [
            'name' => 'Jelgavas Sv. Annas baznīca',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Lielā iela 22A, Jelgava, LV-3001',
            'place_type' => 'church',
            'latitude' => 56.650745,
            'longitude' => 23.715797,
        ],
        'jelgavas-sv-annas-prokatedrale' => [
            'name' => 'Jelgavas Sv. Annas baznīca',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Lielā iela 22A, Jelgava, LV-3001',
            'place_type' => 'church',
            'latitude' => 56.650745,
            'longitude' => 23.715797,
        ],
        'jelgavas-sv-jana-evangeliski-luteriska-baznica' => [
            'name' => 'Jelgavas Sv. Jāņa evaņģēliski luteriskā baznīca',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Jāņa iela 1, Jelgava, LV-3001',
            'place_type' => 'church',
            'latitude' => 56.644480,
            'longitude' => 23.728634,
        ],
        'jelgavas-muzikas-vidusskola' => [
            'name' => 'Jelgavas Mūzikas vidusskola',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Lapskalna iela 2, Jelgava, LV-3007',
            'place_type' => 'school',
            'latitude' => 56.657877,
            'longitude' => 23.719289,
        ],
        'jelgavas-spidolas-valsts-gimnazija' => [
            'name' => 'Jelgavas Spīdolas Valsts ģimnāzija',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Sarmas iela 2, Jelgava, LV-3001',
            'place_type' => 'school',
            'latitude' => 56.645640,
            'longitude' => 23.708544,
        ],
        'jelgavas-valsts-gimnazija' => [
            'name' => 'Jelgavas Valsts ģimnāzija',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Mātera iela 44, Jelgava, LV-3001',
            'place_type' => 'school',
            'latitude' => 56.644081,
            'longitude' => 23.721574,
        ],
        'jelgavas-centra-pamatskola' => [
            'name' => 'Jelgavas Centra pamatskola',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Uzvaras iela 10, Jelgava, LV-3001',
            'place_type' => 'school',
            'latitude' => 56.655044,
            'longitude' => 23.723913,
        ],
        'meza-kapi' => [
            'name' => 'Jelgavas Meža kapi',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Bauskas iela, Jelgava, LV-3001',
            'place_type' => 'cemetery',
            'latitude' => 56.626835,
            'longitude' => 23.753976,
        ],
        'piemineklis-jelgavas-atbrivotajiem' => [
            'name' => 'Piemineklis Jelgavas atbrīvotājiem',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Stacijas iela, Jelgava, LV-3001',
            'place_type' => 'monument',
            'latitude' => 56.641380,
            'longitude' => 23.731016,
        ],
        'jelgava' => [
            'name' => 'Jelgavas pilsētvide',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Jelgava, LV-3001',
            'place_type' => 'public_space',
            'latitude' => 56.641437,
            'longitude' => 23.733911,
        ],
    ];

    public function getSlug(): string
    {
        return 'kultura-jelgava';
    }

    public function getName(): string
    {
        return 'Jelgavas kultūras nams';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $today = Carbon::today('Europe/Riga');
        $maxPages = 10;
        $emptyConsecutivePages = 0;
        $seenExternalIds = [];

        // Preload locations & categories taxonomy
        $locations = $this->loadLocations();
        $categories = $this->loadCategories();

        for ($page = 1; $page <= $maxPages; $page++) {
            $apiUrl = self::BASE_URL . "/wp-json/stec/v5/events?per_page=100&page={$page}&order=desc&orderby=id";
            $eventsData = $this->fetchJson($apiUrl);

            if (empty($eventsData) || !is_array($eventsData)) {
                break;
            }

            $activeOnPage = 0;

            foreach ($eventsData as $item) {
                if (!isset($item['id'])) {
                    continue;
                }

                $externalId = 'kultura-jelgava-' . $item['id'];
                if (isset($seenExternalIds[$externalId])) {
                    continue;
                }

                $dto = $this->parseEvent($item, $locations, $categories, $today);
                if ($dto) {
                    $seenExternalIds[$externalId] = true;
                    $events->push($dto);
                    $activeOnPage++;
                }
            }

            if ($activeOnPage === 0) {
                $emptyConsecutivePages++;
                if ($emptyConsecutivePages >= 2) {
                    // Two consecutive pages without active events indicate we've passed all upcoming dates
                    break;
                }
            } else {
                $emptyConsecutivePages = 0;
            }
        }

        return $events;
    }

    /**
     * Load locations taxonomy from STEC REST API
     */
    public function loadLocations(): array
    {
        $url = self::BASE_URL . '/wp-json/stec/v5/locations?per_page=100';
        $data = $this->fetchJson($url);
        if (empty($data) || !is_array($data)) {
            return [];
        }

        $locations = [];
        foreach ($data as $l) {
            $coords = trim($l['meta']['coordinates'] ?? '');
            $lat = null;
            $lng = null;
            if (!empty($coords) && str_contains($coords, ',')) {
                [$latStr, $lngStr] = explode(',', $coords, 2);
                $lat = is_numeric(trim($latStr)) ? (float)trim($latStr) : null;
                $lng = is_numeric(trim($lngStr)) ? (float)trim($lngStr) : null;
            }

            $locations[$l['id']] = [
                'id' => $l['id'],
                'slug' => $l['slug'] ?? '',
                'name' => $l['name'] ?? '',
                'address' => trim($l['meta']['address'] ?? ''),
                'city' => trim($l['meta']['city'] ?? '') ?: 'Jelgava',
                'postal_code' => trim($l['meta']['postal_code'] ?? ''),
                'latitude' => $lat,
                'longitude' => $lng,
            ];
        }

        return $locations;
    }

    /**
     * Load categories taxonomy from STEC REST API
     */
    public function loadCategories(): array
    {
        $url = self::BASE_URL . '/wp-json/stec/v5/categories?per_page=100';
        $data = $this->fetchJson($url);
        if (empty($data) || !is_array($data)) {
            return [];
        }

        $categories = [];
        foreach ($data as $c) {
            $categories[$c['id']] = [
                'id' => $c['id'],
                'name' => $c['name'] ?? '',
                'slug' => $c['slug'] ?? '',
            ];
        }

        return $categories;
    }

    /**
     * Parse a single STEC event into ScrapedEventDTO
     */
    public function parseEvent(array $item, array $locations, array $categories, Carbon $today): ?ScrapedEventDTO
    {
        $rawTitle = $item['title']['rendered'] ?? '';
        $title = $this->cleanTitle($rawTitle);
        if (empty($title) || $this->isNonEvent($title)) {
            return null;
        }

        // Date and time handling
        $startStr = $item['meta']['start_date'] ?? null;
        $endStr = $item['meta']['end_date'] ?? null;
        if (!$startStr) {
            return null;
        }

        $startAt = Carbon::parse($startStr, 'Europe/Riga');
        $endAt = $endStr ? Carbon::parse($endStr, 'Europe/Riga') : null;
        $hideEnd = !empty($item['meta']['hide_end']);
        $allDay = !empty($item['meta']['all_day']);

        if ($allDay) {
            $startAt = $startAt->startOfDay();
            $endAt = $endAt ? $endAt->endOfDay() : $startAt->copy()->endOfDay();
        } elseif ($endAt && $hideEnd && $startAt->isSameDay($endAt) && $endAt->diffInMinutes($startAt) <= 60) {
            // When hide_end is enabled and the duration is just the default 15-60 min slot, end time was hidden by author
            $endAt = null;
        }

        // Strict AGENTS.md rule: Do not process past events
        if ($endAt && $endAt->lt($today)) {
            return null;
        }
        if (!$endAt && $startAt->lt($today)) {
            return null;
        }

        // Description
        $rawContent = $item['content']['rendered'] ?? '';
        $description = $this->cleanDescription($rawContent);
        if (empty($description)) {
            $description = $title;
        }

        // Location metadata
        $locId = $item['stec_loc'][0] ?? null;
        $locData = $locId && isset($locations[$locId]) ? $locations[$locId] : null;
        $locationMeta = $this->resolveLocationMeta($title, $description, $locData);

        // Category slugs & names
        $catSlugs = [];
        $catNames = [];
        if (!empty($item['stec_cat']) && is_array($item['stec_cat'])) {
            foreach ($item['stec_cat'] as $cid) {
                if (isset($categories[$cid])) {
                    $catSlugs[] = $categories[$cid]['slug'];
                    $catNames[] = $categories[$cid]['name'];
                }
            }
        }

        // Categories & Entertainment type (Strict cinema vs theatre rules)
        $resolvedCategories = $this->resolveCategories($title, $description, $locationMeta['name'], $catSlugs);
        $entertainmentType = $this->resolveEntertainmentType($resolvedCategories, $title, $description, $catSlugs);

        // Ticket URL
        $ticketUrl = $item['meta']['external_link']['url'] ?? null;
        if (!empty($ticketUrl)) {
            $ticketUrl = $this->normalizeUrl($ticketUrl);
        } else {
            // Check for ticketing links in content
            if (preg_match('/https?:\/\/(?:www\.)?bilesuparadize\.lv\/[^\s"\'<>]+/i', $rawContent, $m)) {
                $ticketUrl = $this->normalizeUrl($m[0]);
            } elseif (preg_match('/https?:\/\/(?:www\.)?bilesuserviss\.lv\/[^\s"\'<>]+/i', $rawContent, $m)) {
                $ticketUrl = $this->normalizeUrl($m[0]);
            }
        }

        // Is free detection
        $isFree = false;
        $lowerCatNames = array_map('mb_strtolower', $catNames);
        if (in_array('bezmaksas', $catSlugs, true) || in_array('bezmaksas', $lowerCatNames, true)) {
            $isFree = true;
        }
        $lowerText = mb_strtolower($title . ' ' . $description);
        if (str_contains($lowerText, 'bezmaksas ieeja') || str_contains($lowerText, 'ieeja brīva') || str_contains($lowerText, 'ieeja bez maksas')) {
            $isFree = true;
        }
        if (!empty($ticketUrl) && !str_contains($lowerText, 'bezmaksas')) {
            $isFree = false;
        }

        // Image URL
        $imageUrl = $item['meta']['images'][0]['sizes']['full']
            ?? $item['meta']['images'][0]['sizes']['large']
            ?? $item['meta']['images'][0]['sizes']['medium']
            ?? null;

        if (!$imageUrl && !empty($item['featured_media'])) {
            $mediaData = $this->fetchJson(self::BASE_URL . "/wp-json/wp/v2/media/{$item['featured_media']}");
            if (!empty($mediaData['source_url'])) {
                $imageUrl = $mediaData['source_url'];
            }
        }

        if ($imageUrl && !str_starts_with($imageUrl, 'http://') && !str_starts_with($imageUrl, 'https://')) {
            $imageUrl = self::BASE_URL . (str_starts_with($imageUrl, '/') ? '' : '/') . $imageUrl;
        }

        $canonicalUrl = $item['link'] ?? self::BASE_URL . '/stec_event/' . ($item['slug'] ?? $item['id']) . '/';

        return new ScrapedEventDTO(
            title: $title,
            startAt: $startAt,
            endAt: $endAt,
            description: $description,
            shortDescription: Str::limit($description, 200),
            venueName: $locationMeta['name'],
            city: $locationMeta['city'],
            region: $locationMeta['region'],
            address: $locationMeta['address'],
            latitude: $locationMeta['latitude'],
            longitude: $locationMeta['longitude'],
            placeType: $locationMeta['place_type'],
            categoryNames: $resolvedCategories,
            entertainmentType: $entertainmentType,
            isFree: $isFree,
            priceMin: null,
            priceMax: null,
            currency: 'EUR',
            ticketUrl: $ticketUrl,
            imageUrl: $imageUrl,
            sourceUrl: $canonicalUrl,
            sourceExternalId: 'kultura-jelgava-' . $item['id'],
            locale: 'lv',
            rawData: [
                'id' => $item['id'],
                'slug' => $item['slug'] ?? null,
                'stec_loc' => $item['stec_loc'] ?? [],
                'stec_cat' => $item['stec_cat'] ?? [],
                'categories' => $catNames,
            ]
        );
    }

    /**
     * Resolve location details using venueMap, STEC location data, and title/description fallback
     */
    public function resolveLocationMeta(string $title, string $description, ?array $locData): array
    {
        // 1. Try matching location by STEC location slug
        if ($locData && !empty($locData['slug']) && isset($this->venueMap[$locData['slug']])) {
            return $this->venueMap[$locData['slug']];
        }

        // 2. Try matching by raw location name in venueMap keys
        if ($locData && !empty($locData['name'])) {
            $locLower = mb_strtolower(trim($locData['name']));
            foreach ($this->venueMap as $slug => $meta) {
                if (str_contains($locLower, mb_strtolower($meta['name'])) || str_contains(mb_strtolower($meta['name']), $locLower)) {
                    return $meta;
                }
            }
        }

        // 3. Fallback to scanning title and description for known venues
        $combined = mb_strtolower($title . ' ' . $description);
        foreach ($this->venueMap as $slug => $meta) {
            $venueNameLower = mb_strtolower($meta['name']);
            if (str_contains($combined, $venueNameLower)) {
                return $meta;
            }
        }

        // 4. If STEC location data is present, format it
        if ($locData && !empty($locData['name'])) {
            $addr = $locData['address'] ?: 'Jelgava';
            if ($locData['postal_code'] && !str_contains($addr, $locData['postal_code'])) {
                $addr .= ', ' . $locData['postal_code'];
            }

            return [
                'name' => $this->cleanTitle($locData['name']),
                'city' => $locData['city'] ?: 'Jelgava',
                'region' => 'Zemgale',
                'address' => $addr,
                'place_type' => 'cultural_center',
                'latitude' => $locData['latitude'],
                'longitude' => $locData['longitude'],
            ];
        }

        // 5. Default venue: Jelgavas kultūras nams
        return $this->venueMap['jelgavas-kulturas-nams'];
    }

    /**
     * Resolve categories strictly following AGENTS.md rules
     */
    public function resolveCategories(string $title, string $description, string $venueName, array $catSlugs = []): array
    {
        $text = mb_strtolower($title . ' ' . $description);
        $venueLower = mb_strtolower($venueName);

        // 1. STRICT CINEMA RULE (Highest Priority)
        // Kino venues or keywords: ALWAYS KINO, NEVER TEATRIS
        $cinemaKeywords = [
            'filma', 'filmas', 'filmu', 'filmā',
            'kinoseanss', 'filmas seanss', 'spēlfilma',
            'dokumentālā filma', 'animācijas filma', 'kinofestivāls',
            'kino seanss', 'kino:', 'kino |', 'kino .',
        ];
        $isCinema = in_array('kino', $catSlugs, true);
        if (!$isCinema) {
            foreach ($cinemaKeywords as $kw) {
                if (str_contains($text, $kw)) {
                    $isCinema = true;
                    break;
                }
            }
        }

        if ($isCinema) {
            $categories = ['Kino'];
            if (str_contains($text, 'bērniem') || str_contains($text, 'animācija') || str_contains($text, 'multfilma')) {
                $categories[] = 'Bērniem';
            }
            return array_unique($categories);
        }

        // 2. EXPLICIT TAXONOMY CATEGORIES (Curated by site editors)
        if (in_array('izrade', $catSlugs, true) || in_array('teatra-izrade', $catSlugs, true) || in_array('balets', $catSlugs, true)) {
            $categories = ['Teātris'];
            if (str_contains($text, 'bērniem') || str_contains($text, 'ģimenēm') || str_contains($text, 'leļļu')) {
                $categories[] = 'Bērniem';
            }
            return array_unique($categories);
        }

        if (in_array('koncerts', $catSlugs, true) || in_array('koncertizrade', $catSlugs, true) || in_array('muzikls', $catSlugs, true)) {
            $categories = ['Mūzika'];
            if (str_contains($text, 'bērniem') || str_contains($text, 'ģimenēm')) {
                $categories[] = 'Bērniem';
            }
            return array_unique($categories);
        }

        if (in_array('izstade', $catSlugs, true)) {
            return ['Izstādes'];
        }

        // 3. KEYWORD FALLBACK: THEATRE
        $theatreKeywords = [
            'izrāde', 'izrādē', 'izrādi', 'izrādes', 'iestudējums',
            'luga', 'lugā', 'komēdija', 'traģēdija', 'stāvizrāde',
            'stand-up', 'standup', 'leļļu izrāde', 'balets', 'opera',
        ];
        $isTheatre = in_array('komedija', $catSlugs, true)
            || in_array('stand-up', $catSlugs, true)
            || in_array('stavizrade', $catSlugs, true);

        if (!$isTheatre) {
            foreach ($theatreKeywords as $kw) {
                if (str_contains($text, $kw)) {
                    $isTheatre = true;
                    break;
                }
            }
        }

        if (!$isTheatre && (str_contains($venueLower, 'teātr') || str_contains($text, 'teātra izrāde') || str_contains($text, 'alunāna teātr'))) {
            $isTheatre = true;
        }

        if ($isTheatre) {
            $categories = ['Teātris'];
            if (str_contains($text, 'bērniem') || str_contains($text, 'ģimenēm') || str_contains($text, 'leļļu')) {
                $categories[] = 'Bērniem';
            }
            return array_unique($categories);
        }

        // 4. KEYWORD FALLBACK: MUSIC & CONCERTS
        $musicKeywords = [
            'koncerts', 'koncertā', 'koncertprogramma', 'dziedātājs',
            'koris', 'koru', 'orķestris', 'orķestra', 'kvartets',
            'akustiskā tūre', 'dziesmas', 'muzikāls', 'džezs', 'roks',
        ];
        $isMusic = false;
        foreach ($musicKeywords as $kw) {
            if (str_contains($text, $kw)) {
                $isMusic = true;
                break;
            }
        }

        if ($isMusic) {
            $categories = ['Mūzika'];
            if (str_contains($text, 'bērniem') || str_contains($text, 'ģimenēm')) {
                $categories[] = 'Bērniem';
            }
            return array_unique($categories);
        }

        // 5. KEYWORD FALLBACK: EXHIBITIONS & ART
        $exhibitionKeywords = [
            'izstāde', 'izstādē', 'izstādi', 'ekspozīcija',
            'personālizstāde', 'gleznas', 'glezniecība', 'gleznu',
            'tēlniecība', 'fotogrāfiju izstāde',
        ];
        $isExhibition = false;
        foreach ($exhibitionKeywords as $kw) {
            if (str_contains($text, $kw)) {
                $isExhibition = true;
                break;
            }
        }

        if ($isExhibition) {
            return ['Izstādes'];
        }

        // 5. SPORTS
        $sportsKeywords = ['sports', 'skrējiens', 'maratons', 'basketbols', 'futbols', 'hokejs', 'slidošana', 'turnīrs'];
        foreach ($sportsKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return ['Sports'];
            }
        }

        // 6. CHILDREN
        if (str_contains($text, 'bērniem') || str_contains($text, 'leļļu')) {
            return ['Bērniem'];
        }

        // 7. FALLBACK: Cits
        return ['Cits'];
    }

    /**
     * Resolve entertainment type string
     */
    public function resolveEntertainmentType(array $categories, string $title, string $description, array $catSlugs = []): string
    {
        $text = mb_strtolower($title . ' ' . $description);

        if (in_array('Kino', $categories, true)) {
            return 'movie';
        }
        if (in_array('Teātris', $categories, true)) {
            return 'theatre';
        }
        if (in_array('Mūzika', $categories, true)) {
            return 'concert';
        }
        if (in_array('Izstādes', $categories, true)) {
            return 'exhibition';
        }
        if (in_array('Sports', $categories, true)) {
            return 'sports';
        }
        if (in_array('festivali', $catSlugs, true) || in_array('svetki', $catSlugs, true) || str_contains($text, 'festivāls') || str_contains($text, 'svētki')) {
            return 'festival';
        }

        return 'event';
    }

    /**
     * Check if item is a generic directory notice or placeholder
     */
    public function isNonEvent(string $title): bool
    {
        $lower = mb_strtolower(trim($title));
        if (empty($lower)) {
            return true;
        }

        $blacklisted = [
            'darba laiks',
            'kases darba laiks',
            'biļešu kases darba laiks',
            'kontakti',
            'repertuārs',
        ];

        foreach ($blacklisted as $pattern) {
            if ($lower === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clean HTML text description, stripping Borlabs cookie block and tags
     */
    public function cleanDescription(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);

        // Remove Borlabs cookie container and related embeds
        $html = preg_replace('/<div[^>]*class="[^"]*brlbs-[^"]*"[^>]*>.*?<\/div>\s*<\/div>/is', '', $html);
        $html = preg_replace('/<div[^>]*class="[^"]*brlbs-[^"]*"[^>]*>.*?<\/div>/is', '', $html);

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xc2\xa0", '&nbsp;'], ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Clean plain text title
     */
    public function cleanTitle(string $rawTitle): string
    {
        $title = strip_tags($rawTitle);
        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = str_replace(["\xc2\xa0", '&nbsp;'], ' ', $title);

        return trim(preg_replace('/\s+/', ' ', $title));
    }

    /**
     * Normalize URL
     */
    protected function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = preg_replace('/\s+/', '', $url);

        return $url;
    }
}
