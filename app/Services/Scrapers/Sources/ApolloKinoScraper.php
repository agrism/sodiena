<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class ApolloKinoScraper extends BaseScraper
{
    public const BASE_URL = 'https://www.apollokino.lv';
    public const EVENTS_API = 'https://www.apollokino.lv/xml/Events';
    public const SCHEDULE_API = 'https://www.apollokino.lv/xml/Schedule';

    public const CINEMA_PAGES = [
        1035 => 'https://www.apollokino.lv/cinemas/akropoleriga',
        1037 => 'https://www.apollokino.lv/cinemas/rigaplaza',
        1038 => 'https://www.apollokino.lv/cinemas/domina',
    ];

    /**
     * Verified Apollo Kino pricing matrix by TheatreID, hall type, format (2D/3D), and weekday/weekend.
     * Sources:
     * - Akropole Rīga (1035): https://www.apollokino.lv/cinemas/akropoleriga
     * - Rīga Plaza (1037):    https://www.apollokino.lv/cinemas/rigaplaza
     * - Domina (1038):        https://www.apollokino.lv/cinemas/domina
     */
    public const DEFAULT_PRICE_MATRIX = [
        // 1035: Apollo Kino Akropole Rīga
        1035 => [
            'standarta' => [
                '2D' => ['weekday' => [5.49, 8.88], 'weekend' => [6.19, 9.99]],
                '3D' => ['weekday' => [6.49, 9.88], 'weekend' => [7.19, 10.99]],
            ],
            'imax' => [
                '2D' => ['weekday' => [7.49, 10.88], 'weekend' => [8.19, 11.99]],
                '3D' => ['weekday' => [8.49, 11.88], 'weekend' => [9.19, 12.99]],
            ],
            'restorans' => [
                '2D' => ['weekday' => [8.05, 11.44], 'weekend' => [8.75, 12.55]],
                '3D' => ['weekday' => [8.05, 11.44], 'weekend' => [8.75, 12.55]],
            ],
            'zvaigznu' => [
                '2D' => ['weekday' => [7.99, 10.88], 'weekend' => [8.99, 11.99]],
                '3D' => ['weekday' => [8.89, 11.88], 'weekend' => [9.89, 12.99]],
            ],
        ],
        // 1037: Apollo Kino Rīga Plaza
        1037 => [
            'standarta' => [
                '2D' => ['weekday' => [5.49, 8.88], 'weekend' => [6.19, 9.99]],
                '3D' => ['weekday' => [6.49, 9.88], 'weekend' => [7.19, 10.99]],
            ],
            'vintage' => [
                '2D' => ['weekday' => [12.49, 15.88], 'weekend' => [13.19, 16.99]],
                '3D' => ['weekday' => [12.49, 15.88], 'weekend' => [13.19, 16.99]],
            ],
            'divans' => [
                '2D' => ['weekday' => [7.69, 11.08], 'weekend' => [8.39, 12.19]],
                '3D' => ['weekday' => [8.69, 12.08], 'weekend' => [9.39, 13.19]],
            ],
        ],
        // 1038: Apollo Kino Domina
        1038 => [
            'standarta' => [
                '2D' => ['weekday' => [4.99, 8.21], 'weekend' => [5.69, 9.32]],
                '3D' => ['weekday' => [5.99, 9.21], 'weekend' => [6.69, 10.32]],
            ],
        ],
    ];

    public const PRICE_MATRIX = self::DEFAULT_PRICE_MATRIX;

    public function __construct()
    {
        parent::__construct();
        $options = [
            'timeout' => 30,
            'connect_timeout' => 20,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                'Accept' => 'application/json',
                'Accept-Language' => 'lv,en-US;q=0.7,en;q=0.3',
            ],
            'verify' => false,
        ];

        if ($proxy = env('SCRAPER_PROXY')) {
            $options['proxy'] = $proxy;
        }

        $this->httpClient = new \GuzzleHttp\Client($options);
    }

    /**
     * Fetch JSON with explicit Accept: application/json header (with XML fallback)
     */
    protected function fetchJson(string $url): ?array
    {
        try {
            $response = $this->httpClient->get($url, [
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
            $body = (string) $response->getBody();

            if (str_starts_with(trim($body), '<?xml') || str_starts_with(trim($body), '<')) {
                $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
                if ($xml !== false) {
                    return json_decode(json_encode($xml), true);
                }
            }

            return json_decode($body, true);
        } catch (\Throwable $e) {
            Log::error("Apollo Kino JSON fetch error for URL {$url}: " . $e->getMessage(), [
                'scraper' => static::class,
            ]);
            return null;
        }
    }

    public function getSlug(): string
    {
        return 'apollo-kino';
    }

    public function getName(): string
    {
        return 'Apollo Kino';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $seenExternalIds = [];

        Log::info("Starting Apollo Kino scraping from XML API...");

        // 1. Fetch full movie metadata catalogue from /xml/Events
        $moviesCatalog = $this->fetchMoviesCatalog();
        Log::info(sprintf("Loaded %d movies from Apollo Kino catalogue", count($moviesCatalog)));

        // 2. Fetch full schedule (up to 365 days) from /xml/Schedule for all areas (1014)
        $scheduleUrl = self::SCHEDULE_API . '?area=1014&nrOfDays=365';
        $scheduleData = $this->fetchJson($scheduleUrl);

        if (empty($scheduleData) || !isset($scheduleData['Shows']) || !is_array($scheduleData['Shows'])) {
            Log::warning("No shows returned by Apollo Kino Schedule API: {$scheduleUrl}");
            return $events;
        }

        $shows = $scheduleData['Shows'];
        $todayStart = now()->startOfDay();

        Log::info(sprintf("Processing %d shows from Apollo Kino schedule...", count($shows)));

        // Load pricing matrix (auto-refreshed on 1st & 15th of the month, or cached/default fallback)
        $priceMatrix = $this->getPriceMatrix();

        foreach ($shows as $show) {
            $showId = $show['ID'] ?? null;
            if (!$showId) {
                continue;
            }

            $externalId = 'show_' . $showId;
            if (isset($seenExternalIds[$externalId])) {
                continue;
            }

            // Start & End dates
            $startAtStr = $show['dttmShowStart'] ?? null;
            if (empty($startAtStr)) {
                continue;
            }

            try {
                $startAt = Carbon::parse($startAtStr);
            } catch (\Throwable $e) {
                Log::debug("Invalid start date for Apollo show {$showId}: {$startAtStr}");
                continue;
            }

            // STRICT RULE: No past events modification (start_at < today)
            if ($startAt->lt($todayStart)) {
                continue;
            }

            $endAt = null;
            if (!empty($show['dttmShowEnd'])) {
                try {
                    $endAt = Carbon::parse($show['dttmShowEnd']);
                } catch (\Throwable $e) {
                    $endAt = null;
                }
            }

            $eventId = (int) ($show['EventID'] ?? 0);
            $movie = $moviesCatalog[$eventId] ?? [];

            // Title
            $title = trim($show['Title'] ?? $movie['title'] ?? '');
            if (empty($title)) {
                continue;
            }

            // Location & Theatre resolution
            $theatreId = (int) ($show['TheatreID'] ?? 0);
            $theatreName = trim($show['Theatre'] ?? '');
            $locationData = $this->resolveTheatre($theatreId, $theatreName);

            // Auditory / Hall & presentation format
            $auditorium = trim($show['TheatreAuditorium'] ?? '');
            $presentationMethod = trim($show['PresentationMethod'] ?? '');

            // Languages & subtitles
            $spokenLang = $show['SpokenLanguage']['Name'] ?? null;
            $subtitles = array_filter([
                $show['SubtitleLanguage1']['Name'] ?? null,
                $show['SubtitleLanguage2']['Name'] ?? null,
            ]);

            // Categories & Entertainment type strictly per AGENTS.md
            $genres = trim($show['Genres'] ?? $movie['genres'] ?? '');
            [$categoryNames, $entertainmentType] = $this->resolveCategories($title, $genres, $locationData['venue_name']);

            // Formatted descriptions
            $description = $this->formatDescription(
                $movie,
                $show,
                $title,
                $genres,
                $auditorium,
                $presentationMethod,
                $spokenLang,
                $subtitles
            );

            $shortDescription = !empty($movie['shortSynopsis'])
                ? $movie['shortSynopsis']
                : Str::limit(strip_tags($description), 160);

            // Poster Image
            $imageUrl = $this->resolveImageUrl($show, $movie);

            // Ticket URL & Source URL
            $ticketUrl = !empty($show['ShowURL'])
                ? trim($show['ShowURL'])
                : sprintf('%s/websales/show/%d', self::BASE_URL, $showId);

            $sourceUrl = !empty($movie['eventUrl'])
                ? trim($movie['eventUrl'])
                : (!empty($show['EventURL']) ? trim($show['EventURL']) : sprintf('%s/event/%d', self::BASE_URL, $eventId));

            // Calculate ticket price range based on cinema, hall, 2D/3D and showtime
            [$priceMin, $priceMax] = $this->calculateShowPrices($theatreId, $auditorium, $presentationMethod, $startAt, $priceMatrix);

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
                isFree: false,
                priceMin: $priceMin,
                priceMax: $priceMax,
                currency: 'EUR',
                ticketUrl: $ticketUrl,
                imageUrl: $imageUrl,
                sourceUrl: $sourceUrl,
                sourceExternalId: $externalId,
                locale: 'lv',
                rawData: array_filter([
                    'show_id' => $showId,
                    'event_id' => $eventId,
                    'original_title' => $movie['originalTitle'] ?? null,
                    'theatre_id' => $theatreId,
                    'theatre' => $theatreName,
                    'auditorium' => $auditorium,
                    'presentation_method' => $presentationMethod,
                    'genres' => $genres,
                    'spoken_language' => $spokenLang,
                    'subtitles' => $subtitles,
                    'rating_label' => $show['RatingLabel'] ?? $movie['ratingLabel'] ?? null,
                    'length_minutes' => $show['LengthInMinutes'] ?? $movie['lengthInMinutes'] ?? null,
                    'production_year' => $show['ProductionYear'] ?? $movie['productionYear'] ?? null,
                    'price_min' => $priceMin,
                    'price_max' => $priceMax,
                    'trailer_url' => $movie['trailerUrl'] ?? null,
                    'youtube_url' => $movie['trailerUrl'] ?? null,
                ]),
            ));
        }

        Log::info(sprintf("Apollo Kino scraping completed: %d upcoming shows prepared", $events->count()));

        return $events;
    }

    /**
     * Fetch all movies from /xml/Events and index them by EventID
     */
    public function fetchMoviesCatalog(): array
    {
        $rawMovies = $this->fetchJson(self::EVENTS_API);
        if (empty($rawMovies) || !is_array($rawMovies)) {
            Log::warning("Failed to fetch movies catalogue from: " . self::EVENTS_API);
            return [];
        }

        $catalog = [];
        foreach ($rawMovies as $m) {
            $id = (int) ($m['ID'] ?? 0);
            if (!$id) {
                continue;
            }

            // Extract director names
            $directors = [];
            if (!empty($m['Directors']) && is_array($m['Directors'])) {
                foreach ($m['Directors'] as $d) {
                    $dName = trim(($d['FirstName'] ?? '') . ' ' . ($d['LastName'] ?? ''));
                    if (!empty($dName)) {
                        $directors[] = $dName;
                    }
                }
            }

            // Extract cast actor names
            $cast = [];
            if (!empty($m['Cast']) && is_array($m['Cast'])) {
                foreach ($m['Cast'] as $c) {
                    $cName = trim(($c['FirstName'] ?? '') . ' ' . ($c['LastName'] ?? ''));
                    if (!empty($cName)) {
                        $cast[] = $cName;
                    }
                }
            }

            // Extract trailer video (YouTube)
            $trailerUrl = null;
            if (!empty($m['Videos']) && is_array($m['Videos'])) {
                foreach ($m['Videos'] as $v) {
                    if (!is_array($v)) {
                        continue;
                    }
                    $loc = trim($v['Location'] ?? '');
                    if (!empty($loc)) {
                        if (str_starts_with($loc, 'http://') || str_starts_with($loc, 'https://')) {
                            $trailerUrl = str_replace('http://', 'https://', $loc);
                        } else {
                            $trailerUrl = 'https://www.youtube.com/watch?v=' . $loc;
                        }
                        break;
                    }
                }
            }

            $catalog[$id] = [
                'title' => trim($m['Title'] ?? ''),
                'originalTitle' => trim($m['OriginalTitle'] ?? ''),
                'productionYear' => $m['ProductionYear'] ?? null,
                'lengthInMinutes' => $m['LengthInMinutes'] ?? null,
                'genres' => trim($m['Genres'] ?? ''),
                'ratingLabel' => trim($m['RatingLabel'] ?? ''),
                'shortSynopsis' => $this->cleanText($m['ShortSynopsis'] ?? ''),
                'synopsis' => $this->cleanText($m['Synopsis'] ?? ''),
                'eventUrl' => $m['EventURL'] ?? null,
                'imageUrl' => $m['Images']['EventLargeImagePortrait'] ?? $m['Images']['EventMediumImagePortrait'] ?? null,
                'directors' => $directors,
                'cast' => $cast,
                'trailerUrl' => $trailerUrl,
            ];
        }

        return $catalog;
    }

    /**
     * Resolve theatre location details with exact coordinates and verified addresses
     */
    public function resolveTheatre(int $theatreId, string $theatreName): array
    {
        $t = mb_strtolower($theatreName, 'UTF-8');

        // Apollo Kino Akropole Rīga (Maskavas iela 257)
        if ($theatreId === 1011 || $theatreId === 1035 || str_contains($t, 'akropole rīga') || str_contains($t, 'akropole riga')) {
            return [
                'venue_name' => 'Apollo Kino Akropole Rīga',
                'address' => 'Maskavas iela 257, Rīga',
                'city' => 'Rīga',
                'region' => 'Rīga un Pierīga',
                'latitude' => 56.9099,
                'longitude' => 24.1878,
                'place_type' => 'cinema',
            ];
        }

        // Apollo Kino Rīga Plaza (Mūkusalas iela 71)
        if ($theatreId === 1013 || $theatreId === 1037 || str_contains($t, 'rīga plaza') || str_contains($t, 'riga plaza') || str_contains($t, 'plaza')) {
            return [
                'venue_name' => 'Apollo Kino Rīga Plaza',
                'address' => 'Mūkusalas iela 71, Rīga',
                'city' => 'Rīga',
                'region' => 'Rīga un Pierīga',
                'latitude' => 56.9298,
                'longitude' => 24.1037,
                'place_type' => 'cinema',
            ];
        }

        // Apollo Kino Domina (Ieriķu iela 3)
        if ($theatreId === 1015 || $theatreId === 1038 || str_contains($t, 'domina')) {
            return [
                'venue_name' => 'Apollo Kino Domina',
                'address' => 'Ieriķu iela 3, Rīga',
                'city' => 'Rīga',
                'region' => 'Rīga un Pierīga',
                'latitude' => 56.9678,
                'longitude' => 24.1643,
                'place_type' => 'cinema',
            ];
        }

        // Apollo Kino Akropole Alfa (Brīvības gatve 372)
        if (str_contains($t, 'alfa')) {
            return [
                'venue_name' => 'Apollo Kino Akropole Alfa',
                'address' => 'Brīvības gatve 372, Rīga',
                'city' => 'Rīga',
                'region' => 'Rīga un Pierīga',
                'latitude' => 56.9839,
                'longitude' => 24.2046,
                'place_type' => 'cinema',
            ];
        }

        // Apollo Kino Ventspils
        if (str_contains($t, 'ventspil')) {
            return [
                'venue_name' => 'Apollo Kino Ventspils',
                'address' => 'Lielais laukums 1, Ventspils',
                'city' => 'Ventspils',
                'region' => 'Kurzeme',
                'latitude' => 57.3948,
                'longitude' => 21.5647,
                'place_type' => 'cinema',
            ];
        }

        // Fallback
        return [
            'venue_name' => !empty($theatreName) ? $theatreName : 'Apollo Kino',
            'address' => 'Rīga',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'latitude' => 56.9496,
            'longitude' => 24.1052,
            'place_type' => 'cinema',
        ];
    }

    /**
     * Resolve categories & entertainmentType strictly adhering to AGENTS.md
     * Rule: Apollo Kino venues and events are ALWAYS Kino and NEVER Teātris!
     */
    public function resolveCategories(string $title, string $genres, string $venueName): array
    {
        $canonical = ['Kino'];
        $entertainmentType = 'kino';

        $combined = mb_strtolower($title . ' ' . $genres . ' ' . $venueName, 'UTF-8');

        // Check for children / family animations
        if (str_contains($combined, 'animācij') || str_contains($combined, 'ģimenes') || str_contains($combined, 'bērniem') || str_contains($combined, 'pasak')) {
            $canonical[] = 'Bērniem';
            $entertainmentType = 'kids';
        }

        // Check for cinema concerts / music films (if title indicates concert or genre is koncertfilma)
        if (
            str_contains($combined, 'koncertfilma') ||
            str_contains(mb_strtolower($title, 'UTF-8'), 'koncert') ||
            str_contains(mb_strtolower($title, 'UTF-8'), 'concert')
        ) {
            $canonical[] = 'Mūzika';
            $entertainmentType = 'concert';
        }

        // Check for sports screenings
        if (str_contains($combined, 'sports') || str_contains($combined, 'čempionāt') || str_contains($combined, 'spēle')) {
            $canonical[] = 'Sports';
        }

        // STRICT AGENTS.md RULE: Apollo Kino events are NEVER Teātris
        $canonical = array_values(array_filter($canonical, fn($c) => $c !== 'Teātris'));

        return [array_values(array_unique($canonical)), $entertainmentType];
    }

    /**
     * Format full rich description combining synopsis and structured metadata
     */
    public function formatDescription(
        array $movie,
        array $show,
        string $title,
        string $genres,
        string $auditorium,
        string $presentationMethod,
        ?string $spokenLang,
        array $subtitles
    ): string {
        $parts = [];

        // 1. Full synopsis from movie catalogue
        $synopsis = !empty($movie['synopsis']) ? $movie['synopsis'] : ($movie['shortSynopsis'] ?? '');
        if (!empty($synopsis)) {
            $parts[] = $synopsis;
        }

        // 2. Structured metadata lines
        $meta = [];

        $origTitle = $movie['originalTitle'] ?? $show['OriginalTitle'] ?? null;
        if (!empty($origTitle) && mb_strtolower($origTitle, 'UTF-8') !== mb_strtolower($title, 'UTF-8')) {
            $meta[] = "Oriģinālnosaukums: {$origTitle}";
        }

        $prodYear = $movie['productionYear'] ?? $show['ProductionYear'] ?? null;
        if (!empty($prodYear) && is_numeric($prodYear) && (int) $prodYear > 1900) {
            $meta[] = "Izlaiduma gads: {$prodYear}";
        }

        $length = $movie['lengthInMinutes'] ?? $show['LengthInMinutes'] ?? null;
        if (!empty($length) && is_numeric($length) && (int) $length > 0) {
            $meta[] = "Filmas garums: {$length} min.";
        }

        if (!empty($genres)) {
            $meta[] = "Žanrs: {$genres}";
        }

        $rating = $movie['ratingLabel'] ?? $show['RatingLabel'] ?? null;
        if (!empty($rating)) {
            $meta[] = "Vecuma ierobežojums: {$rating}";
        }

        if (!empty($spokenLang)) {
            $meta[] = "Valoda: {$spokenLang}";
        }

        if (!empty($subtitles)) {
            $meta[] = "Subtitri: " . implode(', ', $subtitles);
        }

        if (!empty($presentationMethod)) {
            $meta[] = "Formāts: {$presentationMethod}";
        }

        if (!empty($auditorium)) {
            $meta[] = "Kinozāle: {$auditorium}";
        }

        if (!empty($movie['directors'])) {
            $meta[] = "Režisors: " . implode(', ', $movie['directors']);
        }

        if (!empty($movie['cast'])) {
            $castStr = implode(', ', array_slice($movie['cast'], 0, 8));
            $meta[] = "Galvenajās lomās: {$castStr}";
        }

        if (!empty($movie['trailerUrl'])) {
            $meta[] = "Treileris: {$movie['trailerUrl']}";
        }

        if (!empty($meta)) {
            $parts[] = implode("\n", $meta);
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * Resolve best high quality poster image URL
     */
    public function resolveImageUrl(array $show, array $movie): ?string
    {
        $candidates = [
            $show['Images']['EventLargeImagePortrait'] ?? null,
            $movie['imageUrl'] ?? null,
            $show['Images']['EventMediumImagePortrait'] ?? null,
            $show['Images']['EventSmallImagePortrait'] ?? null,
            $show['Images']['EventMicroImagePortrait'] ?? null,
        ];

        foreach ($candidates as $img) {
            if (!empty($img) && is_string($img) && filter_var($img, FILTER_VALIDATE_URL)) {
                return $img;
            }
        }

        return null;
    }

    /**
     * Get active pricing matrix.
     * Automatically refreshes from web on the 1st and 15th of each month (cached for each period).
     * If web refresh fails, logs error and cleanly falls back to DEFAULT_PRICE_MATRIX without halting execution.
     *
     * @param bool $forceRefresh If true, ignores cache and testing guard to force fresh web fetch
     * @return array
     */
    public function getPriceMatrix(bool $forceRefresh = false): array
    {
        $periodKey = now()->format('Y_m') . '_' . (now()->day >= 15 ? '15' : '01');
        $cacheKey = "apollo_kino_price_matrix_{$periodKey}";

        if (!$forceRefresh && Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && !empty($cached)) {
                return $cached;
            }
        }

        // Avoid unmocked external HTTP calls during automated testing unless explicitly forced
        if (!$forceRefresh && app()->environment('testing')) {
            return self::DEFAULT_PRICE_MATRIX;
        }

        try {
            $webMatrix = $this->fetchPriceMatrixFromWeb();
            if (!empty($webMatrix)) {
                $mergedMatrix = array_replace_recursive(self::DEFAULT_PRICE_MATRIX, $webMatrix);
                Cache::put($cacheKey, $mergedMatrix, now()->addDays(20));
                Log::info("Apollo Kino price matrix successfully updated from web for period {$periodKey}");
                return $mergedMatrix;
            }

            throw new \RuntimeException("Parsed price matrix from cinema pages was empty");
        } catch (\Throwable $e) {
            Log::error("Apollo Kino cenu matricas atjaunošana no tīmekļa neizdevās ({$periodKey}): " . $e->getMessage() . ". Tiek izmantota noklusētā cenu matrica.", [
                'scraper' => static::class,
                'period' => $periodKey,
                'error' => $e->getMessage(),
            ]);

            // Cache fallback for 1 hour to prevent hammering if server has issues, while allowing retry later
            Cache::put($cacheKey, self::DEFAULT_PRICE_MATRIX, now()->addHours(1));
            return self::DEFAULT_PRICE_MATRIX;
        }
    }

    /**
     * Fetch and parse cinema price tables from Apollo Kino website for all 3 cinemas
     */
    public function fetchPriceMatrixFromWeb(): array
    {
        $matrix = [];

        foreach (self::CINEMA_PAGES as $theatreId => $url) {
            try {
                $response = $this->httpClient->get($url, [
                    'headers' => [
                        'Accept' => 'text/html,application/xhtml+xml',
                    ],
                ]);
                $html = (string) $response->getBody();
                $cinemaPricing = $this->parseCinemaPricingHtml($html);
                if (!empty($cinemaPricing)) {
                    $matrix[$theatreId] = $cinemaPricing;
                }
            } catch (\Throwable $e) {
                Log::error("Failed to fetch/parse pricing for Apollo cinema {$theatreId} from {$url}: " . $e->getMessage(), [
                    'scraper' => static::class,
                    'theatre_id' => $theatreId,
                    'url' => $url,
                ]);
            }
        }

        return $matrix;
    }

    /**
     * Parse HTML of Apollo Kino cinema page price slider (.price-slider)
     */
    public function parseCinemaPricingHtml(string $html): array
    {
        $crawler = new Crawler($html);
        $cells = $crawler->filter('.price-slider__cell');
        if ($cells->count() === 0) {
            return [];
        }

        // Find column count by locating first row label ('Apollo Klubabiedra cena')
        $numCols = 0;
        foreach ($cells as $idx => $c) {
            if (str_contains($c->textContent, 'Apollo Klubabiedra cena')) {
                $numCols = $idx;
                break;
            }
        }

        if ($numCols === 0) {
            return [];
        }

        // Parse column headers from row 0
        $colDefs = [];
        for ($col = 1; $col < $numCols; $col++) {
            $headerText = mb_strtolower($cells->eq($col)->text(), 'UTF-8');
            $isWeekend = str_contains($headerText, '17:00') || str_contains($headerText, 'brīvdien') || str_contains($headerText, 'brivdien');
            $dayKey = $isWeekend ? 'weekend' : 'weekday';

            $hallType = 'standarta';
            if (str_contains($headerText, 'imax')) {
                $hallType = 'imax';
            } elseif (str_contains($headerText, 'restorān') || str_contains($headerText, 'restoran')) {
                $hallType = 'restorans';
            } elseif (str_contains($headerText, 'vintage')) {
                $hallType = 'vintage';
            } elseif (str_contains($headerText, 'dīvān') || str_contains($headerText, 'divan')) {
                $hallType = 'divans';
            } elseif (str_contains($headerText, 'zvaigžņ') || str_contains($headerText, 'zvaigznu')) {
                $hallType = 'zvaigznu';
            }

            $colDefs[$col] = ['hall' => $hallType, 'day' => $dayKey];
        }

        $result = [];
        $totalCells = $cells->count();

        for ($col = 1; $col < $numCols; $col++) {
            $def = $colDefs[$col] ?? null;
            if (!$def) {
                continue;
            }

            $hall = $def['hall'];
            $day = $def['day'];

            // Adult cell is at Row 1, Child cell is at Row 2
            $adultIdx = $numCols + $col;
            $childIdx = (2 * $numCols) + $col;

            $adultText = $adultIdx < $totalCells ? $cells->eq($adultIdx)->text() : '';
            $childText = $childIdx < $totalCells ? $cells->eq($childIdx)->text() : '';

            foreach (['2D', '3D'] as $fmt) {
                $min = null;
                $max = null;

                // Extract max price from adult text
                if (preg_match('/' . $fmt . '[^\d]*\d+[,.]\d{2}[^\d]*(?:Standarta cena\s*)?(\d+[,.]\d{2})/ui', $adultText, $mAdult)) {
                    $max = (float) str_replace(',', '.', $mAdult[1]);
                } elseif (preg_match('/' . $fmt . '[^\d]*(\d+[,.]\d{2})/ui', $adultText, $mAdult)) {
                    $max = (float) str_replace(',', '.', $mAdult[1]);
                }

                // Extract min price from child text (fallback to adult if child not available)
                if (preg_match('/' . $fmt . '[^\d]*(\d+[,.]\d{2})/ui', $childText, $mChild)) {
                    $min = (float) str_replace(',', '.', $mChild[1]);
                } elseif ($max !== null) {
                    $min = $max;
                }

                if ($min !== null && $max !== null) {
                    $result[$hall][$fmt][$day] = [min($min, $max), max($min, $max)];
                }
            }
        }

        return $result;
    }

    /**
     * Calculate ticket price range (min/max) based on cinema, hall type, 2D/3D format, and showtime
     *
     * @param array|null $priceMatrix Optional custom price matrix; defaults to getPriceMatrix() if null
     * @return array{0: ?float, 1: ?float}
     */
    public function calculateShowPrices(
        int $theatreId,
        string $auditorium,
        string $presentationMethod,
        Carbon $startAt,
        ?array $priceMatrix = null
    ): array {
        $audLower = mb_strtolower($auditorium, 'UTF-8');
        $is3D = str_contains(strtoupper($presentationMethod), '3D');
        $formatKey = $is3D ? '3D' : '2D';

        // Weekend rule: Friday from 17:00, Saturday, Sunday
        $isWeekend = $startAt->isWeekend() || ($startAt->isFriday() && $startAt->hour >= 17);
        $dayKey = $isWeekend ? 'weekend' : 'weekday';

        // Determine hall type
        $hallType = 'standarta';
        if (str_contains($audLower, 'imax')) {
            $hallType = 'imax';
        } elseif (str_contains($audLower, 'restorān') || str_contains($audLower, 'restoran')) {
            $hallType = 'restorans';
        } elseif (str_contains($audLower, 'vintage')) {
            $hallType = 'vintage';
        } elseif (str_contains($audLower, 'zvaigžņ') || str_contains($audLower, 'zvaigznu')) {
            $hallType = 'zvaigznu';
        } elseif (str_contains($audLower, 'dīvān') || str_contains($audLower, 'divan')) {
            $hallType = 'divans';
        }

        $matrix = $priceMatrix ?? $this->getPriceMatrix();
        $theatrePrices = $matrix[$theatreId] ?? ($matrix[1035] ?? []);
        $hallPrices = $theatrePrices[$hallType] ?? ($theatrePrices['standarta'] ?? null);

        if (!$hallPrices) {
            return [null, null];
        }

        $rates = $hallPrices[$formatKey] ?? ($hallPrices['2D'] ?? null);
        if (!$rates || !isset($rates[$dayKey])) {
            return [null, null];
        }

        [$min, $max] = $rates[$dayKey];
        return [(float) $min, (float) $max];
    }
}
