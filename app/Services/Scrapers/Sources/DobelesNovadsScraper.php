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

class DobelesNovadsScraper extends BaseScraper
{
    public const BASE_URL = 'https://visitdobele.lv';
    public const EVENTS_URL = 'https://visitdobele.lv/pasakumi';
    public const MUNICIPALITY_BASE_URL = 'https://www.dobele.lv';

    /**
     * Map of known Dobeles novads venues with normalized metadata
     */
    protected array $venueAddressMap = [
        // Dobele city
        'dobeles pilsētas kultūras nams' => [
            'name' => 'Dobeles pilsētas Kultūras nams',
            'city' => 'Dobele',
            'region' => 'Zemgale',
            'address' => 'Baznīcas iela 6, Dobele, Dobeles novads, LV-3701',
            'place_type' => 'cultural_center',
            'lat' => 56.6263,
            'lon' => 23.2798,
        ],
        'dobeles kultūras nams' => [
            'name' => 'Dobeles pilsētas Kultūras nams',
            'city' => 'Dobele',
            'region' => 'Zemgale',
            'address' => 'Baznīcas iela 6, Dobele, Dobeles novads, LV-3701',
            'place_type' => 'cultural_center',
            'lat' => 56.6263,
            'lon' => 23.2798,
        ],
        'dobeles vēsturiskais centrs' => [
            'name' => 'Dobeles vēsturiskais tirgus laukums',
            'city' => 'Dobele',
            'region' => 'Zemgale',
            'address' => 'Tirgus laukums, Dobele, Dobeles novads, LV-3701',
            'place_type' => 'square',
            'lat' => 56.6258,
            'lon' => 23.2795,
        ],
        'dobeles pilsētas vēsturiskais centrs' => [
            'name' => 'Dobeles vēsturiskais tirgus laukums',
            'city' => 'Dobele',
            'region' => 'Zemgale',
            'address' => 'Tirgus laukums, Dobele, Dobeles novads, LV-3701',
            'place_type' => 'square',
            'lat' => 56.6258,
            'lon' => 23.2795,
        ],
        'dobeles pils' => [
            'name' => 'Dobeles Pils',
            'city' => 'Dobele',
            'region' => 'Zemgale',
            'address' => 'Tērvetes iela 2, Dobele, Dobeles novads, LV-3701',
            'place_type' => 'castle',
            'lat' => 56.6219,
            'lon' => 23.2748,
        ],
        'dobeles amatu māja' => [
            'name' => 'Dobeles Amatu māja',
            'city' => 'Dobele',
            'region' => 'Zemgale',
            'address' => 'Baznīcas iela 8, Dobele, Dobeles novads, LV-3701',
            'place_type' => 'museum',
            'lat' => 56.6265,
            'lon' => 23.2799,
        ],
        'amatu māja' => [
            'name' => 'Dobeles Amatu māja',
            'city' => 'Dobele',
            'region' => 'Zemgale',
            'address' => 'Baznīcas iela 8, Dobele, Dobeles novads, LV-3701',
            'place_type' => 'museum',
            'lat' => 56.6265,
            'lon' => 23.2799,
        ],
        'dobeles mūzikas skola' => [
            'name' => 'Dobeles Mūzikas skolas akustiskā koncertzāle "Zinta"',
            'city' => 'Dobele',
            'region' => 'Zemgale',
            'address' => 'Skolas iela 2, Dobele, Dobeles novads, LV-3701',
            'place_type' => 'concert_hall',
            'lat' => 56.6247,
            'lon' => 23.2785,
        ],
        'koncertzāle zinta' => [
            'name' => 'Dobeles Mūzikas skolas akustiskā koncertzāle "Zinta"',
            'city' => 'Dobele',
            'region' => 'Zemgale',
            'address' => 'Skolas iela 2, Dobele, Dobeles novads, LV-3701',
            'place_type' => 'concert_hall',
            'lat' => 56.6247,
            'lon' => 23.2785,
        ],
        'zinta' => [
            'name' => 'Dobeles Mūzikas skolas akustiskā koncertzāle "Zinta"',
            'city' => 'Dobele',
            'region' => 'Zemgale',
            'address' => 'Skolas iela 2, Dobele, Dobeles novads, LV-3701',
            'place_type' => 'concert_hall',
            'lat' => 56.6247,
            'lon' => 23.2785,
        ],
        'dobeles atbrīvošanas piemineklis' => [
            'name' => 'Dobeles Atbrīvošanas piemineklis',
            'city' => 'Dobele',
            'region' => 'Zemgale',
            'address' => 'Uzvaras iela, Dobele, Dobeles novads, LV-3701',
            'place_type' => 'square',
            'lat' => 56.6234,
            'lon' => 23.2778,
        ],
        'atbrīvošanas piemineklis' => [
            'name' => 'Dobeles Atbrīvošanas piemineklis',
            'city' => 'Dobele',
            'region' => 'Zemgale',
            'address' => 'Uzvaras iela, Dobele, Dobeles novads, LV-3701',
            'place_type' => 'square',
            'lat' => 56.6234,
            'lon' => 23.2778,
        ],
        'dārzkopības institūts' => [
            'name' => 'Dārzkopības institūts un Pētera Upīša dārzs',
            'city' => 'Dobele',
            'region' => 'Zemgale',
            'address' => 'Graudu iela 1, Dobele, Dobeles novads, LV-3701',
            'place_type' => 'park',
            'lat' => 56.6111,
            'lon' => 23.3039,
        ],
        'upīša ceriņu dārzs' => [
            'name' => 'Dārzkopības institūts un Pētera Upīša dārzs',
            'city' => 'Dobele',
            'region' => 'Zemgale',
            'address' => 'Graudu iela 1, Dobele, Dobeles novads, LV-3701',
            'place_type' => 'park',
            'lat' => 56.6111,
            'lon' => 23.3039,
        ],
        'dobeles novada muzejs' => [
            'name' => 'Dobeles novada muzejs',
            'city' => 'Dobele',
            'region' => 'Zemgale',
            'address' => 'Brīvības iela 7, Dobele, Dobeles novads, LV-3701',
            'place_type' => 'museum',
            'lat' => 56.6241,
            'lon' => 23.2775,
        ],
        'dobeles sporta centrs' => [
            'name' => 'Dobeles sporta centrs',
            'city' => 'Dobele',
            'region' => 'Zemgale',
            'address' => 'Tērvetes iela 10, Dobele, Dobeles novads, LV-3701',
            'place_type' => 'hall',
            'lat' => 56.6198,
            'lon' => 23.2762,
        ],

        // Auce
        'auces pilsētas kultūras nams' => [
            'name' => 'Auces pilsētas Kultūras nams',
            'city' => 'Auce',
            'region' => 'Zemgale',
            'address' => 'Auces iela 2, Auce, Dobeles novads, LV-3708',
            'place_type' => 'cultural_center',
            'lat' => 56.4602,
            'lon' => 22.9015,
        ],
        'auces kultūras nams' => [
            'name' => 'Auces pilsētas Kultūras nams',
            'city' => 'Auce',
            'region' => 'Zemgale',
            'address' => 'Auces iela 2, Auce, Dobeles novads, LV-3708',
            'place_type' => 'cultural_center',
            'lat' => 56.4602,
            'lon' => 22.9015,
        ],
        'vecauces pils' => [
            'name' => 'Vecauces pils',
            'city' => 'Vecauce',
            'region' => 'Zemgale',
            'address' => 'Akadēmijas iela 11, Vecauce, Dobeles novads, LV-3708',
            'place_type' => 'castle',
            'lat' => 56.4682,
            'lon' => 22.8885,
        ],

        // Tērvete & Kroņauce
        'lvm dabas parks tērvetē' => [
            'name' => 'LVM dabas parks Tērvetē',
            'city' => 'Tērvete',
            'region' => 'Zemgale',
            'address' => '"Tērvetes sils", Tērvetes pagasts, Dobeles novads, LV-3730',
            'place_type' => 'park',
            'lat' => 56.4883,
            'lon' => 23.3869,
        ],
        'tērvetes dabas parks' => [
            'name' => 'LVM dabas parks Tērvetē',
            'city' => 'Tērvete',
            'region' => 'Zemgale',
            'address' => '"Tērvetes sils", Tērvetes pagasts, Dobeles novads, LV-3730',
            'place_type' => 'park',
            'lat' => 56.4883,
            'lon' => 23.3869,
        ],
        'tērvete' => [
            'name' => 'LVM dabas parks Tērvetē',
            'city' => 'Tērvete',
            'region' => 'Zemgale',
            'address' => '"Tērvetes sils", Tērvetes pagasts, Dobeles novads, LV-3730',
            'place_type' => 'park',
            'lat' => 56.4883,
            'lon' => 23.3869,
        ],
        'zoltners' => [
            'name' => 'Zoltners restorāns un viesnīca',
            'city' => 'Kroņauce',
            'region' => 'Zemgale',
            'address' => 'Kroņauce, Tērvetes pagasts, Dobeles novads, LV-3730',
            'place_type' => 'restaurant',
            'lat' => 56.5165,
            'lon' => 23.3611,
        ],

        // Jaunbērze
        'jaunbērzes tautas nams' => [
            'name' => 'Jaunbērzes Tautas nams',
            'city' => 'Jaunbērze',
            'region' => 'Zemgale',
            'address' => 'Bērzes iela 6, Jaunbērze, Jaunbērzes pagasts, Dobeles novads, LV-3717',
            'place_type' => 'cultural_center',
            'lat' => 56.6698,
            'lon' => 23.4075,
        ],
        'jaunbērzē' => [
            'name' => 'Jaunbērzes Tautas nams',
            'city' => 'Jaunbērze',
            'region' => 'Zemgale',
            'address' => 'Bērzes iela 6, Jaunbērze, Jaunbērzes pagasts, Dobeles novads, LV-3717',
            'place_type' => 'cultural_center',
            'lat' => 56.6698,
            'lon' => 23.4075,
        ],

        // Bērze / Bērzmuiža
        'bērzmuiža' => [
            'name' => 'Kultūras un atpūtas centrs "Bērzmuiža"',
            'city' => 'Bērzmuiža',
            'region' => 'Zemgale',
            'address' => 'Bērzmuiža, Bērzes pagasts, Dobeles novads, LV-3732',
            'place_type' => 'cultural_center',
            'lat' => 56.6433,
            'lon' => 23.3444,
        ],
        'bērzmuižā' => [
            'name' => 'Kultūras un atpūtas centrs "Bērzmuiža"',
            'city' => 'Bērzmuiža',
            'region' => 'Zemgale',
            'address' => 'Bērzmuiža, Bērzes pagasts, Dobeles novads, LV-3732',
            'place_type' => 'cultural_center',
            'lat' => 56.6433,
            'lon' => 23.3444,
        ],
        'pikšas' => [
            'name' => 'Kārļa Ulmaņa piemiņas muzejs "Pikšas"',
            'city' => 'Pikšas',
            'region' => 'Zemgale',
            'address' => '"Pikšas", Bērzes pagasts, Dobeles novads, LV-3732',
            'place_type' => 'museum',
            'lat' => 56.6479,
            'lon' => 23.3934,
        ],

        // Krimūnas & Putni
        'krimūnu tautas nams' => [
            'name' => 'Krimūnu Tautas nams',
            'city' => 'Krimūnas',
            'region' => 'Zemgale',
            'address' => 'Krimūnas, Krimūnu pagasts, Dobeles novads, LV-3719',
            'place_type' => 'cultural_center',
            'lat' => 56.5744,
            'lon' => 23.3283,
        ],
        'krimūnās' => [
            'name' => 'Krimūnu Tautas nams',
            'city' => 'Krimūnas',
            'region' => 'Zemgale',
            'address' => 'Krimūnas, Krimūnu pagasts, Dobeles novads, LV-3719',
            'place_type' => 'cultural_center',
            'lat' => 56.5744,
            'lon' => 23.3283,
        ],
        'putni' => [
            'name' => 'Lauku māja "Putni"',
            'city' => 'Putni',
            'region' => 'Zemgale',
            'address' => '"Putni", Krimūnu pagasts, Dobeles novads, LV-3719',
            'place_type' => 'outdoor',
            'lat' => 56.5925,
            'lon' => 23.3312,
        ],
        'putnos' => [
            'name' => 'Lauku māja "Putni"',
            'city' => 'Putni',
            'region' => 'Zemgale',
            'address' => '"Putni", Krimūnu pagasts, Dobeles novads, LV-3719',
            'place_type' => 'outdoor',
            'lat' => 56.5925,
            'lon' => 23.3312,
        ],

        // Annenieki & Vipēdis
        'vipēdis' => [
            'name' => 'Atpūtas bāze "Vipēdis"',
            'city' => 'Vipēdis',
            'region' => 'Zemgale',
            'address' => 'Vipēdis, Annenieku pagasts, Dobeles novads, LV-3715',
            'place_type' => 'outdoor',
            'lat' => 56.6661,
            'lon' => 23.1118,
        ],
        'vipēdes ezers' => [
            'name' => 'Atpūtas bāze "Vipēdis"',
            'city' => 'Vipēdis',
            'region' => 'Zemgale',
            'address' => 'Vipēdis, Annenieku pagasts, Dobeles novads, LV-3715',
            'place_type' => 'outdoor',
            'lat' => 56.6661,
            'lon' => 23.1118,
        ],
        'annenieki' => [
            'name' => 'Annenieku pagasts',
            'city' => 'Annenieki',
            'region' => 'Zemgale',
            'address' => 'Annenieki, Dobeles novads, LV-3715',
            'place_type' => 'outdoor',
            'lat' => 56.6683,
            'lon' => 23.1167,
        ],

        // Biksti
        'bikstu tautas nams' => [
            'name' => 'Bikstu Tautas nams',
            'city' => 'Biksti',
            'region' => 'Zemgale',
            'address' => 'Biksti, Bikstu pagasts, Dobeles novads, LV-3713',
            'place_type' => 'cultural_center',
            'lat' => 56.6341,
            'lon' => 23.0033,
        ],
        'bikstos' => [
            'name' => 'Bikstu Tautas nams',
            'city' => 'Biksti',
            'region' => 'Zemgale',
            'address' => 'Biksti, Bikstu pagasts, Dobeles novads, LV-3713',
            'place_type' => 'cultural_center',
            'lat' => 56.6341,
            'lon' => 23.0033,
        ],

        // Penkule
        'penkules tautas nams' => [
            'name' => 'Penkules Tautas nams',
            'city' => 'Penkule',
            'region' => 'Zemgale',
            'address' => 'Penkule, Penkules pagasts, Dobeles novads, LV-3725',
            'place_type' => 'cultural_center',
            'lat' => 56.4952,
            'lon' => 23.2384,
        ],

        // Bēne
        'bēnes tautas nams' => [
            'name' => 'Bēnes Tautas nams',
            'city' => 'Bēne',
            'region' => 'Zemgale',
            'address' => 'Centra iela 24, Bēne, Bēnes pagasts, Dobeles novads, LV-3723',
            'place_type' => 'cultural_center',
            'lat' => 56.4862,
            'lon' => 23.0617,
        ],
        'bēnē' => [
            'name' => 'Bēnes Tautas nams',
            'city' => 'Bēne',
            'region' => 'Zemgale',
            'address' => 'Centra iela 24, Bēne, Bēnes pagasts, Dobeles novads, LV-3723',
            'place_type' => 'cultural_center',
            'lat' => 56.4862,
            'lon' => 23.0617,
        ],

        // Naudīte
        'naudīte' => [
            'name' => 'Naudītes pagasta pārvalde',
            'city' => 'Naudīte',
            'region' => 'Zemgale',
            'address' => 'Naudīte, Naudītes pagasts, Dobeles novads, LV-3724',
            'place_type' => 'cultural_center',
            'lat' => 56.5512,
            'lon' => 23.1782,
        ],
        'naudītē' => [
            'name' => 'Naudītes pagasta pārvalde',
            'city' => 'Naudīte',
            'region' => 'Zemgale',
            'address' => 'Naudīte, Naudītes pagasts, Dobeles novads, LV-3724',
            'place_type' => 'cultural_center',
            'lat' => 56.5512,
            'lon' => 23.1782,
        ],
    ];

    public function getSlug(): string
    {
        return 'dobeles-novads';
    }

    public function getName(): string
    {
        return 'Dobeles novada pašvaldība';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $seenExternalIds = [];

        Log::info("Starting Dobeles novads scraping...");

        // 1. Scrape official events calendar from visitdobele.lv
        $visitDobeleEvents = $this->scrapeVisitDobeleCalendar($seenExternalIds);
        foreach ($visitDobeleEvents as $dto) {
            $events->push($dto);
        }

        // 2. Optionally scrape dobele.lv/lv/notikumi if reachable
        $municipalityEvents = $this->scrapeMunicipalityPortal($seenExternalIds);
        foreach ($municipalityEvents as $dto) {
            $events->push($dto);
        }

        Log::info(sprintf("Dobeles novads scraping completed: %d events collected", $events->count()));

        return $events;
    }

    /**
     * Scrape events from visitdobele.lv (official municipal tourism & culture portal)
     */
    public function scrapeVisitDobeleCalendar(array &$seenExternalIds): Collection
    {
        $events = collect();

        try {
            $crawler = $this->fetchCrawler(self::EVENTS_URL);
            if (!$crawler) {
                Log::warning("Failed to fetch visitDobele events from: " . self::EVENTS_URL);
                return $events;
            }

            $cardNodes = $crawler->filter('a.card[href*="/pasakumi/"]');
            Log::info(sprintf("Found %d event cards on visitdobele.lv", $cardNodes->count()));

            $todayStart = now()->startOfDay();

            $cardNodes->each(function (Crawler $card) use ($events, &$seenExternalIds, $todayStart) {
                $href = $card->attr('href');
                if (!preg_match('~/pasakumi/(\d+)(?:/([^\s/?#]+))?~', $href, $m)) {
                    return;
                }

                $id = $m[1];
                $externalId = 'dobele_' . $id;

                if (isset($seenExternalIds[$externalId])) {
                    return;
                }

                // Extract background image from card style
                $cardBgImage = null;
                $style = $card->attr('style') ?? '';
                if (preg_match('#url\((.*?)\)#i', $style, $im)) {
                    $cardBgImage = trim($im[1], '\'" ');
                }

                // Date string from card-calendar
                $calNode = $card->filter('.card-calendar');
                $dateText = $calNode->count() ? trim($calNode->text()) : '';

                // Location inside card-title
                $locNode = $card->filter('.i-location');
                $cardVenue = $locNode->count() ? trim($locNode->text()) : '';

                // Clean title
                $titleNode = $card->filter('.card-title');
                $title = '';
                if ($titleNode->count()) {
                    $cloned = clone $titleNode->getNode(0);
                    // remove .i-location child if present
                    foreach ($cloned->childNodes as $child) {
                        if ($child instanceof \DOMElement && str_contains($child->getAttribute('class'), 'i-location')) {
                            $cloned->removeChild($child);
                        }
                    }
                    $title = trim($cloned->textContent);
                }

                if (empty($title)) {
                    return;
                }

                // Parse dates
                $dateRange = $this->parseDateRange($dateText);
                if (!$dateRange || !$dateRange['start_at']) {
                    return;
                }

                $startAt = $dateRange['start_at'];
                $endAt = $dateRange['end_at'];

                // STRICT RULE: Current & upcoming events only!
                if ($endAt !== null && $endAt->isBefore($todayStart)) {
                    return;
                }
                if ($endAt === null && $startAt->isBefore($todayStart)) {
                    return;
                }

                $eventUrl = str_starts_with($href, 'http') ? $href : self::BASE_URL . $href;

                // Fetch detail page for rich description, high-res image, and ticket links
                $detailCrawler = $this->fetchCrawler($eventUrl);
                $fullDescription = '';
                $imageUrl = $cardBgImage;
                $ticketUrl = $eventUrl;
                $detailVenue = '';

                if ($detailCrawler) {
                    // Extract rich description
                    $saturs = $detailCrawler->filter('.obj_saturs');
                    if ($saturs->count()) {
                        $fullDescription = $this->extractRichDescription($saturs);
                    }

                    // Extract high-res image from detail page
                    $detailImg = $detailCrawler->filter('.obj_saturs img');
                    if ($detailImg->count()) {
                        $imgNode = $detailImg->first();
                        $srcset = $imgNode->attr('srcset') ?? '';
                        $src = $imgNode->attr('src') ?? '';

                        $bestFromSrcset = $this->extractBestImageFromSrcset($srcset);
                        $imageUrl = $bestFromSrcset ?: ($src ?: $cardBgImage);
                    }

                    // Extract detail venue if present in .norises_vieta
                    $vietaNode = $detailCrawler->filter('.norises_vieta');
                    if ($vietaNode->count()) {
                        $detailVenue = trim($vietaNode->text());
                    }

                    // Extract ticketing URL from links in detail page
                    $ticketUrl = $this->extractTicketUrl($detailCrawler, $eventUrl);
                }

                if (empty($fullDescription)) {
                    $fullDescription = $title;
                    if (!empty($cardVenue)) {
                        $fullDescription .= "\nNorises vieta: " . $cardVenue;
                    }
                }

                $shortDescription = Str::limit(strip_tags($fullDescription), 160);

                // Venue & location resolution
                $locationData = $this->resolveLocation($cardVenue, $detailVenue, $title, $fullDescription);

                // Category & EntertainmentType strictly per AGENTS.md
                [$categoryNames, $entertainmentType] = $this->resolveCategories($title, $fullDescription, $locationData['venue_name']);

                // Pricing
                $priceData = $this->parsePrices($fullDescription);

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
                    sourceUrl: $eventUrl,
                    sourceExternalId: $externalId,
                    locale: 'lv',
                    rawData: array_filter([
                        'visitdobele_id' => $id,
                        'date_text' => $dateText,
                        'card_venue' => $cardVenue,
                        'detail_venue' => $detailVenue,
                        'parsed_venue' => $locationData['venue_name'],
                    ]),
                ));
            });
        } catch (\Throwable $e) {
            Log::error("Error scraping visitDobele: " . $e->getMessage(), [
                'scraper' => static::class,
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $events;
    }

    /**
     * Safely attempt to scrape dobele.lv municipal portal if accessible
     */
    public function scrapeMunicipalityPortal(array &$seenExternalIds): Collection
    {
        $events = collect();

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
                $externalId = 'dobele_muni_' . $slug;

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
                $locationData = $this->resolveLocation('', '', $title, $fullDescription ?: '');
                [$categoryNames, $entertainmentType] = $this->resolveCategories($title, $fullDescription ?: '', $locationData['venue_name']);

                $startAt = now()->addDays(2)->setTime(18, 0);

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
            // Municipality server down or timed out - silently continue as visitDobele is primary
            Log::debug("Municipality portal dobele.lv unreachable: " . $e->getMessage());
        }

        return $events;
    }

    /**
     * Parse date range string from visitdobele format:
     * Examples:
     * "25.10.2026 11:00 - 22.11.2026 - 14:00"
     * "06.10.2026 08:00 - 27.11.2026"
     * "20.11.2026 18:00 - 20:30"
     * "19.09.2026 - 01.12.2026"
     * "18.11.2026 17:00"
     * "22.11.2026"
     */
    public function parseDateRange(string $dateText): ?array
    {
        $raw = trim($dateText);
        if (empty($raw)) {
            return null;
        }

        // Pattern 1: Full multi-day with times: "25.10.2026 11:00 - 22.11.2026 - 14:00"
        if (preg_match('/^(\d{2}\.\d{2}\.\d{4})\s+(\d{2}:\d{2})\s*-\s*(\d{2}\.\d{2}\.\d{4})\s*-\s*(\d{2}:\d{2})$/', $raw, $m)) {
            return [
                'start_at' => Carbon::createFromFormat('d.m.Y H:i', "{$m[1]} {$m[2]}", 'Europe/Riga'),
                'end_at' => Carbon::createFromFormat('d.m.Y H:i', "{$m[3]} {$m[4]}", 'Europe/Riga'),
            ];
        }

        // Pattern 2: Multi-day with start time only: "06.10.2026 08:00 - 27.11.2026"
        if (preg_match('/^(\d{2}\.\d{2}\.\d{4})\s+(\d{2}:\d{2})\s*-\s*(\d{2}\.\d{2}\.\d{4})$/', $raw, $m)) {
            return [
                'start_at' => Carbon::createFromFormat('d.m.Y H:i', "{$m[1]} {$m[2]}", 'Europe/Riga'),
                'end_at' => Carbon::createFromFormat('d.m.Y H:i:s', "{$m[3]} 23:59:59", 'Europe/Riga'),
            ];
        }

        // Pattern 3: Single day time range: "20.11.2026 18:00 - 20:30"
        if (preg_match('/^(\d{2}\.\d{2}\.\d{4})\s+(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})$/', $raw, $m)) {
            return [
                'start_at' => Carbon::createFromFormat('d.m.Y H:i', "{$m[1]} {$m[2]}", 'Europe/Riga'),
                'end_at' => Carbon::createFromFormat('d.m.Y H:i', "{$m[1]} {$m[3]}", 'Europe/Riga'),
            ];
        }

        // Pattern 4: Date range without times: "19.09.2026 - 01.12.2026"
        if (preg_match('/^(\d{2}\.\d{2}\.\d{4})\s*-\s*(\d{2}\.\d{2}\.\d{4})$/', $raw, $m)) {
            return [
                'start_at' => Carbon::createFromFormat('d.m.Y H:i:s', "{$m[1]} 00:00:00", 'Europe/Riga'),
                'end_at' => Carbon::createFromFormat('d.m.Y H:i:s', "{$m[2]} 23:59:59", 'Europe/Riga'),
            ];
        }

        // Pattern 5: Single day with start time: "18.11.2026 17:00"
        if (preg_match('/^(\d{2}\.\d{2}\.\d{4})\s+(\d{2}:\d{2})$/', $raw, $m)) {
            return [
                'start_at' => Carbon::createFromFormat('d.m.Y H:i', "{$m[1]} {$m[2]}", 'Europe/Riga'),
                'end_at' => null,
            ];
        }

        // Pattern 6: Single day only: "22.11.2026"
        if (preg_match('/^(\d{2}\.\d{2}\.\d{4})$/', $raw, $m)) {
            return [
                'start_at' => Carbon::createFromFormat('d.m.Y H:i:s', "{$m[1]} 00:00:00", 'Europe/Riga'),
                'end_at' => null,
            ];
        }

        return null;
    }

    /**
     * Extract rich description from HTML crawler without truncating
     */
    public function extractRichDescription(Crawler $crawler): string
    {
        if (!$crawler->count()) {
            return '';
        }

        $node = $crawler->first()->getNode(0);
        $doc = new \DOMDocument();
        $imported = $doc->importNode($node, true);
        $doc->appendChild($imported);

        $xpath = new \DOMXPath($doc);
        // Remove image elements from description body
        foreach ($xpath->query('.//img') as $img) {
            $img->parentNode->removeChild($img);
        }

        $html = $doc->saveHTML();
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/p>/i', "\n\n", $html);
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Pick highest resolution image from srcset
     */
    public function extractBestImageFromSrcset(string $srcset): ?string
    {
        if (empty(trim($srcset))) {
            return null;
        }

        if (preg_match_all('/(https?:\/\/[^\s]+)\s+(\d+)w/i', $srcset, $matches, PREG_SET_ORDER)) {
            usort($matches, fn($a, $b) => (int) $b[2] <=> (int) $a[2]);
            return $matches[0][1];
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
                if (empty($href)) {
                    continue;
                }

                // Unwrap Facebook redirects: https://l.facebook.com/l.php?u=...
                if (str_contains($href, 'facebook.com/l.php') && preg_match('/[?&]u=([^&]+)/i', $href, $fm)) {
                    $href = urldecode($fm[1]);
                }

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

        // Search for patterns: "cena no 15 eiro", "ieejas maksa: 30", "ieejas maksa – 8 eur", "biļetes cena 5,- eur"
        if (preg_match('/(?:cena|maksa|biļet[a-z]*)[^\d\n\r]{1,30}(\d+)(?:[.,](?:\d{2}|-))?\s*(?:€|eur|eiro)?/ui', $t, $m)) {
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
    public function resolveLocation(string $cardVenue, string $detailVenue, string $title, string $description): array
    {
        $candidates = array_filter([$cardVenue, $detailVenue, $title, $description]);
        $combined = mb_strtolower(implode(' ', $candidates), 'UTF-8');

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

        // If cardVenue provided but not in map
        if (!empty($cardVenue)) {
            return [
                'venue_name' => $cardVenue,
                'city' => 'Dobele',
                'region' => 'Zemgale',
                'address' => $cardVenue . ', Dobeles novads',
                'latitude' => 56.6263,
                'longitude' => 23.2798,
                'place_type' => 'venue',
            ];
        }

        // Default Dobele
        return [
            'venue_name' => 'Dobele',
            'city' => 'Dobele',
            'region' => 'Zemgale',
            'address' => 'Dobele, Dobeles novads',
            'latitude' => 56.6263,
            'longitude' => 23.2798,
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
        if (preg_match('/\b(izrāde|izrādes|izrādē|luga|iestudējums|komēdija|teātris|teātra)\b/ui', $titleLower) || str_contains($combined, 'amatierteātr') || str_contains($combined, 'pils teātr')) {
            $canonical[] = 'Teātris';
            $entertainmentType = 'theatre';
        }

        // 3. Music / Concerts
        if (preg_match('/\b(koncerts|koncertā|koncertprogramma|koncertuzvedums|dziesm|koris|ansambl|džezs|muzicē|orķestr|pianists|dziedātāj|dziedātāja|špicberga|pujāts)\b/ui', $combined) || str_contains($combined, 'koncerts') || str_contains($titleLower, '100 debija')) {
            $canonical[] = 'Mūzika';
            $entertainmentType = $entertainmentType ?: 'concert';
        }

        // 4. Exhibitions
        if (str_contains($combined, 'izstāde') || str_contains($combined, 'izstādē') || str_contains($combined, 'glezn') || str_contains($combined, 'rokdarb') || str_contains($combined, 'ekspozīcij')) {
            $canonical[] = 'Izstādes';
            $entertainmentType = $entertainmentType ?: 'exhibition';
        }

        // 5. Sports & Outdoors
        if (preg_match('/\b(skrējiens|izskrien|maratons|turnīrs|čempionāts|mačs|spēle|pārgājiens|pārgājieni|pārgājienā|orientēšanās|nūjošan)\b/ui', $combined)) {
            $canonical[] = 'Sports';
            $entertainmentType = $entertainmentType ?: 'sports';
        }

        // 6. Workshops & Seminars
        if (preg_match('/\b(darbnīca|darbnīcas|darbnīcā|meistarklase|seminārs|semināru|nodarbība|apmācīb)\b/ui', $combined)) {
            $canonical[] = 'Semināri';
            $entertainmentType = $entertainmentType ?: 'workshop';
        }

        // 7. Celebrations & Festivals
        if (preg_match('/\b(svētki|svētkos|svētku|balle|festivāls|egles iedegšana|advente|adventes|ziemassvēt|ziemassvētkos|ziemassvētku|gadu mij[a-z]*)\b/ui', $combined)) {
            $canonical[] = 'Svētki';
            $entertainmentType = $entertainmentType ?: 'festival';
        }

        // 8. Kids / Family
        if (preg_match('/\b(bērniem|bērnu|ģimenēm|jauniešiem|leļļu|rūķu)\b/ui', $combined)) {
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
