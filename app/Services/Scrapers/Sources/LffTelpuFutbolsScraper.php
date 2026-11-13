<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\DomCrawler\Crawler;

class LffTelpuFutbolsScraper extends BaseScraper
{
    public const DEFAULT_IMAGE = 'https://lff.lv/files/images/_resized/0000004721_1050_624_cut.jpg';

    public function getSlug(): string
    {
        return 'lff-telpu-futbols';
    }

    public function getName(): string
    {
        return 'Optibet Telpu futbola Virslīga';
    }

    /**
     * Futsal arenas metadata mapping with verified OpenStreetMap coordinates and official addresses
     */
    protected array $stadiums = [
        'Nīcas sporta halle' => [
            'venue' => 'Nīcas sporta halle',
            'city' => 'Nīca',
            'region' => 'Kurzeme',
            'address' => 'Skolas iela 14, Nīca, Dienvidkurzemes novads',
            'lat' => 56.34601,
            'lon' => 21.07007,
        ],
        'Sauriešu sporta halle' => [
            'venue' => 'Sauriešu sporta halle',
            'city' => 'Saurieši',
            'region' => 'Rīga un Pierīga',
            'address' => 'Līdumnieku iela 10, Saurieši, Ropažu novads',
            'lat' => 56.92098,
            'lon' => 24.35591,
        ],
        'Kuldīgas sporta halle' => [
            'venue' => 'Kuldīgas sporta halle',
            'city' => 'Kuldīga',
            'region' => 'Kurzeme',
            'address' => 'Piltenes iela 25, Kuldīga',
            'lat' => 56.97441,
            'lon' => 21.95851,
        ],
        'Talsu sporta halle' => [
            'venue' => 'Talsu sporta halle',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'Kārļa Mīlenbaha iela 32A, Talsi',
            'lat' => 57.24360,
            'lon' => 22.60495,
        ],
        'Rimi OSC (Sporta spēļu zāle)' => [
            'venue' => 'Rimi Olimpiskais centrs',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Grostonas iela 6B, Rīga',
            'lat' => 56.96766,
            'lon' => 24.12464,
        ],
        'Rimi OSC' => [
            'venue' => 'Rimi Olimpiskais centrs',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Grostonas iela 6B, Rīga',
            'lat' => 56.96766,
            'lon' => 24.12464,
        ],
        'Aizkraukles sporta halle' => [
            'venue' => 'Aizkraukles novada Sporta centrs',
            'city' => 'Aizkraukle',
            'region' => 'Zemgale',
            'address' => 'Lāčplēša iela 21B, Aizkraukle',
            'lat' => 56.60038,
            'lon' => 25.26274,
        ],
        'Daugavpils Olimpiskais centrs' => [
            'venue' => 'Daugavpils Olimpiskais centrs',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Stadiona iela 1, Daugavpils',
            'lat' => 55.87760,
            'lon' => 26.53670,
        ],
        'Salaspils Sporta nams' => [
            'venue' => 'Salaspils Sporta nams',
            'city' => 'Salaspils',
            'region' => 'Rīga un Pierīga',
            'address' => 'Smilšu iela 1, Salaspils',
            'lat' => 56.85899,
            'lon' => 24.33448,
        ],
        'Rēzeknes Olimpiskā centra halle' => [
            'venue' => 'Olimpiskais centrs Rēzekne',
            'city' => 'Rēzekne',
            'region' => 'Latgale',
            'address' => 'Stacijas iela 30, Rēzekne',
            'lat' => 56.51433,
            'lon' => 27.35200,
        ],
        'Olimpiskais centrs Rēzekne' => [
            'venue' => 'Olimpiskais centrs Rēzekne',
            'city' => 'Rēzekne',
            'region' => 'Latgale',
            'address' => 'Stacijas iela 30, Rēzekne',
            'lat' => 56.51433,
            'lon' => 27.35200,
        ],
    ];

    /**
     * Team fallback city mapping (if arena is unspecified or changed)
     */
    protected array $teamCities = [
        'FK Nīca/OtankiMill' => ['city' => 'Nīca', 'region' => 'Kurzeme'],
        'FK Nīca' => ['city' => 'Nīca', 'region' => 'Kurzeme'],
        'TFK Salaspils' => ['city' => 'Salaspils', 'region' => 'Rīga un Pierīga'],
        'Salaspils FA' => ['city' => 'Salaspils', 'region' => 'Rīga un Pierīga'],
        'Futbola Parks Academy' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'TFK Beitar Riga' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'TFK Beitar' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'FC Nikers' => ['city' => 'Kuldīga', 'region' => 'Kurzeme'],
        'AVClub' => ['city' => 'Saurieši', 'region' => 'Rīga un Pierīga'],
        'FC Talsi' => ['city' => 'Talsi', 'region' => 'Kurzeme'],
        'Squad/Samgus Aizkraukle' => ['city' => 'Aizkraukle', 'region' => 'Zemgale'],
    ];

    public function scrape(Source $source): Collection
    {
        $url = $source->url ?: 'https://lff.lv/sacensibas/telpu-futbols/virsliga/?tab=content_1_2';
        $crawler = $this->fetchCrawler($url);

        if (!$crawler) {
            return collect();
        }

        $events = collect();

        // Parse matches directly from schedule tab (#tabContent_1_2, fallback #tabContent_1_1)
        $tab = $crawler->filter('#tabContent_1_2');
        if ($tab->count() === 0) {
            $tab = $crawler->filter('#tabContent_1_1');
        }

        if ($tab->count() > 0) {
            $tab->filter('.tr.match')->each(function (Crawler $row) use (&$events, $url) {
                $dto = $this->parseRow($row, $url);
                if ($dto) {
                    $events->push($dto);
                }
            });
        }

        return $events;
    }

    /**
     * Parse a match row from LFF futsal schedule table
     */
    public function parseRow(Crawler $row, string $baseUrl): ?ScrapedEventDTO
    {
        if (!$row->filter('.date h5')->count() || !$row->filter('.date h6')->count()) {
            return null;
        }

        $months = [
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
            'mai' => 5, 'jūn' => 6, 'jūl' => 7, 'aug' => 8,
            'sep' => 9, 'okt' => 10, 'nov' => 11, 'dec' => 12,
        ];

        $day = trim($row->filter('.date h5')->text());
        $monKey = mb_strtolower(trim($row->filter('.date h6')->text()));
        $time = trim($row->filter('.date .h7')->count() ? $row->filter('.date .h7')->text() : '');
        if (!preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            $time = '00:00';
        }

        $year = trim($row->filter('.date .h8')->count() ? $row->filter('.date .h8')->text() : date('Y'));
        $mon = $months[$monKey] ?? null;
        if (!$mon) {
            return null;
        }

        try {
            $startAt = Carbon::createFromFormat('Y-m-d H:i', sprintf('%04d-%02d-%02d %s', (int)$year, (int)$mon, (int)$day, $time), 'Europe/Riga');
        } catch (\Throwable $e) {
            return null;
        }

        if ($startAt->lt(now()->startOfDay())) {
            return null;
        }

        // Futsal match duration ≈ 90 minutes
        $endAt = (clone $startAt)->addMinutes(90);

        $home = trim($row->filter('.club .title')->first()->count() ? $row->filter('.club .title')->first()->text() : '');
        $away = trim($row->filter('.club .title')->last()->count() ? $row->filter('.club .title')->last()->text() : '');

        if (empty($home) || empty($away)) {
            return null;
        }

        $stadiumRaw = trim($row->filter('.stadium')->count() ? $row->filter('.stadium')->text() : '');
        $location = $this->resolveLocation($stadiumRaw, $home);

        $matchId = $row->attr('data-id');

        $relLink = $row->filter('a[href*="/speles/"], a.info')->count() ? $row->filter('a[href*="/speles/"], a.info')->attr('href') : null;
        $gameUrl = $relLink
            ? (str_starts_with($relLink, 'http') ? $relLink : 'https://lff.lv' . $relLink)
            : $baseUrl;

        $title = "{$home} vs {$away}";
        $shortDescription = "Optibet Telpu futbola Virslīga: {$home} pret {$away} ({$location['venue']}).";

        $descLines = [
            "Optibet Telpu futbola Virslīgas spēle: {$home} pret {$away}.",
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
            imageUrl: self::DEFAULT_IMAGE,
            sourceUrl: $gameUrl,
            sourceExternalId: $matchId ? (string) $matchId : null,
            locale: 'lv',
            rawData: [
                'match_id' => $matchId,
                'home_team' => $home,
                'away_team' => $away,
                'stadium_raw' => $stadiumRaw,
            ]
        );
    }

    public function resolveLocation(string $stadiumRaw, string $homeTeam): array
    {
        $trimmed = trim($stadiumRaw);

        if (!empty($trimmed) && isset($this->stadiums[$trimmed])) {
            return $this->stadiums[$trimmed];
        }

        // Normalized matching (remove quotes and extra whitespace)
        $normalizedInput = str_replace(['"', '“', '”', '«', '»'], '', mb_strtolower($trimmed));

        foreach ($this->stadiums as $name => $data) {
            $normalizedName = str_replace(['"', '“', '”', '«', '»'], '', mb_strtolower($name));
            if (!empty($normalizedInput) && (
                str_contains($normalizedInput, $normalizedName) ||
                str_contains($normalizedName, $normalizedInput)
            )) {
                return $data;
            }
        }

        // Fallback using home team
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
            'venue' => !empty($trimmed) ? $trimmed : 'Sporta halle',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => null,
            'lat' => null,
            'lon' => null,
        ];
    }
}
