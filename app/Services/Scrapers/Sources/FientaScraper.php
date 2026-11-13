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

class FientaScraper extends BaseScraper
{
    public const BASE_URL = 'https://fienta.com';
    public const DEFAULT_URL = 'https://fienta.com/lv';
    public const DEFAULT_IMAGE = 'https://fienta.com/assets_statamic/statamic/opengraph/fienta-og-en.png';

    protected array $detailDescriptionCache = [];

    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mai' => 5, 'jūn' => 6,
        'jūl' => 7, 'aug' => 8, 'sep' => 9, 'okt' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /**
     * Common Latvian venues on Fienta with verified coordinates and addresses
     */
    protected array $venues = [
        'Kinoteātris "K.Suns"' => [
            'venue' => 'Kinoteātris "K.Suns"',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Elizabetes iela 83/85, Rīga, LV-1050',
            'lat' => 56.95150,
            'lon' => 24.12250,
            'place_type' => 'cinema',
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
        'The Salamandra Cinema' => [
            'venue' => 'The Salamandra Cinema',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Salamandras iela 1, Rīga, LV-1024',
            'lat' => 56.99420,
            'lon' => 24.21850,
            'place_type' => 'cinema',
        ],
        'Dailes teātris' => [
            'venue' => 'Dailes teātris',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Brīvības iela 75, Rīga, LV-1001',
            'lat' => 56.95920,
            'lon' => 24.12870,
            'place_type' => 'theatre',
        ],
        'M/Darbnīca' => [
            'venue' => 'M/Darbnīca',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Aristida Briāna iela 9, Rīga, LV-1001',
            'lat' => 56.96340,
            'lon' => 24.13030,
            'place_type' => 'concert_hall',
        ],
        'Hanzas perons' => [
            'venue' => 'Hanzas perons',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Hanzas iela 16A, Rīga, LV-1045',
            'lat' => 56.96520,
            'lon' => 24.11180,
            'place_type' => 'concert_hall',
        ],
        'Kaņepes Kultūras centrs' => [
            'venue' => 'Kaņepes Kultūras centrs',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Skolas iela 15, Rīga, LV-1010',
            'lat' => 56.95750,
            'lon' => 24.11980,
            'place_type' => 'cultural_centre',
        ],
        'Theater-club "Hamlets"' => [
            'venue' => 'Teātra klubs "Hamlets"',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Jāņa sēta 5, Rīga, LV-1050',
            'lat' => 56.94780,
            'lon' => 24.11050,
            'place_type' => 'theatre',
        ],
        'Rīgas Svētā Pāvila evaņģēliski luteriskā baznīca' => [
            'venue' => 'Rīgas Svētā Pāvila evaņģēliski luteriskā baznīca',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Augusta Deglava iela 1, Rīga, LV-1009',
            'lat' => 56.95190,
            'lon' => 24.14810,
            'place_type' => 'church',
        ],
        'Masonic Museum Riga' => [
            'venue' => 'Masonic Museum Riga',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Baznīcas iela 32, Rīga, LV-1010',
            'lat' => 56.95820,
            'lon' => 24.12350,
            'place_type' => 'museum',
        ],
        'Vagonu Hall' => [
            'venue' => 'Vagonu Hall',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Vagonu iela 21, Rīga, LV-1009',
            'lat' => 56.94580,
            'lon' => 24.15680,
            'place_type' => 'concert_hall',
        ],
        'Laska V21' => [
            'venue' => 'Laska V21',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Vagonu iela 21, Rīga, LV-1009',
            'lat' => 56.94580,
            'lon' => 24.15680,
            'place_type' => 'concert_hall',
        ],
        'ATTA CENTRE' => [
            'venue' => 'ATTA CENTRE',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Krasta iela 73, Rīga, LV-1019',
            'lat' => 56.93280,
            'lon' => 24.15420,
            'place_type' => 'cultural_centre',
        ],
        'Baltic Beach Hotel & SPA' => [
            'venue' => 'Baltic Beach Hotel & SPA',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Jūras iela 23/25, Jūrmala, LV-2015',
            'lat' => 56.97400,
            'lon' => 23.79750,
            'place_type' => 'hotel',
        ],
        'ROOM Jūrmala' => [
            'venue' => 'ROOM Jūrmala',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Jūrmala, Latvija',
            'lat' => 56.97150,
            'lon' => 23.79320,
            'place_type' => 'cultural_centre',
        ],
        'Publisko un privāto partnerattiecību biedrība Zied zeme' => [
            'venue' => 'Biedrība "Zied zeme"',
            'city' => 'Lielvārde',
            'region' => 'Vidzeme',
            'address' => 'Lāčplēša iela 14, Lielvārde, LV-5070',
            'lat' => 56.71950,
            'lon' => 24.80950,
            'place_type' => 'cultural_centre',
        ],
        'FonoKlubs' => [
            'venue' => 'FonoKlubs',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Rīgas iela 47, Cēsis, LV-4101',
            'lat' => 57.31500,
            'lon' => 25.27600,
            'place_type' => 'concert_hall',
        ],
        'DIČ BAR' => [
            'venue' => 'DIČ BAR',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Viestura iela 53, Daugavpils, LV-5401',
            'lat' => 55.87450,
            'lon' => 26.52180,
            'place_type' => 'bar',
        ],
        'Benjamiņu nams' => [
            'venue' => 'Benjamiņu nams',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Krišjāņa Barona iela 12, Rīga, LV-1050',
            'lat' => 56.95160,
            'lon' => 24.12070,
            'place_type' => 'cultural_centre',
        ],
        'Nordeķu muiža' => [
            'venue' => 'Nordeķu muiža',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Buļļu iela 16, Rīga, LV-1055',
            'lat' => 56.96310,
            'lon' => 24.05830,
            'place_type' => 'cultural_centre',
        ],
    ];

    public function __construct()
    {
        parent::__construct();
        $this->httpClient = new Client([
            'timeout' => 20,
            'connect_timeout' => 10,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'lv,en-US;q=0.8,en;q=0.5',
            ],
            'verify' => false,
            'allow_redirects' => true,
        ]);
    }

    public function getSlug(): string
    {
        return 'fienta';
    }

    public function getName(): string
    {
        return 'Fienta';
    }

    public function scrape(Source $source): Collection
    {
        $startUrl = $source->url ?: self::DEFAULT_URL;
        $events = collect();
        $seenUrls = [];

        $page = 1;
        $maxPages = 30; // Safety cap

        while ($page <= $maxPages) {
            $pageUrl = $page === 1
                ? $startUrl
                : $startUrl . (str_contains($startUrl, '?') ? '&' : '?') . 'page=' . $page;

            $crawler = $this->fetchCrawler($pageUrl);
            if (!$crawler) {
                break;
            }

            $cards = $crawler->filter('#events .event-card');
            if ($cards->count() === 0) {
                break;
            }

            // Read last page dynamically from root container data-lastpage
            $eventsContainer = $crawler->filter('#events');
            if ($eventsContainer->count() && $page === 1) {
                $lastPageAttr = (int) $eventsContainer->attr('data-lastpage');
                if ($lastPageAttr > 0) {
                    $maxPages = min($lastPageAttr, 40);
                }
            }

            $cards->each(function (Crawler $card) use (&$events, &$seenUrls) {
                try {
                    $dto = $this->parseCard($card);
                    if ($dto && !isset($seenUrls[$dto->sourceUrl])) {
                        $seenUrls[$dto->sourceUrl] = true;
                        $events->push($dto);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Error parsing Fienta event card: ' . $e->getMessage(), [
                        'scraper' => static::class,
                        'exception' => $e,
                    ]);
                }
            });

            $page++;
        }

        return $events;
    }

    /**
     * Parse a single event card from Fienta root page
     */
    public function parseCard(Crawler $card, bool $fetchDetail = true): ?ScrapedEventDTO
    {
        // 1. Link & URL
        $linkNode = $card->filter('a');
        if ($linkNode->count() === 0) {
            return null;
        }

        $rawHref = $linkNode->attr('href') ?? '';
        if (empty($rawHref)) {
            return null;
        }

        $cleanUrl = preg_replace('/\?.*$/', '', $rawHref);
        $fullUrl = str_starts_with($cleanUrl, 'http') ? $cleanUrl : self::BASE_URL . $cleanUrl;

        // External ID
        $path = parse_url($fullUrl, PHP_URL_PATH);
        $pathClean = trim(str_replace(['/lv/', '/s/', '/'], '-', $path), '-');
        $externalId = 'fienta-' . ($pathClean ?: md5($fullUrl));

        // 2. Title
        $titleNode = $card->filter('.event-card-title h2, .event-card-title');
        if ($titleNode->count() === 0) {
            return null;
        }
        $title = trim($titleNode->text());
        if (empty($title)) {
            return null;
        }

        // 3. Date & Time
        $timeNode = $card->filter('time');
        $datetimeAttr = $timeNode->count() ? ($timeNode->attr('datetime') ?? '') : '';
        $timeText = $timeNode->count() ? trim($timeNode->text()) : '';

        [$startAt, $endAt] = $this->parseDateTime($datetimeAttr, $timeText);
        if (!$startAt) {
            return null;
        }

        // AGENTS.md rule: Strictly target current and upcoming events
        $today = Carbon::today('Europe/Riga');
        if ($startAt->lt($today) && (!$endAt || $endAt->lt($today))) {
            return null;
        }

        // 4. Location
        $locNode = $card->filter('.small.text-body-secondary');
        $rawLocation = $locNode->count() ? trim($locNode->text()) : '';
        $location = $this->resolveVenue($rawLocation);

        // 5. Image
        $imgNode = $card->filter('.event-card-img img, picture img, img');
        $rawImg = $imgNode->count() ? $imgNode->attr('src') : null;
        if ($rawImg) {
            $imageUrl = str_starts_with($rawImg, 'http') ? $rawImg : self::BASE_URL . $rawImg;
        } else {
            $imageUrl = self::DEFAULT_IMAGE;
        }

        // 6. Category detection adhering to AGENTS.md
        [$category, $entertainmentType] = $this->detectCategory($title, $location['venue']);

        // 7. Full description from detail page if available
        $detailDesc = $fetchDetail ? $this->fetchDetailDescription($fullUrl) : null;
        $description = $detailDesc ?: "Pasākums: {$title}.\n\nNorises vieta: {$location['venue']}, {$location['city']}.\nBiļetes un reģistrācija pieejama Fienta platformā.";
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
            priceMin: null,
            priceMax: null,
            currency: 'EUR',
            ticketUrl: $fullUrl,
            imageUrl: $imageUrl,
            sourceUrl: $fullUrl,
            sourceExternalId: $externalId,
            locale: 'lv',
            rawData: [
                'raw_location' => $rawLocation,
                'raw_time_text' => $timeText,
                'datetime_attr' => $datetimeAttr,
                'fienta_url' => $fullUrl,
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
            $node = $crawler->filter('#desc .rich-text, #desc, .event-description');
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
            Log::warning("Fienta: failed to extract detail description from {$url}: " . $e->getMessage());
            $this->detailDescriptionCache[$url] = null;
            return null;
        }
    }

    /**
     * Parse start and optional end datetime
     */
    public function parseDateTime(string $datetimeAttr, string $timeText): array
    {
        $startAt = null;
        $endAt = null;

        if (!empty($datetimeAttr)) {
            try {
                $startAt = Carbon::parse($datetimeAttr, 'Europe/Riga');
            } catch (\Throwable $e) {
                $startAt = null;
            }
        }

        // Check for range in text (e.g. "S. 14. nov. 10:00 - Sv. 6. dec. 13:00")
        if (str_contains($timeText, ' - ')) {
            $parts = explode(' - ', $timeText);
            $endPart = trim($parts[1]);
            if (preg_match('/(\d{1,2})\.\s*([a-zA-Zāčēģīķļņšūž]+)\.?(?:\s*(\d{4}))?(?:\s+(\d{1,2}):(\d{2}))?/ui', $endPart, $m)) {
                $day = (int) $m[1];
                $monthPrefix = mb_substr(mb_strtolower($m[2]), 0, 3);
                $month = self::MONTHS[$monthPrefix] ?? null;
                $year = !empty($m[3]) ? (int) $m[3] : ($startAt ? $startAt->year : (int) date('Y'));
                $hour = isset($m[4]) ? (int) $m[4] : 0;
                $min = isset($m[5]) ? (int) $m[5] : 0;

                if ($month) {
                    $endAt = Carbon::create($year, $month, $day, $hour, $min, 0, 'Europe/Riga');
                    if ($startAt && $endAt->lt($startAt)) {
                        $endAt->addYear();
                    }
                }
            }
        }

        return [$startAt, $endAt];
    }

    /**
     * Resolve venue, address and coordinates
     */
    public function resolveVenue(string $rawLocation): array
    {
        $cleanLocation = trim(preg_replace('/\s+/', ' ', $rawLocation));

        // 1. Online event
        if (empty($cleanLocation) || stripos($cleanLocation, 'tiešsaist') !== false || stripos($cleanLocation, 'online') !== false) {
            return [
                'venue' => 'Tiešsaistē',
                'city' => 'Rīga',
                'region' => 'Rīga un Pierīga',
                'address' => 'Tiešsaistē',
                'lat' => null,
                'lon' => null,
                'place_type' => 'online',
            ];
        }

        // 2. Multiple venues
        if (stripos($cleanLocation, 'vairāk') !== false) {
            return [
                'venue' => 'Vairākas norises vietas',
                'city' => 'Rīga',
                'region' => 'Rīga un Pierīga',
                'address' => 'Latvija',
                'lat' => null,
                'lon' => null,
                'place_type' => 'cultural_centre',
            ];
        }

        // 3. Exact or fuzzy match in known venues
        $venuePart = $cleanLocation;
        $cityPart = 'Rīga';

        if (str_contains($cleanLocation, ',')) {
            $parts = array_map('trim', explode(',', $cleanLocation));
            $venuePart = $parts[0];
            $cityPart = end($parts);
        }

        // Check dictionary with full cleanLocation or venuePart
        foreach ($this->venues as $name => $data) {
            if (
                stripos($cleanLocation, $name) !== false
                || stripos($venuePart, $name) !== false
                || stripos($name, $venuePart) !== false
            ) {
                return $data;
            }
        }

        // 4. Resolve city and region
        $city = 'Rīga';
        $region = 'Rīga un Pierīga';

        $cities = [
            'Rīga' => 'Rīga un Pierīga', 'Riga' => 'Rīga un Pierīga', 'Jūrmala' => 'Rīga un Pierīga',
            'Sigulda' => 'Vidzeme', 'Cēsis' => 'Vidzeme', 'Valmiera' => 'Vidzeme', 'Ogre' => 'Vidzeme',
            'Lielvārde' => 'Vidzeme', 'Madona' => 'Vidzeme', 'Smiltene' => 'Vidzeme', 'Cēsu novads' => 'Vidzeme',
            'Liepāja' => 'Kurzeme', 'Ventspils' => 'Kurzeme', 'Kuldīga' => 'Kurzeme', 'Talsi' => 'Kurzeme',
            'Rēzekne' => 'Latgale', 'Daugavpils' => 'Latgale', 'Jēkabpils' => 'Latgale',
            'Jelgava' => 'Zemgale', 'Bauska' => 'Zemgale', 'Dobele' => 'Zemgale', 'Aizkraukle' => 'Zemgale',
        ];

        foreach ($cities as $cName => $rName) {
            if (stripos($cleanLocation, $cName) !== false) {
                $city = ($cName === 'Riga' || $cName === 'Cēsu novads') ? ($cName === 'Riga' ? 'Rīga' : 'Cēsis') : $cName;
                $region = $rName;
                break;
            }
        }

        return [
            'venue' => $venuePart,
            'city' => $city,
            'region' => $region,
            'address' => "{$venuePart}, {$city}, Latvija",
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
        $cinemaVenues = ['k. suns', 'k.suns', 'k suns', 'kino rio', 'forum cinemas', 'apollo kino', 'cinamon', 'splendid palace', 'kino bize', 'kino lora', 'kino gaisma', 'salamandra cinema'];
        foreach ($cinemaVenues as $cv) {
            if (str_contains($venueLower, $cv) || str_contains($titleLower, $cv)) {
                return ['Kino', 'cinema'];
            }
        }

        $isCinemaContent = preg_match('/\b(film screening|filmas seanss|kinoseanss|filma|filmas|filmā|spēlfilma|dokumentālā filma|animācijas filma|kinofestivāls)\b/ui', $titleLower);
        $isFilmMuxicOrConcert = preg_match('/\b(koncerts|klavierkoncerts|mūzika|orķestris|kvartets|dziesma|dzied|tour|šovs|show|filmu mūzik|kinomūzik)\b/ui', $titleLower);

        if ($isCinemaContent && !$isFilmMuxicOrConcert) {
            return ['Kino', 'cinema'];
        }

        // 2. Kids / Family
        if (preg_match('/\b(bērn|bērniem|ģimenēm|kids|children|pasaka)\b/u', $titleLower)) {
            return ['Bērniem', 'family'];
        }

        // 3. Theater (Strict: only actual theater productions, never kinoteātris)
        if (preg_match('/\b(izrāde|lekcijizrāde|luga|iestudējums|opera|balets|teātris|teātrī)\b/u', $titleLower) && !str_contains($full, 'kinoteātr')) {
            return ['Teātris', 'theater'];
        }

        // 4. Music / Concert
        if (preg_match('/\b(koncerts|koncertā|mūzik|dziesm|dzied|koris|kora|orķestr|jazz|rock|akustiski|rave|live in|all-stars|quintet|trio|balādes|festivāl|tūre|tour)\b/u', $titleLower)) {
            return ['Mūzika', 'concert'];
        }

        // 5. Exhibitions / Art
        if (preg_match('/\b(izstāde|izstādē|ekspozīcija|art|muzejs|skicēšana|māksl)\b/u', $titleLower)) {
            return ['Izstādes', 'exhibition'];
        }

        // 6. Tours / Excursions
        if (preg_match('/\b(ekskursija|ekskursijā|tour|apmeklējums)\b/u', $titleLower)) {
            return ['Ekskursijas', 'tour'];
        }

        // 7. Education / Workshops / Lectures
        if (preg_match('/\b(meistarklase|darbnīca|lekcija|lecture|seminārs|kursi|apmācība|supervision|arhitektūra|masterclass|мастер-класс)\b/u', $titleLower)) {
            return ['Izglītība', 'workshop'];
        }

        // 8. Sports
        if (preg_match('/\b(sports|skrējiens|maratons|turnīrs|spēle|mačs|čempionāts|yoga|joga)\b/u', $titleLower)) {
            return ['Sports', 'sports'];
        }

        // 9. Fallback (AGENTS.md rule 3)
        return ['Cits', 'other'];
    }
}

