<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class VisitTukumsScraper extends BaseScraper
{
    public const BASE_URL = 'https://visittukums.lv';
    public const EVENTS_URL = 'https://visittukums.lv/lv/Pasakumi';

    /**
     * Known venue mappings in Tukuma novads.
     * Specific venues and municipal parish centers ordered before general Tukums center.
     */
    protected array $venueMap = [
        'slokenbekas-muiza' => [
            'match' => ['šlokenbekas muiž', 'slokenbekas muiz', 'slokenbeka'],
            'name' => 'Šlokenbekas muiža',
            'city' => 'Milzkalne',
            'region' => 'Kurzeme',
            'address' => 'Milzkalne, Smārdes pagasts, Tukuma novads, LV-3148',
            'place_type' => 'manor',
            'latitude' => 56.9763,
            'longitude' => 23.2244,
        ],
        'jaunmoku-pils' => [
            'match' => ['jaunmoku pil', 'jaunmokas'],
            'name' => 'Jaunmoku pils',
            'city' => 'Jaunmokas',
            'region' => 'Kurzeme',
            'address' => 'Jaunmokas, Tumes pagasts, Tukuma novads, LV-3139',
            'place_type' => 'castle',
            'latitude' => 56.9814,
            'longitude' => 23.0531,
        ],
        'jaunpils-pils' => [
            'match' => ['jaunpils pil', 'jaunpils kultūras nam', 'jaunpils'],
            'name' => 'Jaunpils pils',
            'city' => 'Jaunpils',
            'region' => 'Kurzeme',
            'address' => 'Jaunpils, Tukuma novads, LV-3145',
            'place_type' => 'castle',
            'latitude' => 56.7314,
            'longitude' => 23.0139,
        ],
        'durbes-pils' => [
            'match' => ['durbes pil', 'tukuma muzej'],
            'name' => 'Durbes pils',
            'city' => 'Tukums',
            'region' => 'Kurzeme',
            'address' => 'M. Parka iela 7, Tukums, LV-3101',
            'place_type' => 'museum',
            'latitude' => 56.9587,
            'longitude' => 23.1793,
        ],
        'tukuma-kulturas-nams' => [
            'match' => ['tukuma pilsētas kultūras nam', 'tukuma kultūras nam', 'tukuma kn'],
            'name' => 'Tukuma pilsētas Kultūras nams',
            'city' => 'Tukums',
            'region' => 'Kurzeme',
            'address' => 'Lielā iela 1, Tukums, LV-3101',
            'place_type' => 'cultural_center',
            'latitude' => 56.9678,
            'longitude' => 23.1554,
        ],
        'tukuma-biblioteka' => [
            'match' => ['tukuma bibliotēk', 'tukuma bibliotek', 'bibliotēk'],
            'name' => 'Tukuma bibliotēka',
            'city' => 'Tukums',
            'region' => 'Kurzeme',
            'address' => 'Šēseles iela 3, Tukums, LV-3101',
            'place_type' => 'library',
            'latitude' => 56.9686,
            'longitude' => 23.1539,
        ],
        'irlavas-kulturas-nams' => [
            'match' => ['irlavas kultūras nam', 'irlavas kn', 'irlavā', 'irlava'],
            'name' => 'Irlavas kultūras nams',
            'city' => 'Irlava',
            'region' => 'Kurzeme',
            'address' => 'Irlava, Irlavas pagasts, Tukuma novads, LV-3137',
            'place_type' => 'cultural_center',
            'latitude' => 56.8653,
            'longitude' => 23.0033,
        ],
        'dzukstes-kulturas-nams' => [
            'match' => ['džūkstes kultūras nam', 'džūkstē', 'dzukstes kulturas nam', 'dzukste'],
            'name' => 'Džūkstes Kultūras nams',
            'city' => 'Džūkste',
            'region' => 'Kurzeme',
            'address' => 'Džūkste, Džūkstes pagasts, Tukuma novads, LV-3147',
            'place_type' => 'cultural_center',
            'latitude' => 56.7865,
            'longitude' => 23.3275,
        ],
        'lestenes-tautas-nams' => [
            'match' => ['lestenes tautas nam', 'lestenē', 'lestene'],
            'name' => 'Lestenes tautas nams',
            'city' => 'Lestene',
            'region' => 'Kurzeme',
            'address' => 'Lestene, Lestenes pagasts, Tukuma novads, LV-3119',
            'place_type' => 'cultural_center',
            'latitude' => 56.8488,
            'longitude' => 22.9836,
        ],
        'pures-sokolades-muzejs' => [
            'match' => ['pūres šokolādes muzej', 'pūres beķerej', 'pūrē', 'pūre', 'pure'],
            'name' => 'Pūres Šokolādes muzejs',
            'city' => 'Pūre',
            'region' => 'Kurzeme',
            'address' => 'Pūres Beķereja, Pūre, Tukuma novads, LV-3124',
            'place_type' => 'museum',
            'latitude' => 57.0367,
            'longitude' => 22.9069,
        ],
        'lapmezciema-tautas-nams' => [
            'match' => ['lapmežciema kultūras nam', 'lapmežciema tautas nam', 'lapmežciemā', 'lapmežciems', 'lapmezciem'],
            'name' => 'Lapmežciema Tautas nams',
            'city' => 'Lapmežciems',
            'region' => 'Kurzeme',
            'address' => 'Liepu iela 2, Lapmežciems, Tukuma novads, LV-3118',
            'place_type' => 'cultural_center',
            'latitude' => 56.9961,
            'longitude' => 23.5186,
        ],
        'ragaciems' => [
            'match' => ['ragaciemā', 'ragaciems', 'ragaciema'],
            'name' => 'Ragaciems',
            'city' => 'Ragaciems',
            'region' => 'Kurzeme',
            'address' => 'Ragaciems, Lapmežciema pagasts, Tukuma novads, LV-3118',
            'place_type' => 'venue',
            'latitude' => 57.0253,
            'longitude' => 23.4883,
        ],
        'slampes-kulturas-pils' => [
            'match' => ['slampes kultūras pil', 'slampes kp', 'slampē', 'slampe'],
            'name' => 'Slampes Kultūras pils',
            'city' => 'Slampe',
            'region' => 'Kurzeme',
            'address' => 'Slampe, Slampes pagasts, Tukuma novads, LV-3133',
            'place_type' => 'cultural_center',
            'latitude' => 56.8488,
            'longitude' => 23.2981,
        ],
        'zemites-tautas-nams' => [
            'match' => ['zemītes tautas nam', 'zemītē', 'zemite'],
            'name' => 'Zemītes tautas nams',
            'city' => 'Zemīte',
            'region' => 'Kurzeme',
            'address' => 'Zemīte, Zemītes pagasts, Tukuma novads, LV-3135',
            'place_type' => 'cultural_center',
            'latitude' => 56.9483,
            'longitude' => 22.8025,
        ],
        'vanes-kulturas-nams' => [
            'match' => ['vānes kultūras nam', 'vānē', 'vane'],
            'name' => 'Vānes kultūras nams',
            'city' => 'Vāne',
            'region' => 'Kurzeme',
            'address' => 'Vāne, Vānes pagasts, Tukuma novads, LV-3131',
            'place_type' => 'cultural_center',
            'latitude' => 56.8858,
            'longitude' => 22.6844,
        ],
        'kandavas-kulturas-nams' => [
            'match' => ['kandavas kultūras nam', 'kandavas kn', 'galerijā “vējspārns”', 'kandavas kultūras nama'],
            'name' => 'Kandavas kultūras nams',
            'city' => 'Kandava',
            'region' => 'Kurzeme',
            'address' => 'Lielā iela 28, Kandava, Tukuma novads, LV-3120',
            'place_type' => 'cultural_center',
            'latitude' => 57.0422,
            'longitude' => 22.7758,
        ],
        'kandavas-baznica' => [
            'match' => ['kandavas luterāņu baznīc', 'kandavas baznīc'],
            'name' => 'Kandavas evaņģēliski luteriskā baznīca',
            'city' => 'Kandava',
            'region' => 'Kurzeme',
            'address' => 'Baznīcas iela 5, Kandava, Tukuma novads, LV-3120',
            'place_type' => 'church',
            'latitude' => 57.0416,
            'longitude' => 22.7766,
        ],
        'kandavas-vina-nams' => [
            'match' => ['kandavas vīna nam', 'vīna nam'],
            'name' => 'Kandavas Vīna nams',
            'city' => 'Kandava',
            'region' => 'Kurzeme',
            'address' => 'Ūdens iela 2, Kandava, Tukuma novads, LV-3120',
            'place_type' => 'restaurant',
            'latitude' => 57.0412,
            'longitude' => 22.7770,
        ],
        'kalnmuizas-pils' => [
            'match' => ['kalnmuižas pil', 'kalnmuiz'],
            'name' => 'Kalnmuižas pils',
            'city' => 'Kalnmuiža',
            'region' => 'Kurzeme',
            'address' => 'Kalnmuiža, Kandavas pagasts, Tukuma novads, LV-3120',
            'place_type' => 'castle',
            'latitude' => 57.0673,
            'longitude' => 22.6983,
        ],
        'kukul-ceptuve' => [
            'match' => ['kukul', 'kukuļa'],
            'name' => 'Kukul ceptuve',
            'city' => 'Kandava',
            'region' => 'Kurzeme',
            'address' => 'Tirgus laukums 1, Kandava, Tukuma novads, LV-3120',
            'place_type' => 'restaurant',
            'latitude' => 57.0400,
            'longitude' => 22.7750,
        ],
        'kandava' => [
            'match' => ['kandavā', 'kandava'],
            'name' => 'Kandava',
            'city' => 'Kandava',
            'region' => 'Kurzeme',
            'address' => 'Kandava, Tukuma novads, LV-3120',
            'place_type' => 'venue',
            'latitude' => 57.0410,
            'longitude' => 22.7750,
        ],
        'cinevilla' => [
            'match' => ['cinevill'],
            'name' => 'Kino pilsētiņa Cinevilla',
            'city' => 'Slampe',
            'region' => 'Kurzeme',
            'address' => 'Vidusvecvagari, Slampes pagasts, Tukuma novads, LV-3133',
            'place_type' => 'venue',
            'latitude' => 56.8800,
            'longitude' => 23.2300,
        ],
        'tukums-center' => [
            'match' => ['tukumā', 'tukums', 'brīvības laukum'],
            'name' => 'Tukuma pilsētas centrs',
            'city' => 'Tukums',
            'region' => 'Kurzeme',
            'address' => 'Brīvības laukums, Tukums, LV-3101',
            'place_type' => 'square',
            'latitude' => 56.9678,
            'longitude' => 23.1554,
        ],
    ];

    public function getSlug(): string
    {
        return 'visittukums';
    }

    public function getName(): string
    {
        return 'Visit Tukums';
    }

    /**
     * Scrape events from visittukums.lv.
     * Extracts all information directly from the event listings and pagination
     * without crawling into individual detail pages.
     *
     * @return Collection<int, ScrapedEventDTO>
     */
    public function scrape(Source $source): Collection
    {
        $events = collect();
        $today = Carbon::today('Europe/Riga');
        $maxPages = 20;

        for ($page = 1; $page <= $maxPages; $page++) {
            $url = $page === 1 ? self::EVENTS_URL : self::EVENTS_URL . '/' . $page;

            $crawler = $this->fetchCrawler($url);
            if (!$crawler) {
                Log::warning("VisitTukumsScraper: Failed to fetch page {$page} at {$url}");
                break;
            }

            $cards = $crawler->filter('div.article2');
            if ($cards->count() === 0) {
                break;
            }

            $cards->each(function (Crawler $card) use (&$events, $today, $url) {
                try {
                    $linkNode = $card->filter('h3 a');
                    if ($linkNode->count() === 0) {
                        return;
                    }

                    $rawTitle = trim($linkNode->text(''));
                    if (empty($rawTitle)) {
                        return;
                    }

                    [$tag, $title] = $this->extractTagAndTitle($rawTitle);

                    $href = $linkNode->attr('href') ?? '';
                    $sourceUrl = str_starts_with($href, 'http') ? $href : self::BASE_URL . (str_starts_with($href, '/') ? '' : '/') . $href;

                    // External ID from numeric ID in URL (e.g. /lv/Pasakumi/0/6179 -> visittukums-6179)
                    $externalId = null;
                    if (preg_match('/\/(\d+)(?:\/)?$/', $href, $idMatch)) {
                        $externalId = 'visittukums-' . $idMatch[1];
                    } else {
                        $externalId = 'visittukums-' . md5($sourceUrl . $title);
                    }

                    // Dates
                    $dateNode = $card->filter('div[style*="font-size: 12px"]');
                    $dateStr = $dateNode->count() ? trim($dateNode->text('')) : '';
                    [$startAt, $endAt] = $this->parseDates($dateStr, $today);

                    if (!$startAt) {
                        return;
                    }

                    // STRICT RULE: No past events
                    $effectiveEnd = $endAt ?? $startAt;
                    if ($effectiveEnd->lt($today)) {
                        return;
                    }

                    // Image
                    $imgNode = $card->filter('.imageWrap img');
                    $imageUrl = $imgNode->count() ? trim($imgNode->attr('src') ?? '') : null;
                    if (!empty($imageUrl)) {
                        // Normalize http to https
                        if (str_starts_with($imageUrl, 'http://')) {
                            $imageUrl = 'https://' . substr($imageUrl, 7);
                        } elseif (str_starts_with($imageUrl, '//')) {
                            $imageUrl = 'https:' . $imageUrl;
                        } elseif (str_starts_with($imageUrl, '/')) {
                            $imageUrl = self::BASE_URL . $imageUrl;
                        }
                    }

                    // Excerpt / description
                    $excerptNode = $card->filter('div.excerpt');
                    $description = $excerptNode->count() ? trim($excerptNode->text('')) : '';
                    // Clean HTML entities if any
                    $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                    // Location
                    $loc = $this->resolveLocation($rawTitle, $description, $imageUrl ?? '');

                    // Categories & Entertainment type
                    $categories = $this->resolveCategories($tag, $title, $description, $loc['name']);
                    $entertainmentType = $this->resolveEntertainmentType($categories, $title, $description);

                    // Pricing
                    $priceData = $this->parsePrice($description);

                    $dto = new ScrapedEventDTO(
                        title: $title,
                        startAt: $startAt,
                        endAt: $endAt,
                        description: !empty($description) ? $description : $title,
                        shortDescription: !empty($description) ? mb_substr($description, 0, 200, 'UTF-8') : null,
                        venueName: $loc['name'],
                        city: $loc['city'],
                        region: $loc['region'],
                        address: $loc['address'],
                        latitude: $loc['lat'],
                        longitude: $loc['lng'],
                        placeType: $loc['place_type'],
                        categoryNames: $categories,
                        entertainmentType: $entertainmentType,
                        isFree: $priceData['is_free'],
                        priceMin: $priceData['price_min'],
                        priceMax: $priceData['price_max'],
                        currency: 'EUR',
                        imageUrl: $imageUrl,
                        sourceUrl: $sourceUrl,
                        sourceExternalId: $externalId,
                        locale: 'lv',
                        rawData: [
                            'raw_title' => $rawTitle,
                            'tag' => $tag,
                            'date_str' => $dateStr,
                            'href' => $href,
                        ]
                    );

                    $events->push($dto);
                } catch (\Throwable $e) {
                    Log::warning('VisitTukumsScraper: Failed to parse card: ' . $e->getMessage(), [
                        'url' => $url,
                    ]);
                }
            });

            // Check if there is a next page
            $nextLink = $crawler->filter('div.pagination a[rel="next"]');
            if ($nextLink->count() === 0) {
                // Double check if next page number link exists
                $nextPageLink = $crawler->filter('div.pagination a[data-ci-pagination-page="' . ($page + 1) . '"]');
                if ($nextPageLink->count() === 0) {
                    break;
                }
            }
        }

        return $events;
    }

    /**
     * Extract optional category tag prefix and clean title.
     * E.g. "TEĀTRIS | Amatierteātra izrāde \"Dārgumi\"" -> ["TEĀTRIS", "Amatierteātra izrāde \"Dārgumi\""]
     */
    public function extractTagAndTitle(string $rawTitle): array
    {
        $rawTitle = trim(preg_replace('/\s+/', ' ', $rawTitle));

        if (preg_match('/^([A-ZĀČĒĢĪĶĻŅŠŪŽ\s]{3,})\s*\|\s*(.+)$/u', $rawTitle, $m)) {
            $tag = trim($m[1]);
            $title = trim($m[2]);

            return [$tag, $title];
        }

        return [null, $rawTitle];
    }

    /**
     * Parse start and end dates from string.
     */
    public function parseDates(string $dateStr, ?Carbon $now = null): array
    {
        $tz = 'Europe/Riga';
        $now = $now ?? Carbon::today($tz);
        $dateStr = trim(preg_replace('/\s+/', ' ', $dateStr));

        if (empty($dateStr)) {
            return [null, null];
        }

        $startAt = null;
        $endAt = null;

        // Pattern 1: DD.MM.YYYY HH:MM - HH:MM
        if (preg_match('/^(\d{1,2}\.\d{1,2}\.\d{4})\s+(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})$/', $dateStr, $m)) {
            try {
                $startAt = Carbon::createFromFormat('d.m.Y H:i', "{$m[1]} {$m[2]}", $tz);
                $endAt = Carbon::createFromFormat('d.m.Y H:i', "{$m[1]} {$m[3]}", $tz);
            } catch (\Throwable $e) {
                // ignore
            }
        }
        // Pattern 2: DD.MM.YYYY HH:MM - DD.MM.YYYY HH:MM (or without time on second date)
        elseif (preg_match('/^(\d{1,2}\.\d{1,2}\.\d{4})\s+(\d{1,2}:\d{2})\s*-\s*(\d{1,2}\.\d{1,2}\.\d{4})(?:\s+(\d{1,2}:\d{2}))?$/', $dateStr, $m)) {
            try {
                $startAt = Carbon::createFromFormat('d.m.Y H:i', "{$m[1]} {$m[2]}", $tz);
                $endTime = !empty($m[4]) ? $m[4] : '23:59:59';
                $endFormat = !empty($m[4]) ? 'd.m.Y H:i' : 'd.m.Y H:i:s';
                $endAt = Carbon::createFromFormat($endFormat, "{$m[3]} {$endTime}", $tz);

                // Discard invalid end date if it is set in the past relative to startAt
                if ($endAt && $endAt->lt($startAt)) {
                    $endAt = null;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        // Pattern 3: DD.MM.YYYY - DD.MM.YYYY
        elseif (preg_match('/^(\d{1,2}\.\d{1,2}\.\d{4})\s*-\s*(\d{1,2}\.\d{1,2}\.\d{4})$/', $dateStr, $m)) {
            try {
                $startAt = Carbon::createFromFormat('d.m.Y', $m[1], $tz)->startOfDay();
                $endAt = Carbon::createFromFormat('d.m.Y', $m[2], $tz)->endOfDay();

                if ($endAt && $endAt->lt($startAt)) {
                    $endAt = null;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        // Pattern 4: DD.MM.YYYY HH:MM
        elseif (preg_match('/^(\d{1,2}\.\d{1,2}\.\d{4})\s+(\d{1,2}:\d{2})$/', $dateStr, $m)) {
            try {
                $startAt = Carbon::createFromFormat('d.m.Y H:i', "{$m[1]} {$m[2]}", $tz);
            } catch (\Throwable $e) {
                // ignore
            }
        }
        // Pattern 5: DD.MM.YYYY
        elseif (preg_match('/^(\d{1,2}\.\d{1,2}\.\d{4})$/', $dateStr, $m)) {
            try {
                $startAt = Carbon::createFromFormat('d.m.Y', $m[1], $tz)->startOfDay();
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return [$startAt, $endAt];
    }

    /**
     * Resolve location information from title, excerpt, and image url.
     */
    public function resolveLocation(string $title, string $description, string $imgUrl): array
    {
        $haystack = mb_strtolower($title . ' ' . $description . ' ' . $imgUrl, 'UTF-8');

        foreach ($this->venueMap as $venue) {
            foreach ($venue['match'] as $matchKeyword) {
                if (str_contains($haystack, $matchKeyword)) {
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

        // Default Tukuma novada centrs
        return [
            'name' => 'Tukums',
            'city' => 'Tukums',
            'region' => 'Kurzeme',
            'address' => 'Tukums, LV-3101',
            'place_type' => 'venue',
            'lat' => 56.9678,
            'lng' => 23.1554,
        ];
    }

    /**
     * Resolve event categories adhering strictly to AGENTS.md rules.
     */
    public function resolveCategories(?string $tag, string $title, string $description, string $venue): array
    {
        $t = mb_strtolower($title, 'UTF-8');
        $d = mb_strtolower($description, 'UTF-8');
        $v = mb_strtolower($venue, 'UTF-8');
        $tagLower = $tag ? mb_strtolower($tag, 'UTF-8') : '';
        $comb = $tagLower . ' ' . $t . ' ' . $v . ' ' . $d;

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
        $isTheaterTag = str_contains($tagLower, 'teātr');
        $isTheaterProduction = str_contains($comb, 'izrāde') || str_contains($comb, 'izrādes')
            || str_contains($comb, 'iestudējums') || str_contains($comb, 'luga')
            || str_contains($comb, 'balets') || str_contains($comb, 'stand-up')
            || str_contains($comb, 'stāvizrāde') || str_contains($comb, 'komēdija')
            || str_contains($comb, 'amatierteātr');

        if ($isTheaterTag || $isTheaterProduction) {
            $cats = ['Teātris'];
            if (in_array($tagLower, ['bērniem', 'ģimenei'], true) || str_contains($t, 'bērniem') || str_contains($t, 'ģimenei')) {
                $cats[] = 'Bērniem';
            }
            return $cats;
        }

        // 3. Workshops / Seminars / Lectures (prioritize before general background music mention in body)
        $isWorkshopTag = in_array($tagLower, ['radoši', 'meistarklase', 'tikšanās'], true);
        $isWorkshopTitle = str_contains($t, 'darbnīca') || str_contains($t, 'meistarklase') || str_contains($t, 'veidošana') || str_contains($t, 'prezentācija');
        if ($isWorkshopTag || $isWorkshopTitle) {
            return ['Semināri'];
        }

        // 4. Music / Concerts
        $isMusicTag = in_array($tagLower, ['mūzika', 'koncerts'], true);
        $isMusicText = str_contains($t, 'koncert') || str_contains($t, 'dziesm') || str_contains($t, 'mūzik')
            || str_contains($t, 'muzikāl') || str_contains($t, 'orķestr') || str_contains($t, 'koris')
            || str_contains($t, 'džez') || str_contains($t, 'dziedātāj') || str_contains($t, 'dzied')
            || str_contains($t, 'čell') || str_contains($t, 'ģitār') || str_contains($t, 'rokmūzik')
            || str_contains($t, 'solists') || str_contains($t, 'klavier')
            || str_contains($d, 'koncertprogrammā') || str_contains($d, 'svētku koncert') || str_contains($d, 'dziesminiec');

        if ($isMusicTag || $isMusicText) {
            $cats = ['Mūzika'];
            if (in_array($tagLower, ['svētki', 'valsts svētki', 'ziemassvētki', 'gadumija', 'latvija'], true)) {
                $cats[] = 'Svētki';
            }
            return $cats;
        }

        // 5. Children / Family
        if (in_array($tagLower, ['bērniem', 'ģimenei'], true) || str_contains($t, 'bērniem') || str_contains($comb, 'robotu šovs')) {
            return ['Bērniem'];
        }

        // 6. Sports / Active Recreation
        if (str_contains($tagLower, 'atpūta') || str_contains($t, 'skrējiens') || str_contains($t, 'pārgājiens') || str_contains($t, 'maratons') || str_contains($t, 'sports')) {
            return ['Sports'];
        }

        // 7. Exhibitions
        if (str_contains($tagLower, 'izstād') || str_contains($t, 'izstāde') || str_contains($t, 'gleznu')) {
            return ['Izstādes'];
        }

        // 8. Holidays / Celebrations / Fairs / Food
        if (
            in_array($tagLower, ['svētki', 'valsts svētki', 'ziemassvētki', 'gadumija', 'latvija', 'tirgus', 'gardēžiem', 'labdarības pasākums'], true)
            || str_contains($t, 'svētki') || str_contains($t, 'tirdziņš') || str_contains($t, 'egles iedegšana') || str_contains($t, 'andele')
        ) {
            return ['Svētki'];
        }

        // Fallback per AGENTS.md rule 3
        return ['Cits'];
    }

    /**
     * Resolve entertainment type from category names and text.
     */
    public function resolveEntertainmentType(array $categories, string $title, string $description): string
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
     * Parse price and free admission flags.
     */
    public function parsePrice(string $description): array
    {
        $lower = mb_strtolower($description, 'UTF-8');

        $isFree = false;
        if (
            str_contains($lower, 'bez maksas') || str_contains($lower, 'bezmaksas')
            || str_contains($lower, 'ieeja brīva') || str_contains($lower, 'brīva ieeja')
            || str_contains($lower, 'ieeja bez maksas')
        ) {
            $isFree = true;
        }

        $priceMin = null;
        $priceMax = null;

        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*(?:EUR|€)/ui', $description, $matches)) {
            $prices = array_map(fn($p) => (float) str_replace(',', '.', $p), $matches[1]);
            if (!empty($prices)) {
                $priceMin = min($prices);
                $priceMax = max($prices);
                if ($priceMin === 0.0) {
                    $isFree = true;
                }
            }
        }

        if ($priceMin !== null && $priceMin > 0) {
            $isFree = false;
        } elseif ($isFree && $priceMin === null) {
            $priceMin = 0.0;
            $priceMax = 0.0;
        }

        return [
            'is_free' => $isFree,
            'price_min' => $priceMin,
            'price_max' => $priceMax,
        ];
    }
}
