<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\DomCrawler\Crawler;

class HandballLvScraper extends BaseScraper
{
    public const DEFAULT_IMAGE = '/images/handball.webp';

    public function getSlug(): string
    {
        return 'handball-lv';
    }

    public function getName(): string
    {
        return 'Latvijas Handbola Federācija (handball.lv)';
    }

    /**
     * Handball arena metadata mapping with verified OpenStreetMap coordinates and official addresses
     */
    protected array $venues = [
        'Salaspils Sporta nams' => [
            'venue' => 'Salaspils Sporta nams',
            'city' => 'Salaspils',
            'region' => 'Rīga un Pierīga',
            'address' => 'Smilšu iela 1, Salaspils',
            'lat' => 56.85899,
            'lon' => 24.33448,
        ],
        'Salaspils sporta hall' => [
            'venue' => 'Salaspils Sporta nams',
            'city' => 'Salaspils',
            'region' => 'Rīga un Pierīga',
            'address' => 'Smilšu iela 1, Salaspils',
            'lat' => 56.85899,
            'lon' => 24.33448,
        ],
        'Dobeles sporta centrs' => [
            'venue' => 'Dobeles sporta centrs',
            'city' => 'Dobele',
            'region' => 'Zemgale',
            'address' => 'Tērvetes iela 10, Dobele',
            'lat' => 56.62069,
            'lon' => 23.27009,
        ],
        'Ogres novada sporta centrs' => [
            'venue' => 'Ogres novada sporta centrs',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Skolas iela 21, Ogre',
            'lat' => 56.81714,
            'lon' => 24.59184,
        ],
        'NBS Sporta klubs' => [
            'venue' => 'NBS Sporta klubs',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Krustabaznīcas iela 9, Rīga',
            'lat' => 56.98670,
            'lon' => 24.19334,
        ],
        'Aizkraukles novada Sporta centrs' => [
            'venue' => 'Aizkraukles novada Sporta centrs',
            'city' => 'Aizkraukle',
            'region' => 'Zemgale',
            'address' => 'Lāčplēša iela 21B, Aizkraukle',
            'lat' => 56.60038,
            'lon' => 25.26274,
        ],
        'Stopiņu pamatskola' => [
            'venue' => 'Stopiņu pamatskolas sporta zāle',
            'city' => 'Saurieši',
            'region' => 'Rīga un Pierīga',
            'address' => 'Līdumnieku iela 10, Saurieši',
            'lat' => 56.92098,
            'lon' => 24.35591,
        ],
        'Ludzas 2. vidusskolas sporta halle' => [
            'venue' => 'Ludzas 2. vidusskolas sporta halle',
            'city' => 'Ludza',
            'region' => 'Latgale',
            'address' => 'P. Miglinīka iela 27, Ludza',
            'lat' => 56.53987,
            'lon' => 27.71747,
        ],
        'Ludza sport hall' => [
            'venue' => 'Ludzas 2. vidusskolas sporta halle',
            'city' => 'Ludza',
            'region' => 'Latgale',
            'address' => 'P. Miglinīka iela 27, Ludza',
            'lat' => 56.53987,
            'lon' => 27.71747,
        ],
        'Jūrmalas Aspazijas psk. sporta halle' => [
            'venue' => 'Jūrmalas Aspazijas pamatskolas sporta halle',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Aizputes iela 1A, Jūrmala',
            'lat' => 56.98146,
            'lon' => 23.86258,
        ],
        'Vaiņodes vidusskolas sporta halle' => [
            'venue' => 'Vaiņodes vidusskolas sporta halle',
            'city' => 'Vaiņode',
            'region' => 'Kurzeme',
            'address' => 'Avotu iela 4, Vaiņode',
            'lat' => 56.42234,
            'lon' => 21.85611,
        ],
        'Ulbrokas sporta komplekss' => [
            'venue' => 'Ulbrokas sporta komplekss',
            'city' => 'Ulbroka',
            'region' => 'Rīga un Pierīga',
            'address' => 'Institūta iela 1, Ulbroka',
            'lat' => 56.93800,
            'lon' => 24.28600,
        ],
        'Murjāņu sporta ģimnāzija' => [
            'venue' => 'Murjāņu sporta ģimnāzijas sporta halle',
            'city' => 'Murjāņi',
            'region' => 'Vidzeme',
            'address' => 'Klintslejas 4, Murjāņi',
            'lat' => 57.13500,
            'lon' => 24.66700,
        ],
        'Jēkabpils 3. vidusskola' => [
            'venue' => 'Jēkabpils sporta halle',
            'city' => 'Jēkabpils',
            'region' => 'Latgale',
            'address' => 'Jaunā iela 39, Jēkabpils',
            'lat' => 56.49500,
            'lon' => 25.87500,
        ],
    ];

    /**
     * Team fallback city mapping (if venue is unspecified)
     */
    protected array $teamCities = [
        'ZRHK TENAX Dobele' => ['city' => 'Dobele', 'region' => 'Zemgale'],
        'Tenax Dobele' => ['city' => 'Dobele', 'region' => 'Zemgale'],
        'ASK/MSĢ' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'ASK Zemessardze/LSPA' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'HK Ogre/RSU' => ['city' => 'Ogre', 'region' => 'Vidzeme'],
        'HK Ogre' => ['city' => 'Ogre', 'region' => 'Vidzeme'],
        'Salaspils SS' => ['city' => 'Salaspils', 'region' => 'Rīga un Pierīga'],
        'Salaspils' => ['city' => 'Salaspils', 'region' => 'Rīga un Pierīga'],
        'SK Latgols' => ['city' => 'Ludza', 'region' => 'Latgale'],
        'Ludza' => ['city' => 'Ludza', 'region' => 'Latgale'],
        'HK Vaiņode' => ['city' => 'Vaiņode', 'region' => 'Kurzeme'],
        'Stopiņu PHK' => ['city' => 'Saurieši', 'region' => 'Rīga un Pierīga'],
        'Jēkabpils SS' => ['city' => 'Jēkabpils', 'region' => 'Latgale'],
    ];

    public function scrape(Source $source): Collection
    {
        $url = $source->url ?: 'https://handball.lv/league/1278/calendar';
        $crawler = $this->fetchCrawler($url);

        if (!$crawler) {
            return collect();
        }

        $seasonText = $crawler->filter('p:contains("Season")')->count()
            ? $crawler->filter('p:contains("Season")')->text()
            : '';
        $seasonStartYear = (int) date('Y');
        if (preg_match('/Season\s+(\d{4})\/(\d{4})/', $seasonText, $sm)) {
            $seasonStartYear = (int) $sm[1];
        }

        $defaultLeague = $crawler->filter('h1')->count()
            ? trim($crawler->filter('h1')->text())
            : 'Latvijas Handbola čempionāts';

        $months = [
            'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4,
            'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
            'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
            'jūn' => 6, 'jūl' => 7, 'aug' => 8, 'sep' => 9, 'okt' => 10, 'nov' => 11, 'dec' => 12,
            'janvāris' => 1, 'februāris' => 2, 'marts' => 3, 'aprīlis' => 4,
            'maijs' => 5, 'jūnijs' => 6, 'jūlijs' => 7, 'augusts' => 8,
            'septembris' => 9, 'oktobris' => 10, 'novembris' => 11, 'decembris' => 12,
        ];

        $events = collect();

        $crawler->filter('.calendar-day')->each(function (Crawler $dayNode) use ($months, $seasonStartYear, $defaultLeague, $url, &$events) {
            $text = trim($dayNode->text());
            if (!preg_match('/(\d{1,2})\.\s*([a-zA-Zā-žĀ-Ž]+)/u', $text, $m)) {
                return;
            }

            $day = (int) $m[1];
            $monKey = mb_strtolower(trim($m[2]));
            $mon = $months[$monKey] ?? null;
            if (!$mon) {
                return;
            }

            $year = ($mon >= 7) ? $seasonStartYear : ($seasonStartYear + 1);
            $dateStr = sprintf('%04d-%02d-%02d', $year, $mon, $day);

            $parentRow = $dayNode->getNode(0)->parentNode->parentNode;
            $c = new Crawler($parentRow);

            $c->filter('.calendar-row')->each(function (Crawler $row) use ($dateStr, $defaultLeague, $url, &$events) {
                $dto = $this->parseRow($row, $dateStr, $url, $defaultLeague);
                if ($dto) {
                    $events->push($dto);
                }
            });
        });

        return $events;
    }

    public function parseRow(Crawler $row, string $dateStr, string $baseUrl, string $defaultLeague = ''): ?ScrapedEventDTO
    {
        $firstCol = $row->filter('.calendar-first-column');

        $league = $firstCol->filter('a.d-block')->count()
            ? trim($firstCol->filter('a.d-block')->text())
            : $defaultLeague;

        $stage = $firstCol->filter('span')->count()
            ? trim($firstCol->filter('span')->text())
            : '';

        $venueDiv = $firstCol->filter('div[style*="font-size: 80%"] > div')->count()
            ? trim($firstCol->filter('div[style*="font-size: 80%"] > div')->text())
            : '';

        $parts = explode('·', $venueDiv, 2);
        $time = trim($parts[0] ?? '');
        $venueRaw = trim($parts[1] ?? '');

        // Extract teams
        $teams = [];
        $row->filter('.calendar-team > div:first-child')->each(function (Crawler $t) use (&$teams) {
            $name = trim($t->text());
            if ($name !== '') {
                $teams[] = $name;
            }
        });

        $home = $teams[0] ?? '';
        $away = $teams[1] ?? '';

        // Rule: Semi-final/final placeholder games without exact info (time 0:00/00:00 and no venue, or missing teams) must be skipped
        $isUnconfirmedTime = ($time === '00:00' || $time === '0:00' || empty($time));
        $isMissingVenue = empty($venueRaw);
        $isMissingTeams = empty($home) || empty($away);

        if ($isMissingTeams || ($isUnconfirmedTime && $isMissingVenue)) {
            return null;
        }

        try {
            $startAt = Carbon::createFromFormat('Y-m-d H:i', "{$dateStr} {$time}", 'Europe/Riga');
        } catch (\Throwable $e) {
            return null;
        }

        // Rule: do not ingest past events
        if ($startAt->lt(now()->startOfDay())) {
            return null;
        }

        // Handball match duration ≈ 90 minutes
        $endAt = (clone $startAt)->addMinutes(90);

        $location = $this->resolveLocation($venueRaw, $home);

        // Match link and external ID
        $link = $row->filter('a[href*="/calendar/"]')->count()
            ? $row->filter('a[href*="/calendar/"]')->attr('href')
            : $baseUrl;
        if (!str_starts_with($link, 'http')) {
            $link = 'https://handball.lv' . (str_starts_with($link, '/') ? '' : '/') . $link;
        }

        $matchId = null;
        if (preg_match('/\/calendar\/(\d+)/', $link, $lm)) {
            $matchId = $lm[1];
        }

        // YouTube live stream if available
        $yt = $row->filter('a[href*="youtube"]')->count()
            ? $row->filter('a[href*="youtube"]')->attr('href')
            : null;

        $title = "{$home} vs {$away}";
        $stageSuffix = $stage ? " ({$stage})" : '';
        $shortDescription = "{$league}: {$home} pret {$away} ({$location['venue']}).";

        $descLines = [
            "{$league} handbola spēle: {$home} pret {$away}{$stageSuffix}.",
            '',
            "Norises vieta: {$location['venue']}, {$location['city']}",
        ];
        if (!empty($location['address'])) {
            $descLines[] = "Adrese: {$location['address']}";
        }
        if (!empty($yt)) {
            $descLines[] = "Tiešraide: {$yt}";
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
            ticketUrl: $link,
            imageUrl: self::DEFAULT_IMAGE,
            sourceUrl: $link,
            sourceExternalId: $matchId ? (string) $matchId : null,
            locale: 'lv',
            rawData: [
                'match_id' => $matchId,
                'league' => $league,
                'stage' => $stage,
                'home_team' => $home,
                'away_team' => $away,
                'venue_raw' => $venueRaw,
                'youtube_url' => $yt,
            ]
        );
    }

    public function resolveLocation(string $venueRaw, string $homeTeam): array
    {
        $trimmed = trim($venueRaw);

        // 1. Check exact key or prefix/containment in known venues
        foreach ($this->venues as $name => $data) {
            if (!empty($trimmed) && (
                stripos($trimmed, $name) !== false ||
                stripos($name, $trimmed) !== false
            )) {
                return $data;
            }
        }

        // 2. Fallback to team home city if venue unknown
        if (isset($this->teamCities[$homeTeam])) {
            $teamInfo = $this->teamCities[$homeTeam];
            return [
                'venue' => !empty($trimmed) ? $trimmed : "{$homeTeam} mājas arēna",
                'city' => $teamInfo['city'],
                'region' => $teamInfo['region'],
                'address' => null,
                'lat' => null,
                'lon' => null,
            ];
        }

        return [
            'venue' => !empty($trimmed) ? $trimmed : 'Handbola zāle',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => null,
            'lat' => null,
            'lon' => null,
        ];
    }
}

