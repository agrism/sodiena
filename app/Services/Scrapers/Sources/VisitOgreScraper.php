<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class VisitOgreScraper extends BaseScraper
{
    public const BASE_URL = 'https://www.visitogre.lv';
    public const EVENTS_PATH = '/lv/pasakumi';
    public const DEFAULT_IMAGE = 'https://www.visitogre.lv/data/images/theme/ogre-logo.png';

    protected array $detailCache = [];

    /**
     * Known venue metadata mapping in Ogres novads
     */
    protected array $venueAddressMap = [
        'ogres novada kultūras centrs, lielā zāle' => [
            'name' => 'Ogres novada Kultūras centrs, Lielā zāle',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Brīvības iela 15, Ogre, LV-5001',
            'place_type' => 'cultural_center',
        ],
        'ogres novada kultūras centrs, radošā telpa' => [
            'name' => 'Ogres novada Kultūras centrs, Radošā telpa',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Brīvības iela 15, Ogre, LV-5001',
            'place_type' => 'cultural_center',
        ],
        'ogres novada kultūras centrs' => [
            'name' => 'Ogres novada Kultūras centrs',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Brīvības iela 15, Ogre, LV-5001',
            'place_type' => 'cultural_center',
        ],
        'ogres vēstures un mākslas muzejs' => [
            'name' => 'Ogres Vēstures un mākslas muzejs',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Brīvības iela 36, Ogre, LV-5001',
            'place_type' => 'museum',
        ],
        'ogres centrālā bibliotēka' => [
            'name' => 'Ogres Centrālā bibliotēka',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Brīvības iela 35, Ogre, LV-5001',
            'place_type' => 'library',
        ],
        'sporta arēna ogre' => [
            'name' => 'Sporta arēna Ogre',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Gunāra Astras iela 1, Ogre, LV-5001',
            'place_type' => 'stadium',
        ],
        'ogres stadions' => [
            'name' => 'Ogres stadions',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Skolas iela 12, Ogre, LV-5001',
            'place_type' => 'stadium',
        ],
        'ogres teātris' => [
            'name' => 'Ogres teātris',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Brīvības iela 15, Ogre, LV-5001',
            'place_type' => 'theatre',
        ],
        'ikšķiles bibliotēka' => [
            'name' => 'Ikšķiles bibliotēka',
            'city' => 'Ikšķile',
            'region' => 'Vidzeme',
            'address' => 'Peldu iela 22, Ikšķile, LV-5052',
            'place_type' => 'library',
        ],
        'ikšķiles tautas nams' => [
            'name' => 'Ikšķiles Tautas nams',
            'city' => 'Ikšķile',
            'region' => 'Vidzeme',
            'address' => 'Lībiešu iela 2, Ikšķile, LV-5052',
            'place_type' => 'cultural_center',
        ],
        'lielvārdes kultūras nams' => [
            'name' => 'Lielvārdes Kultūras nams',
            'city' => 'Lielvārde',
            'region' => 'Vidzeme',
            'address' => 'Parka iela 3, Lielvārde, LV-5070',
            'place_type' => 'cultural_center',
        ],
        'andreja pumpura lielvārdes muzejs' => [
            'name' => 'Andreja Pumpura Lielvārdes muzejs',
            'city' => 'Lielvārde',
            'region' => 'Vidzeme',
            'address' => 'Edgara Kauliņa aleja 20, Lielvārde, LV-5070',
            'place_type' => 'museum',
        ],
        'ķeguma tautas nams' => [
            'name' => 'Ķeguma Tautas nams',
            'city' => 'Ķegums',
            'region' => 'Vidzeme',
            'address' => 'Lāčplēša iela 1, Ķegums, LV-5025',
            'place_type' => 'cultural_center',
        ],
        'ciemupes tautas nams' => [
            'name' => 'Ciemupes Tautas nams',
            'city' => 'Ciemupe',
            'region' => 'Vidzeme',
            'address' => 'Liepu gatve 4, Ciemupe, Ogres novads, LV-5004',
            'place_type' => 'cultural_center',
        ],
        'suntažu kultūras nams' => [
            'name' => 'Suntažu Kultūras nams',
            'city' => 'Suntaži',
            'region' => 'Vidzeme',
            'address' => 'Suntaži, Ogres novads, LV-5060',
            'place_type' => 'cultural_center',
        ],
        'meņģeles tautas nams' => [
            'name' => 'Meņģeles Tautas nams',
            'city' => 'Meņģele',
            'region' => 'Vidzeme',
            'address' => 'Meņģele, Ogres novads, LV-5047',
            'place_type' => 'cultural_center',
        ],
        'jumpravas pagasta kultūras nams' => [
            'name' => 'Jumpravas pagasta Kultūras nams',
            'city' => 'Jumprava',
            'region' => 'Vidzeme',
            'address' => 'Daugavas iela 6, Jumprava, Ogres novads, LV-5022',
            'place_type' => 'cultural_center',
        ],
        'lauberes kultūras nams' => [
            'name' => 'Lauberes Kultūras nams',
            'city' => 'Laubere',
            'region' => 'Vidzeme',
            'address' => 'Ozolu gatve 2, Laubere, Ogres novads, LV-5044',
            'place_type' => 'cultural_center',
        ],
        'tīnūžu tautas nams' => [
            'name' => 'Tīnūžu Tautas nams',
            'city' => 'Tīnūži',
            'region' => 'Vidzeme',
            'address' => '"Kraujas", Tīnūži, Ogres novads, LV-5015',
            'place_type' => 'cultural_center',
        ],
        'tomes tautas nams' => [
            'name' => 'Tomes Tautas nams',
            'city' => 'Tome',
            'region' => 'Vidzeme',
            'address' => 'Tome, Ogres novads, LV-5020',
            'place_type' => 'cultural_center',
        ],
        'madlienas kultūras nams' => [
            'name' => 'Madlienas Kultūras nams',
            'city' => 'Madliena',
            'region' => 'Vidzeme',
            'address' => 'Madliena, Ogres novads, LV-5045',
            'place_type' => 'cultural_center',
        ],
        'rembates tautas nams' => [
            'name' => 'Rembates Tautas nams',
            'city' => 'Rembate',
            'region' => 'Vidzeme',
            'address' => 'Rembate, Ogres novads, LV-5016',
            'place_type' => 'cultural_center',
        ],
        'birzgales tautas nams' => [
            'name' => 'Birzgales Tautas nams',
            'city' => 'Birzgale',
            'region' => 'Vidzeme',
            'address' => 'Birzgale, Ogres novads, LV-5033',
            'place_type' => 'cultural_center',
        ],
        'taurupes tautas nams' => [
            'name' => 'Taurupes Tautas nams',
            'city' => 'Taurupe',
            'region' => 'Vidzeme',
            'address' => 'Taurupe, Ogres novads, LV-5064',
            'place_type' => 'cultural_center',
        ],
        'krapes tautas nams' => [
            'name' => 'Krapes Tautas nams',
            'city' => 'Krape',
            'region' => 'Vidzeme',
            'address' => 'Krape, Ogres novads, LV-5012',
            'place_type' => 'cultural_center',
        ],
        'mazozolu tautas nams' => [
            'name' => 'Mazozolu Tautas nams',
            'city' => 'Mazozoli',
            'region' => 'Vidzeme',
            'address' => 'Mazozoli, Ogres novads, LV-5062',
            'place_type' => 'cultural_center',
        ],
        'lēdmanes tautas nams' => [
            'name' => 'Lēdmanes Tautas nams',
            'city' => 'Lēdmane',
            'region' => 'Vidzeme',
            'address' => 'Lēdmane, Ogres novads, LV-5011',
            'place_type' => 'cultural_center',
        ],
    ];

    public function getSlug(): string
    {
        return 'visitogre';
    }

    public function getName(): string
    {
        return 'Visit Ogre';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $sourceBase = $source->url ?: (self::BASE_URL . self::EVENTS_PATH);
        $baseUrl = rtrim($sourceBase, '/');
        $today = Carbon::today('Europe/Riga');

        $currentMonthUrl = $baseUrl . '/';
        $visitedMonthUrls = [];
        $discoveredEvents = []; // [eventId => ['url' => ..., 'dateStr' => ..., 'thumbImg' => ..., 'listTitle' => ...]]
        $maxMonths = 6;
        $consecutiveEmptyMonths = 0;

        // 1. Month-by-month traversal via ?calendar=YYYY-M
        for ($m = 0; $m < $maxMonths; $m++) {
            if (isset($visitedMonthUrls[$currentMonthUrl])) {
                break;
            }
            $visitedMonthUrls[$currentMonthUrl] = true;

            $crawler = $this->fetchCrawler($currentMonthUrl);
            if (!$crawler) {
                break;
            }

            // Extract event dates from #calendar a[data-events]
            $datesInMonth = [];
            $crawler->filter('#calendar a[data-events]')->each(function (Crawler $node) use (&$datesInMonth) {
                $d = trim($node->attr('data-events') ?? '');
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                    $datesInMonth[] = $d;
                }
            });

            // Extract any event cards already rendered in #events_list on this page
            $this->extractEventsFromHtml($crawler->html(), $baseUrl, $discoveredEvents);

            $monthEventsCount = 0;

            // Query dynamic events for each date via POST
            foreach ($datesInMonth as $dateStr) {
                try {
                    $dateObj = Carbon::createFromFormat('Y-m-d', $dateStr, 'Europe/Riga')->startOfDay();
                    if ($dateObj->lt($today)) {
                        continue;
                    }

                    $response = $this->httpClient->post($baseUrl . '/', [
                        'form_params' => ['date' => $dateStr],
                        'timeout' => 10,
                    ]);
                    $fragHtml = (string) $response->getBody();
                    $foundCount = $this->extractEventsFromHtml($fragHtml, $baseUrl, $discoveredEvents, $dateStr);
                    $monthEventsCount += $foundCount;
                } catch (\Throwable $e) {
                    Log::warning("VisitOgreScraper: Failed to fetch events for date {$dateStr}: " . $e->getMessage());
                }
            }

            if ($monthEventsCount === 0 && empty($datesInMonth)) {
                $consecutiveEmptyMonths++;
                if ($consecutiveEmptyMonths >= 2) {
                    break;
                }
            } else {
                $consecutiveEmptyMonths = 0;
            }

            // Find next month switcher link: a.next with [?&]calendar=
            $nextLink = null;
            $nextNodes = $crawler->filter('#calendar .header a.next');
            if ($nextNodes->count() > 0) {
                $rawNext = trim($nextNodes->attr('href') ?? '');
                if (!empty($rawNext) && preg_match('#[?&]calendar=\d{4}-\d{1,2}#', $rawNext)) {
                    $nextLink = $this->normalizeUrl($rawNext, $baseUrl);
                }
            }

            if (!$nextLink || isset($visitedMonthUrls[$nextLink])) {
                break;
            }

            $currentMonthUrl = $nextLink;
        }

        // 2. Fetch details for each discovered event URL strictly under visitogre.lv/lv/pasakumi
        foreach ($discoveredEvents as $eventId => $meta) {
            try {
                $eventUrl = $meta['url'];
                if (!$this->isVisitOgreEventUrl($eventUrl)) {
                    continue;
                }

                $detailHtml = $this->fetchDetailHtml($eventUrl);
                if (!$detailHtml) {
                    continue;
                }

                $dto = $this->parseEventDetail($detailHtml, $eventUrl, $meta, $today);
                if (!$dto) {
                    continue;
                }

                // Strict Scope Rule: Current and upcoming events only
                if ($dto->endAt) {
                    if ($dto->endAt->lt($today)) {
                        continue;
                    }
                } elseif ($dto->startAt->lt($today)) {
                    continue;
                }

                $events->push($dto);
            } catch (\Throwable $e) {
                Log::warning("VisitOgreScraper: Failed to parse event ID {$eventId}", [
                    'url' => $meta['url'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $events;
    }

    /**
     * Extract event cards from HTML snippet or page
     */
    public function extractEventsFromHtml(string $html, string $baseUrl, array &$discoveredEvents, ?string $fallbackDate = null): int
    {
        $crawler = new Crawler($html, $baseUrl);
        $count = 0;

        // Check for preceding date headers if available: <div class="event_date">DD.MM.YYYY</div>
        $currentDate = $fallbackDate;

        $crawler->filter('.event_date, table.event')->each(function (Crawler $node) use (&$currentDate, &$discoveredEvents, &$count, $baseUrl) {
            if ($node->matches('.event_date')) {
                $dateText = trim($node->text(''));
                if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $dateText, $dm)) {
                    $currentDate = sprintf('%04d-%02d-%02d', $dm[3], $dm[2], $dm[1]);
                }
                return;
            }

            if ($node->matches('table.event')) {
                $idAttr = $node->attr('id') ?? '';
                $eventId = preg_replace('/\D/', '', $idAttr);

                $linkNode = $node->filter('a');
                $rawLink = $linkNode->count() ? trim($linkNode->attr('href') ?? '') : '';
                if (empty($rawLink) && empty($eventId)) {
                    return;
                }

                $eventUrl = $this->normalizeUrl($rawLink, $baseUrl);
                if (empty($eventId) && preg_match('#/pasakumi/(\d+)#', $eventUrl, $im)) {
                    $eventId = $im[1];
                }

                if (empty($eventId)) {
                    return;
                }

                $title = $node->filter('h3')->count() ? trim($node->filter('h3')->text()) : '';
                $thumbImg = $node->filter('td.thumb img')->count() ? $node->filter('td.thumb img')->attr('src') : null;
                if ($thumbImg) {
                    $thumbImg = $this->normalizeUrl($thumbImg, $baseUrl);
                }

                if (!isset($discoveredEvents[$eventId])) {
                    $discoveredEvents[$eventId] = [
                        'id' => $eventId,
                        'url' => $eventUrl,
                        'date' => $currentDate,
                        'listTitle' => $title,
                        'thumbImg' => $thumbImg,
                    ];
                    $count++;
                }
            }
        });

        return $count;
    }

    /**
     * Parse detail HTML into ScrapedEventDTO
     */
    public function parseEventDetail(string $html, string $eventUrl, array $meta = [], ?Carbon $today = null): ?ScrapedEventDTO
    {
        $today = $today ?: Carbon::today('Europe/Riga');
        $crawler = new Crawler($html, $eventUrl);

        $h1Node = $crawler->filter('h1');
        $title = $h1Node->count() ? $this->cleanText($h1Node->text()) : ($meta['listTitle'] ?? '');
        if (empty($title) || mb_strlen($title, 'UTF-8') < 3) {
            return null;
        }

        // Full content description
        $contentNode = $crawler->filter('.event_full');
        $description = null;
        if ($contentNode->count()) {
            $contentHtml = $contentNode->html();
            $description = $this->cleanText($contentHtml);
        }

        if (empty($description)) {
            $description = $title;
        }

        $shortDescription = Str::limit(strip_tags($description), 160);

        // Date and Time parsing
        $dateStr = $meta['date'] ?? null;
        if (empty($dateStr)) {
            // Attempt extraction from content: e.g. "13. novembrī"
            $dateStr = $this->extractDateFromContent($description);
        }

        if (empty($dateStr)) {
            return null;
        }

        $timeRange = $this->parseTime($description, $title);
        $startTime = $timeRange['start'] ?? null;
        $endTime = $timeRange['end'] ?? null;

        if ($startTime) {
            try {
                $startAt = Carbon::createFromFormat('Y-m-d H:i', "{$dateStr} {$startTime}", 'Europe/Riga');
            } catch (\Throwable $e) {
                $startAt = Carbon::parse($dateStr, 'Europe/Riga')->setTime(0, 0);
            }
        } else {
            $startAt = Carbon::parse($dateStr, 'Europe/Riga')->setTime(0, 0);
        }

        $endAt = null;
        if ($endTime) {
            try {
                $endAt = Carbon::createFromFormat('Y-m-d H:i', "{$dateStr} {$endTime}", 'Europe/Riga');
                if ($endAt->lt($startAt)) {
                    $endAt->addDay();
                }
            } catch (\Throwable $e) {
                $endAt = null;
            }
        }

        // Image resolution
        $imageUrl = null;
        $imgNode = $crawler->filter('.event_full img');
        if ($imgNode->count()) {
            $imageUrl = $this->normalizeUrl($imgNode->attr('src') ?? '', self::BASE_URL);
        }

        if (empty($imageUrl) && !empty($meta['thumbImg'])) {
            $imageUrl = $this->normalizeUrl($meta['thumbImg'], self::BASE_URL);
        }

        if (empty($imageUrl)) {
            $imageUrl = self::DEFAULT_IMAGE;
        }

        // Venue, City, Address, Region resolution
        $venueData = $this->resolveVenueAndCity($title, $description);
        $venueName = $venueData['name'];
        $city = $venueData['city'];
        $region = $venueData['region'];
        $address = $venueData['address'];
        $placeType = $venueData['place_type'];

        // Categories & Entertainment Type resolution per AGENTS.md rules
        $categoryNames = $this->resolveCategories($title, $description, $venueName);
        $entertainmentType = $this->resolveEntertainmentType($title, $categoryNames);

        // Price & Free Status
        $priceData = $this->parsePrice($description);
        $isFree = $priceData['is_free'];
        $priceMin = $priceData['price_min'];
        $priceMax = $priceData['price_max'];

        // Ticket URL resolution
        $ticketUrl = $this->extractTicketUrl($html, $eventUrl);

        $eventId = $meta['id'] ?? (preg_match('#/pasakumi/(\d+)#', $eventUrl, $im) ? $im[1] : md5($eventUrl));
        $sourceExternalId = "visitogre-{$eventId}";

        return new ScrapedEventDTO(
            title: $title,
            startAt: $startAt,
            endAt: $endAt,
            description: $description,
            shortDescription: $shortDescription,
            venueName: $venueName,
            city: $city,
            region: $region,
            address: $address,
            placeType: $placeType,
            categoryNames: $categoryNames,
            entertainmentType: $entertainmentType,
            isFree: $isFree,
            priceMin: $priceMin,
            priceMax: $priceMax,
            currency: 'EUR',
            ticketUrl: $ticketUrl,
            imageUrl: $imageUrl,
            sourceUrl: $eventUrl,
            sourceExternalId: $sourceExternalId,
            locale: 'lv',
            rawData: [
                'event_id' => $eventId,
                'source' => 'visitogre.lv',
            ]
        );
    }

    /**
     * Resolve Venue, City, and Address from text
     */
    public function resolveVenueAndCity(string $title, string $description): array
    {
        $text = mb_strtolower($title . ' ' . $description, 'UTF-8');

        // Specific venue stem matching
        $stemVenueMap = [
            'lielajā zāl' => 'ogres novada kultūras centrs, lielā zāle',
            'lielā zāl' => 'ogres novada kultūras centrs, lielā zāle',
            'radošajā telp' => 'ogres novada kultūras centrs, radošā telpa',
            'radošā telp' => 'ogres novada kultūras centrs, radošā telpa',
            'ikšķiles bibliotēk' => 'ikšķiles bibliotēka',
            'ikšķiles tautas nam' => 'ikšķiles tautas nams',
            'ogres vēstures un mākslas muzej' => 'ogres vēstures un mākslas muzejs',
            'ogres muzej' => 'ogres vēstures un mākslas muzejs',
            'ogres centrālajā bibliotēk' => 'ogres centrālā bibliotēka',
            'ogres bibliotēk' => 'ogres centrālā bibliotēka',
            'sporta arēnā ogre' => 'sporta arēna ogre',
            'sporta arēna ogre' => 'sporta arēna ogre',
            'arēnā ogre' => 'sporta arēna ogre',
            'ogres stadion' => 'ogres stadions',
            'ogres teātr' => 'ogres teātris',
            'andreja pumpura' => 'andreja pumpura lielvārdes muzejs',
            'lielvārdes kultūras nam' => 'lielvārdes kultūras nams',
            'lielvārdes tautas nam' => 'lielvārdes kultūras nams',
            'ķeguma tautas nam' => 'ķeguma tautas nams',
            'ciemupes tautas nam' => 'ciemupes tautas nams',
            'suntažu kultūras nam' => 'suntažu kultūras nams',
            'suntažu tautas nam' => 'suntažu kultūras nams',
            'meņģeles tautas nam' => 'meņģeles tautas nams',
            'jumpravas pagasta kultūras nam' => 'jumpravas pagasta kultūras nams',
            'jumpravas kultūras nam' => 'jumpravas pagasta kultūras nams',
            'jumpravas tautas nam' => 'jumpravas pagasta kultūras nams',
            'lauberes kultūras nam' => 'lauberes kultūras nams',
            'tīnūžu tautas nam' => 'tīnūžu tautas nams',
            'tomes tautas nam' => 'tomes tautas nams',
            'madlienas kultūras nam' => 'madlienas kultūras nams',
            'rembates tautas nam' => 'rembates tautas nams',
            'birzgales tautas nam' => 'birzgales tautas nams',
            'taurupes tautas nam' => 'taurupes tautas nams',
            'krapes tautas nam' => 'krapes tautas nams',
            'mazozolu tautas nam' => 'mazozolu tautas nams',
            'lēdmanes tautas nam' => 'lēdmanes tautas nams',
            'ledmanes tautas nam' => 'lēdmanes tautas nams',
            'ogres novada kultūras centr' => 'ogres novada kultūras centrs',
            'ogres kultūras centr' => 'ogres novada kultūras centrs',
        ];

        foreach ($stemVenueMap as $stem => $mapKey) {
            if (str_contains($text, $stem) && isset($this->venueAddressMap[$mapKey])) {
                return $this->venueAddressMap[$mapKey];
            }
        }

        // 1. Check known venue address map directly
        foreach ($this->venueAddressMap as $key => $data) {
            if (str_contains($text, $key)) {
                return $data;
            }
        }

        // 2. Detect Ogres novads city/parish
        $city = 'Ogre';
        $parishMap = [
            'ikšķil' => 'Ikšķile',
            'lielvārd' => 'Lielvārde',
            'ķegum' => 'Ķegums',
            'suntaž' => 'Suntaži',
            'meņģel' => 'Meņģele',
            'ciemup' => 'Ciemupe',
            'jumprav' => 'Jumprava',
            'lauber' => 'Laubere',
            'tīnūž' => 'Tīnūži',
            'tomē' => 'Tome',
            'tome' => 'Tome',
            'madlien' => 'Madliena',
            'rembat' => 'Rembate',
            'birzgal' => 'Birzgale',
            'taurup' => 'Taurupe',
            'krap' => 'Krape',
            'mazozol' => 'Mazozoli',
            'lēdman' => 'Lēdmane',
            'ledman' => 'Lēdmane',
            'ogrē' => 'Ogre',
            'ogre' => 'Ogre',
        ];

        // Prioritize title to avoid street names or mentions in description hijacking the city
        $titleLower = mb_strtolower($title, 'UTF-8');
        foreach ($parishMap as $needle => $cName) {
            if (str_contains($titleLower, $needle)) {
                $city = $cName;
                break;
            }
        }

        if ($city === 'Ogre' && !str_contains($titleLower, 'ogr')) {
            foreach ($parishMap as $needle => $cName) {
                if (str_contains($text, $needle)) {
                    $city = $cName;
                    break;
                }
            }
        }

        // 3. Fallback venue name based on city
        $venue = match ($city) {
            'Ikšķile' => 'Ikšķiles Tautas nams',
            'Lielvārde' => 'Lielvārdes Kultūras nams',
            'Ķegums' => 'Ķeguma Tautas nams',
            'Suntaži' => 'Suntažu Kultūras nams',
            'Meņģele' => 'Meņģeles Tautas nams',
            'Ciemupe' => 'Ciemupes Tautas nams',
            'Jumprava' => 'Jumpravas pagasta Kultūras nams',
            'Laubere' => 'Lauberes Kultūras nams',
            'Tīnūži' => 'Tīnūžu Tautas nams',
            'Tome' => 'Tomes Tautas nams',
            'Madliena' => 'Madlienas Kultūras nams',
            'Rembate' => 'Rembates Tautas nams',
            'Birzgale' => 'Birzgales Tautas nams',
            'Taurupe' => 'Taurupes Tautas nams',
            'Krape' => 'Krapes Tautas nams',
            'Mazozoli' => 'Mazozolu Tautas nams',
            'Lēdmane' => 'Lēdmanes Tautas nams',
            default => 'Ogres novada Kultūras centrs',
        };

        $address = "{$city}, Ogres novads";

        return [
            'name' => $venue,
            'city' => $city,
            'region' => 'Vidzeme',
            'address' => $address,
            'place_type' => 'cultural_center',
        ];
    }

    /**
     * Resolve categories per strict AGENTS.md rules
     */
    public function resolveCategories(string $title, string $description, string $venue): array
    {
        $text = mb_strtolower($title . ' ' . $description . ' ' . $venue, 'UTF-8');

        // Strict Rule 1: Cinema / Kino (Strict & Priority)
        // Venues or content with filma, kinoseanss, spēlfilma, dokumentālā filma, etc.
        // MUST NEVER be classified as Teātris even if kinoteātris appears
        $isCinema = (
            str_contains($text, 'filma') ||
            str_contains($text, 'filmas') ||
            str_contains($text, 'filmu') ||
            str_contains($text, 'filmā') ||
            str_contains($text, 'kinoseanss') ||
            str_contains($text, 'kinoseans') ||
            str_contains($text, 'filmas seanss') ||
            str_contains($text, 'spēlfilma') ||
            str_contains($text, 'dokumentālā filma') ||
            str_contains($text, 'dokumentala filma') ||
            str_contains($text, 'animācijas filma') ||
            str_contains($text, 'animacijas filma') ||
            str_contains($text, 'īsfilma') ||
            str_contains($text, 'isfilma') ||
            str_contains($text, 'kinofestivāls') ||
            str_contains($text, 'kino bize') ||
            str_contains($text, 'kinoteātr')
        );

        if ($isCinema) {
            return ['Kino'];
        }

        // Strict Rule 2: Theaters
        // Only actual theater productions (izrāde, luga, iestudējums, opera, balets)
        $isTheatre = (
            str_contains($text, 'ogres teātr') ||
            str_contains($text, 'teātra izrāde') ||
            str_contains($text, 'teatra izrade') ||
            str_contains($text, 'teātra iestudējum') ||
            str_contains($text, 'teātris') ||
            str_contains($text, 'teatris') ||
            str_contains($text, 'izrāde') ||
            str_contains($text, 'izrade') ||
            str_contains($text, 'luga') ||
            str_contains($text, 'iestudējums') ||
            str_contains($text, 'opera') ||
            str_contains($text, 'balets')
        ) && !str_contains($text, 'filma');

        if ($isTheatre) {
            return ['Teātris'];
        }

        // Music / Concerts
        if (
            str_contains($text, 'koncerts') ||
            str_contains($text, 'dziesma') ||
            str_contains($text, 'dziesmas') ||
            str_contains($text, 'dziesmu') ||
            str_contains($text, 'koris') ||
            str_contains($text, 'orķestr') ||
            str_contains($text, 'orkestr') ||
            str_contains($text, 'mūzika') ||
            str_contains($text, 'muzika') ||
            str_contains($text, 'balle') ||
            str_contains($text, 'ansambl') ||
            str_contains($text, 'kapela') ||
            str_contains($text, 'akordeon') ||
            str_contains($text, 'dziedātāj')
        ) {
            return ['Mūzika'];
        }

        // Festivals / Holidays
        if (
            str_contains($text, 'svētki') ||
            str_contains($text, 'svetki') ||
            str_contains($text, 'valsts svētk') ||
            str_contains($text, 'lāčplēš') ||
            str_contains($text, 'lacples') ||
            str_contains($text, '18. novembr') ||
            str_contains($text, 'svinīgais pasākums') ||
            str_contains($text, 'ziemassvētku') ||
            str_contains($text, 'jaunais gads')
        ) {
            return ['Festivāli & Svētki'];
        }

        // Exhibitions / Art
        if (
            str_contains($text, 'izstāde') ||
            str_contains($text, 'izstade') ||
            str_contains($text, 'ekspozīcij') ||
            str_contains($text, 'māksla') ||
            str_contains($text, 'glezn') ||
            str_contains($text, 'muzej') ||
            str_contains($text, 'meistarklase')
        ) {
            return ['Māksla un izstādes'];
        }

        // Children / Family
        if (
            str_contains($text, 'bērniem') ||
            str_contains($text, 'berniem') ||
            str_contains($text, 'ģimenei') ||
            str_contains($text, 'gimenei') ||
            str_contains($text, 'leļļu') ||
            str_contains($text, 'pasaka')
        ) {
            return ['Bērniem'];
        }

        // Sports
        if (
            str_contains($text, 'sports') ||
            str_contains($text, 'čempionāt') ||
            str_contains($text, 'turnīrs') ||
            str_contains($text, 'skrējiens') ||
            str_contains($text, 'futbol') ||
            str_contains($text, 'basketbol') ||
            str_contains($text, 'kamaniņ')
        ) {
            return ['Sports'];
        }

        // Strict Rule 3: Fallback to Cits
        return ['Cits'];
    }

    /**
     * Resolve entertainment type
     */
    public function resolveEntertainmentType(string $title, array $categories): string
    {
        $primary = $categories[0] ?? 'Cits';

        return match ($primary) {
            'Kino' => 'movie',
            'Teātris' => 'performance',
            'Mūzika' => 'concert',
            'Festivāli & Svētki' => 'festival',
            'Māksla un izstādes' => 'exhibition',
            'Sports' => 'sports',
            'Bērniem' => 'family',
            default => 'chill',
        };
    }

    /**
     * Extract start and end time from text
     */
    public function parseTime(string $content, string $title): array
    {
        $search = $content . ' ' . $title;

        // Match "plkst. 11.00-14.00" or "plkst. 11:00 - 14:00"
        if (preg_match('/plkst\.?\s*(\d{1,2})[\.:](\d{2})\s*[-–]\s*(\d{1,2})[\.:](\d{2})/iu', $search, $tm)) {
            return [
                'start' => sprintf('%02d:%02d', (int) $tm[1], (int) $tm[2]),
                'end' => sprintf('%02d:%02d', (int) $tm[3], (int) $tm[4]),
            ];
        }

        // Match "plkst. 18.00" or "plkst. 18:00"
        if (preg_match('/plkst\.?\s*(\d{1,2})[\.:](\d{2})/iu', $search, $tm)) {
            return [
                'start' => sprintf('%02d:%02d', (int) $tm[1], (int) $tm[2]),
                'end' => null,
            ];
        }

        // Match standalone time range e.g. "11.00 - 14.00" or "11:00-14:00"
        if (preg_match('/(?:^|[^\d])([012]?\d)[\.:]([0-5]\d)\s*[-–]\s*([012]?\d)[\.:]([0-5]\d)/u', $search, $tm)) {
            $h1 = (int) $tm[1];
            $h2 = (int) $tm[3];
            if ($h1 >= 7 && $h1 <= 23 && $h2 >= 7 && $h2 <= 23) {
                return [
                    'start' => sprintf('%02d:%02d', $h1, (int) $tm[2]),
                    'end' => sprintf('%02d:%02d', $h2, (int) $tm[4]),
                ];
            }
        }

        // Match standalone time e.g. "18.00 lāpu gājiens" or "18.00"
        if (preg_match('/(?:^|[^\d])([012]?\d)[\.:]([0-5]\d)(?:\s|$|[^\d\.:])/u', $search, $tm)) {
            $h = (int) $tm[1];
            if ($h >= 7 && $h <= 23) {
                return [
                    'start' => sprintf('%02d:%02d', $h, (int) $tm[2]),
                    'end' => null,
                ];
            }
        }

        return [
            'start' => null,
            'end' => null,
        ];
    }

    /**
     * Extract date from content if not present in list
     */
    protected function extractDateFromContent(string $content): ?string
    {
        $months = [
            'janvār' => 1, 'februār' => 2, 'mart' => 3, 'aprīl' => 4,
            'maij' => 5, 'jūnij' => 6, 'jūlij' => 7, 'august' => 8,
            'septembr' => 9, 'oktobr' => 10, 'novembr' => 11, 'decembr' => 12,
        ];

        if (preg_match('/(?:^|\s)(\d{1,2})\.\s*(janvār|februār|mart|aprīl|maij|jūnij|jūlij|august|septembr|oktobr|novembr|decembr)[a-zāčēģīķļņšūž]*/iu', $content, $m)) {
            $day = (int) $m[1];
            $monthKey = mb_strtolower($m[2], 'UTF-8');
            $month = $months[$monthKey] ?? null;

            if ($month) {
                $year = (int) date('Y');
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        return null;
    }

    /**
     * Extract price and free status
     */
    public function parsePrice(string $content): array
    {
        $isFree = false;
        $priceMin = null;
        $priceMax = null;

        if (
            preg_match('/(?:ieeja|biļet[a-z]*|maksa)\s*[-–—:]?\s*(?:bez\s+maksas|brīva)/iu', $content) ||
            preg_match('/\b(?:bezmaksas|brīva\s+ieeja|ieeja\s+brīva)\b/iu', $content)
        ) {
            $isFree = true;
            $priceMin = 0.0;
            $priceMax = 0.0;
        } elseif (preg_match('/(?:ieeja|biļet[a-z]*|cena|maksa)\s*[-–—:]?\s*(\d+(?:[.,]\d{1,2})?)\s*(?:€|eur)/iu', $content, $pm)) {
            $val = (float) str_replace(',', '.', $pm[1]);
            $priceMin = $val;
            $priceMax = $val;
        }

        return [
            'is_free' => $isFree,
            'price_min' => $priceMin,
            'price_max' => $priceMax,
        ];
    }

    /**
     * Extract ticketing link if present in description
     */
    protected function extractTicketUrl(string $html, string $defaultUrl): string
    {
        if (preg_match('/href=["\'](https?:\/\/(?:www\.)?(?:bilesuparadize\.lv|bezrindas\.lv|aula\.lv|ekase\.lv|ticketbest\.lv)[^"\']*)["\']/iu', $html, $tm)) {
            $clean = urldecode($tm[1]);
            // Strip trailing " vai", " vai/", punctuation
            $clean = preg_replace('/\s+vai.*$/i', '', $clean);
            $clean = trim($clean, " /");
            if (filter_var($clean, FILTER_VALIDATE_URL)) {
                return $clean;
            }
        }

        return $defaultUrl;
    }

    /**
     * Guard to verify the link stays strictly under visitogre.lv/lv/pasakumi
     */
    public function isVisitOgreEventUrl(string $url): bool
    {
        $parsed = parse_url($url);
        $host = strtolower($parsed['host'] ?? '');
        $path = $parsed['path'] ?? '';

        if (!in_array($host, ['www.visitogre.lv', 'visitogre.lv'], true)) {
            return false;
        }

        return str_starts_with($path, '/lv/pasakumi');
    }

    /**
     * Helper to normalize URLs
     */
    protected function normalizeUrl(string $url, string $baseUrl = self::BASE_URL): string
    {
        $url = trim($url);
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        if (str_starts_with($url, '/')) {
            return self::BASE_URL . $url;
        }
        return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
    }

    /**
     * Fetch detail page HTML with local memory cache
     */
    public function fetchDetailHtml(string $url): ?string
    {
        if (isset($this->detailCache[$url])) {
            return $this->detailCache[$url];
        }

        try {
            $response = $this->httpClient->get($url, ['timeout' => 10]);
            $html = (string) $response->getBody();
            $this->detailCache[$url] = $html;
            return $html;
        } catch (\Throwable $e) {
            Log::warning("VisitOgreScraper: Failed to fetch detail HTML for {$url}: " . $e->getMessage());
            return null;
        }
    }
}
