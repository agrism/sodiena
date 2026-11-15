<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class CesisScraper extends BaseScraper
{
    public const BASE_URL = 'https://pasakumi.cesis.lv';

    /**
     * Known venue mappings across Cēsu novads.
     * Maps location names / keywords from pasakumi.cesis.lv to normalized metadata.
     */
    protected array $venueMap = [
        'koncertzale-cesis' => [
            'match' => ['koncertzāle "cēsis"', 'koncertzāle cēsis', 'koncertzālē cēsis', 'koncertzāle'],
            'name' => 'Koncertzāle "Cēsis"',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Raunas iela 12, Cēsis, LV-4101',
            'place_type' => 'concert_hall',
            'latitude' => 57.3134,
            'longitude' => 25.2750,
        ],
        'cesu-izstazu-nams' => [
            'match' => ['cēsu izstāžu nams', 'izstāžu nams'],
            'name' => 'Cēsu Izstāžu nams',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Pils laukums 3, Cēsis, LV-4101',
            'place_type' => 'gallery',
            'latitude' => 57.3129,
            'longitude' => 25.2712,
        ],
        'cesu-pils' => [
            'match' => ['cēsu pils', 'cēsu jaunā pils', 'cēsu muzejs', 'jaunā pils'],
            'name' => 'Cēsu pils',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Pils laukums 9, Cēsis, LV-4101',
            'place_type' => 'castle',
            'latitude' => 57.3131,
            'longitude' => 25.2715,
        ],
        'cesu-biblioteka' => [
            'match' => ['cēsu centrālā bibliotēka', 'cēsu bibliotēka'],
            'name' => 'Cēsu Centrālā bibliotēka',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Raunas iela 1, Cēsis, LV-4101',
            'place_type' => 'library',
            'latitude' => 57.3128,
            'longitude' => 25.2738,
        ],
        'vienibas-laukums' => [
            'match' => ['vienības laukums'],
            'name' => 'Vienības laukums',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Vienības laukums, Cēsis, LV-4101',
            'place_type' => 'square',
            'latitude' => 57.3130,
            'longitude' => 25.2740,
        ],
        'maksla-mala' => [
            'match' => ['mākslas telpa mala', 'mala'],
            'name' => 'Mākslas telpa Mala',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Lielā Skolas iela 4, Cēsis, LV-4101',
            'place_type' => 'cultural_center',
            'latitude' => 57.3117,
            'longitude' => 25.2709,
        ],
        'dimension-ink' => [
            'match' => ['dimension ink'],
            'name' => 'Dimension Ink',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Rīgas iela 16, Cēsis, LV-4101',
            'place_type' => 'gallery',
            'latitude' => 57.3119,
            'longitude' => 25.2743,
        ],
        'zeit' => [
            'match' => ['zeit', 'radošais kvartāls "zeit"'],
            'name' => 'Radošais kvartāls "Zeit"',
            'city' => 'Līgatne',
            'region' => 'Vidzeme',
            'address' => 'Gaujas iela 4, Līgatne, LV-4110',
            'place_type' => 'cultural_center',
            'latitude' => 57.2348,
            'longitude' => 25.0415,
        ],
        'augsligatnes-kn' => [
            'match' => ['augšlīgatnes kultūras nams', 'augšlīgatne'],
            'name' => 'Augšlīgatnes Kultūras nams',
            'city' => 'Augšlīgatne',
            'region' => 'Vidzeme',
            'address' => 'Nītaures iela 8, Augšlīgatne, Līgatnes pagasts, Cēsu novads, LV-4108',
            'place_type' => 'cultural_center',
            'latitude' => 57.1950,
            'longitude' => 25.0350,
        ],
        'ligatnes-amatu-maja' => [
            'match' => ['līgatnes amatu māja', 'līgatnes pilsētas bibliotēka'],
            'name' => 'Līgatnes Amatu māja',
            'city' => 'Līgatne',
            'region' => 'Vidzeme',
            'address' => 'Pilsoņu iela 4, Līgatne, LV-4110',
            'place_type' => 'cultural_center',
            'latitude' => 57.2335,
            'longitude' => 25.0405,
        ],
        'ligatnes-kn' => [
            'match' => ['līgatnes kultūras nams', 'līgatne'],
            'name' => 'Līgatnes Kultūras nams',
            'city' => 'Līgatne',
            'region' => 'Vidzeme',
            'address' => 'Spriņģu iela 4, Līgatne, LV-4110',
            'place_type' => 'cultural_center',
            'latitude' => 57.2343,
            'longitude' => 25.0401,
        ],
        'liepas-kn' => [
            'match' => ['liepas kultūras nams', 'liepa'],
            'name' => 'Liepas Kultūras nams',
            'city' => 'Liepa',
            'region' => 'Vidzeme',
            'address' => 'Rūpnīcas iela 14, Liepa, Liepas pagasts, Cēsu novads, LV-4128',
            'place_type' => 'cultural_center',
            'latitude' => 57.3828,
            'longitude' => 25.4300,
        ],
        'priekulu-kn' => [
            'match' => ['priekuļu kultūras nams', 'priekuļu pagasta bibliotēka', 'priekuļi'],
            'name' => 'Priekuļu Kultūras nams',
            'city' => 'Priekuļi',
            'region' => 'Vidzeme',
            'address' => 'Cēsu prospekts 1, Priekuļi, Priekuļu pagasts, Cēsu novads, LV-4126',
            'place_type' => 'cultural_center',
            'latitude' => 57.3142,
            'longitude' => 25.3580,
        ],
        'straupes-zirgu-pasts' => [
            'match' => ['straupes zirgu pasts'],
            'name' => 'Straupes zirgu pasts',
            'city' => 'Straupe',
            'region' => 'Vidzeme',
            'address' => 'Zirgu pasts, Straupe, Straupes pagasts, Cēsu novads, LV-4152',
            'place_type' => 'landmark',
            'latitude' => 57.3481,
            'longitude' => 24.9492,
        ],
        'lielstraupes-pils' => [
            'match' => ['lielstraupes pils'],
            'name' => 'Lielstraupes pils',
            'city' => 'Straupe',
            'region' => 'Vidzeme',
            'address' => 'Straupe, Straupes pagasts, Cēsu novads, LV-4152',
            'place_type' => 'castle',
            'latitude' => 57.3485,
            'longitude' => 24.9480,
        ],
        'mazstraupes-pils' => [
            'match' => ['mazstraupes pils'],
            'name' => 'Mazstraupes pils',
            'city' => 'Straupe',
            'region' => 'Vidzeme',
            'address' => 'Straupe, Straupes pagasts, Cēsu novads, LV-4152',
            'place_type' => 'castle',
            'latitude' => 57.3485,
            'longitude' => 24.9480,
        ],
        'straupes-tn' => [
            'match' => ['straupes pagasta tautas nams', 'straupe'],
            'name' => 'Straupes pagasta Tautas nams',
            'city' => 'Straupe',
            'region' => 'Vidzeme',
            'address' => 'Straupe, Straupes pagasts, Cēsu novads, LV-4152',
            'place_type' => 'cultural_center',
            'latitude' => 57.3481,
            'longitude' => 24.9492,
        ],
        'stalbes-tn' => [
            'match' => ['stalbes tautas nams', 'stalbe'],
            'name' => 'Stalbes Tautas nams',
            'city' => 'Stalbe',
            'region' => 'Vidzeme',
            'address' => 'Stalbe, Stalbes pagasts, Cēsu novads, LV-4151',
            'place_type' => 'cultural_center',
            'latitude' => 57.3712,
            'longitude' => 25.0315,
        ],
        'jaunpiebalgas-kc' => [
            'match' => ['jaunpiebalgas kultūras centrs', "izstāžu zāle ''velves''", 'jaunpiebalgas pagasta bibliotēka', 'jaunpiebalgas apvienības pārvaldes zāle', 'jaunpiebalga'],
            'name' => 'Jaunpiebalgas Kultūras centrs',
            'city' => 'Jaunpiebalga',
            'region' => 'Vidzeme',
            'address' => 'Gaujas iela 4, Jaunpiebalga, Jaunpiebalgas pagasts, Cēsu novads, LV-4125',
            'place_type' => 'cultural_center',
            'latitude' => 57.1783,
            'longitude' => 26.0417,
        ],
        'vecpiebalgas-kn' => [
            'match' => ['vecpiebalgas kultūras nams', 'vecpiebalgas viesistaba', 'vecpiebalgas pamatskola', 'zem ķenča cepures', 'vecpiebalga'],
            'name' => 'Vecpiebalgas Kultūras nams',
            'city' => 'Vecpiebalga',
            'region' => 'Vidzeme',
            'address' => 'Gaismas iela 1, Vecpiebalga, Vecpiebalgas pagasts, Cēsu novads, LV-4122',
            'place_type' => 'cultural_center',
            'latitude' => 57.0609,
            'longitude' => 25.8162,
        ],
        'dzerbenes-pils' => [
            'match' => ['dzērbenes pils', 'dzērbene'],
            'name' => 'Dzērbenes pils',
            'city' => 'Dzērbene',
            'region' => 'Vidzeme',
            'address' => 'Dzērbene, Dzērbenes pagasts, Cēsu novads, LV-4118',
            'place_type' => 'castle',
            'latitude' => 57.1878,
            'longitude' => 25.6806,
        ],
        'nekena-muiza' => [
            'match' => ['nēķena muiža', 'taurenes kultūras nams', 'taurene'],
            'name' => 'Nēķena muiža',
            'city' => 'Taurene',
            'region' => 'Vidzeme',
            'address' => 'Nēķins, Taurene, Taurenes pagasts, Cēsu novads, LV-4119',
            'place_type' => 'manor',
            'latitude' => 57.1648,
            'longitude' => 25.6883,
        ],
        'veselavas-muiza' => [
            'match' => ['veselavas muiža', 'veselava'],
            'name' => 'Veselavas muiža',
            'city' => 'Veselava',
            'region' => 'Vidzeme',
            'address' => 'Veselava, Veselavas pagasts, Cēsu novads, LV-4116',
            'place_type' => 'manor',
            'latitude' => 57.2917,
            'longitude' => 25.4764,
        ],
        'vaives-tn' => [
            'match' => ['vaives tautas nams', 'kaķukrogs', 'vaive'],
            'name' => 'Vaives Tautas nams "Kaķukrogs"',
            'city' => 'Vaive',
            'region' => 'Vidzeme',
            'address' => 'Kaķukrogs, Rīdzenes ciems, Vaives pagasts, Cēsu novads, LV-4136',
            'place_type' => 'cultural_center',
            'latitude' => 57.2625,
            'longitude' => 25.3214,
        ],
        'kaives-tn' => [
            'match' => ['kaives tautas nams', 'kaive'],
            'name' => 'Kaives Tautas nams',
            'city' => 'Kaive',
            'region' => 'Vidzeme',
            'address' => 'Kaive, Kaives pagasts, Cēsu novads, LV-4144',
            'place_type' => 'cultural_center',
            'latitude' => 57.0400,
            'longitude' => 25.6450,
        ],
        'amatas-kc' => [
            'match' => ['amatas kultūras centrs', 'amata'],
            'name' => 'Amatas Kultūras centrs',
            'city' => 'Ģikši',
            'region' => 'Vidzeme',
            'address' => 'Ģikši, Amatas pagasts, Cēsu novads, LV-4141',
            'place_type' => 'cultural_center',
            'latitude' => 57.2033,
            'longitude' => 25.3133,
        ],
        'ieriku-stacija' => [
            'match' => ['ieriķu dzelzceļa stacija', 'ieriķi'],
            'name' => 'Ieriķu dzelzceļa stacija',
            'city' => 'Ieriķi',
            'region' => 'Vidzeme',
            'address' => 'Ieriķi, Drabešu pagasts, Cēsu novads, LV-4142',
            'place_type' => 'landmark',
            'latitude' => 57.2183,
            'longitude' => 25.1700,
        ],
        'zosenu-kn' => [
            'match' => ['zosēnu kultūras nams', 'zosēni'],
            'name' => 'Zosēnu Kultūras nams',
            'city' => 'Melnbārži',
            'region' => 'Vidzeme',
            'address' => 'Melnbārži, Zosēnu pagasts, Cēsu novads, LV-4123',
            'place_type' => 'cultural_center',
            'latitude' => 57.1350,
            'longitude' => 25.9250,
        ],
        'skujenes-tn' => [
            'match' => ['skujenes tautas nams', 'skujene'],
            'name' => 'Skujenes Tautas nams',
            'city' => 'Skujene',
            'region' => 'Vidzeme',
            'address' => 'Skujene, Skujenes pagasts, Cēsu novads, LV-4143',
            'place_type' => 'cultural_center',
            'latitude' => 57.0944,
            'longitude' => 25.4736,
        ],
        'raiskuma-tn' => [
            'match' => ['raiskuma pagasta tautas nams', 'auciemmuiža', 'auciems', 'raiskums'],
            'name' => 'Raiskuma pagasta Tautas nams "Auciemmuiža"',
            'city' => 'Auciems',
            'region' => 'Vidzeme',
            'address' => 'Auciems, Raiskuma pagasts, Cēsu novads, LV-4146',
            'place_type' => 'cultural_center',
            'latitude' => 57.3611,
            'longitude' => 25.1056,
        ],
        'nitaure' => [
            'match' => ['nītaure'],
            'name' => 'Nītaure',
            'city' => 'Nītaure',
            'region' => 'Vidzeme',
            'address' => 'Nītaure, Nītaures pagasts, Cēsu novads, LV-4112',
            'place_type' => 'venue',
            'latitude' => 57.0717,
            'longitude' => 25.1950,
        ],
        'marsnenu-bibl' => [
            'match' => ['mārsnēnu bibliotēka', 'mārsnēni'],
            'name' => 'Mārsnēnu bibliotēka',
            'city' => 'Mārsnēni',
            'region' => 'Vidzeme',
            'address' => 'Mārsnēni, Mārsnēnu pagasts, Cēsu novads, LV-4129',
            'place_type' => 'library',
            'latitude' => 57.4083,
            'longitude' => 25.5083,
        ],
        'ruckas-muiza' => [
            'match' => ['ruckas muiža'],
            'name' => 'Ruckas muiža',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Piebalgas iela 19, Cēsis, LV-4101',
            'place_type' => 'manor',
            'latitude' => 57.3060,
            'longitude' => 25.2790,
        ],
        'plmc' => [
            'match' => ['pasaules latviešu mākslas centrs'],
            'name' => 'Pasaules latviešu mākslas centrs',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Lielā Skolas iela 6, Cēsis, LV-4101',
            'place_type' => 'gallery',
            'latitude' => 57.3115,
            'longitude' => 25.2707,
        ],
        'dukuri' => [
            'match' => ['mākslas rančo "dukuri"', 'dukuri'],
            'name' => 'Mākslas rančo "Dukuri"',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Dukuri, Cēsu novads, LV-4101',
            'place_type' => 'venue',
            'latitude' => 25.2500,
            'longitude' => 57.2950,
        ],
        'museus' => [
            'match' => ['izstāžu zāle "museus"', 'museus'],
            'name' => 'Izstāžu zāle "MUSEUS"',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Rīgas iela 24, Cēsis, LV-4101',
            'place_type' => 'gallery',
            'latitude' => 57.3125,
            'longitude' => 25.2730,
        ],
        'muiza' => [
            'match' => ['picērija "muiža"', 'picērija muiža'],
            'name' => 'Picērija "Muiža"',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Cēsu iela 11, Drabešu pagasts, LV-4142',
            'place_type' => 'restaurant',
            'latitude' => 57.2800,
            'longitude' => 25.2600,
        ],
        'vanadzins' => [
            'match' => ['h.e. vanadziņš', 'vanadziņš'],
            'name' => 'H.E. Vanadziņš',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Rīgas iela 15, Cēsis, LV-4101',
            'place_type' => 'restaurant',
            'latitude' => 57.3122,
            'longitude' => 25.2735,
        ],
        'harmonija' => [
            'match' => ['kultūras biedrība "harmonija"', 'harmonija'],
            'name' => 'Kultūras biedrība "Harmonija"',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Rīgas iela 24, Cēsis, LV-4101',
            'place_type' => 'cultural_center',
            'latitude' => 57.3125,
            'longitude' => 25.2730,
        ],
        'cesu-novads' => [
            'match' => ['cēsu novads', 'cēsis', 'cēsīs'],
            'name' => 'Cēsis',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Cēsis, LV-4101',
            'place_type' => 'square',
            'latitude' => 57.3130,
            'longitude' => 25.2740,
        ],
    ];

    public function getSlug(): string
    {
        return 'cesis';
    }

    public function getName(): string
    {
        return 'Pasākumi Cēsīs';
    }

    /**
     * Scrape events from pasakumi.cesis.lv.
     * All current and upcoming events are pre-rendered in HTML in the main listing table.
     *
     * @return Collection<int, ScrapedEventDTO>
     */
    public function scrape(Source $source): Collection
    {
        $events = collect();
        $today = Carbon::today('Europe/Riga');

        $crawler = $this->fetchCrawler(self::BASE_URL);
        if (!$crawler) {
            Log::error('CesisScraper: Failed to fetch ' . self::BASE_URL);
            return $events;
        }

        // 1. Build map of carousel items for poster images and short descriptions
        $carouselMap = [];
        $crawler->filter('.owl-carousel .event-thumbnail')->each(function (Crawler $item) use (&$carouselMap) {
            $link = $item->filter('a');
            if ($link->count() === 0) {
                return;
            }

            $href = trim($link->attr('href') ?? '');
            $poster = $item->filter('.event-poster');
            $style = $poster->count() ? ($poster->attr('style') ?? '') : '';
            $imgUrl = null;
            if (preg_match('/url\([\'"]?([^"\'\)]+)[\'"]?\)/i', $style, $m)) {
                $imgUrl = $m[1];
            }

            $descNode = $item->filter('p');
            $desc = $descNode->count() ? trim($descNode->text('')) : null;

            if ($href) {
                $carouselMap[$href] = [
                    'image' => $imgUrl,
                    'description' => $desc,
                ];
            }
        });

        // 2. Iterate over all event rows in .filter-results-container
        $crawler->filter('div.results-event')->each(function (Crawler $row) use (&$events, $today, $carouselMap) {
            try {
                $titleNode = $row->filter('a.title');
                if ($titleNode->count() === 0) {
                    return;
                }

                $rawTitle = trim($titleNode->text(''));
                if (empty($rawTitle)) {
                    return;
                }

                $sourceUrl = trim($titleNode->attr('href') ?? '');
                if (empty($sourceUrl)) {
                    return;
                }

                // External ID from slug
                $slug = basename(rtrim(parse_url($sourceUrl, PHP_URL_PATH) ?? '', '/'));
                $externalId = !empty($slug) ? 'cesis-' . $slug : 'cesis-' . md5($sourceUrl . $rawTitle);

                // Dates & Time
                $dateNode = $row->filter('.date');
                $timeNode = $row->filter('.time');
                $dateStr = $dateNode->count() ? trim($dateNode->text('')) : '';
                $timeStr = $timeNode->count() ? trim($timeNode->text('')) : '';

                [$startAt, $endAt] = $this->parseDates($dateStr, $timeStr, $today);
                if (!$startAt) {
                    return;
                }

                // STRICT RULE: No past events
                $effectiveEnd = $endAt ?? $startAt;
                if ($effectiveEnd->lt($today)) {
                    return;
                }

                // Location
                $locNode = $row->filter('.location');
                $rawLocation = $locNode->count() ? trim($locNode->text('')) : '';
                $location = $this->resolveLocation($rawLocation);

                // Price
                $priceNode = $row->filter('.price-wrap .price');
                $priceStr = $priceNode->count() ? trim($priceNode->text('')) : '';
                $priceData = $this->parsePrice($priceStr);

                // Ticket URL
                $buyNode = $row->filter('.buy-wrap a.buy-button');
                $ticketUrl = $buyNode->count() ? trim($buyNode->attr('href') ?? '') : null;
                if ($ticketUrl === '0' || empty($ticketUrl)) {
                    $ticketUrl = null;
                }

                // Categories & Entertainment type
                $categories = $this->resolveCategories($rawTitle, $location['name']);
                $entertainmentType = $this->resolveEntertainmentType($categories, $rawTitle, $location['name']);

                // Image and description from carousel map if available
                $carouselData = $carouselMap[$sourceUrl] ?? null;
                $imageUrl = $carouselData['image'] ?? null;
                $description = $carouselData['description'] ?? null;
                if (empty($description)) {
                    $description = $rawTitle . ' norisināsies vietā ' . $location['name'] . ', ' . $location['city'] . '.';
                }

                $dto = new ScrapedEventDTO(
                    title: $rawTitle,
                    startAt: $startAt,
                    endAt: $endAt,
                    description: $description,
                    shortDescription: $description ? mb_substr($description, 0, 200, 'UTF-8') : null,
                    venueName: $location['name'],
                    city: $location['city'],
                    region: $location['region'],
                    address: $location['address'],
                    latitude: $location['lat'],
                    longitude: $location['lng'],
                    placeType: $location['place_type'],
                    categoryNames: $categories,
                    entertainmentType: $entertainmentType,
                    isFree: $priceData['is_free'],
                    priceMin: $priceData['price_min'],
                    priceMax: $priceData['price_max'],
                    currency: 'EUR',
                    ticketUrl: $ticketUrl,
                    imageUrl: $imageUrl,
                    sourceUrl: $sourceUrl,
                    sourceExternalId: $externalId,
                    locale: 'lv',
                    rawData: [
                        'raw_title' => $rawTitle,
                        'date_str' => $dateStr,
                        'time_str' => $timeStr,
                        'raw_location' => $rawLocation,
                        'price_str' => $priceStr,
                    ]
                );

                $events->push($dto);
            } catch (\Throwable $e) {
                Log::warning('CesisScraper: Failed to parse event row: ' . $e->getMessage());
            }
        });

        return $events;
    }

    /**
     * Parse date and time strings into start and end Carbon objects.
     */
    public function parseDates(string $dateStr, string $timeStr, ?Carbon $now = null): array
    {
        $tz = 'Europe/Riga';
        $now = $now ?? Carbon::today($tz);
        $dateStr = trim(preg_replace('/\s+/', ' ', $dateStr));
        $timeStr = trim(preg_replace('/\s+/', ' ', $timeStr));

        if (empty($dateStr)) {
            return [null, null];
        }

        // Special case: "- 21.11.2026" (ends on 21.11.2026)
        if (preg_match('/^-\s*(\d{1,2}\.\d{1,2}\.\d{4})$/', $dateStr, $m)) {
            try {
                $endAt = Carbon::createFromFormat('d.m.Y', $m[1], $tz)->endOfDay();
                $startAt = $now->lt($endAt) ? $now->copy()->startOfDay() : $endAt->copy()->startOfDay();

                return [$startAt, $endAt];
            } catch (\Throwable $e) {
                // ignore
            }
        }

        $startTime = null;
        $endTime = null;
        if (!empty($timeStr)) {
            if (preg_match('/^(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})$/', $timeStr, $tm)) {
                $startTime = $tm[1];
                $endTime = $tm[2];
            } elseif (preg_match('/^(\d{1,2}:\d{2})$/', $timeStr, $tm)) {
                $startTime = $tm[1];
            }
        }

        // Date range: DD.MM.YYYY - DD.MM.YYYY
        if (preg_match('/^(\d{1,2}\.\d{1,2}\.\d{4})\s*-\s*(\d{1,2}\.\d{1,2}\.\d{4})$/', $dateStr, $dm)) {
            try {
                $sFormat = $startTime ? 'd.m.Y H:i' : 'd.m.Y';
                $sVal = $startTime ? "{$dm[1]} {$startTime}" : $dm[1];
                $startAt = Carbon::createFromFormat($sFormat, $sVal, $tz);
                if (!$startTime) {
                    $startAt = $startAt->startOfDay();
                }

                $eFormat = $endTime ? 'd.m.Y H:i' : 'd.m.Y';
                $eVal = $endTime ? "{$dm[2]} {$endTime}" : $dm[2];
                $endAt = Carbon::createFromFormat($eFormat, $eVal, $tz);
                if (!$endTime) {
                    $endAt = $endAt->endOfDay();
                }

                if ($endAt && $endAt->lt($startAt)) {
                    $endAt = null;
                }

                return [$startAt, $endAt];
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Single date: DD.MM.YYYY
        if (preg_match('/^(\d{1,2}\.\d{1,2}\.\d{4})$/', $dateStr, $dm)) {
            try {
                if ($startTime) {
                    $startAt = Carbon::createFromFormat('d.m.Y H:i', "{$dm[1]} {$startTime}", $tz);
                    $endAt = $endTime ? Carbon::createFromFormat('d.m.Y H:i', "{$dm[1]} {$endTime}", $tz) : null;
                } else {
                    $startAt = Carbon::createFromFormat('d.m.Y', $dm[1], $tz)->startOfDay();
                    $endAt = null;
                }

                return [$startAt, $endAt];
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return [null, null];
    }

    /**
     * Resolve location information from venue name.
     */
    public function resolveLocation(string $rawLocation): array
    {
        $lower = mb_strtolower(trim($rawLocation), 'UTF-8');

        foreach ($this->venueMap as $venue) {
            foreach ($venue['match'] as $matchKeyword) {
                if (str_contains($lower, $matchKeyword)) {
                    return [
                        'name' => $venue['name'],
                        'city' => $venue['city'],
                        'region' => $venue['region'],
                        'address' => $venue['address'],
                        'place_type' => $venue['place_type'],
                        'lat' => $venue['latitude'],
                        'lng' => $venue['longitude'],
                    ];
                }
            }
        }

        return [
            'name' => !empty($rawLocation) ? $rawLocation : 'Cēsis',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Cēsis, LV-4101',
            'place_type' => 'venue',
            'lat' => 57.3130,
            'lng' => 25.2740,
        ];
    }

    /**
     * Resolve categories adhering strictly to AGENTS.md rules.
     */
    public function resolveCategories(string $title, string $venue): array
    {
        $t = mb_strtolower($title, 'UTF-8');
        $v = mb_strtolower($venue, 'UTF-8');
        $comb = $t . ' ' . $v;

        // 1. STRICT AGENTS.MD RULE: Cinema / Kino (Priority check)
        $kinoKeywords = [
            'filma', 'filmas', 'filmu', 'filmā', 'kinoseanss', 'filmas seanss',
            'spēlfilma', 'dokumentālā filma', 'animācijas filma', 'kinofestivāls',
            'kinoteātr', '3d cinema', 'kino seans',
        ];
        foreach ($kinoKeywords as $kw) {
            if (str_contains($comb, $kw)) {
                return ['Kino'];
            }
        }

        // 2. STRICT AGENTS.MD RULE: Theaters
        $isTheaterProduction = str_contains($comb, 'izrāde') || str_contains($comb, 'izrādes')
            || str_contains($comb, 'iestudējums') || str_contains($comb, 'luga')
            || str_contains($comb, 'balets') || str_contains($comb, 'stand-up')
            || str_contains($comb, 'stāvizrāde') || str_contains($comb, 'komēdija')
            || str_contains($comb, 'amatierteātr') || str_contains($comb, 'amatierteātru');

        if ($isTheaterProduction) {
            $cats = ['Teātris'];
            if (str_contains($t, 'bērniem') || str_contains($t, 'ģimenēm')) {
                $cats[] = 'Bērniem';
            }
            return $cats;
        }

        // 3. Exhibitions
        if (
            str_contains($t, 'izstāde') || str_contains($t, 'izstādes') || str_contains($t, 'personālizstāde')
            || str_contains($t, 'kopizstāde') || str_contains($t, 'gleznu') || str_contains($t, 'fotogrāfiju izstāde')
            || str_contains($t, 'ekspozīcija') || str_contains($t, 'apskatāms')
        ) {
            return ['Izstādes'];
        }

        // 4. Workshops / Seminars / Education
        if (
            str_contains($t, 'meistarklase') || str_contains($t, 'darbnīca') || str_contains($t, 'laboratorija')
            || str_contains($t, 'nodarbība') || str_contains($t, 'klubiņš') || str_contains($t, 'prezentācija')
            || str_contains($t, 'saruna') || str_contains($t, 'erudīcijas')
        ) {
            return ['Semināri'];
        }

        // 5. Music / Concerts
        $isConcertHall = str_contains($v, 'koncertzāle');
        $isMusic = str_contains($t, 'koncert') || str_contains($t, 'dziesm') || str_contains($t, 'mūzik')
            || str_contains($t, 'muzikāl') || str_contains($t, 'solokoncerts') || str_contains($t, 'koris')
            || str_contains($t, 'orķestr') || str_contains($t, 'džez') || str_contains($t, 'jazz')
            || str_contains($t, 'opera') || str_contains($t, 'dzejkoncerts') || str_contains($t, 'balāde')
            || str_contains($t, 'ansambļ') || str_contains($t, 'dziedātāj') || str_contains($t, 'pianists')
            || str_contains($t, 'ģitār') || str_contains($t, 'grupa') || str_contains($t, 'balle')
            || str_contains($t, 'kamermūzik') || str_contains($t, 'vokālistu konkurss')
            || str_contains($t, 'kremerata') || str_contains($t, 'raxtu raxti') || str_contains($t, 'dzelzs vilks');

        if ($isMusic || $isConcertHall) {
            $cats = ['Mūzika'];
            if (str_contains($t, 'svētki') || str_contains($t, 'svētku') || str_contains($t, 'ziemassvētk') || str_contains($t, 'gadumij')) {
                $cats[] = 'Svētki';
            }
            return $cats;
        }

        // 6. Children / Family
        if (str_contains($t, 'bērniem') || str_contains($t, 'ģimenēm') || str_contains($t, 'pirmsskolas')) {
            return ['Bērniem'];
        }

        // 7. Festivities / Fairs
        if (
            str_contains($t, 'svētki') || str_contains($t, 'svētku') || str_contains($t, 'tirdziņš')
            || str_contains($t, 'egles iedegšana') || str_contains($t, 'eglītes iedegšana')
            || str_contains($t, 'gadumija') || str_contains($t, 'vakariņas') || str_contains($t, 'sarīkojums')
        ) {
            return ['Svētki'];
        }

        // 8. Fallback per AGENTS.md rule 3
        return ['Cits'];
    }

    /**
     * Resolve entertainment type from categories.
     */
    public function resolveEntertainmentType(array $categories, string $title, string $venue): string
    {
        if (in_array('Kino', $categories, true)) {
            return 'movie';
        }
        if (in_array('Teātris', $categories, true)) {
            return 'performance';
        }
        if (in_array('Mūzika', $categories, true)) {
            return 'concert';
        }
        if (in_array('Izstādes', $categories, true)) {
            return 'exhibition';
        }
        if (in_array('Sports', $categories, true)) {
            return 'sports';
        }
        if (in_array('Bērniem', $categories, true)) {
            return 'family';
        }
        if (in_array('Semināri', $categories, true)) {
            return 'education';
        }
        if (in_array('Svētki', $categories, true)) {
            return 'chill';
        }

        return 'other';
    }

    /**
     * Parse price string.
     */
    public function parsePrice(string $priceStr): array
    {
        $lower = mb_strtolower(trim($priceStr), 'UTF-8');

        if (
            str_contains($lower, 'bez maksas') || str_contains($lower, 'bezmaksas')
            || str_contains($lower, 'brīva')
        ) {
            return [
                'is_free' => true,
                'price_min' => 0.0,
                'price_max' => 0.0,
            ];
        }

        $priceMin = null;
        $priceMax = null;

        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*(?:€|eur)/ui', $priceStr, $matches)) {
            $prices = array_map(fn($p) => (float) str_replace(',', '.', $p), $matches[1]);
            if (!empty($prices)) {
                $priceMin = min($prices);
                $priceMax = max($prices);
            }
        }

        return [
            'is_free' => ($priceMin === 0.0),
            'price_min' => $priceMin,
            'price_max' => $priceMax,
        ];
    }
}
