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

class JelgavasNovadsScraper extends BaseScraper
{
    public const BASE_URL = 'https://www.jelgavasnovads.lv';
    public const CALENDAR_PATH = '/lv/notikumu-kalendars';

    /**
     * Map of known Jelgavas novads venues to normalized names, addresses, region, and place types
     */
    protected array $venueAddressMap = [
        'ozolnieku tautas nams' => [
            'name' => 'Ozolnieku Tautas nams',
            'city' => 'Ozolnieki',
            'region' => 'Zemgale',
            'address' => 'Rīgas iela 18, Ozolnieki, Jelgavas novads, LV-3018',
            'place_type' => 'cultural_center',
        ],
        'elejas saieta nams' => [
            'name' => 'Elejas saieta nams',
            'city' => 'Eleja',
            'region' => 'Zemgale',
            'address' => 'Lietuvas iela 17, Eleja, Jelgavas novads, LV-3006',
            'place_type' => 'cultural_center',
        ],
        'elejas muižas parks' => [
            'name' => 'Elejas muižas parks',
            'city' => 'Eleja',
            'region' => 'Zemgale',
            'address' => 'Parka iela 1, Eleja, Jelgavas novads, LV-3006',
            'place_type' => 'park',
        ],
        'kalnciema kultūras nams' => [
            'name' => 'Kalnciema kultūras nams',
            'city' => 'Kalnciems',
            'region' => 'Zemgale',
            'address' => 'Jelgavas iela 15, Kalnciems, Jelgavas novads, LV-3016',
            'place_type' => 'cultural_center',
        ],
        'nākotnes kultūras nams' => [
            'name' => 'Nākotnes kultūras nams',
            'city' => 'Nākotne',
            'region' => 'Zemgale',
            'address' => 'Skolas iela 2, Nākotne, Glūdas pagasts, Jelgavas novads, LV-3040',
            'place_type' => 'cultural_center',
        ],
        'vilces tautas nams' => [
            'name' => 'Vilces Tautas nams',
            'city' => 'Vilce',
            'region' => 'Zemgale',
            'address' => 'Skolas iela 2, Vilce, Vilces pagasts, Jelgavas novads, LV-3026',
            'place_type' => 'cultural_center',
        ],
        'vilces muiža' => [
            'name' => 'Vilces muiža',
            'city' => 'Vilce',
            'region' => 'Zemgale',
            'address' => 'Skolas iela 2, Vilce, Vilces pagasts, Jelgavas novads, LV-3026',
            'place_type' => 'historical_site',
        ],
        'zaļenieku kultūras nams' => [
            'name' => 'Zaļenieku kultūras nams',
            'city' => 'Zaļenieki',
            'region' => 'Zemgale',
            'address' => 'Centra iela 2, Zaļenieki, Zaļenieku pagasts, Jelgavas novads, LV-3011',
            'place_type' => 'cultural_center',
        ],
        'zaļā muiža' => [
            'name' => 'Zaļā muiža',
            'city' => 'Zaļenieki',
            'region' => 'Zemgale',
            'address' => 'Zaļenieki, Zaļenieku pagasts, Jelgavas novads, LV-3011',
            'place_type' => 'historical_site',
        ],
        'lielplatones tautas nams' => [
            'name' => 'Lielplatones Tautas nams',
            'city' => 'Lielplatone',
            'region' => 'Zemgale',
            'address' => 'Lielplatones muiža, Lielplatones pagasts, Jelgavas novads, LV-3022',
            'place_type' => 'cultural_center',
        ],
        'lielplatones muiža' => [
            'name' => 'Lielplatones muiža',
            'city' => 'Lielplatone',
            'region' => 'Zemgale',
            'address' => 'Lielplatones muiža, Lielplatones pagasts, Jelgavas novads, LV-3022',
            'place_type' => 'historical_site',
        ],
        'vircavas tautas nams' => [
            'name' => 'Vircavas Tautas nams',
            'city' => 'Vircava',
            'region' => 'Zemgale',
            'address' => 'Muižas iela 1, Vircava, Vircavas pagasts, Jelgavas novads, LV-3020',
            'place_type' => 'cultural_center',
        ],
        'salgales tautas nams' => [
            'name' => 'Salgales Tautas nams',
            'city' => 'Emburga',
            'region' => 'Zemgale',
            'address' => 'Centra iela 4, Emburga, Salgales pagasts, Jelgavas novads, LV-3045',
            'place_type' => 'cultural_center',
        ],
        'valgundes tautas nams' => [
            'name' => 'Valgundes Tautas nams',
            'city' => 'Valgunde',
            'region' => 'Zemgale',
            'address' => 'Saules iela 2, Valgunde, Valgundes pagasts, Jelgavas novads, LV-3017',
            'place_type' => 'cultural_center',
        ],
        'svētes tautas nams' => [
            'name' => 'Svētes Tautas nams',
            'city' => 'Svēte',
            'region' => 'Zemgale',
            'address' => 'Vilces ceļš 1, Svēte, Svētes pagasts, Jelgavas novads, LV-3008',
            'place_type' => 'cultural_center',
        ],
        'līvbērzes kultūras nams' => [
            'name' => 'Līvbērzes kultūras nams',
            'city' => 'Līvbērze',
            'region' => 'Zemgale',
            'address' => 'Jelgavas iela 2, Līvbērze, Līvbērzes pagasts, Jelgavas novads, LV-3003',
            'place_type' => 'cultural_center',
        ],
        'platones tautas nams' => [
            'name' => 'Platones Tautas nams',
            'city' => 'Platone',
            'region' => 'Zemgale',
            'address' => 'Lielvircavas iela 1, Platone, Platones pagasts, Jelgavas novads, LV-3021',
            'place_type' => 'cultural_center',
        ],
        'sesavas tautas nams' => [
            'name' => 'Sesavas Tautas nams',
            'city' => 'Sesava',
            'region' => 'Zemgale',
            'address' => 'Skolas iela 1, Sesava, Sesavas pagasts, Jelgavas novads, LV-3023',
            'place_type' => 'cultural_center',
        ],
        'ānes kultūras nams' => [
            'name' => 'Ānes kultūras nams',
            'city' => 'Āne',
            'region' => 'Zemgale',
            'address' => 'Celtnieku iela 12, Āne, Cenu pagasts, Jelgavas novads, LV-3043',
            'place_type' => 'cultural_center',
        ],
        'jaunsvirlaukas tautas nams' => [
            'name' => 'Jaunsvirlaukas Tautas nams',
            'city' => 'Staļģene',
            'region' => 'Zemgale',
            'address' => 'Skolas iela 2, Staļģene, Jaunsvirlaukas pagasts, Jelgavas novads, LV-3031',
            'place_type' => 'cultural_center',
        ],
        'staļģenes muiža' => [
            'name' => 'Staļģenes muiža',
            'city' => 'Staļģene',
            'region' => 'Zemgale',
            'address' => 'Skolas iela 2, Staļģene, Jaunsvirlaukas pagasts, Jelgavas novads, LV-3031',
            'place_type' => 'historical_site',
        ],
        'branku pakalpojumu centrs' => [
            'name' => 'Branku pakalpojumu centrs',
            'city' => 'Brankas',
            'region' => 'Zemgale',
            'address' => 'Saules iela 2, Brankas, Cenu pagasts, Jelgavas novads, LV-3043',
            'place_type' => 'cultural_center',
        ],
    ];

    public function getSlug(): string
    {
        return 'jelgavas-novads';
    }

    public function getName(): string
    {
        return 'Jelgavas novada pašvaldība';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $baseUrl = $source->url ?: (self::BASE_URL . self::CALENDAR_PATH);
        $today = Carbon::today('Europe/Riga');
        $maxPages = 10;
        $seenExternalIds = [];

        for ($page = 0; $page < $maxPages; $page++) {
            if ($page > 0) {
                $this->politeDelay(1000, 300);
            }
            $pageUrl = $page === 0 ? $baseUrl : $baseUrl . '?page=' . $page;
            $crawler = $this->fetchCrawler($pageUrl);

            if (!$crawler) {
                Log::warning("JelgavasNovadsScraper: Failed to fetch page {$pageUrl}");
                break;
            }

            $items = $crawler->filter('div.event');
            if ($items->count() === 0) {
                break;
            }

            $allPageEventsPast = true;

            $items->each(function (Crawler $node) use (&$events, &$seenExternalIds, &$allPageEventsPast, $baseUrl, $today) {
                $titleNode = $node->filter('h2.event-title a, .event-title a, h2.event-title');
                if (!$titleNode->count()) {
                    return;
                }

                $title = $this->cleanText($titleNode->first()->text());
                if (empty($title) || $this->isNonEvent($title)) {
                    return;
                }

                // Extract Categories
                $categories = [];
                $node->filter('div.event-categories a, .event-categories a, .event-category a, div.event-categories, .event-category')
                    ->each(function (Crawler $catNode) use (&$categories) {
                        $catText = $this->cleanText($catNode->text());
                        if (!empty($catText)) {
                            $categories[] = $catText;
                        }
                    });
                $categories = array_values(array_unique($categories));

                // User constraint: Exclude all events with category "komitejas sēde"
                if ($this->isExcludedEvent($categories, $title)) {
                    return;
                }

                $linkNode = $node->filter('h2.event-title a, a[href*="/notikums/"]');
                $relLink = $linkNode->count() ? $linkNode->first()->attr('href') : '';
                $cleanUrl = $this->normalizeUrl($relLink);

                $slugPart = $cleanUrl ? basename(parse_url($cleanUrl, PHP_URL_PATH)) : Str::slug($title);
                $externalId = 'jelgavasnovads-' . $slugPart;

                if (isset($seenExternalIds[$externalId])) {
                    return;
                }

                // Dates & times
                $dateStr = $node->filter('span.date-date')->count() ? $this->cleanText($node->filter('span.date-date')->text()) : '';
                $timeStr = $node->filter('span.date-time')->count() ? $this->cleanText($node->filter('span.date-time')->text()) : '';
                [$startAt, $endAt, $allDay] = $this->parseLatvianDates($dateStr, $timeStr);

                if (!$startAt) {
                    return;
                }

                // Strict AGENTS.md rule: Do not process past events
                if ($endAt && $endAt->lt($today)) {
                    return;
                }
                if (!$endAt && $startAt->lt($today)) {
                    return;
                }

                $allPageEventsPast = false;

                // Description directly from card (strictly NO crawling into detail pages)
                $description = $node->filter('div.event-content')->count() ? $this->cleanText($node->filter('div.event-content')->text()) : $title;
                if (empty($description)) {
                    $description = $title;
                }

                // Location
                $locationRaw = $node->filter('span.describing-field-value')->count() ? $this->cleanText($node->filter('span.describing-field-value')->text()) : '';
                $venueInfo = $this->resolveLocation($locationRaw, $title, $description);

                // Categories & Entertainment Type (adhering strictly to AGENTS.md)
                $canonicalCategories = $this->resolveCategories($categories, $title, $description);
                $entertainmentType = $this->resolveEntertainmentType($canonicalCategories, $title, $description);

                // Price & Free check
                $priceData = $this->parsePrice($description);

                // Image
                $thumbImg = $node->filter('div.event-image img')->count() ? $node->filter('div.event-image img')->attr('src') : null;
                if ($thumbImg && !str_starts_with($thumbImg, 'http')) {
                    $thumbImg = self::BASE_URL . (str_starts_with($thumbImg, '/') ? '' : '/') . $thumbImg;
                }

                $seenExternalIds[$externalId] = true;

                $dto = new ScrapedEventDTO(
                    title: $title,
                    startAt: $startAt,
                    endAt: $endAt,
                    description: $description,
                    shortDescription: Str::limit(strip_tags($description), 160),
                    venueName: $venueInfo['name'],
                    city: $venueInfo['city'],
                    region: 'Zemgale',
                    address: $venueInfo['address'],
                    placeType: $venueInfo['place_type'],
                    categoryNames: $canonicalCategories,
                    entertainmentType: $entertainmentType,
                    isFree: $priceData['is_free'],
                    priceMin: $priceData['price_min'],
                    priceMax: $priceData['price_max'],
                    currency: 'EUR',
                    ticketUrl: $cleanUrl ?: $baseUrl,
                    imageUrl: $thumbImg,
                    sourceUrl: $cleanUrl ?: $baseUrl,
                    sourceExternalId: $externalId,
                    locale: 'lv',
                    rawData: [
                        'date_raw' => $dateStr,
                        'time_raw' => $timeStr,
                        'location_raw' => $locationRaw,
                        'categories_raw' => $categories,
                    ]
                );

                $events->push($dto);
            });

            if ($allPageEventsPast && $items->count() > 0) {
                // If all events on this page are strictly in the past, stop paginating
                break;
            }
        }

        return $events;
    }

    /**
     * Check if event has category or title "komitejas sēde" (user explicit exclusion rule)
     */
    public function isExcludedEvent(array $categories, string $title): bool
    {
        $lowerTitle = mb_strtolower($title, 'UTF-8');
        if (str_contains($lowerTitle, 'komitejas sēde') || str_contains($lowerTitle, 'komitejas sēdes')) {
            return true;
        }

        foreach ($categories as $cat) {
            $lowerCat = mb_strtolower(trim($cat), 'UTF-8');
            if (str_contains($lowerCat, 'komitejas sēde') || str_contains($lowerCat, 'komitejas sēdes')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve Venue and City from text and location
     */
    public function resolveLocation(string $locationRaw, string $title = '', string $description = ''): array
    {
        $text = mb_strtolower($locationRaw . ' ' . $title . ' ' . $description, 'UTF-8');

        // 1. Direct stem match in known venues
        $stemVenueMap = [
            'ozolnieku tautas nam' => 'ozolnieku tautas nams',
            'elejas saieta nam' => 'elejas saieta nams',
            'elejas muižas park' => 'elejas muižas parks',
            'elejas park' => 'elejas muižas parks',
            'kalnciema kultūras nam' => 'kalnciema kultūras nams',
            'kalnciema tautas nam' => 'kalnciema kultūras nams',
            'nākotnes kultūras nam' => 'nākotnes kultūras nams',
            'nākotnes tautas nam' => 'nākotnes kultūras nams',
            'vilces tautas nam' => 'vilces tautas nams',
            'vilces muiž' => 'vilces muiža',
            'zaļenieku kultūras nam' => 'zaļenieku kultūras nams',
            'zaļenieku tautas nam' => 'zaļenieku kultūras nams',
            'zaļā muiž' => 'zaļā muiža',
            'zaļās muiž' => 'zaļā muiža',
            'zaļajā muiž' => 'zaļā muiža',
            'lielplatones tautas nam' => 'lielplatones tautas nams',
            'lielplatones muiž' => 'lielplatones muiža',
            'vircavas tautas nam' => 'vircavas tautas nams',
            'vircavas muiž' => 'vircavas tautas nams',
            'salgales tautas nam' => 'salgales tautas nams',
            'emburgas tautas nam' => 'salgales tautas nams',
            'salgales kultūras nam' => 'salgales tautas nams',
            'valgundes tautas nam' => 'valgundes tautas nams',
            'svētes tautas nam' => 'svētes tautas nams',
            'līvbērzes kultūras nam' => 'līvbērzes kultūras nams',
            'platones tautas nam' => 'platones tautas nams',
            'sesavas tautas nam' => 'sesavas tautas nams',
            'ānes kultūras nam' => 'ānes kultūras nams',
            'staļģenes muiž' => 'staļģenes muiža',
            'jaunsvirlaukas tautas nam' => 'jaunsvirlaukas tautas nams',
            'branku pakalpojumu centr' => 'branku pakalpojumu centrs',
        ];

        foreach ($stemVenueMap as $stem => $mapKey) {
            if (str_contains($text, $stem) && isset($this->venueAddressMap[$mapKey])) {
                return $this->venueAddressMap[$mapKey];
            }
        }

        // 2. Parish / City detection
        $parishMap = [
            'ozolniek' => 'Ozolnieki',
            'elej' => 'Eleja',
            'kalnciem' => 'Kalnciems',
            'glūd' => 'Nākotne',
            'nākotn' => 'Nākotne',
            'vilc' => 'Vilce',
            'zaļeniek' => 'Zaļenieki',
            'zaleniek' => 'Zaļenieki',
            'lielplaton' => 'Lielplatone',
            'vircav' => 'Vircava',
            'salgal' => 'Emburga',
            'emburg' => 'Emburga',
            'valgund' => 'Valgunde',
            'svēt' => 'Svēte',
            'svete' => 'Svēte',
            'līvbērz' => 'Līvbērze',
            'livberz' => 'Līvbērze',
            'platon' => 'Platone',
            'sesav' => 'Sesava',
            'ān' => 'Āne',
            'staļģen' => 'Staļģene',
            'stalgen' => 'Staļģene',
            'jaunsvirlauk' => 'Staļģene',
            'brank' => 'Brankas',
            'cen' => 'Brankas',
        ];

        $city = 'Jelgava';
        $primaryText = mb_strtolower(trim($locationRaw . ' ' . $title), 'UTF-8');
        foreach ($parishMap as $needle => $cName) {
            if (str_contains($primaryText, $needle)) {
                $city = $cName;
                break;
            }
        }

        if ($city === 'Jelgava') {
            foreach ($parishMap as $needle => $cName) {
                if (str_contains($text, $needle)) {
                    $city = $cName;
                    break;
                }
            }
        }

        $venue = match ($city) {
            'Ozolnieki' => 'Ozolnieku Tautas nams',
            'Eleja' => 'Elejas saieta nams',
            'Kalnciems' => 'Kalnciema kultūras nams',
            'Nākotne' => 'Nākotnes kultūras nams',
            'Vilce' => 'Vilces Tautas nams',
            'Zaļenieki' => 'Zaļenieku kultūras nams',
            'Lielplatone' => 'Lielplatones Tautas nams',
            'Vircava' => 'Vircavas Tautas nams',
            'Emburga' => 'Salgales Tautas nams',
            'Valgunde' => 'Valgundes Tautas nams',
            'Svēte' => 'Svētes Tautas nams',
            'Līvbērze' => 'Līvbērzes kultūras nams',
            'Platone' => 'Platones Tautas nams',
            'Sesava' => 'Sesavas Tautas nams',
            'Āne' => 'Ānes kultūras nams',
            'Staļģene' => 'Jaunsvirlaukas Tautas nams',
            'Brankas' => 'Branku pakalpojumu centrs',
            default => 'Jelgavas novada pašvaldība',
        };

        $address = match ($city) {
            'Jelgava' => 'Pasta iela 37, Jelgava, LV-3001',
            default => "{$city}, Jelgavas novads",
        };

        return [
            'name' => $venue,
            'city' => $city,
            'region' => 'Zemgale',
            'address' => $address,
            'place_type' => 'cultural_center',
        ];
    }

    /**
     * Resolve categories following strict AGENTS.md rules
     */
    public function resolveCategories(array $rawCategories, string $title, string $description = ''): array
    {
        $text = mb_strtolower(implode(' ', $rawCategories) . ' ' . $title . ' ' . $description, 'UTF-8');

        // STRICT RULE 1: Cinema / Kino (Priority)
        // Kino venues or keywords: ALWAYS KINO, NEVER TEATRIS
        $cinemaKeywords = [
            'filma', 'filmas', 'filmu', 'filmā',
            'kinoseanss', 'filmas seanss', 'spēlfilma',
            'dokumentālā filma', 'animācijas filma', 'kinofestivāls',
            'kino seanss', 'kinoteātr', 'kino:', 'kino |',
        ];
        $isCinema = in_array('Kino', $rawCategories, true);
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

        // STRICT RULE 2: Theaters
        $theatreKeywords = [
            'teātra izrāde', 'izrāde', 'izrādē', 'izrādi', 'izrādes', 'iestudējums',
            'luga', 'lugā', 'komēdija', 'traģēdija', 'stāvizrāde',
            'stand-up', 'standup', 'leļļu izrāde', 'balets', 'opera',
        ];
        $isTheatre = in_array('Teātris', $rawCategories, true) || in_array('Izrāde', $rawCategories, true);
        if (!$isTheatre) {
            foreach ($theatreKeywords as $kw) {
                if (str_contains($text, $kw)) {
                    $isTheatre = true;
                    break;
                }
            }
        }

        if ($isTheatre) {
            $categories = ['Teātris'];
            if (str_contains($text, 'bērniem') || str_contains($text, 'ģimenēm') || str_contains($text, 'leļļu')) {
                $categories[] = 'Bērniem';
            }
            return array_unique($categories);
        }

        // Music & Concerts
        $musicKeywords = [
            'koncerts', 'koncertā', 'koncertprogramma', 'dziedātājs',
            'koris', 'koru', 'orķestris', 'orķestra', 'kvartets',
            'akustiskā tūre', 'dziesmas', 'muzikāls', 'džezs', 'roks', 'balle',
        ];
        $isMusic = in_array('Koncerts', $rawCategories, true) || in_array('Mūzika', $rawCategories, true);
        if (!$isMusic) {
            foreach ($musicKeywords as $kw) {
                if (str_contains($text, $kw)) {
                    $isMusic = true;
                    break;
                }
            }
        }

        if ($isMusic) {
            $categories = ['Mūzika'];
            if (str_contains($text, 'bērniem') || str_contains($text, 'ģimenēm')) {
                $categories[] = 'Bērniem';
            }
            return array_unique($categories);
        }

        // Exhibitions & Art
        $exhibitionKeywords = [
            'izstāde', 'izstādē', 'izstādi', 'ekspozīcija',
            'personālizstāde', 'gleznas', 'glezniecība', 'gleznu',
            'tēlniecība', 'fotogrāfiju izstāde',
        ];
        $isExhibition = in_array('Izstāde', $rawCategories, true);
        if (!$isExhibition) {
            foreach ($exhibitionKeywords as $kw) {
                if (str_contains($text, $kw)) {
                    $isExhibition = true;
                    break;
                }
            }
        }

        if ($isExhibition) {
            return ['Izstādes'];
        }

        // Sports
        $sportsKeywords = ['sports', 'skrējiens', 'maratons', 'basketbols', 'futbols', 'hokejs', 'slidošana', 'turnīrs', 'nūjošana'];
        foreach ($sportsKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return ['Sports'];
            }
        }

        // Children
        if (str_contains($text, 'bērniem') || str_contains($text, 'leļļu') || in_array('Bērniem', $rawCategories, true)) {
            return ['Bērniem'];
        }

        // Fallback: Cits
        return ['Cits'];
    }

    /**
     * Resolve entertainment type
     */
    public function resolveEntertainmentType(array $categories, string $title, string $description = ''): string
    {
        $text = mb_strtolower($title . ' ' . $description, 'UTF-8');

        if (in_array('Kino', $categories, true)) {
            return 'movie';
        }
        if (in_array('Mūzika', $categories, true)) {
            return 'concert';
        }
        if (in_array('Teātris', $categories, true)) {
            return 'theatre';
        }
        if (in_array('Izstādes', $categories, true)) {
            return 'exhibition';
        }
        if (in_array('Sports', $categories, true)) {
            return 'sports';
        }
        if (str_contains($text, 'festivāls') || str_contains($text, 'svētki') || str_contains($text, 'tirdziņš')) {
            return 'festival';
        }

        return 'event';
    }

    /**
     * Parse Latvian dates from "span.date-date" and "span.date-time"
     */
    public function parseLatvianDates(string $dateStr, string $timeStr = ''): array
    {
        $months = [
            'janvāris' => 1, 'janvārī' => 1, 'janvāra' => 1, 'janv' => 1,
            'februāris' => 2, 'februārī' => 2, 'februāra' => 2, 'febr' => 2,
            'marts' => 3, 'martā' => 3, 'marta' => 3, 'mar' => 3,
            'aprīlis' => 4, 'aprīlī' => 4, 'aprīļa' => 4, 'apr' => 4,
            'maijs' => 5, 'maijā' => 5, 'maija' => 5, 'mai' => 5,
            'jūnijs' => 6, 'jūnijā' => 6, 'jūnija' => 6, 'jūn' => 6,
            'jūlijs' => 7, 'jūlijā' => 7, 'jūlija' => 7, 'jūl' => 7,
            'augusts' => 8, 'augustā' => 8, 'augusta' => 8, 'aug' => 8,
            'septembris' => 9, 'septembrī' => 9, 'septembra' => 9, 'sept' => 9,
            'oktobris' => 10, 'oktobrī' => 10, 'oktobra' => 10, 'okt' => 10,
            'novembris' => 11, 'novembrī' => 11, 'novembra' => 11, 'nov' => 11,
            'decembris' => 12, 'decembrī' => 12, 'decembra' => 12, 'dec' => 12,
        ];

        $clean = trim(str_replace(['&#8211;', '&ndash;', '–', '—'], '-', $dateStr));
        $allDay = false;

        // Parse time string e.g. "18.00", "18:00", "18.00 - 20.00", "Visu dienu"
        $startTime = '10:00:00';
        $endTime = null;

        $timeClean = trim(str_replace(['&#8211;', '&ndash;', '–', '—'], '-', $timeStr));
        if (mb_stripos($timeClean, 'visu dienu') !== false) {
            $allDay = true;
            $startTime = '00:00:00';
            $endTime = '23:59:59';
        } elseif (preg_match('/(\d{1,2})[.:](\d{2})\s*-\s*(\d{1,2})[.:](\d{2})/u', $timeClean, $tm)) {
            $startTime = sprintf('%02d:%02d:00', (int)$tm[1], (int)$tm[2]);
            $endTime = sprintf('%02d:%02d:00', (int)$tm[3], (int)$tm[4]);
        } elseif (preg_match('/(\d{1,2})[.:](\d{2})/u', $timeClean, $tm)) {
            $startTime = sprintf('%02d:%02d:00', (int)$tm[1], (int)$tm[2]);
        }

        // Check for date range e.g. "16. - 20. novembris, 2026" or "16. novembris - 5. decembris, 2026"
        if (str_contains($clean, '-')) {
            $parts = explode('-', $clean, 2);
            $p1 = trim($parts[0]);
            $p2 = trim($parts[1]);

            // Match full second date: e.g. "20. novembris, 2026" or "20. novembris"
            $year2 = (int) date('Y');
            $month2 = (int) date('n');
            $day2 = 1;

            if (preg_match('/(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:[,\s]+(\d{4}))?/iu', $p2, $m2)) {
                $day2 = (int) $m2[1];
                $mName2 = mb_strtolower(trim($m2[2]));
                $month2 = $months[$mName2] ?? (int) date('n');
                if (!empty($m2[3])) {
                    $year2 = (int) $m2[3];
                }
            }

            // Match first date: could be just "16." or "16. oktobris"
            $year1 = $year2;
            $month1 = $month2;
            $day1 = 1;

            if (preg_match('/(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:[,\s]+(\d{4}))?/iu', $p1, $m1)) {
                $day1 = (int) $m1[1];
                $mName1 = mb_strtolower(trim($m1[2]));
                $month1 = $months[$mName1] ?? $month2;
                if (!empty($m1[3])) {
                    $year1 = (int) $m1[3];
                }
            } elseif (preg_match('/(\d{1,2})\./u', $p1, $m1)) {
                $day1 = (int) $m1[1];
            }

            $startAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year1, $month1, $day1, $startTime), 'Europe/Riga');
            $endEndTime = $endTime ?: '20:00:00';
            $endAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year2, $month2, $day2, $endEndTime), 'Europe/Riga');

            return [$startAt, $endAt, $allDay];
        }

        // Single date: "16. novembris, 2026" or "16. novembris" or "16.11.2026"
        if (preg_match('/(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:[,\s]+(\d{4}))?/iu', $clean, $m)) {
            $day = (int) $m[1];
            $monthName = mb_strtolower(trim($m[2]));
            $month = $months[$monthName] ?? (int) date('n');
            $year = !empty($m[3]) ? (int) $m[3] : (int) date('Y');

            $startAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year, $month, $day, $startTime), 'Europe/Riga');
            $endAt = $endTime ? Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year, $month, $day, $endTime), 'Europe/Riga') : null;

            return [$startAt, $endAt, $allDay];
        }

        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $clean, $m)) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];

            $startAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year, $month, $day, $startTime), 'Europe/Riga');
            $endAt = $endTime ? Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year, $month, $day, $endTime), 'Europe/Riga') : null;

            return [$startAt, $endAt, $allDay];
        }

        return [null, null, false];
    }

    /**
     * Parse price and free status
     */
    public function parsePrice(string $content): array
    {
        $isFree = true;
        $priceMin = null;
        $priceMax = null;

        if (
            preg_match('/(?:ieeja|biļet[a-z]*|maksa)\s*[-–—:]?\s*(?:bez\s+maksas|brīva)/iu', $content) ||
            preg_match('/\b(?:bezmaksas|brīva\s+ieeja|ieeja\s+brīva)\b/iu', $content)
        ) {
            $isFree = true;
            $priceMin = 0.0;
            $priceMax = 0.0;
        } elseif (preg_match('/(?:ieeja|biļet[a-z]*|cena|maksa)\s*[-–—:]?\s*(\d+(?:[.,]\d{1,2})?)\s*(?:€|eur)/iu', $content, $pm)) {
            $val = (float) str_replace(',', '.', $pm[1]);
            $isFree = false;
            $priceMin = $val;
            $priceMax = $val;
        }

        return [
            'is_free' => $isFree,
            'price_min' => $priceMin,
            'price_max' => $priceMax,
        ];
    }

    public function isNonEvent(string $title): bool
    {
        $lower = mb_strtolower(trim($title));
        if (empty($lower)) {
            return true;
        }

        $blacklisted = [
            'darba laiks',
            'kases darba laiks',
            'kontakti',
            'repertuārs',
        ];

        return in_array($lower, $blacklisted, true);
    }

    public function cleanText(?string $text): string
    {
        if (empty($text)) {
            return '';
        }
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xc2\xa0", '&nbsp;'], ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    protected function normalizeUrl(?string $relUrl): ?string
    {
        if (empty($relUrl)) {
            return null;
        }

        if (str_starts_with($relUrl, 'http://') || str_starts_with($relUrl, 'https://')) {
            $parsed = parse_url($relUrl);
            return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'www.jelgavasnovads.lv') . ($parsed['path'] ?? '');
        }

        $path = strtok($relUrl, '?');

        return self::BASE_URL . (str_starts_with($path, '/') ? '' : '/') . $path;
    }
}
