<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class AulaLvScraper extends BaseScraper
{
    public const BASE_URL = 'https://www.aula.lv';
    public const DEFAULT_URL = 'https://www.aula.lv/visi';
    public const DEFAULT_IMAGE = 'https://www.aula.lv/assets/images/aula-graph-placeholder-white.png';

    protected array $detailDescriptionCache = [];

    /**
     * Common Latvian venues on Aula with verified coordinates and addresses
     */
    protected array $venues = [
        'Pasākumu un kultūras telpa "Pauze" (ex. OratoriO)' => [
            'venue' => 'Pasākumu un kultūras telpa "Pauze"',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Sporta iela 2, Rīga, LV-1013',
            'lat' => 56.96380,
            'lon' => 24.11650,
            'place_type' => 'cultural_centre',
        ],
        'Pasākumu un kultūras telpa "Pauze"' => [
            'venue' => 'Pasākumu un kultūras telpa "Pauze"',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Sporta iela 2, Rīga, LV-1013',
            'lat' => 56.96380,
            'lon' => 24.11650,
            'place_type' => 'cultural_centre',
        ],
        'Latvijas Universitātes Lielā aula' => [
            'venue' => 'Latvijas Universitātes Lielā aula',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Raiņa bulvāris 19, Rīga, LV-1586',
            'lat' => 56.95070,
            'lon' => 24.11620,
            'place_type' => 'concert_hall',
        ],
        'K. Suns' => [
            'venue' => 'Kinoteātris "K.Suns"',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Elizabetes iela 83/85, Rīga, LV-1050',
            'lat' => 56.95150,
            'lon' => 24.12250,
            'place_type' => 'cinema',
        ],
        'Teātra-klubs "Hamlets"' => [
            'venue' => 'Teātra klubs "Hamlets"',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Jāņa sēta 5, Rīga, LV-1050',
            'lat' => 56.94780,
            'lon' => 24.11050,
            'place_type' => 'theatre',
        ],
        'Mūzikas nams “Daile”' => [
            'venue' => 'Mūzikas nams "Daile"',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Krišjāņa Barona iela 31, Rīga, LV-1011',
            'lat' => 56.95470,
            'lon' => 24.12720,
            'place_type' => 'concert_hall',
        ],
        'Mūzikas nams "Daile"' => [
            'venue' => 'Mūzikas nams "Daile"',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Krišjāņa Barona iela 31, Rīga, LV-1011',
            'lat' => 56.95470,
            'lon' => 24.12720,
            'place_type' => 'concert_hall',
        ],
        'Hanzas Perons' => [
            'venue' => 'Hanzas perons',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Hanzas iela 16A, Rīga, LV-1045',
            'lat' => 56.96520,
            'lon' => 24.11180,
            'place_type' => 'concert_hall',
        ],
        'K. K. fon Stricka villa' => [
            'venue' => 'K. K. fon Stricka villa',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Aristida Briāna iela 9, Rīga, LV-1001',
            'lat' => 56.96370,
            'lon' => 24.13060,
            'place_type' => 'cultural_centre',
        ],
        'Rīgas mazākais kabarē' => [
            'venue' => 'Rīgas mazākais kabarē (K. K. fon Stricka villa)',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Aristida Briāna iela 9, Rīga, LV-1001',
            'lat' => 56.96370,
            'lon' => 24.13060,
            'place_type' => 'cultural_centre',
        ],
        'Dobeles pils' => [
            'venue' => 'Dobeles Pils',
            'city' => 'Dobele',
            'region' => 'Zemgale',
            'address' => 'Tērvetes iela, Dobele, LV-3701',
            'lat' => 56.62310,
            'lon' => 23.27530,
            'place_type' => 'castle',
        ],
        'Dobeles Pils' => [
            'venue' => 'Dobeles Pils',
            'city' => 'Dobele',
            'region' => 'Zemgale',
            'address' => 'Tērvetes iela, Dobele, LV-3701',
            'lat' => 56.62310,
            'lon' => 23.27530,
            'place_type' => 'castle',
        ],
        'Vecauces pils' => [
            'venue' => 'Vecauces pils',
            'city' => 'Auce',
            'region' => 'Zemgale',
            'address' => 'Akadēmijas iela 11A, Vecauce, Dobeles novads, LV-3708',
            'lat' => 56.46740,
            'lon' => 22.90560,
            'place_type' => 'castle',
        ],
        'Štakeldangas muiža' => [
            'venue' => 'Štakeldangas muiža',
            'city' => 'Lielvārde',
            'region' => 'Vidzeme',
            'address' => 'Lielvārde, Ogres novads, LV-5070',
            'lat' => 56.71670,
            'lon' => 24.80000,
            'place_type' => 'cultural_centre',
        ],
        'Rendas kultūras nams' => [
            'venue' => 'Rendas pagasta kultūras nams',
            'city' => 'Renda',
            'region' => 'Kurzeme',
            'address' => 'Renda, Kuldīgas novads, LV-3319',
            'lat' => 57.06730,
            'lon' => 22.02380,
            'place_type' => 'cultural_centre',
        ],
        'VYBRA studija' => [
            'venue' => 'VYBRA studija',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Rīga, Latvija',
            'lat' => 56.94960,
            'lon' => 24.10520,
            'place_type' => 'cultural_centre',
        ],
        'Dimension Ink | Cēsis' => [
            'venue' => 'Dimension Ink',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Cēsis, Latvija',
            'lat' => 57.31190,
            'lon' => 25.27430,
            'place_type' => 'cultural_centre',
        ],
        'DIMENSION INK Cēsis' => [
            'venue' => 'Dimension Ink',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Cēsis, Latvija',
            'lat' => 57.31190,
            'lon' => 25.27430,
            'place_type' => 'cultural_centre',
        ],
        'Zunda Towers' => [
            'venue' => 'Zunda Towers',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Raņķa dambis 30, Rīga, LV-1048',
            'lat' => 56.94890,
            'lon' => 24.07680,
            'place_type' => 'cultural_centre',
        ],
        'Fantadroms' => [
            'venue' => 'Fantadroms (Jaunā Teika)',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Gustava Zemgala gatve 74, Rīga, LV-1039',
            'lat' => 56.97440,
            'lon' => 24.17560,
            'place_type' => 'cultural_centre',
        ],
        'Baza dance company' => [
            'venue' => 'Baza dance company',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Rīga, Latvija',
            'lat' => 56.94960,
            'lon' => 24.10520,
            'place_type' => 'cultural_centre',
        ],
        'Mežaparka Lielā estrāde' => [
            'venue' => 'Mežaparka Lielā estrāde',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Ostas prospekts 11, Rīga, LV-1054',
            'lat' => 57.00160,
            'lon' => 24.15650,
            'place_type' => 'concert_hall',
        ],
        'Kalnsētas parks' => [
            'venue' => 'Kalnsētas parks',
            'city' => 'Saldus',
            'region' => 'Kurzeme',
            'address' => 'Kalnsētas iela, Saldus, LV-3801',
            'lat' => 56.66670,
            'lon' => 22.49500,
            'place_type' => 'park',
        ],
        'Liepājas pludmale' => [
            'venue' => 'Liepājas pludmale',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Liepāja, Latvija',
            'lat' => 56.51260,
            'lon' => 21.01330,
            'place_type' => 'park',
        ],
        'RTU auditoriju centrs “Domus Auditorialis”' => [
            'venue' => 'RTU "Domus Auditorialis"',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Zunda krastmala 8, Rīga, LV-1048',
            'lat' => 56.95380,
            'lon' => 24.08310,
            'place_type' => 'university',
        ],
    ];

    public function __construct()
    {
        parent::__construct();
        $this->httpClient = new Client([
            'timeout' => 20,
            'connect_timeout' => 10,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                'Accept' => 'application/json, text/javascript, */*; q=0.01',
                'Accept-Language' => 'lv,en-US;q=0.8,en;q=0.5',
                'X-Requested-With' => 'XMLHttpRequest',
                'Referer' => self::DEFAULT_URL,
                'Origin' => self::BASE_URL,
            ],
            'verify' => false,
        ]);
    }

    public function getSlug(): string
    {
        return 'aula';
    }

    public function getName(): string
    {
        return 'Aula (aula.lv)';
    }

    public function scrape(Source $source): Collection
    {
        $postUrl = $source->url ?: self::DEFAULT_URL;
        $events = collect();
        $seenUrls = [];

        $page = 1;
        $maxPages = 20;

        while ($page <= $maxPages) {
            try {
                $response = $this->httpClient->post($postUrl, [
                    'form_params' => [
                        'act' => 'loadEvents',
                        'p' => $page,
                        'f' => '',
                        'all' => 0,
                    ],
                ]);

                $body = (string) $response->getBody();
                $data = json_decode($body, true);

                if (!$data || empty($data['html'])) {
                    break;
                }

                $crawler = new Crawler($data['html'], self::BASE_URL);
                $cards = $crawler->filter('article.event-block');

                if ($cards->count() === 0) {
                    break;
                }

                $cards->each(function (Crawler $card) use (&$events, &$seenUrls) {
                    try {
                        $dto = $this->parseCard($card);
                        if ($dto && !isset($seenUrls[$dto->sourceUrl])) {
                            $seenUrls[$dto->sourceUrl] = true;
                            $events->push($dto);
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Error parsing Aula event card: ' . $e->getMessage(), [
                            'scraper' => static::class,
                            'exception' => $e,
                        ]);
                    }
                });

                // Status 'hide' indicates no more pages available
                if (($data['status'] ?? '') === 'hide') {
                    break;
                }

                $page++;
            } catch (\Throwable $e) {
                Log::error("Aula scraper request failed on page {$page}: " . $e->getMessage(), [
                    'scraper' => static::class,
                ]);
                break;
            }
        }

        return $events;
    }

    /**
     * Parse a single event card from Aula /visi AJAX response
     */
    public function parseCard(Crawler $card, bool $fetchDetail = true): ?ScrapedEventDTO
    {
        // 1. Title
        $titleNode = $card->filter('.title h2, a.title');
        if ($titleNode->count() === 0) {
            return null;
        }
        $title = trim($titleNode->text());
        if (empty($title)) {
            return null;
        }

        // 2. Link & URL
        $linkNode = $card->filter('a.title, a.thumb');
        if ($linkNode->count() === 0) {
            return null;
        }
        $rawHref = $linkNode->attr('href') ?? '';
        if (empty($rawHref)) {
            return null;
        }

        $cleanHref = trim($rawHref);
        $fullUrl = str_starts_with($cleanHref, 'http') ? $cleanHref : self::BASE_URL . $cleanHref;

        // External ID
        $externalId = null;
        if (preg_match('/[?&]event=(\d+)/', $fullUrl, $m)) {
            $externalId = 'aula-' . $m[1];
        } else {
            $slugPart = basename(parse_url($fullUrl, PHP_URL_PATH));
            $externalId = 'aula-' . ($slugPart ?: md5($fullUrl));
        }

        // 3. Date & Time
        $timeNode = $card->filter('time.date');
        if ($timeNode->count() === 0) {
            // E.g. "Dažādi norises laiki" (gift cards, open vouchers)
            return null;
        }

        $datetimeAttr = $timeNode->attr('datetime') ?? '';
        if (empty($datetimeAttr)) {
            return null;
        }

        try {
            $startAt = Carbon::parse($datetimeAttr, 'Europe/Riga');
        } catch (\Throwable $e) {
            return null;
        }

        // AGENTS.md rule: Strictly target current and upcoming events
        if ($startAt->lt(Carbon::today('Europe/Riga'))) {
            return null;
        }

        $endAt = null;

        // 4. Location
        $placeNode = $card->filter('.place');
        $rawPlace = $placeNode->count() ? trim($placeNode->text()) : '';
        $location = $this->resolveVenue($rawPlace);

        // 5. Image
        $imgNode = $card->filter('img.image, img');
        $rawImg = $imgNode->count() ? $imgNode->attr('src') : null;
        if ($rawImg) {
            $cleanImg = preg_replace('/\?.*$/', '', $rawImg);
            $imageUrl = str_starts_with($cleanImg, 'http') ? $cleanImg : self::BASE_URL . $cleanImg;
        } else {
            $imageUrl = self::DEFAULT_IMAGE;
        }

        // 6. Prices
        $priceNode = $card->filter('.price');
        $rawPrice = $priceNode->count() ? trim($priceNode->text()) : '';
        [$priceMin, $priceMax] = $this->parsePrices($rawPrice);

        // 7. Category detection adhering to AGENTS.md
        [$category, $entertainmentType] = $this->detectCategory($title, $location['venue']);

        // 8. Full description from detail page if available
        $detailDesc = $fetchDetail ? $this->fetchDetailDescription($fullUrl) : null;
        $description = $detailDesc ?: "Pasākums: {$title}.\n\nNorises vieta: {$location['venue']}, {$location['city']}.\nBiļetes pieejamas Aula.lv platformā.";
        $shortDescription = Str::limit(strip_tags($description), 160);

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
            priceMin: $priceMin,
            priceMax: $priceMax,
            currency: 'EUR',
            ticketUrl: $fullUrl,
            imageUrl: $imageUrl,
            sourceUrl: $fullUrl,
            sourceExternalId: $externalId,
            locale: 'lv',
            rawData: [
                'raw_place' => $rawPlace,
                'raw_price' => $rawPrice,
                'datetime' => $datetimeAttr,
                'aula_url' => $fullUrl,
            ]
        );
    }

    public function fetchDetailDescription(string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        if (array_key_exists($url, $this->detailDescriptionCache)) {
            return $this->detailDescriptionCache[$url];
        }

        $crawler = $this->fetchCrawler($url);
        if (!$crawler) {
            $this->detailDescriptionCache[$url] = null;
            return null;
        }

        try {
            $paras = [];
            $node = $crawler->filter('.custom-event-content-section .toggleable-content, .custom-event-content-section .text-content, .inner-content.text-content');
            if ($node->count() > 0) {
                $node->filter('p, h2, h3, h4, li')->each(function (Crawler $p) use (&$paras) {
                    $t = html_entity_decode(strip_tags($p->text('')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $t = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $t);
                    $t = trim(preg_replace('/\s+/u', ' ', $t));
                    if (!empty($t)) {
                        $paras[] = $t;
                    }
                });
            }

            if (empty($paras) && $node->count() > 0) {
                $raw = html_entity_decode(strip_tags($node->text('')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $raw = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $raw);
                $raw = trim(preg_replace('/\s+/u', ' ', $raw));
                if (!empty($raw)) {
                    $paras[] = $raw;
                }
            }

            $desc = !empty($paras) ? implode("\n\n", $paras) : null;
            $this->detailDescriptionCache[$url] = $desc;
            return $desc;
        } catch (\Throwable $e) {
            Log::warning("Aula: failed to extract detail description from {$url}: " . $e->getMessage());
            $this->detailDescriptionCache[$url] = null;
            return null;
        }
    }

    /**
     * Extract min and max ticket prices
     */
    public function parsePrices(string $rawPrice): array
    {
        if (empty($rawPrice) || stripos($rawPrice, 'izpārdot') !== false) {
            return [null, null];
        }

        $cleanPrice = str_replace([',', ' '], ['', ''], $rawPrice);
        if (preg_match_all('/(\d+(?:\.\d{1,2})?)/', $cleanPrice, $m) && !empty($m[1])) {
            $numbers = array_map('floatval', $m[1]);
            return [min($numbers), max($numbers)];
        }

        return [null, null];
    }

    /**
     * Resolve venue, address and coordinates
     */
    public function resolveVenue(string $rawVenue): array
    {
        $cleanVenue = trim(preg_replace('/\s+/', ' ', $rawVenue));

        if (empty($cleanVenue)) {
            return [
                'venue' => 'Latvija',
                'city' => 'Rīga',
                'region' => 'Rīga un Pierīga',
                'address' => 'Rīga, Latvija',
                'lat' => 56.9496,
                'lon' => 24.1052,
                'place_type' => 'cultural_centre',
            ];
        }

        // Exact match in known venues
        if (isset($this->venues[$cleanVenue])) {
            return $this->venues[$cleanVenue];
        }

        // Fuzzy match
        $normalizedInput = str_replace(['"', '“', '”', '«', '»'], '', mb_strtolower($cleanVenue));
        foreach ($this->venues as $name => $data) {
            $normalizedName = str_replace(['"', '“', '”', '«', '»'], '', mb_strtolower($name));
            if (str_contains($normalizedInput, $normalizedName) || str_contains($normalizedName, $normalizedInput)) {
                return $data;
            }
        }

        // Fallback by parsing city from venue string
        $city = 'Rīga';
        $region = 'Rīga un Pierīga';

        $cities = [
            'Rīga' => 'Rīga un Pierīga', 'Riga' => 'Rīga un Pierīga', 'Jūrmala' => 'Rīga un Pierīga',
            'Sigulda' => 'Vidzeme', 'Cēsis' => 'Vidzeme', 'Valmiera' => 'Vidzeme', 'Ogre' => 'Vidzeme',
            'Lielvārde' => 'Vidzeme', 'Madona' => 'Vidzeme', 'Smiltene' => 'Vidzeme',
            'Liepāja' => 'Kurzeme', 'Ventspils' => 'Kurzeme', 'Kuldīga' => 'Kurzeme', 'Talsi' => 'Kurzeme', 'Saldus' => 'Kurzeme', 'Renda' => 'Kurzeme',
            'Rēzekne' => 'Latgale', 'Daugavpils' => 'Latgale', 'Jēkabpils' => 'Latgale',
            'Jelgava' => 'Zemgale', 'Bauska' => 'Zemgale', 'Dobele' => 'Zemgale', 'Auce' => 'Zemgale', 'Aizkraukle' => 'Zemgale',
        ];

        foreach ($cities as $cName => $rName) {
            if (stripos($cleanVenue, $cName) !== false) {
                $city = $cName;
                $region = $rName;
                break;
            }
        }

        return [
            'venue' => $cleanVenue,
            'city' => $city,
            'region' => $region,
            'address' => "{$cleanVenue}, {$city}, Latvija",
            'lat' => null,
            'lon' => null,
            'place_type' => 'cultural_centre',
        ];
    }

    /**
     * Strict Category Detection adhering to AGENTS.md rules
     */
    public function detectCategory(string $title, string $venue): array
    {
        $titleLower = mb_strtolower($title, 'UTF-8');
        $venueLower = mb_strtolower($venue, 'UTF-8');
        $full = $titleLower . ' ' . $venueLower;

        // 1. Strict cinema check (AGENTS.md)
        $cinemaVenues = ['k. suns', 'k.suns', 'k suns', 'kino rio', 'forum cinemas', 'apollo kino', 'cinamon', 'splendid palace', 'kino bize', 'kino lora', 'kino gaisma'];
        foreach ($cinemaVenues as $cv) {
            if (str_contains($venueLower, $cv) || str_contains($titleLower, $cv)) {
                return ['Kino', 'cinema'];
            }
        }

        $isCinemaContent = preg_match('/\b(kinoseanss|filmas seanss|spēlfilma|dokumentālā filma|animācijas filma|kinofestivāls)\b/ui', $titleLower)
            || preg_match('/\b(filma|filmas|filmā)\b/ui', $titleLower);

        $isFilmMuxicOrConcert = preg_match('/\b(koncerts|klavierkoncerts|mūzika|orķestris|kvartets|dziesma|dzied|tour|šovs|show|filmu mūzik|kinomūzik|kino skaņās)\b/ui', $titleLower);

        if ($isCinemaContent && !$isFilmMuxicOrConcert) {
            return ['Kino', 'cinema'];
        }

        // 2. Kids / Family
        if (preg_match('/\b(bērn|bērniem|ģimenēm|kids|children|pasaka)\b/u', $titleLower)) {
            return ['Bērniem', 'family'];
        }

        // 3. Theater (Strict: only actual theater productions, never kinoteātris)
        if (preg_match('/\b(izrāde|luga|iestudējums|opera|balets|teātr|improvizācij|impro\b|kabarē)/u', $full) && !str_contains($full, 'kinoteātr')) {
            return ['Teātris', 'theater'];
        }

        // 4. Music / Concert
        if (preg_match('/\b(koncerts|koris|kora|dziesm|dzied|mūzik|grupa|ansamblis|orķestr|festivāls|festivālā|balle|dance|solo|summer sound)\b/u', $titleLower)) {
            return ['Mūzika', 'concert'];
        }

        // 5. Exhibitions / Art
        if (preg_match('/\b(izstāde|māksla|glezn|ekspozīcija|muzejs)\b/u', $titleLower)) {
            return ['Izstādes', 'exhibition'];
        }

        // 6. Education / Conferences / Lectures
        if (preg_match('/\b(konference|seminārs|lekcija|meistarklase|darbnīca)\b/u', $titleLower)) {
            return ['Izglītība', 'conference'];
        }

        // 7. Sports
        if (preg_match('/\b(sports|skrējiens|maratons|turnīrs|spēle|mačs|čempionāts)\b/u', $titleLower)) {
            return ['Sports', 'sports'];
        }

        // 8. Fallback to Cits (AGENTS.md rule 3)
        return ['Cits', 'other'];
    }
}

