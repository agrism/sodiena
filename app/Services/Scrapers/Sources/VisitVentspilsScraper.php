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

class VisitVentspilsScraper extends BaseScraper
{
    public const BASE_URL = 'https://www.visitventspils.com';
    public const CALENDAR_PATH = '/pasakumi/';

    /**
     * Map of known Ventspils venues with normalized metadata
     */
    protected array $venueAddressMap = [
        'koncertzāle "latvija"' => [
            'name' => 'Koncertzāle "Latvija"',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Lielais laukums 1, Ventspils, LV-3601',
            'place_type' => 'concert_hall',
            'lat' => 57.3908,
            'lng' => 21.5645,
        ],
        'teātra nams "jūras vārti"' => [
            'name' => 'Teātra nams "Jūras vārti"',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Karlīnes iela 40, Ventspils, LV-3601',
            'place_type' => 'theater',
            'lat' => 57.3933,
            'lng' => 21.5420,
        ],
        'kino rio' => [
            'name' => 'Kino Rio',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Pils iela 28, Ventspils, LV-3601',
            'place_type' => 'cinema',
            'lat' => 57.3957,
            'lng' => 21.5646,
        ],
        'piejūras brīvdabas muzejs' => [
            'name' => 'Piejūras brīvdabas muzejs',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Riņķa iela 2, Ventspils, LV-3601',
            'place_type' => 'museum',
            'lat' => 57.3857,
            'lng' => 21.5367,
        ],
        'livonijas ordeņa pils' => [
            'name' => 'Livonijas ordeņa pils',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Jāņa iela 17, Ventspils, LV-3601',
            'place_type' => 'museum',
            'lat' => 57.3961,
            'lng' => 21.5592,
        ],
        'amatu māja' => [
            'name' => 'Amatu māja',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Skolas iela 3, Ventspils, LV-3601',
            'place_type' => 'cultural_center',
            'lat' => 57.3948,
            'lng' => 21.5621,
        ],
        'h.dorbes muzejs' => [
            'name' => 'H. Dorbes muzejs',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Ērgļu iela 1, Ventspils, LV-3601',
            'place_type' => 'museum',
            'lat' => 57.3879,
            'lng' => 21.5524,
        ],
        'ventspils kultūras centrs' => [
            'name' => 'Ventspils Kultūras centrs',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Kuldīgas iela 18, Ventspils, LV-3601',
            'place_type' => 'cultural_center',
            'lat' => 57.3922,
            'lng' => 21.5638,
        ],
        'zinātnes centrs "vizium"' => [
            'name' => 'Zinātnes centrs "VIZIUM"',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Rūpniecības iela 2, Ventspils, LV-3601',
            'place_type' => 'museum',
            'lat' => 57.4048,
            'lng' => 21.5732,
        ],
        'olimpiskais centrs "ventspils"' => [
            'name' => 'Olimpiskais centrs "Ventspils"',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Sporta iela 7/9, Ventspils, LV-3601',
            'place_type' => 'sports_facility',
            'lat' => 57.3905,
            'lng' => 21.5516,
        ],
        'pārventas sporta komplekss' => [
            'name' => 'Pārventas sporta komplekss',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Tārgales iela 5b, Ventspils, LV-3602',
            'place_type' => 'sports_facility',
            'lat' => 57.4095,
            'lng' => 21.5891,
        ],
        'ūdens piedzīvojumu parks' => [
            'name' => 'Ūdens piedzīvojumu parks',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Lauku iela 5, Ventspils, LV-3601',
            'place_type' => 'sports_facility',
            'lat' => 57.3887,
            'lng' => 21.5501,
        ],
        'galvenā bibliotēka' => [
            'name' => 'Ventspils Galvenā bibliotēka',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Akmeņu iela 2, Ventspils, LV-3601',
            'place_type' => 'library',
            'lat' => 57.3944,
            'lng' => 21.5647,
        ],
        'pārventas bibliotēka' => [
            'name' => 'Pārventas bibliotēka',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Tārgales iela 4, Ventspils, LV-3602',
            'place_type' => 'library',
            'lat' => 57.4098,
            'lng' => 21.5880,
        ],
        'gāliņciema bibliotēka' => [
            'name' => 'Gāliņciema bibliotēka',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Kuldīgas iela 138, Ventspils, LV-3601',
            'place_type' => 'library',
            'lat' => 57.3774,
            'lng' => 21.5794,
        ],
        'bērnu bibliotēka' => [
            'name' => 'Ventspils Bērnu bibliotēka',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Tirgus iela 5, Ventspils, LV-3601',
            'place_type' => 'library',
            'lat' => 57.3965,
            'lng' => 21.5658,
        ],
        'reņķa dārzs' => [
            'name' => 'Reņķa dārzs',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Sporta iela, Ventspils, LV-3601',
            'place_type' => 'park',
            'lat' => 57.3872,
            'lng' => 21.5583,
        ],
        'bērnu pilsētiņa' => [
            'name' => 'Bērnu pilsētiņa',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Lielais prospekts, Ventspils, LV-3601',
            'place_type' => 'park',
            'lat' => 57.3875,
            'lng' => 21.5435,
        ],
        'bērnu parks "fantāzija"' => [
            'name' => 'Bērnu parks "Fantāzija"',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Talsu iela, Ventspils, LV-3602',
            'place_type' => 'park',
            'lat' => 57.4140,
            'lng' => 21.5930,
        ],
        'lielais laukums' => [
            'name' => 'Lielais laukums',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Lielais laukums, Ventspils, LV-3601',
            'place_type' => 'park',
            'lat' => 57.3908,
            'lng' => 21.5645,
        ],
        'ostas ielas promenāde' => [
            'name' => 'Ostas ielas promenāde',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Ostas iela, Ventspils, LV-3601',
            'place_type' => 'cultural_center',
            'lat' => 57.3970,
            'lng' => 21.5620,
        ],
        'ventspils tirgus' => [
            'name' => 'Ventspils tirgus',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Pils iela 14, Ventspils, LV-3601',
            'place_type' => 'market',
            'lat' => 57.3965,
            'lng' => 21.5665,
        ],
        'ventspils jaunrades nams' => [
            'name' => 'Ventspils Jaunrades nams',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Maiznieku iela 11, Ventspils, LV-3601',
            'place_type' => 'cultural_center',
            'lat' => 57.3920,
            'lng' => 21.5600,
        ],
    ];

    public function getSlug(): string
    {
        return 'visitventspils';
    }

    public function getName(): string
    {
        return 'VisitVentspils';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $targetUrl = $source->url ?: (self::BASE_URL . self::CALENDAR_PATH);
        $today = Carbon::today('Europe/Riga');
        $seenExternalIds = [];

        $crawler = $this->fetchCrawler($targetUrl);
        if (!$crawler) {
            Log::warning("VisitVentspilsScraper: Failed to fetch page {$targetUrl}");
            return $events;
        }

        $items = $crawler->filter('article.list-item');
        if ($items->count() === 0) {
            return $events;
        }

        $items->each(function (Crawler $node) use (&$events, &$seenExternalIds, $today, $targetUrl) {
            // Check if unavailable / cancelled
            $classAttr = $node->attr('class') ?? '';
            if (str_contains($classAttr, 'list-item--unaviable')) {
                return;
            }

            $titleNode = $node->filter('.list-item__title, h3.list-item__title, h3');
            if (!$titleNode->count()) {
                return;
            }

            $title = $this->cleanText($titleNode->first()->text());
            if (empty($title) || $this->isNonEvent($title)) {
                return;
            }

            // Status node (Pirkt biļeti, Bezmaksas, Atcelts, Izpārdots)
            $statusNode = $node->filter('.list-item__status');
            $statusText = $statusNode->count() ? $this->cleanText($statusNode->text()) : '';
            if (mb_stripos($statusText, 'atcelts') !== false) {
                return;
            }

            // Link & External ID
            $linkNode = $node->filter('a.list-item__inner, a[href*="/pasakums/"]');
            $relLink = $linkNode->count() ? $linkNode->first()->attr('href') : '';
            $cleanUrl = $this->normalizeUrl($relLink);

            $favNode = $node->filter('button.add-to-favourites');
            $dataId = $favNode->count() ? trim($favNode->first()->attr('data-id')) : '';
            $slugPart = $cleanUrl ? basename(parse_url($cleanUrl, PHP_URL_PATH)) : '';
            $externalId = 'visitventspils-' . ($dataId ?: ($slugPart ?: Str::slug($title)));

            if (isset($seenExternalIds[$externalId])) {
                return;
            }

            // Dates & Times
            [$startAt, $endAt, $allDay] = $this->parseCardDates($node);
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

            // Description directly from card (strictly NO crawling into detail pages)
            $descNode = $node->filter('.list-item__desc, p.list-item__desc');
            $description = $descNode->count() ? $this->cleanText($descNode->first()->text()) : $title;
            if (empty($description)) {
                $description = $title;
            }

            // Location
            $locNode = $node->filter('.list-item__address, p.list-item__address');
            $locationRaw = $locNode->count() ? $this->cleanText($locNode->first()->text()) : '';
            $venueInfo = $this->resolveLocation($locationRaw, $title, $description);

            // Categories & Entertainment Type (adhering strictly to AGENTS.md)
            $canonicalCategories = $this->resolveCategories($locationRaw, $title, $description);
            $entertainmentType = $this->resolveEntertainmentType($canonicalCategories, $title, $description);

            // Price & Ticket info
            $ticketLinkNode = $statusNode->filter('a');
            $ticketUrl = $ticketLinkNode->count() ? $ticketLinkNode->first()->attr('href') : null;
            $priceData = $this->parsePrice($statusText, $description, $ticketUrl);

            // Image
            $imgNode = $node->filter('.list-item__image img');
            $imageUrl = $imgNode->count() ? $imgNode->first()->attr('src') : null;
            if ($imageUrl && !str_starts_with($imageUrl, 'http')) {
                $imageUrl = self::BASE_URL . (str_starts_with($imageUrl, '/') ? '' : '/') . $imageUrl;
            }

            $seenExternalIds[$externalId] = true;

            $dto = new ScrapedEventDTO(
                title: $title,
                startAt: $startAt,
                endAt: $endAt,
                description: $description,
                shortDescription: Str::limit(strip_tags($description), 160),
                venueName: $venueInfo['name'],
                city: $venueInfo['city'],
                region: $venueInfo['region'],
                address: $venueInfo['address'],
                latitude: $venueInfo['lat'] ?? null,
                longitude: $venueInfo['lng'] ?? null,
                placeType: $venueInfo['place_type'],
                categoryNames: $canonicalCategories,
                entertainmentType: $entertainmentType,
                isFree: $priceData['is_free'],
                priceMin: $priceData['price_min'],
                priceMax: $priceData['price_max'],
                currency: 'EUR',
                ticketUrl: $ticketUrl ?: ($cleanUrl ?: $targetUrl),
                imageUrl: $imageUrl,
                sourceUrl: $cleanUrl ?: $targetUrl,
                sourceExternalId: $externalId,
                locale: 'lv',
                rawData: [
                    'location_raw' => $locationRaw,
                    'status_raw' => $statusText,
                ]
            );

            $events->push($dto);
        });

        return $events;
    }

    /**
     * Parse dates from card markup (.list-item__date-day, .list-item__date-month, .list-item__date-time)
     */
    public function parseCardDates(Crawler $node): array
    {
        $months = [
            'janv' => 1, 'jan' => 1,
            'febr' => 2, 'feb' => 2,
            'mart' => 3, 'mar' => 3,
            'apr' => 4,
            'maij' => 5, 'mai' => 5,
            'jūn' => 6, 'jun' => 6,
            'jūl' => 7, 'jul' => 7,
            'aug' => 8,
            'sept' => 9, 'sep' => 9,
            'okt' => 10, 'oct' => 10,
            'nov' => 11,
            'dec' => 12,
        ];

        $days = $node->filter('.list-item__date-day');
        $monthEls = $node->filter('.list-item__date-month');
        $timeEl = $node->filter('.list-item__date-time');

        $timeStr = $timeEl->count() ? trim($timeEl->first()->text()) : '';
        $startTime = '10:00:00';
        $endTime = null;
        $allDay = empty($timeStr);

        if (preg_match('/(\d{1,2})[.:](\d{2})\s*-\s*(\d{1,2})[.:](\d{2})/u', $timeStr, $tm)) {
            $startTime = sprintf('%02d:%02d:00', (int)$tm[1], (int)$tm[2]);
            $endTime = sprintf('%02d:%02d:00', (int)$tm[3], (int)$tm[4]);
        } elseif (preg_match('/(\d{1,2})[.:](\d{2})/u', $timeStr, $tm)) {
            $startTime = sprintf('%02d:%02d:00', (int)$tm[1], (int)$tm[2]);
        }

        $dates = [];
        for ($i = 0; $i < $days->count(); $i++) {
            $d = (int) trim($days->eq($i)->text());
            $mRaw = $monthEls->count() > $i ? str_replace(["\n", "\r", '<br>', '<br/>'], ' ', $monthEls->eq($i)->html()) : '';
            $mClean = mb_strtolower(strip_tags($mRaw));
            $y = preg_match('/\b(20\d{2})\b/', $mClean, $yM) ? (int)$yM[1] : (int)date('Y');
            $mNum = (int) date('n');
            foreach ($months as $stem => $num) {
                if (mb_stripos($mClean, $stem) !== false) {
                    $mNum = $num;
                    break;
                }
            }
            $dates[] = ['d' => $d, 'm' => $mNum, 'y' => $y];
        }

        if (count($dates) >= 2) {
            $startAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $dates[0]['y'], $dates[0]['m'], $dates[0]['d'], $startTime), 'Europe/Riga');
            $endAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $dates[1]['y'], $dates[1]['m'], $dates[1]['d'], $endTime ?: '20:00:00'), 'Europe/Riga');
            return [$startAt, $endAt, $allDay];
        }

        if (count($dates) === 1) {
            $startAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $dates[0]['y'], $dates[0]['m'], $dates[0]['d'], $startTime), 'Europe/Riga');
            $endAt = $endTime ? Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $dates[0]['y'], $dates[0]['m'], $dates[0]['d'], $endTime), 'Europe/Riga') : null;
            return [$startAt, $endAt, $allDay];
        }

        return [null, null, false];
    }

    /**
     * Resolve Venue and Location
     */
    public function resolveLocation(string $locationRaw, string $title = '', string $description = ''): array
    {
        $text = mb_strtolower($locationRaw . ' ' . $title . ' ' . $description, 'UTF-8');

        // Stem mapping for known Ventspils venues
        $stemVenueMap = [
            'kino rio' => 'kino rio',
            'teātra nam' => 'teātra nams "jūras vārti"',
            'teatra nam' => 'teātra nams "jūras vārti"',
            'jūras vārt' => 'teātra nams "jūras vārti"',
            'juras vart' => 'teātra nams "jūras vārti"',
            'koncertzāl' => 'koncertzāle "latvija"',
            'koncertzal' => 'koncertzāle "latvija"',
            'piejūras brīvdabas muzej' => 'piejūras brīvdabas muzejs',
            'piejuras brivdabas muzej' => 'piejūras brīvdabas muzejs',
            'livonijas ordeņa pil' => 'livonijas ordeņa pils',
            'livonijas orden' => 'livonijas ordeņa pils',
            'amatu māj' => 'amatu māja',
            'amatu maj' => 'amatu māja',
            'h.dorbes' => 'h.dorbes muzejs',
            'dorbes' => 'h.dorbes muzejs',
            'vizium' => 'zinātnes centrs "vizium"',
            'ventspils kultūras centr' => 'ventspils kultūras centrs',
            'ventspils kulturas centr' => 'ventspils kultūras centrs',
            'kultūras centr' => 'ventspils kultūras centrs',
            'kulturas centr' => 'ventspils kultūras centrs',
            'pārventas sporta kompleks' => 'pārventas sporta komplekss',
            'parventas sporta kompleks' => 'pārventas sporta komplekss',
            'pārventas sporta hall' => 'pārventas sporta komplekss',
            'parventas sporta hall' => 'pārventas sporta komplekss',
            'ūdens piedzīvojumu park' => 'ūdens piedzīvojumu parks',
            'udens piedzivojumu park' => 'ūdens piedzīvojumu parks',
            'oc "ventspils"' => 'olimpiskais centrs "ventspils"',
            'oc ventspils' => 'olimpiskais centrs "ventspils"',
            'olimpiskais centrs "ventspils"' => 'olimpiskais centrs "ventspils"',
            'olimpiskais centrs ventspils' => 'olimpiskais centrs "ventspils"',
            'olimpiskā centra "ventspils"' => 'olimpiskais centrs "ventspils"',
            'pārventas bibliotēk' => 'pārventas bibliotēka',
            'parventas bibliotek' => 'pārventas bibliotēka',
            'gāliņciema bibliotēk' => 'gāliņciema bibliotēka',
            'galinciema bibliotek' => 'gāliņciema bibliotēka',
            'gāliņciema sabiedrisk' => 'gāliņciema bibliotēka',
            'galvenā bibliotēk' => 'galvenā bibliotēka',
            'galvena bibliotek' => 'galvenā bibliotēka',
            'bērnu bibliotēk' => 'bērnu bibliotēka',
            'bernu bibliotek' => 'bērnu bibliotēka',
            'reņķa dārz' => 'reņķa dārzs',
            'renka darz' => 'reņķa dārzs',
            'bērnu pilsētiņ' => 'bērnu pilsētiņa',
            'bernu pilsetin' => 'bērnu pilsētiņa',
            'fantāzij' => 'bērnu parks "fantāzija"',
            'fantazij' => 'bērnu parks "fantāzija"',
            'lielais laukum' => 'lielais laukums',
            'ostas ielas promenād' => 'ostas ielas promenāde',
            'ventspils tirg' => 'ventspils tirgus',
            'tirgus laukum' => 'ventspils tirgus',
            'jaunrades nam' => 'ventspils jaunrades nams',
        ];

        foreach ($stemVenueMap as $stem => $mapKey) {
            if (str_contains($text, $stem) && isset($this->venueAddressMap[$mapKey])) {
                return $this->venueAddressMap[$mapKey];
            }
        }

        // Ventspils novads parishes / towns
        $novadsMap = [
            'jūrkaln' => ['name' => 'Jūrkalnes tautas nams', 'city' => 'Jūrkalne', 'address' => 'Jūrkalne, Ventspils novads'],
            'jurkaln' => ['name' => 'Jūrkalnes tautas nams', 'city' => 'Jūrkalne', 'address' => 'Jūrkalne, Ventspils novads'],
            'pilten' => ['name' => 'Piltenes kultūras nams', 'city' => 'Piltene', 'address' => 'Lielā iela 19, Piltene, Ventspils novads'],
            'ugāl' => ['name' => 'Ugāles tautas nams "Gaisma"', 'city' => 'Ugāle', 'address' => 'Ugāle, Ventspils novads'],
            'ugal' => ['name' => 'Ugāles tautas nams "Gaisma"', 'city' => 'Ugāle', 'address' => 'Ugāle, Ventspils novads'],
            'usm' => ['name' => 'Usmas tautas nams', 'city' => 'Usma', 'address' => 'Usma, Ventspils novads'],
            'zir' => ['name' => 'Ziru tautas nams', 'city' => 'Ziras', 'address' => 'Ziras, Ventspils novads'],
            'zlēk' => ['name' => 'Zlēku kultūras nams', 'city' => 'Zlēkas', 'address' => 'Zlēkas, Ventspils novads'],
            'zlek' => ['name' => 'Zlēku kultūras nams', 'city' => 'Zlēkas', 'address' => 'Zlēkas, Ventspils novads'],
            'pop' => ['name' => 'Popes kultūras nams', 'city' => 'Pope', 'address' => 'Pope, Ventspils novads'],
            'anc' => ['name' => 'Ances kultūras nams', 'city' => 'Ance', 'address' => 'Ance, Ventspils novads'],
            'puz' => ['name' => 'Puzes kultūras nams', 'city' => 'Puze', 'address' => 'Puze, Ventspils novads'],
            'tārgal' => ['name' => 'Tārgales pamatskola', 'city' => 'Tārgale', 'address' => 'Tārgale, Ventspils novads'],
            'targal' => ['name' => 'Tārgales pamatskola', 'city' => 'Tārgale', 'address' => 'Tārgale, Ventspils novads'],
            'užav' => ['name' => 'Užavas tautas nams', 'city' => 'Užava', 'address' => 'Užava, Ventspils novads'],
            'uzav' => ['name' => 'Užavas tautas nams', 'city' => 'Užava', 'address' => 'Užava, Ventspils novads'],
            'vārv' => ['name' => 'Vārves muiža', 'city' => 'Vārve', 'address' => 'Vārve, Ventspils novads'],
            'varv' => ['name' => 'Vārves muiža', 'city' => 'Vārve', 'address' => 'Vārve, Ventspils novads'],
        ];

        foreach ($novadsMap as $stem => $info) {
            if (str_contains($text, $stem)) {
                return [
                    'name' => $info['name'],
                    'city' => $info['city'],
                    'region' => 'Kurzeme',
                    'address' => $info['address'],
                    'place_type' => 'cultural_center',
                ];
            }
        }

        $cleanLoc = trim($locationRaw);
        $venueName = !empty($cleanLoc) ? $cleanLoc : 'Ventspils';
        $address = !empty($cleanLoc) ? "{$cleanLoc}, Ventspils" : 'Ventspils, LV-3601';

        return [
            'name' => $venueName,
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => $address,
            'place_type' => 'cultural_center',
        ];
    }

    /**
     * Resolve categories adhering strictly to AGENTS.md
     */
    public function resolveCategories(string $locationRaw, string $title, string $description = ''): array
    {
        $text = mb_strtolower($locationRaw . ' ' . $title . ' ' . $description, 'UTF-8');

        // STRICT RULE 1: Cinema / Kino (Priority)
        // Kino Rio venue or film keywords -> ALWAYS KINO, NEVER TEATRIS
        // Note: kinoteātris contains "teātr", but MUST NEVER be classified as Teātris
        $cinemaKeywords = [
            'kino rio', 'kinoteātr', 'kinoteatr',
            'filma', 'filmas', 'filmu', 'filmā',
            'kinoseanss', 'filmas seanss', 'spēlfilma',
            'dokumentālā filma', 'animācijas filma', 'kinofestivāls',
            'kino seanss', 'kino:', 'kino |',
        ];

        $isCinema = false;
        foreach ($cinemaKeywords as $kw) {
            if (str_contains($text, $kw)) {
                $isCinema = true;
                break;
            }
        }

        if ($isCinema) {
            $categories = ['Kino'];
            if (str_contains($text, 'bērniem') || str_contains($text, 'animācija') || str_contains($text, 'multfilma')) {
                $categories[] = 'Bērniem';
            }
            return array_unique($categories);
        }

        // STRICT RULE 2: Theaters
        // Only actual theater productions (izrāde, luga, iestudējums, balets, opera)
        $theatreKeywords = [
            'teātra izrāde', 'izrāde', 'izrādē', 'izrādi', 'izrādes', 'iestudējums',
            'luga', 'lugā', 'komēdija', 'traģēdija', 'stāvizrāde',
            'stand-up', 'standup', 'leļļu izrāde', 'balets', 'opera',
        ];

        $isTheatre = false;
        foreach ($theatreKeywords as $kw) {
            if (str_contains($text, $kw)) {
                $isTheatre = true;
                break;
            }
        }

        if ($isTheatre) {
            $categories = ['Teātris'];
            if (str_contains($text, 'bērniem') || str_contains($text, 'ģimenēm') || str_contains($text, 'leļļu')) {
                $categories[] = 'Bērniem';
            }
            return array_unique($categories);
        }

        // Music & Concerts
        $musicKeywords = [
            'koncerts', 'koncertā', 'koncertprogramma', 'dziedātājs',
            'koris', 'koru', 'orķestris', 'orķestra', 'kvartets',
            'akustiskā tūre', 'dziesmas', 'muzikāls', 'džezs', 'roks', 'balle',
            'grupa', 'grupas', 'koncertzāle "latvija"',
        ];

        $isMusic = false;
        foreach ($musicKeywords as $kw) {
            if (str_contains($text, $kw)) {
                $isMusic = true;
                break;
            }
        }

        if ($isMusic) {
            $categories = ['Mūzika'];
            if (str_contains($text, 'bērniem') || str_contains($text, 'ģimenēm')) {
                $categories[] = 'Bērniem';
            }
            return array_unique($categories);
        }

        // Sports
        $sportsKeywords = [
            'sports', 'čempionāt', 'cempionat', 'spēle', 'spele', 'spēles', 'speles',
            'basketbols', 'basketbola', 'hokejs', 'hokeja', 'skrējiens', 'maratons',
            'pusmaratons', 'slidošana', 'turnīrs', 'turnirs', 'volejbols', 'peldēšanā',
            'peldesana', 'bokss', 'boksa', 'skeitparks', 'jāšanas',
        ];

        foreach ($sportsKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return ['Sports'];
            }
        }

        // Exhibitions & Art & Museums
        $exhibitionKeywords = [
            'izstāde', 'izstādē', 'izstādi', 'izstādes', 'ekspozīcija',
            'personālizstāde', 'gleznas', 'glezniecība', 'tēlniecība',
            'fotogrāfiju izstāde', 'krājuma izstāde',
        ];

        foreach ($exhibitionKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return ['Izstādes'];
            }
        }

        // Children / Family
        if (str_contains($text, 'bērniem') || str_contains($text, 'bērnu pilsētiņ') || str_contains($text, 'leļļu') || str_contains($text, 'ģimenēm')) {
            return ['Bērniem'];
        }

        // Fallback: Cits
        return ['Cits'];
    }

    /**
     * Resolve entertainment type
     */
    public function resolveEntertainmentType(array $categories, string $title, string $description = ''): string
    {
        $text = mb_strtolower($title . ' ' . $description, 'UTF-8');

        if (in_array('Kino', $categories, true)) {
            return 'movie';
        }
        if (in_array('Mūzika', $categories, true)) {
            return 'concert';
        }
        if (in_array('Teātris', $categories, true)) {
            return 'theatre';
        }
        if (in_array('Izstādes', $categories, true)) {
            return 'exhibition';
        }
        if (in_array('Sports', $categories, true)) {
            return 'sports';
        }
        if (str_contains($text, 'festivāls') || str_contains($text, 'svētki') || str_contains($text, 'tirdziņš') || str_contains($text, 'tirgus')) {
            return 'festival';
        }

        return 'event';
    }

    /**
     * Parse price and free status
     */
    public function parsePrice(string $statusText, string $description = '', ?string $ticketUrl = null): array
    {
        $combined = mb_strtolower($statusText . ' ' . $description, 'UTF-8');

        if (str_contains($combined, 'bezmaksas') || str_contains($combined, 'bez maksas') || str_contains($combined, 'ieeja brīva')) {
            return [
                'is_free' => true,
                'price_min' => null,
                'price_max' => null,
            ];
        }

        $isFree = empty($ticketUrl);
        $priceMin = null;
        $priceMax = null;

        if (preg_match('/(\d+(?:[.,]\d{2})?)\s*-\s*(\d+(?:[.,]\d{2})?)\s*(?:€|eur)/iu', $description, $m)) {
            $isFree = false;
            $priceMin = (float) str_replace(',', '.', $m[1]);
            $priceMax = (float) str_replace(',', '.', $m[2]);
        } elseif (preg_match('/(?:biļet[a-z]*|maksa|cena)[:\s]+(\d+(?:[.,]\d{2})?)\s*(?:€|eur)/iu', $description, $m)) {
            $isFree = false;
            $priceMin = (float) str_replace(',', '.', $m[1]);
            $priceMax = $priceMin;
        } elseif (preg_match('/(\d+(?:[.,]\d{2})?)\s*(?:€|eur)/iu', $description, $m)) {
            $val = (float) str_replace(',', '.', $m[1]);
            if ($val > 0) {
                $isFree = false;
                $priceMin = $val;
                $priceMax = $val;
            }
        }

        if (!empty($ticketUrl) && $priceMin === null) {
            $isFree = false;
        }

        return [
            'is_free' => $isFree,
            'price_min' => $priceMin,
            'price_max' => $priceMax,
        ];
    }

    public function isNonEvent(string $title): bool
    {
        $lower = mb_strtolower(trim($title));
        if (empty($lower)) {
            return true;
        }

        $blacklisted = [
            'darba laiks',
            'kases darba laiks',
            'kontakti',
            'repertuārs',
        ];

        return in_array($lower, $blacklisted, true);
    }

    public function cleanText(?string $text): string
    {
        if (empty($text)) {
            return '';
        }
        $cleaned = parent::cleanText($text);
        return trim($cleaned ?? '');
    }

    protected function normalizeUrl(?string $relUrl): ?string
    {
        if (empty($relUrl)) {
            return null;
        }

        if (str_starts_with($relUrl, 'http://') || str_starts_with($relUrl, 'https://')) {
            $parsed = parse_url($relUrl);
            return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'www.visitventspils.com') . ($parsed['path'] ?? '');
        }

        $path = strtok($relUrl, '?');
        return self::BASE_URL . (str_starts_with($path, '/') ? '' : '/') . $path;
    }
}
