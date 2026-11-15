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

class DaugavpilsScraper extends BaseScraper
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
        'tradīciju māja' => [
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
        'ledus halle' => [
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
        'sporta skola' => [
            'name' => 'Daugavpils Sporta skola',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Kandavas iela 17A, Daugavpils, LV-5401',
            'place_type' => 'sports_complex',
        ],
        'kultūras pils' => [
            'name' => 'Daugavpils Kultūras pils',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Smilšu iela 92, Daugavpils, LV-5410',
            'place_type' => 'cultural_center',
        ],
        'jaunbūves kultūrtelpa' => [
            'name' => 'Jaunbūves kultūrtelpa',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Varšavas iela 14, Daugavpils, LV-5404',
            'place_type' => 'cultural_center',
        ],
        'grīvas kultūrtelpa' => [
            'name' => 'Grīvas kultūrtelpa',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Komunālā iela 2, Daugavpils, LV-5408',
            'place_type' => 'cultural_center',
        ],
        'forštates kultūrtelpa' => [
            'name' => 'Forštates kultūrtelpa',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Vidzemes iela 41A, Daugavpils, LV-5422',
            'place_type' => 'cultural_center',
        ],
        'vienības laukums' => [
            'name' => 'Vienības laukums',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Vienības laukums, Daugavpils, LV-5401',
            'place_type' => 'square',
        ],
        'cietoksn' => [
            'name' => 'Daugavpils cietoksnis',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Nikolaja iela 5, Daugavpils, LV-5401',
            'place_type' => 'landmark',
        ],
        'novadpētniecības' => [
            'name' => 'Daugavpils Novadpētniecības un mākslas muzejs',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Rīgas iela 8, Daugavpils, LV-5401',
            'place_type' => 'museum',
        ],
        'inženieru arsenāls' => [
            'name' => 'Daugavpils Inženieru arsenāls',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Imperatora iela 8, Daugavpils, LV-5401',
            'place_type' => 'museum',
        ],
        'zīlīte' => [
            'name' => 'Bērnu bibliotēka "Zīlīte"',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Mihoelsa iela 58, Daugavpils, LV-5401',
            'place_type' => 'library',
        ],
        'poļu nams' => [
            'name' => 'Poļu kultūras centrs (Poļu nams)',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Varšavas iela 30, Daugavpils, LV-5404',
            'place_type' => 'cultural_center',
        ],
    ];

    public function getSlug(): string
    {
        return 'daugavpils-dome';
    }

    public function getName(): string
    {
        return 'Daugavpils valstspilsēta';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $baseUrl = $source->url ?: 'https://www.daugavpils.lv/afisa/';
        $today = Carbon::today('Europe/Riga');
        $maxPages = 6;
        $seenUrls = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $pageUrl = $page === 1 ? $baseUrl : rtrim($baseUrl, '/') . '/?page=' . $page;
            $crawler = $this->fetchCrawler($pageUrl);

            if (!$crawler) {
                Log::warning("DaugavpilsScraper: Failed to fetch page {$pageUrl}");
                break;
            }

            $cards = $crawler->filter('.afisa-posts .col-xs-6, .afisa-posts > div');
            if ($cards->count() === 0) {
                break;
            }

            $pageNewEvents = 0;

            $cards->each(function (Crawler $node) use (&$events, &$seenUrls, &$pageNewEvents, $today) {
                $linkNode = $node->filter('a')->first();
                if (!$linkNode->count()) {
                    return;
                }

                $relLink = $linkNode->attr('href');
                $cleanUrl = $this->normalizeUrl($relLink);
                if (empty($cleanUrl) || in_array($cleanUrl, $seenUrls, true)) {
                    return;
                }
                $seenUrls[] = $cleanUrl;

                $titleNode = $node->filter('.event-text h3, h3');
                $title = $this->cleanText($titleNode->count() ? $titleNode->text() : $linkNode->attr('title'));
                if (empty($title) || $this->isNonEvent($title, $cleanUrl)) {
                    return;
                }

                // Dates & times
                [$startAt, $endAt] = $this->extractDates($node, $today);
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

                // Location info
                $placeRaw = $node->filter('.event-text p.place, p.place')->count()
                    ? $this->cleanText($node->filter('.event-text p.place, p.place')->text())
                    : 'Daugavpils';
                $locationInfo = $this->resolveLocation($placeRaw, $title);

                // Image URL
                $imgNode = $node->filter('.event-img img, img.event-pict');
                $imageUrl = $imgNode->count() ? $imgNode->attr('src') : null;
                if ($imageUrl && !str_starts_with($imageUrl, 'http://') && !str_starts_with($imageUrl, 'https://')) {
                    $imageUrl = 'https://www.daugavpils.lv' . (str_starts_with($imageUrl, '/') ? '' : '/') . $imageUrl;
                }

                // Categories & Entertainment Type (adhering strictly to AGENTS.md)
                $categories = $this->resolveCategories($title, $placeRaw);
                $entertainmentType = $this->resolveEntertainmentType($categories, $title, $placeRaw);

                // External ID
                $slugPart = basename(parse_url($cleanUrl, PHP_URL_PATH));
                $externalId = 'daugavpils-' . $slugPart;

                // Description from detail page with fallback
                $detailDescription = $this->fetchDetailDescription($cleanUrl);
                if (!empty($detailDescription)) {
                    $description = $detailDescription;
                } else {
                    $description = $title;
                    if (!empty($locationInfo['name']) && $locationInfo['name'] !== 'Daugavpils') {
                        $description .= "\nNorises vieta: " . $locationInfo['name'];
                    }
                    if (!empty($locationInfo['address']) && $locationInfo['address'] !== 'Daugavpils') {
                        $description .= "\nAdrese: " . $locationInfo['address'];
                    }
                }

                $dto = new ScrapedEventDTO(
                    title: $title,
                    startAt: $startAt,
                    endAt: $endAt,
                    description: $description,
                    shortDescription: Str::limit($title, 160),
                    venueName: $locationInfo['name'],
                    city: 'Daugavpils',
                    region: 'Latgale',
                    address: $locationInfo['address'],
                    placeType: $locationInfo['place_type'] ?? 'venue',
                    categoryNames: $categories,
                    entertainmentType: $entertainmentType,
                    isFree: false,
                    imageUrl: $imageUrl,
                    sourceUrl: $cleanUrl,
                    sourceExternalId: $externalId,
                    locale: 'lv',
                    rawData: [
                        'place_raw' => $placeRaw,
                    ]
                );

                $events->push($dto);
                $pageNewEvents++;
            });

            // Stop pagination if no new events were found on current page
            if ($pageNewEvents === 0) {
                break;
            }
        }

        Log::info("DaugavpilsScraper: Scraped {$events->count()} events across pages.");

        return $events;
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
            return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'www.daugavpils.lv') . ($parsed['path'] ?? '');
        }

        $path = strtok($relUrl, '?');
        return 'https://www.daugavpils.lv' . (str_starts_with($path, '/') ? '' : '/') . $path;
    }

    /**
     * Extract start and end dates from card node
     */
    public function extractDates(Crawler $node, ?Carbon $today = null): array
    {
        $today = $today ?: Carbon::today('Europe/Riga');
        $currentYear = $today->year;
        $currentMonth = $today->month;

        $months = [
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mai' => 5, 'may' => 5,
            'jun' => 6, 'jūn' => 6, 'jul' => 7, 'jūl' => 7, 'aug' => 8, 'sep' => 9,
            'okt' => 10, 'oct' => 10, 'nov' => 11, 'dec' => 12,
            'janvāris' => 1, 'februāris' => 2, 'marts' => 3, 'aprīlis' => 4,
            'maijs' => 5, 'jūnijs' => 6, 'jūlijs' => 7, 'augusts' => 8,
            'septembris' => 9, 'oktobris' => 10, 'novembris' => 11, 'decembris' => 12,
        ];

        // 1. Multi-day format (.date1, .date2)
        if ($node->filter('.date1')->count() && $node->filter('.date2')->count()) {
            $date1Text = trim($node->filter('.date1')->text());
            $time1Text = $node->filter('.time1')->count() ? trim($node->filter('.time1')->text()) : '10:00';
            $date2Text = trim($node->filter('.date2')->text());
            $time2Text = $node->filter('.time2')->count() ? trim($node->filter('.time2')->text()) : '18:00';

            preg_match('/(\d{1,2})\s*([a-zāčēģīķļņšūž]+)/iu', $date1Text, $m1);
            preg_match('/(\d{1,2})\s*([a-zāčēģīķļņšūž]+)/iu', $date2Text, $m2);

            if (!empty($m1) && !empty($m2)) {
                $day1 = (int) $m1[1];
                $mon1 = $months[mb_strtolower(trim($m1[2]))] ?? $currentMonth;
                $day2 = (int) $m2[1];
                $mon2 = $months[mb_strtolower(trim($m2[2]))] ?? $currentMonth;

                $time1 = preg_match('/(\d{1,2})[\.:](\d{2})/', $time1Text, $tm1) ? sprintf('%02d:%02d', $tm1[1], $tm1[2]) : '10:00';
                $time2 = preg_match('/(\d{1,2})[\.:](\d{2})/', $time2Text, $tm2) ? sprintf('%02d:%02d', $tm2[1], $tm2[2]) : '18:00';

                // Year calculation for upcoming dates
                $y1 = ($mon1 < $currentMonth && $mon2 < $currentMonth) ? $currentYear + 1 : $currentYear;
                $y2 = ($mon2 < $mon1) ? $y1 + 1 : $y1;

                $start = Carbon::createFromDate($y1, $mon1, $day1, 'Europe/Riga')->setTime(...explode(':', $time1));
                $end = Carbon::createFromDate($y2, $mon2, $day2, 'Europe/Riga')->setTime(...explode(':', $time2));

                if ($end->lessThan($start)) {
                    $end = $end->copy()->addYear();
                }

                return [$start, $end];
            }
        }

        // 2. Single day format (.date, .month, .time)
        $dayText = $node->filter('.event-date .date, .date')->count() ? trim($node->filter('.event-date .date, .date')->text()) : '';
        $monthText = $node->filter('.event-date .month, .month')->count() ? trim($node->filter('.event-date .month, .month')->text()) : '';
        $timeText = $node->filter('.event-date .time, .time')->count() ? trim($node->filter('.event-date .time, .time')->text()) : '18:00';

        $day = (int) preg_replace('/[^\d]/', '', $dayText);
        if ($day === 0) {
            $day = (int) $today->format('d');
        }

        $mKey = mb_strtolower(trim($monthText));
        $mon = $months[$mKey] ?? $currentMonth;

        // If month is before current month, it's next year
        $yr = ($mon < $currentMonth) ? $currentYear + 1 : $currentYear;

        $startTime = '18:00';
        if (preg_match('/(\d{1,2})[\.:](\d{2})/i', $timeText, $tm)) {
            $startTime = sprintf('%02d:%02d', $tm[1], $tm[2]);
        }

        $start = Carbon::createFromDate($yr, $mon, $day, 'Europe/Riga')->setTime(...explode(':', $startTime));

        return [$start, null];
    }

    /**
     * Resolve location and address details from card place text
     */
    public function resolveLocation(string $placeRaw, string $title = ''): array
    {
        $raw = trim($placeRaw);
        $rawLower = mb_strtolower($raw, 'UTF-8');
        $titleLower = mb_strtolower($title, 'UTF-8');

        // Check dictionary matches first
        foreach ($this->venueMap as $key => $meta) {
            if (str_contains($rawLower, $key) || str_contains($titleLower, $key)) {
                return $meta;
            }
        }

        if (empty($raw) || $raw === 'Daugavpils') {
            return [
                'name' => 'Daugavpils',
                'address' => 'Daugavpils',
                'place_type' => 'venue',
            ];
        }

        // Check if string is in ALL CAPS and normalize capitalization
        if (mb_strtoupper($raw, 'UTF-8') === $raw) {
            $raw = mb_convert_case($raw, MB_CASE_TITLE, 'UTF-8');
        }

        $parts = array_map('trim', explode(',', $raw));

        $placeType = 'venue';
        if (str_contains($rawLower, 'teātr')) {
            $placeType = 'theatre';
        } elseif (str_contains($rawLower, 'muzej')) {
            $placeType = 'museum';
        } elseif (str_contains($rawLower, 'sport') || str_contains($rawLower, 'halle') || str_contains($rawLower, 'stadion')) {
            $placeType = 'sports_complex';
        } elseif (str_contains($rawLower, 'baznīc') || str_contains($rawLower, 'katedrāl')) {
            $placeType = 'church';
        } elseif (str_contains($rawLower, 'bibliotēk')) {
            $placeType = 'library';
        } elseif (str_contains($rawLower, 'laukums')) {
            $placeType = 'square';
        }

        if (count($parts) >= 2) {
            $venueName = $parts[0];
            $address = implode(', ', array_slice($parts, 1));
            if (!str_contains($address, 'Daugavpils')) {
                $address .= ', Daugavpils';
            }
            return [
                'name' => $venueName,
                'address' => $address,
                'place_type' => $placeType,
            ];
        }

        return [
            'name' => $raw,
            'address' => str_contains($raw, 'Daugavpils') ? $raw : $raw . ', Daugavpils',
            'place_type' => $placeType,
        ];
    }

    /**
     * Categories resolution adhering strictly to AGENTS.md domain rules
     */
    public function resolveCategories(string $title, ?string $venueRaw = ''): array
    {
        $cats = [];
        $titleLower = mb_strtolower($title, 'UTF-8');
        $venueLower = mb_strtolower($venueRaw ?? '', 'UTF-8');
        $fullText = $titleLower . ' ' . $venueLower;

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
            str_contains($fullText, 'glezn') || str_contains($fullText, 'mākslas centrs') ||
            str_contains($fullText, 'novadpētniecības muzejs') || str_contains($fullText, 'stājglezniecīb') ||
            str_contains($fullText, 'keramik') || str_contains($fullText, 'fotogrāfij') ||
            str_contains($fullText, 'akvareļ')
        ) {
            $cats[] = 'Izstādes';
        }

        // 3. Theater Productions (NEVER cinema/film)
        $isTheatreMention = str_contains($fullText, 'daugavpils teātr') || (str_contains($fullText, 'teātr') && !str_contains($fullText, 'kinoteātr'));
        $isTheatreContent = str_contains($titleLower, 'izrād') || str_contains($titleLower, 'luga') ||
            str_contains($titleLower, 'iestudējum') || str_contains($titleLower, 'stāvizrād') ||
            str_contains($titleLower, 'stand-up') || str_contains($titleLower, 'komēdij') ||
            str_contains($titleLower, 'monoizrād') || str_contains($titleLower, 'monodrām') ||
            str_contains($titleLower, 'opera') || str_contains($titleLower, 'balets');

        if (!$isCinema && !str_contains($fullText, 'kinoteātr') && ($isTheatreMention || $isTheatreContent)) {
            $cats[] = 'Teātris';
        }

        // 4. Music / Concerts
        if (
            str_contains($titleLower, 'koncert') || str_contains($titleLower, 'dziesm') ||
            str_contains($titleLower, 'orķestr') || str_contains($titleLower, 'koris') ||
            str_contains($titleLower, 'džez') || str_contains($titleLower, 'muzikāl') ||
            str_contains($titleLower, 'koklē') || str_contains($titleLower, 'zvani') ||
            str_contains($titleLower, 'trio') || str_contains($titleLower, 'ansambl') ||
            str_contains($titleLower, 'dziedāšan') || str_contains($titleLower, 'solokoncert') ||
            str_contains($titleLower, 'kamermūzik')
        ) {
            $cats[] = 'Mūzika';
        }

        // 5. Sports
        $isSportsVenue = str_contains($venueLower, 'ledus halle') || str_contains($venueLower, 'sporta skola') ||
            str_contains($venueLower, 'olimpiskais centrs') || str_contains($venueLower, 'sporta zāle') ||
            str_contains($venueLower, 'stadions') || str_contains($venueLower, 'spīdvej');
        $isSportsContent = str_contains($titleLower, 'hokej') || str_contains($titleLower, 'čempionāt') ||
            str_contains($titleLower, 'volejbol') || str_contains($titleLower, 'basketbol') ||
            str_contains($titleLower, 'futbol') || str_contains($titleLower, 'telpu futbols') ||
            str_contains($titleLower, 'virslīga') || str_contains($titleLower, 'sports') ||
            str_contains($titleLower, 'turnīrs') || str_contains($titleLower, 'skrējien') ||
            str_contains($titleLower, 'pret ') || str_contains($titleLower, 'aktīva daugavpils') ||
            str_contains($titleLower, 'līga');

        if ($isSportsVenue || $isSportsContent) {
            $cats[] = 'Sports';
        }

        // 6. Kids / Families
        if (
            str_contains($titleLower, 'bērniem') || str_contains($titleLower, 'mazuļ') ||
            str_contains($titleLower, 'leļļu') || str_contains($titleLower, 'pasak') ||
            str_contains($titleLower, 'ģimen')
        ) {
            $cats[] = 'Bērniem';
        }

        // 7. Workshops / Seminars
        if (
            str_contains($titleLower, 'darbnīca') || str_contains($titleLower, 'meistarklas') ||
            str_contains($titleLower, 'meistarklaš') || str_contains($titleLower, 'nodarbīb') ||
            str_contains($titleLower, 'lekcij') || str_contains($titleLower, 'kursi') ||
            str_contains($titleLower, 'saruna') || str_contains($titleLower, 'diskusija')
        ) {
            $cats[] = 'Semināri';
        }

        // 8. Holidays / Celebrations
        if (
            str_contains($titleLower, 'svētk') || str_contains($titleLower, 'balle') ||
            str_contains($titleLower, 'tirdziņ') || str_contains($titleLower, 'gadatirg') ||
            str_contains($titleLower, 'egles iedegšan') || str_contains($titleLower, 'jaunais gads') ||
            str_contains($titleLower, 'proklamēšan') || str_contains($titleLower, 'lāčplēša')
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
    public function resolveEntertainmentType(array $categories, string $title, ?string $venueRaw = ''): string
    {
        $titleLower = mb_strtolower($title, 'UTF-8');
        $venueLower = mb_strtolower($venueRaw ?? '', 'UTF-8');

        if (in_array('Kino', $categories, true) || str_contains($venueLower, 'kino') || str_contains($titleLower, 'filma') || str_contains($titleLower, 'kinoseans')) {
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
     * Filter out non-event municipal notices
     */
    public function isNonEvent(string $title, ?string $url = null): bool
    {
        $lower = mb_strtolower(trim($title));
        $urlLower = $url ? strtolower($url) : '';

        $blacklistedPatterns = [
            'ielaušanās spēle',
            'gada pasākumu kalendārs',
            'ielu remonta darbi',
            'remonta darbi',
            'tiešsaistes pieraksts',
            'pašvaldības speciālist',
            'konkursa 2. kārta',
            'konkursa 1. kārta',
            'remigrācijas veicināšanas',
            'pasākumi daugavpilī',
        ];

        foreach ($blacklistedPatterns as $pattern) {
            if (str_contains($lower, $pattern) || str_contains($urlLower, $pattern)) {
                return true;
            }
        }

        return false;
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
        $crawler->filter('article > p, .article-content p, .news-content p')->each(function (Crawler $p) use (&$paragraphs) {
            $t = $this->cleanText($p->text());
            if (empty($t) || str_starts_with($t, 'Foto:') || str_starts_with($t, 'Informāciju sagatavoja:')) {
                return;
            }
            if (!in_array($t, $paragraphs, true)) {
                $paragraphs[] = $t;
            }
        });

        if (empty($paragraphs)) {
            $crawler->filter('article')->each(function (Crawler $p) use (&$paragraphs) {
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
