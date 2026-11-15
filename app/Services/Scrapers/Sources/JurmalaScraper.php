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

class JurmalaScraper extends BaseScraper
{
    public const BASE_URL = 'https://www.jurmala.lv';
    public const CALENDAR_PATH = '/lv/notikumu-kalendars';

    /**
     * Map of known Jūrmala venues with normalized metadata
     */
    protected array $venueAddressMap = [
        'dzintaru koncertzāle' => [
            'name' => 'Dzintaru koncertzāle',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Turaidas iela 1, Jūrmala, LV-2015',
            'place_type' => 'concert_hall',
            'lat' => 56.9749,
            'lng' => 23.8114,
        ],
        'skvērs pie dzintaru koncertzāles' => [
            'name' => 'Skvērs pie Dzintaru koncertzāles',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Turaidas iela 1, Jūrmala, LV-2015',
            'place_type' => 'park',
            'lat' => 56.9749,
            'lng' => 23.8114,
        ],
        'jūrmalas kultūras centrs' => [
            'name' => 'Jūrmalas Kultūras centrs',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Jomas iela 35, Jūrmala, LV-2015',
            'place_type' => 'cultural_center',
            'lat' => 56.9720,
            'lng' => 23.7975,
        ],
        'kauguru kultūras nams' => [
            'name' => 'Kauguru kultūras nams',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Raiņa iela 110, Jūrmala, LV-2016',
            'place_type' => 'cultural_center',
            'lat' => 56.9583,
            'lng' => 23.6125,
        ],
        'mellužu estrāde' => [
            'name' => 'Mellužu estrāde',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Mellužu prospekts 6, Jūrmala, LV-2008',
            'place_type' => 'amphitheater',
            'lat' => 56.9642,
            'lng' => 23.7228,
        ],
        'jūrmalas teātris' => [
            'name' => 'Jūrmalas teātris',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Muižas iela 7, Jūrmala, LV-2010',
            'place_type' => 'theater',
            'lat' => 56.9839,
            'lng' => 23.8560,
        ],
        'jūrmalas muzejs' => [
            'name' => 'Jūrmalas muzejs',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Tirgoņu iela 29, Jūrmala, LV-2015',
            'place_type' => 'museum',
            'lat' => 56.9723,
            'lng' => 23.7997,
        ],
        'bulduru izstāžu nams' => [
            'name' => 'Bulduru Izstāžu nams',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Muižas iela 6, Jūrmala, LV-2010',
            'place_type' => 'gallery',
            'lat' => 56.9839,
            'lng' => 23.8569,
        ],
        'jūrmalas brīvdabas muzejs' => [
            'name' => 'Jūrmalas Brīvdabas muzejs',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Tīklu iela 1A, Jūrmala, LV-2010',
            'place_type' => 'museum',
            'lat' => 56.9856,
            'lng' => 23.8864,
        ],
        'aspazijas māja' => [
            'name' => 'Aspazijas māja',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Z. Meierovica prospekts 18/20, Jūrmala, LV-2015',
            'place_type' => 'museum',
            'lat' => 56.9691,
            'lng' => 23.7742,
        ],
        'raiņa un aspazijas vasarnīca' => [
            'name' => 'Raiņa un Aspazijas vasarnīca',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'J. Pliekšāna iela 5/7, Jūrmala, LV-2015',
            'place_type' => 'museum',
            'lat' => 56.9715,
            'lng' => 23.8160,
        ],
        'mākslas stacija "dubulti"' => [
            'name' => 'Mākslas stacija "Dubulti"',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Zigfrīda Meierovica prospekts 4, Jūrmala, LV-2015',
            'place_type' => 'gallery',
            'lat' => 56.9705,
            'lng' => 23.7735,
        ],
        'dubultu koncertzāle' => [
            'name' => 'Dubultu koncertzāle',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Strēlnieku prospekts 30, Jūrmala, LV-2015',
            'place_type' => 'concert_hall',
            'lat' => 56.9698,
            'lng' => 23.7712,
        ],
        'jūrmalas mūzikas vidusskola' => [
            'name' => 'Jūrmalas Mūzikas vidusskola',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Strēlnieku prospekts 30, Jūrmala, LV-2015',
            'place_type' => 'school',
            'lat' => 56.9698,
            'lng' => 23.7712,
        ],
        'jūrmalas mākslas skola' => [
            'name' => 'Jūrmalas Mākslas skola',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Strēlnieku prospekts 30, Jūrmala, LV-2015',
            'place_type' => 'school',
            'lat' => 56.9698,
            'lng' => 23.7712,
        ],
        'vitālija jermolajeva gleznu teātris "inner light"' => [
            'name' => 'Vitālija Jermolajeva gleznu teātris "Inner light"',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Omnibusa iela 19, Jūrmala, LV-2015',
            'place_type' => 'gallery',
            'lat' => 56.9729,
            'lng' => 23.8055,
        ],
        'jūrmalas kultūrtelpas un vides dizaina centrs' => [
            'name' => 'Jūrmalas Kultūrtelpas un vides dizaina centrs',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Pils iela 1, Jūrmala, LV-2015',
            'place_type' => 'cultural_center',
            'lat' => 56.9774,
            'lng' => 23.8242,
        ],
        'ķemeru kūrorta parks' => [
            'name' => 'Ķemeru kūrorta parks',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Tūristu iela 32, Jūrmala, LV-2012',
            'place_type' => 'park',
            'lat' => 56.9535,
            'lng' => 23.5042,
        ],
        'ķemeru ūdenstornis' => [
            'name' => 'Ķemeru ūdenstornis',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Tūristu iela 32, Jūrmala, LV-2012',
            'place_type' => 'historical_site',
            'lat' => 56.9535,
            'lng' => 23.5042,
        ],
        'kauguru parks' => [
            'name' => 'Kauguru parks',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Mazā Nometņu iela 1, Jūrmala, LV-2016',
            'place_type' => 'park',
            'lat' => 56.9590,
            'lng' => 23.6150,
        ],
        'dzintaru mežaparks' => [
            'name' => 'Dzintaru mežaparks',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Lazdonas iela 1, Jūrmala, LV-2015',
            'place_type' => 'park',
            'lat' => 56.9770,
            'lng' => 23.8150,
        ],
        'horna dārzs' => [
            'name' => 'Horna dārzs',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Jomas iela 35, Jūrmala, LV-2015',
            'place_type' => 'park',
            'lat' => 56.9720,
            'lng' => 23.7975,
        ],
        'slokas stadions' => [
            'name' => 'Slokas stadions',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Skolas iela 44, Jūrmala, LV-2011',
            'place_type' => 'sports_facility',
            'lat' => 56.9560,
            'lng' => 23.6180,
        ],
        'jūrmalas centrālā bibliotēka' => [
            'name' => 'Jūrmalas Centrālā bibliotēka',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Strēlnieku prospekts 38, Jūrmala, LV-2015',
            'place_type' => 'library',
        ],
        'kauguru bibliotēka' => [
            'name' => 'Kauguru bibliotēka',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Engures iela 4A, Jūrmala, LV-2016',
            'place_type' => 'library',
        ],
        'slokas bibliotēka' => [
            'name' => 'Slokas bibliotēka',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Dzirnavu iela 1, Jūrmala, LV-2011',
            'place_type' => 'library',
        ],
        'bulduru bibliotēka' => [
            'name' => 'Bulduru bibliotēka',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Muižas iela 6, Jūrmala, LV-2010',
            'place_type' => 'library',
        ],
        'asaru bibliotēka' => [
            'name' => 'Asaru bibliotēka',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Dāvja iela 9, Jūrmala, LV-2008',
            'place_type' => 'library',
        ],
    ];

    public function getSlug(): string
    {
        return 'jurmala';
    }

    public function getName(): string
    {
        return 'Jūrmalas valstspilsēta';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $baseUrl = $source->url ?: (self::BASE_URL . self::CALENDAR_PATH);
        $today = Carbon::today('Europe/Riga');
        $maxPages = 10;
        $seenExternalIds = [];

        for ($page = 0; $page < $maxPages; $page++) {
            $pageUrl = $page === 0 ? $baseUrl : $baseUrl . '?page=' . $page;
            $crawler = $this->fetchCrawler($pageUrl);

            if (!$crawler) {
                Log::warning("JurmalaScraper: Failed to fetch page {$pageUrl}");
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

                // Links & External ID
                $linkNode = $node->filter('h2.event-title a, a[href*="/notikums/"]');
                $relLink = $linkNode->count() ? $linkNode->first()->attr('href') : '';
                $cleanUrl = $this->normalizeUrl($relLink);

                $slugPart = $cleanUrl ? basename(parse_url($cleanUrl, PHP_URL_PATH)) : Str::slug($title);
                $externalId = 'jurmala-' . $slugPart;

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
     * Resolve Venue and Location from raw string, title, and description
     */
    public function resolveLocation(string $locationRaw, string $title = '', string $description = ''): array
    {
        $text = mb_strtolower($locationRaw . ' ' . $title . ' ' . $description, 'UTF-8');

        // Stem matching for known Jūrmala venues
        $stemVenueMap = [
            'skvērs pie dzintaru koncertzāl' => 'skvērs pie dzintaru koncertzāles',
            'pie dzintaru koncertzāl' => 'skvērs pie dzintaru koncertzāles',
            'dzintaru koncertzāl' => 'dzintaru koncertzāle',
            'jūrmalas kultūras centr' => 'jūrmalas kultūras centrs',
            'jurmalas kulturas centr' => 'jūrmalas kultūras centrs',
            'kauguru kultūras nam' => 'kauguru kultūras nams',
            'kauguru kulturas nam' => 'kauguru kultūras nams',
            'mellužu estrād' => 'mellužu estrāde',
            'melluzu estrad' => 'mellužu estrāde',
            'jūrmalas teātr' => 'jūrmalas teātris',
            'jurmalas teatr' => 'jūrmalas teātris',
            'jūrmalas muzej' => 'jūrmalas muzejs',
            'jurmalas muzej' => 'jūrmalas muzejs',
            'bulduru izstāžu nam' => 'bulduru izstāžu nams',
            'bulduru izstazu nam' => 'bulduru izstāžu nams',
            'jūrmalas brīvdabas muzej' => 'jūrmalas brīvdabas muzejs',
            'jurmalas brivdabas muzej' => 'jūrmalas brīvdabas muzejs',
            'aspazijas māj' => 'aspazijas māja',
            'aspazijas maj' => 'aspazijas māja',
            'raiņa un aspazijas vasarnīc' => 'raiņa un aspazijas vasarnīca',
            'mākslas stacija "dubulti"' => 'mākslas stacija "dubulti"',
            'mākslas stacija dubulti' => 'mākslas stacija "dubulti"',
            'makslas stacija "dubulti"' => 'mākslas stacija "dubulti"',
            'makslas stacija dubulti' => 'mākslas stacija "dubulti"',
            'dubultu mākslas stacij' => 'mākslas stacija "dubulti"',
            'dubultu koncertzāl' => 'dubultu koncertzāle',
            'jūrmalas mūzikas vidusskol' => 'jūrmalas mūzikas vidusskola',
            'jūrmalas mākslas skol' => 'jūrmalas mākslas skola',
            'inner light' => 'vitālija jermolajeva gleznu teātris "inner light"',
            'gleznu teātris "inner light"' => 'vitālija jermolajeva gleznu teātris "inner light"',
            'kultūrtelpas un vides dizaina centr' => 'jūrmalas kultūrtelpas un vides dizaina centrs',
            'ķemeru kūrorta park' => 'ķemeru kūrorta parks',
            'kemeru kurorta park' => 'ķemeru kūrorta parks',
            'ķemeru ūdenstorn' => 'ķemeru ūdenstornis',
            'kemeru udenstorn' => 'ķemeru ūdenstornis',
            'kauguru park' => 'kauguru parks',
            'dzintaru mežapark' => 'dzintaru mežaparks',
            'dzintaru mezapark' => 'dzintaru mežaparks',
            'horna dārz' => 'horna dārzs',
            'horna darz' => 'horna dārzs',
            'slokas stadion' => 'slokas stadions',
            'jūrmalas centrālā bibliotēk' => 'jūrmalas centrālā bibliotēka',
            'kauguru bibliotēk' => 'kauguru bibliotēka',
            'slokas bibliotēk' => 'slokas bibliotēka',
            'bulduru bibliotēk' => 'bulduru bibliotēka',
            'asaru bibliotēk' => 'asaru bibliotēka',
        ];

        foreach ($stemVenueMap as $stem => $mapKey) {
            if (str_contains($text, $stem) && isset($this->venueAddressMap[$mapKey])) {
                return $this->venueAddressMap[$mapKey];
            }
        }

        // Neighborhood recognition for address fallback
        $neighborhoodMap = [
            'major' => 'Majori, Jūrmala, LV-2015',
            'dubult' => 'Dubulti, Jūrmala, LV-2015',
            'dzintar' => 'Dzintari, Jūrmala, LV-2015',
            'buldur' => 'Bulduri, Jūrmala, LV-2010',
            'melluž' => 'Melluži, Jūrmala, LV-2008',
            'melluz' => 'Melluži, Jūrmala, LV-2008',
            'asar' => 'Asari, Jūrmala, LV-2008',
            'kaugur' => 'Kauguri, Jūrmala, LV-2016',
            'slok' => 'Sloka, Jūrmala, LV-2011',
            'ķemer' => 'Ķemeri, Jūrmala, LV-2012',
            'kemer' => 'Ķemeri, Jūrmala, LV-2012',
            'vaivar' => 'Vaivari, Jūrmala, LV-2008',
            'lielup' => 'Lielupe, Jūrmala, LV-2010',
            'priedain' => 'Priedaine, Jūrmala, LV-2010',
            'valter' => 'Valteri, Jūrmala, LV-2008',
            'buļļuciem' => 'Buļļuciems, Jūrmala, LV-2010',
        ];

        $matchedAddress = null;
        foreach ($neighborhoodMap as $stem => $addr) {
            if (str_contains($text, $stem)) {
                $matchedAddress = $addr;
                break;
            }
        }

        $cleanLoc = trim($locationRaw);
        $venueName = !empty($cleanLoc) ? $cleanLoc : 'Jūrmalas valstspilsēta';
        $address = $matchedAddress ?: (!empty($cleanLoc) ? "{$cleanLoc}, Jūrmala" : 'Jomas iela 1/5, Jūrmala, LV-2015');

        return [
            'name' => $venueName,
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => $address,
            'place_type' => 'cultural_center',
        ];
    }

    /**
     * Resolve categories adhering strictly to AGENTS.md
     */
    public function resolveCategories(array $rawCategories, string $title, string $description = ''): array
    {
        $text = mb_strtolower(implode(' ', $rawCategories) . ' ' . $title . ' ' . $description, 'UTF-8');

        // STRICT RULE 1: Cinema / Kino (Priority)
        // Venues or keywords: ALWAYS KINO, NEVER TEATRIS
        // Note: kinoteātris contains "teātr", but MUST NEVER be classified as Teātris
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
        $isExhibition = in_array('Izstāde', $rawCategories, true) || in_array('Izstādes', $rawCategories, true);
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
        $sportsKeywords = ['sports', 'skrējiens', 'maratons', 'basketbols', 'futbols', 'hokejs', 'slidošana', 'turnīrs', 'nūjošana', 'šahs', 'orientēšanās', 'velobrauciens'];
        foreach ($sportsKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return ['Sports'];
            }
        }

        // Children / Family
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

        // Date range e.g. "16. - 20. novembris, 2026" or "16. novembris - 5. decembris, 2026"
        if (str_contains($clean, '-')) {
            $parts = explode('-', $clean, 2);
            $p1 = trim($parts[0]);
            $p2 = trim($parts[1]);

            // Match second date: e.g. "20. novembris, 2026" or "20.11.2026"
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
            } elseif (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $p2, $m2)) {
                $day2 = (int) $m2[1];
                $month2 = (int) $m2[2];
                $year2 = (int) $m2[3];
            }

            // Match first date: could be just "16." or "16. oktobris" or "16.11."
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
            } elseif (preg_match('/(\d{1,2})\.(\d{1,2})\.?/u', $p1, $m1)) {
                $day1 = (int) $m1[1];
                $month1 = (int) $m1[2];
            } elseif (preg_match('/(\d{1,2})\./u', $p1, $m1)) {
                $day1 = (int) $m1[1];
            }

            $startAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year1, $month1, $day1, $startTime), 'Europe/Riga');
            $endEndTime = $endTime ?: '20:00:00';
            $endAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year2, $month2, $day2, $endEndTime), 'Europe/Riga');

            return [$startAt, $endAt, $allDay];
        }

        // Single date: "16. novembris, 2026" or "16. novembris"
        if (preg_match('/(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:[,\s]+(\d{4}))?/iu', $clean, $m)) {
            $day = (int) $m[1];
            $monthName = mb_strtolower(trim($m[2]));
            $month = $months[$monthName] ?? (int) date('n');
            $year = !empty($m[3]) ? (int) $m[3] : (int) date('Y');

            $startAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year, $month, $day, $startTime), 'Europe/Riga');
            $endAt = $endTime ? Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year, $month, $day, $endTime), 'Europe/Riga') : null;

            return [$startAt, $endAt, $allDay];
        }

        // Single numeric date: "16.11.2026"
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

        $text = mb_strtolower($content, 'UTF-8');

        $freePatterns = ['bez maksas', 'ieeja bez maksas', 'ieeja brīva', 'brīvbiļete', 'brīvbiļetes', 'bezmaksas'];
        $hasFreeText = false;
        foreach ($freePatterns as $fp) {
            if (str_contains($text, $fp)) {
                $hasFreeText = true;
                break;
            }
        }

        if (preg_match('/(\d+(?:[.,]\d{2})?)\s*-\s*(\d+(?:[.,]\d{2})?)\s*(?:€|eur)/iu', $content, $m)) {
            $isFree = false;
            $priceMin = (float) str_replace(',', '.', $m[1]);
            $priceMax = (float) str_replace(',', '.', $m[2]);
        } elseif (preg_match('/(?:biļet[a-z]*|maksa|cena)[:\s]+(\d+(?:[.,]\d{2})?)\s*(?:€|eur)/iu', $content, $m)) {
            $isFree = false;
            $priceMin = (float) str_replace(',', '.', $m[1]);
            $priceMax = $priceMin;
        } elseif (preg_match('/(\d+(?:[.,]\d{2})?)\s*(?:€|eur)/iu', $content, $m)) {
            $val = (float) str_replace(',', '.', $m[1]);
            if ($val > 0) {
                $isFree = false;
                $priceMin = $val;
                $priceMax = $val;
            }
        }

        if ($hasFreeText && $priceMin === null) {
            $isFree = true;
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
            return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'www.jurmala.lv') . ($parsed['path'] ?? '');
        }

        $path = strtok($relUrl, '?');
        return self::BASE_URL . (str_starts_with($path, '/') ? '' : '/') . $path;
    }
}
