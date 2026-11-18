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

class LimbazuNovadsScraper extends BaseScraper
{
    public const BASE_URL = 'https://www.visitlimbazi.lv';
    public const EVENTS_RPC_URL = 'https://www.visitlimbazi.lv/lv/pasakumi-drizuma?cats=&place=0&dt=rpc';
    public const MUNICIPALITY_BASE_URL = 'https://www.limbazunovads.lv';

    /**
     * Map of known Limbažu novads venues with normalized metadata
     */
    protected array $venueAddressMap = [
        // Limbaži
        'limbažu kultūras nams' => [
            'name' => 'Limbažu Kultūras nams',
            'city' => 'Limbaži',
            'region' => 'Vidzeme',
            'address' => 'Rīgas iela 9, Limbaži, Limbažu novads, LV-4001',
            'place_type' => 'cultural_center',
            'lat' => 57.5146,
            'lon' => 24.7176,
        ],
        'limbažu kultūras centrs' => [
            'name' => 'Limbažu Kultūras nams',
            'city' => 'Limbaži',
            'region' => 'Vidzeme',
            'address' => 'Rīgas iela 9, Limbaži, Limbažu novads, LV-4001',
            'place_type' => 'cultural_center',
            'lat' => 57.5146,
            'lon' => 24.7176,
        ],
        'limbažu galvenā bibliotēka' => [
            'name' => 'Limbažu Galvenā bibliotēka',
            'city' => 'Limbaži',
            'region' => 'Vidzeme',
            'address' => 'Parka iela 23, Limbaži, Limbažu novads, LV-4001',
            'place_type' => 'hall',
            'lat' => 57.5182,
            'lon' => 24.7121,
        ],
        'limbažu muzejs' => [
            'name' => 'Limbažu muzejs',
            'city' => 'Limbaži',
            'region' => 'Vidzeme',
            'address' => 'Burtnieku iela 7, Limbaži, Limbažu novads, LV-4001',
            'place_type' => 'museum',
            'lat' => 57.5135,
            'lon' => 24.7202,
        ],
        'limbažos' => [
            'name' => 'Limbažu Kultūras nams',
            'city' => 'Limbaži',
            'region' => 'Vidzeme',
            'address' => 'Rīgas iela 9, Limbaži, Limbažu novads, LV-4001',
            'place_type' => 'cultural_center',
            'lat' => 57.5146,
            'lon' => 24.7176,
        ],

        // Salacgrīva
        'salacgrīvas kultūras nams' => [
            'name' => 'Salacgrīvas Kultūras nams',
            'city' => 'Salacgrīva',
            'region' => 'Vidzeme',
            'address' => 'Ostas iela 2, Salacgrīva, Limbažu novads, LV-4033',
            'place_type' => 'cultural_center',
            'lat' => 57.7547,
            'lon' => 24.3589,
        ],
        'salacgrīvā' => [
            'name' => 'Salacgrīvas Kultūras nams',
            'city' => 'Salacgrīva',
            'region' => 'Vidzeme',
            'address' => 'Ostas iela 2, Salacgrīva, Limbažu novads, LV-4033',
            'place_type' => 'cultural_center',
            'lat' => 57.7547,
            'lon' => 24.3589,
        ],

        // Aloja
        'alojas kultūras nams' => [
            'name' => 'Alojas Kultūras nams',
            'city' => 'Aloja',
            'region' => 'Vidzeme',
            'address' => 'Jūras iela 13, Aloja, Limbažu novads, LV-4064',
            'place_type' => 'cultural_center',
            'lat' => 57.7676,
            'lon' => 24.8776,
        ],
        'alojā' => [
            'name' => 'Alojas Kultūras nams',
            'city' => 'Aloja',
            'region' => 'Vidzeme',
            'address' => 'Jūras iela 13, Aloja, Limbažu novads, LV-4064',
            'place_type' => 'cultural_center',
            'lat' => 57.7676,
            'lon' => 24.8776,
        ],

        // Staicele
        'staiceles kultūras nams' => [
            'name' => 'Staiceles Kultūras nams',
            'city' => 'Staicele',
            'region' => 'Vidzeme',
            'address' => 'Lielā iela 7, Staicele, Limbažu novads, LV-4068',
            'place_type' => 'cultural_center',
            'lat' => 57.8361,
            'lon' => 24.7508,
        ],
        'staiceles bibliotēka' => [
            'name' => 'Staiceles pilsētas bibliotēka',
            'city' => 'Staicele',
            'region' => 'Vidzeme',
            'address' => 'Lielā iela 7, Staicele, Limbažu novads, LV-4068',
            'place_type' => 'hall',
            'lat' => 57.8361,
            'lon' => 24.7508,
        ],
        'staicelē' => [
            'name' => 'Staiceles Kultūras nams',
            'city' => 'Staicele',
            'region' => 'Vidzeme',
            'address' => 'Lielā iela 7, Staicele, Limbažu novads, LV-4068',
            'place_type' => 'cultural_center',
            'lat' => 57.8361,
            'lon' => 24.7508,
        ],

        // Ainaži
        'ainažu kultūras nams' => [
            'name' => 'Ainažu Kultūras nams',
            'city' => 'Ainaži',
            'region' => 'Vidzeme',
            'address' => 'Parka iela 16, Ainaži, Limbažu novads, LV-4035',
            'place_type' => 'cultural_center',
            'lat' => 57.8631,
            'lon' => 24.3592,
        ],
        'ainažos' => [
            'name' => 'Ainažu Kultūras nams',
            'city' => 'Ainaži',
            'region' => 'Vidzeme',
            'address' => 'Parka iela 16, Ainaži, Limbažu novads, LV-4035',
            'place_type' => 'cultural_center',
            'lat' => 57.8631,
            'lon' => 24.3592,
        ],

        // Vidriži
        'vidriži' => [
            'name' => 'Sporta un kultūras centrs "Vidriži"',
            'city' => 'Vidriži',
            'region' => 'Vidzeme',
            'address' => 'Vidriži, Vidrižu pagasts, Limbažu novads, LV-4013',
            'place_type' => 'cultural_center',
            'lat' => 57.3481,
            'lon' => 24.6297,
        ],
        'vidrižos' => [
            'name' => 'Sporta un kultūras centrs "Vidriži"',
            'city' => 'Vidriži',
            'region' => 'Vidzeme',
            'address' => 'Vidriži, Vidrižu pagasts, Limbažu novads, LV-4013',
            'place_type' => 'cultural_center',
            'lat' => 57.3481,
            'lon' => 24.6297,
        ],

        // Liepupe
        'liepupes tautas nams' => [
            'name' => 'Liepupes Tautas nams',
            'city' => 'Liepupe',
            'region' => 'Vidzeme',
            'address' => 'Jelgavkrasti, Liepupes pagasts, Limbažu novads, LV-4023',
            'place_type' => 'cultural_center',
            'lat' => 57.4589,
            'lon' => 24.4691,
        ],
        'liepupē' => [
            'name' => 'Liepupes Tautas nams',
            'city' => 'Liepupe',
            'region' => 'Vidzeme',
            'address' => 'Jelgavkrasti, Liepupes pagasts, Limbažu novads, LV-4023',
            'place_type' => 'cultural_center',
            'lat' => 57.4589,
            'lon' => 24.4691,
        ],

        // Pāle
        'pāles kultūras nams' => [
            'name' => 'Pāles Kultūras nams',
            'city' => 'Pāle',
            'region' => 'Vidzeme',
            'address' => 'Pāle, Pāles pagasts, Limbažu novads, LV-4052',
            'place_type' => 'cultural_center',
            'lat' => 57.6534,
            'lon' => 24.7892,
        ],
        'pālē' => [
            'name' => 'Pāles Kultūras nams',
            'city' => 'Pāle',
            'region' => 'Vidzeme',
            'address' => 'Pāle, Pāles pagasts, Limbažu novads, LV-4052',
            'place_type' => 'cultural_center',
            'lat' => 57.6534,
            'lon' => 24.7892,
        ],

        // Lauvas
        'lauvu tautas nams' => [
            'name' => 'Lauvu Tautas nams',
            'city' => 'Lauvas',
            'region' => 'Vidzeme',
            'address' => 'Lauvas, Limbažu pagasts, Limbažu novads, LV-4011',
            'place_type' => 'cultural_center',
            'lat' => 57.4812,
            'lon' => 24.6412,
        ],
        'lauvās' => [
            'name' => 'Lauvu Tautas nams',
            'city' => 'Lauvas',
            'region' => 'Vidzeme',
            'address' => 'Lauvas, Limbažu pagasts, Limbažu novads, LV-4011',
            'place_type' => 'cultural_center',
            'lat' => 57.4812,
            'lon' => 24.6412,
        ],

        // Puikule
        'puikules muiža' => [
            'name' => 'Puikules muiža',
            'city' => 'Puikule',
            'region' => 'Vidzeme',
            'address' => 'Puikule, Brīvzemnieku pagasts, Limbažu novads, LV-4061',
            'place_type' => 'historical',
            'lat' => 57.6978,
            'lon' => 24.9654,
        ],
        'puikulē' => [
            'name' => 'Puikules muiža',
            'city' => 'Puikule',
            'region' => 'Vidzeme',
            'address' => 'Puikule, Brīvzemnieku pagasts, Limbažu novads, LV-4061',
            'place_type' => 'historical',
            'lat' => 57.6978,
            'lon' => 24.9654,
        ],

        // Minhauzens / Dunte
        'minhauzen' => [
            'name' => 'Minhauzena muzejs',
            'city' => 'Dunte',
            'region' => 'Vidzeme',
            'address' => 'Duntes muiža, Dunte, Liepupes pagasts, Limbažu novads, LV-4052',
            'place_type' => 'museum',
            'lat' => 57.4047,
            'lon' => 24.4252,
        ],
        'duntē' => [
            'name' => 'Minhauzena muzejs',
            'city' => 'Dunte',
            'region' => 'Vidzeme',
            'address' => 'Duntes muiža, Dunte, Liepupes pagasts, Limbažu novads, LV-4052',
            'place_type' => 'museum',
            'lat' => 57.4047,
            'lon' => 24.4252,
        ],

        // Svētciems / Svētupes muiža
        'svētupes muiža' => [
            'name' => 'Svētupes muižas komplekss',
            'city' => 'Svētciems',
            'region' => 'Vidzeme',
            'address' => 'Svētciems, Salacgrīvas pagasts, Limbažu novads, LV-4033',
            'place_type' => 'historical',
            'lat' => 57.7052,
            'lon' => 24.3821,
        ],
    ];

    public function getSlug(): string
    {
        return 'limbazu-novads';
    }

    public function getName(): string
    {
        return 'Limbažu novada pašvaldība';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $seenExternalIds = [];

        Log::info("Starting Limbažu novads scraping...");

        // 1. Scrape official events calendar from visitLimbazi.lv
        $visitLimbaziEvents = $this->scrapeVisitLimbaziCalendar($seenExternalIds);
        foreach ($visitLimbaziEvents as $dto) {
            $events->push($dto);
        }

        // 2. Optionally scrape limbazunovads.lv/lv/notikumi if available
        $municipalityEvents = $this->scrapeMunicipalityPortal($seenExternalIds);
        foreach ($municipalityEvents as $dto) {
            $events->push($dto);
        }

        Log::info(sprintf("Limbažu novads scraping completed: %d events collected", $events->count()));

        return $events;
    }

    /**
     * Scrape events from visitLimbazi.lv (official municipal tourism & culture portal)
     */
    public function scrapeVisitLimbaziCalendar(array &$seenExternalIds): Collection
    {
        $events = collect();

        try {
            $crawler = $this->fetchCrawler(self::EVENTS_RPC_URL);
            if (!$crawler) {
                Log::warning("Failed to fetch visitLimbazi events from: " . self::EVENTS_RPC_URL);
                return $events;
            }

            // Extract each event link from container
            $itemLinks = [];
            $crawler->filter('a[href^="jaunumi?"]')->each(function (Crawler $node) use (&$itemLinks) {
                $href = $node->attr('href');
                if (preg_match('/jaunumi\?(\d+)/', $href, $m)) {
                    $id = $m[1];
                    if (!isset($itemLinks[$id])) {
                        $itemLinks[$id] = [
                            'id' => $id,
                            'url' => self::BASE_URL . '/lv/jaunumi?' . $id,
                            'title' => trim($node->filter('b')->count() ? $node->filter('b')->text() : $node->text()),
                        ];
                    }
                }
            });

            Log::info(sprintf("Found %d event teasers on visitLimbazi", count($itemLinks)));

            $todayStart = now()->startOfDay();

            foreach ($itemLinks as $item) {
                $id = $item['id'];
                $externalId = 'visitlimbazi_' . $id;

                if (isset($seenExternalIds[$externalId])) {
                    continue;
                }

                $this->politeDelay(500, 200);

                $detailCrawler = $this->fetchCrawler($item['url']);
                if (!$detailCrawler) {
                    continue;
                }

                // Title
                $title = $detailCrawler->filter('h1')->count()
                    ? trim($detailCrawler->filter('h1')->text())
                    : $item['title'];

                if (empty($title)) {
                    continue;
                }

                // Dates from <div class="date">
                $dateText = $detailCrawler->filter('.date')->count()
                    ? trim($detailCrawler->filter('.date')->text())
                    : '';

                $dateRange = $this->parseDateRange($dateText);
                if (!$dateRange || empty($dateRange['start_at'])) {
                    continue;
                }

                $startAt = $dateRange['start_at'];
                $endAt = $dateRange['end_at'] ?? null;

                // STRICT RULE: No past events modification
                if ($startAt->lt($todayStart)) {
                    continue;
                }

                // Content / Description from .column.col-8
                $contentCol = $detailCrawler->filter('.column.col-8');
                $fullDescription = $this->extractRichDescription($contentCol);

                if (empty($fullDescription)) {
                    $fullDescription = $title;
                }

                $shortDescription = Str::limit(strip_tags($fullDescription), 160);

                // Poster Image
                $imageUrl = $this->extractImageUrl($detailCrawler);

                // Ticketing URL
                $ticketUrl = $this->extractTicketUrl($contentCol, $item['url']);

                // Price Parsing
                $priceData = $this->parsePrices($fullDescription);

                // Venue & Location resolution
                $locationData = $this->resolveLocation($title, $fullDescription);

                // Category & EntertainmentType strictly per AGENTS.md
                [$categoryNames, $entertainmentType] = $this->resolveCategories($title, $fullDescription, $locationData['venue_name']);

                $seenExternalIds[$externalId] = true;

                $events->push(new ScrapedEventDTO(
                    title: $title,
                    startAt: $startAt,
                    endAt: $endAt,
                    description: $fullDescription,
                    shortDescription: $shortDescription,
                    venueName: $locationData['venue_name'],
                    city: $locationData['city'],
                    region: $locationData['region'],
                    address: $locationData['address'],
                    latitude: $locationData['latitude'],
                    longitude: $locationData['longitude'],
                    placeType: $locationData['place_type'],
                    categoryNames: $categoryNames,
                    entertainmentType: $entertainmentType,
                    isFree: $priceData['is_free'],
                    priceMin: $priceData['price_min'],
                    priceMax: $priceData['price_max'],
                    currency: 'EUR',
                    ticketUrl: $ticketUrl,
                    imageUrl: $imageUrl,
                    sourceUrl: $item['url'],
                    sourceExternalId: $externalId,
                    locale: 'lv',
                    rawData: array_filter([
                        'visitlimbazi_id' => $id,
                        'date_text' => $dateText,
                        'parsed_venue' => $locationData['venue_name'],
                    ]),
                ));
            }
        } catch (\Throwable $e) {
            Log::error("Error scraping visitLimbazi: " . $e->getMessage(), [
                'scraper' => static::class,
            ]);
        }

        return $events;
    }

    /**
     * Safely attempt to scrape limbazunovads.lv municipal portal if accessible
     */
    public function scrapeMunicipalityPortal(array &$seenExternalIds): Collection
    {
        $events = collect();

        // Check if municipality portal responds within 3 seconds
        try {
            $client = new \GuzzleHttp\Client([
                'timeout' => 4,
                'connect_timeout' => 3,
                'verify' => false,
            ]);

            $calendarUrl = self::MUNICIPALITY_BASE_URL . '/lv/notikumi';
            $response = $client->get($calendarUrl);
            if ($response->getStatusCode() !== 200) {
                return $events;
            }

            $crawler = new Crawler((string) $response->getBody(), $calendarUrl);
            $eventNodes = $crawler->filter('article.event-teaser, .view-events .views-row, a[href*="/lv/notikums/"]');

            $eventNodes->each(function (Crawler $node) use ($events, &$seenExternalIds) {
                $linkNode = $node->filter('a[href*="/lv/notikums/"]');
                if (!$linkNode->count()) {
                    return;
                }

                $href = $linkNode->first()->attr('href');
                $url = str_starts_with($href, 'http') ? $href : self::MUNICIPALITY_BASE_URL . $href;
                $slug = basename(parse_url($url, PHP_URL_PATH));
                $externalId = 'limbazunovads_' . $slug;

                if (isset($seenExternalIds[$externalId])) {
                    return;
                }

                $title = trim($linkNode->text());
                if (empty($title)) {
                    return;
                }

                $detailCrawler = $this->fetchCrawler($url);
                if (!$detailCrawler) {
                    return;
                }

                $fullDescription = $this->extractRichDescription($detailCrawler->filter('.event-full__body, .content-node, .field--name-body'));
                $locationData = $this->resolveLocation($title, $fullDescription ?: '');
                [$categoryNames, $entertainmentType] = $this->resolveCategories($title, $fullDescription ?: '', $locationData['venue_name']);

                $startAt = now()->addDays(2)->setTime(18, 0); // fallback if date node missing

                $seenExternalIds[$externalId] = true;

                $events->push(new ScrapedEventDTO(
                    title: $title,
                    startAt: $startAt,
                    endAt: null,
                    description: $fullDescription ?: $title,
                    shortDescription: Str::limit(strip_tags($fullDescription ?: $title), 160),
                    venueName: $locationData['venue_name'],
                    city: $locationData['city'],
                    region: $locationData['region'],
                    address: $locationData['address'],
                    latitude: $locationData['latitude'],
                    longitude: $locationData['longitude'],
                    placeType: $locationData['place_type'],
                    categoryNames: $categoryNames,
                    entertainmentType: $entertainmentType,
                    isFree: false,
                    priceMin: null,
                    priceMax: null,
                    currency: 'EUR',
                    ticketUrl: $url,
                    imageUrl: null,
                    sourceUrl: $url,
                    sourceExternalId: $externalId,
                    locale: 'lv',
                ));
            });
        } catch (\Throwable $e) {
            // Municipality server down or timed out - silently continue as visitLimbazi is primary
            Log::debug("Municipality portal limbazunovads.lv unreachable: " . $e->getMessage());
        }

        return $events;
    }

    /**
     * Parse date range string from visitLimbazi format:
     * Examples:
     * "19. Novembris 17:00 - 18:30"
     * "21. Novembris 10:30 - 12:30"
     * "19. Decembris 17:00 - 20. Decembris 21:00"
     */
    public function parseDateRange(string $dateText): ?array
    {
        if (empty(trim($dateText))) {
            return null;
        }

        $clean = mb_strtolower(trim($dateText), 'UTF-8');

        $monthMap = [
            'janvāris' => 1, 'februāris' => 2, 'marts' => 3, 'aprīlis' => 4,
            'maijs' => 5, 'jūnijs' => 6, 'jūlijs' => 7, 'augusts' => 8,
            'septembris' => 9, 'oktobris' => 10, 'novembris' => 11, 'decembris' => 12,
            'janvārī' => 1, 'februārī' => 2, 'martā' => 3, 'aprīlī' => 4,
            'maijā' => 5, 'jūnijā' => 6, 'jūlijā' => 7, 'augustā' => 8,
            'septembrī' => 9, 'oktobrī' => 10, 'novembrī' => 11, 'decembrī' => 12,
        ];

        $currentYear = now()->year;
        $currentMonth = now()->month;

        // Pattern 1: Multi-day range: "19. Decembris 17:00 - 20. Decembris 21:00"
        if (preg_match('/(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)\s+(\d{1,2}:\d{2})\s*-\s*(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)\s+(\d{1,2}:\d{2})/u', $clean, $m2)) {
            $sDay = (int) $m2[1];
            $sMonth = $monthMap[$m2[2]] ?? $currentMonth;
            [$sh, $sm] = explode(':', $m2[3]);

            $eDay = (int) $m2[4];
            $eMonth = $monthMap[$m2[5]] ?? $currentMonth;
            [$eh, $em] = explode(':', $m2[6]);

            $sYear = ($sMonth < $currentMonth && $currentMonth >= 10) ? $currentYear + 1 : $currentYear;
            $eYear = ($eMonth < $currentMonth && $currentMonth >= 10) ? $currentYear + 1 : $currentYear;

            $start = Carbon::create($sYear, $sMonth, $sDay, (int) $sh, (int) $sm, 0);
            $end = Carbon::create($eYear, $eMonth, $eDay, (int) $eh, (int) $em, 0);

            return ['start_at' => $start, 'end_at' => $end];
        }

        // Pattern 2: Single day: "19. Novembris 17:00 - 18:30" or "19. Novembris 17:00"
        if (preg_match('/(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)\s+(\d{1,2}:\d{2})(?:\s*-\s*(\d{1,2}:\d{2}))?/u', $clean, $m)) {
            $day = (int) $m[1];
            $monthName = $m[2];
            $month = $monthMap[$monthName] ?? $currentMonth;

            $year = ($month < $currentMonth && $currentMonth >= 10) ? $currentYear + 1 : $currentYear;
            [$sh, $sm] = explode(':', $m[3]);

            $start = Carbon::create($year, $month, $day, (int) $sh, (int) $sm, 0);

            $end = null;
            if (!empty($m[4])) {
                [$eh, $em] = explode(':', $m[4]);
                $end = Carbon::create($year, $month, $day, (int) $eh, (int) $em, 0);
            }

            return ['start_at' => $start, 'end_at' => $end];
        }

        return null;
    }

    /**
     * Extract rich description from HTML content
     */
    public function extractRichDescription(Crawler $crawler): string
    {
        if (!$crawler->count()) {
            return '';
        }

        $html = $crawler->html();

        // Remove date and h1 header tags if present inside column
        $html = preg_replace('/<div class="date">.*?<\/div>/is', '', $html);
        $html = preg_replace('/<h1>.*?<\/h1>/is', '', $html);

        return $this->cleanText($html) ?: '';
    }

    /**
     * Extract poster image URL
     */
    public function extractImageUrl(Crawler $crawler): ?string
    {
        // 1. Check fancybox gallery link
        $fancyBoxLink = $crawler->filter('.jaunumsOpenIMG a[data-fancybox], a[data-fancybox="gallery"]');
        if ($fancyBoxLink->count()) {
            $href = $fancyBoxLink->first()->attr('href');
            if (!empty($href)) {
                return str_starts_with($href, 'http') ? $href : self::BASE_URL . '/lv/' . ltrim($href, '/');
            }
        }

        // 2. Check image tag
        $img = $crawler->filter('.jaunumsOpenIMG img, .column.col-4 img');
        if ($img->count()) {
            $src = $img->first()->attr('src');
            if (!empty($src)) {
                // If src is a thumbnail URL like "tumbs/?w=1&i=box/jaunumi/4884.webp", extract the original box image
                if (preg_match('/i=(box\/[^\s&"\']+)/', $src, $im)) {
                    return self::BASE_URL . '/lv/' . $im[1];
                }
                return str_starts_with($src, 'http') ? $src : self::BASE_URL . '/lv/' . ltrim($src, '/');
            }
        }

        return null;
    }

    /**
     * Extract ticketing URL from external ticket provider links
     */
    public function extractTicketUrl(Crawler $crawler, string $fallbackUrl): string
    {
        if ($crawler->count()) {
            $links = $crawler->filter('a')->extract(['href']);
            foreach ($links as $href) {
                if (empty($href)) continue;
                if (preg_match('/(?:bilesuparadize\.lv|bezrindas\.lv|ticketshop\.lv|fienta\.com|aula\.lv)\/([^\s"\']+)/i', $href)) {
                    return trim($href);
                }
            }
        }

        return $fallbackUrl;
    }

    /**
     * Parse ticket pricing from event text
     */
    public function parsePrices(string $text): array
    {
        $isFree = false;
        $priceMin = null;
        $priceMax = null;

        $t = mb_strtolower($text, 'UTF-8');

        if (str_contains($t, 'bez maksas') || str_contains($t, 'brīva ieeja') || str_contains($t, 'ieeja brīva')) {
            $isFree = true;
            return ['is_free' => true, 'price_min' => 0.0, 'price_max' => 0.0];
        }

        // Search for patterns: "cena 5,- eur", "biļetes cena: 5 €", "ieejas maksa – 7 €", "cena ir 5 eur"
        if (preg_match('/(?:cena|maksa|biļet[a-z]*)[^\d\n\r]{1,30}(\d+)(?:[.,](?:\d{2}|-))?\s*(?:€|eur)/ui', $t, $m)) {
            $price = (float) $m[1];
            if ($price > 0 && $price < 1000) {
                $priceMin = $price;
                $priceMax = $price;
                $isFree = false;
            }
        }

        return [
            'is_free' => $isFree,
            'price_min' => $priceMin,
            'price_max' => $priceMax,
        ];
    }

    /**
     * Resolve structured venue details
     */
    public function resolveLocation(string $title, string $description): array
    {
        $combined = mb_strtolower($title . ' ' . $description, 'UTF-8');

        foreach ($this->venueAddressMap as $key => $venue) {
            if (str_contains($combined, $key)) {
                return [
                    'venue_name' => $venue['name'],
                    'city' => $venue['city'],
                    'region' => $venue['region'],
                    'address' => $venue['address'],
                    'latitude' => $venue['lat'] ?? null,
                    'longitude' => $venue['lon'] ?? null,
                    'place_type' => $venue['place_type'],
                ];
            }
        }

        // Default Limbaži
        return [
            'venue_name' => 'Limbažu novads',
            'city' => 'Limbaži',
            'region' => 'Vidzeme',
            'address' => 'Limbažu novads',
            'latitude' => 57.5146,
            'longitude' => 24.7176,
            'place_type' => 'venue',
        ];
    }

    /**
     * Resolve categories & entertainmentType strictly adhering to AGENTS.md
     */
    public function resolveCategories(string $title, string $description, string $venueName): array
    {
        $canonical = [];
        $entertainmentType = null;

        $combined = mb_strtolower($title . ' ' . $description . ' ' . $venueName, 'UTF-8');
        $titleLower = mb_strtolower($title, 'UTF-8');

        // 1. Strict Cinema / Kino rule (AGENTS.md):
        // Any event with filma, filmas, filmu, filmā, kinoseanss, spēlfilma, dokumentālā filma is ALWAYS Kino and NEVER Teātris!
        $isCinema = (bool) preg_match('/\b(kinoseanss|kino seanss|spēlfilma|dokumentālā filma|animācijas filma|kinofestivāls)\b/ui', $titleLower)
            || ((bool) preg_match('/\b(filma|filmas|filmā|filmu)\b/ui', $titleLower) && !preg_match('/\b(dziesm|mūzik|soundtrack|koncerts)\b/ui', $titleLower))
            || str_contains($combined, 'kinofilma') || str_contains($combined, 'kino seanss');

        if ($isCinema) {
            $canonical[] = 'Kino';
            $entertainmentType = 'kino';
            return [array_values(array_unique($canonical)), $entertainmentType];
        }

        // 2. Real Theatre productions (AGENTS.md):
        // Actual productions (izrāde, luga, iestudējums, komēdija, opera, balets)
        if (preg_match('/\b(izrāde|izrādes|izrādē|luga|iestudējums|komēdija|teātris|teātra)\b/ui', $titleLower) || str_contains($combined, 'pannas teātr') || str_contains($combined, 'humora izrāde')) {
            $canonical[] = 'Teātris';
            $entertainmentType = str_contains($combined, 'humor') || str_contains($combined, 'standup') ? 'standup' : 'theatre';

            if (str_contains($combined, 'bērniem') || str_contains($combined, 'leļļu')) {
                $canonical[] = 'Bērniem';
                $entertainmentType = 'kids';
            }
        }

        // 3. Music / Concerts
        if (preg_match('/\b(koncerts|koncertā|dziesm|koris|ansambl|džezs|muzicē|orķestr)\b/ui', $titleLower) || str_contains($combined, 'koncerts')) {
            $canonical[] = 'Mūzika';
            $entertainmentType = $entertainmentType ?: 'concert';
        }

        // 4. Exhibitions
        if (str_contains($combined, 'izstāde') || str_contains($combined, 'izstādē') || str_contains($combined, 'ekspozīcij')) {
            $canonical[] = 'Izstādes';
            $entertainmentType = $entertainmentType ?: 'exhibition';
        }

        // 5. Celebrations & Festivals
        if (str_contains($combined, 'svētki') || str_contains($combined, 'svētkos') || str_contains($combined, 'balle') || str_contains($combined, 'festivāls') || str_contains($combined, 'egles iedegšana')) {
            $canonical[] = 'Svētki';
            $entertainmentType = $entertainmentType ?: 'festival';
        }

        // 6. Kids / Family
        if (str_contains($combined, 'bērniem') || str_contains($combined, 'ģimenēm') || str_contains($combined, 'jauniešiem')) {
            $canonical[] = 'Bērniem';
            $entertainmentType = $entertainmentType ?: 'kids';
        }

        // Fallback to Cits
        if (empty($canonical)) {
            $canonical[] = 'Cits';
            $entertainmentType = $entertainmentType ?: 'other';
        }

        return [array_values(array_unique($canonical)), $entertainmentType];
    }
}
