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

class LiveRigaScraper extends BaseScraper
{
    protected ?Client $client = null;
    protected array $detailDescriptionCache = [];

    protected function getClient(): Client
    {
        if ($this->client === null) {
            $this->client = new Client([
                'timeout' => 15,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'lv,en;q=0.9',
                ],
            ]);
        }

        return $this->client;
    }

    public function setClient(Client $client): void
    {
        $this->client = $client;
    }

    public function getSlug(): string
    {
        return 'live-riga';
    }

    public function getName(): string
    {
        return 'Live Riga';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $seenExternalIds = [];
        $baseUrl = $source->url ?: 'https://www.liveriga.com/lv/apmekle/pasakumi';
        $today = Carbon::today('Europe/Riga');

        try {
            $firstPageHtml = $this->fetchPageHtml($baseUrl);
            if (!$firstPageHtml) {
                Log::warning("LiveRiga: failed to fetch initial page at {$baseUrl}");
                return $events;
            }

            $firstCrawler = new Crawler($firstPageHtml);
            $totalPages = $this->extractTotalPages($firstCrawler);

            for ($page = 1; $page <= $totalPages; $page++) {
                if ($page === 1) {
                    $crawler = $firstCrawler;
                } else {
                    $pageUrl = $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . 'page=' . $page;
                    $pageHtml = $this->fetchPageHtml($pageUrl);
                    if (!$pageHtml) {
                        break;
                    }
                    $crawler = new Crawler($pageHtml);
                }

                $cards = $crawler->filter('#event-list-container a.card.card-item');
                if ($cards->count() === 0) {
                    break;
                }

                $cards->each(function (Crawler $cardNode) use (&$events, &$seenExternalIds, $today) {
                    try {
                        $dto = $this->parseCard($cardNode, $today);
                        if ($dto && !isset($seenExternalIds[$dto->sourceExternalId])) {
                            $seenExternalIds[$dto->sourceExternalId] = true;
                            $events->push($dto);
                        }
                    } catch (\Throwable $e) {
                        Log::warning("LiveRiga: failed to parse card: " . $e->getMessage());
                    }
                });
            }
        } catch (\Throwable $e) {
            Log::error("LiveRiga scrape failed: " . $e->getMessage(), ['exception' => $e]);
        }

        return $events;
    }

    public function extractTotalPages(Crawler $crawler): int
    {
        $pagesText = $crawler->filter('.pagination .pages')->count()
            ? trim($crawler->filter('.pagination .pages')->first()->text(''))
            : '';

        if (!empty($pagesText) && preg_match('/(?:no|\/)\s*(\d+)/i', $pagesText, $m)) {
            return min(max((int)$m[1], 1), 30);
        }

        // Check highest pagination number link
        $maxPage = 1;
        $crawler->filter('.pagination ul li a')->each(function (Crawler $node) use (&$maxPage) {
            $val = trim($node->text(''));
            if (is_numeric($val) && (int)$val > $maxPage) {
                $maxPage = (int)$val;
            }
        });

        return min(max($maxPage, 1), 30);
    }

    public function parseCard(Crawler $cardNode, ?Carbon $today = null, bool $fetchDetail = true): ?ScrapedEventDTO
    {
        $today = $today ?: Carbon::today('Europe/Riga');

        $titleNode = $cardNode->filter('h3.card-title');
        if ($titleNode->count() === 0) {
            return null;
        }

        $title = html_entity_decode(strip_tags($titleNode->first()->text('')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $title);
        $title = trim(preg_replace('/\s+/u', ' ', $title));
        if (empty($title)) {
            return null;
        }

        $relUrl = $cardNode->attr('href') ?: '';
        $sourceUrl = $this->normalizeUrl($relUrl);

        $categoryText = $cardNode->filter('.card-pre-title')->count()
            ? trim(html_entity_decode(strip_tags($cardNode->filter('.card-pre-title')->first()->text('')), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            : '';

        $timeNode = $cardNode->filter('time.d-none');
        if ($timeNode->count() === 0) {
            $timeNode = $cardNode->filter('time');
        }
        $dateText = $timeNode->count() ? trim($timeNode->first()->text('')) : '';
        if (empty($dateText)) {
            return null;
        }

        $parsedDates = $this->parseDate($dateText, $today);
        if (!$parsedDates) {
            return null;
        }

        [$startAt, $endAt] = $parsedDates;

        // Skip past events
        if ($endAt->lt($today->copy()->startOfDay())) {
            return null;
        }

        // Image
        $imgNode = $cardNode->filter('figure img');
        $imageUrl = null;
        if ($imgNode->count() > 0) {
            $rawImg = $imgNode->first()->attr('src') ?: '';
            if (!empty($rawImg)) {
                $imageUrl = $this->normalizeImageUrl($rawImg);
            }
        }

        // Venue & Location
        $venueInfo = $this->resolveVenue($title, $categoryText, $sourceUrl);

        // Categories (Strict cinema priority per AGENTS.md)
        $categories = $this->resolveCategories($title, $categoryText, $venueInfo['name']);

        // External ID
        $slug = basename(parse_url($sourceUrl, PHP_URL_PATH));
        $externalId = 'liveriga-' . Str::slug($slug) . '-' . $startAt->format('Y-m-d');

        // Fetch detail description from event page if available
        $detailDesc = $fetchDetail ? $this->fetchDetailDescription($sourceUrl) : null;
        $description = $detailDesc ?: "{$title}. Norises vieta: {$venueInfo['name']}, {$venueInfo['city']}. Informācija: {$sourceUrl}";
        $shortDesc = Str::limit(strip_tags($description), 160);

        return new ScrapedEventDTO(
            title: $title,
            startAt: $startAt,
            endAt: $endAt,
            description: $description,
            shortDescription: $shortDesc,
            venueName: $venueInfo['name'],
            city: $venueInfo['city'],
            region: 'Rīga un Pierīga',
            address: $venueInfo['address'],
            latitude: $venueInfo['latitude'],
            longitude: $venueInfo['longitude'],
            categoryNames: $categories,
            imageUrl: $imageUrl,
            sourceUrl: $sourceUrl,
            sourceExternalId: $externalId,
            locale: 'lv',
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

        $html = $this->fetchPageHtml($url);
        if (!$html) {
            $this->detailDescriptionCache[$url] = null;
            return null;
        }

        try {
            $crawler = new Crawler($html);
            $paras = [];
            $node = $crawler->filter('article .textual-content, .textual-content');
            if ($node->count() > 0) {
                $node->filter('p')->each(function (Crawler $p) use (&$paras) {
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
            Log::warning("LiveRiga: failed to extract detail description from {$url}: " . $e->getMessage());
            $this->detailDescriptionCache[$url] = null;
            return null;
        }
    }

    public function parseDate(string $dateStr, Carbon $today): ?array
    {
        $monthMap = [
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mai' => 5,
            'jūn' => 6, 'jun' => 6, 'jūl' => 7, 'jul' => 7, 'aug' => 8,
            'sep' => 9, 'okt' => 10, 'nov' => 11, 'dec' => 12,
        ];

        $clean = trim(mb_strtolower($dateStr, 'UTF-8'));
        $clean = preg_replace('/\s+/', ' ', $clean);

        // Date Range: 'DD Mmm - DD Mmm' (e.g. 14 Nov - 15 Nov, 27 Nov - 03 Jan)
        if (preg_match('/^(\d{1,2})\s+([a-zāčēģīķļņšūž]+)\s*[-–—]\s*(\d{1,2})\s+([a-zāčēģīķļņšūž]+)$/ui', $clean, $m)) {
            $d1 = (int)$m[1];
            $m1Str = mb_substr($m[2], 0, 3, 'UTF-8');
            $m1 = $monthMap[$m1Str] ?? null;

            $d2 = (int)$m[3];
            $m2Str = mb_substr($m[4], 0, 3, 'UTF-8');
            $m2 = $monthMap[$m2Str] ?? null;

            if (!$m1 || !$m2) {
                return null;
            }

            $curYear = $today->year;
            $curMonth = $today->month;
            $curDay = $today->day;

            if ($m2 < $curMonth) {
                $endYear = $curYear + 1;
            } else {
                $endYear = $curYear;
            }

            if ($m1 > $m2) {
                $startYear = $endYear - 1;
            } else {
                $startYear = $endYear;
            }

            $startAt = Carbon::create($startYear, $m1, $d1, 0, 0, 0, 'Europe/Riga');
            $endAt = Carbon::create($endYear, $m2, $d2, 23, 59, 59, 'Europe/Riga');

            return [$startAt, $endAt];
        }

        // Single Day: 'DD Mmm' (e.g. 11 Nov, 02 Dec)
        if (preg_match('/^(\d{1,2})\s+([a-zāčēģīķļņšūž]+)$/ui', $clean, $m)) {
            $d = (int)$m[1];
            $mStr = mb_substr($m[2], 0, 3, 'UTF-8');
            $mon = $monthMap[$mStr] ?? null;

            if (!$mon) {
                return null;
            }

            $curYear = $today->year;
            $curMonth = $today->month;
            $curDay = $today->day;

            if ($mon < $curMonth) {
                $year = $curYear + 1;
            } else {
                $year = $curYear;
            }

            $startAt = Carbon::create($year, $mon, $d, 0, 0, 0, 'Europe/Riga');
            $endAt = $startAt->copy()->setTime(23, 59, 59);

            return [$startAt, $endAt];
        }

        return null;
    }

    public function resolveCategories(string $title, string $categoryText, ?string $venueName = null): array
    {
        $lowerTitle = mb_strtolower($title, 'UTF-8');
        $lowerCat = mb_strtolower($categoryText, 'UTF-8');
        $lowerVenue = mb_strtolower($venueName ?? '', 'UTF-8');
        $categories = [];

        // 1. Strict Cinema / Kino rule per AGENTS.md
        // Venues K. Suns, Kino Rio, Forum Cinemas, Apollo Kino, Cinamon, Splendid Palace, Kino Bize, Kino Lora, Kino Gaisma
        // Content with filma, filmas, filmu, filmā, kinoseanss, filmas seanss, spēlfilma, dokumentālā filma, animācijas filma, kinofestivāls
        $isCinema = (
            str_contains($lowerCat, 'kino') ||
            str_contains($lowerTitle, 'filma') || str_contains($lowerTitle, 'filmas') ||
            str_contains($lowerTitle, 'filmu') || str_contains($lowerTitle, 'filmā') ||
            str_contains($lowerTitle, 'kinoseans') || str_contains($lowerTitle, 'spēlfilma') ||
            str_contains($lowerTitle, 'dokumentālā filma') || str_contains($lowerTitle, 'animācijas filma') ||
            str_contains($lowerTitle, 'kinofestivāl') || str_contains($lowerTitle, 'kinoteātr') ||
            str_contains($lowerVenue, 'k. suns') || str_contains($lowerVenue, 'k.suns') || str_contains($lowerVenue, 'ksuns') ||
            str_contains($lowerVenue, 'splendid palace') || str_contains($lowerVenue, 'kino bize')
        );

        if ($isCinema) {
            return ['kino'];
        }

        // 2. Base mapping from LiveRiga pre-title
        if (str_contains($lowerCat, 'opera') || str_contains($lowerCat, 'balets')) {
            $categories[] = 'teatris';
            $categories[] = 'muzika';
        } elseif (str_contains($lowerCat, 'teātr') || str_contains($lowerCat, 'teatr')) {
            $categories[] = 'teatris';
        } elseif (str_contains($lowerCat, 'koncert') || str_contains($lowerCat, 'mūzik') || str_contains($lowerCat, 'muzik')) {
            $categories[] = 'muzika';
        } elseif (str_contains($lowerCat, 'sports')) {
            $categories[] = 'sports';
        } elseif (str_contains($lowerCat, 'izstād') || str_contains($lowerCat, 'izstad') || str_contains($lowerCat, 'gadatirg') || str_contains($lowerCat, 'laikmetīgā māksla')) {
            $categories[] = 'izstades';
        } elseif (str_contains($lowerCat, 'svētk') || str_contains($lowerCat, 'festivāl')) {
            $categories[] = 'svetki';
        } elseif (str_contains($lowerCat, 'bērn') || str_contains($lowerCat, 'ģimen')) {
            $categories[] = 'berniem';
        }

        // Content refinements
        if (preg_match('/\b(?:izrāde|luga|iestudējums|komēdija|stand-up|teātris)\b/ui', $lowerTitle) && !in_array('teatris', $categories, true)) {
            $categories[] = 'teatris';
        }

        if (preg_match('/\b(?:koncerts|festivāls|orķestris|koris|dziesm|solo koncerts)\b/ui', $lowerTitle) && !in_array('muzika', $categories, true)) {
            $categories[] = 'muzika';
        }

        if (preg_match('/\b(?:futbols|basketbols|hokejs|maratons|skrējiens|turnīrs|čempionāts|spēle|uefa|fiba)\b/ui', $lowerTitle) && !in_array('sports', $categories, true)) {
            $categories[] = 'sports';
        }

        if (preg_match('/\b(?:izstāde|ekspozīcija|galerija|muzejs)\b/ui', $lowerTitle) && !in_array('izstades', $categories, true)) {
            $categories[] = 'izstades';
        }

        if (empty($categories)) {
            $categories[] = 'citi';
        }

        return array_values(array_unique($categories));
    }

    public function resolveVenue(string $title, string $categoryText, string $sourceUrl): array
    {
        $lowerTitle = mb_strtolower($title, 'UTF-8');
        $lowerCat = mb_strtolower($categoryText, 'UTF-8');

        $venues = [
            'anglikāņu' => [
                'name' => 'Anglikāņu baznīca',
                'address' => 'Anglikāņu iela 2A, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9503,
                'longitude' => 24.1039,
            ],
            'āgenskalna tirg' => [
                'name' => 'Āgenskalna tirgus',
                'address' => 'Nometņu iela 64, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9360,
                'longitude' => 24.0722,
            ],
            'kalnciema kvartāl' => [
                'name' => 'Kalnciema kvartāls',
                'address' => 'Kalnciema iela 35, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9405,
                'longitude' => 24.0673,
            ],
            'arēna rīga' => [
                'name' => 'Arēna Rīga',
                'address' => 'Skanstes iela 21, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9682,
                'longitude' => 24.1227,
            ],
            'fiba' => [
                'name' => 'Arēna Rīga',
                'address' => 'Skanstes iela 21, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9682,
                'longitude' => 24.1227,
            ],
            'vef' => [
                'name' => 'VEF Kultūras pils',
                'address' => 'Ropažu iela 2, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9744,
                'longitude' => 24.1673,
            ],
            'hanzas peron' => [
                'name' => 'Hanzas Perons',
                'address' => 'Hanzas iela 16A, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9658,
                'longitude' => 24.1130,
            ],
            'dailes teātr' => [
                'name' => 'Dailes teātris',
                'address' => 'Brīvības iela 75, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9593,
                'longitude' => 24.1264,
            ],
            'jaunais rīgas teātr' => [
                'name' => 'Jaunais Rīgas teātris',
                'address' => 'Lāčplēša iela 25, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9538,
                'longitude' => 24.1232,
            ],
            'čehova' => [
                'name' => 'Mihaila Čehova Rīgas Krievu teātris',
                'address' => 'Kaļķu iela 16, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9488,
                'longitude' => 24.1093,
            ],
            'nacionālais teātr' => [
                'name' => 'Latvijas Nacionālais teātris',
                'address' => 'Kronvalda bulvāris 2, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9535,
                'longitude' => 24.1042,
            ],
            'splendid' => [
                'name' => 'Splendid Palace',
                'address' => 'Elizabetes iela 61, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9547,
                'longitude' => 24.1192,
            ],
            'k. suns' => [
                'name' => 'Kinoteātris K.Suns',
                'address' => 'Elizabetes iela 83/85, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9515,
                'longitude' => 24.1245,
            ],
            'k.suns' => [
                'name' => 'Kinoteātris K.Suns',
                'address' => 'Elizabetes iela 83/85, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9515,
                'longitude' => 24.1245,
            ],
            'kino bize' => [
                'name' => 'Kino Bize',
                'address' => 'Elizabetes iela 37, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9575,
                'longitude' => 24.1147,
            ],
            'doma baznīc' => [
                'name' => 'Rīgas Doms',
                'address' => 'Doma laukums 1, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9491,
                'longitude' => 24.1044,
            ],
            'rīgas dom' => [
                'name' => 'Rīgas Doms',
                'address' => 'Doma laukums 1, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9491,
                'longitude' => 24.1044,
            ],
            'pētera baznīc' => [
                'name' => 'Rīgas Sv. Pētera baznīca',
                'address' => 'Reformācijas laukums 1, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9477,
                'longitude' => 24.1094,
            ],
            'lielajā ģildē' => [
                'name' => 'Lielā ģilde',
                'address' => 'Amatu iela 6, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9497,
                'longitude' => 24.1077,
            ],
            'lielā ģilde' => [
                'name' => 'Lielā ģilde',
                'address' => 'Amatu iela 6, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9497,
                'longitude' => 24.1077,
            ],
            'mazajā ģildē' => [
                'name' => 'Mazā ģilde',
                'address' => 'Amatu iela 5, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9499,
                'longitude' => 24.1082,
            ],
            'lielajā aulā' => [
                'name' => 'Latvijas Universitātes Lielā aula',
                'address' => 'Raiņa bulvāris 19, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9507,
                'longitude' => 24.1162,
            ],
            'nāciju līgas spēle' => [
                'name' => 'Skonto stadions',
                'address' => 'E. Melngaiļa iela 1a, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9614,
                'longitude' => 24.1164,
            ],
            'skonto stad' => [
                'name' => 'Skonto stadions',
                'address' => 'E. Melngaiļa iela 1a, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9614,
                'longitude' => 24.1164,
            ],
            'zuzeum' => [
                'name' => 'Mākslas centrs Zuzeum',
                'address' => 'Lāčplēša iela 101, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9493,
                'longitude' => 24.1378,
            ],
            'kim?' => [
                'name' => 'Kim? Laikmetīgās mākslas centrs',
                'address' => 'Sporta iela 2, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9658,
                'longitude' => 24.1203,
            ],
            'maratons' => [
                'name' => '11. novembra krastmala',
                'address' => '11. novembra krastmala, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9472,
                'longitude' => 24.1031,
            ],
            'ķīpsal' => [
                'name' => 'Starptautiskais izstāžu centrs Ķīpsala',
                'address' => 'Ķīpsalas iela 8, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9546,
                'longitude' => 24.0805,
            ],
            'ziemassvētku tirdziņ' => [
                'name' => 'Doma laukums',
                'address' => 'Doma laukums, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9496,
                'longitude' => 24.1048,
            ],
            'doma laukum' => [
                'name' => 'Doma laukums',
                'address' => 'Doma laukums, Rīga',
                'city' => 'Rīga',
                'latitude' => 56.9496,
                'longitude' => 24.1048,
            ],
        ];

        foreach ($venues as $needle => $data) {
            if (str_contains($lowerTitle, $needle)) {
                return array_merge($data, [
                    'country' => 'Latvija',
                    'country_code' => 'LV',
                ]);
            }
        }

        // Category-based venue inference
        if (str_contains($lowerCat, 'opera') || str_contains($lowerCat, 'balets')) {
            return [
                'name' => 'Latvijas Nacionālā opera un balets',
                'address' => 'Aspazijas bulvāris 3, Rīga',
                'city' => 'Rīga',
                'country' => 'Latvija',
                'country_code' => 'LV',
                'latitude' => 56.9496,
                'longitude' => 24.1147,
            ];
        }

        if (str_contains($lowerCat, 'starptautiskie gadatirgi')) {
            return [
                'name' => 'Starptautiskais izstāžu centrs Ķīpsala',
                'address' => 'Ķīpsalas iela 8, Rīga',
                'city' => 'Rīga',
                'country' => 'Latvija',
                'country_code' => 'LV',
                'latitude' => 56.9546,
                'longitude' => 24.0805,
            ];
        }

        // Default Riga location
        return [
            'name' => 'Rīga',
            'address' => 'Rīga, Latvija',
            'city' => 'Rīga',
            'country' => 'Latvija',
            'country_code' => 'LV',
            'latitude' => 56.9496,
            'longitude' => 24.1052,
        ];
    }

    protected function fetchPageHtml(string $url): ?string
    {
        try {
            $response = $this->getClient()->get($url);
            return (string)$response->getBody();
        } catch (\Throwable $e) {
            Log::warning("LiveRiga: HTTP error fetching {$url}: " . $e->getMessage());
            return null;
        }
    }

    protected function normalizeUrl(string $url): string
    {
        if (empty($url)) {
            return 'https://www.liveriga.com/lv/apmekle/pasakumi';
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return 'https://www.liveriga.com' . (str_starts_with($url, '/') ? '' : '/') . $url;
    }

    protected function normalizeImageUrl(string $url): string
    {
        // Strip query params to obtain original full-res image
        $clean = preg_replace('/\?.*$/', '', $url);

        if (str_starts_with($clean, 'http://') || str_starts_with($clean, 'https://')) {
            return $clean;
        }

        return 'https://www.liveriga.com' . (str_starts_with($clean, '/') ? '' : '/') . $clean;
    }
}
