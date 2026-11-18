<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ApolloKinoScraper extends BaseScraper
{
    public const BASE_URL = 'https://www.apollokino.lv';
    public const EVENTS_API = 'https://www.apollokino.lv/xml/Events';
    public const SCHEDULE_API = 'https://www.apollokino.lv/xml/Schedule';

    public function __construct()
    {
        parent::__construct();
        $this->httpClient = new \GuzzleHttp\Client([
            'timeout' => 30,
            'connect_timeout' => 20,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                'Accept' => 'application/json',
                'Accept-Language' => 'lv,en-US;q=0.7,en;q=0.3',
            ],
            'verify' => false,
        ]);
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

        // 2. Fetch 7-day schedule from /xml/Schedule
        $scheduleUrl = self::SCHEDULE_API . '?nrOfDays=7';
        $scheduleData = $this->fetchJson($scheduleUrl);

        if (empty($scheduleData) || !isset($scheduleData['Shows']) || !is_array($scheduleData['Shows'])) {
            Log::warning("No shows returned by Apollo Kino Schedule API: {$scheduleUrl}");
            return $events;
        }

        $shows = $scheduleData['Shows'];
        $todayStart = now()->startOfDay();

        Log::info(sprintf("Processing %d shows from Apollo Kino schedule...", count($shows)));

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
                priceMin: null,
                priceMax: null,
                currency: 'EUR',
                ticketUrl: $ticketUrl,
                imageUrl: $imageUrl,
                sourceUrl: $sourceUrl,
                sourceExternalId: $externalId,
                locale: 'lv',
                rawData: array_filter([
                    'show_id' => $showId,
                    'event_id' => $eventId,
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

        // Check for cinema concerts / music films
        if (str_contains($combined, 'koncert') || str_contains($combined, 'mūzik') || str_contains($combined, 'opera')) {
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
}
