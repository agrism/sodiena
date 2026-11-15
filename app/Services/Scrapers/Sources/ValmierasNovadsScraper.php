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

class ValmierasNovadsScraper extends BaseScraper
{
    /**
     * Map of known Valmieras novads venues with coordinates, normalized names and place types.
     */
    protected array $venueMap = [
        'valmieras kultūras centr' => [
            'name' => 'Valmieras Kultūras centrs',
            'city' => 'Valmiera',
            'region' => 'Vidzeme',
            'address' => 'Rīgas iela 10, Valmiera, LV-4201',
            'place_type' => 'culture_centre',
            'lat' => 57.5385,
            'lng' => 25.4260,
        ],
        'akustik' => [
            'name' => 'Kultūrtelpa "Akustika"',
            'city' => 'Valmiera',
            'region' => 'Vidzeme',
            'address' => 'Diakonāta iela 6, Valmiera, LV-4201',
            'place_type' => 'culture_centre',
            'lat' => 57.5398,
            'lng' => 25.4290,
        ],
        'teātra akustika' => [
            'name' => 'Kafejnīca "Teātra Akustika"',
            'city' => 'Valmiera',
            'region' => 'Vidzeme',
            'address' => 'Lāčplēša iela 4, Valmiera, LV-4201',
            'place_type' => 'restaurant',
            'lat' => 57.5401,
            'lng' => 25.4277,
        ],
        '3d cinema' => [
            'name' => '3D Cinema Valmierā',
            'city' => 'Valmiera',
            'region' => 'Vidzeme',
            'address' => 'Rīgas iela 19, Valmiera, LV-4201',
            'place_type' => 'cinema',
            'lat' => 57.5380,
            'lng' => 25.4240,
        ],
        'kino gaisma' => [
            'name' => 'Kino Gaisma',
            'city' => 'Valmiera',
            'region' => 'Vidzeme',
            'address' => 'Rīgas iela 19, Valmiera, LV-4201',
            'place_type' => 'cinema',
            'lat' => 57.5380,
            'lng' => 25.4240,
        ],
        'valmieras drāmas teātr' => [
            'name' => 'Valmieras Drāmas teātris',
            'city' => 'Valmiera',
            'region' => 'Vidzeme',
            'address' => 'Lāčplēša iela 4, Valmiera, LV-4201',
            'place_type' => 'theater',
            'lat' => 57.5401,
            'lng' => 25.4277,
        ],
        'valmieras teātr' => [
            'name' => 'Valmieras teātris',
            'city' => 'Valmiera',
            'region' => 'Vidzeme',
            'address' => 'Lāčplēša iela 4, Valmiera, LV-4201',
            'place_type' => 'theater',
            'lat' => 57.5401,
            'lng' => 25.4277,
        ],
        'valmieras muzej' => [
            'name' => 'Valmieras muzejs',
            'city' => 'Valmiera',
            'region' => 'Vidzeme',
            'address' => 'Bruņinieku iela 1, Valmiera, LV-4201',
            'place_type' => 'museum',
            'lat' => 57.5393,
            'lng' => 25.4285,
        ],
        'valmieras olimpisk' => [
            'name' => 'Valmieras Olimpiskais centrs',
            'city' => 'Valmiera',
            'region' => 'Vidzeme',
            'address' => 'Čempionu iela 2, Valmiera, LV-4201',
            'place_type' => 'sports_complex',
            'lat' => 57.5305,
            'lng' => 25.4410,
        ],
        'ledus hall' => [
            'name' => 'Valmieras Olimpiskā centra ledus halle',
            'city' => 'Valmiera',
            'region' => 'Vidzeme',
            'address' => 'Čempionu iela 4, Valmiera, LV-4201',
            'place_type' => 'sports_complex',
            'lat' => 57.5305,
            'lng' => 25.4410,
        ],
        'daliņa stadion' => [
            'name' => 'Jāņa Daliņa stadions',
            'city' => 'Valmiera',
            'region' => 'Vidzeme',
            'address' => 'Jāņa Daliņa iela 2, Valmiera, LV-4201',
            'place_type' => 'stadium',
            'lat' => 57.5420,
            'lng' => 25.4380,
        ],
        'valmieras integrētajā bibliotēk' => [
            'name' => 'Valmieras Integrētā bibliotēka',
            'city' => 'Valmiera',
            'region' => 'Vidzeme',
            'address' => 'Cēsu iela 4, Valmiera, LV-4201',
            'place_type' => 'library',
            'lat' => 57.5375,
            'lng' => 25.4255,
        ],
        'valmieras tirg' => [
            'name' => 'Valmieras tirgus',
            'city' => 'Valmiera',
            'region' => 'Vidzeme',
            'address' => 'Tērbatas iela 7, Valmiera, LV-4201',
            'place_type' => 'market',
            'lat' => 57.5410,
            'lng' => 25.4210,
        ],
        'koncertzālē “valmiera”' => [
            'name' => 'Koncertzāle "Valmiera"',
            'city' => 'Valmiera',
            'region' => 'Vidzeme',
            'address' => 'Leona Paegles iela 10, Valmiera, LV-4201',
            'place_type' => 'concert_hall',
            'lat' => 57.5415,
            'lng' => 25.4265,
        ],
        'koncertzālē "valmiera"' => [
            'name' => 'Koncertzāle "Valmiera"',
            'city' => 'Valmiera',
            'region' => 'Vidzeme',
            'address' => 'Leona Paegles iela 10, Valmiera, LV-4201',
            'place_type' => 'concert_hall',
            'lat' => 57.5415,
            'lng' => 25.4265,
        ],
        'valmiermuiž' => [
            'name' => 'Valmiermuižas pils parks un alus darītava',
            'city' => 'Valmiermuiža',
            'region' => 'Vidzeme',
            'address' => 'Dzirnavu iela 2, Valmiermuiža, LV-4247',
            'place_type' => 'restaurant',
            'lat' => 57.5560,
            'lng' => 25.4280,
        ],
        'kvarc' => [
            'name' => 'Kultūrtelpa KVARC',
            'city' => 'Valmiermuiža',
            'region' => 'Vidzeme',
            'address' => 'Dzirnavu iela 2, Valmiermuiža, LV-4247',
            'place_type' => 'culture_centre',
            'lat' => 57.5560,
            'lng' => 25.4280,
        ],
        'mazsalacas kultūras centr' => [
            'name' => 'Mazsalacas Kultūras centrs',
            'city' => 'Mazsalaca',
            'region' => 'Vidzeme',
            'address' => 'Rūjienas iela 1, Mazsalaca, LV-4215',
            'place_type' => 'culture_centre',
            'lat' => 57.8638,
            'lng' => 25.0535,
        ],
        'rūjienas kultūras centr' => [
            'name' => 'Rūjienas Kultūras centrs',
            'city' => 'Rūjiena',
            'region' => 'Vidzeme',
            'address' => 'Upes iela 9, Rūjiena, LV-4240',
            'place_type' => 'culture_centre',
            'lat' => 57.8965,
            'lng' => 25.3280,
        ],
        'strenču kultūras centr' => [
            'name' => 'Strenču Kultūras centrs',
            'city' => 'Strenči',
            'region' => 'Vidzeme',
            'address' => 'Pulkveža Zemitāna iela 5, Strenči, LV-4730',
            'place_type' => 'culture_centre',
            'lat' => 57.6255,
            'lng' => 25.6880,
        ],
        'sedas kultūras nam' => [
            'name' => 'Sedas Kultūras nams',
            'city' => 'Seda',
            'region' => 'Vidzeme',
            'address' => 'Parka iela 17, Seda, LV-4728',
            'place_type' => 'culture_centre',
            'lat' => 57.6510,
            'lng' => 25.7520,
        ],
        'kocēnu kultūras nam' => [
            'name' => 'Kocēnu Kultūras nams',
            'city' => 'Kocēni',
            'region' => 'Vidzeme',
            'address' => 'Alejas iela 8, Kocēni, LV-4220',
            'place_type' => 'culture_centre',
            'lat' => 57.5200,
            'lng' => 25.3370,
        ],
        'burtnieku kultūras centr' => [
            'name' => 'Burtnieku Kultūras centrs',
            'city' => 'Burtnieki',
            'region' => 'Vidzeme',
            'address' => 'J. Vintēna iela 7, Burtnieki, LV-4206',
            'place_type' => 'culture_centre',
            'lat' => 57.6970,
            'lng' => 25.2750,
        ],
        'dikļu kultūras centr' => [
            'name' => 'Dikļu Kultūras centrs',
            'city' => 'Dikļi',
            'region' => 'Vidzeme',
            'address' => 'Dikļi, Dikļu pagasts, LV-4223',
            'place_type' => 'culture_centre',
            'lat' => 57.5960,
            'lng' => 25.0930,
        ],
        'naukšēnu pagasta kultūras nam' => [
            'name' => 'Naukšēnu pagasta kultūras nams',
            'city' => 'Naukšēni',
            'region' => 'Vidzeme',
            'address' => '"Pagastmāja", Naukšēni, LV-4244',
            'place_type' => 'culture_centre',
            'lat' => 57.8830,
            'lng' => 25.4560,
        ],
        'naukšēnu muiž' => [
            'name' => 'Naukšēnu muiža',
            'city' => 'Naukšēni',
            'region' => 'Vidzeme',
            'address' => 'Naukšēni, Naukšēnu pagasts, LV-4244',
            'place_type' => 'manor',
            'lat' => 57.8830,
            'lng' => 25.4560,
        ],
        'kauguru kultūras nam' => [
            'name' => 'Kauguru Kultūras nams',
            'city' => 'Kauguri',
            'region' => 'Vidzeme',
            'address' => '"Mūsmājas", Kauguru pagasts, LV-4224',
            'place_type' => 'culture_centre',
            'lat' => 57.5140,
            'lng' => 25.4780,
        ],
        'trikātas kultūras' => [
            'name' => 'Trikātas Kultūras centrs',
            'city' => 'Trikāta',
            'region' => 'Vidzeme',
            'address' => '"Trikāta", Trikātas pagasts, LV-4725',
            'place_type' => 'culture_centre',
            'lat' => 57.5420,
            'lng' => 25.7060,
        ],
        'bērzaines tautas nam' => [
            'name' => 'Bērzaines Tautas nams',
            'city' => 'Bērzaine',
            'region' => 'Vidzeme',
            'address' => 'Bērzaine, Bērzaines pagasts, LV-4227',
            'place_type' => 'culture_centre',
            'lat' => 57.6040,
            'lng' => 25.2440,
        ],
        'matīšu tautas nam' => [
            'name' => 'Matīšu Tautas nams',
            'city' => 'Matīši',
            'region' => 'Vidzeme',
            'address' => 'Matīši, Matīšu pagasts, LV-4210',
            'place_type' => 'culture_centre',
            'lat' => 57.7010,
            'lng' => 25.1580,
        ],
        'ēveles tautas nam' => [
            'name' => 'Ēveles Tautas nams',
            'city' => 'Ēvele',
            'region' => 'Vidzeme',
            'address' => 'Ēvele, Ēveles pagasts, LV-4713',
            'place_type' => 'culture_centre',
            'lat' => 57.6950,
            'lng' => 25.5680,
        ],
        'lodes kultūras nam' => [
            'name' => 'Lodes Kultūras nams',
            'city' => 'Lode',
            'region' => 'Vidzeme',
            'address' => 'Lode, Lodes pagasts, LV-4248',
            'place_type' => 'culture_centre',
            'lat' => 57.9250,
            'lng' => 25.5030,
        ],
        'sēļu muiž' => [
            'name' => 'Sēļu muiža',
            'city' => 'Sēļi',
            'region' => 'Vidzeme',
            'address' => 'Sēļi, Sēļu pagasts, LV-4213',
            'place_type' => 'manor',
            'lat' => 57.8890,
            'lng' => 25.1850,
        ],
        'mujānu biedrības nam' => [
            'name' => 'Mujānu biedrības nams',
            'city' => 'Mujāni',
            'region' => 'Vidzeme',
            'address' => 'Mujāni, Kocēnu pagasts, LV-4220',
            'place_type' => 'culture_centre',
            'lat' => 57.5250,
            'lng' => 25.2670,
        ],
        'vaidavas kultūras' => [
            'name' => 'Vaidavas Kultūras un amatniecības centrs',
            'city' => 'Vaidava',
            'region' => 'Vidzeme',
            'address' => 'Skolas iela 1A, Vaidava, LV-4228',
            'place_type' => 'culture_centre',
            'lat' => 57.4330,
            'lng' => 25.2780,
        ],
        'kocēnu sporta nam' => [
            'name' => 'Kocēnu Sporta nams',
            'city' => 'Kocēni',
            'region' => 'Vidzeme',
            'address' => 'Alejas iela 4, Kocēni, LV-4220',
            'place_type' => 'sports_facility',
            'lat' => 57.5210,
            'lng' => 25.3380,
        ],
        'rubenes sporta nam' => [
            'name' => 'Rubenes Sporta nams',
            'city' => 'Rubene',
            'region' => 'Vidzeme',
            'address' => 'Rubene, Kocēnu pagasts, LV-4226',
            'place_type' => 'sports_facility',
            'lat' => 57.4660,
            'lng' => 25.2580,
        ],
        'brenguļu sporta centr' => [
            'name' => 'Brenguļu sporta centrs "Kaimiņi"',
            'city' => 'Brenguļi',
            'region' => 'Vidzeme',
            'address' => 'Brenguļi, Brenguļu pagasts, LV-4245',
            'place_type' => 'sports_facility',
            'lat' => 57.5450,
            'lng' => 25.5580,
        ],
    ];

    public function getSlug(): string
    {
        return 'valmieras-novads';
    }

    public function getName(): string
    {
        return 'Valmieras novada pašvaldība';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $today = Carbon::today('Europe/Riga');
        $todayStr = $today->format('Y-m-d');

        // 1. Fetch poster gallery map from the calendar HTML page
        $calendarUrl = $source->url ?: 'https://www.valmierasnovads.lv/aktualitates/norisu-kalendars/';
        $posterMap = $this->fetchPosterGalleryMap($calendarUrl);

        // 2. Fetch all events JSON from the dedicated REST endpoint
        $jsonUrl = 'https://www.valmierasnovads.lv/wp-json/valmiera/events';
        $items = $this->fetchJson($jsonUrl);

        if (!is_array($items)) {
            Log::warning("ValmierasNovadsScraper: Failed to fetch JSON from {$jsonUrl}");
            return $events;
        }

        $seenExternalIds = [];

        foreach ($items as $item) {
            $summary = trim($item['summary'] ?? '');
            if (empty($summary)) {
                continue;
            }

            $startDateStr = trim($item['startDate'] ?? '');
            $endDateStr = trim($item['endDate'] ?? '') ?: $startDateStr;
            $timeFrom = trim($item['timeFrom'] ?? '');
            $placeRaw = trim($item['place'] ?? '');
            $contentHtml = $item['content'] ?? '';
            $link = trim($item['link'] ?? '');

            if (empty($startDateStr)) {
                continue;
            }

            // Strict AGENTS.md rule: Do NOT modify or ingest past events
            if ($endDateStr < $todayStr && $startDateStr < $todayStr) {
                continue;
            }

            // Dates & Times parsing
            [$startAt, $endAt] = $this->parseDates($startDateStr, $endDateStr, $timeFrom, $contentHtml);
            if (!$startAt) {
                continue;
            }

            // AGENTS.md rule check on Carbon instances
            if ($endAt && $endAt->lt($today)) {
                continue;
            }
            if (!$endAt && $startAt->lt($today)) {
                continue;
            }

            // Description cleaning
            $description = $this->cleanText($contentHtml) ?: $summary;
            $shortDescription = Str::limit(strip_tags($description), 160);

            // Clean event URL & External ID
            $slug = !empty($link) ? basename(rtrim(parse_url($link, PHP_URL_PATH), '/')) : Str::slug($summary);
            $externalId = 'valmiera-' . $slug . '-' . $startDateStr;
            $sourceUrl = !empty($link) ? $link : $calendarUrl;

            if (isset($seenExternalIds[$externalId])) {
                continue;
            }

            // Location Meta Resolution
            $locationMeta = $this->resolveLocationMeta($placeRaw, $summary, $description);

            // Image URL resolution
            $imageUrl = $this->resolveImageUrl($contentHtml, $summary, $posterMap);

            // Price & Free Admission detection
            $priceData = $this->parsePrice($description);
            $isFree = $priceData['is_free'];
            $priceMin = $priceData['price_min'];
            $priceMax = $priceData['price_max'];

            // Ticket URL detection
            $ticketUrl = $this->extractTicketUrl($contentHtml) ?: $sourceUrl;

            // Categories & Entertainment Type resolution (Strict AGENTS.md rules)
            $categories = $this->resolveCategories($summary, $description, $locationMeta['name']);
            $entertainmentType = $this->resolveEntertainmentType($categories, $summary, $description);

            $dto = new ScrapedEventDTO(
                title: $summary,
                startAt: $startAt,
                endAt: $endAt,
                description: $description,
                shortDescription: $shortDescription,
                venueName: $locationMeta['name'],
                city: $locationMeta['city'],
                region: $locationMeta['region'],
                address: $locationMeta['address'],
                latitude: $locationMeta['lat'],
                longitude: $locationMeta['lng'],
                placeType: $locationMeta['place_type'],
                categoryNames: $categories,
                entertainmentType: $entertainmentType,
                isFree: $isFree,
                priceMin: $priceMin,
                priceMax: $priceMax,
                currency: 'EUR',
                ticketUrl: $ticketUrl,
                imageUrl: $imageUrl,
                sourceUrl: $sourceUrl,
                sourceExternalId: $externalId,
                locale: 'lv',
                rawData: [
                    'start_date' => $startDateStr,
                    'end_date' => $endDateStr,
                    'time_from' => $timeFrom,
                    'place_raw' => $placeRaw,
                ]
            );

            $seenExternalIds[$externalId] = true;
            $events->push($dto);
        }

        Log::info("ValmierasNovadsScraper: Scraped {$events->count()} events from Valmieras novads.");

        return $events;
    }

    /**
     * Fetch poster gallery map from the calendar page to enrich events with posters.
     */
    protected function fetchPosterGalleryMap(string $calendarUrl): array
    {
        $crawler = $this->fetchCrawler($calendarUrl);
        if (!$crawler) {
            return [];
        }

        $posterMap = [];

        $crawler->filter('.posters-list-img img')->each(function (Crawler $img) use (&$posterMap) {
            $src = $img->attr('data-lazy') ?: $img->attr('src');
            $alt = trim($img->attr('alt') ?? '');
            if ($src && $alt) {
                $cleanAlt = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $alt), 'UTF-8');
                $posterMap[$cleanAlt] = $src;
            }
        });

        $crawler->filter('.news-container')->each(function (Crawler $nc) use (&$posterMap) {
            $h2 = $nc->filter('h2')->count() ? trim($nc->filter('h2')->text()) : '';
            $img = $nc->filter('img.event-image')->count() ? ($nc->filter('img.event-image')->attr('data-src') ?: $nc->filter('img.event-image')->attr('src')) : null;
            if ($h2 && $img && !str_contains($img, 'base64')) {
                $cleanH2 = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $h2), 'UTF-8');
                $posterMap[$cleanH2] = $img;
            }
        });

        return $posterMap;
    }

    /**
     * Parse date and time from JSON fields and content.
     */
    public function parseDates(string $startDateStr, string $endDateStr, string $timeFrom, string $contentHtml = ''): array
    {
        $startDateStr = trim($startDateStr);
        $endDateStr = trim($endDateStr) ?: $startDateStr;
        $timeFrom = trim($timeFrom);

        if (empty($startDateStr)) {
            return [null, null];
        }

        // Multi-day event
        if ($endDateStr !== $startDateStr) {
            $startTime = !empty($timeFrom) ? $timeFrom . ':00' : '00:00:00';
            $startAt = Carbon::parse("{$startDateStr} {$startTime}");
            $endAt = Carbon::parse("{$endDateStr} 23:59:59");
            return [$startAt, $endAt];
        }

        // Single-day event
        $startTime = !empty($timeFrom) ? $timeFrom : '00:00';
        $startAt = Carbon::parse("{$startDateStr} {$startTime}:00");
        $endAt = null;

        // Try to find end time from content (e.g. "plkst. 11.00–15.00" or "18.30-19.15")
        if (preg_match('/plkst\.?\s*\d{1,2}[:.]\d{2}\s*[-–]\s*(\d{1,2}[:.]\d{2})/ui', $contentHtml, $tm)) {
            $eTime = str_replace('.', ':', $tm[1]);
            $endAt = Carbon::parse("{$startDateStr} {$eTime}:00");
        }

        return [$startAt, $endAt];
    }

    /**
     * Resolve location metadata, matching known venues or formatting parsed place string.
     */
    public function resolveLocationMeta(?string $placeRaw, string $title = '', string $description = ''): array
    {
        $placeRaw = trim($placeRaw ?? '');
        $combined = mb_strtolower($placeRaw . ' ' . $title . ' ' . $description, 'UTF-8');

        foreach ($this->venueMap as $stem => $meta) {
            if (str_contains($combined, $stem)) {
                return $meta;
            }
        }

        // Resolve city from place text
        $city = 'Valmiera';
        $knownCities = [
            'mazsalac' => 'Mazsalaca',
            'rūjien' => 'Rūjiena',
            'strenč' => 'Strenči',
            'sedā' => 'Seda',
            'seda' => 'Seda',
            'kocēn' => 'Kocēni',
            'burtniek' => 'Burtnieki',
            'valmiermuiž' => 'Valmiermuiža',
            'dikļ' => 'Dikļi',
            'naukšēn' => 'Naukšēni',
            'bērzain' => 'Bērzaine',
            'trikāt' => 'Trikāta',
            'brenguļ' => 'Brenguļi',
            'vaidav' => 'Vaidava',
            'rencēn' => 'Rencēni',
            'matīš' => 'Matīši',
            'ēvel' => 'Ēvele',
            'sēļ' => 'Sēļi',
            'mujān' => 'Mujāni',
            'vecat' => 'Vecate',
            'lodē' => 'Lode',
            'lode' => 'Lode',
        ];

        foreach ($knownCities as $stem => $cName) {
            if (str_contains(mb_strtolower($placeRaw, 'UTF-8'), $stem)) {
                $city = $cName;
                break;
            }
        }

        $name = !empty($placeRaw) ? $placeRaw : 'Valmiera';
        $address = !empty($placeRaw) ? $placeRaw . ', ' . $city : $city;

        return [
            'name' => $name,
            'city' => $city,
            'region' => 'Vidzeme',
            'address' => $address,
            'place_type' => $this->inferPlaceType($name, $title),
            'lat' => 57.5385,
            'lng' => 25.4260,
        ];
    }

    /**
     * Infer place type from venue name and title.
     */
    protected function inferPlaceType(string $venue, string $title): string
    {
        $lower = mb_strtolower($venue . ' ' . $title, 'UTF-8');

        if (str_contains($lower, 'cinema') || str_contains($lower, 'kino')) {
            return 'cinema';
        }
        if (str_contains($lower, 'teātr')) {
            return 'theater';
        }
        if (str_contains($lower, 'koncertzāl')) {
            return 'concert_hall';
        }
        if (str_contains($lower, 'muzej') || str_contains($lower, 'muiž')) {
            return 'museum';
        }
        if (str_contains($lower, 'kultūras centrs') || str_contains($lower, 'kultūras nams') || str_contains($lower, 'tautas nams') || str_contains($lower, 'kultūrtelp')) {
            return 'culture_centre';
        }
        if (str_contains($lower, 'bibliotēk')) {
            return 'library';
        }
        if (str_contains($lower, 'stadion') || str_contains($lower, 'sport') || str_contains($lower, 'halle') || str_contains($lower, 'basein')) {
            return 'sports_complex';
        }
        if (str_contains($lower, 'parks') || str_contains($lower, 'dārz') || str_contains($lower, 'skvērs') || str_contains($lower, 'laukums')) {
            return 'park';
        }
        if (str_contains($lower, 'tirg')) {
            return 'market';
        }

        return 'venue';
    }

    /**
     * Resolve categories adhering strictly to AGENTS.md rules.
     */
    public function resolveCategories(string $title, string $description, string $venue): array
    {
        $t = mb_strtolower($title, 'UTF-8');
        $d = mb_strtolower($description, 'UTF-8');
        $v = mb_strtolower($venue, 'UTF-8');
        $comb = $t . ' ' . $v;

        // Strip pricing boilerplate mentioning kids to avoid false positives on kids category
        $dStripped = preg_replace('/bērniem\s+(?:līdz|no)\s+\d+[^\n.]*/ui', '', $d);

        // STRICT AGENTS.MD RULE: Cinema / Kino (Priority check)
        $kinoKeywords = [
            'filma', 'filmas', 'filmu', 'filmā', 'kinoseanss', 'filmas seanss',
            'spēlfilma', 'dokumentālā filma', 'animācijas filma', 'kinofestivāls',
            'kinoteātr', '3d cinema', 'kino gaisma', 'kino zālē',
        ];
        foreach ($kinoKeywords as $kw) {
            if (str_contains($comb, $kw) || str_contains($d, $kw)) {
                return ['Kino'];
            }
        }

        // STRICT AGENTS.MD RULE: Theaters
        $isTheaterVenue = str_contains($comb, 'valmieras teātr') || str_contains($comb, 'valmieras drāmas teātr') || str_contains($comb, 'teātra akustika');
        $isTheaterProduction = str_contains($comb, 'izrāde') || str_contains($comb, 'izrādes')
            || str_contains($comb, 'iestudējums') || str_contains($comb, 'luga')
            || str_contains($comb, 'balets') || str_contains($comb, 'stand-up') || str_contains($comb, 'komēdija')
            || str_contains($d, 'teātra izrāde') || str_contains($d, 'lugas iestudējums');

        if ($isTheaterVenue || $isTheaterProduction) {
            if (str_contains($t, 'koncerts') && !str_contains($t, 'izrāde') && !str_contains($t, 'luga')) {
                return ['Mūzika', 'Teātris'];
            }
            return ['Teātris'];
        }

        // Music / Concerts
        if (
            str_contains($comb, 'koncert') || str_contains($comb, 'dziesm') || str_contains($comb, 'mūzik')
            || str_contains($comb, 'muzikāl') || str_contains($comb, 'orķestr') || str_contains($comb, 'koris')
            || str_contains($comb, 'džez') || str_contains($comb, 'kamermūzik') || str_contains($comb, 'klavier')
            || str_contains($comb, 'solists') || str_contains($comb, 'dziedātāj') || str_contains($comb, 'dzied')
            || str_contains($comb, 'čell') || str_contains($comb, 'ģitār') || str_contains($comb, 'opera')
            || str_contains($d, 'koncertprogrammā') || str_contains($d, 'koncertā') || str_contains($d, 'koncerts')
        ) {
            return ['Mūzika'];
        }

        // Exhibitions & Art
        if (
            str_contains($comb, 'izstād') || str_contains($comb, 'ekspozīcij') || str_contains($comb, 'glezn')
            || str_contains($comb, 'māksl') || str_contains($comb, 'tēlniec') || str_contains($comb, 'muzejs')
            || str_contains($comb, 'fotogrāf') || str_contains($d, 'izstādē') || str_contains($d, 'ekspozīcijā')
        ) {
            return ['Izstādes'];
        }

        // Dedicated Kids & Family events
        if (
            str_contains($t, 'bērn') || str_contains($t, 'ģimen') || str_contains($t, 'rotaļ')
            || str_contains($dStripped, 'pasākums bērniem') || str_contains($dStripped, 'bērnu pasākums')
            || str_contains($dStripped, 'ģimenēm ar bērniem')
        ) {
            return ['Bērniem'];
        }

        // Sports & Active recreation
        if (
            str_contains($comb, 'sports') || str_contains($comb, 'florbol') || str_contains($comb, 'basketbol')
            || str_contains($comb, 'futbol') || str_contains($comb, 'slidošan') || str_contains($comb, 'nūjošan')
            || str_contains($comb, 'skrējiens') || str_contains($comb, 'turnīrs') || str_contains($comb, 'čempionāts')
            || str_contains($comb, 'novus') || str_contains($comb, 'zole') || str_contains($comb, 'zolīt')
            || str_contains($d, 'sacensīb') || str_contains($d, 'sporta nodarbīb')
        ) {
            return ['Sports'];
        }

        // Workshops / Seminars
        if (
            str_contains($comb, 'meistarklase') || str_contains($comb, 'lekcij') || str_contains($comb, 'seminār')
            || str_contains($comb, 'nodarbīb') || str_contains($comb, 'darbnīca') || str_contains($comb, 'konferenc')
            || str_contains($comb, 'diskusij') || str_contains($comb, 'sarun')
            || str_contains($d, 'meistarklasē') || str_contains($d, 'lekcijā')
        ) {
            return ['Semināri'];
        }

        // Festivals, Celebrations & Gastronomy
        if (
            str_contains($comb, 'svētk') || str_contains($comb, 'festivāl') || str_contains($comb, 'egles iedegšana')
            || str_contains($comb, 'egļu iedegšana') || str_contains($comb, 'tirdziņš') || str_contains($comb, 'tirgus')
            || str_contains($comb, 'jaunā gada') || str_contains($comb, 'saulgriež') || str_contains($comb, 'advent')
            || str_contains($comb, 'balle') || str_contains($comb, 'danc') || str_contains($comb, 'diskotēk')
            || str_contains($d, 'svētku') || str_contains($d, 'festivāls')
        ) {
            return ['Svētki'];
        }

        // Fallback: Cits per AGENTS.md rules
        return ['Cits'];
    }

    /**
     * Resolve entertainment type.
     */
    public function resolveEntertainmentType(array $categories, string $title, string $description): string
    {
        $lower = mb_strtolower($title . ' ' . $description, 'UTF-8');

        if (in_array('Kino', $categories, true)) {
            return 'movie';
        }
        if (in_array('Teātris', $categories, true)) {
            return 'performance';
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
        if (in_array('Bērniem', $categories, true)) {
            return 'family';
        }
        if (in_array('Semināri', $categories, true)) {
            return 'education';
        }
        if (in_array('Svētki', $categories, true)) {
            return 'chill';
        }

        return 'other';
    }

    /**
     * Resolve image URL from content <img> or mapped posters gallery.
     */
    protected function resolveImageUrl(string $contentHtml, string $title, array $posterMap): ?string
    {
        // 1. Check for <img> in content
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $contentHtml, $m)) {
            $img = $m[1];
            if (!str_contains($img, 'base64')) {
                return $img;
            }
        }

        // 2. Check in poster gallery map
        $titleClean = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $title), 'UTF-8');
        foreach ($posterMap as $alt => $src) {
            if (str_contains($alt, $titleClean) || str_contains($titleClean, $alt)) {
                return $src;
            }
        }

        return null;
    }

    /**
     * Extract ticketing URL from content HTML.
     */
    protected function extractTicketUrl(string $contentHtml): ?string
    {
        if (preg_match('/href=["\'](https?:\/\/(?:www\.)?(?:bilesuparadize|bilesuserviss|bezrindas|fienta|aula|ekase)[^"\']+)["\']/i', $contentHtml, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Parse price and free admission from description.
     */
    public function parsePrice(string $description): array
    {
        $lower = mb_strtolower($description, 'UTF-8');

        $isFree = false;
        if (
            str_contains($lower, 'bez maksas') || str_contains($lower, 'bezmaksas')
            || str_contains($lower, 'ieeja brīva') || str_contains($lower, 'brīva ieeja')
            || str_contains($lower, 'ieeja bez maksas')
        ) {
            $isFree = true;
        }

        $priceMin = null;
        $priceMax = null;

        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*(?:EUR|€)/ui', $description, $matches)) {
            $prices = array_map(fn($p) => (float) str_replace(',', '.', $p), $matches[1]);
            if (!empty($prices)) {
                $priceMin = min($prices);
                $priceMax = max($prices);
                if ($priceMin === 0.0) {
                    $isFree = true;
                }
            }
        }

        if ($priceMin !== null && $priceMin > 0) {
            $isFree = false;
        } elseif ($isFree && $priceMin === null) {
            $priceMin = 0.0;
            $priceMax = 0.0;
        }

        return [
            'is_free' => $isFree,
            'price_min' => $priceMin,
            'price_max' => $priceMax,
        ];
    }
}
