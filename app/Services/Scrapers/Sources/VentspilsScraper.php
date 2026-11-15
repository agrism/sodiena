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

class VentspilsScraper extends BaseScraper
{
    public const BASE_URL = 'https://www.ventspils.lv';
    public const CALENDAR_PATH = '/notikumi/?view=all';

    /**
     * Map of known Ventspils venues with normalized metadata
     */
    protected array $venueAddressMap = [
        'koncertzāle "latvija"' => [
            'name' => 'Koncertzāle "Latvija"',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Lielais laukums 1, Ventspils, LV-3601',
            'place_type' => 'concert_hall',
            'lat' => 57.3908,
            'lng' => 21.5645,
        ],
        'teātra nams "jūras vārti"' => [
            'name' => 'Teātra nams "Jūras vārti"',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Karlīnes iela 40, Ventspils, LV-3601',
            'place_type' => 'theater',
            'lat' => 57.3933,
            'lng' => 21.5420,
        ],
        'kino rio' => [
            'name' => 'Kino Rio',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Pils iela 28, Ventspils, LV-3601',
            'place_type' => 'cinema',
            'lat' => 57.3957,
            'lng' => 21.5646,
        ],
        'piejūras brīvdabas muzejs' => [
            'name' => 'Piejūras brīvdabas muzejs',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Riņķa iela 2, Ventspils, LV-3601',
            'place_type' => 'museum',
            'lat' => 57.3857,
            'lng' => 21.5367,
        ],
        'livonijas ordeņa pils' => [
            'name' => 'Livonijas ordeņa pils',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Jāņa iela 17, Ventspils, LV-3601',
            'place_type' => 'museum',
            'lat' => 57.3961,
            'lng' => 21.5592,
        ],
        'amatu māja' => [
            'name' => 'Amatu māja',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Skolas iela 3, Ventspils, LV-3601',
            'place_type' => 'cultural_center',
            'lat' => 57.3948,
            'lng' => 21.5621,
        ],
        'h.dorbes muzejs' => [
            'name' => 'H. Dorbes muzejs',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Ērgļu iela 1, Ventspils, LV-3601',
            'place_type' => 'museum',
            'lat' => 57.3879,
            'lng' => 21.5524,
        ],
        'ventspils kultūras centrs' => [
            'name' => 'Ventspils Kultūras centrs',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Kuldīgas iela 18, Ventspils, LV-3601',
            'place_type' => 'cultural_center',
            'lat' => 57.3922,
            'lng' => 21.5638,
        ],
        'zinātnes centrs "vizium"' => [
            'name' => 'Zinātnes centrs "VIZIUM"',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Rūpniecības iela 2, Ventspils, LV-3601',
            'place_type' => 'museum',
            'lat' => 57.4048,
            'lng' => 21.5732,
        ],
        'olimpiskais centrs "ventspils"' => [
            'name' => 'Olimpiskais centrs "Ventspils"',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Sporta iela 7/9, Ventspils, LV-3601',
            'place_type' => 'sports_facility',
            'lat' => 57.3905,
            'lng' => 21.5516,
        ],
        'pārventas sporta komplekss' => [
            'name' => 'Pārventas sporta komplekss',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Tārgales iela 5b, Ventspils, LV-3602',
            'place_type' => 'sports_facility',
            'lat' => 57.4095,
            'lng' => 21.5891,
        ],
        'ūdens piedzīvojumu parks' => [
            'name' => 'Ūdens piedzīvojumu parks',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Lauku iela 5, Ventspils, LV-3601',
            'place_type' => 'sports_facility',
            'lat' => 57.3887,
            'lng' => 21.5501,
        ],
        'galvenā bibliotēka' => [
            'name' => 'Ventspils Galvenā bibliotēka',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Akmeņu iela 2, Ventspils, LV-3601',
            'place_type' => 'library',
            'lat' => 57.3944,
            'lng' => 21.5647,
        ],
        'pārventas bibliotēka' => [
            'name' => 'Pārventas bibliotēka',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Tārgales iela 4, Ventspils, LV-3602',
            'place_type' => 'library',
            'lat' => 57.4098,
            'lng' => 21.5880,
        ],
        'gāliņciema bibliotēka' => [
            'name' => 'Gāliņciema bibliotēka',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Kuldīgas iela 138, Ventspils, LV-3601',
            'place_type' => 'library',
            'lat' => 57.3774,
            'lng' => 21.5794,
        ],
        'bērnu bibliotēka' => [
            'name' => 'Ventspils Bērnu bibliotēka',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Tirgus iela 5, Ventspils, LV-3601',
            'place_type' => 'library',
            'lat' => 57.3965,
            'lng' => 21.5658,
        ],
        'reņķa dārzs' => [
            'name' => 'Reņķa dārzs',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Sporta iela, Ventspils, LV-3601',
            'place_type' => 'park',
            'lat' => 57.3872,
            'lng' => 21.5583,
        ],
        'bērnu pilsētiņa' => [
            'name' => 'Bērnu pilsētiņa',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Lielais prospekts, Ventspils, LV-3601',
            'place_type' => 'park',
            'lat' => 57.3875,
            'lng' => 21.5435,
        ],
        'bērnu parks "fantāzija"' => [
            'name' => 'Bērnu parks "Fantāzija"',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Talsu iela, Ventspils, LV-3602',
            'place_type' => 'park',
            'lat' => 57.4140,
            'lng' => 21.5930,
        ],
        'lielais laukums' => [
            'name' => 'Lielais laukums',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Lielais laukums, Ventspils, LV-3601',
            'place_type' => 'park',
            'lat' => 57.3908,
            'lng' => 21.5645,
        ],
        'ostas ielas promenāde' => [
            'name' => 'Ostas ielas promenāde',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Ostas iela, Ventspils, LV-3601',
            'place_type' => 'cultural_center',
            'lat' => 57.3970,
            'lng' => 21.5620,
        ],
        'ventspils tirgus' => [
            'name' => 'Ventspils tirgus',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Pils iela 14, Ventspils, LV-3601',
            'place_type' => 'market',
            'lat' => 57.3965,
            'lng' => 21.5665,
        ],
        'ventspils jaunrades nams' => [
            'name' => 'Ventspils Jaunrades nams',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Maiznieku iela 11, Ventspils, LV-3601',
            'place_type' => 'cultural_center',
            'lat' => 57.3920,
            'lng' => 21.5600,
        ],
        'ventspils tehnikums' => [
            'name' => 'Ventspils Tehnikums',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Saules iela 15, Ventspils, LV-3601',
            'place_type' => 'school',
            'lat' => 57.3910,
            'lng' => 21.5550,
        ],
    ];

    public function getSlug(): string
    {
        return 'ventspils';
    }

    public function getName(): string
    {
        return 'Ventspils valstspilsēta';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $today = Carbon::today('Europe/Riga');
        $maxPages = 15;
        $seenExternalIds = [];

        for ($page = 0; $page < $maxPages; $page++) {
            $pageUrl = $page === 0
                ? self::BASE_URL . self::CALENDAR_PATH
                : self::BASE_URL . '/notikumi/page/' . ($page + 1) . '/?view=all';

            $crawler = $this->fetchCrawler($pageUrl);
            if (!$crawler) {
                Log::warning("VentspilsScraper: Failed to fetch page {$pageUrl}");
                break;
            }

            $items = $crawler->filter('article.post-item');
            if ($items->count() === 0) {
                break;
            }

            $allPageEventsPast = true;

            $items->each(function (Crawler $node) use (&$events, &$seenExternalIds, &$allPageEventsPast, $today, $pageUrl) {
                $titleNode = $node->filter('h2.post-item__title, .post-item__title');
                if (!$titleNode->count()) {
                    return;
                }

                $title = $this->cleanText($titleNode->first()->text());
                if (empty($title) || $this->isNonEvent($title)) {
                    return;
                }

                $linkNode = $node->filter('.post-item__title a, a.post-item__image, a[href*="/notikumi/"]');
                $relLink = $linkNode->count() ? ($linkNode->first()->attr('href') ?? '') : '';
                $cleanUrl = $this->normalizeUrl($relLink);

                $slugPart = $cleanUrl ? basename(parse_url($cleanUrl, PHP_URL_PATH)) : Str::slug($title);
                $externalId = 'ventspils-' . $slugPart;

                if (isset($seenExternalIds[$externalId])) {
                    return;
                }

                // Date
                $dateNode = $node->filter('.post-item__event-date');
                $dateStr = $dateNode->count() ? $this->cleanText($dateNode->first()->text()) : '';
                [$startAt, $endAt, $allDay] = $this->parseLatvianDates($dateStr);

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

                // Category from URL or content
                $urlCatSlug = $this->extractCategoryFromUrl($cleanUrl);

                // Image
                $imgNode = $node->filter('img');
                $imageUrl = $imgNode->count() ? $imgNode->first()->attr('src') : null;
                if ($imageUrl && !str_starts_with($imageUrl, 'http')) {
                    $imageUrl = self::BASE_URL . (str_starts_with($imageUrl, '/') ? '' : '/') . $imageUrl;
                }

                // Location resolution from title, category and URL
                $venueInfo = $this->resolveLocation('', $title, $urlCatSlug);

                // Categories & Entertainment type strictly adhering to AGENTS.md
                $canonicalCategories = $this->resolveCategories($urlCatSlug, $title);
                $entertainmentType = $this->resolveEntertainmentType($canonicalCategories, $title);

                // Price
                $priceData = $this->parsePrice($title);

                $description = $title;
                $seenExternalIds[$externalId] = true;

                $dto = new ScrapedEventDTO(
                    title: $title,
                    startAt: $startAt,
                    endAt: $endAt,
                    description: $description,
                    shortDescription: Str::limit(strip_tags($description), 160),
                    venueName: $venueInfo['name'],
                    city: $venueInfo['city'],
                    region: $venueInfo['region'],
                    address: $venueInfo['address'],
                    latitude: $venueInfo['lat'] ?? null,
                    longitude: $venueInfo['lng'] ?? null,
                    placeType: $venueInfo['place_type'],
                    categoryNames: $canonicalCategories,
                    entertainmentType: $entertainmentType,
                    isFree: $priceData['is_free'],
                    priceMin: $priceData['price_min'],
                    priceMax: $priceData['price_max'],
                    currency: 'EUR',
                    ticketUrl: $cleanUrl ?: $pageUrl,
                    imageUrl: $imageUrl,
                    sourceUrl: $cleanUrl ?: $pageUrl,
                    sourceExternalId: $externalId,
                    locale: 'lv',
                    rawData: [
                        'date_raw' => $dateStr,
                        'category_slug' => $urlCatSlug,
                    ]
                );

                $events->push($dto);
            });

            // If all events on the page are in the past, stop paginating
            if ($allPageEventsPast && $items->count() > 0) {
                break;
            }

            // If there's no next page link in the pager, stop
            if ($crawler->filter('.pager__link--next')->count() === 0) {
                break;
            }
        }

        return $events;
    }

    /**
     * Extract category slug from URL e.g. /notikumi/sports/pasakums/ -> sports
     */
    public function extractCategoryFromUrl(?string $url): string
    {
        if (empty($url)) {
            return '';
        }

        $path = trim(parse_url($url, PHP_URL_PATH) ?? '', '/');
        $parts = explode('/', $path);

        if (count($parts) >= 2 && $parts[0] === 'notikumi') {
            return $parts[1];
        }

        return '';
    }

    /**
     * Resolve Venue and Location
     */
    public function resolveLocation(string $locationRaw, string $title = '', string $context = ''): array
    {
        $text = mb_strtolower($locationRaw . ' ' . $title . ' ' . $context, 'UTF-8');

        // Stem mapping for known Ventspils venues
        $stemVenueMap = [
            'kino rio' => 'kino rio',
            'teātra nam' => 'teātra nams "jūras vārti"',
            'teatra nam' => 'teātra nams "jūras vārti"',
            'jūras vārt' => 'teātra nams "jūras vārti"',
            'juras vart' => 'teātra nams "jūras vārti"',
            'koncertzāl' => 'koncertzāle "latvija"',
            'koncertzal' => 'koncertzāle "latvija"',
            'piejūras brīvdabas muzej' => 'piejūras brīvdabas muzejs',
            'piejuras brivdabas muzej' => 'piejūras brīvdabas muzejs',
            'livonijas ordeņa pil' => 'livonijas ordeņa pils',
            'livonijas orden' => 'livonijas ordeņa pils',
            'amatu māj' => 'amatu māja',
            'amatu maj' => 'amatu māja',
            'h.dorbes' => 'h.dorbes muzejs',
            'dorbes' => 'h.dorbes muzejs',
            'vizium' => 'zinātnes centrs "vizium"',
            'ventspils kultūras centr' => 'ventspils kultūras centrs',
            'ventspils kulturas centr' => 'ventspils kultūras centrs',
            'kultūras centr' => 'ventspils kultūras centrs',
            'kulturas centr' => 'ventspils kultūras centrs',
            'pārventas sporta kompleks' => 'pārventas sporta komplekss',
            'parventas sporta kompleks' => 'pārventas sporta komplekss',
            'pārventas sporta hall' => 'pārventas sporta komplekss',
            'parventas sporta hall' => 'pārventas sporta komplekss',
            'ūdens piedzīvojumu park' => 'ūdens piedzīvojumu parks',
            'udens piedzivojumu park' => 'ūdens piedzīvojumu parks',
            'oc "ventspils"' => 'olimpiskais centrs "ventspils"',
            'oc ventspils' => 'olimpiskais centrs "ventspils"',
            'olimpiskais centrs "ventspils"' => 'olimpiskais centrs "ventspils"',
            'olimpiskais centrs ventspils' => 'olimpiskais centrs "ventspils"',
            'olimpiskā centra "ventspils"' => 'olimpiskais centrs "ventspils"',
            'pārventas bibliotēk' => 'pārventas bibliotēka',
            'parventas bibliotek' => 'pārventas bibliotēka',
            'gāliņciema bibliotēk' => 'gāliņciema bibliotēka',
            'galinciema bibliotek' => 'gāliņciema bibliotēka',
            'gāliņciema sabiedrisk' => 'gāliņciema bibliotēka',
            'galvenā bibliotēk' => 'galvenā bibliotēka',
            'galvena bibliotek' => 'galvenā bibliotēka',
            'bērnu bibliotēk' => 'bērnu bibliotēka',
            'bernu bibliotek' => 'bērnu bibliotēka',
            'reņķa dārz' => 'reņķa dārzs',
            'renka darz' => 'reņķa dārzs',
            'bērnu pilsētiņ' => 'bērnu pilsētiņa',
            'bernu pilsetin' => 'bērnu pilsētiņa',
            'fantāzij' => 'bērnu parks "fantāzija"',
            'fantazij' => 'bērnu parks "fantāzija"',
            'lielais laukum' => 'lielais laukums',
            'ostas ielas promenād' => 'ostas ielas promenāde',
            'ventspils tirg' => 'ventspils tirgus',
            'tirgus laukum' => 'ventspils tirgus',
            'jaunrades nam' => 'ventspils jaunrades nams',
            'ventspils tehnikum' => 'ventspils tehnikums',
        ];

        foreach ($stemVenueMap as $stem => $mapKey) {
            if (str_contains($text, $stem) && isset($this->venueAddressMap[$mapKey])) {
                return $this->venueAddressMap[$mapKey];
            }
        }

        // Ventspils novads parishes / towns
        $novadsMap = [
            'jūrkaln' => ['name' => 'Jūrkalnes tautas nams', 'city' => 'Jūrkalne', 'address' => 'Jūrkalne, Ventspils novads'],
            'jurkaln' => ['name' => 'Jūrkalnes tautas nams', 'city' => 'Jūrkalne', 'address' => 'Jūrkalne, Ventspils novads'],
            'pilten' => ['name' => 'Piltenes kultūras nams', 'city' => 'Piltene', 'address' => 'Lielā iela 19, Piltene, Ventspils novads'],
            'ugāl' => ['name' => 'Ugāles tautas nams "Gaisma"', 'city' => 'Ugāle', 'address' => 'Ugāle, Ventspils novads'],
            'ugal' => ['name' => 'Ugāles tautas nams "Gaisma"', 'city' => 'Ugāle', 'address' => 'Ugāle, Ventspils novads'],
            'usm' => ['name' => 'Usmas tautas nams', 'city' => 'Usma', 'address' => 'Usma, Ventspils novads'],
            'zir' => ['name' => 'Ziru tautas nams', 'city' => 'Ziras', 'address' => 'Ziras, Ventspils novads'],
            'zlēk' => ['name' => 'Zlēku kultūras nams', 'city' => 'Zlēkas', 'address' => 'Zlēkas, Ventspils novads'],
            'zlek' => ['name' => 'Zlēku kultūras nams', 'city' => 'Zlēkas', 'address' => 'Zlēkas, Ventspils novads'],
            'pop' => ['name' => 'Popes kultūras nams', 'city' => 'Pope', 'address' => 'Pope, Ventspils novads'],
            'anc' => ['name' => 'Ances kultūras nams', 'city' => 'Ance', 'address' => 'Ance, Ventspils novads'],
            'puz' => ['name' => 'Puzes kultūras nams', 'city' => 'Puze', 'address' => 'Puze, Ventspils novads'],
            'tārgal' => ['name' => 'Tārgales pamatskola', 'city' => 'Tārgale', 'address' => 'Tārgale, Ventspils novads'],
            'targal' => ['name' => 'Tārgales pamatskola', 'city' => 'Tārgale', 'address' => 'Tārgale, Ventspils novads'],
            'užav' => ['name' => 'Užavas tautas nams', 'city' => 'Užava', 'address' => 'Užava, Ventspils novads'],
            'uzav' => ['name' => 'Užavas tautas nams', 'city' => 'Užava', 'address' => 'Užava, Ventspils novads'],
            'vārv' => ['name' => 'Vārves muiža', 'city' => 'Vārve', 'address' => 'Vārve, Ventspils novads'],
            'varv' => ['name' => 'Vārves muiža', 'city' => 'Vārve', 'address' => 'Vārve, Ventspils novads'],
        ];

        foreach ($novadsMap as $stem => $info) {
            if (str_contains($text, $stem)) {
                return [
                    'name' => $info['name'],
                    'city' => $info['city'],
                    'region' => 'Kurzeme',
                    'address' => $info['address'],
                    'place_type' => 'cultural_center',
                ];
            }
        }

        $cleanLoc = trim($locationRaw);
        $venueName = !empty($cleanLoc) ? $cleanLoc : 'Ventspils';
        $address = !empty($cleanLoc) ? "{$cleanLoc}, Ventspils" : 'Jūras iela 36, Ventspils, LV-3601';

        return [
            'name' => $venueName,
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => $address,
            'place_type' => 'cultural_center',
        ];
    }

    /**
     * Resolve categories adhering strictly to AGENTS.md
     */
    public function resolveCategories(string $categorySlug, string $title): array
    {
        $text = mb_strtolower($categorySlug . ' ' . $title, 'UTF-8');

        // STRICT RULE 1: Cinema / Kino (Priority)
        // Kino Rio or film keywords -> ALWAYS KINO, NEVER TEATRIS
        // Note: kinoteātris contains "teātr", but MUST NEVER be classified as Teātris
        $cinemaKeywords = [
            'kino rio', 'kinoteātr', 'kinoteatr',
            'filma', 'filmas', 'filmu', 'filmā',
            'kinoseanss', 'filmas seanss', 'spēlfilma',
            'dokumentālā filma', 'animācijas filma', 'kinofestivāls',
            'kino seanss', 'kino:', 'kino |',
        ];

        $isCinema = false;
        foreach ($cinemaKeywords as $kw) {
            if (str_contains($text, $kw)) {
                $isCinema = true;
                break;
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
        // Only actual theater productions (izrāde, luga, iestudējums, opera, balets)
        $theatreKeywords = [
            'teātra izrāde', 'izrāde', 'izrādē', 'izrādi', 'izrādes', 'iestudējums',
            'luga', 'lugā', 'komēdija', 'traģēdija', 'stāvizrāde',
            'stand-up', 'standup', 'leļļu izrāde', 'balets', 'opera',
        ];

        $isTheatre = false;
        foreach ($theatreKeywords as $kw) {
            if (str_contains($text, $kw)) {
                $isTheatre = true;
                break;
            }
        }

        if ($isTheatre) {
            $categories = ['Teātris'];
            if (str_contains($text, 'bērniem') || str_contains($text, 'ģimenēm') || str_contains($text, 'leļļu')) {
                $categories[] = 'Bērniem';
            }
            return array_unique($categories);
        }

        // Sports
        $sportsKeywords = [
            'sports', 'čempionāt', 'cempionat', 'spēle', 'spele', 'spēles', 'speles',
            'basketbols', 'basketbola', 'hokejs', 'hokeja', 'skrējiens', 'maratons',
            'pusmaratons', 'slidošana', 'turnīrs', 'turnirs', 'volejbols', 'peldēšanā',
            'bokss', 'boksa', 'skeitparks',
        ];

        if ($categorySlug === 'sports') {
            return ['Sports'];
        }
        foreach ($sportsKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return ['Sports'];
            }
        }

        // Music & Concerts
        $musicKeywords = [
            'koncerts', 'koncertā', 'koncertprogramma', 'dziedātājs',
            'koris', 'koru', 'orķestris', 'orķestra', 'kvartets',
            'akustiskā tūre', 'dziesmas', 'muzikāls', 'džezs', 'roks', 'balle',
            'grupa', 'grupas', 'koncertzāle "latvija"',
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

        // Exhibitions & Art & Museums
        $exhibitionKeywords = [
            'izstāde', 'izstādē', 'izstādi', 'izstādes', 'ekspozīcija',
            'personālizstāde', 'gleznas', 'glezniecība', 'tēlniecība',
            'fotogrāfiju izstāde', 'krājuma izstāde', 'muzejs',
        ];

        if ($categorySlug === 'izstades' || $categorySlug === 'muzejs') {
            return ['Izstādes'];
        }
        foreach ($exhibitionKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return ['Izstādes'];
            }
        }

        // Children / Family
        if ($categorySlug === 'gimenem-ar-berniem' || str_contains($text, 'bērniem') || str_contains($text, 'bērnu pilsētiņ') || str_contains($text, 'leļļu') || str_contains($text, 'pasaku rīts')) {
            return ['Bērniem'];
        }

        // Fallback: Cits
        return ['Cits'];
    }

    /**
     * Resolve entertainment type
     */
    public function resolveEntertainmentType(array $categories, string $title): string
    {
        $text = mb_strtolower($title, 'UTF-8');

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
        if (str_contains($text, 'festivāls') || str_contains($text, 'svētki') || str_contains($text, 'tirdziņš') || str_contains($text, 'tirgus') || str_contains($text, 'gadatirgus')) {
            return 'festival';
        }

        return 'event';
    }

    /**
     * Parse Latvian dates from date string e.g. "07.novembris, 2026 - 17.novembris, 2026" or "17.novembris, 2026"
     */
    public function parseLatvianDates(string $dateStr): array
    {
        $months = [
            'janvāris' => 1, 'janvārī' => 1, 'janvāra' => 1, 'janv' => 1, 'jan' => 1,
            'februāris' => 2, 'februārī' => 2, 'februāra' => 2, 'febr' => 2, 'feb' => 2,
            'marts' => 3, 'martā' => 3, 'marta' => 3, 'mar' => 3,
            'aprīlis' => 4, 'aprīlī' => 4, 'aprīļa' => 4, 'apr' => 4,
            'maijs' => 5, 'maijā' => 5, 'maija' => 5, 'mai' => 5,
            'jūnijs' => 6, 'jūnijā' => 6, 'jūnija' => 6, 'jūn' => 6, 'jun' => 6,
            'jūlijs' => 7, 'jūlijā' => 7, 'jūlija' => 7, 'jūl' => 7, 'jul' => 7,
            'augusts' => 8, 'augustā' => 8, 'augusta' => 8, 'aug' => 8,
            'septembris' => 9, 'septembrī' => 9, 'septembra' => 9, 'sept' => 9, 'sep' => 9,
            'oktobris' => 10, 'oktobrī' => 10, 'oktobra' => 10, 'okt' => 10, 'oct' => 10,
            'novembris' => 11, 'novembrī' => 11, 'novembra' => 11, 'nov' => 11,
            'decembris' => 12, 'decembrī' => 12, 'decembra' => 12, 'dec' => 12,
        ];

        $clean = trim(str_replace(['&#8211;', '&ndash;', '–', '—'], '-', $dateStr));
        if (empty($clean)) {
            return [null, null, false];
        }

        // Date range e.g. "07.novembris, 2026 - 17.novembris, 2026"
        if (str_contains($clean, '-')) {
            $parts = explode('-', $clean, 2);
            $p1 = trim($parts[0]);
            $p2 = trim($parts[1]);

            // Match second date: e.g. "17.novembris, 2026" or "17.11.2026"
            $year2 = (int) date('Y');
            $month2 = (int) date('n');
            $day2 = 1;

            if (preg_match('/(\d{1,2})\.?\s*([a-zāčēģīķļņšūž]+)(?:[,\s]+(\d{4}))?/iu', $p2, $m2)) {
                $day2 = (int) $m2[1];
                $mName2 = mb_strtolower(trim($m2[2]));
                $month2 = $months[$mName2] ?? (int) date('n');
                if (!empty($m2[3])) {
                    $year2 = (int) $m2[3];
                }
            } elseif (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $p2, $m2)) {
                $day2 = (int) $m2[1];
                $month2 = (int) $m2[2];
                $year2 = (int) $m2[3];
            }

            // Match first date: e.g. "07.novembris, 2026" or "07." or "07.11."
            $year1 = $year2;
            $month1 = $month2;
            $day1 = 1;

            if (preg_match('/(\d{1,2})\.?\s*([a-zāčēģīķļņšūž]+)(?:[,\s]+(\d{4}))?/iu', $p1, $m1)) {
                $day1 = (int) $m1[1];
                $mName1 = mb_strtolower(trim($m1[2]));
                $month1 = $months[$mName1] ?? $month2;
                if (!empty($m1[3])) {
                    $year1 = (int) $m1[3];
                }
            } elseif (preg_match('/(\d{1,2})\.(\d{1,2})\.?/u', $p1, $m1)) {
                $day1 = (int) $m1[1];
                $month1 = (int) $m1[2];
            } elseif (preg_match('/(\d{1,2})\./u', $p1, $m1)) {
                $day1 = (int) $m1[1];
            }

            $startAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d 10:00:00', $year1, $month1, $day1), 'Europe/Riga');
            $endAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d 20:00:00', $year2, $month2, $day2), 'Europe/Riga');

            return [$startAt, $endAt, true];
        }

        // Single date: "16.novembris, 2026" or "16. novembris, 2026"
        if (preg_match('/(\d{1,2})\.?\s*([a-zāčēģīķļņšūž]+)(?:[,\s]+(\d{4}))?/iu', $clean, $m)) {
            $day = (int) $m[1];
            $monthName = mb_strtolower(trim($m[2]));
            $month = $months[$monthName] ?? (int) date('n');
            $year = !empty($m[3]) ? (int) $m[3] : (int) date('Y');

            $startAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d 10:00:00', $year, $month, $day), 'Europe/Riga');

            return [$startAt, null, true];
        }

        // Numeric date: "16.11.2026"
        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $clean, $m)) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];

            $startAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d 10:00:00', $year, $month, $day), 'Europe/Riga');

            return [$startAt, null, true];
        }

        return [null, null, false];
    }

    /**
     * Parse price and free status
     */
    public function parsePrice(string $title): array
    {
        $text = mb_strtolower($title, 'UTF-8');

        if (str_contains($text, 'bezmaksas') || str_contains($text, 'bez maksas') || str_contains($text, 'ieeja brīva')) {
            return [
                'is_free' => true,
                'price_min' => null,
                'price_max' => null,
            ];
        }

        $isFree = true;
        $priceMin = null;
        $priceMax = null;

        if (preg_match('/(\d+(?:[.,]\d{2})?)\s*-\s*(\d+(?:[.,]\d{2})?)\s*(?:€|eur)/iu', $title, $m)) {
            $isFree = false;
            $priceMin = (float) str_replace(',', '.', $m[1]);
            $priceMax = (float) str_replace(',', '.', $m[2]);
        } elseif (preg_match('/(?:biļet[a-z]*|maksa|cena)[:\s]+(\d+(?:[.,]\d{2})?)\s*(?:€|eur)/iu', $title, $m)) {
            $isFree = false;
            $priceMin = (float) str_replace(',', '.', $m[1]);
            $priceMax = $priceMin;
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
        $cleaned = parent::cleanText($text);
        return trim($cleaned ?? '');
    }

    protected function normalizeUrl(?string $relUrl): ?string
    {
        if (empty($relUrl)) {
            return null;
        }

        if (str_starts_with($relUrl, 'http://') || str_starts_with($relUrl, 'https://')) {
            $parsed = parse_url($relUrl);
            return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'www.ventspils.lv') . ($parsed['path'] ?? '');
        }

        $path = strtok($relUrl, '?');
        return self::BASE_URL . (str_starts_with($path, '/') ? '' : '/') . $path;
    }
}
