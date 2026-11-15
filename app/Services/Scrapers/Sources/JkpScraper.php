<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class JkpScraper extends BaseScraper
{
    public const BASE_URL = 'https://www.jkp.lv/sakums/kalendars/';
    public const AJAX_URL = 'https://www.jkp.lv/sakums/kalendars/?ajaxCalendar=1&em_ajax=1&mo=%d&yr=%d';

    /**
     * How many consecutive months to fetch ahead starting from the current month.
     */
    public int $monthsAhead = 6;

    /**
     * Known venue mappings across Jēkabpils novads.
     * All venues in Jēkabpils novads belong to region Latgale in the system.
     */
    protected array $venueMap = [
        'krustpils-kc' => [
            'match' => ['krustpils kultūras centrs', 'krustpils kc', 'krustpils kultūras centrā'],
            'name' => 'Krustpils kultūras centrs',
            'city' => 'Jēkabpils',
            'region' => 'Latgale',
            'address' => 'Rīgas iela 212, Jēkabpils, LV-5202',
            'place_type' => 'cultural_center',
            'latitude' => 56.5098,
            'longitude' => 25.8679,
        ],
        'krustpils-pils' => [
            'match' => ['krustpils pils', 'jēkabpils vēstures muzejs'],
            'name' => 'Krustpils pils',
            'city' => 'Jēkabpils',
            'region' => 'Latgale',
            'address' => 'Rīgas iela 216b, Jēkabpils, LV-5202',
            'place_type' => 'castle',
            'latitude' => 56.5106,
            'longitude' => 25.8698,
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
        'vipes-mazers' => [
            'match' => ['vīpes amatniecības centrs "māzers"', 'vīpes amatniecības centrs', 'māzers', 'vīpe', 'vīpē'],
            'name' => 'Vīpes amatniecības centrs "Māzers"',
            'city' => 'Vīpe',
            'region' => 'Latgale',
            'address' => 'Vīpe, Vīpes pagasts, Jēkabpils novads, LV-5225',
            'place_type' => 'cultural_center',
            'latitude' => 56.4678,
            'longitude' => 26.0464,
        ],
        'salas-tautas-nams' => [
            'match' => ['salas tautas nams', 'susējas iela', 'sala', 'salā'],
            'name' => 'Salas Tautas nams',
            'city' => 'Sala',
            'region' => 'Latgale',
            'address' => 'Susējas iela 9, Sala, Salas pagasts, Jēkabpils novads, LV-5230',
            'place_type' => 'cultural_center',
            'latitude' => 56.5050,
            'longitude' => 25.7640,
        ],
        'viesites-kc' => [
            'match' => ['viesītes kultūras centrs "sēlija"', 'viesītes kultūras centrs', 'viesītes kultūras centra', 'sēlija', 'viesīte', 'viesītē'],
            'name' => 'Viesītes kultūras centrs "Sēlija"',
            'city' => 'Viesīte',
            'region' => 'Latgale',
            'address' => 'Smilšu iela 2, Viesīte, Jēkabpils novads, LV-5237',
            'place_type' => 'cultural_center',
            'latitude' => 56.3468,
            'longitude' => 25.5562,
        ],
        'selpils-tautas-nams' => [
            'match' => ['sēlpils tautas nams', 'sēlpilī', 'sēlpils'],
            'name' => 'Sēlpils Tautas nams',
            'city' => 'Sēlija',
            'region' => 'Latgale',
            'address' => '"Daugavas", Sēlija, Sēlpils pagasts, Jēkabpils novads, LV-5232',
            'place_type' => 'cultural_center',
            'latitude' => 56.5502,
            'longitude' => 25.6881,
        ],
        'rubenes-tautas-nams' => [
            'match' => ['rubenes tautas nams', 'rubeņi', 'rubeņos', 'rubene'],
            'name' => 'Rubenes Tautas nams',
            'city' => 'Rubeņi',
            'region' => 'Latgale',
            'address' => '"Pārupes", Rubeņi, Rubenes pagasts, Jēkabpils novads, LV-5229',
            'place_type' => 'cultural_center',
            'latitude' => 56.1264,
            'longitude' => 26.0494,
        ],
        'lones-tautas-nams' => [
            'match' => ['lones tautas nams', 'lone', 'lonē', 'saukas pagasts'],
            'name' => 'Lones Tautas nams',
            'city' => 'Lone',
            'region' => 'Latgale',
            'address' => '"Doles", Lone, Saukas pagasts, Jēkabpils novads, LV-5224',
            'place_type' => 'cultural_center',
            'latitude' => 56.2572,
            'longitude' => 25.4982,
        ],
        'abelu-tautas-nams' => [
            'match' => ['ābeļu tautas nams', 'ābeļi', 'ābeļos', 'brodi'],
            'name' => 'Ābeļu Tautas nams',
            'city' => 'Brodi',
            'region' => 'Latgale',
            'address' => 'Brodi, Ābeļu pagasts, Jēkabpils novads, LV-5201',
            'place_type' => 'cultural_center',
            'latitude' => 56.4633,
            'longitude' => 25.9083,
        ],
        'garsenes-pils' => [
            'match' => ['gārsenes pils', 'gārsenes tautas nams', 'gārsene', 'gārsenē'],
            'name' => 'Gārsenes pils',
            'city' => 'Gārsene',
            'region' => 'Latgale',
            'address' => '"Labieši", Gārsene, Gārsenes pagasts, Jēkabpils novads, LV-5218',
            'place_type' => 'castle',
            'latitude' => 56.0991,
            'longitude' => 25.8105,
        ],
        'variesu-tautas-nams' => [
            'match' => ['variešu tautas nams', 'varieši', 'variešos'],
            'name' => 'Variešu Tautas nams',
            'city' => 'Varieši',
            'region' => 'Latgale',
            'address' => 'Varieši, Variešu pagasts, Jēkabpils novads, LV-5236',
            'place_type' => 'cultural_center',
            'latitude' => 56.5794,
            'longitude' => 25.9628,
        ],
        'birzu-tautas-nams' => [
            'match' => ['biržu tautas nams', 'birži', 'biržos'],
            'name' => 'Biržu Tautas nams',
            'city' => 'Birži',
            'region' => 'Latgale',
            'address' => 'Birži, Salas pagasts, Jēkabpils novads, LV-5215',
            'place_type' => 'cultural_center',
            'latitude' => 56.4022,
            'longitude' => 25.7950,
        ],
        'elksnu-tautas-nams' => [
            'match' => ['elkšņu tautas nams', 'elkšņu bibliotēka', 'elkšņi', 'elkšņos'],
            'name' => 'Elkšņu Tautas nams',
            'city' => 'Elkšņi',
            'region' => 'Latgale',
            'address' => 'Elkšņi, Elkšņu pagasts, Jēkabpils novads, LV-5217',
            'place_type' => 'cultural_center',
            'latitude' => 56.2167,
            'longitude' => 25.6167,
        ],
        'spungenu-tautas-nams' => [
            'match' => ['spuņģēnu tautas nams', 'spuņģēni', 'spuņģēnos'],
            'name' => 'Spuņģēnu Tautas nams',
            'city' => 'Spuņģēni',
            'region' => 'Latgale',
            'address' => 'Spuņģēni, Krustpils pagasts, Jēkabpils novads, LV-5202',
            'place_type' => 'cultural_center',
            'latitude' => 56.5333,
            'longitude' => 25.8833,
        ],
        'rites-tautas-nams' => [
            'match' => ['rites tautas nams', 'rīte', 'cīruļi'],
            'name' => 'Rites Tautas nams',
            'city' => 'Cīruļi',
            'region' => 'Latgale',
            'address' => 'Cīruļi, Rites pagasts, Jēkabpils novads, LV-5228',
            'place_type' => 'cultural_center',
            'latitude' => 56.1750,
            'longitude' => 25.4750,
        ],
        'atasiene-annas-sata' => [
            'match' => ['atašienes tautas nams', 'annas sāta', 'atašiene', 'atašienē'],
            'name' => 'Atašienes Tautas nams "Annas sāta"',
            'city' => 'Atašiene',
            'region' => 'Latgale',
            'address' => 'Liepu aleja 13, Atašiene, Atašienes pagasts, Jēkabpils novads, LV-5211',
            'place_type' => 'cultural_center',
            'latitude' => 56.5361,
            'longitude' => 26.3986,
        ],
        'mezares-tautas-nams' => [
            'match' => ['mežāres tautas nams', 'mežāre', 'mežārē'],
            'name' => 'Mežāres Tautas nams',
            'city' => 'Mežāre',
            'region' => 'Latgale',
            'address' => 'Mežāre, Mežāres pagasts, Jēkabpils novads, LV-5226',
            'place_type' => 'cultural_center',
            'latitude' => 56.5278,
            'longitude' => 26.2306,
        ],
        'dunavas-tautas-nams' => [
            'match' => ['dunavas tautas nams', 'dunava', 'dunavā'],
            'name' => 'Dunavas Tautas nams',
            'city' => 'Dunava',
            'region' => 'Latgale',
            'address' => 'Dunava, Dunavas pagasts, Jēkabpils novads, LV-5216',
            'place_type' => 'cultural_center',
            'latitude' => 56.1950,
            'longitude' => 26.2167,
        ],
        'dignajas-pamatskola' => [
            'match' => ['dignājas pamatskola', 'dignāja', 'dignājā'],
            'name' => 'Dignājas pamatskola',
            'city' => 'Dignāja',
            'region' => 'Latgale',
            'address' => 'Dignāja, Dignājas pagasts, Jēkabpils novads, LV-5219',
            'place_type' => 'venue',
            'latitude' => 56.3200,
            'longitude' => 26.1300,
        ],
        'selu-am-rume' => [
            'match' => ['sēļu amatniecības centrs "rūme"', 'amatniecības centrs "rūme"', 'rūme', 'zasa', 'zasā'],
            'name' => 'Sēļu amatniecības centrs "Rūme"',
            'city' => 'Zasa',
            'region' => 'Latgale',
            'address' => 'Zasa, Zasas pagasts, Jēkabpils novads, LV-5239',
            'place_type' => 'cultural_center',
            'latitude' => 56.2861,
            'longitude' => 25.9750,
        ],
        'asares-tautas-nams' => [
            'match' => ['asares tautas nams', 'asare', 'asarē'],
            'name' => 'Asares Tautas nams',
            'city' => 'Asare',
            'region' => 'Latgale',
            'address' => 'Asare, Asares pagasts, Jēkabpils novads, LV-5212',
            'place_type' => 'cultural_center',
            'latitude' => 56.1167,
            'longitude' => 25.8833,
        ],
        'jaunamuiza' => [
            'match' => ['jaunāmuiža', 'jaunāmuižā', 'pansionātā "jaunāmuiža"'],
            'name' => 'Pansionāts "Jaunāmuiža"',
            'city' => 'Jaunāmuiža',
            'region' => 'Latgale',
            'address' => 'Jaunāmuiža, Kūku pagasts, Jēkabpils novads, LV-5222',
            'place_type' => 'venue',
            'latitude' => 56.4800,
            'longitude' => 26.0100,
        ],
        'kuku-tautas-nams' => [
            'match' => ['kūku tautas nams', 'kūkas', 'zīlāni', 'zīlānos'],
            'name' => 'Kūku Tautas nams',
            'city' => 'Zīlāni',
            'region' => 'Latgale',
            'address' => 'Zīlāni, Kūku pagasts, Jēkabpils novads, LV-5222',
            'place_type' => 'cultural_center',
            'latitude' => 56.5100,
            'longitude' => 25.9300,
        ],
        'leimanu-tautas-nams' => [
            'match' => ['leimaņu tautas nams', 'leimaņi', 'mežgale'],
            'name' => 'Leimaņu Tautas nams',
            'city' => 'Mežgale',
            'region' => 'Latgale',
            'address' => 'Mežgale, Leimaņu pagasts, Jēkabpils novads, LV-5223',
            'place_type' => 'cultural_center',
            'latitude' => 56.2833,
            'longitude' => 25.8500,
        ],
        'jekabpils-default' => [
            'match' => ['jēkabpils', 'jekabpils', 'vecpilsētas'],
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
        return 'jkp';
    }

    public function getName(): string
    {
        return 'Jēkabpils Kultūras pārvalde';
    }

    /**
     * Scrape events from jkp.lv calendar month-by-month.
     * All detailed modal contents are embedded directly in the monthly calendar AJAX responses.
     * No crawling into individual detail pages is performed.
     *
     * @return Collection<int, ScrapedEventDTO>
     */
    public function scrape(Source $source): Collection
    {
        $events = collect();
        $seenIds = [];
        $today = Carbon::today('Europe/Riga');
        $startCursor = Carbon::now('Europe/Riga')->startOfMonth();

        for ($offset = 0; $offset < $this->monthsAhead; $offset++) {
            $monthDate = $startCursor->copy()->addMonths($offset);
            $month = (int) $monthDate->format('n');
            $year = (int) $monthDate->format('Y');

            $monthUrl = sprintf(self::AJAX_URL, $month, $year);
            $crawler = $this->fetchCrawler($monthUrl);

            if (!$crawler) {
                Log::warning("JkpScraper: Failed to fetch calendar for {$year}-{$month}");
                continue;
            }

            $eventNodes = $crawler->filter('.em-cal-event-content');

            foreach ($eventNodes as $eventNode) {
                try {
                    $node = new Crawler($eventNode);
                    $eventId = trim($node->attr('data-event-id') ?? '');

                    if (empty($eventId) || isset($seenIds[$eventId])) {
                        continue;
                    }

                    // Extract category from modal taxonomy
                    $rawCategories = '';
                    if ($node->filter('.em-event-categories')->count() > 0) {
                        $rawCategories = trim($node->filter('.em-event-categories')->text());
                    }

                    // Strict filtering: items tagged as 'Aktualitātes' without location are news articles, skip
                    $rawLocation = '';
                    if ($node->filter('.em-event-location')->count() > 0) {
                        $rawLocation = trim($node->filter('.em-event-location')->text());
                    }

                    if (str_contains($rawCategories, 'Aktualitātes') && empty($rawLocation)) {
                        continue;
                    }

                    // Extract title
                    $titleNode = $node->filter('.em-modal-title a');
                    if ($titleNode->count() === 0) {
                        continue;
                    }
                    $title = $this->cleanText($titleNode->text()) ?? '';
                    if (empty($title)) {
                        continue;
                    }

                    // Extract URL
                    $url = $titleNode->attr('href');
                    if (empty($url) && $node->filter('.em-item-read-more')->count() > 0) {
                        $url = $node->filter('.em-item-read-more')->attr('href');
                    }
                    if (!empty($url) && !str_starts_with($url, 'http')) {
                        $url = rtrim('https://www.jkp.lv', '/') . '/' . ltrim($url, '/');
                    }
                    if (empty($url)) {
                        $url = self::BASE_URL;
                    }

                    // Extract dates & times
                    $dateStr = $node->filter('.em-event-date')->count() > 0
                        ? trim($node->filter('.em-event-date')->text())
                        : '';
                    $timeStr = $node->filter('.em-event-time')->count() > 0
                        ? trim($node->filter('.em-event-time')->text())
                        : '';

                    [$startAt, $endAt] = $this->parseDates($dateStr, $timeStr, $today, $year, $month);

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

                    // Mark as seen to avoid duplicates across months
                    $seenIds[$eventId] = true;

                    // Extract description
                    $description = null;
                    if ($node->filter('.em-item-desc')->count() > 0) {
                        $description = $this->cleanText($node->filter('.em-item-desc')->text());
                    }
                    $shortDescription = $description ? mb_substr($description, 0, 250) : null;

                    // Extract image
                    $imageUrl = null;
                    if ($node->filter('.em-item-image img')->count() > 0) {
                        $imgSrc = $node->filter('.em-item-image img')->attr('src');
                        if (!empty($imgSrc) && !str_contains($imgSrc, 'placeholder')) {
                            $imageUrl = str_starts_with($imgSrc, 'http')
                                ? $imgSrc
                                : rtrim('https://www.jkp.lv', '/') . '/' . ltrim($imgSrc, '/');
                        }
                    }

                    // Resolve location
                    $locationData = $this->resolveLocation($rawLocation, $description);

                    // Resolve categories & entertainment type strictly per AGENTS.md
                    $categories = $this->resolveCategories($title, $locationData['name'], $rawCategories);
                    $entertainmentType = $this->resolveEntertainmentType($categories, $title, $locationData['name']);

                    // Parse price
                    $priceData = $this->parsePrice($description ?? '');

                    // Check for external ticketing links in description
                    $ticketUrl = null;
                    if ($description) {
                        if (preg_match('/(https?:\/\/(?:www\.)?(?:bilesuparadize\.lv|bilesuserviss\.lv|bezrindas\.lv|passportix\.eu|fienta\.com)[^\s"\'<>]+)/i', $description, $ticketMatch)) {
                            $ticketUrl = $ticketMatch[1];
                        }
                    }

                    $dto = new ScrapedEventDTO(
                        title: $title,
                        startAt: $startAt,
                        endAt: $endAt,
                        description: $description,
                        shortDescription: $shortDescription,
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
                        ticketUrl: $ticketUrl,
                        imageUrl: $imageUrl,
                        sourceUrl: $url,
                        sourceExternalId: 'jkp-' . $eventId,
                        locale: 'lv',
                        rawData: [
                            'event_id' => $eventId,
                            'raw_date' => $dateStr,
                            'raw_time' => $timeStr,
                            'raw_location' => $rawLocation,
                            'raw_categories' => $rawCategories,
                        ]
                    );

                    $events->push($dto);
                } catch (\Throwable $e) {
                    Log::warning('JkpScraper: Failed to parse event node: ' . $e->getMessage(), [
                        'eventId' => $eventId ?? null,
                    ]);
                }
            }
        }

        return $events;
    }

    /**
     * Parse date and time strings into [Carbon startAt, ?Carbon endAt].
     */
    public function parseDates(
        string $dateStr,
        string $timeStr,
        Carbon $today,
        int $contextYear = 2026,
        int $contextMonth = 11
    ): array {
        $tz = 'Europe/Riga';
        $dateStr = trim(preg_replace('/[\s\xc2\xa0]+/u', ' ', $dateStr));
        $timeStr = trim(preg_replace('/[\s\xc2\xa0]+/u', ' ', $timeStr));

        // Time extraction: |plkst. 19.00 or 19.00 - 21.00 or 19:00
        $startTime = null;
        $endTime = null;
        if (preg_match('/(\d{1,2})[.:](\d{2})(?:\s*-\s*(\d{1,2})[.:](\d{2}))?/', $timeStr, $tm)) {
            $startTime = sprintf('%02d:%02d', (int) $tm[1], (int) $tm[2]);
            if (isset($tm[3], $tm[4])) {
                $endTime = sprintf('%02d:%02d', (int) $tm[3], (int) $tm[4]);
            }
        }

        $startAt = null;
        $endAt = null;

        // Case 1: Date range with full years: DD.MM.YYYY - DD.MM.YYYY
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})\s*-\s*(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $dateStr, $m)) {
            $sY = (int) $m[3]; $sM = (int) $m[2]; $sD = (int) $m[1];
            $eY = (int) $m[6]; $eM = (int) $m[5]; $eD = (int) $m[4];
            $startAt = Carbon::create($sY, $sM, $sD, 0, 0, 0, $tz);
            $endAt = Carbon::create($eY, $eM, $eD, 23, 59, 59, $tz);
        }
        // Case 2: Date range with end year only: DD.MM - DD.MM.YYYY
        elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\s*-\s*(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $dateStr, $m)) {
            $eY = (int) $m[5]; $eM = (int) $m[4]; $eD = (int) $m[3];
            $sM = (int) $m[2]; $sD = (int) $m[1];
            $sY = ($eM < $sM) ? ($eY - 1) : $eY;
            $startAt = Carbon::create($sY, $sM, $sD, 0, 0, 0, $tz);
            $endAt = Carbon::create($eY, $eM, $eD, 23, 59, 59, $tz);
        }
        // Case 3: Date range without year: DD.MM - DD.MM
        elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\s*-\s*(\d{1,2})\.(\d{1,2})$/', $dateStr, $m)) {
            $sD = (int) $m[1]; $sM = (int) $m[2];
            $eD = (int) $m[3]; $eM = (int) $m[4];

            $sY = $contextYear;
            $eY = $contextYear;

            if ($eM < $sM) {
                if ($contextMonth >= 10) {
                    $eY = $contextYear + 1;
                } else {
                    $sY = $contextYear - 1;
                }
            } elseif ($contextMonth <= 2 && $sM >= 11) {
                $sY = $contextYear - 1;
                $eY = $contextYear - 1;
            }

            $startAt = Carbon::create($sY, $sM, $sD, 0, 0, 0, $tz);
            $endAt = Carbon::create($eY, $eM, $eD, 23, 59, 59, $tz);
        }
        // Case 4: Single date with year: DD.MM.YYYY
        elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $dateStr, $m)) {
            $sD = (int) $m[1]; $sM = (int) $m[2]; $sY = (int) $m[3];
            $startAt = Carbon::create($sY, $sM, $sD, 0, 0, 0, $tz);
        }
        // Case 5: Single date without year: DD.MM
        elseif (preg_match('/^(\d{1,2})\.(\d{1,2})$/', $dateStr, $m)) {
            $sD = (int) $m[1]; $sM = (int) $m[2]; $sY = $contextYear;
            if ($contextMonth <= 2 && $sM >= 11) {
                $sY = $contextYear - 1;
            }
            $startAt = Carbon::create($sY, $sM, $sD, 0, 0, 0, $tz);
        }

        if ($startAt) {
            if ($startTime && $startTime !== '00:00') {
                [$h, $min] = explode(':', $startTime);
                $startAt->setTime((int) $h, (int) $min, 0);
            }

            if ($endAt) {
                if ($endTime) {
                    [$eh, $emin] = explode(':', $endTime);
                    $endAt->setTime((int) $eh, (int) $emin, 0);
                }
            } elseif ($endTime) {
                [$eh, $emin] = explode(':', $endTime);
                $endAt = $startAt->copy()->setTime((int) $eh, (int) $emin, 0);
            }
        }

        return [$startAt, $endAt];
    }

    /**
     * Resolve location information from raw location string and description.
     */
    public function resolveLocation(string $rawLocation, ?string $description = null): array
    {
        $locLower = mb_strtolower(trim($rawLocation), 'UTF-8');

        // 1. Try matching against explicit location field first
        if (!empty($locLower)) {
            foreach ($this->venueMap as $venue) {
                foreach ($venue['match'] as $matchKeyword) {
                    if (str_contains($locLower, $matchKeyword)) {
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
        }

        // 2. Fall back to scanning description for known venues if location field is empty or generic
        if ($description) {
            $descLower = mb_strtolower($description, 'UTF-8');
            foreach ($this->venueMap as $key => $venue) {
                // Don't match generic fallback keyword 'jēkabpils' from description
                if ($key === 'jekabpils-default') {
                    continue;
                }
                foreach ($venue['match'] as $matchKeyword) {
                    if (str_contains($descLower, $matchKeyword)) {
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
        }

        return [
            'name' => !empty($rawLocation) ? $rawLocation : 'Jēkabpils',
            'city' => 'Jēkabpils',
            'region' => 'Latgale',
            'address' => 'Brīvības iela 120, Jēkabpils, LV-5201',
            'place_type' => 'cultural_center',
            'lat' => 56.4988,
            'lng' => 25.8714,
        ];
    }

    /**
     * Resolve categories adhering strictly to AGENTS.md rules.
     */
    public function resolveCategories(string $title, string $venue, ?string $emCategories = null): array
    {
        $t = mb_strtolower($title, 'UTF-8');
        $v = mb_strtolower($venue, 'UTF-8');
        $comb = $t . ' ' . $v;

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
            str_contains($t, 'izstāde') || str_contains($t, 'izstādē') || str_contains($t, 'izstādes')
            || str_contains($t, 'personālizstāde') || str_contains($t, 'kopizstāde') || str_contains($t, 'gleznu')
            || str_contains($t, 'fotoizstāde') || str_contains($t, 'fotogrāfiju izstāde')
            || str_contains($t, 'ekspozīcija') || str_contains($t, 'apskatāms') || str_contains($t, 'apskatāma')
        ) {
            return ['Izstādes'];
        }

        // 4. Workshops / Seminars / Education / Tournaments
        if (
            str_contains($t, 'meistarklas') || str_contains($t, 'meistarkal') || str_contains($t, 'darbnīca')
            || str_contains($t, 'laboratorija') || str_contains($t, 'nodarbība') || str_contains($t, 'seminārs')
            || str_contains($t, 'lekcija') || str_contains($t, 'prezentācija') || str_contains($t, 'saruna')
            || str_contains($t, 'konkurss') || str_contains($t, 'viktorīna')
        ) {
            return ['Semināri'];
        }

        // 5. Sports / Games
        if (
            str_contains($t, 'čempionāts') || str_contains($t, 'turnīrs') || str_contains($t, 'zolītes')
            || str_contains($t, 'sports') || str_contains($t, 'sacensības')
        ) {
            return ['Sports'];
        }

        // 6. Music / Concerts
        $isMusic = str_contains($t, 'koncert') || str_contains($t, 'dziesm') || str_contains($t, 'mūzik')
            || str_contains($t, 'muzikāl') || str_contains($t, 'solokoncerts') || str_contains($t, 'koris')
            || str_contains($t, 'orķestr') || str_contains($t, 'džez') || str_contains($t, 'jazz')
            || str_contains($t, 'opera') || str_contains($t, 'dzejkoncerts') || str_contains($t, 'balāde')
            || str_contains($t, 'ansambļ') || str_contains($t, 'dziedātāj') || str_contains($t, 'pianists')
            || str_contains($t, 'ģitār') || str_contains($t, 'grupa') || str_contains($t, 'balle')
            || str_contains($t, 'kamermūzik') || str_contains($t, 'lielkoncerts');

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
            str_contains($t, 'svētki') || str_contains($t, 'svētku') || str_contains($t, 'tirdziņš')
            || str_contains($t, 'tirgus') || str_contains($t, 'vakartirgū') || str_contains($t, 'vakartirgus')
            || str_contains($t, 'egles iedegšana') || str_contains($t, 'eglītes iedegšana')
            || str_contains($t, 'gadumija') || str_contains($t, 'piemiņas') || str_contains($t, 'sarīkojums')
            || str_contains($t, 'lāčplēša') || str_contains($t, 'leģendu nakts') || str_contains($t, 'proklamēšan')
            || str_contains($t, 'ziemassvētk')
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
}
