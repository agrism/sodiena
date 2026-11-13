<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class EkaseLvScraper extends BaseScraper
{
    public const BASE_URL = 'https://ekase.lv';
    public const PROGRAM_URL = 'https://ekase.lv/lv/events/program';
    public const DEFAULT_IMAGE = 'https://ekase.lv/img/ekase-logo.svg';

    private const MONTHS = [
        'janvāris' => 1, 'janvāra' => 1, 'janv' => 1,
        'februāris' => 2, 'februāra' => 2, 'febr' => 2,
        'marts' => 3, 'marta' => 3, 'mar' => 3,
        'aprīlis' => 4, 'aprīļa' => 4, 'apr' => 4,
        'maijs' => 5, 'maija' => 5,
        'jūnijs' => 6, 'jūnija' => 6, 'jūn' => 6,
        'jūlijs' => 7, 'jūlija' => 7, 'jūl' => 7,
        'augusts' => 8, 'augusta' => 8, 'aug' => 8,
        'septembris' => 9, 'septembra' => 9, 'sept' => 9,
        'oktobris' => 10, 'oktobra' => 10, 'okt' => 10,
        'novembris' => 11, 'novembra' => 11, 'nov' => 11,
        'decembris' => 12, 'decembra' => 12, 'dec' => 12,
    ];

    /**
     * Known venues with verified coordinates and addresses
     */
    protected array $venues = [
        'Aizkraukles Sv.Terēzes no Bērna Jēzus Romas katoļu baznīca' => [
            'venue' => 'Aizkraukles Sv. Terēzes no Bērna Jēzus Romas katoļu baznīca',
            'city' => 'Aizkraukle',
            'region' => 'Zemgale',
            'address' => 'Lāčplēša iela 22, Aizkraukle, LV-5101',
            'lat' => 56.60220,
            'lon' => 25.25360,
            'place_type' => 'church',
        ],
        'GORS, Latgales vēstniecība' => [
            'venue' => 'Latgales vēstniecība GORS',
            'city' => 'Rēzekne',
            'region' => 'Latgale',
            'address' => 'Pils iela 4, Rēzekne, LV-4601',
            'lat' => 56.50285,
            'lon' => 27.33230,
            'place_type' => 'concert_hall',
        ],
        'Latgales vēstniecība GORS' => [
            'venue' => 'Latgales vēstniecība GORS',
            'city' => 'Rēzekne',
            'region' => 'Latgale',
            'address' => 'Pils iela 4, Rēzekne, LV-4601',
            'lat' => 56.50285,
            'lon' => 27.33230,
            'place_type' => 'concert_hall',
        ],
        'Iecavas evanģēliskā baznīca' => [
            'venue' => 'Iecavas evaņģēliski luteriskā baznīca',
            'city' => 'Iecava',
            'region' => 'Zemgale',
            'address' => 'Rīgas iela 6, Iecava, Bauskas novads, LV-3913',
            'lat' => 56.59860,
            'lon' => 24.19830,
            'place_type' => 'church',
        ],
        'Koncertzāle CĒSIS' => [
            'venue' => 'Koncertzāle "Cēsis"',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Raunas iela 12, Cēsis, LV-4101',
            'lat' => 57.31340,
            'lon' => 25.27500,
            'place_type' => 'concert_hall',
        ],
        'Liepājas koncertzāle "Lielais dzintars"' => [
            'venue' => 'Koncertzāle "Lielais dzintars"',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Radio iela 8, Liepāja, LV-3401',
            'lat' => 56.51260,
            'lon' => 21.01330,
            'place_type' => 'concert_hall',
        ],
        'Mežotnes baznīca' => [
            'venue' => 'Mežotnes baznīca',
            'city' => 'Mežotne',
            'region' => 'Zemgale',
            'address' => 'Mežotne, Rundāles pagasts, Bauskas novads, LV-3918',
            'lat' => 56.43890,
            'lon' => 24.05310,
            'place_type' => 'church',
        ],
        'Pärnu Rannastaadion' => [
            'venue' => 'Pärnu Rannastaadion',
            'city' => 'Pärnu',
            'region' => 'Pärnumaa',
            'address' => 'Ranna pst 2, Pärnu, 80010, Igaunija',
            'lat' => 58.37520,
            'lon' => 24.49840,
            'place_type' => 'stadium',
        ],
        'Rīgas evaņģēliski luteriskā Lutera baznīca' => [
            'venue' => 'Rīgas Lutera baznīca (Torņakalna baznīca)',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Torņakalna iela 3/5, Rīga, LV-1004',
            'lat' => 56.92720,
            'lon' => 24.08580,
            'place_type' => 'church',
        ],
        'Siguldas Evaņģēliski luteriskā baznīca' => [
            'venue' => 'Siguldas evaņģēliski luteriskā baznīca',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Baznīcas iela 2, Sigulda, LV-2150',
            'lat' => 57.16440,
            'lon' => 24.85190,
            'place_type' => 'church',
        ],
        'Talsu Evaņģēliski luteriskā baznīca' => [
            'venue' => 'Talsu evaņģēliski luteriskā baznīca',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'Baznīcas laukums 1, Talsi, LV-3201',
            'lat' => 57.24580,
            'lon' => 22.58860,
            'place_type' => 'church',
        ],
        'Valmieras Kultūras centrs' => [
            'venue' => 'Valmieras Kultūras centrs',
            'city' => 'Valmiera',
            'region' => 'Vidzeme',
            'address' => 'Rīgas iela 10, Valmiera, LV-4201',
            'lat' => 57.53860,
            'lon' => 25.42420,
            'place_type' => 'cultural_centre',
        ],
        'Sigulda (SIGULDA ZOO)' => [
            'venue' => 'Sigulda ZOO',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => '"Veselības avots", Krimuldas pagasts, Siguldas novads',
            'lat' => 57.18560,
            'lon' => 24.84230,
            'place_type' => 'park',
        ],
    ];

    public function __construct()
    {
        parent::__construct();
        $this->httpClient = new Client([
            'timeout' => 20,
            'connect_timeout' => 10,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'lv,en-US;q=0.7,en;q=0.3',
            ],
            'verify' => false,
        ]);
    }

    public function getSlug(): string
    {
        return 'ekase';
    }

    public function getName(): string
    {
        return 'eKase';
    }

    public function scrape(Source $source): Collection
    {
        $startUrl = $source->url ?: self::PROGRAM_URL;
        $eventUrls = $this->collectEventUrls($startUrl);

        $events = collect();
        foreach ($eventUrls as $url) {
            try {
                $dto = $this->parseEventPage($url);
                if ($dto) {
                    $events->push($dto);
                }
            } catch (\Throwable $e) {
                Log::warning("Error parsing eKase event page {$url}: " . $e->getMessage(), [
                    'scraper' => static::class,
                    'exception' => $e,
                ]);
            }
        }

        return $events;
    }

    /**
     * Collect all unique direct event URLs from program pages and tours/shows
     */
    public function collectEventUrls(string $startUrl): array
    {
        $uniqueUrls = [];
        $visitedShows = [];
        $page = 1;
        $maxPages = 20;

        while ($page <= $maxPages) {
            $pageUrl = $page === 1
                ? $startUrl
                : rtrim($startUrl, '/') . '/page/' . $page;

            $crawler = $this->fetchCrawler($pageUrl);
            if (!$crawler) {
                break;
            }

            $eventCards = $crawler->filter('.el-event');
            if ($eventCards->count() === 0) {
                break;
            }

            $eventCards->each(function (Crawler $card) use (&$uniqueUrls, &$visitedShows) {
                // Find all links in the card
                $hrefs = $card->filter('a')->each(fn (Crawler $a) => $a->attr('href'));

                foreach ($hrefs as $href) {
                    if (!$href) {
                        continue;
                    }

                    // Tour / show page grouping multiple dates
                    if (str_contains($href, '/lv/shows/view/')) {
                        $showUrl = str_starts_with($href, 'http') ? $href : self::BASE_URL . $href;
                        if (!isset($visitedShows[$showUrl])) {
                            $visitedShows[$showUrl] = true;
                            $this->extractEventsFromShow($showUrl, $uniqueUrls);
                        }
                    } elseif (str_contains($href, '/lv/events/view/')) {
                        $evUrl = str_starts_with($href, 'http') ? $href : self::BASE_URL . $href;
                        $uniqueUrls[$evUrl] = true;
                    }
                }
            });

            // Check if there is an active next page
            $nextPageNode = $crawler->filter('.pagination .next:not(.disabled)');
            if ($nextPageNode->count() === 0) {
                break;
            }

            $page++;
        }

        return array_keys($uniqueUrls);
    }

    /**
     * Follow a tour / show page and extract all individual event links
     */
    protected function extractEventsFromShow(string $showUrl, array &$uniqueUrls): void
    {
        $crawler = $this->fetchCrawler($showUrl);
        if (!$crawler) {
            return;
        }

        $crawler->filter('.event-list .event-wrapper a.secondary-button, .event-list .event a.secondary-button, .event-list a[href*="/lv/events/view/"]')
            ->each(function (Crawler $a) use (&$uniqueUrls) {
                $href = $a->attr('href');
                if ($href && str_contains($href, '/lv/events/view/')) {
                    $evUrl = str_starts_with($href, 'http') ? $href : self::BASE_URL . $href;
                    $uniqueUrls[$evUrl] = true;
                }
            });
    }

    /**
     * Parse an individual eKase event page into a ScrapedEventDTO
     */
    public function parseEventPage(string $url): ?ScrapedEventDTO
    {
        $crawler = $this->fetchCrawler($url);
        if (!$crawler) {
            return null;
        }

        // 1. External ID from URL
        $externalId = null;
        if (preg_match('/\/lv\/events\/view\/(\d+)/', $url, $m)) {
            $externalId = $m[1];
        }

        // 2. Title
        $titleNode = $crawler->filter('.event-title');
        if ($titleNode->count() === 0) {
            return null;
        }
        $title = trim($titleNode->text());
        if (empty($title)) {
            return null;
        }

        // 3. Artist
        $artistNode = $crawler->filter('.artist-name');
        $artist = $artistNode->count() ? trim($artistNode->text()) : '';

        // 4. Date and time
        $dateParts = $crawler->filter('.ea-date .ea-value span')->each(fn (Crawler $n) => trim($n->text()));
        $rawDate = trim(implode(' ', array_filter($dateParts)));

        $startAt = $this->parseDateTime($rawDate);
        if (!$startAt) {
            Log::info("Skipping undated eKase item: {$title} ({$url})");
            return null;
        }

        // AGENTS.md rule: Strictly target current and upcoming events
        if ($startAt->lt(Carbon::today('Europe/Riga'))) {
            return null;
        }

        $endAt = null;

        // 5. Venue and location
        $placeNode = $crawler->filter('.ea-place .ea-value');
        $rawPlace = $placeNode->count() ? trim($placeNode->text()) : '';
        $location = $this->resolveVenue($rawPlace);

        // 6. Description
        $descrNode = $crawler->filter('.description-wrapper .dw-toggle, .description-wrapper');
        $rawDescr = $descrNode->count() ? trim($descrNode->text()) : '';
        $description = !empty($rawDescr)
            ? $rawDescr
            : "Pasākums: {$title}.\n\nNorises vieta: {$location['venue']}, {$location['city']}.\nBiļetes pieejamas eKase.lv platformā.";
        $shortDescription = Str::limit(strip_tags($description), 160);

        // 7. Image
        $imgNode = $crawler->filter('.img-gallery-display-box, #img-gallery img');
        $rawImg = $imgNode->count() ? $imgNode->attr('src') : null;
        if ($rawImg) {
            // Strip cache query string
            $cleanImg = preg_replace('/\?.*$/', '', $rawImg);
            $imageUrl = str_starts_with($cleanImg, 'http') ? $cleanImg : self::BASE_URL . $cleanImg;
        } else {
            $imageUrl = self::DEFAULT_IMAGE;
        }

        // 8. Prices
        [$priceMin, $priceMax] = $this->parsePrices($crawler);

        // 9. Category detection adhering to AGENTS.md
        [$category, $entertainmentType] = $this->detectCategory($title, $description, $location['venue']);

        return new ScrapedEventDTO(
            title: $title,
            startAt: $startAt,
            endAt: $endAt,
            description: $description,
            shortDescription: $shortDescription,
            venueName: $location['venue'],
            city: $location['city'],
            region: $location['region'],
            address: $location['address'],
            latitude: $location['lat'],
            longitude: $location['lon'],
            placeType: $location['place_type'],
            categoryNames: [$category],
            entertainmentType: $entertainmentType,
            isFree: false,
            priceMin: $priceMin,
            priceMax: $priceMax,
            currency: 'EUR',
            ticketUrl: $url,
            imageUrl: $imageUrl,
            sourceUrl: $url,
            sourceExternalId: $externalId,
            locale: 'lv',
            rawData: [
                'artist' => $artist,
                'raw_place' => $rawPlace,
                'raw_date' => $rawDate,
                'ekase_url' => $url,
            ]
        );
    }

    /**
     * Parse Latvian date string into Carbon instance
     * e.g. "21. novembris 2026 20:00" or "14. novembris - 20. novembris 2026 19:00"
     */
    public function parseDateTime(string $rawDate): ?Carbon
    {
        $clean = trim(preg_replace('/[\s\x{00a0}]+/u', ' ', $rawDate));
        if (empty($clean)) {
            return null;
        }

        $pattern = '/(\d{1,2})\.\s*([a-zA-ZāčēģīķļņšūžĀČĒĢĪĶĻŅŠŪŽ]+)\s*(?:-\s*\d{1,2}\.\s*[a-zA-ZāčēģīķļņšūžĀČĒĢĪĶĻŅŠŪŽ]+\s*)?(\d{4})(?:\s+(\d{1,2}):(\d{2}))?/u';

        if (preg_match($pattern, $clean, $m)) {
            $day = (int) $m[1];
            $monthStr = mb_strtolower($m[2]);
            $year = (int) $m[3];
            $hour = isset($m[4]) ? (int) $m[4] : 0;
            $minute = isset($m[5]) ? (int) $m[5] : 0;

            $month = self::MONTHS[$monthStr] ?? null;
            if ($month) {
                return Carbon::create($year, $month, $day, $hour, $minute, 0, 'Europe/Riga');
            }
        }

        return null;
    }

    /**
     * Extract min and max ticket prices from event content
     */
    public function parsePrices(Crawler $crawler): array
    {
        $container = $crawler->filter('.main-event-content');
        if ($container->count() === 0) {
            $container = $crawler->filter('.main-show-content');
        }
        if ($container->count() === 0) {
            $container = $crawler;
        }

        $priceNodes = $container->filter('.area-prices .amount, .prices .price .amount, .ticksel-data .col-3 span, .ticket-selector .ticksel-data');
        $allNumbers = [];

        $priceNodes->each(function (Crawler $node) use (&$allNumbers) {
            $text = $node->text();
            if (preg_match_all('/(\d+(?:[.,]\d{1,2})?)\s*(?:€|EUR|&euro;)?/iu', $text, $matches)) {
                foreach ($matches[1] as $numStr) {
                    $num = (float) str_replace(',', '.', $numStr);
                    if ($num > 0 && $num < 5000) {
                        $allNumbers[] = $num;
                    }
                }
            }
        });

        if (empty($allNumbers)) {
            return [null, null];
        }

        return [min($allNumbers), max($allNumbers)];
    }

    /**
     * Resolve venue, address, and coordinates
     */
    public function resolveVenue(string $rawVenue): array
    {
        $cleanVenue = trim(preg_replace('/\s+/', ' ', $rawVenue));

        if (empty($cleanVenue)) {
            return [
                'venue' => 'Latvija',
                'city' => 'Rīga',
                'region' => 'Rīga un Pierīga',
                'address' => 'Rīga, Latvija',
                'lat' => 56.9496,
                'lon' => 24.1052,
                'place_type' => 'cultural_centre',
            ];
        }

        // Exact match in known venues
        if (isset($this->venues[$cleanVenue])) {
            return $this->venues[$cleanVenue];
        }

        // Fuzzy match
        $normalizedInput = str_replace(['"', '“', '”', '«', '»'], '', mb_strtolower($cleanVenue));
        foreach ($this->venues as $name => $data) {
            $normalizedName = str_replace(['"', '“', '”', '«', '»'], '', mb_strtolower($name));
            if (str_contains($normalizedInput, $normalizedName) || str_contains($normalizedName, $normalizedInput)) {
                return $data;
            }
        }

        // Fallback by parsing city from venue string
        $city = 'Rīga';
        $cities = [
            'Rīga' => 'Rīga un Pierīga', 'Jūrmala' => 'Rīga un Pierīga', 'Sigulda' => 'Vidzeme',
            'Cēsis' => 'Vidzeme', 'Valmiera' => 'Vidzeme', 'Smiltene' => 'Vidzeme',
            'Liepāja' => 'Kurzeme', 'Ventspils' => 'Kurzeme', 'Kuldīga' => 'Kurzeme', 'Talsi' => 'Kurzeme',
            'Rēzekne' => 'Latgale', 'Daugavpils' => 'Latgale', 'Preiļi' => 'Latgale',
            'Jelgava' => 'Zemgale', 'Bauska' => 'Zemgale', 'Iecava' => 'Zemgale', 'Aizkraukle' => 'Zemgale',
            'Pärnu' => 'Pärnumaa', 'Tallinn' => 'Harjumaa', 'Tartu' => 'Tartumaa',
        ];

        foreach ($cities as $cName => $rName) {
            if (stripos($cleanVenue, $cName) !== false) {
                $city = $cName;
                break;
            }
        }

        $region = $cities[$city] ?? 'Rīga un Pierīga';

        return [
            'venue' => $cleanVenue,
            'city' => $city,
            'region' => $region,
            'address' => "{$cleanVenue}, {$city}",
            'lat' => null,
            'lon' => null,
            'place_type' => 'cultural_centre',
        ];
    }

    /**
     * Strict Category Detection adhering to AGENTS.md rules
     */
    public function detectCategory(string $title, string $description, string $venue): array
    {
        $titleLower = mb_strtolower($title, 'UTF-8');
        $descLower = mb_strtolower($description, 'UTF-8');
        $venueLower = mb_strtolower($venue, 'UTF-8');
        $fullText = $titleLower . ' ' . $descLower . ' ' . $venueLower;

        // 1. Strict Cinema check (AGENTS.md)
        $cinemaVenues = ['k. suns', 'k.suns', 'k suns', 'kino rio', 'forum cinemas', 'apollo kino', 'cinamon', 'splendid palace', 'kino bize', 'kino lora', 'kino gaisma'];
        foreach ($cinemaVenues as $cv) {
            if (str_contains($venueLower, $cv) || str_contains($titleLower, $cv)) {
                return ['Kino', 'cinema'];
            }
        }

        $isCinemaContent = preg_match('/\b(kinoseanss|filmas seanss|spēlfilma|dokumentālā filma|animācijas filma|kinofestivāls)\b/ui', $titleLower)
            || preg_match('/\b(filma|filmas|filmā)\b/ui', $titleLower);

        $isFilmMuxicOrConcert = preg_match('/\b(koncerts|klavierkoncerts|mūzika|orķestris|kvartets|kvintets|dziesma|dzied|tour|tūre|šovs|show|filmu mūzik|kinomūzik|skaņu celiņ)\b/ui', $titleLower)
            || preg_match('/\b(klavierkoncerts|klaviermūzikas vakars|orķestr|koncerts|dzīvajā koncertā)\b/ui', mb_substr($descLower, 0, 300));

        if ($isCinemaContent && !$isFilmMuxicOrConcert) {
            return ['Kino', 'cinema'];
        }

        // 2. Kids / Family check
        if (preg_match('/\b(bērn|bērniem|ģimenēm|kids|children|pasaka)\b/u', $titleLower)) {
            return ['Bērniem', 'family'];
        }

        // 3. Theater check (Strict: only actual theater productions, never kinoteātris)
        if (preg_match('/\b(izrāde|luga|iestudējums|opera|balets|teātris|teātrī)\b/u', $titleLower) && !str_contains($fullText, 'kinoteātr')) {
            return ['Teātris', 'theater'];
        }

        // 4. Music / Concert check
        if (
            preg_match('/\b(koncert|kor[iaous]|dziesm|dzied|mūzik|grup[asue]|ansambl|orķestr|festivāl|live|tour|tūre|album|skaņdarb|akustisk|brainstorm|prāta vētra)\b/u', $titleLower)
            || preg_match('/\b(koncert|kor[iaous]|koncerttūr|mūzik|dziesm|orķestr|grup[asue]|tūr[eē]|album[asui])/u', $fullText)
        ) {
            return ['Mūzika', 'concert'];
        }

        // 5. Stand-up / Comedy
        if (preg_match('/\b(stand-up|standup|komēdija|humor|joki|improvizācij)\b/u', $titleLower)) {
            return ['Kultūra', 'standup'];
        }

        // 6. Exhibitions / Art
        if (preg_match('/\b(izstāde|māksla|glezn|ekspozīcij|muzejs)\b/u', $titleLower)) {
            return ['Izstādes', 'exhibition'];
        }

        // 7. Sports
        if (preg_match('/\b(sports|skrējiens|maratons|čempionāts|kauss|mačs|spēle|turnīrs)\b/u', $titleLower)) {
            return ['Sports', 'sports'];
        }

        // 8. Fallback to "Cits" (AGENTS.md rule 3)
        return ['Cits', 'other'];
    }
}
