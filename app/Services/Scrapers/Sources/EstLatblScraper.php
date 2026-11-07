<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\DomCrawler\Crawler;

class EstLatblScraper extends BaseScraper
{
    public const DEFAULT_IMAGE = 'https://sodiena.hel1.your-objectstorage.com/leagues/estlatbl.webp';

    public function getSlug(): string
    {
        return 'estlatbl';
    }

    public function getName(): string
    {
        return 'Optibet Latvijas - Igaunijas Basketbola Līga';
    }

    /**
     * Arena metadata mapping with verified OpenStreetMap coordinates and official addresses
     */
    protected array $arenas = [
        'Daugavas sporta nams' => [
            'venue' => 'Daugavas sporta nams',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Krišjāņa Barona iela 107, Rīga',
            'lat' => 56.96362,
            'lon' => 24.14304,
        ],
        'Komandas Sporta Spelu Halle' => [
            'venue' => 'Komandu sporta spēļu zāle',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Krišjāņa Barona iela 99C, Rīga',
            'lat' => 56.96352,
            'lon' => 24.14134,
        ],
        'O. Kalpaka RTDP' => [
            'venue' => 'O. Kalpaka RTDP sporta halle',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Skrindu iela 1, Rīga',
            'lat' => 56.94498,
            'lon' => 24.13124,
        ],
        'Rimi Olimpiskais centrs' => [
            'venue' => 'Rimi Olimpiskais centrs',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Grostonas iela 6B, Rīga',
            'lat' => 56.96766,
            'lon' => 24.12464,
        ],
        'Xiaomi Arena' => [
            'venue' => 'Arēna Rīga',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Skanstes iela 21, Rīga',
            'lat' => 56.96786,
            'lon' => 24.12127,
        ],
        'Sporta arena Ogre' => [
            'venue' => 'Sporta arēna Ogre',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Gunāra Astras iela 1, Ogre',
            'lat' => 56.82441,
            'lon' => 24.60866,
        ],
        'Olimpiskais centrs "Ventspils"' => [
            'venue' => 'Olimpiskais centrs "Ventspils"',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Sporta iela 7/9, Ventspils',
            'lat' => 57.38669,
            'lon' => 21.57104,
        ],
        'Valmieras Olimpiskais Centrs' => [
            'venue' => 'Valmieras Olimpiskais centrs',
            'city' => 'Valmiera',
            'region' => 'Vidzeme',
            'address' => 'Čempionu iela 2, Valmiera',
            'lat' => 57.52812,
            'lon' => 25.38789,
        ],
        'Liepajas Olimpiskais centrs' => [
            'venue' => 'Liepājas Olimpiskais centrs',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Brīvības iela 39, Liepāja',
            'lat' => 56.51906,
            'lon' => 21.01784,
        ],
        'Marupe SC' => [
            'venue' => 'Mārupes Sporta centrs',
            'city' => 'Mārupe',
            'region' => 'Rīga un Pierīga',
            'address' => 'Kantora iela 97, Mārupe',
            'lat' => 56.91958,
            'lon' => 24.03668,
        ],
        'Saldus Sporta Nams' => [
            'venue' => 'Saldus sporta nams',
            'city' => 'Saldus',
            'region' => 'Kurzeme',
            'address' => 'Jelgavas iela 6, Saldus',
            'lat' => 56.66825,
            'lon' => 22.49929,
        ],
        'Jurmalas valsts gimnazijas sporta zale' => [
            'venue' => 'Jūrmalas Valsts ģimnāzijas sporta zāle',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Raiņa iela 55, Jūrmala',
            'lat' => 56.95317,
            'lon' => 23.61049,
        ],
        'Cesis SK' => [
            'venue' => 'Cēsu Sporta komplekss',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Piebalgas iela 18, Cēsis',
            'lat' => 57.30790,
            'lon' => 25.27820,
        ],
        'Gulbenes Sporta Nams' => [
            'venue' => 'Gulbenes Sporta centrs',
            'city' => 'Gulbene',
            'region' => 'Vidzeme',
            'address' => 'Skolas iela 12, Gulbene',
            'lat' => 57.17115,
            'lon' => 26.76888,
        ],
        'Olympic Center Rezekne' => [
            'venue' => 'Olimpiskais centrs Rēzekne',
            'city' => 'Rēzekne',
            'region' => 'Latgale',
            'address' => 'Stacijas iela 30, Rēzekne',
            'lat' => 56.51433,
            'lon' => 27.35200,
        ],
        // Igaunijas arēnas
        'Keila Tervisekeskus' => [
            'venue' => 'Keila Tervisekeskus',
            'city' => 'Keila',
            'region' => 'Igaunija',
            'address' => 'Paldiski mnt 17, Keila, Igaunija',
            'lat' => 59.31469,
            'lon' => 24.41182,
        ],
        'Tartu, TÜ sph (Kullami peaväljak)' => [
            'venue' => 'Tartu Ülikooli spordihoone',
            'city' => 'Tartu',
            'region' => 'Igaunija',
            'address' => 'Ujula 4, Tartu, Igaunija',
            'lat' => 58.38831,
            'lon' => 26.72191,
        ],
        'Pärnu Spordihall' => [
            'venue' => 'Pärnu Spordihall',
            'city' => 'Pērnava',
            'region' => 'Igaunija',
            'address' => 'Riia mnt 129, Pärnu, Igaunija',
            'lat' => 58.37650,
            'lon' => 24.53254,
        ],
        'Forus Spordikeskus Viimsi' => [
            'venue' => 'Forus Spordikeskus Viimsi',
            'city' => 'Viimsi',
            'region' => 'Igaunija',
            'address' => 'Karulaugu tee 13, Haabneeme, Igaunija',
            'lat' => 59.50928,
            'lon' => 24.83599,
        ],
        'Tallinn, TalTech Spordihoone' => [
            'venue' => 'TalTech Spordihoone',
            'city' => 'Tallina',
            'region' => 'Igaunija',
            'address' => 'Männiliiva 7, Tallinn, Igaunija',
            'lat' => 59.39360,
            'lon' => 24.67823,
        ],
        'Nord Spordihoone' => [
            'venue' => 'Nord Spordihoone',
            'city' => 'Tallina',
            'region' => 'Igaunija',
            'address' => 'Tervise 21, Tallinn, Igaunija',
            'lat' => 59.39507,
            'lon' => 24.70054,
        ],
    ];

    /**
     * Team fallback city mapping (if arena is unspecified)
     */
    protected array $teamCities = [
        'VEF Rīga' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'Rīgas Zeļļi' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'Rigas Ekselences Juniori' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'Latvijas Universitāte' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'BK Ventspils' => ['city' => 'Ventspils', 'region' => 'Kurzeme'],
        'BK Liepaja' => ['city' => 'Liepāja', 'region' => 'Kurzeme'],
        'BK Liepāja' => ['city' => 'Liepāja', 'region' => 'Kurzeme'],
        'BK Ogre' => ['city' => 'Ogre', 'region' => 'Vidzeme'],
        'Valmiera Glass VIA' => ['city' => 'Valmiera', 'region' => 'Vidzeme'],
        'BC Kalev/Cramo' => ['city' => 'Tallina', 'region' => 'Igaunija'],
        'Bigbank/Kalev' => ['city' => 'Tallina', 'region' => 'Igaunija'],
        'TalTech/ALEXELA' => ['city' => 'Tallina', 'region' => 'Igaunija'],
        'Tartu Ülikool Maks & Moorits' => ['city' => 'Tartu', 'region' => 'Igaunija'],
        'BC Pärnu' => ['city' => 'Pērnava', 'region' => 'Igaunija'],
        'Keila Coolbet' => ['city' => 'Keila', 'region' => 'Igaunija'],
        'Keila KK' => ['city' => 'Keila', 'region' => 'Igaunija'],
        'Viimsi' => ['city' => 'Viimsi', 'region' => 'Igaunija'],
    ];

    public function scrape(Source $source): Collection
    {
        $url = $source->url ?: 'https://www.estlatbl.com/lv/game-center';
        $crawler = $this->fetchCrawler($url);

        if (!$crawler) {
            return collect();
        }

        $events = collect();
        $rows = $crawler->filter('table.scheduleAndResults tbody tr');

        $rows->each(function (Crawler $row) use (&$events, $url) {
            $dto = $this->parseRow($row, $url);
            if ($dto) {
                $events->push($dto);
            }
        });

        return $events;
    }

    public function parseRow(Crawler $row, string $baseUrl): ?ScrapedEventDTO
    {
        $dateText = trim($row->filter('.dateAndTime')->count() ? $row->filter('.dateAndTime')->text() : '');
        if (!preg_match('/(\d{2}\.\d{2}\.\d{4}),?\s+(\d{2}:\d{2})/', $dateText, $matches)) {
            return null;
        }

        try {
            $startAt = Carbon::createFromFormat('d.m.Y H:i', "{$matches[1]} {$matches[2]}", 'Europe/Riga');
        } catch (\Throwable $e) {
            return null;
        }

        // Rule: do not ingest past events
        if ($startAt->lt(now()->startOfDay())) {
            return null;
        }

        $endAt = (clone $startAt)->addMinutes(105);

        $home = trim($row->filter('.homeTeamNameDesktop')->count() ? $row->filter('.homeTeamNameDesktop')->text() : '');
        $away = trim($row->filter('.visitorTeamNameDesktop')->count() ? $row->filter('.visitorTeamNameDesktop')->text() : '');

        if (empty($home) || empty($away)) {
            return null;
        }

        $arenaRaw = trim($row->filter('.arena')->count() ? $row->filter('.arena')->text() : '');
        $location = $this->resolveLocation($arenaRaw, $home);

        $homeLogo = $row->filter('.homeTeam .logo img')->count() ? $row->filter('.homeTeam .logo img')->attr('src') : null;
        $awayLogo = $row->filter('.awayTeam .logo img')->count() ? $row->filter('.awayTeam .logo img')->attr('src') : null;
        $imageUrl = self::DEFAULT_IMAGE;

        $broadcasts = [];
        $row->filter('.broadcast img')->each(function (Crawler $img) use (&$broadcasts) {
            $title = trim($img->attr('title') ?? '');
            if ($title && !in_array($title, $broadcasts, true)) {
                $broadcasts[] = $title;
            }
        });

        // Game link & ID from onclick
        $td = $row->filter('.dateAndTimeTd');
        $onclick = $td->count() ? $td->attr('onclick') : '';
        preg_match('/gid=(\d+)/', $onclick, $gidMatches);
        $gid = $gidMatches[1] ?? null;

        $gameUrl = $gid ? "https://www.estlatbl.com/lv/game-center?gid={$gid}" : $baseUrl;

        $title = "{$home} vs {$away}";
        $shortDescription = "Optibet Latvijas - Igaunijas Basketbola līga: {$home} pret {$away} ({$location['venue']}).";

        $descLines = [
            "Optibet Latvijas - Igaunijas Basketbola līgas spēle: {$home} pret {$away}.",
            "",
            "Norises vieta: {$location['venue']}, {$location['city']}",
        ];
        if (!empty($location['address'])) {
            $descLines[] = "Adrese: {$location['address']}";
        }
        if (!empty($broadcasts)) {
            $descLines[] = "Tiešraide: " . implode(', ', $broadcasts);
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
            imageUrl: $imageUrl,
            sourceUrl: $gameUrl,
            sourceExternalId: $gid ? (string) $gid : null,
            locale: 'lv',
            rawData: [
                'gid' => $gid,
                'home_team' => $home,
                'away_team' => $away,
                'arena_raw' => $arenaRaw,
                'broadcasts' => $broadcasts,
                'home_logo' => $homeLogo,
                'away_logo' => $awayLogo,
            ]
        );
    }

    public function resolveLocation(string $arenaRaw, string $homeTeam): array
    {
        $trimmed = trim($arenaRaw);

        if (!empty($trimmed) && isset($this->arenas[$trimmed])) {
            return $this->arenas[$trimmed];
        }

        // Partial match in arena map
        foreach ($this->arenas as $name => $data) {
            if (!empty($trimmed) && (stripos($trimmed, $name) !== false || stripos($name, $trimmed) !== false)) {
                return $data;
            }
        }

        // Fallback using home team
        if (isset($this->teamCities[$homeTeam])) {
            $teamInfo = $this->teamCities[$homeTeam];
            return [
                'venue' => (!empty($trimmed) && !str_contains($trimmed, 'koht')) ? $trimmed : "{$homeTeam} mājas arēna",
                'city' => $teamInfo['city'],
                'region' => $teamInfo['region'],
                'address' => null,
                'lat' => null,
                'lon' => null,
            ];
        }

        return [
            'venue' => !empty($trimmed) ? $trimmed : 'Sporta arēna',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => null,
            'lat' => null,
            'lon' => null,
        ];
    }
}

