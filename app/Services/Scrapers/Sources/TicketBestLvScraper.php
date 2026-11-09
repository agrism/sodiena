<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class TicketBestLvScraper extends BaseScraper
{
    public const DEFAULT_IMAGE = 'https://ticketbest.lv/assets/logo-8dcde2e695af5ebd20fb44d14867e24891738bcaea1fe20996407175067c171b.png';
    public const BASE_URL = 'https://ticketbest.lv';

    public function __construct()
    {
        parent::__construct();
        $this->httpClient = new Client([
            'timeout' => 20,
            'connect_timeout' => 10,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'lv,en-US;q=0.7,en;q=0.3',
                'Cookie' => 'locale=lv; country=latviya',
            ],
            'verify' => false,
        ]);
    }

    public function getSlug(): string
    {
        return 'ticketbest';
    }

    public function getName(): string
    {
        return 'TicketBest (ticketbest.lv)';
    }

    /**
     * Known Latvian venues with verified OpenStreetMap coordinates and addresses
     */
    protected array $venues = [
        'Rīgas Latviešu biedrības nams' => [
            'venue' => 'Rīgas Latviešu biedrības nams',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Merķeļa iela 13, Rīga',
            'lat' => 56.95023,
            'lon' => 24.11733,
            'place_type' => 'cultural_centre',
        ],
        'Tallinas Kvartāla Angārs' => [
            'venue' => 'Tallinas Kvartāla Angārs',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Tallinas iela 10-3, Rīga',
            'lat' => 56.96429,
            'lon' => 24.13482,
            'place_type' => 'concert_hall',
        ],
        'SPELET koncertzāle' => [
            'venue' => 'SPELET koncertzāle',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Brīvības iela 96a, Rīga',
            'lat' => 56.96059,
            'lon' => 24.13217,
            'place_type' => 'concert_hall',
        ],
        'Xiaomi Arēna Rīga' => [
            'venue' => 'Arēna Rīga',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Skanstes iela 21, Rīga',
            'lat' => 56.96786,
            'lon' => 24.12127,
            'place_type' => 'arena',
        ],
        'Arēna Rīga' => [
            'venue' => 'Arēna Rīga',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Skanstes iela 21, Rīga',
            'lat' => 56.96786,
            'lon' => 24.12127,
            'place_type' => 'arena',
        ],
        'Teātra namā "Jūras vārti"' => [
            'venue' => 'Teātra nams "Jūras vārti"',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Karlīnes iela 40, Ventspils',
            'lat' => 57.39332,
            'lon' => 21.54203,
            'place_type' => 'theatre',
        ],
        'Teātra nams "Jūras vārti"' => [
            'venue' => 'Teātra nams "Jūras vārti"',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Karlīnes iela 40, Ventspils',
            'lat' => 57.39332,
            'lon' => 21.54203,
            'place_type' => 'theatre',
        ],
        'Liepājas koncertzāle LIELAIS DZINTARS' => [
            'venue' => 'Koncertzāle "Lielais dzintars"',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Radio iela 8, Liepāja',
            'lat' => 56.51142,
            'lon' => 21.01057,
            'place_type' => 'concert_hall',
        ],
        'Koncertzāle "Lielais dzintars"' => [
            'venue' => 'Koncertzāle "Lielais dzintars"',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Radio iela 8, Liepāja',
            'lat' => 56.51142,
            'lon' => 21.01057,
            'place_type' => 'concert_hall',
        ],
        'VEF Kultūras pils' => [
            'venue' => 'VEF Kultūras pils',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Ropažu iela 2, Rīga',
            'lat' => 56.97450,
            'lon' => 24.16605,
            'place_type' => 'cultural_centre',
        ],
        'Dzintaru koncertzāle' => [
            'venue' => 'Dzintaru koncertzāle',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Turaidas iela 1, Jūrmala',
            'lat' => 56.97427,
            'lon' => 23.82173,
            'place_type' => 'concert_hall',
        ],
        'Hanzas Perons' => [
            'venue' => 'Hanzas Perons',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Hanzas iela 16A, Rīga',
            'lat' => 56.96497,
            'lon' => 24.11304,
            'place_type' => 'cultural_centre',
        ],
        'Palladium Rīga' => [
            'venue' => 'Koncertzāle "Palladium Rīga"',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Marijas iela 21, Rīga',
            'lat' => 56.95350,
            'lon' => 24.12460,
            'place_type' => 'concert_hall',
        ],
        'Latgales vēstniecība GORS' => [
            'venue' => 'Latgales vēstniecība GORS',
            'city' => 'Rēzekne',
            'region' => 'Latgale',
            'address' => 'Pils iela 4, Rēzekne',
            'lat' => 56.50285,
            'lon' => 27.33230,
            'place_type' => 'concert_hall',
        ],
    ];

    public function scrape(Source $source): Collection
    {
        $url = $source->url ?: 'https://ticketbest.lv/';
        $crawler = $this->fetchCrawler($url);

        if (!$crawler) {
            return collect();
        }

        $events = collect();

        $crawler->filter('.card')->each(function (Crawler $card) use (&$events) {
            $dto = $this->parseCard($card);
            if ($dto) {
                $events->push($dto);
            }
        });

        return $events;
    }

    /**
     * Parse a single event card from TicketBest homepage
     */
    public function parseCard(Crawler $card): ?ScrapedEventDTO
    {
        // 1. Strict country check (Latvia events only)
        $rawAddress = $card->filter('.card_adress')->count()
            ? trim($card->filter('.card_adress')->text())
            : '';

        if (!str_contains(mb_strtolower($rawAddress), 'latvia') && !str_contains(mb_strtolower($rawAddress), 'latvija')) {
            return null;
        }

        // 2. Title
        $titleNode = $card->filter('.card_title');
        if ($titleNode->count() === 0) {
            return null;
        }
        $title = trim($titleNode->text());
        if (empty($title)) {
            return null;
        }

        // 3. Event URL and External ID
        $relHref = $titleNode->attr('href') ?? '';
        $fullUrl = str_starts_with($relHref, 'http')
            ? $relHref
            : rtrim(self::BASE_URL, '/') . '/' . ltrim($relHref, '/');

        $externalId = null;
        if (preg_match('/[?&]event_id=(\d+)/', $fullUrl, $mId)) {
            $externalId = 'tb-' . $mId[1];
        } elseif (preg_match('/\/performances\/([^\/?#]+)/', $fullUrl, $mSlug)) {
            $externalId = 'tb-' . $mSlug[1];
        } else {
            $externalId = 'tb-' . substr(md5($fullUrl), 0, 10);
        }

        // 4. Start Date & Time
        $btn = $card->filter('.card_button');
        $rawShowtime = $btn->count() ? $btn->attr('data-unifd-showtime') : null;
        $startAt = $this->parseShowtime($rawShowtime, $card);

        if (!$startAt) {
            return null;
        }

        // Strict rule: skip past events
        if ($startAt->lt(now()->startOfDay())) {
            return null;
        }

        // Typical concert duration ≈ 2 hours
        $endAt = (clone $startAt)->addMinutes(120);

        // 5. Image URL
        $rawImg = $card->filter('.card_image')->count()
            ? $card->filter('.card_image')->attr('src')
            : null;
        $imageUrl = $rawImg
            ? (str_starts_with($rawImg, 'http') ? $rawImg : rtrim(self::BASE_URL, '/') . '/' . ltrim($rawImg, '/'))
            : self::DEFAULT_IMAGE;

        // 6. Venue & Location
        $location = $this->resolveAddress($rawAddress);

        // 7. Optional Detail Page Fetch (for full description and organizer)
        $detailInfo = $this->fetchEventDetails($fullUrl);
        $description = $detailInfo['description'] ?: "Pasākums: {$title}.\n\nNorises vieta: {$location['venue']}, {$location['city']}.\nBiļetes pieejamas TicketBest platformā.";
        $shortDescription = $detailInfo['short_description'] ?: (mb_strlen($description) <= 160 ? $description : Str::limit(strip_tags($description), 160));
        $organizer = $detailInfo['organizer'];

        // 8. Category and Entertainment Type detection (adhering to AGENTS.md)
        [$category, $entertainmentType] = $this->detectCategory($title, $description, $location['venue']);

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
            placeType: $location['place_type'],
            categoryNames: [$category],
            entertainmentType: $entertainmentType,
            isFree: false,
            priceMin: null,
            priceMax: null,
            currency: 'EUR',
            ticketUrl: $fullUrl,
            imageUrl: $imageUrl,
            sourceUrl: $fullUrl,
            sourceExternalId: $externalId,
            locale: 'lv',
            rawData: [
                'organizer' => $organizer,
                'address_raw' => $rawAddress,
                'showtime_raw' => $rawShowtime,
                'ticketbest_url' => $fullUrl,
            ]
        );
    }

    /**
     * Parse showtime attribute (YYYY.MM.DD+HH:mm) with fallback to .card_date text
     */
    public function parseShowtime(?string $rawShowtime, Crawler $card): ?Carbon
    {
        if (!empty($rawShowtime) && preg_match('/^(\d{4})[.-](\d{2})[.-](\d{2})[+ ](\d{1,2}):(\d{2})$/', $rawShowtime, $m)) {
            try {
                return Carbon::createFromFormat(
                    'Y-m-d H:i',
                    sprintf('%04d-%02d-%02d %02d:%02d', (int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4], (int)$m[5]),
                    'Europe/Riga'
                );
            } catch (\Throwable $e) {
                // fallback below
            }
        }

        // Fallback: parse .card_date (e.g. "ceturtdiena • 12 novembris 2026", "19:00")
        $dateNode = $card->filter('.card_date');
        if ($dateNode->count() > 0) {
            $text = trim($dateNode->text());
            $months = [
                'janvāris' => 1, 'februāris' => 2, 'marts' => 3, 'aprīlis' => 4,
                'maijs' => 5, 'jūnijs' => 6, 'jūlijs' => 7, 'augusts' => 8,
                'septembris' => 9, 'oktobris' => 10, 'novembris' => 11, 'decembris' => 12,
            ];

            if (preg_match('/(\d{1,2})\s+([a-zāčēģīķļņšūž]+)\s+(\d{4})/ui', $text, $dMatch)) {
                $day = (int) $dMatch[1];
                $monName = mb_strtolower($dMatch[2]);
                $year = (int) $dMatch[3];
                $mon = $months[$monName] ?? null;

                $time = '00:00';
                if (preg_match('/(\d{1,2}:\d{2})/', $text, $tMatch)) {
                    $time = $tMatch[1];
                }

                if ($mon) {
                    try {
                        return Carbon::createFromFormat('Y-m-d H:i', sprintf('%04d-%02d-%02d %s', $year, $mon, $day, $time), 'Europe/Riga');
                    } catch (\Throwable $e) {
                        return null;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Resolve location from raw address string (e.g. "Latvia, Rīga, Rīgas Latviešu biedrības nams")
     */
    public function resolveAddress(string $rawAddress): array
    {
        $parts = array_map('trim', explode(',', $rawAddress));
        $city = $parts[1] ?? 'Rīga';
        $venueRaw = implode(', ', array_slice($parts, 2));

        if (empty($venueRaw)) {
            $venueRaw = $city;
        }

        // Exact match in known venues
        if (isset($this->venues[$venueRaw])) {
            return $this->venues[$venueRaw];
        }

        // Fuzzy match
        $normalizedInput = str_replace(['"', '“', '”', '«', '»'], '', mb_strtolower($venueRaw));
        foreach ($this->venues as $name => $data) {
            $normalizedName = str_replace(['"', '“', '”', '«', '»'], '', mb_strtolower($name));
            if (str_contains($normalizedInput, $normalizedName) || str_contains($normalizedName, $normalizedInput)) {
                return $data;
            }
        }

        // Fallback by city
        $region = match ($city) {
            'Liepāja', 'Ventspils', 'Kuldīga', 'Talsi', 'Saldus', 'Tukums' => 'Kurzeme',
            'Daugavpils', 'Rēzekne', 'Preiļi', 'Krāslava', 'Ludza', 'Jēkabpils' => 'Latgale',
            'Jelgava', 'Bauska', 'Dobele', 'Aizkraukle' => 'Zemgale',
            'Cēsis', 'Valmiera', 'Sigulda', 'Ogre', 'Madona', 'Limbaži', 'Smiltene', 'Gulbene', 'Alūksne' => 'Vidzeme',
            default => 'Rīga un Pierīga',
        };

        return [
            'venue' => $venueRaw,
            'city' => $city,
            'region' => $region,
            'address' => "{$venueRaw}, {$city}, Latvija",
            'lat' => null,
            'lon' => null,
            'place_type' => 'cultural_centre',
        ];
    }

    /**
     * Fetch event description and organizer from detail page
     */
    protected function fetchEventDetails(string $url): array
    {
        $result = [
            'description' => null,
            'short_description' => null,
            'organizer' => null,
        ];

        try {
            $crawler = $this->fetchCrawler($url);
            if (!$crawler) {
                return $result;
            }

            $article = $crawler->filter('article');
            if ($article->count() > 0) {
                // Organizer
                $orgNode = $article->filter('.organization-link a');
                if ($orgNode->count() > 0) {
                    $result['organizer'] = trim($orgNode->text());
                }

                // Clean description text
                $paragraphs = [];
                $article->filter('p, h2, h3')->each(function (Crawler $node) use (&$paragraphs) {
                    $class = $node->attr('class') ?? '';
                    if (str_contains($class, 'organization-link')) {
                        return;
                    }
                    $t = trim($node->text());
                    if (!empty($t) && $t !== 'Pasākuma informācija') {
                        $paragraphs[] = $t;
                    }
                });

                if (!empty($paragraphs)) {
                    $result['description'] = implode("\n\n", $paragraphs);
                    $result['short_description'] = Str::limit($paragraphs[0], 160);
                }
            }
        } catch (\Throwable $e) {
            // Non-critical, fallback to card data
        }

        return $result;
    }

    /**
     * Detect category and entertainment type adhering strictly to AGENTS.md
     */
    public function detectCategory(string $title, string $description, string $venue): array
    {
        $titleLower = mb_strtolower($title, 'UTF-8');
        $descLower = mb_strtolower($description, 'UTF-8');
        $venueLower = mb_strtolower($venue, 'UTF-8');
        $fullText = $titleLower . ' ' . $descLower . ' ' . $venueLower;

        // 1. Strict Cinema check
        $cinemaVenues = ['k. suns', 'k.suns', 'k suns', 'kino rio', 'forum cinemas', 'apollo kino', 'cinamon', 'splendid palace', 'kino bize', 'kino lora', 'kino gaisma'];
        foreach ($cinemaVenues as $cv) {
            if (str_contains($venueLower, $cv) || str_contains($titleLower, $cv)) {
                return ['Kino', 'cinema'];
            }
        }

        $isCinemaContent = preg_match('/\b(kinoseanss|filmas seanss|spēlfilma|dokumentālā filma|animācijas filma|kinofestivāls)\b/ui', $titleLower)
            || preg_match('/\b(filma|filmas|filmā)\b/ui', $titleLower);

        $isFilmMuxicOrConcert = preg_match('/\b(koncerts|klavierkoncerts|mūzika|orķestris|kvartets|kvintets|dziesma|dzied|tour|šovs|show|filmu mūzik|kinomūzik|skaņu celiņ)\b/ui', $titleLower)
            || preg_match('/\b(klavierkoncerts|klaviermūzikas vakars|orķestr|koncerts|dzīvajā koncertā)\b/ui', mb_substr($descLower, 0, 300));

        if ($isCinemaContent && !$isFilmMuxicOrConcert) {
            return ['Kino', 'cinema'];
        }

        // 2. Kids / Family check
        if (preg_match('/\b(bērn|bērniem|ģimenēm|kids|children|pasaka)\b/u', $titleLower)) {
            return ['Bērniem', 'family'];
        }

        // 3. Theater check (Strict: only actual theater productions, never kinoteātris)
        if (preg_match('/\b(izrāde|luga|iestudējums|opera|balets|teātris|teātrī)\b/u', $titleLower) && !str_contains($fullText, 'kinoteātr')) {
            return ['Teātris', 'theater'];
        }

        // 4. Music / Concert check
        if (preg_match('/\b(koncerts|koncertā|mūzika|mūzikas|orķestris|orķestri|kvartets|kvintets|grupa|dziesma|dziesmas|dzied|tour|turneja|hiti|sveču gaismā|klaviermūzika|džezs|roks|k-pop|live machine|sade|bridgerton|einaudi|morricone|string orchestra)\b/u', $fullText)) {
            return ['Mūzika', 'concert'];
        }

        // 5. Exhibition check
        if (preg_match('/\b(izstāde|izstādes|māksla|gleznas|skulptūr|muzejs)\b/u', $fullText)) {
            return ['Izstādes', 'exhibition'];
        }

        // 6. Fallback
        return ['Cits', 'other'];
    }
}
