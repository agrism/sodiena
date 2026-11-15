<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class VisitJekabpilsScraper extends BaseScraper
{
    public const BASE_URL = 'https://visit.jekabpils.lv/lv/visi-pasakumi/';

    /**
     * Latvian month names abbreviation to numeric month map.
     */
    protected array $monthMap = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
        'mai' => 5, 'jūn' => 6, 'jun' => 6, 'jūl' => 7, 'jul' => 7,
        'aug' => 8, 'sep' => 9, 'okt' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /**
     * Known venue mappings across Jēkabpils novads.
     * All venues in Jēkabpils novads belong to region Latgale in the system.
     */
    protected array $venueMap = [
        'krustpils-kc' => [
            'match' => ['krustpils kultūr', 'krustpils kc', 'krustpils kultūras centrā'],
            'name' => 'Krustpils kultūras centrs',
            'city' => 'Jēkabpils',
            'region' => 'Latgale',
            'address' => 'Rīgas iela 212, Jēkabpils, LV-5202',
            'place_type' => 'cultural_center',
            'latitude' => 56.5098,
            'longitude' => 25.8679,
        ],
        'krustpils-pils' => [
            'match' => ['krustpils pil', 'jēkabpils vēstures muzejs'],
            'name' => 'Krustpils pils',
            'city' => 'Jēkabpils',
            'region' => 'Latgale',
            'address' => 'Rīgas iela 216b, Jēkabpils, LV-5202',
            'place_type' => 'castle',
            'latitude' => 56.5106,
            'longitude' => 25.8698,
        ],
        'selu-seta' => [
            'match' => ['sēļu sēt'],
            'name' => 'Brīvdabas nodaļa "Sēļu sēta"',
            'city' => 'Jēkabpils',
            'region' => 'Latgale',
            'address' => 'Filozofu iela 6, Jēkabpils, LV-5201',
            'place_type' => 'museum',
            'latitude' => 56.4950,
            'longitude' => 25.8650,
        ],
        'sporta-halle' => [
            'match' => ['sporta hall', 'ledus arēn'],
            'name' => 'Jēkabpils sporta halle',
            'city' => 'Jēkabpils',
            'region' => 'Latgale',
            'address' => 'Brīvības iela 289b, Jēkabpils, LV-5201',
            'place_type' => 'venue',
            'latitude' => 56.4890,
            'longitude' => 25.8750,
        ],
        'kartingu-halle' => [
            'match' => ['kartingu hall'],
            'name' => 'Jēkabpils kartingu halle',
            'city' => 'Jēkabpils',
            'region' => 'Latgale',
            'address' => 'Rīgas iela 218a, Jēkabpils, LV-5202',
            'place_type' => 'venue',
            'latitude' => 56.5110,
            'longitude' => 25.8700,
        ],
        'kvartals-86' => [
            'match' => ['kvartāl'],
            'name' => 'Kvartāls 86',
            'city' => 'Jēkabpils',
            'region' => 'Latgale',
            'address' => 'Brīvības iela 86, Jēkabpils, LV-5201',
            'place_type' => 'cultural_center',
            'latitude' => 56.4980,
            'longitude' => 25.8700,
        ],
        'sedumi' => [
            'match' => ['sedumi'],
            'name' => 'Tirdzniecības centrs "Sedumi"',
            'city' => 'Jēkabpils',
            'region' => 'Latgale',
            'address' => 'Brīvības iela 301a, Jēkabpils, LV-5201',
            'place_type' => 'mall',
            'latitude' => 56.4880,
            'longitude' => 25.8760,
        ],
        'tc-aura' => [
            'match' => ['aura'],
            'name' => 'Tirdzniecības centrs "Aura"',
            'city' => 'Jēkabpils',
            'region' => 'Latgale',
            'address' => 'Nākotnes iela 2, Jēkabpils, LV-5201',
            'place_type' => 'mall',
            'latitude' => 56.4950,
            'longitude' => 25.8550,
        ],
        'kekis' => [
            'match' => ['ķēķis', 'ķēķī'],
            'name' => 'Kafejnīca "Ķēķis"',
            'city' => 'Jēkabpils',
            'region' => 'Latgale',
            'address' => 'Brīvības iela 136, Jēkabpils, LV-5201',
            'place_type' => 'restaurant',
            'latitude' => 56.4990,
            'longitude' => 25.8710,
        ],
        'lauku-siks' => [
            'match' => ['lauku šiks'],
            'name' => 'Kafejnīca "Lauku šiks"',
            'city' => 'Viesīte',
            'region' => 'Latgale',
            'address' => 'Viesītes pagasts, Jēkabpils novads, LV-5237',
            'place_type' => 'restaurant',
            'latitude' => 56.3470,
            'longitude' => 25.5550,
        ],
        'taborkalns' => [
            'match' => ['taborkaln'],
            'name' => 'Taborkalna skatu tornis',
            'city' => 'Sēlija',
            'region' => 'Latgale',
            'address' => 'Sēlpils pagasts, Jēkabpils novads, LV-5232',
            'place_type' => 'landmark',
            'latitude' => 56.5400,
            'longitude' => 25.6800,
        ],
        'asares-muiza' => [
            'match' => ['asare', 'asarē', 'asares'],
            'name' => 'Asares muiža',
            'city' => 'Asare',
            'region' => 'Latgale',
            'address' => 'Asare, Asares pagasts, Jēkabpils novads, LV-5212',
            'place_type' => 'manor',
            'latitude' => 56.1167,
            'longitude' => 25.8833,
        ],
        'ilzu-baznica' => [
            'match' => ['ilzu baznīc', 'ilzes baznīc'],
            'name' => 'Ilzu luterāņu baznīca',
            'city' => 'Cīruļi',
            'region' => 'Latgale',
            'address' => 'Rites pagasts, Jēkabpils novads, LV-5228',
            'place_type' => 'landmark',
            'latitude' => 56.1750,
            'longitude' => 25.4750,
        ],
        'kaldabrunas-baznica' => [
            'match' => ['kaldabruņ', 'červonk'],
            'name' => 'Kaldabruņas luterāņu baznīca',
            'city' => 'Kaldabruņa',
            'region' => 'Latgale',
            'address' => 'Kaldabruņa, Rubenes pagasts, Jēkabpils novads, LV-5229',
            'place_type' => 'landmark',
            'latitude' => 56.1600,
            'longitude' => 25.9800,
        ],
        'vipes-mazers' => [
            'match' => ['vīpe', 'vīpē', 'vīpes', 'māzers'],
            'name' => 'Vīpes amatniecības centrs "Māzers"',
            'city' => 'Vīpe',
            'region' => 'Latgale',
            'address' => 'Vīpe, Vīpes pagasts, Jēkabpils novads, LV-5225',
            'place_type' => 'cultural_center',
            'latitude' => 56.4678,
            'longitude' => 26.0464,
        ],
        'salas-tautas-nams' => [
            'match' => ['salas tautas', 'salā', 'salas pagast'],
            'name' => 'Salas Tautas nams',
            'city' => 'Sala',
            'region' => 'Latgale',
            'address' => 'Susējas iela 9, Sala, Salas pagasts, Jēkabpils novads, LV-5230',
            'place_type' => 'cultural_center',
            'latitude' => 56.5050,
            'longitude' => 25.7640,
        ],
        'viesites-kc' => [
            'match' => ['viesīt'],
            'name' => 'Viesītes kultūras centrs "Sēlija"',
            'city' => 'Viesīte',
            'region' => 'Latgale',
            'address' => 'Smilšu iela 2, Viesīte, Jēkabpils novads, LV-5237',
            'place_type' => 'cultural_center',
            'latitude' => 56.3468,
            'longitude' => 25.5562,
        ],
        'selpils-tautas-nams' => [
            'match' => ['sēlpil'],
            'name' => 'Sēlpils Tautas nams',
            'city' => 'Sēlija',
            'region' => 'Latgale',
            'address' => '"Daugavas", Sēlija, Sēlpils pagasts, Jēkabpils novads, LV-5232',
            'place_type' => 'cultural_center',
            'latitude' => 56.5502,
            'longitude' => 25.6881,
        ],
        'rubenes-tautas-nams' => [
            'match' => ['ruben', 'rubeņ'],
            'name' => 'Rubenes Tautas nams',
            'city' => 'Rubeņi',
            'region' => 'Latgale',
            'address' => '"Pārupes", Rubeņi, Rubenes pagasts, Jēkabpils novads, LV-5229',
            'place_type' => 'cultural_center',
            'latitude' => 56.1264,
            'longitude' => 26.0494,
        ],
        'lones-tautas-nams' => [
            'match' => ['lonē', 'lones'],
            'name' => 'Lones Tautas nams',
            'city' => 'Lone',
            'region' => 'Latgale',
            'address' => '"Doles", Lone, Saukas pagasts, Jēkabpils novads, LV-5224',
            'place_type' => 'cultural_center',
            'latitude' => 56.2572,
            'longitude' => 25.4982,
        ],
        'abelu-tautas-nams' => [
            'match' => ['ābeļ', 'brodi'],
            'name' => 'Ābeļu Tautas nams',
            'city' => 'Brodi',
            'region' => 'Latgale',
            'address' => 'Brodi, Ābeļu pagasts, Jēkabpils novads, LV-5201',
            'place_type' => 'cultural_center',
            'latitude' => 56.4633,
            'longitude' => 25.9083,
        ],
        'garsenes-pils' => [
            'match' => ['gārsen'],
            'name' => 'Gārsenes pils',
            'city' => 'Gārsene',
            'region' => 'Latgale',
            'address' => '"Labieši", Gārsene, Gārsenes pagasts, Jēkabpils novads, LV-5218',
            'place_type' => 'castle',
            'latitude' => 56.0991,
            'longitude' => 25.8105,
        ],
        'variesu-tautas-nams' => [
            'match' => ['varieš'],
            'name' => 'Variešu Tautas nams',
            'city' => 'Varieši',
            'region' => 'Latgale',
            'address' => 'Varieši, Variešu pagasts, Jēkabpils novads, LV-5236',
            'place_type' => 'cultural_center',
            'latitude' => 56.5794,
            'longitude' => 25.9628,
        ],
        'birzu-tautas-nams' => [
            'match' => ['birž'],
            'name' => 'Biržu Tautas nams',
            'city' => 'Birži',
            'region' => 'Latgale',
            'address' => 'Birži, Salas pagasts, Jēkabpils novads, LV-5215',
            'place_type' => 'cultural_center',
            'latitude' => 56.4022,
            'longitude' => 25.7950,
        ],
        'elksnu-tautas-nams' => [
            'match' => ['elkšņ'],
            'name' => 'Elkšņu Tautas nams',
            'city' => 'Elkšņi',
            'region' => 'Latgale',
            'address' => 'Elkšņi, Elkšņu pagasts, Jēkabpils novads, LV-5217',
            'place_type' => 'cultural_center',
            'latitude' => 56.2167,
            'longitude' => 25.6167,
        ],
        'dignajas-pamatskola' => [
            'match' => ['dignāj'],
            'name' => 'Dignājas pamatskola',
            'city' => 'Dignāja',
            'region' => 'Latgale',
            'address' => 'Dignāja, Dignājas pagasts, Jēkabpils novads, LV-5219',
            'place_type' => 'venue',
            'latitude' => 56.3200,
            'longitude' => 26.1300,
        ],
        'jekabpils-tautas-nams' => [
            'match' => ['jēkabpils tautas nams', 'jēkabpils tautas namā', 'jēkabpils tn'],
            'name' => 'Jēkabpils Tautas nams',
            'city' => 'Jēkabpils',
            'region' => 'Latgale',
            'address' => 'Vecpilsētas laukums 3, Jēkabpils, LV-5201',
            'place_type' => 'cultural_center',
            'latitude' => 56.4984,
            'longitude' => 25.8617,
        ],
        'jekabpils' => [
            'match' => ['jēkabpils', 'jēkabpilī', 'jekabpils'],
            'name' => 'Jēkabpils',
            'city' => 'Jēkabpils',
            'region' => 'Latgale',
            'address' => 'Brīvības iela 120, Jēkabpils, LV-5201',
            'place_type' => 'square',
            'latitude' => 56.4988,
            'longitude' => 25.8714,
        ],
    ];

    public function getSlug(): string
    {
        return 'visitjekabpils';
    }

    public function getName(): string
    {
        return 'Visit Jēkabpils';
    }

    /**
     * Scrape events from visit.jekabpils.lv/lv/visi-pasakumi/.
     * All upcoming events across all dates are pre-rendered directly on the main listing page.
     * No crawling into individual detail pages is performed.
     *
     * @return Collection<int, ScrapedEventDTO>
     */
    public function scrape(Source $source): Collection
    {
        $events = collect();
        $seenIds = [];
        $today = Carbon::today('Europe/Riga');

        $crawler = $this->fetchCrawler(self::BASE_URL);
        if (!$crawler) {
            Log::error('VisitJekabpilsScraper: Failed to fetch ' . self::BASE_URL);
            return $events;
        }

        $cards = $crawler->filter('.card-events');

        foreach ($cards as $cardElement) {
            try {
                $node = new Crawler($cardElement);

                // Extract title
                $titleNode = $node->filter('h4');
                if ($titleNode->count() === 0) {
                    continue;
                }
                $title = $this->cleanText($titleNode->text()) ?? '';
                if (empty($title)) {
                    continue;
                }

                // Extract link and external ID
                $linkNode = $node->filter('a.event-link');
                $href = $linkNode->count() > 0 ? trim($linkNode->attr('href') ?? '') : '';
                if (empty($href)) {
                    continue;
                }

                $sourceUrl = str_starts_with($href, 'http')
                    ? $href
                    : rtrim('https://visit.jekabpils.lv', '/') . '/' . ltrim($href, '/');

                // Extract date from URL path: /lv/visi-pasakumi/{YYYY-MM-DD}/{slug}/
                $urlDate = '';
                $urlSlug = '';
                if (preg_match('#/(\d{4}-\d{2}-\d{2})/([^/]+)#', $href, $urlMatch)) {
                    $urlDate = $urlMatch[1];
                    $urlSlug = trim($urlMatch[2], '-');
                }

                $externalId = !empty($urlDate) && !empty($urlSlug)
                    ? 'visitjekabpils-' . $urlDate . '-' . $urlSlug
                    : 'visitjekabpils-' . md5($href);

                if (isset($seenIds[$externalId])) {
                    continue;
                }

                // Extract times and date string
                $timesStr = $node->filter('.event-times')->count() > 0
                    ? trim($node->filter('.event-times')->text())
                    : '';

                [$startAt, $endAt] = $this->parseDates($timesStr, $urlDate, $today);

                if (!$startAt) {
                    continue;
                }

                // Strict rule: do NOT ingest or mutate past events
                $isPast = false;
                if ($endAt) {
                    if ($endAt->lt($today)) {
                        $isPast = true;
                    }
                } elseif ($startAt->lt($today)) {
                    $isPast = true;
                }

                if ($isPast) {
                    continue;
                }

                $seenIds[$externalId] = true;

                // Extract category button
                $catBtn = $node->filter('.event-cat-btn')->count() > 0
                    ? trim($node->filter('.event-cat-btn')->text())
                    : '';

                // Extract image
                $imageUrl = null;
                $imgNode = $node->filter('.event-image img');
                if ($imgNode->count() > 0) {
                    $imgSrc = trim($imgNode->attr('src') ?? '');
                    if (!empty($imgSrc) && !str_contains($imgSrc, 'placeholder')) {
                        $imageUrl = str_starts_with($imgSrc, 'http')
                            ? $imgSrc
                            : rtrim('https://visit.jekabpils.lv', '/') . '/' . ltrim($imgSrc, '/');
                    }
                }

                // Resolve location
                $locationData = $this->resolveLocation($title, $urlSlug);

                // Resolve categories and entertainment type strictly adhering to AGENTS.md
                $categories = $this->resolveCategories($title, $catBtn, $locationData['name']);
                $entertainmentType = $this->resolveEntertainmentType($categories, $title, $locationData['name']);

                // Parse price if any is mentioned in title
                $priceData = $this->parsePrice($title);

                // Fetch full description from detail page
                $detailDescription = $this->fetchDetailDescription($sourceUrl);
                $description = !empty($detailDescription) ? $detailDescription : $title;

                $dto = new ScrapedEventDTO(
                    title: $title,
                    startAt: $startAt,
                    endAt: $endAt,
                    description: $description,
                    shortDescription: \Illuminate\Support\Str::limit(strip_tags($description), 200),
                    venueName: $locationData['name'],
                    city: $locationData['city'],
                    region: $locationData['region'],
                    address: $locationData['address'],
                    latitude: $locationData['lat'],
                    longitude: $locationData['lng'],
                    placeType: $locationData['place_type'],
                    categoryNames: $categories,
                    entertainmentType: $entertainmentType,
                    isFree: $priceData['is_free'],
                    priceMin: $priceData['price_min'],
                    priceMax: $priceData['price_max'],
                    currency: 'EUR',
                    ticketUrl: null,
                    imageUrl: $imageUrl,
                    sourceUrl: $sourceUrl,
                    sourceExternalId: $externalId,
                    locale: 'lv',
                    rawData: [
                        'title' => $title,
                        'raw_times' => $timesStr,
                        'category_btn' => $catBtn,
                        'link' => $href,
                        'image' => $imageUrl,
                    ]
                );

                $events->push($dto);
            } catch (\Throwable $e) {
                Log::warning('VisitJekabpilsScraper: Failed to parse event card: ' . $e->getMessage(), [
                    'url' => $href ?? null,
                ]);
            }
        }

        return $events;
    }

    /**
     * Parse date and time strings from card-events.
     * Examples:
     * - "24 Aug - 4 Dec | 10:00" with urlDate "2026-08-24"
     * - "15 Nov | 10:00" with urlDate "2026-11-15"
     * - "17 Apr | 11:00" with urlDate "2027-04-17"
     * - "20 Dec - 5 Jan | 18:00" with urlDate "2026-12-20"
     */
    public function parseDates(string $timesStr, string $urlDate, Carbon $today): array
    {
        $tz = 'Europe/Riga';
        $timesStr = trim(preg_replace('/[\s\xc2\xa0]+/u', ' ', $timesStr));

        $timePart = '';
        $datePart = $timesStr;
        if (str_contains($timesStr, '|')) {
            [$datePart, $timePart] = array_map('trim', explode('|', $timesStr, 2));
        }

        $startTime = '00:00';
        $endTime = null;
        if (preg_match('/(\d{1,2}:\d{2})(?:\s*-\s*(\d{1,2}:\d{2}))?/', $timePart, $tm)) {
            $startTime = $tm[1];
            if (isset($tm[2])) {
                $endTime = $tm[2];
            }
        }

        $startYear = (int) $today->format('Y');
        $startMonth = (int) $today->format('n');
        $startDay = (int) $today->format('j');
        if (!empty($urlDate) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $urlDate, $um)) {
            $startYear = (int) $um[1];
            $startMonth = (int) $um[2];
            $startDay = (int) $um[3];
        }

        $startAt = null;
        $endAt = null;

        // Date range: e.g. "24 Aug - 4 Dec" or "1 Nov - 27 Nov"
        if (preg_match('/^(\d{1,2})\s*([a-zāčēģīķļņšūž]+)\s*-\s*(\d{1,2})\s*([a-zāčēģīķļņšūž]+)/ui', $datePart, $rm)) {
            $sDay = (int) $rm[1];
            $sMonthStr = mb_strtolower(trim($rm[2]), 'UTF-8');
            $sMonth = $this->monthMap[$sMonthStr] ?? $startMonth;

            $eDay = (int) $rm[3];
            $eMonthStr = mb_strtolower(trim($rm[4]), 'UTF-8');
            $eMonth = $this->monthMap[$eMonthStr] ?? $sMonth;

            $endYear = ($eMonth < $sMonth) ? ($startYear + 1) : $startYear;

            [$sh, $sm] = explode(':', $startTime);
            $startAt = Carbon::create($startYear, $sMonth, $sDay, (int) $sh, (int) $sm, 0, $tz);
            $endAt = Carbon::create($endYear, $eMonth, $eDay, 23, 59, 59, $tz);
            if ($endTime) {
                [$eh, $em] = explode(':', $endTime);
                $endAt->setTime((int) $eh, (int) $em, 0);
            }
        }
        // Single date: e.g. "15 Nov"
        elseif (preg_match('/^(\d{1,2})\s*([a-zāčēģīķļņšūž]+)/ui', $datePart, $sm)) {
            $sDay = (int) $sm[1];
            $sMonthStr = mb_strtolower(trim($sm[2]), 'UTF-8');
            $sMonth = $this->monthMap[$sMonthStr] ?? $startMonth;

            [$sh, $sm] = explode(':', $startTime);
            $startAt = Carbon::create($startYear, $sMonth, $sDay, (int) $sh, (int) $sm, 0, $tz);
            if ($endTime) {
                [$eh, $em] = explode(':', $endTime);
                $endAt = $startAt->copy()->setTime((int) $eh, (int) $em, 0);
            }
        }
        // Fallback using urlDate if provided
        elseif (!empty($urlDate)) {
            [$sh, $sm] = explode(':', $startTime);
            $startAt = Carbon::createFromFormat('Y-m-d H:i', "{$urlDate} {$startTime}", $tz);
            if ($endTime) {
                [$eh, $em] = explode(':', $endTime);
                $endAt = $startAt->copy()->setTime((int) $eh, (int) $em, 0);
            }
        }

        return [$startAt, $endAt];
    }

    /**
     * Resolve location information from title and slug.
     */
    public function resolveLocation(string $title, string $slug = ''): array
    {
        $comb = mb_strtolower($title . ' ' . $slug, 'UTF-8');

        foreach ($this->venueMap as $venue) {
            foreach ($venue['match'] as $matchKeyword) {
                if (str_contains($comb, $matchKeyword)) {
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

        $default = $this->venueMap['jekabpils'];
        return [
            'name' => $default['name'],
            'city' => $default['city'],
            'region' => $default['region'],
            'address' => $default['address'],
            'place_type' => $default['place_type'],
            'lat' => $default['latitude'],
            'lng' => $default['longitude'],
        ];
    }

    /**
     * Resolve categories adhering strictly to AGENTS.md rules.
     */
    public function resolveCategories(string $title, string $catBtn = '', string $venue = ''): array
    {
        $t = mb_strtolower($title, 'UTF-8');
        $cb = mb_strtolower($catBtn, 'UTF-8');
        $v = mb_strtolower($venue, 'UTF-8');
        $comb = $t . ' ' . $cb . ' ' . $v;

        // 1. STRICT AGENTS.MD RULE: Cinema / Kino (Priority check)
        $kinoKeywords = [
            'filma', 'filmas', 'filmu', 'filmā', 'kinoseanss', 'filmas seanss',
            'spēlfilma', 'dokumentālā filma', 'animācijas filma', 'kinofestivāls',
            'kinoteātr', '3d cinema', 'kino seans', 'kinovakars',
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
            || str_contains($comb, 'traģikomēdija') || str_contains($comb, 'amatierteātr')
            || str_contains($comb, 'amatierteātru') || str_contains($comb, 'teātra draugu kopa')
            || str_contains($comb, 'tautas teātris');

        if ($isTheaterProduction) {
            $cats = ['Teātris'];
            if (str_contains($t, 'bērniem') || str_contains($t, 'ģimenēm')) {
                $cats[] = 'Bērniem';
            }
            return $cats;
        }

        // 3. Exhibitions
        if (
            str_contains($cb, 'izstādes') || str_contains($t, 'izstāde') || str_contains($t, 'izstādē')
            || str_contains($t, 'izstādes') || str_contains($t, 'personālizstāde') || str_contains($t, 'kopizstāde')
            || str_contains($t, 'gleznu') || str_contains($t, 'fotoizstāde') || str_contains($t, 'fotogrāfiju izstāde')
            || str_contains($t, 'ekspozīcija')
        ) {
            return ['Izstādes'];
        }

        // 4. Workshops / Masterclasses / Seminars
        if (
            str_contains($cb, 'meistarklases') || str_contains($cb, 'darbnīcas') || str_contains($t, 'meistarklas')
            || str_contains($t, 'meistarkal') || str_contains($t, 'darbnīca') || str_contains($t, 'laboratorija')
            || str_contains($t, 'nodarbība') || str_contains($t, 'seminārs') || str_contains($t, 'lekcija')
            || str_contains($t, 'prezentācija') || str_contains($t, 'saruna') || str_contains($t, 'viktorīna')
        ) {
            return ['Semināri'];
        }

        // 5. Sports / Active Recreation
        if (
            str_contains($cb, 'aktīvā atpūta') || str_contains($t, 'čempionāts') || str_contains($t, 'turnīrs')
            || str_contains($t, 'sports') || str_contains($t, 'sacensības') || str_contains($t, 'pārgājiens')
            || str_contains($t, 'velo') || str_contains($t, 'karting') || str_contains($t, 'makšķerēšan')
            || str_contains($t, 'ledus arēnā') || str_contains($t, 'soļu') || str_contains($t, 'brauciens')
        ) {
            return ['Sports'];
        }

        // 6. Music / Concerts
        $isMusic = str_contains($t, 'koncert') || str_contains($t, 'dziesm') || str_contains($t, 'mūzik')
            || str_contains($t, 'muzikāl') || str_contains($t, 'solokoncerts') || str_contains($t, 'koris')
            || str_contains($t, 'kora') || str_contains($t, 'orķestr') || str_contains($t, 'džez')
            || str_contains($t, 'jazz') || str_contains($t, 'opera') || str_contains($t, 'dzejkoncerts')
            || str_contains($t, 'balāde') || str_contains($t, 'ansambļ') || str_contains($t, 'dziedātāj')
            || str_contains($t, 'pianists') || str_contains($t, 'ģitār') || str_contains($t, 'grupa')
            || str_contains($t, 'balle') || str_contains($t, 'kamermūzik') || str_contains($t, 'lielkoncerts');

        if ($isMusic) {
            $cats = ['Mūzika'];
            if (str_contains($t, 'svētki') || str_contains($t, 'svētku') || str_contains($t, 'ziemassvētk') || str_contains($t, 'gadumij')) {
                $cats[] = 'Svētki';
            }
            if (str_contains($t, 'bērniem') || str_contains($t, 'ģimenēm')) {
                $cats[] = 'Bērniem';
            }
            return $cats;
        }

        // 7. Children / Family
        if (str_contains($t, 'bērniem') || str_contains($t, 'ģimenēm') || str_contains($t, 'pirmsskolas')) {
            return ['Bērniem'];
        }

        // 8. Festivities / Fairs
        if (
            str_contains($cb, 'tirdziņi') || str_contains($t, 'svētki') || str_contains($t, 'svētku')
            || str_contains($t, 'tirdziņš') || str_contains($t, 'tirgus') || str_contains($t, 'gadadiena')
            || str_contains($t, 'proklamēšan') || str_contains($t, 'valsts svētku') || str_contains($t, 'ziemassvētk')
            || str_contains($t, 'ieskandināšana') || str_contains($t, 'sarīkojums')
        ) {
            return ['Svētki'];
        }

        // 9. Fallback per AGENTS.md rule 3
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
            || str_contains($lower, 'brīva') || str_contains($lower, 'ieeja brīva')
        ) {
            return [
                'is_free' => true,
                'price_min' => 0.0,
                'price_max' => 0.0,
            ];
        }

        $priceMin = null;
        $priceMax = null;

        // Check for range with single currency symbol at the end (e.g. 5.00 - 15.00 € or 5-15 EUR)
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:-|līdz)\s*(\d+(?:[.,]\d+)?)\s*(?:€|eur)/ui', $priceStr, $rangeMatch)) {
            $p1 = (float) str_replace(',', '.', $rangeMatch[1]);
            $p2 = (float) str_replace(',', '.', $rangeMatch[2]);
            $priceMin = min($p1, $p2);
            $priceMax = max($p1, $p2);
        } elseif (preg_match_all('/(\d+(?:[.,]\d+)?)\s*(?:€|eur)/ui', $priceStr, $matches)) {
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

    protected array $detailDescriptionCache = [];

    /**
     * Fetch full description from event detail page
     */
    public function fetchDetailDescription(?string $url): ?string
    {
        if (empty($url) || !str_starts_with($url, 'http')) {
            return null;
        }

        if (isset($this->detailDescriptionCache[$url])) {
            return $this->detailDescriptionCache[$url];
        }

        $crawler = $this->fetchCrawler($url);
        if (!$crawler) {
            return null;
        }

        $paragraphs = [];
        $crawler->filter('.about-event .main-html-content p')->each(function (Crawler $p) use (&$paragraphs) {
            $t = $this->cleanText($p->text());
            if (!empty($t) && !in_array($t, $paragraphs, true)) {
                $paragraphs[] = $t;
            }
        });

        if (empty($paragraphs)) {
            $crawler->filter('.about-event .main-html-content, .about-event')->each(function (Crawler $p) use (&$paragraphs) {
                $t = $this->cleanText($p->text());
                if (!empty($t) && !in_array($t, $paragraphs, true)) {
                    $paragraphs[] = $t;
                }
            });
        }

        $description = !empty($paragraphs) ? implode("\n\n", $paragraphs) : null;
        $this->detailDescriptionCache[$url] = $description;

        return $description;
    }
}
