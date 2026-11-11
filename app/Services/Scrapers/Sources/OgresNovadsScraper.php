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

class OgresNovadsScraper extends BaseScraper
{
    public const BASE_URL = 'https://www.ogresnovads.lv';
    public const CALENDAR_PATH = '/lv/notikumu-kalendars';

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
        'ogres novada kultūras centrs, izstāžu zāle' => [
            'name' => 'Ogres novada Kultūras centrs, Izstāžu zāle',
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
        'ogres mūzikas un mākslas skola' => [
            'name' => 'Ogres Mūzikas un mākslas skola',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Brīvības iela 50, Ogre, LV-5001',
            'place_type' => 'school',
        ],
        'omms izstāžu zāle' => [
            'name' => 'Ogres Mūzikas un mākslas skola, Izstāžu zāle',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Brīvības iela 50, Ogre, LV-5001',
            'place_type' => 'school',
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
        'tomes bibliotēka' => [
            'name' => 'Tomes bibliotēka',
            'city' => 'Tome',
            'region' => 'Vidzeme',
            'address' => 'Tome, Ogres novads, LV-5020',
            'place_type' => 'library',
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
        'mazozolu pagasta kultūras nams' => [
            'name' => 'Mazozolu pagasta Kultūras nams',
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
        return 'ogres-novads';
    }

    public function getName(): string
    {
        return 'Ogres novada pašvaldība';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $baseUrl = $source->url ?: (self::BASE_URL . self::CALENDAR_PATH);
        $maxPages = 10;
        $today = Carbon::today('Europe/Riga');

        for ($page = 0; $page < $maxPages; $page++) {
            $pageUrl = $page === 0 ? $baseUrl : $baseUrl . '?page=' . $page;
            $crawler = $this->fetchCrawler($pageUrl);

            if (!$crawler) {
                break;
            }

            $nodes = $crawler->filter('div.event');
            if ($nodes->count() === 0) {
                break;
            }

            $allPageEventsPast = true;

            foreach ($nodes as $domElement) {
                try {
                    $node = new Crawler($domElement, $baseUrl);

                    // 1. Check Categories for Exclusions
                    $categories = $node->filter('.event-categories a, .event-categories')->each(fn ($c) => trim($c->text()));
                    if ($this->shouldSkipCategories($categories)) {
                        continue;
                    }

                    // 2. Extract Title and Link
                    $titleNode = $node->filter('h2.event-title a, .event-title a, h2.event-title');
                    $title = $this->cleanText($titleNode->count() ? $titleNode->first()->text() : '');
                    if (empty($title)) {
                        continue;
                    }

                    // Exclude meeting titles (e.g. padomes sēde)
                    if ($this->isExcludedTitle($title)) {
                        continue;
                    }

                    $linkNode = $node->filter('h2.event-title a, a[href*="/notikums/"]');
                    $relLink = $linkNode->count() ? $linkNode->first()->attr('href') : '';
                    $cleanUrl = $this->normalizeUrl($relLink);

                    // 3. Extract Date and Time from card
                    $dateStr = $node->filter('span.date-date')->count() ? $this->cleanText($node->filter('span.date-date')->text()) : '';
                    $timeStr = $node->filter('span.date-time')->count() ? $this->cleanText($node->filter('span.date-time')->text()) : '';
                    [$startAt, $endAt, $allDay] = $this->parseLatvianDates($dateStr, $timeStr);

                    if (!$startAt) {
                        continue;
                    }

                    // 4. Extract Location and Image from card
                    $locationRaw = $node->filter('span.describing-field-value')->count() ? $this->cleanText($node->filter('span.describing-field-value')->text()) : '';
                    $thumbImg = $node->filter('div.event-image img')->count() ? $node->filter('div.event-image img')->attr('src') : null;
                    if ($thumbImg && !str_starts_with($thumbImg, 'http')) {
                        $thumbImg = self::BASE_URL . (str_starts_with($thumbImg, '/') ? '' : '/') . $thumbImg;
                    }

                    // Fetch detail page to check for accordion unpack or full description
                    $detailCrawler = $cleanUrl ? $this->fetchDetailCrawler($cleanUrl) : null;

                    // 5. Special Case: Accordion Exhibition Bundle Unpacking
                    if ($detailCrawler && $this->isAccordionExhibitionBundle($detailCrawler)) {
                        $unpacked = $this->unpackAccordionExhibitions($detailCrawler, $cleanUrl, $today);
                        foreach ($unpacked as $unpackedDto) {
                            $events->push($unpackedDto);
                            $allPageEventsPast = false;
                        }
                        continue;
                    }

                    // Standard Event Scope Rule: Current and upcoming only
                    if ($endAt) {
                        if ($endAt->lt($today)) {
                            continue;
                        }
                    } elseif ($startAt->lt($today)) {
                        continue;
                    }

                    $allPageEventsPast = false;

                    // Standard Event Ingestion
                    $fullDescription = $detailCrawler ? $this->extractDetailDescription($detailCrawler) : '';
                    if (empty($fullDescription)) {
                        $fullDescription = $node->filter('div.event-content')->count() ? $this->cleanText($node->filter('div.event-content')->text()) : $title;
                    }

                    $venueInfo = $this->resolveLocation($locationRaw, $title, $fullDescription);
                    $canonicalCats = $this->resolveCategories($categories, $title, $fullDescription);
                    $entertainmentType = $this->resolveEntertainmentType($canonicalCats, $title);
                    $priceData = $this->parsePrice($fullDescription);

                    $slugPart = $cleanUrl ? basename(parse_url($cleanUrl, PHP_URL_PATH)) : Str::slug($title);
                    $sourceExternalId = 'ogresnovads-' . $slugPart;

                    $dto = new ScrapedEventDTO(
                        title: $title,
                        startAt: $startAt,
                        endAt: $endAt,
                        description: $fullDescription,
                        shortDescription: Str::limit(strip_tags($fullDescription), 160),
                        venueName: $venueInfo['name'],
                        city: $venueInfo['city'],
                        region: 'Vidzeme',
                        address: $venueInfo['address'],
                        placeType: $venueInfo['place_type'],
                        categoryNames: $canonicalCats,
                        entertainmentType: $entertainmentType,
                        isFree: $priceData['is_free'],
                        priceMin: $priceData['price_min'],
                        priceMax: $priceData['price_max'],
                        currency: 'EUR',
                        ticketUrl: $cleanUrl ?: $baseUrl,
                        imageUrl: $thumbImg,
                        sourceUrl: $cleanUrl ?: $baseUrl,
                        sourceExternalId: $sourceExternalId,
                        locale: 'lv',
                        rawData: [
                            'location_raw' => $locationRaw,
                            'categories' => $categories,
                            'all_day' => $allDay,
                        ]
                    );

                    $events->push($dto);
                } catch (\Throwable $e) {
                    Log::warning("OgresNovadsScraper parse error: " . $e->getMessage());
                }
            }

            if ($allPageEventsPast) {
                // If every event on this page is in the past, no need to crawl older pages
                break;
            }
        }

        return $events;
    }

    /**
     * Check if categories should be excluded (Sanāksme, Veselības veicināšana)
     */
    public function shouldSkipCategories(array $categories): bool
    {
        foreach ($categories as $cat) {
            $catLower = mb_strtolower($cat, 'UTF-8');
            if (
                str_contains($catLower, 'sanāksm') ||
                str_contains($catLower, 'sanaksme') ||
                str_contains($catLower, 'veselīb') ||
                str_contains($catLower, 'veselib')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if title indicates meeting or administrative session
     */
    public function isExcludedTitle(string $title): bool
    {
        $lower = mb_strtolower($title, 'UTF-8');
        return str_contains($lower, 'padomes sēde') ||
               str_contains($lower, 'iedzīvotāju padome') ||
               str_contains($lower, 'komitejas sēde') ||
               str_contains($lower, 'domes sēde');
    }

    /**
     * Check if detail page is an accordion exhibition bundle
     */
    public function isAccordionExhibitionBundle(Crawler $crawler): bool
    {
        $cards = $crawler->filter('.accordion');
        return $cards->count() >= 2;
    }

    /**
     * Unpack accordion exhibition cards into individual ScrapedEventDTOs
     */
    public function unpackAccordionExhibitions(Crawler $crawler, string $pageUrl, Carbon $today): array
    {
        $dtos = [];
        $cards = $crawler->filter('.accordion');

        $months = [
            'janvār' => 1, 'februār' => 2, 'mart' => 3, 'aprīl' => 4,
            'maij' => 5, 'jūnij' => 6, 'jūlij' => 7, 'august' => 8,
            'septembr' => 9, 'oktobr' => 10, 'novembr' => 11, 'decembr' => 12,
        ];

        $cards->each(function (Crawler $card, $i) use (&$dtos, $today, $months, $pageUrl) {
            $btnNode = $card->filter('.card-header button.btn, .accordion__accordion-title, .card-header');
            $rawTitle = $btnNode->count() ? trim($btnNode->text()) : '';
            if (empty($rawTitle)) {
                return;
            }

            $bodyNode = $card->filter('.card-body');
            $desc = $bodyNode->count() ? $this->cleanText($bodyNode->html()) : '';
            if (empty($desc)) {
                $desc = $rawTitle;
            }

            $anchorId = $card->filter('.card-header')->count() ? $card->filter('.card-header')->attr('id') : "exhibition-{$i}";
            $cardImg = $card->filter('img')->count() ? $card->filter('img')->attr('src') : null;
            if ($cardImg && !str_starts_with($cardImg, 'http')) {
                $cardImg = self::BASE_URL . (str_starts_with($cardImg, '/') ? '' : '/') . $cardImg;
            }

            $search = mb_strtolower($rawTitle . ' ' . $desc, 'UTF-8');

            // Parse end date e.g. "līdz 27. decembrim", "līdz 9. janvārim"
            $endAt = null;
            if (preg_match('/līdz\s+(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)/iu', $search, $em)) {
                $day = (int) $em[1];
                $mStr = mb_strtolower($em[2], 'UTF-8');
                foreach ($months as $k => $moNum) {
                    if (str_starts_with($mStr, $k)) {
                        $currentYear = (int) $today->year;
                        $year = ($moNum < (int) $today->month) ? ($currentYear + 1) : $currentYear;
                        $endAt = Carbon::create($year, $moNum, $day, 19, 0, 0, 'Europe/Riga');
                        break;
                    }
                }
            }

            // Scope Rule: If exhibition already ended in the past, skip it!
            if ($endAt && $endAt->lt($today)) {
                return;
            }

            // Parse start date e.g. "no 17. līdz 30. novembrim" or "no 1. oktobra"
            $startAt = $today->copy()->setTime(10, 0);
            if (preg_match('/no\s+(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)/iu', $search, $sm)) {
                $sDay = (int) $sm[1];
                $sMonth = mb_strtolower($sm[2], 'UTF-8');
                foreach ($months as $k => $moNum) {
                    if (str_starts_with($sMonth, $k)) {
                        $startYear = (int) $today->year;
                        $startAt = Carbon::create($startYear, $moNum, $sDay, 10, 0, 0, 'Europe/Riga');
                        break;
                    }
                }
            }

            // Resolve Venue and City for this specific exhibition
            $venueInfo = $this->resolveLocation('', $rawTitle, $desc);
            $cardUrl = $pageUrl . '#' . $anchorId;
            $cardExternalId = 'ogresnovads-izstade-' . ($anchorId ?: substr(md5($rawTitle), 0, 8));

            $priceData = $this->parsePrice($desc);

            $dtos[] = new ScrapedEventDTO(
                title: $rawTitle,
                startAt: $startAt,
                endAt: $endAt,
                description: $desc,
                shortDescription: Str::limit(strip_tags($desc), 160),
                venueName: $venueInfo['name'],
                city: $venueInfo['city'],
                region: 'Vidzeme',
                address: $venueInfo['address'],
                placeType: $venueInfo['place_type'],
                categoryNames: ['Māksla un izstādes'],
                entertainmentType: 'exhibition',
                isFree: $priceData['is_free'],
                priceMin: $priceData['price_min'],
                priceMax: $priceData['price_max'],
                currency: 'EUR',
                ticketUrl: $cardUrl,
                imageUrl: $cardImg,
                sourceUrl: $cardUrl,
                sourceExternalId: $cardExternalId,
                locale: 'lv',
                rawData: [
                    'accordion_anchor' => $anchorId,
                    'parent_url' => $pageUrl,
                ]
            );
        });

        return $dtos;
    }

    /**
     * Resolve Venue and City from text
     */
    public function resolveLocation(string $locationRaw, string $title = '', string $description = ''): array
    {
        $text = mb_strtolower($locationRaw . ' ' . $title . ' ' . $description, 'UTF-8');

        // Stem matching for known Ogres novada venues
        $stemVenueMap = [
            'lielajā zāl' => 'ogres novada kultūras centrs, lielā zāle',
            'lielā zāl' => 'ogres novada kultūras centrs, lielā zāle',
            'radošajā telp' => 'ogres novada kultūras centrs, radošā telpa',
            'radošā telp' => 'ogres novada kultūras centrs, radošā telpa',
            'izstāžu zāl' => 'ogres novada kultūras centrs, izstāžu zāle',
            'izstazu zal' => 'ogres novada kultūras centrs, izstāžu zāle',
            'ogres vēstures un mākslas muzej' => 'ogres vēstures un mākslas muzejs',
            'ogres muzej' => 'ogres vēstures un mākslas muzejs',
            'ogres centrālajā bibliotēk' => 'ogres centrālā bibliotēka',
            'ogres bibliotēk' => 'ogres centrālā bibliotēka',
            'omms izstāžu zāl' => 'omms izstāžu zāle',
            'mūzikas un mākslas skol' => 'ogres mūzikas un mākslas skola',
            'omms' => 'ogres mūzikas un mākslas skola',
            'sporta arēnā ogre' => 'sporta arēna ogre',
            'sporta arēna ogre' => 'sporta arēna ogre',
            'arēnā ogre' => 'sporta arēna ogre',
            'ogres stadion' => 'ogres stadions',
            'ogres teātr' => 'ogres teātris',
            'ikšķiles bibliotēk' => 'ikšķiles bibliotēka',
            'ikšķiles tautas nam' => 'ikšķiles tautas nams',
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
            'tomes bibliotēk' => 'tomes bibliotēka',
            'madlienas kultūras nam' => 'madlienas kultūras nams',
            'rembates tautas nam' => 'rembates tautas nams',
            'birzgales tautas nam' => 'birzgales tautas nams',
            'taurupes tautas nam' => 'taurupes tautas nams',
            'krapes tautas nam' => 'krapes tautas nams',
            'mazozolu pagasta kultūras nam' => 'mazozolu pagasta kultūras nams',
            'mazozolu tautas nam' => 'mazozolu pagasta kultūras nams',
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

        // City/Parish detection
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
            'ogresgal' => 'Ogresgals',
            'ogrē' => 'Ogre',
            'ogre' => 'Ogre',
        ];

        // Prioritize locationRaw and title to avoid street names or notices in description hijacking the city
        $primaryText = mb_strtolower(trim($locationRaw . ' ' . $title), 'UTF-8');
        foreach ($parishMap as $needle => $cName) {
            if (str_contains($primaryText, $needle)) {
                $city = $cName;
                break;
            }
        }

        if ($city === 'Ogre' && !str_contains($primaryText, 'ogr')) {
            foreach ($parishMap as $needle => $cName) {
                if (str_contains($text, $needle)) {
                    $city = $cName;
                    break;
                }
            }
        }

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
            'Mazozoli' => 'Mazozolu pagasta Kultūras nams',
            'Lēdmane' => 'Lēdmanes Tautas nams',
            default => 'Ogres novada Kultūras centrs',
        };

        return [
            'name' => $venue,
            'city' => $city,
            'region' => 'Vidzeme',
            'address' => "{$city}, Ogres novads",
            'place_type' => 'cultural_center',
        ];
    }

    /**
     * Resolve Categories adhering strictly to AGENTS.md
     */
    public function resolveCategories(array $rawCategories, string $title, string $description = ''): array
    {
        $text = mb_strtolower(implode(' ', $rawCategories) . ' ' . $title . ' ' . $description, 'UTF-8');

        // STRICT RULE 1: Cinema / Kino (Priority)
        if (
            str_contains($text, 'filma') ||
            str_contains($text, 'filmas') ||
            str_contains($text, 'filmu') ||
            str_contains($text, 'filmā') ||
            str_contains($text, 'kinoseanss') ||
            str_contains($text, 'kinoseans') ||
            str_contains($text, 'spēlfilma') ||
            str_contains($text, 'spelfilma') ||
            str_contains($text, 'dokumentālā filma') ||
            str_contains($text, 'dokumentala filma') ||
            str_contains($text, 'animācijas filma') ||
            str_contains($text, 'īsfilma') ||
            str_contains($text, 'isfilma') ||
            str_contains($text, 'kinoteātr') ||
            in_array('Kino', $rawCategories, true)
        ) {
            return ['Kino'];
        }

        // STRICT RULE 2: Theaters
        if (
            (
                str_contains($text, 'teātra izrāde') ||
                str_contains($text, 'teātris') ||
                str_contains($text, 'izrāde') ||
                str_contains($text, 'iestudējums') ||
                str_contains($text, 'luga') ||
                in_array('Teātris', $rawCategories, true)
            ) && !str_contains($text, 'filma')
        ) {
            return ['Teātris'];
        }

        // Music
        if (
            str_contains($text, 'koncerts') ||
            str_contains($text, 'dziesma') ||
            str_contains($text, 'dzied') ||
            str_contains($text, 'koris') ||
            str_contains($text, 'orķestr') ||
            str_contains($text, 'mūzika') ||
            str_contains($text, 'balle') ||
            in_array('Koncerts', $rawCategories, true) ||
            in_array('Balle', $rawCategories, true)
        ) {
            return ['Mūzika'];
        }

        // Festivals / Holidays
        if (
            str_contains($text, 'valsts svētk') ||
            str_contains($text, 'lāčplēš') ||
            str_contains($text, 'lāpu gājiens') ||
            str_contains($text, '18. novembr') ||
            str_contains($text, 'svētki') ||
            str_contains($text, 'svinīgais pasākums') ||
            in_array('Valsts svētki', $rawCategories, true) ||
            in_array('Lāčplēša diena', $rawCategories, true) ||
            in_array('Lāpu gājiens', $rawCategories, true)
        ) {
            return ['Festivāli & Svētki'];
        }

        // Exhibitions / Art
        if (
            str_contains($text, 'izstāde') ||
            str_contains($text, 'izstade') ||
            str_contains($text, 'meistarklase') ||
            str_contains($text, 'glezn') ||
            str_contains($text, 'māksla') ||
            in_array('Izstāde', $rawCategories, true) ||
            in_array('Meistarklase', $rawCategories, true)
        ) {
            return ['Māksla un izstādes'];
        }

        // Children / Family
        if (
            str_contains($text, 'bērniem') ||
            str_contains($text, 'ģimenei') ||
            str_contains($text, 'pasaka') ||
            in_array('Bērniem', $rawCategories, true)
        ) {
            return ['Bērniem'];
        }

        // Sports
        if (
            str_contains($text, 'sports') ||
            str_contains($text, 'turnīrs') ||
            str_contains($text, 'skrējiens') ||
            in_array('Sports', $rawCategories, true)
        ) {
            return ['Sports'];
        }

        // STRICT RULE 3: Fallback to Cits
        return ['Cits'];
    }

    /**
     * Resolve entertainment type
     */
    public function resolveEntertainmentType(array $categories, string $title): string
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
     * Parse price and free status
     */
    public function parsePrice(string $content): array
    {
        $isFree = true; // Most municipal events are free unless fee specified
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
            $isFree = false;
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
     * Parse Latvian dates into Carbon instances
     */
    public function parseLatvianDates(string $dateStr, string $timeStr = ''): array
    {
        $months = [
            'janvāris' => 1, 'janvārī' => 1, 'janvāra' => 1,
            'februāris' => 2, 'februārī' => 2, 'februāra' => 2,
            'marts' => 3, 'martā' => 3, 'marta' => 3,
            'aprīlis' => 4, 'aprīlī' => 4, 'aprīļa' => 4,
            'maijs' => 5, 'maijā' => 5, 'maija' => 5,
            'jūnijs' => 6, 'jūnijā' => 6, 'jūnija' => 6,
            'jūlijs' => 7, 'jūlijā' => 7, 'jūlija' => 7,
            'augusts' => 8, 'augustā' => 8, 'augusta' => 8,
            'septembris' => 9, 'septembrī' => 9, 'septembra' => 9,
            'oktobris' => 10, 'oktobrī' => 10, 'oktobra' => 10,
            'novembris' => 11, 'novembrī' => 11, 'novembra' => 11,
            'decembris' => 12, 'decembrī' => 12, 'decembra' => 12,
        ];

        $clean = trim(str_replace(['&#8211;', '&ndash;', '–', '—'], '-', $dateStr));
        $parts = explode('-', $clean);

        $parseSingle = function (string $p, ?int $fallbackYear = null, ?int $fallbackMonth = null) use ($months) {
            $p = trim($p);

            // Format: "11. novembris, 2026" or "11. novembrī"
            if (preg_match('/(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:[,\s]+(\d{4}))?/iu', $p, $m)) {
                $day = (int) $m[1];
                $monthName = mb_strtolower(trim($m[2]));
                $month = $months[$monthName] ?? ($fallbackMonth ?? (int) date('n'));
                $year = !empty($m[3]) ? (int) $m[3] : ($fallbackYear ?? (int) date('Y'));
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }

            // Format: dd.mm.yyyy
            if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $p, $m)) {
                return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
            }

            return null;
        };

        $isAllDay = false;
        $startTime = '10:00';
        $endTime = null;

        $timeClean = mb_strtolower(trim($timeStr), 'UTF-8');
        if (str_contains($timeClean, 'visu dienu')) {
            $isAllDay = true;
            $startTime = '09:00';
        } elseif (preg_match('/(\d{1,2})[\.:](\d{2})\s*[-–]\s*(\d{1,2})[\.:](\d{2})/u', $timeClean, $tm)) {
            $startTime = sprintf('%02d:%02d', (int) $tm[1], (int) $tm[2]);
            $endTime = sprintf('%02d:%02d', (int) $tm[3], (int) $tm[4]);
        } elseif (preg_match('/(\d{1,2})[\.:](\d{2})/u', $timeClean, $tm)) {
            $startTime = sprintf('%02d:%02d', (int) $tm[1], (int) $tm[2]);
        }

        if (count($parts) === 2) {
            $endParsed = $parseSingle($parts[1]);
            $fallbackYear = $endParsed ? (int) substr($endParsed, 0, 4) : null;
            $fallbackMonth = $endParsed ? (int) substr($endParsed, 5, 2) : null;
            $startParsed = $parseSingle($parts[0], $fallbackYear, $fallbackMonth);

            if ($startParsed && $endParsed) {
                $startAt = Carbon::createFromFormat('Y-m-d H:i', "{$startParsed} {$startTime}", 'Europe/Riga');
                $endAtTime = $endTime ?: ($isAllDay ? '21:00' : '19:00');
                $endAt = Carbon::createFromFormat('Y-m-d H:i', "{$endParsed} {$endAtTime}", 'Europe/Riga');
                return [$startAt, $endAt, $isAllDay];
            }
        }

        $singleParsed = $parseSingle($parts[0]);
        if ($singleParsed) {
            $startAt = Carbon::createFromFormat('Y-m-d H:i', "{$singleParsed} {$startTime}", 'Europe/Riga');
            $endAt = $endTime ? Carbon::createFromFormat('Y-m-d H:i', "{$singleParsed} {$endTime}", 'Europe/Riga') : null;
            return [$startAt, $endAt, $isAllDay];
        }

        return [null, null, false];
    }

    /**
     * Normalize relative or absolute URL
     */
    protected function normalizeUrl(?string $relUrl): ?string
    {
        if (empty($relUrl)) {
            return null;
        }

        if (str_starts_with($relUrl, 'http://') || str_starts_with($relUrl, 'https://')) {
            $parsed = parse_url($relUrl);
            return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'www.ogresnovads.lv') . ($parsed['path'] ?? '');
        }

        $path = strtok($relUrl, '?');
        return self::BASE_URL . (str_starts_with($path, '/') ? '' : '/') . $path;
    }

    /**
     * Fetch detail page crawler with local memory cache
     */
    public function fetchDetailCrawler(string $url): ?Crawler
    {
        if (isset($this->detailCache[$url])) {
            return new Crawler($this->detailCache[$url], $url);
        }

        try {
            $response = $this->httpClient->get($url, ['timeout' => 10]);
            $html = (string) $response->getBody();
            $this->detailCache[$url] = $html;
            return new Crawler($html, $url);
        } catch (\Throwable $e) {
            Log::warning("OgresNovadsScraper detail fetch error for {$url}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract detail description from Crawler
     */
    protected function extractDetailDescription(Crawler $crawler): ?string
    {
        $node = $crawler->filter('.cke_editable, .text__text-content, .event-content');
        if ($node->count()) {
            return $this->cleanText($node->first()->html());
        }

        return null;
    }
}

