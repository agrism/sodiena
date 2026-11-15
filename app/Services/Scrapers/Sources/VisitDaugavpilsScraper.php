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

class VisitDaugavpilsScraper extends BaseScraper
{
    /**
     * Map of known Daugavpils venues to normalized names, addresses, and place types
     */
    protected array $venueMap = [
        'rotko' => [
            'name' => 'Rotko muzejs',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Mihaila iela 3, Daugavpils, LV-5401',
            'place_type' => 'museum',
        ],
        'martinsona māja' => [
            'name' => 'Rotko muzeja Martinsona māja',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Mihaila iela 3, Daugavpils, LV-5401',
            'place_type' => 'museum',
        ],
        'daugavpils teātr' => [
            'name' => 'Daugavpils teātris',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Rīgas iela 22A, Daugavpils, LV-5401',
            'place_type' => 'theatre',
        ],
        'vienības nam' => [
            'name' => 'Daugavpils Vienības nams',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Rīgas iela 22A, Daugavpils, LV-5401',
            'place_type' => 'cultural_center',
        ],
        'tradīciju māj' => [
            'name' => 'Daugavpils Vienības nama Tradīciju māja',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Rīgas iela 22A, Daugavpils, LV-5401',
            'place_type' => 'cultural_center',
        ],
        'mediju zāle' => [
            'name' => 'Daugavpils Vienības nama Mediju zāle',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Rīgas iela 22A, Daugavpils, LV-5401',
            'place_type' => 'cultural_center',
        ],
        'ledus hall' => [
            'name' => 'Daugavpils Ledus halle',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Stacijas iela 45A, Daugavpils, LV-5401',
            'place_type' => 'sports_complex',
        ],
        'olimpiskais centrs' => [
            'name' => 'Daugavpils Olimpiskais centrs',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Stadiona iela 1, Daugavpils, LV-5401',
            'place_type' => 'sports_complex',
        ],
        'sporta skol' => [
            'name' => 'Daugavpils Sporta skola',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Kandavas iela 17A, Daugavpils, LV-5401',
            'place_type' => 'sports_complex',
        ],
        'kultūras pil' => [
            'name' => 'Daugavpils Kultūras pils',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Smilšu iela 92, Daugavpils, LV-5410',
            'place_type' => 'cultural_center',
        ],
        'park hotel latgola' => [
            'name' => 'Viesnīca "Park Hotel Latgola"',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Ģimnāzijas iela 46, Daugavpils, LV-5401',
            'place_type' => 'hotel',
        ],
        'latgola' => [
            'name' => 'Viesnīca "Park Hotel Latgola"',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Ģimnāzijas iela 46, Daugavpils, LV-5401',
            'place_type' => 'hotel',
        ],
        'centrālajā bibliotēk' => [
            'name' => 'Latgales Centrālā bibliotēka',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Rīgas iela 22A, Daugavpils, LV-5401',
            'place_type' => 'library',
        ],
        'arka' => [
            'name' => 'Mākslas centrs "Arka"',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Cietokšņa iela 28, Daugavpils, LV-5401',
            'place_type' => 'gallery',
        ],
        'new east' => [
            'name' => 'Kultūrtelpa "New East"',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => '18. novembra iela 41a, Daugavpils, LV-5401',
            'place_type' => 'cultural_center',
        ],
        '4metri' => [
            'name' => 'Galerija "4METRI"',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Hospitāļa iela 8, Daugavpils cietoksnis, LV-5401',
            'place_type' => 'gallery',
        ],
        'cietoksn' => [
            'name' => 'Daugavpils cietoksnis',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Nikolaja iela 5, Daugavpils, LV-5401',
            'place_type' => 'landmark',
        ],
        'inženieru arsenāl' => [
            'name' => 'Daugavpils Inženieru arsenāls',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Imperatora iela 8, Daugavpils, LV-5401',
            'place_type' => 'museum',
        ],
        'novadpētniecības' => [
            'name' => 'Daugavpils Novadpētniecības un mākslas muzejs',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Rīgas iela 8, Daugavpils, LV-5401',
            'place_type' => 'museum',
        ],
        'jaunbūves kultūrtelp' => [
            'name' => 'Jaunbūves kultūrtelpa',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Varšavas iela 14, Daugavpils, LV-5404',
            'place_type' => 'cultural_center',
        ],
        'forštates kultūrtelp' => [
            'name' => 'Forštates kultūrtelpa',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Vidzemes iela 41A, Daugavpils, LV-5422',
            'place_type' => 'cultural_center',
        ],
        'grīvas kultūrtelp' => [
            'name' => 'Grīvas kultūrtelpa',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Komunālā iela 2, Daugavpils, LV-5408',
            'place_type' => 'cultural_center',
        ],
        'lama dazz' => [
            'name' => 'Tekstila un mākslas studija "Lama Dazz"',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Vienības iela 17, Daugavpils, LV-5401',
            'place_type' => 'venue',
        ],
        'latgales mācību centrs' => [
            'name' => 'Latgales mācību centrs',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Saules iela 38, Daugavpils, LV-5401',
            'place_type' => 'school',
        ],
        'saules skol' => [
            'name' => 'Mākslu izglītības kompetences centrs "Saules skola"',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Saules iela 1/3, Daugavpils, LV-5401',
            'place_type' => 'school',
        ],
        'daugavpils universitāt' => [
            'name' => 'Daugavpils Universitāte',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Vienības iela 13, Daugavpils, LV-5401',
            'place_type' => 'school',
        ],
        'ditton' => [
            'name' => 'Tirdzniecības centrs "Ditton"',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Cietokšņa iela 60, Daugavpils, LV-5401',
            'place_type' => 'commercial_venue',
        ],
        'raiņa māj' => [
            'name' => 'Raiņa māja Berķenelē',
            'city' => 'Berķenele',
            'region' => 'Latgale',
            'address' => 'Birkineļi, Kalkūnes pagasts, LV-5449',
            'place_type' => 'museum',
        ],
        'skrindu' => [
            'name' => 'Skrindu dzimtas muzejs',
            'city' => 'Vabole',
            'region' => 'Latgale',
            'address' => 'Vabole, Vaboles pagasts, LV-5477',
            'place_type' => 'museum',
        ],
        'zīlīte' => [
            'name' => 'Bērnu bibliotēka "Zīlīte"',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Mihoelsa iela 58, Daugavpils, LV-5401',
            'place_type' => 'library',
        ],
        'rīgas iel' => [
            'name' => 'Rīgas iela',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Rīgas iela, Daugavpils, LV-5401',
            'place_type' => 'square',
        ],
        'vienības laukum' => [
            'name' => 'Vienības laukums',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Vienības laukums, Daugavpils, LV-5401',
            'place_type' => 'square',
        ],
    ];

    public function getSlug(): string
    {
        return 'visitdaugavpils';
    }

    public function getName(): string
    {
        return 'Visit Daugavpils';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $baseUrl = $source->url ?: 'https://www.visitdaugavpils.lv/pasakumi/';
        $today = Carbon::today('Europe/Riga');
        $maxPages = 15;
        $seenExternalIds = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $pageUrl = $page === 1 ? $baseUrl : rtrim($baseUrl, '/') . '/?pno=' . $page;
            $crawler = $this->fetchCrawler($pageUrl);

            if (!$crawler) {
                Log::warning("VisitDaugavpilsScraper: Failed to fetch page {$pageUrl}");
                break;
            }

            $items = $crawler->filter('.em-events-list .grid-100.grid-parent');
            if ($items->count() === 0) {
                break;
            }

            $pageNewEvents = 0;

            $items->each(function (Crawler $item) use (&$events, &$seenExternalIds, &$pageNewEvents, $today) {
                $titleNode = $item->filter('.objekta-nosaukums a, .objekta-nosaukums');
                if (!$titleNode->count()) {
                    return;
                }

                $title = $this->cleanText($titleNode->text());
                if (empty($title) || $this->isNonEvent($title)) {
                    return;
                }

                $eventUrl = $titleNode->nodeName() === 'a' ? $titleNode->attr('href') : $item->filter('a')->attr('href');
                $cleanUrl = $this->normalizeUrl($eventUrl);

                $slug = basename(rtrim(parse_url($cleanUrl, PHP_URL_PATH), '/'));
                $externalId = 'visitdaugavpils-' . $slug;

                if (isset($seenExternalIds[$externalId])) {
                    return;
                }

                // Date and time
                $dateText = $item->filter('.pasakumatext b')->count() ? trim($item->filter('.pasakumatext b')->text()) : '';
                $timeText = $item->filter('.pasakumalaiks')->count() ? trim($item->filter('.pasakumalaiks')->text()) : '';

                [$startAt, $endAt] = $this->parseDates($dateText, $timeText, $today);
                if (!$startAt) {
                    return;
                }

                // Strict AGENTS.md rule: Do not process past events
                if ($endAt && $endAt->lt($today)) {
                    return;
                }
                if (!$endAt && $startAt->lt($today)) {
                    return;
                }

                // Description
                $descNode = $item->filter('.grid-75 p');
                $description = $descNode->count() ? $this->cleanText($descNode->text()) : $title;
                if (empty($description)) {
                    $description = $title;
                }

                // Location resolving
                $locationMeta = $this->resolveLocationMeta($title, $description);

                // Image URL
                $imgNode = $item->filter('img.pasakumaimage');
                $imageUrl = $imgNode->count() ? $imgNode->attr('src') : null;
                if ($imageUrl && !str_starts_with($imageUrl, 'http://') && !str_starts_with($imageUrl, 'https://')) {
                    $imageUrl = 'https://www.visitdaugavpils.lv' . (str_starts_with($imageUrl, '/') ? '' : '/') . $imageUrl;
                }

                // Categories & Entertainment Type (strictly adhering to AGENTS.md)
                $categories = $this->resolveCategories($title, $description, $locationMeta['name']);
                $entertainmentType = $this->resolveEntertainmentType($categories, $title, $description);

                // Free event detection
                $isFree = false;
                $fullLower = mb_strtolower($title . ' ' . $description, 'UTF-8');
                if (str_contains($fullLower, 'bezmaksas') || str_contains($fullLower, 'ieeja brīva') || str_contains($fullLower, 'brīva ieeja')) {
                    $isFree = true;
                }

                $dto = new ScrapedEventDTO(
                    title: $title,
                    startAt: $startAt,
                    endAt: $endAt,
                    description: $description,
                    shortDescription: Str::limit($description, 160),
                    venueName: $locationMeta['name'],
                    city: $locationMeta['city'],
                    region: $locationMeta['region'],
                    address: $locationMeta['address'],
                    placeType: $locationMeta['place_type'],
                    categoryNames: $categories,
                    entertainmentType: $entertainmentType,
                    isFree: $isFree,
                    priceMin: $isFree ? 0.0 : null,
                    priceMax: $isFree ? 0.0 : null,
                    currency: 'EUR',
                    ticketUrl: $cleanUrl,
                    imageUrl: $imageUrl,
                    sourceUrl: $cleanUrl,
                    sourceExternalId: $externalId,
                    locale: 'lv',
                    rawData: [
                        'date_text' => $dateText,
                        'time_text' => $timeText,
                    ]
                );

                $seenExternalIds[$externalId] = true;
                $events->push($dto);
                $pageNewEvents++;
            });

            // If no next button exists or no new events on this page, stop pagination
            $nextLink = $crawler->filter('.em-pagination a.next');
            if ($nextLink->count() === 0 || $pageNewEvents === 0) {
                break;
            }
        }

        Log::info("VisitDaugavpilsScraper: Scraped {$events->count()} events across pages.");

        return $events;
    }

    /**
     * Parse date and time from visitdaugavpils card
     */
    public function parseDates(string $dateText, string $timeText = '', ?Carbon $today = null): array
    {
        $today = $today ?: Carbon::today('Europe/Riga');
        $dateText = trim($dateText);
        $timeText = trim($timeText);

        if (empty($dateText)) {
            return [null, null];
        }

        // 1. Date range: "06.11.2026 - 31.01.2027" or "01.01.2023 - 31.12.2026"
        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})\s*[-–—]\s*(\d{1,2})\.(\d{1,2})\.(\d{4})/', $dateText, $m)) {
            $startAt = Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1], 'Europe/Riga')->startOfDay();
            $endAt = Carbon::createFromDate((int) $m[6], (int) $m[5], (int) $m[4], 'Europe/Riga')->endOfDay();

            return [$startAt, $endAt];
        }

        // 2. Single date: "21.11.2026"
        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/', $dateText, $m)) {
            $year = (int) $m[3];
            $month = (int) $m[2];
            $day = (int) $m[1];

            // Parse time: "09:00 - 15:00"
            if (preg_match('/(\d{1,2})[\.:](\d{2})\s*[-–—]\s*(\d{1,2})[\.:](\d{2})/', $timeText, $tm)) {
                $startAt = Carbon::createFromDate($year, $month, $day, 'Europe/Riga')->setTime((int) $tm[1], (int) $tm[2], 0);
                $endAt = Carbon::createFromDate($year, $month, $day, 'Europe/Riga')->setTime((int) $tm[3], (int) $tm[4], 0);

                if ($endAt->lessThan($startAt)) {
                    $endAt->addDay();
                }

                return [$startAt, $endAt];
            }

            // Single time: "18:00"
            if (preg_match('/(\d{1,2})[\.:](\d{2})/', $timeText, $tm)) {
                $startAt = Carbon::createFromDate($year, $month, $day, 'Europe/Riga')->setTime((int) $tm[1], (int) $tm[2], 0);

                return [$startAt, null];
            }

            // No time specified -> default to midnight start of day
            $startAt = Carbon::createFromDate($year, $month, $day, 'Europe/Riga')->startOfDay();

            return [$startAt, null];
        }

        return [null, null];
    }

    /**
     * Resolve location, address and place type from title and description
     */
    public function resolveLocationMeta(string $title, string $description = ''): array
    {
        $haystack = mb_strtolower($title . ' ' . $description, 'UTF-8');

        foreach ($this->venueMap as $key => $known) {
            if (str_contains($haystack, $key)) {
                return $known;
            }
        }

        // Default Daugavpils location
        return [
            'name' => 'Daugavpils',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Daugavpils, Latgale',
            'place_type' => 'venue',
        ];
    }

    /**
     * Categories resolution adhering strictly to AGENTS.md domain rules
     */
    public function resolveCategories(string $title, string $description = '', string $venueName = ''): array
    {
        $cats = [];
        $titleLower = mb_strtolower($title, 'UTF-8');
        $descLower = mb_strtolower($description, 'UTF-8');
        $venueLower = mb_strtolower($venueName, 'UTF-8');
        $fullText = $titleLower . ' ' . $descLower . ' ' . $venueLower;

        // 1. Strict Cinema / Kino Check (Priority Rule from AGENTS.md)
        $isCinema = (
            str_contains($venueLower, 'kino') || str_contains($venueLower, 'silverscreen') ||
            str_contains($fullText, 'filma') || str_contains($fullText, 'filmas') ||
            str_contains($fullText, 'filmu') || str_contains($fullText, 'filmā') ||
            str_contains($fullText, 'kinoseans') || str_contains($fullText, 'filmas seans') ||
            str_contains($fullText, 'spēlfilma') || str_contains($fullText, 'dokumentālā filma') ||
            str_contains($fullText, 'animācijas filma') || str_contains($fullText, 'kinofestivāl')
        );

        if ($isCinema) {
            $cats[] = 'Kino';
        }

        // 2. Exhibitions
        if (
            str_contains($fullText, 'izstād') || str_contains($fullText, 'ekspozīcij') ||
            str_contains($fullText, 'glezn') || str_contains($fullText, 'stājglezniecīb') ||
            str_contains($fullText, 'keramik') || str_contains($fullText, 'fotogrāfij') ||
            str_contains($fullText, 'akvareļ') || str_contains($fullText, 'tēlotājmāksl') ||
            str_contains($fullText, 'skulptūr')
        ) {
            $cats[] = 'Izstādes';
        }

        // 3. Theater Productions (NEVER cinema/film)
        $isTheatreMention = str_contains($fullText, 'daugavpils teātr') || str_contains($fullText, 'leļļu teātr') ||
            (str_contains($fullText, 'teātr') && !str_contains($fullText, 'kinoteātr'));
        $isTheatreContent = str_contains($titleLower, 'izrād') || str_contains($titleLower, 'luga') ||
            str_contains($titleLower, 'iestudējum') || str_contains($titleLower, 'stāvizrād') ||
            str_contains($titleLower, 'stand-up') || str_contains($titleLower, 'komēdij') ||
            str_contains($titleLower, 'monoizrād') || str_contains($titleLower, 'monodrām') ||
            str_contains($titleLower, 'opera') || str_contains($titleLower, 'balet');

        if (!$isCinema && !str_contains($fullText, 'kinoteātr') && ($isTheatreMention || $isTheatreContent)) {
            $cats[] = 'Teātris';
        }

        // 4. Music / Concerts
        if (
            str_contains($fullText, 'koncert') || str_contains($fullText, 'dziesm') ||
            str_contains($fullText, 'orķestr') || str_contains($fullText, 'koris') ||
            str_contains($fullText, 'džez') || str_contains($fullText, 'muzikāl') ||
            str_contains($fullText, 'koklē') || str_contains($fullText, 'zvani') ||
            str_contains($fullText, 'trio') || str_contains($fullText, 'ansambl') ||
            str_contains($fullText, 'dziedāšan') || str_contains($fullText, 'solokoncert') ||
            str_contains($fullText, 'kamermūzik') || str_contains($fullText, 'muzikālā banka') ||
            str_contains($fullText, 'flamenko') || str_contains($fullText, 'dzīvā mūzika') ||
            str_contains($fullText, 'finālšovs')
        ) {
            $cats[] = 'Mūzika';
        }

        // 5. Sports
        $isSportsVenue = str_contains($venueLower, 'ledus halle') || str_contains($venueLower, 'sporta skola') ||
            str_contains($venueLower, 'olimpiskais centrs') || str_contains($venueLower, 'slidošan');
        $isSportsContent = str_contains($fullText, 'sports') || str_contains($fullText, 'čempionāt') ||
            str_contains($fullText, 'volejbol') || str_contains($fullText, 'basketbol') ||
            str_contains($fullText, 'futbol') || str_contains($fullText, 'hokej') ||
            str_contains($fullText, 'slidošan') || str_contains($fullText, 'turnīrs') ||
            str_contains($fullText, 'skrējien') || str_contains($fullText, 'maratons') ||
            str_contains($fullText, 'spīdvej');

        if ($isSportsVenue || $isSportsContent) {
            $cats[] = 'Sports';
        }

        // 6. Kids / Families
        if (
            str_contains($titleLower, 'bērniem') || str_contains($titleLower, 'mazuļ') ||
            str_contains($titleLower, 'leļļu') || str_contains($titleLower, 'pasak') ||
            str_contains($titleLower, 'ģimen') || str_contains($descLower, 'bērniem')
        ) {
            $cats[] = 'Bērniem';
        }

        // 7. Workshops / Seminars
        if (
            str_contains($fullText, 'darbnīca') || str_contains($fullText, 'meistarklas') ||
            str_contains($fullText, 'meistarklaš') || str_contains($fullText, 'nodarbīb') ||
            str_contains($fullText, 'lekcij') || str_contains($fullText, 'kursi') ||
            str_contains($fullText, 'saruna') || str_contains($fullText, 'diskusija')
        ) {
            $cats[] = 'Semināri';
        }

        // 8. Holidays / Celebrations
        if (
            str_contains($fullText, 'svētk') || str_contains($fullText, 'balle') ||
            str_contains($fullText, 'tirdziņ') || str_contains($fullText, 'gadatirg') ||
            str_contains($fullText, 'egles iedegšan') || str_contains($fullText, 'jaunais gads') ||
            str_contains($fullText, 'advente') || str_contains($fullText, 'proklamēšan') ||
            str_contains($fullText, 'lāčplēša')
        ) {
            $cats[] = 'Svētki';
        }

        // 9. Fallback Rule from AGENTS.md
        if (empty($cats)) {
            $cats[] = 'Cits';
        }

        return array_values(array_unique($cats));
    }

    /**
     * Resolve entertainment type based on categories and keywords
     */
    public function resolveEntertainmentType(array $categories, string $title, string $description = ''): string
    {
        $haystack = mb_strtolower($title . ' ' . $description, 'UTF-8');

        if (in_array('Kino', $categories, true) || str_contains($haystack, 'filma') || str_contains($haystack, 'kinoseans')) {
            return 'movie';
        }
        if (in_array('Teātris', $categories, true)) {
            return 'theatre';
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

        return 'event';
    }

    /**
     * Check if item is a generic directory calendar rather than an actual event
     */
    public function isNonEvent(string $title): bool
    {
        $lower = mb_strtolower(trim($title), 'UTF-8');

        $blacklisted = [
            'pasākumu kalendārs',
            'izrāžu kalendārs',
            'kino seansu repertuārs',
            'seansu repertuārs',
            'gada pasākumu kalendārs',
            'kultūras pasākumi daugavpils',
            'repertuārs',
        ];

        foreach ($blacklisted as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clean and normalize absolute URL
     */
    protected function normalizeUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $url = trim($url);
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (str_starts_with($url, '/')) {
            return 'https://www.visitdaugavpils.lv' . $url;
        }

        return $url;
    }
}
