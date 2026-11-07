<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class LhfHokejsScraper extends BaseScraper
{
    public const DEFAULT_IMAGE = 'https://sodiena.hel1.your-objectstorage.com/leagues/hokejs_ohl.webp';
    public const DEFAULT_IMAGE_OHL = 'https://sodiena.hel1.your-objectstorage.com/leagues/hokejs_ohl.webp';
    public const DEFAULT_IMAGE_SIEVĪTES = 'https://sodiena.hel1.your-objectstorage.com/leagues/hokejs_sievietes.webp';

    public function getSlug(): string
    {
        return 'lhf-hokejs';
    }

    public function getName(): string
    {
        return 'Latvijas Hokeja Federācija (lhf.lv)';
    }

    /**
     * Supported hockey leagues configuration
     */
    protected array $groups = [
        'ohl' => [
            'name' => 'Optibet Hokeja Līga (OHL)',
            'image' => self::DEFAULT_IMAGE_OHL,
            'url' => 'https://www.lhf.lv/lv/turniri/optibet-hokeja-liga/speles',
        ],
        'sievietes' => [
            'name' => 'Baltijas un Latvijas Čempionāts sievietēm',
            'image' => self::DEFAULT_IMAGE_SIEVĪTES,
            'url' => 'https://www.lhf.lv/lv/turniri/baltijas-un-latvijas-cempionats-sievietem/speles',
        ],
    ];

    /**
     * Hockey arena metadata mapping with verified OpenStreetMap coordinates and official addresses
     */
    protected array $venues = [
        'Volvo SC ledus halle' => [
            'venue' => 'Volvo sporta centrs',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Jūrmalas gatve 78D, Rīga',
            'lat' => 56.95133,
            'lon' => 24.00691,
        ],
        'Jelgavas ledus halle' => [
            'venue' => 'Jelgavas ledus halle',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Rīgas iela 11, Jelgava',
            'lat' => 56.66069,
            'lon' => 23.74347,
        ],
        'Mogo ledus halle' => [
            'venue' => 'Mogo ledus halle',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Brīvības gatve 335, Rīga',
            'lat' => 56.98621,
            'lon' => 24.20868,
        ],
        'Daugavpils ledus halle' => [
            'venue' => 'Daugavpils ledus halle',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Stadiona iela 45B, Daugavpils',
            'lat' => 55.87812,
            'lon' => 26.51797,
        ],
        'Liepājas OC ledus halle' => [
            'venue' => 'Liepājas Olimpiskā centra ledus halle',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Brīvības iela 3/9, Liepāja',
            'lat' => 56.52297,
            'lon' => 21.02459,
        ],
        'Inbox.LV ledus halle' => [
            'venue' => 'Inbox.lv ledus halle',
            'city' => 'Piņķi',
            'region' => 'Rīga un Pierīga',
            'address' => 'Hokeja iela 1, Piņķi',
            'lat' => 56.95541,
            'lon' => 23.91157,
        ],
        'Rīgas Hokeja skolas ledus halle' => [
            'venue' => 'Rīgas Hokeja skolas ledus halle',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Vietalvas iela 15 k-1, Rīga',
            'lat' => 56.94557,
            'lon' => 24.16943,
        ],
        'Kurbads ledus halle' => [
            'venue' => 'Kurbads ledus halle',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Rumbulas iela 19, Rīga',
            'lat' => 56.94350,
            'lon' => 24.17796,
        ],
        'T/c Akropole slidotava' => [
            'venue' => 'Akropole Rīga ledus arēna',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Latgales iela 257, Rīga',
            'lat' => 56.92397,
            'lon' => 24.17516,
        ],
        'Tukuma ledus halle' => [
            'venue' => 'Tukuma ledus halle',
            'city' => 'Tukums',
            'region' => 'Kurzeme',
            'address' => 'Stadiona iela 3, Tukums',
            'lat' => 56.96635,
            'lon' => 23.15770,
        ],
        'Daugavas ledus halle' => [
            'venue' => 'Daugavas ledus halle',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Augšiela 1, Rīga',
            'lat' => 56.95471,
            'lon' => 24.15606,
        ],
        'Vilniaus pramogų arena' => [
            'venue' => 'Vilniaus pramogų arena',
            'city' => 'Viļņa',
            'region' => 'Lietuva',
            'address' => 'Ąžuolyno g. 9, Vilnius, Lithuania',
            'lat' => 54.70555,
            'lon' => 25.23637,
        ],
        'Elektrenai ice arena' => [
            'venue' => 'Elektrėnų ledo rūmai',
            'city' => 'Elektrēni',
            'region' => 'Lietuva',
            'address' => 'Draugystės g. 24, Elektrėnai, Lithuania',
            'lat' => 54.78444,
            'lon' => 24.66944,
        ],
        'Klaipēda' => [
            'venue' => 'Klaipėdos ledo arena (Akropolis)',
            'city' => 'Klaipēda',
            'region' => 'Lietuva',
            'address' => 'Taikos pr. 61, Klaipēda, Lithuania',
            'lat' => 55.69283,
            'lon' => 21.15657,
        ],
        'Tallina, Igaunija' => [
            'venue' => 'Škoda Jäähall (Tallinn)',
            'city' => 'Tallina',
            'region' => 'Igaunija',
            'address' => 'Haabersti 3, Tallinn, Estonia',
            'lat' => 59.42614,
            'lon' => 24.64441,
        ],
        'Ozo ledus halle' => [
            'venue' => 'OZO ledus halle',
            'city' => 'Ozolnieki',
            'region' => 'Zemgale',
            'address' => 'Stadiona iela 5B, Ozolnieki',
            'lat' => 56.69117,
            'lon' => 23.78960,
        ],
        'Xiaomi Arēna' => [
            'venue' => 'Xiaomi Arēna (Arēna Rīga)',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Skanstes iela 21, Rīga',
            'lat' => 56.96786,
            'lon' => 24.12127,
        ],
        'SCHWENK Brocēnu ledus halle' => [
            'venue' => 'Brocēnu ledus halle',
            'city' => 'Brocēni',
            'region' => 'Kurzeme',
            'address' => 'Ezera iela 1, Brocēni',
            'lat' => 56.68448,
            'lon' => 22.56251,
        ],
        'Mārupes Ledus halle' => [
            'venue' => 'Mārupes Ledus halle',
            'city' => 'Mārupe',
            'region' => 'Rīga un Pierīga',
            'address' => 'Kantora iela 128, Mārupe',
            'lat' => 56.91648,
            'lon' => 24.02255,
        ],
        'Olaines slidotava' => [
            'venue' => 'Olaines slidotava',
            'city' => 'Olaine',
            'region' => 'Rīga un Pierīga',
            'address' => 'Zeiferta iela 4, Olaine',
            'lat' => 56.78824,
            'lon' => 23.93870,
        ],
        'Rēzeknes OC ledus halle' => [
            'venue' => 'Rēzeknes Olimpiskā centra ledus halle',
            'city' => 'Rēzekne',
            'region' => 'Latgale',
            'address' => 'Stacijas iela 30, Rēzekne',
            'lat' => 56.51433,
            'lon' => 27.35200,
        ],
        'Jēkabpils sporta halle' => [
            'venue' => 'Jēkabpils sporta halle',
            'city' => 'Jēkabpils',
            'region' => 'Latgale',
            'address' => 'Brīvības iela 289B, Jēkabpils',
            'lat' => 56.49897,
            'lon' => 25.84116,
        ],
        'Valmieras OC ledus halle' => [
            'venue' => 'Valmieras Olimpiskā centra ledus halle',
            'city' => 'Valmiera',
            'region' => 'Vidzeme',
            'address' => 'Čempionu iela 4, Valmiera',
            'lat' => 57.53874,
            'lon' => 25.44318,
        ],
        'Ventspils OC ledus halle' => [
            'venue' => 'Ventspils Olimpiskā centra ledus halle',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Sporta iela 7/9, Ventspils',
            'lat' => 57.39121,
            'lon' => 21.56448,
        ],
        'Talsu hokeja halle' => [
            'venue' => 'Talsu ledus halle',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'Dundagas iela 34, Talsi',
            'lat' => 57.25556,
            'lon' => 22.59750,
        ],
        'Vidzemes Ledus halle' => [
            'venue' => 'Vidzemes ledus halle',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Meža prospekts 3, Ogre',
            'lat' => 56.81972,
            'lon' => 24.61417,
        ],
    ];

    /**
     * Team fallback city mapping
     */
    protected array $teamCities = [
        'HK MOGO/RSU' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'HK Prizma/RTU' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'HK Zemgale/LBTU' => ['city' => 'Jelgava', 'region' => 'Zemgale'],
        'Liepājas hokeja komanda' => ['city' => 'Liepāja', 'region' => 'Kurzeme'],
        'RĪGAS HS/DINABURGA' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'HC Panter' => ['city' => 'Tallina', 'region' => 'Igaunija'],
        'HC Hockey Punks-Mototoja' => ['city' => 'Viļņa', 'region' => 'Lietuva'],
        'HC Energija' => ['city' => 'Elektrēni', 'region' => 'Lietuva'],
        'HC Kyiv Capitals' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'HC Klaipėda Girls' => ['city' => 'Klaipēda', 'region' => 'Lietuva'],
        'HS Kurbads' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'Laima' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'SHK L&L Hope' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'SHK Tukums' => ['city' => 'Tukums', 'region' => 'Kurzeme'],
        'SOSTINE HOCKEY' => ['city' => 'Viļņa', 'region' => 'Lietuva'],
    ];

    public function scrape(Source $source): Collection
    {
        // 1. Determine groups to scrape
        $groupsToScrape = $this->groups;

        if (!empty($source->url)) {
            if (preg_match('/[?&]group=([a-zA-Z0-9_-]+)/', $source->url, $gm)) {
                $specifiedGroup = $gm[1];
                foreach ($this->groups as $grpKey => $meta) {
                    if (strcasecmp($grpKey, $specifiedGroup) === 0) {
                        $groupsToScrape = [$grpKey => $meta];
                        break;
                    }
                }
            } elseif (str_contains($source->url, 'baltijas-un-latvijas-cempionats-sievietem')) {
                $groupsToScrape = ['sievietes' => $this->groups['sievietes']];
            }
        }

        $allEvents = collect();

        foreach ($groupsToScrape as $grp => $meta) {
            $groupEvents = $this->scrapeGroup($grp, $meta);
            $allEvents = $allEvents->merge($groupEvents);
        }

        return $allEvents;
    }

    /**
     * Scrape a specific group (OHL or sievietes)
     */
    public function scrapeGroup(string $grp, array $meta): Collection
    {
        $events = collect();
        $crawler = $this->fetchCrawler($meta['url']);

        if (!$crawler) {
            Log::error("LhfHokejsScraper: failed to fetch crawler for {$grp} at {$meta['url']}");
            return $events;
        }

        $rows = $crawler->filter('table.game_schedule tbody tr');
        $rows->each(function (Crawler $row) use (&$events, $grp, $meta) {
            $dto = $this->parseRow($row, $grp, $meta);
            if ($dto) {
                $events->push($dto);
            }
        });

        return $events;
    }

    /**
     * Parse a match row from table.game_schedule
     */
    public function parseRow(Crawler $row, string $grp, array $meta): ?ScrapedEventDTO
    {
        $cols = $row->filter('td');
        if ($cols->count() < 7) {
            return null;
        }

        // Check if game has already been played (score column at index 8)
        $score = $cols->count() > 8 ? trim($cols->eq(8)->text()) : '';
        if (!empty($score) && preg_match('/\d+\s*:\s*\d+/', $score)) {
            return null;
        }

        // Match number
        $matchNo = ltrim(trim($cols->eq(0)->text()), '#');

        // Date and time
        $dateStr = trim($cols->eq(5)->text());
        $timeStr = trim($cols->eq(6)->text());
        $time = preg_match('/^\d{1,2}:\d{2}$/', $timeStr) ? $timeStr : '00:00';
        $isTimeTbd = (strtoupper($timeStr) === 'TP' || $time === '00:00');

        try {
            $startAt = Carbon::createFromFormat('d.m.Y H:i', "{$dateStr} {$time}", 'Europe/Riga');
        } catch (\Throwable $e) {
            return null;
        }

        // Rule: do not ingest past events
        if ($startAt->lt(now()->startOfDay())) {
            return null;
        }

        // Hockey match duration ≈ 135 minutes (3 periods of 20 min + breaks + stoppages)
        $endAt = (clone $startAt)->addMinutes(135);

        // Teams
        $home = trim($cols->eq(2)->text());
        $away = trim($cols->eq(3)->text());
        if (empty($home) || empty($away)) {
            return null;
        }

        // Venue
        $venueRaw = trim($cols->eq(4)->text());
        $location = $this->resolveLocation($venueRaw, $home);

        // Links & External ID
        $gameUrl = $meta['url'];
        $streamUrl = null;
        $externalId = null;

        if ($cols->count() > 9) {
            $linkElements = $cols->eq(9)->filter('a');
            $linkElements->each(function (Crawler $a) use (&$gameUrl, &$streamUrl, &$externalId) {
                $href = $a->attr('href') ?? '';
                if (str_contains($href, 'latvijashokejs.tv')) {
                    $streamUrl = $href;
                } elseif (str_contains($href, '/speles/') || str_contains($href, 'lhf.lv')) {
                    $gameUrl = str_starts_with($href, 'http') ? $href : 'https://www.lhf.lv' . $href;
                    if (preg_match('/\/(\d+)$/', $href, $im)) {
                        $externalId = $im[1];
                    }
                }
            });
        }

        $sourceExternalId = $externalId
            ? "lhf_{$grp}_{$externalId}"
            : "lhf_{$grp}_" . substr(md5("{$dateStr}_{$timeStr}_{$home}_{$away}"), 0, 10);

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
        if ($isTimeTbd) {
            $descLines[] = "Piezīme: Spēles sākuma laiks tiks precizēts.";
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
                'venue_raw' => $venueRaw,
                'date_raw' => $dateStr,
                'time_raw' => $timeStr,
                'match_number' => $matchNo,
                'stream_url' => $streamUrl,
            ]
        );
    }

    /**
     * Resolve arena location and coordinates
     */
    public function resolveLocation(string $venueRaw, string $homeTeam): array
    {
        $trimmed = trim($venueRaw);

        // 1. Direct or fuzzy substring match in known hockey venues
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
                'venue' => !empty($trimmed) ? $trimmed : "{$homeTeam} mājas halle",
                'city' => $teamInfo['city'],
                'region' => $teamInfo['region'],
                'address' => null,
                'lat' => null,
                'lon' => null,
            ];
        }

        return [
            'venue' => !empty($trimmed) ? $trimmed : 'Hokeja halle',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => null,
            'lat' => null,
            'lon' => null,
        ];
    }
}
