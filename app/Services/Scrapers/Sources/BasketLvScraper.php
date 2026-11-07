<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class BasketLvScraper extends BaseScraper
{
    public const DEFAULT_IMAGE_NBL = 'https://sodiena.hel1.your-objectstorage.com/leagues/ramirent-nbl.webp';
    public const DEFAULT_IMAGE_SBBL = 'https://sodiena.hel1.your-objectstorage.com/leagues/sbbl.webp';

    public const LEAGUES = [
        'nbl' => [
            'name' => 'Ramirent NBL',
            'website_id' => 120,
            'calendar_url' => 'https://nbl.basket.lv/kalendars/',
            'default_image' => self::DEFAULT_IMAGE_NBL,
            'only_latvia' => false,
        ],
        'sbbl' => [
            'name' => 'BioSil SBBL',
            'website_id' => 309,
            'calendar_url' => 'https://sbblb.basket.lv/kalendars/',
            'default_image' => self::DEFAULT_IMAGE_SBBL,
            'only_latvia' => true,
        ],
    ];

    public function getSlug(): string
    {
        return 'basket-lv';
    }

    public function getName(): string
    {
        return 'Latvijas Basketbola Savienība (basket.lv)';
    }

    /**
     * Basketball venue metadata mapping with verified OpenStreetMap coordinates and official addresses
     */
    protected array $venues = [
        // User requested: Daugavpils KSC īstenībā ir Daugavpils kultūras pils sporta zāle
        'Daugavpils KSC' => [
            'venue' => 'Daugavpils kultūras pils sporta zāle',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Smilšu iela 92, Daugavpils',
            'lat' => 55.88738,
            'lon' => 26.55463,
            'in_latvia' => true,
        ],
        // User requested: Rīga, KSSH īstenībā ir Komandu sporta spēļu halle Rīgā
        'Rīga, KSSH' => [
            'venue' => 'Komandu sporta spēļu halle Rīgā',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Krišjāņa Barona iela 99C, Rīga',
            'lat' => 56.96352,
            'lon' => 24.14134,
            'in_latvia' => true,
        ],
        'KSSH 1' => [
            'venue' => 'Komandu sporta spēļu halle Rīgā',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Krišjāņa Barona iela 99C, Rīga',
            'lat' => 56.96352,
            'lon' => 24.14134,
            'in_latvia' => true,
        ],
        'Bauskas SH' => [
            'venue' => 'Bauskas novada BJSS sporta halle',
            'city' => 'Bauska',
            'region' => 'Zemgale',
            'address' => 'Pilskalna iela 26, Bauska',
            'lat' => 56.40267,
            'lon' => 24.18705,
            'in_latvia' => true,
        ],
        'DSN' => [
            'venue' => 'Daugavas sporta nams',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Krišjāņa Barona iela 107, Rīga',
            'lat' => 56.96677,
            'lon' => 24.14811,
            'in_latvia' => true,
        ],
        'Grobiņas SC' => [
            'venue' => 'Grobiņas sporta centrs',
            'city' => 'Grobiņa',
            'region' => 'Kurzeme',
            'address' => 'M. Namiķa iela 2B, Grobiņa',
            'lat' => 56.53297,
            'lon' => 21.15643,
            'in_latvia' => true,
        ],
        'Gulbenes SC' => [
            'venue' => 'Gulbenes sporta centrs',
            'city' => 'Gulbene',
            'region' => 'Vidzeme',
            'address' => 'Skolas iela 12, Gulbene',
            'lat' => 57.17115,
            'lon' => 26.76888,
            'in_latvia' => true,
        ],
        'Jelgavas SH' => [
            'venue' => 'Jelgavas sporta halle',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Mātera iela 44A, Jelgava',
            'lat' => 56.64778,
            'lon' => 23.72228,
            'in_latvia' => true,
        ],
        'Jēkabpils SH' => [
            'venue' => 'Jēkabpils sporta halle',
            'city' => 'Jēkabpils',
            'region' => 'Latgale',
            'address' => 'Brīvības iela 289, Jēkabpils',
            'lat' => 56.49505,
            'lon' => 25.86548,
            'in_latvia' => true,
        ],
        'Jēkabpils 3.vsk' => [
            'venue' => 'Jēkabpils 3. vidusskolas sporta manēža',
            'city' => 'Jēkabpils',
            'region' => 'Latgale',
            'address' => 'Slimnīcas iela 5, Jēkabpils',
            'lat' => 56.50652,
            'lon' => 25.88886,
            'in_latvia' => true,
        ],
        'Kandavas SH' => [
            'venue' => 'Kandavas sporta halle',
            'city' => 'Kandava',
            'region' => 'Kurzeme',
            'address' => 'Skolas iela 10, Kandava',
            'lat' => 57.04273,
            'lon' => 22.77583,
            'in_latvia' => true,
        ],
        'Liepājas OC' => [
            'venue' => 'Liepājas Olimpiskais centrs',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Brīvības iela 39, Liepāja',
            'lat' => 56.51906,
            'lon' => 21.01784,
            'in_latvia' => true,
        ],
        'Limbažu vsk.' => [
            'venue' => 'Limbažu sporta halle',
            'city' => 'Limbaži',
            'region' => 'Vidzeme',
            'address' => 'Parka iela 36, Limbaži',
            'lat' => 57.50703,
            'lon' => 24.70969,
            'in_latvia' => true,
        ],
        'Mārupes NSS' => [
            'venue' => 'Mārupes sporta centrs',
            'city' => 'Mārupe',
            'region' => 'Rīga un Pierīga',
            'address' => 'Kantora iela 97, Mārupe',
            'lat' => 56.90805,
            'lon' => 24.05315,
            'in_latvia' => true,
        ],
        'OC Ventspils' => [
            'venue' => 'Olimpiskais centrs "Ventspils"',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Sporta iela 7/9, Ventspils',
            'lat' => 57.39055,
            'lon' => 21.55462,
            'in_latvia' => true,
        ],
        'RSU sporta komplekss' => [
            'venue' => 'RSU Sporta kluba zāle',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Cigoriņu iela 3, Rīga',
            'lat' => 56.93010,
            'lon' => 24.08465,
            'in_latvia' => true,
        ],
        'Salaspils SN' => [
            'venue' => 'Salaspils sporta nams',
            'city' => 'Salaspils',
            'region' => 'Rīga un Pierīga',
            'address' => 'Smilšu iela 1, Salaspils',
            'lat' => 56.85873,
            'lon' => 24.35415,
            'in_latvia' => true,
        ],
        'Saldus SN' => [
            'venue' => 'Saldus sporta nams',
            'city' => 'Saldus',
            'region' => 'Kurzeme',
            'address' => 'Jelgavas iela 6, Saldus',
            'lat' => 56.66698,
            'lon' => 22.50293,
            'in_latvia' => true,
        ],
        'Sporta Arēna Ogre' => [
            'venue' => 'Arēna Ogre',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Gunāra Astras iela 1, Ogre',
            'lat' => 56.81525,
            'lon' => 24.61903,
            'in_latvia' => true,
        ],
        'Talsu SH' => [
            'venue' => 'Talsu 2. vidusskolas sporta halle',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'Kārļa Mīlenbaha iela 32, Talsi',
            'lat' => 57.24360,
            'lon' => 22.60495,
            'in_latvia' => true,
        ],
        'Talsu SN' => [
            'venue' => 'Talsu sporta nams',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'Kareivju iela 12, Talsi',
            'lat' => 57.24792,
            'lon' => 22.59719,
            'in_latvia' => true,
        ],
        'Tukuma SH' => [
            'venue' => 'Tukuma Sporta skolas halle',
            'city' => 'Tukums',
            'region' => 'Kurzeme',
            'address' => 'Pauzera iela 7, Tukums',
            'lat' => 56.96490,
            'lon' => 23.14800,
            'in_latvia' => true,
        ],
        'Valmieras OC' => [
            'venue' => 'Valmieras Olimpiskais centrs',
            'city' => 'Valmiera',
            'region' => 'Vidzeme',
            'address' => 'Čempionu iela 2, Valmiera',
            'lat' => 57.53856,
            'lon' => 25.44199,
            'in_latvia' => true,
        ],
        'Ķekavas SK' => [
            'venue' => 'Ķekavas sporta klubs',
            'city' => 'Ķekava',
            'region' => 'Rīga un Pierīga',
            'address' => 'Gaismas iela 19, Ķekava',
            'lat' => 56.82855,
            'lon' => 24.23439,
            'in_latvia' => true,
        ],
        // Known foreign venues in SBBL to explicitly filter out
        'Druskininki' => [
            'venue' => 'Druskininkai Sports Center',
            'city' => 'Druskininki',
            'region' => 'Lietuva',
            'address' => null,
            'lat' => null,
            'lon' => null,
            'in_latvia' => false,
        ],
        'Kristiine Spordimaja' => [
            'venue' => 'Kristiine Spordimaja',
            'city' => 'Tallina',
            'region' => 'Igaunija',
            'address' => null,
            'lat' => null,
            'lon' => null,
            'in_latvia' => false,
        ],
        'Ring Sports & Saunad sports hall' => [
            'venue' => 'Ring Sport & Saunad',
            'city' => 'Tallina',
            'region' => 'Igaunija',
            'address' => null,
            'lat' => null,
            'lon' => null,
            'in_latvia' => false,
        ],
        'Savanoiriu sporto arena' => [
            'venue' => 'Savanorių sporto arena',
            'city' => 'Kauņa',
            'region' => 'Lietuva',
            'address' => null,
            'lat' => null,
            'lon' => null,
            'in_latvia' => false,
        ],
        'Tartu Ulikooli Spordihoone' => [
            'venue' => 'Tartu Ülikooli Spordihoone',
            'city' => 'Tartu',
            'region' => 'Igaunija',
            'address' => null,
            'lat' => null,
            'lon' => null,
            'in_latvia' => false,
        ],
        'Žalgiris Basketball academy' => [
            'venue' => 'Žalgiris Basketball Academy',
            'city' => 'Kauņa',
            'region' => 'Lietuva',
            'address' => null,
            'lat' => null,
            'lon' => null,
            'in_latvia' => false,
        ],
    ];

    /**
     * Team fallback city mapping for Latvian teams
     */
    protected array $teamCities = [
        'BK Bauska' => ['city' => 'Bauska', 'region' => 'Zemgale'],
        'BK Grobiņa' => ['city' => 'Grobiņa', 'region' => 'Kurzeme'],
        'BK Jelgava' => ['city' => 'Jelgava', 'region' => 'Zemgale'],
        'BK Liepāja/LSSS' => ['city' => 'Liepāja', 'region' => 'Kurzeme'],
        'BK Salaspils/API Mobile' => ['city' => 'Salaspils', 'region' => 'Rīga un Pierīga'],
        'BK Saldus' => ['city' => 'Saldus', 'region' => 'Kurzeme'],
        'BK Talsi' => ['city' => 'Talsi', 'region' => 'Kurzeme'],
        'BK Ķekava' => ['city' => 'Ķekava', 'region' => 'Rīga un Pierīga'],
        'BS Tukums/TSS' => ['city' => 'Tukums', 'region' => 'Kurzeme'],
        'Bertānu Valmieras BS' => ['city' => 'Valmiera', 'region' => 'Vidzeme'],
        'Gulbenes Buki/BJSS' => ['city' => 'Gulbene', 'region' => 'Vidzeme'],
        'Jēkabpils BA' => ['city' => 'Jēkabpils', 'region' => 'Latgale'],
        'Kandavas Anzāģe/Turība' => ['city' => 'Kandava', 'region' => 'Kurzeme'],
        'Mārupe/LU' => ['city' => 'Mārupe', 'region' => 'Rīga un Pierīga'],
        'OBS/BK Ogre' => ['city' => 'Ogre', 'region' => 'Vidzeme'],
        'OC Limbaži' => ['city' => 'Limbaži', 'region' => 'Vidzeme'],
        'RSU/VEF Rīga' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'Rīgas Basketbola skola' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'Rīgas Kadeti' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'Spars/Ventspils Augstskola' => ['city' => 'Ventspils', 'region' => 'Kurzeme'],
        'TTT Rīga Kadetes' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'RSU-2' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'Daugavpils Universitāte' => ['city' => 'Daugavpils', 'region' => 'Latgale'],
    ];

    public function scrape(Source $source): Collection
    {
        $allEvents = collect();

        foreach (self::LEAGUES as $leagueKey => $leagueConfig) {
            $events = $this->scrapeLeague($leagueKey, $leagueConfig);
            $allEvents = $allEvents->merge($events);
        }

        return $allEvents;
    }

    /**
     * Scrape fixtures for a single league
     */
    public function scrapeLeague(string $leagueKey, array $leagueConfig): Collection
    {
        $events = collect();
        $websiteId = $leagueConfig['website_id'];

        // Extract website-id dynamically from calendar page HTML, with fallback to config
        try {
            $html = $this->fetchHtml($leagueConfig['calendar_url']);
            if ($html && preg_match('/data-website-id=[\x22\x27](\d+)[\x22\x27]/', $html, $m)) {
                $websiteId = (int) $m[1];
            }
        } catch (\Throwable $e) {
            Log::warning("BasketLvScraper: could not fetch calendar page for {$leagueKey}, using default websiteId {$websiteId}: " . $e->getMessage());
        }

        $apiUrl = "https://embed-api.eui.connect.sportradar.com/v1/embed/{$websiteId}/fixtures";

        try {
            $json = $this->fetchJson($apiUrl);
            $fixtures = $json['data']['fixtures'] ?? [];

            foreach ($fixtures as $fixture) {
                $dto = $this->parseFixture($fixture, $leagueKey, $leagueConfig);
                if ($dto) {
                    $events->push($dto);
                }
            }
        } catch (\Throwable $e) {
            Log::error("BasketLvScraper: error scraping league {$leagueKey}: " . $e->getMessage());
        }

        return $events;
    }

    /**
     * Parse a single fixture array from the Sportradar Connect API
     */
    public function parseFixture(array $fixture, string $leagueKey, array $leagueConfig): ?ScrapedEventDTO
    {
        // 1. Check status - skip cancelled, postponed or finished
        $statusValue = $fixture['status']['value'] ?? '';
        if (in_array(strtoupper($statusValue), ['FINISHED', 'CANCELLED', 'POSTPONED'], true)) {
            return null;
        }

        // 2. Extract start time
        $timeStr = $fixture['startTimeLocal'] ?? $fixture['startTimeUTC'] ?? null;
        if (empty($timeStr)) {
            return null;
        }

        try {
            if (!empty($fixture['startTimeLocal'])) {
                $startAt = Carbon::parse($fixture['startTimeLocal'], 'Europe/Riga');
            } else {
                $utcStr = str_ends_with($fixture['startTimeUTC'], 'Z') ? $fixture['startTimeUTC'] : $fixture['startTimeUTC'] . 'Z';
                $startAt = Carbon::parse($utcStr)->setTimezone('Europe/Riga');
            }
        } catch (\Throwable $e) {
            return null;
        }

        // Rule: Do not ingest past events
        if ($startAt->lt(now()->startOfDay())) {
            return null;
        }

        // Basketball match duration ≈ 105 minutes
        $endAt = (clone $startAt)->addMinutes(105);

        // 3. Extract competitors
        $homeTeam = null;
        $awayTeam = null;
        foreach ($fixture['competitors'] ?? [] as $competitor) {
            if (!empty($competitor['isHome'])) {
                $homeTeam = trim($competitor['name'] ?? '');
            } else {
                $awayTeam = trim($competitor['name'] ?? '');
            }
        }

        if (empty($homeTeam) || empty($awayTeam)) {
            return null;
        }

        // 4. Resolve location
        $venueRaw = trim($fixture['venue'] ?? '');
        $location = $this->resolveLocation($venueRaw, $homeTeam);

        // Rule for SBBL: only include matches taking place in Latvia
        if (!empty($leagueConfig['only_latvia'])) {
            if (empty($location['in_latvia'])) {
                return null;
            }
        }

        // 5. Build titles and descriptions
        $leagueName = $leagueConfig['name'];
        $titleFormatted = "{$homeTeam} vs {$awayTeam}";
        $shortDescription = "{$leagueName}: {$homeTeam} pret {$awayTeam} ({$location['venue']}).";

        $descLines = [
            "{$leagueName} spēle: {$homeTeam} pret {$awayTeam}.",
            '',
            "Norises vieta: {$location['venue']}, {$location['city']}",
        ];
        if (!empty($location['address'])) {
            $descLines[] = "Adrese: {$location['address']}";
        }
        $description = implode("\n", $descLines);

        // 6. URLs and external ID
        $fixtureId = $fixture['fixtureId'] ?? substr(md5("{$startAt->toIso8601String()}_{$homeTeam}_{$awayTeam}"), 0, 12);
        $fixtureLink = $fixture['link'] ?? '';
        $calendarUrl = $leagueConfig['calendar_url'];
        if (!empty($fixtureLink)) {
            $sourceUrl = $calendarUrl . '?' . ltrim($fixtureLink, '&');
        } else {
            $sourceUrl = $calendarUrl;
        }

        $sourceExternalId = "basket_{$leagueKey}_{$fixtureId}";

        return new ScrapedEventDTO(
            title: $titleFormatted,
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
            ticketUrl: $sourceUrl,
            imageUrl: $leagueConfig['default_image'],
            sourceUrl: $sourceUrl,
            sourceExternalId: $sourceExternalId,
            locale: 'lv',
            rawData: [
                'league' => $leagueName,
                'league_key' => $leagueKey,
                'home_team' => $homeTeam,
                'away_team' => $awayTeam,
                'venue_raw' => $venueRaw,
                'fixture_id' => $fixtureId,
                'timezone' => $fixture['timezone'] ?? 'Europe/Riga',
            ]
        );
    }

    /**
     * Resolve arena location and coordinates
     */
    public function resolveLocation(string $venueRaw, string $homeTeam): array
    {
        $trimmed = trim($venueRaw);

        // 1. Direct or fuzzy substring match in known basketball venues
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
                'in_latvia' => true,
            ];
        }

        return [
            'venue' => !empty($trimmed) ? $trimmed : 'Basketbola zāle',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => null,
            'lat' => null,
            'lon' => null,
            'in_latvia' => false,
        ];
    }
}
