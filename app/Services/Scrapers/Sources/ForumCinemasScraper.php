<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Event;
use App\Models\Source;
use App\Services\Language\MarkusCinemaLanguageParser;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class ForumCinemasScraper extends BaseScraper
{
    public const BASE_URL = 'https://www.forumcinemas.lv';
    public const EVENTS_API = 'https://www.forumcinemas.lv/xml/Events';
    public const SCHEDULE_API = 'https://www.forumcinemas.lv/xml/Schedule';

    public function __construct()
    {
        parent::__construct();
        $options = [
            'timeout' => 30,
            'connect_timeout' => 20,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                'Accept' => 'text/xml,application/xml,application/json,*/*',
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
     * Fetch XML and convert to associative array
     */
    protected function fetchXml(string $url): ?array
    {
        try {
            $response = $this->httpClient->get($url);
            $body = (string) $response->getBody();

            if (empty(trim($body))) {
                return null;
            }

            if (str_starts_with(trim($body), '<?xml') || str_starts_with(trim($body), '<')) {
                libxml_use_internal_errors(true);
                $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
                if ($xml !== false) {
                    return json_decode(json_encode($xml), true);
                }
            }

            return json_decode($body, true);
        } catch (\Throwable $e) {
            Log::error("Forum Cinemas XML fetch error for URL {$url}: " . $e->getMessage(), [
                'scraper' => static::class,
            ]);
            return null;
        }
    }

    public function getSlug(): string
    {
        return 'forum-cinemas';
    }

    public function getName(): string
    {
        return 'Forum Cinemas';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $seenExternalIds = [];

        Log::info("Starting Forum Cinemas scraping from XML API...");

        // 1. Fetch full movie metadata catalogue from /xml/Events
        $moviesCatalog = $this->fetchMoviesCatalog();
        Log::info(sprintf("Loaded %d movies from Forum Cinemas catalogue", count($moviesCatalog)));

        // 2. Fetch 14-day schedule from /xml/Schedule for all areas (1013)
        $scheduleUrl = self::SCHEDULE_API . '?area=1013&nrOfDays=14';
        $scheduleData = $this->fetchXml($scheduleUrl);

        if (empty($scheduleData) || !isset($scheduleData['Shows'])) {
            Log::warning("No shows returned by Forum Cinemas Schedule API: {$scheduleUrl}");
            return $events;
        }

        $shows = $scheduleData['Shows']['Show'] ?? $scheduleData['Shows'];
        if (isset($shows['ID'])) {
            $shows = [$shows];
        }

        if (!is_array($shows)) {
            Log::warning("Unexpected shows data structure from Forum Cinemas Schedule API");
            return $events;
        }

        $todayStart = now()->startOfDay();

        // 3. Preload known show prices from database to avoid re-fetching already known prices
        $existingPrices = Event::where('source_external_id', 'LIKE', 'show_%')
            ->whereNotNull('price_min')
            ->get(['source_external_id', 'price_min', 'price_max'])
            ->keyBy('source_external_id');

        Log::info(sprintf(
            "Processing %d shows from Forum Cinemas schedule (known prices in DB: %d)...",
            count($shows),
            $existingPrices->count()
        ));

        $priceFetchCount = 0;

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
                Log::debug("Invalid start date for Forum Cinemas show {$showId}: {$startAtStr}");
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
            $title = is_string($show['Title'] ?? null) ? trim($show['Title']) : (is_string($movie['title'] ?? null) ? trim($movie['title']) : '');
            if (empty($title)) {
                continue;
            }

            // Location & Theatre resolution
            $theatreId = (int) ($show['TheatreID'] ?? 0);
            $theatreName = is_string($show['Theatre'] ?? null) ? trim($show['Theatre']) : '';
            $locationData = $this->resolveTheatre($theatreId, $theatreName);

            // Auditory / Hall & presentation format
            $auditorium = is_string($show['TheatreAuditorium'] ?? null) ? trim($show['TheatreAuditorium']) : '';
            $presentationMethod = is_string($show['PresentationMethod'] ?? null) ? trim($show['PresentationMethod']) : '';

            // Languages & subtitles (safely extracting string even if empty XML tag became array)
            $spokenLang = $this->extractXmlName($show['SpokenLanguage'] ?? null);
            $sub1 = $this->extractXmlName($show['SubtitleLanguage1'] ?? null);
            $sub2 = $this->extractXmlName($show['SubtitleLanguage2'] ?? null);
            $subtitles = array_values(array_filter([$sub1, $sub2]));

            // Level 1 Explicit Language parsing strictly via MarkusCinemaLanguageParser
            $parsedLanguage = MarkusCinemaLanguageParser::parseShow($show, [
                'Synopsis' => $movie['synopsis'] ?? null,
                'ShortSynopsis' => $movie['shortSynopsis'] ?? null,
                'Genres' => $show['Genres'] ?? ($movie['genres'] ?? null),
                'Title' => $title,
            ]);

            // Categories & Entertainment type strictly per AGENTS.md
            $genres = is_string($show['Genres'] ?? null) ? trim($show['Genres']) : (is_string($movie['genres'] ?? null) ? trim($movie['genres']) : '');
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
            $ticketUrl = str_replace('http://', 'https://', $ticketUrl);

            $sourceUrl = !empty($movie['eventUrl'])
                ? trim($movie['eventUrl'])
                : (!empty($show['EventURL']) ? trim($show['EventURL']) : sprintf('%s/event/%d', self::BASE_URL, $eventId));
            $sourceUrl = str_replace('http://', 'https://', $sourceUrl);

            // Resolve ticket prices (reuse from database if already known, otherwise fetch from websales)
            $priceMin = null;
            $priceMax = null;

            if ($existingPrices->has($externalId)) {
                $existing = $existingPrices->get($externalId);
                $priceMin = (float) $existing->price_min;
                $priceMax = $existing->price_max !== null ? (float) $existing->price_max : $priceMin;
            } elseif (!empty($ticketUrl)) {
                [$priceMin, $priceMax] = $this->fetchShowPrices($ticketUrl);
                $priceFetchCount++;
                if ($priceFetchCount % 25 === 0) {
                    Log::info(sprintf("Forum Cinemas price fetching progress: %d newly fetched...", $priceFetchCount));
                }
                $this->sleepPolitely();
            }

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
                    'cinema' => [
                        'spoken_language_raw' => $show['SpokenLanguage'] ?? null,
                        'subtitle_languages_raw' => array_values(array_filter([
                            $show['SubtitleLanguage1'] ?? null,
                            $show['SubtitleLanguage2'] ?? null,
                        ])),
                        'presentation_method' => $presentationMethod,
                        'auditorium' => $auditorium,
                        'chain' => 'forum-cinemas',
                        'session_id' => $showId,
                    ],
                    'language' => $parsedLanguage->toScraperArray(),
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

        Log::info(sprintf(
            "Forum Cinemas scraping completed: %d upcoming shows prepared (newly fetched prices: %d, reused from DB: %d)",
            $events->count(),
            $priceFetchCount,
            $events->count() - $priceFetchCount
        ));

        return $events;
    }

    /**
     * Fetch all movies from /xml/Events and index them by EventID
     */
    public function fetchMoviesCatalog(): array
    {
        $rawMoviesData = $this->fetchXml(self::EVENTS_API);
        if (empty($rawMoviesData) || !is_array($rawMoviesData)) {
            Log::warning("Failed to fetch movies catalogue from: " . self::EVENTS_API);
            return [];
        }

        $rawMovies = $rawMoviesData['Event'] ?? $rawMoviesData;
        if (isset($rawMovies['ID'])) {
            $rawMovies = [$rawMovies];
        }

        $catalog = [];
        foreach ($rawMovies as $m) {
            $id = (int) ($m['ID'] ?? 0);
            if (!$id) {
                continue;
            }

            // Extract director names
            $directors = [];
            $directorNodes = $m['Directors']['Director'] ?? $m['Directors'] ?? [];
            if (isset($directorNodes['FirstName']) || isset($directorNodes['LastName'])) {
                $directorNodes = [$directorNodes];
            }
            if (is_array($directorNodes)) {
                foreach ($directorNodes as $d) {
                    if (!is_array($d)) {
                        continue;
                    }
                    $dName = trim(($d['FirstName'] ?? '') . ' ' . ($d['LastName'] ?? ''));
                    if (!empty($dName)) {
                        $directors[] = $dName;
                    }
                }
            }

            // Extract cast actor names
            $cast = [];
            $castNodes = $m['Cast']['Actor'] ?? $m['Cast'] ?? [];
            if (isset($castNodes['FirstName']) || isset($castNodes['LastName'])) {
                $castNodes = [$castNodes];
            }
            if (is_array($castNodes)) {
                foreach ($castNodes as $c) {
                    if (!is_array($c)) {
                        continue;
                    }
                    $cName = trim(($c['FirstName'] ?? '') . ' ' . ($c['LastName'] ?? ''));
                    if (!empty($cName)) {
                        $cast[] = $cName;
                    }
                }
            }

            // Extract trailer video (YouTube)
            $trailerUrl = null;
            $videoNodes = $m['Videos']['EventVideo'] ?? $m['Videos'] ?? [];
            if (isset($videoNodes['Location'])) {
                $videoNodes = [$videoNodes];
            }
            if (is_array($videoNodes)) {
                foreach ($videoNodes as $v) {
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

            $img = $m['Images']['EventLargeImagePortrait']
                ?? $m['Images']['EventMediumImagePortrait']
                ?? $m['Images']['EventSmallImagePortrait']
                ?? null;
            if (!empty($img) && is_string($img)) {
                $img = str_replace('http://', 'https://', $img);
            }

            $eventUrl = $m['EventURL'] ?? null;
            if (!empty($eventUrl) && is_string($eventUrl)) {
                $eventUrl = str_replace('http://', 'https://', $eventUrl);
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
                'eventUrl' => $eventUrl,
                'imageUrl' => $img,
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

        // Forum Cinemas Alfa (Brīvības gatve 372, Rīga)
        if ($theatreId === 1034 || $theatreId === 1012 || str_contains($t, 'alfa')) {
            return [
                'venue_name' => 'Forum Cinemas Alfa',
                'address' => 'Brīvības gatve 372, Rīga, LV-1006',
                'city' => 'Rīga',
                'region' => 'Rīga un Pierīga',
                'latitude' => 56.9835,
                'longitude' => 24.2045,
                'place_type' => 'cinema',
            ];
        }

        // Forum Cinemas Centrs / Kino Citadele (13. janvāra iela 8, Rīga)
        return [
            'venue_name' => 'Kino Citadele',
            'address' => '13. janvāra iela 8, Rīga, LV-1050',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'latitude' => 56.9458,
            'longitude' => 24.1147,
            'place_type' => 'cinema',
        ];
    }

    /**
     * Resolve categories strictly per AGENTS.md:
     * - Forum Cinemas is ALWAYS Kino and NEVER Teātris!
     * - Secondary categories (Bērniem, Mūzika) resolved from genres and title.
     */
    public function resolveCategories(string $title, string $genres, string $venueName): array
    {
        $canonical = ['Kino'];
        $entertainmentType = 'kino';

        $combined = mb_strtolower($title . ' ' . $genres . ' ' . $venueName, 'UTF-8');

        // Kids / Family
        if (
            str_contains($combined, 'animācij') ||
            str_contains($combined, 'ģimenes') ||
            str_contains($combined, 'bērniem') ||
            str_contains($combined, 'bērnu')
        ) {
            $canonical[] = 'Bērniem';
        }

        // Music / Concerts
        if (
            str_contains($combined, 'mūzika') ||
            str_contains($combined, 'mūzikls') ||
            str_contains($combined, 'koncertfilma') ||
            str_contains($combined, 'koncerts') ||
            str_contains($combined, 'opera') ||
            str_contains($combined, 'balets')
        ) {
            $canonical[] = 'Mūzika';
        }

        return [array_values(array_unique($canonical)), $entertainmentType];
    }

    /**
     * Format rich description including synopsis, metadata, and screening session details
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
        $sections = [];

        // 1. Main Synopsis
        $synopsis = !empty($movie['synopsis']) ? $movie['synopsis'] : ($movie['shortSynopsis'] ?? '');
        if (!empty($synopsis)) {
            $sections[] = $synopsis;
        }

        // 2. Movie metadata block
        $metaDetails = [];

        if (!empty($movie['originalTitle']) && mb_strtolower($movie['originalTitle']) !== mb_strtolower($title)) {
            $metaDetails[] = "Oriģinālnosaukums: {$movie['originalTitle']}";
        }

        if (!empty($movie['productionYear'])) {
            $metaDetails[] = "Gads: {$movie['productionYear']}";
        }

        if (!empty($movie['lengthInMinutes']) && (int) $movie['lengthInMinutes'] > 0) {
            $metaDetails[] = "Ilgums: {$movie['lengthInMinutes']} min";
        }

        if (!empty($genres)) {
            $metaDetails[] = "Žanrs: {$genres}";
        }

        if (!empty($movie['ratingLabel'])) {
            $metaDetails[] = "Vecuma ierobežojums: {$movie['ratingLabel']}";
        }

        if (!empty($movie['directors'])) {
            $metaDetails[] = "Režisors: " . implode(', ', $movie['directors']);
        }

        if (!empty($movie['cast'])) {
            $metaDetails[] = "Lomās: " . implode(', ', array_slice($movie['cast'], 0, 8));
        }

        if (!empty($movie['trailerUrl'])) {
            $metaDetails[] = "Treileris: {$movie['trailerUrl']}";
        }

        if (!empty($metaDetails)) {
            $sections[] = implode("\n", $metaDetails);
        }

        // 3. Screening session format block
        $sessionDetails = [];

        $sessionDetails[] = "Kinoteātris: " . ($show['Theatre'] ?? 'Forum Cinemas');

        if (!empty($auditorium)) {
            $sessionDetails[] = "Zāle: {$auditorium}";
        }

        if (!empty($presentationMethod)) {
            $sessionDetails[] = "Formāts: {$presentationMethod}";
        }

        if (!empty($spokenLang)) {
            $sessionDetails[] = "Valoda: {$spokenLang}";
        }

        if (!empty($subtitles)) {
            $sessionDetails[] = "Subtitri: " . implode(', ', $subtitles);
        }

        if (!empty($sessionDetails)) {
            $sections[] = "--- Seansa informācija ---\n" . implode("\n", $sessionDetails);
        }

        $fullDescription = trim(implode("\n\n", $sections));

        return !empty($fullDescription) ? $fullDescription : "Filmas \"{$title}\" kinoseanss Forum Cinemas kinoteātrī.";
    }

    /**
     * Resolve best available poster image
     */
    public function resolveImageUrl(array $show, array $movie): ?string
    {
        if (!empty($movie['imageUrl']) && is_string($movie['imageUrl'])) {
            return str_replace('http://', 'https://', $movie['imageUrl']);
        }

        $showImages = $show['Images'] ?? [];
        if (is_array($showImages)) {
            $img = $showImages['EventLargeImagePortrait']
                ?? $showImages['EventMediumImagePortrait']
                ?? $showImages['EventSmallImagePortrait']
                ?? null;

            if (!empty($img) && is_string($img)) {
                return str_replace('http://', 'https://', $img);
            }
        }

        return null;
    }

    /**
     * Safely extract text name from XML value, handling empty XML tags that convert to empty arrays
     */
    public function extractXmlName(mixed $val): ?string
    {
        if (is_string($val) && !empty(trim($val))) {
            return trim($val);
        }
        if (is_array($val) && isset($val['Name']) && is_string($val['Name']) && !empty(trim($val['Name']))) {
            return trim($val['Name']);
        }
        return null;
    }

    /**
     * Fetch ticket price range (min/max) from show websales page
     *
     * @return array{0: ?float, 1: ?float}
     */
    public function fetchShowPrices(string $ticketUrl): array
    {
        try {
            $response = $this->httpClient->get($ticketUrl);
            $html = (string) $response->getBody();

            if (empty(trim($html))) {
                return [null, null];
            }

            $crawler = new Crawler($html);
            $prices = [];

            // 1. Look for .showPrices-table-price
            foreach ($crawler->filter('.showPrices-table-price') as $node) {
                if (preg_match('/([0-9]+(?:[.,][0-9]{1,2})?)\s*€/u', $node->textContent, $matches)) {
                    $val = (float) str_replace(',', '.', $matches[1]);
                    if ($val > 0) {
                        $prices[] = $val;
                    }
                }
            }

            // 2. Fallback: look for .ticket-row
            if (empty($prices)) {
                foreach ($crawler->filter('.ticket-row') as $node) {
                    if (preg_match('/([0-9]+(?:[.,][0-9]{1,2})?)\s*€/u', $node->textContent, $matches)) {
                        $val = (float) str_replace(',', '.', $matches[1]);
                        if ($val > 0) {
                            $prices[] = $val;
                        }
                    }
                }
            }

            if (empty($prices)) {
                return [null, null];
            }

            return [min($prices), max($prices)];
        } catch (\Throwable $e) {
            Log::debug("Forum Cinemas price fetch error for {$ticketUrl}: " . $e->getMessage());
            return [null, null];
        }
    }

    /**
     * Polite rate limiting with jitter between HTTP requests (1000ms + 0-400ms jitter)
     */
    protected function sleepPolitely(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $us = 1000000 + random_int(0, 400000);
        usleep($us);
    }

    protected function cleanText(?string $text): string
    {
        if (empty($text)) {
            return '';
        }

        $clean = strip_tags($text);
        $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = preg_replace('/[ \t]+/', ' ', $clean);
        $clean = preg_replace('/\n{3,}/', "\n\n", $clean);

        return trim($clean);
    }
}
