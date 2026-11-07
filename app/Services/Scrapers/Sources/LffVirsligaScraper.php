<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\DomCrawler\Crawler;

class LffVirsligaScraper extends BaseScraper
{
    public const DEFAULT_IMAGE = 'https://sodiena.hel1.your-objectstorage.com/leagues/virsliga.webp';

    public function getSlug(): string
    {
        return 'lff-virsliga';
    }

    public function getName(): string
    {
        return 'Tonybet Virslīga (LFF)';
    }

    /**
     * Stadium metadata mapping with verified OpenStreetMap coordinates and official addresses
     */
    protected array $stadiums = [
        'Skonto stadions' => [
            'venue' => 'Skonto stadions',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Emiļa Melngaiļa iela 1a, Rīga',
            'lat' => 56.96139,
            'lon' => 24.11667,
        ],
        'LNK Sporta Parks (dabīgais)' => [
            'venue' => 'LNK Sporta Parks',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Lejupes iela 5, Rīga',
            'lat' => 56.90848,
            'lon' => 24.15379,
        ],
        'LNK Sporta Parks (mākslīgais)' => [
            'venue' => 'LNK Sporta Parks (mākslīgais laukums)',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Lejupes iela 5, Rīga',
            'lat' => 56.90848,
            'lon' => 24.15379,
        ],
        'LNK Sporta Parks' => [
            'venue' => 'LNK Sporta Parks',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Lejupes iela 5, Rīga',
            'lat' => 56.90848,
            'lon' => 24.15379,
        ],
        'Jāņa Skredeļa stadions' => [
            'venue' => 'Jāņa Skredeļa stadions',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Kauguru iela 6, Rīga',
            'lat' => 56.93527,
            'lon' => 24.05071,
        ],
        'Mežaparks SV' => [
            'venue' => 'Mežaparks Sports Village',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Ezermalas iela 24, Rīga',
            'lat' => 57.00001,
            'lon' => 24.17126,
        ],
        'Daugavas stadions, Rīga' => [
            'venue' => 'Daugavas stadions',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Augšiela 1, Rīga',
            'lat' => 56.95556,
            'lon' => 24.15833,
        ],
        'Hanzas vidusskolas laukums' => [
            'venue' => 'Rīgas Hanzas vidusskolas futbola laukums',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Grostonas iela 5, Rīga',
            'lat' => 56.96694,
            'lon' => 24.12583,
        ],
        'FK Jelgava Sporta bāzes mākslīgais laukums' => [
            'venue' => 'FK Jelgava sporta bāze',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Kārklu iela 6, Jelgava',
            'lat' => 56.63693,
            'lon' => 23.70212,
        ],
        'Zemgales OC' => [
            'venue' => 'Zemgales Olimpiskais centrs',
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Kronvalda iela 24, Jelgava',
            'lat' => 56.66639,
            'lon' => 23.73889,
        ],
        'Tukuma pils. stad. māksl. lauk.' => [
            'venue' => 'Tukuma pilsētas stadions (mākslīgais laukums)',
            'city' => 'Tukums',
            'region' => 'Kurzeme',
            'address' => 'Kuldīgas iela 62, Tukums',
            'lat' => 56.95196,
            'lon' => 23.12656,
        ],
        'Tukuma pilsētas stadions' => [
            'venue' => 'Tukuma pilsētas stadions',
            'city' => 'Tukums',
            'region' => 'Kurzeme',
            'address' => 'Kuldīgas iela 62, Tukums',
            'lat' => 56.95196,
            'lon' => 23.12656,
        ],
        'Stadions "Daugava", rezerves laukums' => [
            'venue' => 'Stadions "Daugava" (rezerves laukums)',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Jūrmalas parks 3, Liepāja',
            'lat' => 56.50501,
            'lon' => 20.99264,
        ],
        'Stadions “Daugava”, Liepāja' => [
            'venue' => 'Stadions "Daugava"',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Jūrmalas parks 3, Liepāja',
            'lat' => 56.50501,
            'lon' => 20.99264,
        ],
        'Stadions "Daugava"' => [
            'venue' => 'Stadions "Daugava"',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Jūrmalas parks 3, Liepāja',
            'lat' => 56.50501,
            'lon' => 20.99264,
        ],
        'Stadions "Esplanāde", mākslīgais laukums' => [
            'venue' => 'Stadions "Esplanāde"',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Stadiona iela 1, Daugavpils',
            'lat' => 55.87876,
            'lon' => 26.51538,
        ],
        'Stadions "Esplanāde"' => [
            'venue' => 'Stadions "Esplanāde"',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Stadiona iela 1, Daugavpils',
            'lat' => 55.87876,
            'lon' => 26.51538,
        ],
        'Stadions "Celtnieks"' => [
            'venue' => 'Stadions "Celtnieks"',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Jelgavas iela 7, Daugavpils',
            'lat' => 55.87652,
            'lon' => 26.54191,
        ],
        'Ogres stadions' => [
            'venue' => 'Ogres stadions',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Skolas iela 12, Ogre',
            'lat' => 56.81677,
            'lon' => 24.59359,
        ],
        'Salaspils stadions' => [
            'venue' => 'Salaspils stadions',
            'city' => 'Salaspils',
            'region' => 'Rīga un Pierīga',
            'address' => 'Rudzu iela 2, Salaspils',
            'lat' => 56.85797,
            'lon' => 24.33726,
        ],
        'Jūrmalas pilsētas stadions "Sloka" (dabīgais)' => [
            'venue' => 'Jūrmalas pilsētas stadions "Sloka"',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Skolas iela 5, Jūrmala',
            'lat' => 56.94967,
            'lon' => 23.61028,
        ],
        'Jūrmalas pilsētas stadions "Sloka"' => [
            'venue' => 'Jūrmalas pilsētas stadions "Sloka"',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Skolas iela 5, Jūrmala',
            'lat' => 56.94967,
            'lon' => 23.61028,
        ],
        'Jāņa Daliņa stadions' => [
            'venue' => 'Jāņa Daliņa stadions',
            'city' => 'Valmiera',
            'region' => 'Vidzeme',
            'address' => 'Jāņa Daliņa iela 2, Valmiera',
            'lat' => 57.54080,
            'lon' => 25.43720,
        ],
        'Grobiņas stadions' => [
            'venue' => 'Grobiņas pilsētas stadions',
            'city' => 'Grobiņa',
            'region' => 'Kurzeme',
            'address' => 'Ventspils iela 41, Grobiņa',
            'lat' => 56.53670,
            'lon' => 21.16830,
        ],
        'Ķekavas stadions' => [
            'venue' => 'Ķekavas vidusskolas stadions',
            'city' => 'Ķekava',
            'region' => 'Rīga un Pierīga',
            'address' => 'Skolas iela 2, Ķekava',
            'lat' => 56.82860,
            'lon' => 24.23250,
        ],
    ];

    /**
     * Team fallback city mapping (if stadium is unspecified)
     */
    protected array $teamCities = [
        'Riga FC' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'FC RFS' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'FK RFS' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'RFS' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'FK Liepāja' => ['city' => 'Liepāja', 'region' => 'Kurzeme'],
        'Liepāja' => ['city' => 'Liepāja', 'region' => 'Kurzeme'],
        'FK Tukums 2000' => ['city' => 'Tukums', 'region' => 'Kurzeme'],
        'FK Tukums 2000/Telms' => ['city' => 'Tukums', 'region' => 'Kurzeme'],
        'FK Auda' => ['city' => 'Ķekava', 'region' => 'Rīga un Pierīga'],
        'FS Jelgava' => ['city' => 'Jelgava', 'region' => 'Zemgale'],
        'BFC Daugavpils' => ['city' => 'Daugavpils', 'region' => 'Latgale'],
        'FK Grobiņa' => ['city' => 'Grobiņa', 'region' => 'Kurzeme'],
        'Grobiņas SC' => ['city' => 'Grobiņa', 'region' => 'Kurzeme'],
        'SK Super Nova' => ['city' => 'Salaspils', 'region' => 'Rīga un Pierīga'],
        'Super Nova' => ['city' => 'Salaspils', 'region' => 'Rīga un Pierīga'],
        'Ogre United' => ['city' => 'Ogre', 'region' => 'Vidzeme'],
        'Valmiera FC' => ['city' => 'Valmiera', 'region' => 'Vidzeme'],
        'FK Metta' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
    ];

    public function scrape(Source $source): Collection
    {
        $url = $source->url ?: 'https://lff.lv/sacensibas/viriesi/virsliga/?tab=content_1_1';
        $crawler = $this->fetchCrawler($url);

        if (!$crawler) {
            return collect();
        }

        $events = collect();

        // 1. Primary: parse upcoming round matches from #tabContent_1_1
        $tab1 = $crawler->filter('#tabContent_1_1');
        if ($tab1->count() > 0) {
            $items = $tab1->filter('.fixtures.style1 > div, .fixtures > div.tr');
            if ($items->count() === 0) {
                $items = $tab1->filter('.tr');
            }

            $currentDate = null;
            $items->each(function (Crawler $item) use (&$events, &$currentDate, $url) {
                $class = $item->attr('class') ?? '';

                if (str_contains($class, 'th1') || $item->filter('.h3')->count() > 0) {
                    $text = trim($item->text());
                    if (preg_match('/(\d{2}\.\d{2}\.\d{4})/', $text, $matches)) {
                        $currentDate = $matches[1];
                    }
                    return;
                }

                if (str_contains($class, 'match')) {
                    if (!$currentDate) {
                        return;
                    }
                    $dto = $this->parseTab1Match($item, $currentDate, $url);
                    if ($dto) {
                        $events->push($dto);
                    }
                }
            });
        }

        // 2. Fallback: if tabContent_1_1 was empty, parse upcoming matches from full schedule tabContent_1_2
        if ($events->isEmpty()) {
            $tab2 = $crawler->filter('#tabContent_1_2');
            if ($tab2->count() > 0) {
                $tab2->filter('.tr.match')->each(function (Crawler $row) use (&$events, $url) {
                    $dto = $this->parseTab2Match($row, $url);
                    if ($dto) {
                        $events->push($dto);
                    }
                });
            }
        }

        return $events;
    }

    /**
     * Parse a match element from #tabContent_1_1 (Nākamās spēles)
     */
    public function parseTab1Match(Crawler $row, string $dateStr, string $baseUrl): ?ScrapedEventDTO
    {
        $time = trim($row->filter('.matchday h4')->count() ? $row->filter('.matchday h4')->text() : '00:00');
        if (empty($time)) {
            $time = '00:00';
        }

        try {
            $startAt = Carbon::createFromFormat('d.m.Y H:i', "{$dateStr} {$time}", 'Europe/Riga');
        } catch (\Throwable $e) {
            return null;
        }

        // Rule: do not ingest past events
        if ($startAt->lt(now()->startOfDay())) {
            return null;
        }

        $endAt = (clone $startAt)->addMinutes(115);

        $home = trim($row->filter('.club .title')->first()->count() ? $row->filter('.club .title')->first()->text() : '');
        $away = trim($row->filter('.club .title')->last()->count() ? $row->filter('.club .title')->last()->text() : '');

        if (empty($home) || empty($away)) {
            return null;
        }

        $stadiumRaw = trim($row->filter('.stadium')->count() ? $row->filter('.stadium')->text() : '');
        $location = $this->resolveLocation($stadiumRaw, $home);

        $round = '';
        if ($row->filter('.matchday h5')->count() && $row->filter('.matchday h6')->count()) {
            $round = trim($row->filter('.matchday h5')->text()) . '. ' . trim($row->filter('.matchday h6')->text());
        }

        $homeLogo = $row->filter('.club')->first()->filter('.logo img')->count()
            ? $row->filter('.club')->first()->filter('.logo img')->attr('src')
            : null;
        $awayLogo = $row->filter('.club')->last()->filter('.logo img')->count()
            ? $row->filter('.club')->last()->filter('.logo img')->attr('src')
            : null;

        $matchId = $row->attr('data-id');

        $relLink = $row->filter('a.info')->count() ? $row->filter('a.info')->attr('href') : null;
        $gameUrl = $relLink
            ? (str_starts_with($relLink, 'http') ? $relLink : 'https://lff.lv' . $relLink)
            : $baseUrl;

        $title = "{$home} vs {$away}";
        $roundSuffix = $round ? " ({$round})" : '';
        $shortDescription = "Tonybet Virslīga: {$home} pret {$away} ({$location['venue']}).";

        $descLines = [
            "Tonybet Virslīgas futbola spēle: {$home} pret {$away}{$roundSuffix}.",
            "",
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
            ticketUrl: $gameUrl,
            imageUrl: self::DEFAULT_IMAGE,
            sourceUrl: $gameUrl,
            sourceExternalId: $matchId ? (string) $matchId : null,
            locale: 'lv',
            rawData: [
                'match_id' => $matchId,
                'round' => $round,
                'home_team' => $home,
                'away_team' => $away,
                'stadium_raw' => $stadiumRaw,
                'home_logo' => $homeLogo,
                'away_logo' => $awayLogo,
            ]
        );
    }

    /**
     * Fallback: parse a match element from #tabContent_1_2 (Visas spēles)
     */
    public function parseTab2Match(Crawler $row, string $baseUrl): ?ScrapedEventDTO
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
        $time = trim($row->filter('.date .h7')->count() ? $row->filter('.date .h7')->text() : '00:00');
        $year = trim($row->filter('.date .h8')->count() ? $row->filter('.date .h8')->text() : date('Y'));

        $mon = $months[$monKey] ?? null;
        if (!$mon) {
            return null;
        }

        try {
            $startAt = Carbon::createFromFormat('Y-m-d H:i', sprintf('%04d-%02d-%02d %s', $year, $mon, $day, $time), 'Europe/Riga');
        } catch (\Throwable $e) {
            return null;
        }

        if ($startAt->lt(now()->startOfDay())) {
            return null;
        }

        $endAt = (clone $startAt)->addMinutes(115);

        $home = trim($row->filter('.club .title')->first()->count() ? $row->filter('.club .title')->first()->text() : '');
        $away = trim($row->filter('.club .title')->last()->count() ? $row->filter('.club .title')->last()->text() : '');

        if (empty($home) || empty($away)) {
            return null;
        }

        $stadiumRaw = trim($row->filter('.stadium')->count() ? $row->filter('.stadium')->text() : '');
        $location = $this->resolveLocation($stadiumRaw, $home);

        $matchId = $row->attr('data-id');

        $relLink = $row->filter('a.info')->count() ? $row->filter('a.info')->attr('href') : null;
        $gameUrl = $relLink
            ? (str_starts_with($relLink, 'http') ? $relLink : 'https://lff.lv' . $relLink)
            : $baseUrl;

        $title = "{$home} vs {$away}";
        $shortDescription = "Tonybet Virslīga: {$home} pret {$away} ({$location['venue']}).";

        $descLines = [
            "Tonybet Virslīgas futbola spēle: {$home} pret {$away}.",
            "",
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
            ticketUrl: $gameUrl,
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
                'venue' => !empty($trimmed) ? $trimmed : "{$homeTeam} mājas stadions",
                'city' => $teamInfo['city'],
                'region' => $teamInfo['region'],
                'address' => null,
                'lat' => null,
                'lon' => null,
            ];
        }

        return [
            'venue' => !empty($trimmed) ? $trimmed : 'Futbola stadions',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => null,
            'lat' => null,
            'lon' => null,
        ];
    }
}

