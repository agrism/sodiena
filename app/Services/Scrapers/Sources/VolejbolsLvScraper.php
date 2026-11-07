<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class VolejbolsLvScraper extends BaseScraper
{
    public const DEFAULT_IMAGE = 'https://sodiena.hel1.your-objectstorage.com/leagues/volejbols.webp';

    public function getSlug(): string
    {
        return 'volejbols-lv';
    }

    public function getName(): string
    {
        return 'Latvijas Volejbola Federācija (volejbols.lv)';
    }

    /**
     * Volleyball venue metadata mapping with verified OpenStreetMap coordinates and official addresses
     */
    protected array $venues = [
        'Cēsu Sporta komplekss' => [
            'venue' => 'Cēsu Sporta komplekss',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Piebalgas iela 18, Cēsis',
            'lat' => 57.30790,
            'lon' => 25.27820,
        ],
        'Aizputes Sporta centrs' => [
            'venue' => 'Aizputes Sporta centrs',
            'city' => 'Aizpute',
            'region' => 'Kurzeme',
            'address' => 'Ziedu iela 7B, Aizpute',
            'lat' => 56.71957,
            'lon' => 21.61044,
        ],
        'Rimi Olympic center' => [
            'venue' => 'Rimi Olimpiskais centrs',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Grostonas iela 6B, Rīga',
            'lat' => 56.96766,
            'lon' => 24.12464,
        ],
        'Valsts Robežsardzes sporta zāle' => [
            'venue' => 'Valsts Robežsardzes sporta zāle',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Andreja Pumpura iela 105B, Daugavpils',
            'lat' => 55.88376,
            'lon' => 26.53448,
        ],
        'Tartu Ülikooli SH' => [
            'venue' => 'Tartu Ülikooli Spordihoone',
            'city' => 'Tartu',
            'region' => 'Igaunija',
            'address' => 'Ujula 4, Tartu, Estonia',
            'lat' => 58.38831,
            'lon' => 26.72191,
        ],
        'Tuule Sportshall' => [
            'venue' => 'Tuule spordihoone (Jüri)',
            'city' => 'Jüri',
            'region' => 'Igaunija',
            'address' => 'Laste 3, Jüri, Rae vald, Estonia',
            'lat' => 59.35477,
            'lon' => 24.90806,
        ],
        'Audentese Sports Hall' => [
            'venue' => 'Audentese spordikeskus',
            'city' => 'Tallina',
            'region' => 'Igaunija',
            'address' => 'Tondi 84, Tallinn, Estonia',
            'lat' => 59.40460,
            'lon' => 24.71051,
        ],
        'Pārventas Sporta halle' => [
            'venue' => 'Pārventas Sporta halle',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Tārgales iela 5A, Ventspils',
            'lat' => 57.40466,
            'lon' => 21.59354,
        ],
        'Jelgavas novada SC' => [
            'venue' => 'Jelgavas novada Sporta centrs',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Aviācijas iela 8C, Jelgava',
            'lat' => 56.65524,
            'lon' => 23.75783,
        ],
        'Jēkabpils Sporta nams' => [
            'venue' => 'Jēkabpils sporta nams',
            'city' => 'Jēkabpils',
            'region' => 'Latgale',
            'address' => 'Brīvības iela 2J, Jēkabpils',
            'lat' => 56.48790,
            'lon' => 25.88816,
        ],
        'President Valdas Adamkus' => [
            'venue' => 'President Valdas Adamkus Sports Center (VDU)',
            'city' => 'Kauņa',
            'region' => 'Lietuva',
            'address' => 'Universiteto g. 8, Akademija, Kaunas, Lithuania',
            'lat' => 54.89228,
            'lon' => 23.82822,
        ],
        'RSU Sporta komplekss' => [
            'venue' => 'RSU Sporta kluba zāle',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Cigoriņu iela 3, Rīga',
            'lat' => 56.93010,
            'lon' => 24.08465,
        ],
        'RSU Sports Hall' => [
            'venue' => 'RSU Sporta kluba zāle',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Cigoriņu iela 3, Rīga',
            'lat' => 56.93010,
            'lon' => 24.08465,
        ],
        'Võru Spordikeskus' => [
            'venue' => 'Võru Spordikeskus',
            'city' => 'Veru',
            'region' => 'Igaunija',
            'address' => 'Räpina mnt 3a, Võru, Estonia',
            'lat' => 57.84489,
            'lon' => 27.00953,
        ],
        'Dubysa' => [
            'venue' => 'Sporto mokykla Dubysa',
            'city' => 'Šauļi',
            'region' => 'Lietuva',
            'address' => 'Gumbinės g. 18A, Šiauliai, Lithuania',
            'lat' => 55.92829,
            'lon' => 23.30340,
        ],
        'Tārgales pamatskolas Sporta zāle' => [
            'venue' => 'Tārgales pamatskolas sporta zāle',
            'city' => 'Tārgale',
            'region' => 'Kurzeme',
            'address' => 'Tārgale, Ventspils novads',
            'lat' => 57.33078,
            'lon' => 21.64458,
        ],
        'Liepājas OC Volejbola zāle' => [
            'venue' => 'Liepājas Olimpiskā centra volejbola zāle',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Brīvības iela 39, Liepāja',
            'lat' => 56.51906,
            'lon' => 21.01784,
        ],
        'Pärnu Spordihall' => [
            'venue' => 'Pärnu Spordihall',
            'city' => 'Pērnava',
            'region' => 'Igaunija',
            'address' => 'Riia mnt 129, Pärnu, Estonia',
            'lat' => 58.37650,
            'lon' => 24.53254,
        ],
        'TalTech Spordihall' => [
            'venue' => 'TalTech Spordihoone',
            'city' => 'Tallina',
            'region' => 'Igaunija',
            'address' => 'Männiliiva 7, Tallinn, Estonia',
            'lat' => 59.39360,
            'lon' => 24.67823,
        ],
        'Misas vidusskolas sp.zāle' => [
            'venue' => 'Misas pamatskolas sporta zāle',
            'city' => 'Misa',
            'region' => 'Zemgale',
            'address' => 'Saules iela 6, Misa, Bauskas novads',
            'lat' => 56.58968,
            'lon' => 24.39363,
        ],
        'Daugavpils BJSS SH' => [
            'venue' => 'Daugavpils BJSS sporta halle',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Kandavas iela 17A, Daugavpils',
            'lat' => 55.87523,
            'lon' => 26.51746,
        ],
        'Balvu sākumskola' => [
            'venue' => 'Balvu sākumskolas sporta zāle',
            'city' => 'Balvi',
            'region' => 'Latgale',
            'address' => 'Partizānu iela 16, Balvi',
            'lat' => 57.12994,
            'lon' => 27.26220,
        ],
        'Sējas Sporta centrs' => [
            'venue' => 'Sējas Sporta centrs',
            'city' => 'Sēja',
            'region' => 'Rīga un Pierīga',
            'address' => 'Loja, Sējas pagasts, Saulkrastu novads',
            'lat' => 57.17057,
            'lon' => 24.63266,
        ],
        'Sporta nams Taurenītis' => [
            'venue' => 'Sporta nams "Taurenītis"',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Kļavu iela 29/31, Dubulti, Jūrmala',
            'lat' => 56.96573,
            'lon' => 23.76742,
        ],
        'Rīgas Volejbola centrs' => [
            'venue' => 'Rīgas Volejbola centrs',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Ganību dambis 20A, Rīga',
            'lat' => 56.97522,
            'lon' => 24.11597,
        ],
        'Rīgas Volejbola skola' => [
            'venue' => 'Rīgas Volejbola skola',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Cieceres iela 9, Rīga',
            'lat' => 56.93220,
            'lon' => 24.07505,
        ],
        'Tīraines Sporta komplekss' => [
            'venue' => 'Tīraines Sporta komplekss',
            'city' => 'Tīraine',
            'region' => 'Rīga un Pierīga',
            'address' => 'Tīraine, Mārupes novads',
            'lat' => 56.88541,
            'lon' => 24.06578,
        ],
        'Daugavpils Sporta skola' => [
            'venue' => 'Daugavpils Sporta skolas halle',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Kandavas iela 17A, Daugavpils',
            'lat' => 55.87523,
            'lon' => 26.51746,
        ],
    ];

    /**
     * Team fallback city mapping
     */
    protected array $teamCities = [
        'Aizpute' => ['city' => 'Aizpute', 'region' => 'Kurzeme'],
        'Jelgavas novads / Evopipes' => ['city' => 'Jelgava', 'region' => 'Zemgale'],
        'VK Ventspils' => ['city' => 'Ventspils', 'region' => 'Kurzeme'],
        'RSU' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'RSU 2' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'Robežsardze / RSU' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'Jēkabpils Lūši' => ['city' => 'Jēkabpils', 'region' => 'Latgale'],
        'Jēkabpils juniori / JSS' => ['city' => 'Jēkabpils', 'region' => 'Latgale'],
        'Ezerzeme / DU / Homelike' => ['city' => 'Daugavpils', 'region' => 'Latgale'],
        'Augšdaugava / Ezerzeme' => ['city' => 'Daugavpils', 'region' => 'Latgale'],
        'Daugavpils Sporta skola' => ['city' => 'Daugavpils', 'region' => 'Latgale'],
        'Vecumnieki' => ['city' => 'Vecumnieki', 'region' => 'Zemgale'],
        'Liepājas sporta spēļu skola' => ['city' => 'Liepāja', 'region' => 'Kurzeme'],
        'Liepāja/LSSS' => ['city' => 'Liepāja', 'region' => 'Kurzeme'],
        'RVS/LU' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'Rīgas volejbola skola' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'Mārupe' => ['city' => 'Mārupe', 'region' => 'Rīga un Pierīga'],
        'VK Lazdukalns / Balvu novads' => ['city' => 'Balvi', 'region' => 'Latgale'],
        'Murjāņu Sporta ģimnāzija' => ['city' => 'Murjāņi', 'region' => 'Rīga un Pierīga'],
        'Jūrmalas volejbola klubs / Jūrmalas sporta skola' => ['city' => 'Jūrmala', 'region' => 'Rīga un Pierīga'],
        'Cēsu pilsētas Sporta skola' => ['city' => 'Cēsis', 'region' => 'Vidzeme'],
        'Valmieras sporta skola' => ['city' => 'Valmiera', 'region' => 'Vidzeme'],
        'VK Gulbene' => ['city' => 'Gulbene', 'region' => 'Vidzeme'],
        'Ogre' => ['city' => 'Ogre', 'region' => 'Vidzeme'],
        'SK Lauciene' => ['city' => 'Lauciene', 'region' => 'Kurzeme'],
        'VK Dunalka' => ['city' => 'Dunalka', 'region' => 'Kurzeme'],
        'TÜ/Bigbank' => ['city' => 'Tartu', 'region' => 'Igaunija'],
        'BIGBANK Tartu' => ['city' => 'Tartu', 'region' => 'Igaunija'],
        'Barrus Võru VK' => ['city' => 'Veru', 'region' => 'Igaunija'],
        'PÄRNU Võrkpalliklubi' => ['city' => 'Pērnava', 'region' => 'Igaunija'],
        'Selver x TalTech' => ['city' => 'Tallina', 'region' => 'Igaunija'],
        'Audentese SG / NT' => ['city' => 'Tallina', 'region' => 'Igaunija'],
        'Rae Spordikool/VIASTON' => ['city' => 'Jüri', 'region' => 'Igaunija'],
        'Elga-Grafaite S-Sportas Šiauliai' => ['city' => 'Šauļi', 'region' => 'Lietuva'],
        'VDU Kaunas' => ['city' => 'Kauņa', 'region' => 'Lietuva'],
    ];

    public function scrape(Source $source): Collection
    {
        $allEvents = collect();
        $subpageCache = [];

        $now = Carbon::now('Europe/Riga');
        $currentYear = (int) $now->format('Y');
        $currentMonth = (int) $now->format('m');
        $todayStr = $now->format('Y-m-d');

        // Determine months to inspect through the volleyball season
        $monthsToScrape = [];
        if ($currentMonth >= 7) {
            for ($m = $currentMonth; $m <= 12; $m++) {
                $monthsToScrape[] = [$currentYear, $m];
            }
            for ($m = 1; $m <= 6; $m++) {
                $monthsToScrape[] = [$currentYear + 1, $m];
            }
        } else {
            for ($m = $currentMonth; $m <= 6; $m++) {
                $monthsToScrape[] = [$currentYear, $m];
            }
        }

        foreach ($monthsToScrape as [$year, $month]) {
            $monthEvents = $this->scrapeMonth($year, $month, $todayStr, $subpageCache);
            $allEvents = $allEvents->merge($monthEvents);
        }

        return $allEvents;
    }

    /**
     * Scrape a specific year and month
     */
    public function scrapeMonth(int $year, int $month, string $todayStr, array &$subpageCache): Collection
    {
        $events = collect();
        $url = "https://volejbols.lv/wp-json/forget/v1/calendar/year-block/{$year}?month={$month}";

        try {
            $json = $this->fetchJson($url);
            if (!$json || empty($json['html'])) {
                return $events;
            }

            $crawler = new Crawler($json['html']);
            $items = $crawler->filter('.year-cal__list > li');

            $items->each(function (Crawler $li) use (&$events, &$subpageCache, $todayStr) {
                $dto = $this->parseMatch($li, $todayStr, $subpageCache);
                if ($dto) {
                    $events->push($dto);
                }
            });
        } catch (\Throwable $e) {
            Log::error("VolejbolsLvScraper: error scraping month {$year}-{$month}: " . $e->getMessage());
        }

        return $events;
    }

    /**
     * Parse a match element from the calendar list
     */
    public function parseMatch(Crawler $li, string $todayStr, array &$subpageCache): ?ScrapedEventDTO
    {
        $dateStr = $li->attr('data-date');
        if (empty($dateStr) || $dateStr < $todayStr) {
            return null; // Skip past dates
        }

        $a = $li->filter('a.year-cal-item');
        if (!$a->count()) {
            return null;
        }

        $title = trim($a->filter('.year-cal-item__title')->text());
        $cleanTitle = trim(preg_replace('/\s+/', ' ', $title));

        // Rule: Only process matches starting with "Elme Messer", "Latvijas čempionāts", or "LČ Nacionālā līga"
        if (!preg_match('/^(?:Elme Messer|Latvijas čempionāts|LČ Nacionālā līga)/iu', $cleanTitle)) {
            return null;
        }

        $timeStr = $a->filter('.year-cal-item__time')->count() ? trim($a->filter('.year-cal-item__time')->text()) : '00:00';
        $time = preg_match('/^\d{1,2}:\d{2}$/', $timeStr) ? $timeStr : '00:00';

        try {
            $startAt = Carbon::createFromFormat('Y-m-d H:i', "{$dateStr} {$time}", 'Europe/Riga');
        } catch (\Throwable $e) {
            return null;
        }

        // Rule: do not ingest past events
        if ($startAt->lt(now()->startOfDay())) {
            return null;
        }

        // Volleyball match duration ≈ 105 minutes
        $endAt = (clone $startAt)->addMinutes(105);

        $teamsStr = $a->filter('.year-cal-item__teams')->count() ? trim($a->filter('.year-cal-item__teams')->text()) : '';
        $teamParts = preg_split('/\s*[–—-]\s*/u', $teamsStr);
        $home = trim($teamParts[0] ?? '');
        $away = trim($teamParts[1] ?? '');

        $matchUrl = $a->attr('href');
        if (!str_starts_with($matchUrl, 'http')) {
            $matchUrl = 'https://volejbols.lv' . $matchUrl;
        }

        // Fetch subpage details (venue, JSON-LD, score)
        $subpageDetails = $this->fetchSubpageDetails($matchUrl, $subpageCache);

        // Skip if match has already been played
        if (!empty($subpageDetails['is_played'])) {
            return null;
        }

        if (!empty($subpageDetails['home'])) {
            $home = $subpageDetails['home'];
        }
        if (!empty($subpageDetails['away'])) {
            $away = $subpageDetails['away'];
        }
        if (!empty($subpageDetails['league'])) {
            $cleanTitle = $subpageDetails['league'];
        }

        if (empty($home) || empty($away)) {
            return null;
        }

        $venueRaw = $subpageDetails['venue'] ?? '';
        $location = $this->resolveLocation($venueRaw, $home);

        // External ID extraction
        if (preg_match('/\/bvl\/spele\/(\d+)/', $matchUrl, $im)) {
            $sourceExternalId = "lvf_bvl_{$im[1]}";
        } elseif (preg_match('/\/spele\/(\d+)/', $matchUrl, $im)) {
            $sourceExternalId = "lvf_{$im[1]}";
        } else {
            $sourceExternalId = 'lvf_' . substr(md5("{$dateStr}_{$timeStr}_{$home}_{$away}"), 0, 10);
        }

        $titleFormatted = "{$home} vs {$away}";
        $shortDescription = "{$cleanTitle}: {$home} pret {$away} ({$location['venue']}).";

        $descLines = [
            "{$cleanTitle} spēle: {$home} pret {$away}.",
            '',
            "Norises vieta: {$location['venue']}, {$location['city']}",
        ];
        if (!empty($location['address'])) {
            $descLines[] = "Adrese: {$location['address']}";
        }
        $description = implode("\n", $descLines);

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
            ticketUrl: $matchUrl,
            imageUrl: self::DEFAULT_IMAGE,
            sourceUrl: $matchUrl,
            sourceExternalId: $sourceExternalId,
            locale: 'lv',
            rawData: [
                'league' => $cleanTitle,
                'home_team' => $home,
                'away_team' => $away,
                'venue_raw' => $venueRaw,
                'date_raw' => $dateStr,
                'time_raw' => $timeStr,
            ]
        );
    }

    /**
     * Fetch match subpage details with in-memory caching
     */
    public function fetchSubpageDetails(string $subpageUrl, array &$subpageCache): array
    {
        if (isset($subpageCache[$subpageUrl])) {
            return $subpageCache[$subpageUrl];
        }

        $details = [
            'venue' => '',
            'home' => '',
            'away' => '',
            'league' => '',
            'is_played' => false,
        ];

        try {
            $crawler = $this->fetchCrawler($subpageUrl);
            if ($crawler) {
                // 1. Direct venue element
                if ($crawler->filter('.game-card__venue')->count()) {
                    $details['venue'] = trim($crawler->filter('.game-card__venue')->text());
                }

                // 2. Check if played from score element
                if ($crawler->filter('.game-card__score')->count()) {
                    $scoreEl = $crawler->filter('.game-card__score')->first();
                    $scoreClass = $scoreEl->attr('class') ?? '';
                    $scoreText = trim($scoreEl->text());
                    if (!str_contains($scoreClass, 'game-card__score--empty') && preg_match('/\d+/', $scoreText)) {
                        $details['is_played'] = true;
                    }
                }

                // 3. Structured data from JSON-LD
                $crawler->filter('script[type="application/ld+json"]')->each(function (Crawler $s) use (&$details) {
                    $data = json_decode($s->text(), true);
                    if (!is_array($data)) {
                        return;
                    }
                    if (empty($details['venue']) && !empty($data['location']['name'])) {
                        $details['venue'] = trim($data['location']['name']);
                    }
                    if (!empty($data['homeTeam']['name'])) {
                        $details['home'] = trim($data['homeTeam']['name']);
                    }
                    if (!empty($data['awayTeam']['name'])) {
                        $details['away'] = trim($data['awayTeam']['name']);
                    }
                    if (!empty($data['superEvent']['name'])) {
                        $details['league'] = trim($data['superEvent']['name']);
                    }
                });
            }
        } catch (\Throwable $e) {
            Log::warning("VolejbolsLvScraper: failed to fetch subpage {$subpageUrl}: " . $e->getMessage());
        }

        $subpageCache[$subpageUrl] = $details;
        return $details;
    }

    /**
     * Resolve arena location and coordinates
     */
    public function resolveLocation(string $venueRaw, string $homeTeam): array
    {
        $trimmed = trim($venueRaw);

        // 1. Direct or fuzzy substring match in known volleyball venues
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
            'venue' => !empty($trimmed) ? $trimmed : 'Volejbola zāle',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => null,
            'lat' => null,
            'lon' => null,
        ];
    }
}

