<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class FloorballLvScraper extends BaseScraper
{
    public const DEFAULT_IMAGE = 'https://sodiena.hel1.your-objectstorage.com/leagues/floorball_elvi.webp';
    public const DEFAULT_IMAGE_ELVI = 'https://sodiena.hel1.your-objectstorage.com/leagues/floorball_elvi.webp';
    public const DEFAULT_IMAGE_LATEST = 'https://sodiena.hel1.your-objectstorage.com/leagues/floorball_latest.webp';

    public function getSlug(): string
    {
        return 'floorball-lv';
    }

    public function getName(): string
    {
        return 'Latvijas Florbola Savienība (floorball.lv)';
    }

    /**
     * Supported floorball leagues configuration
     */
    protected array $groups = [
        'vv' => [
            'name' => 'Elvi Florbola līga (Vīrieši)',
            'image' => self::DEFAULT_IMAGE_ELVI,
            'url' => 'https://www.floorball.lv/lv/2026/chempionats/vv/kalendars',
        ],
        'sv' => [
            'name' => 'Elvi Florbola līga (Sievietes)',
            'image' => self::DEFAULT_IMAGE_ELVI,
            'url' => 'https://www.floorball.lv/lv/2026/chempionats/sv/kalendars',
        ],
        'LE' => [
            'name' => 'LAT-EST florbola līga',
            'image' => self::DEFAULT_IMAGE_LATEST,
            'url' => 'https://www.floorball.lv/lv/2026/chempionats/LE/kalendars',
        ],
    ];

    /**
     * Floorball arena metadata mapping with verified OpenStreetMap coordinates and official addresses
     */
    protected array $venues = [
        'Sporta komplekss Cēsis' => [
            'venue' => 'Cēsu Sporta komplekss',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Piebalgas iela 18, Cēsis',
            'lat' => 57.30790,
            'lon' => 25.27820,
        ],
        'Gaujas iela 45, Cēsis' => [
            'venue' => 'Cēsu sporta zāle (Gaujas iela 45)',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Gaujas iela 45, Cēsis',
            'lat' => 57.30896,
            'lon' => 25.24579,
        ],
        'Talsu Valsts ģimnāzija' => [
            'venue' => 'Talsu Valsts ģimnāzijas sporta halle',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'Brīvības iela 29, Talsi',
            'lat' => 57.24002,
            'lon' => 22.59116,
        ],
        'Kocēnu sporta nams' => [
            'venue' => 'Kocēnu sporta nams',
            'city' => 'Kocēni',
            'region' => 'Vidzeme',
            'address' => 'Alejas iela 4, Kocēni',
            'lat' => 57.52194,
            'lon' => 25.33477,
        ],
        'LOC manēža' => [
            'venue' => 'Liepājas Olimpiskā centra vieglatlētikas manēža',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Brīvības iela 39, Liepāja',
            'lat' => 56.51906,
            'lon' => 21.01784,
        ],
        'Lielvārdes sporta centrs' => [
            'venue' => 'Lielvārdes sporta centrs',
            'city' => 'Lielvārde',
            'region' => 'Vidzeme',
            'address' => 'Raiņa iela 22, Lielvārde',
            'lat' => 56.71433,
            'lon' => 24.82019,
        ],
        'Ķekavas vsk. sporta komplekss' => [
            'venue' => 'Ķekavas vidusskolas sporta komplekss',
            'city' => 'Ķekava',
            'region' => 'Rīga un Pierīga',
            'address' => 'Gaismas iela 19, Ķekava',
            'lat' => 56.82403,
            'lon' => 24.23006,
        ],
        'Valmieras olimpiskais centrs' => [
            'venue' => 'Valmieras Olimpiskais centrs',
            'city' => 'Valmiera',
            'region' => 'Vidzeme',
            'address' => 'Čempionu iela 2, Valmiera',
            'lat' => 57.52812,
            'lon' => 25.38789,
        ],
        'Ulbrokas sporta komplekss' => [
            'venue' => 'Ulbrokas sporta komplekss',
            'city' => 'Ulbroka',
            'region' => 'Rīga un Pierīga',
            'address' => 'Institūta iela 1B, Ulbroka',
            'lat' => 56.92862,
            'lon' => 24.32186,
        ],
        'Ādažu sporta centrs' => [
            'venue' => 'Ādažu sporta centrs',
            'city' => 'Ādaži',
            'region' => 'Rīga un Pierīga',
            'address' => 'Gaujas iela 30, Ādaži',
            'lat' => 57.07909,
            'lon' => 24.33935,
        ],
        'Spuņciems' => [
            'venue' => 'Salas pagasta sporta halle',
            'city' => 'Spuņciems',
            'region' => 'Rīga un Pierīga',
            'address' => 'Skolas iela 1, Spuņciems, Salas pagasts, Mārupes novads',
            'lat' => 56.93818,
            'lon' => 23.70735,
        ],
        'Märjamaa Valla Spordikeskus' => [
            'venue' => 'Märjamaa Valla Spordikeskus',
            'city' => 'Märjamaa',
            'region' => 'Igaunija',
            'address' => 'Pärnu mnt 67, Märjamaa, Igaunija',
            'lat' => 58.90386,
            'lon' => 24.42711,
        ],
        'Ääsmäe Spordihoone' => [
            'venue' => 'Ääsmäe Spordihoone',
            'city' => 'Ääsmäe',
            'region' => 'Igaunija',
            'address' => 'Kasesalu tee 16, Ääsmäe, Igaunija',
            'lat' => 59.24494,
            'lon' => 24.50672,
        ],
        'Õismäe Sportmängude hall' => [
            'venue' => 'Õismäe Sportmängude hall',
            'city' => 'Tallina',
            'region' => 'Igaunija',
            'address' => 'Ehitajate tee 141, Tallinn, Igaunija',
            'lat' => 59.41304,
            'lon' => 24.66072,
        ],
        'Õismäe Sportmängude Hall,' => [
            'venue' => 'Õismäe Sportmängude hall',
            'city' => 'Tallina',
            'region' => 'Igaunija',
            'address' => 'Ehitajate tee 141, Tallinn, Igaunija',
            'lat' => 59.41304,
            'lon' => 24.66072,
        ],
        'Jõgeva spordihoone, rohu 10' => [
            'venue' => 'Jõgeva spordihoone Virtus',
            'city' => 'Jõgeva',
            'region' => 'Igaunija',
            'address' => 'Rohu 10, Jõgeva, Igaunija',
            'lat' => 58.74810,
            'lon' => 26.38874,
        ],
    ];

    /**
     * Team fallback city mapping (if venue is unspecified)
     */
    protected array $teamCities = [
        'FBK SĀC' => ['city' => 'Ādaži', 'region' => 'Rīga un Pierīga'],
        'FBK Valmiera' => ['city' => 'Valmiera', 'region' => 'Vidzeme'],
        'FK Kurši' => ['city' => 'Liepāja', 'region' => 'Kurzeme'],
        'Ķekava/RB&B' => ['city' => 'Ķekava', 'region' => 'Rīga un Pierīga'],
        'FK Ķekava' => ['city' => 'Ķekava', 'region' => 'Rīga un Pierīga'],
        'Lekrings' => ['city' => 'Cēsis', 'region' => 'Vidzeme'],
        'Lekrings/CPSS' => ['city' => 'Cēsis', 'region' => 'Vidzeme'],
        'Lielvārde/Unihoc' => ['city' => 'Lielvārde', 'region' => 'Vidzeme'],
        'Rubene' => ['city' => 'Kocēni', 'region' => 'Vidzeme'],
        'FK Talsi/TNSS/Krauzers' => ['city' => 'Talsi', 'region' => 'Kurzeme'],
        'Masters Ulbroka/LU' => ['city' => 'Ulbroka', 'region' => 'Rīga un Pierīga'],
        'NND/RJTC' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'Tartu Linnameeskond' => ['city' => 'Tartu', 'region' => 'Igaunija'],
        'Sparta team Automaailm' => ['city' => 'Tallina', 'region' => 'Igaunija'],
        'Sparta' => ['city' => 'Tallina', 'region' => 'Igaunija'],
        'Jogeva SK Tähe/Olivia' => ['city' => 'Jõgeva', 'region' => 'Igaunija'],
        'Jõgeva SK Tähe/Olivia' => ['city' => 'Jõgeva', 'region' => 'Igaunija'],
        'Eesti Maaülikooli SK' => ['city' => 'Tartu', 'region' => 'Igaunija'],
    ];

    public function scrape(Source $source): Collection
    {
        $jar = new CookieJar();
        $client = new Client([
            'cookies' => $jar,
            'version' => 1.1, // Mandatory: floorball.lv nginx HTTP/2 bug causes REFUSED_STREAM on POST
            'timeout' => 20,
            'connect_timeout' => 10,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                'Referer' => 'https://www.floorball.lv/lv/2026/chempionats/vv/kalendars',
            ],
            'verify' => false,
        ]);

        // 1. Visit calendar page to establish PHP session (PHPSESSID) and discover season info
        $pageUrl = $source->url ?: 'https://www.floorball.lv/lv/2026/chempionats/vv/kalendars';
        $seasonStartYear = (int) date('Y');
        $seasonId = 35; // Default season ID for 2026/2027

        try {
            $response = $client->get($pageUrl);
            $html = (string) $response->getBody();

            if (preg_match('/\/(\d{4})\/chempionats/', $pageUrl, $ym)) {
                $seasonStartYear = (int) $ym[1];
            } elseif (preg_match('/Season\s+(\d{4})\/(\d{4})/', $html, $sm)) {
                $seasonStartYear = (int) $sm[1];
            }

            if (preg_match('/"filtrs_sezona",\s*"value":\s*"(\d+)"/', $html, $szm)) {
                $seasonId = (int) $szm[1];
            }
        } catch (\Throwable $e) {
            Log::error("FloorballLvScraper: error establishing session on {$pageUrl}: " . $e->getMessage());
        }

        // 2. Determine groups to scrape: by default scrape all 3 (vv, sv, LE)
        $groupsToScrape = $this->groups;
        if (!empty($source->url) && preg_match('/[?&]group=([a-zA-Z0-9_-]+)/', $source->url, $gm)) {
            $specifiedGroup = $gm[1];
            foreach ($this->groups as $grpKey => $meta) {
                if (strcasecmp($grpKey, $specifiedGroup) === 0) {
                    $groupsToScrape = [$grpKey => $meta];
                    break;
                }
            }
        }

        $allEvents = collect();

        foreach ($groupsToScrape as $grp => $meta) {
            $groupEvents = $this->scrapeGroup($grp, $meta, $client, $seasonStartYear, $seasonId);
            $allEvents = $allEvents->merge($groupEvents);
        }

        return $allEvents;
    }

    /**
     * Scrape a specific group (vv, sv, LE) via DataTables AJAX POST
     */
    public function scrapeGroup(string $grp, array $meta, Client $client, int $seasonStartYear, int $seasonId): Collection
    {
        $events = collect();
        $ajaxUrl = 'https://www.floorball.lv/ajax/ajax_chempionats_kalendars.php';

        try {
            $response = $client->post($ajaxUrl, [
                'headers' => [
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Referer' => $meta['url'],
                ],
                'form_params' => [
                    'sEcho' => 1,
                    'iColumns' => 7,
                    'iDisplayStart' => 0,
                    'iDisplayLength' => 300, // Fetch all matches for the league
                    'url' => 'https://www.floorball.lv/lv',
                    'menu' => 'chempionats',
                    'filtrs_grupa' => $grp,
                    'filtrs_sezona' => $seasonId,
                    'filtrs_spelu_veids' => 0,
                    'filtrs_menesis' => 'all', // Full season
                    'filtrs_komanda' => 0,
                    'filtrs_majas_viesi' => 0,
                ],
            ]);

            $json = json_decode((string) $response->getBody(), true);
            $rows = $json['aaData'] ?? [];

            foreach ($rows as $row) {
                $dto = $this->parseRow($row, $grp, $meta, $seasonStartYear);
                if ($dto) {
                    $events->push($dto);
                }
            }
        } catch (\Throwable $e) {
            Log::error("FloorballLvScraper error fetching group {$grp}: " . $e->getMessage());
        }

        return $events;
    }

    /**
     * Parse a single match row from aaData
     * Format: [0: "DD.MM", 1: "HH:MM", 2: homeHtml, 3: scoreHtml, 4: awayHtml, 5: venue, 6: referees]
     */
    public function parseRow(array $row, string $grp, array $meta, int $seasonStartYear): ?ScrapedEventDTO
    {
        $dateText = trim(strip_tags($row[0] ?? ''));
        $timeText = trim(strip_tags($row[1] ?? ''));

        if (!preg_match('/(\d{1,2})\.(\d{1,2})/', $dateText, $dm)) {
            return null;
        }

        $day = (int) $dm[1];
        $mon = (int) $dm[2];
        $year = ($mon >= 7) ? $seasonStartYear : ($seasonStartYear + 1);

        $time = preg_match('/^\d{1,2}:\d{2}$/', $timeText) ? $timeText : '00:00';

        try {
            $startAt = Carbon::createFromFormat('Y-m-d H:i', sprintf('%04d-%02d-%02d %s', $year, $mon, $day, $time), 'Europe/Riga');
        } catch (\Throwable $e) {
            return null;
        }

        // Rule: do not ingest past events
        if ($startAt->lt(now()->startOfDay())) {
            return null;
        }

        // Floorball match duration ≈ 105 minutes (3 periods + breaks)
        $endAt = (clone $startAt)->addMinutes(105);

        // Extract home team name and ID
        $homeRaw = $row[2] ?? '';
        if (preg_match('/title="([^"]+)"/', $homeRaw, $hm)) {
            $home = html_entity_decode(trim($hm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } else {
            $home = html_entity_decode(trim(strip_tags($homeRaw)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Extract away team name and ID
        $awayRaw = $row[4] ?? '';
        if (preg_match('/title="([^"]+)"/', $awayRaw, $aw)) {
            $away = html_entity_decode(trim($aw[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } else {
            $away = html_entity_decode(trim(strip_tags($awayRaw)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $home = trim(str_replace('&nbsp;', ' ', $home));
        $away = trim(str_replace('&nbsp;', ' ', $away));

        if (empty($home) || empty($away)) {
            return null;
        }

        $homeId = '';
        if (preg_match('/\/komanda\/(\d+)/', $homeRaw, $hmId)) {
            $homeId = $hmId[1];
        }

        $awayId = '';
        if (preg_match('/\/komanda\/(\d+)/', $awayRaw, $awId)) {
            $awayId = $awId[1];
        }

        $venueRaw = trim(strip_tags($row[5] ?? ''));
        $location = $this->resolveLocation($venueRaw, $home);

        $gameUrl = $meta['url'];
        if (preg_match('/href="([^"]+)"/', $row[3] ?? '', $protoMatch)) {
            $gameUrl = $protoMatch[1];
        }

        // Deterministic external ID
        $idParts = [$grp, $startAt->format('YmdHi')];
        if ($homeId && $awayId) {
            $idParts[] = $homeId;
            $idParts[] = $awayId;
        } else {
            $idParts[] = substr(md5("{$home}_{$away}"), 0, 8);
        }
        $sourceExternalId = implode('_', $idParts);

        $leagueName = $meta['name'];
        $title = "{$home} vs {$away}";
        $shortDescription = "{$leagueName}: {$home} pret {$away} ({$location['venue']}).";

        $descLines = [
            "{$leagueName} spēle: {$home} pret {$away}.",
            '',
            "Norises vieta: {$location['venue']}, {$location['city']}",
        ];
        if (!empty($location['address'])) {
            $descLines[] = "Adrese: {$location['address']}";
        }
        $description = implode("\n", $descLines);

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
            placeType: 'sports_facility',
            categoryNames: ['Sports'],
            entertainmentType: 'sports',
            isFree: false,
            priceMin: null,
            priceMax: null,
            currency: 'EUR',
            ticketUrl: null,
            imageUrl: $meta['image'],
            sourceUrl: $gameUrl,
            sourceExternalId: $sourceExternalId,
            locale: 'lv',
            rawData: [
                'group' => $grp,
                'league' => $leagueName,
                'home_team' => $home,
                'away_team' => $away,
                'home_id' => $homeId,
                'away_id' => $awayId,
                'venue_raw' => $venueRaw,
                'date_raw' => $dateText,
                'time_raw' => $timeText,
            ]
        );
    }

    public function resolveLocation(string $venueRaw, string $homeTeam): array
    {
        $trimmed = trim($venueRaw);

        // 1. Direct or fuzzy substring match in known floorball venues
        foreach ($this->venues as $name => $data) {
            if (!empty($trimmed) && (
                stripos($trimmed, $name) !== false ||
                stripos($name, $trimmed) !== false
            )) {
                return $data;
            }
        }

        // 2. Fallback to team home city if venue is unknown
        if (isset($this->teamCities[$homeTeam])) {
            $teamInfo = $this->teamCities[$homeTeam];
            return [
                'venue' => !empty($trimmed) ? $trimmed : "{$homeTeam} mājas zāle",
                'city' => $teamInfo['city'],
                'region' => $teamInfo['region'],
                'address' => null,
                'lat' => null,
                'lon' => null,
            ];
        }

        return [
            'venue' => !empty($trimmed) ? $trimmed : 'Florbola zāle',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => null,
            'lat' => null,
            'lon' => null,
        ];
    }
}
