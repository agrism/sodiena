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

class LiepajaKalendarsScraper extends BaseScraper
{
    /**
     * Map of known Liepāja venues to normalized names, addresses, and place types
     */
    protected array $venueMap = [
        'lielais dzintars' => [
            'name' => 'Koncertzāle "Lielais dzintars"',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Radio iela 8, Liepāja, LV-3401',
            'place_type' => 'concert_hall',
        ],
        'liepājas teātr' => [
            'name' => 'Liepājas teātris',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Teātra iela 4, Liepāja, LV-3401',
            'place_type' => 'theatre',
        ],
        'leļļu teātr' => [
            'name' => 'Liepājas Leļļu teātris',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Rožu laukums 5/6, Liepāja, LV-3401',
            'place_type' => 'theatre',
        ],
        'neatkarīgais teātr' => [
            'name' => 'Liepājas Neatkarīgais teātris',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Dārza iela 42, Liepāja, LV-3401',
            'place_type' => 'theatre',
        ],
        'ledus halle' => [
            'name' => 'LOC Ledus halle',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Brīvības iela 1, Liepāja, LV-3401',
            'place_type' => 'sports_complex',
        ],
        'tenisa halle' => [
            'name' => 'LOC Tenisa halle',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Liedaga iela 7, Liepāja, LV-3401',
            'place_type' => 'sports_complex',
        ],
        'loc manēž' => [
            'name' => 'LOC Manēža',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Brīvības iela 55, Liepāja, LV-3401',
            'place_type' => 'sports_complex',
        ],
        'olimpiskais centrs' => [
            'name' => 'Liepājas Olimpiskais centrs',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Brīvības iela 39, Liepāja, LV-3401',
            'place_type' => 'sports_complex',
        ],
        'fontaine palace' => [
            'name' => 'Mūzikas klubs "Fontaine Palace"',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Dzirnavu iela 4, Liepāja, LV-3401',
            'place_type' => 'club',
        ],
        'latviešu biedrības nams' => [
            'name' => 'Liepājas Latviešu biedrības nams',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Rožu laukums 5/6, Liepāja, LV-3401',
            'place_type' => 'cultural_center',
        ],
        'romas dārzs' => [
            'name' => 'Galerija "Romas dārzs"',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Zivju iela 3, Liepāja, LV-3401',
            'place_type' => 'gallery',
        ],
        'pegaza pagalms' => [
            'name' => 'Kultūras vieta "Pegaza pagalms"',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Kuršu iela 20, Liepāja, LV-3401',
            'place_type' => 'cultural_center',
        ],
        'rožu laukums' => [
            'name' => 'Rožu laukums',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Rožu laukums, Liepāja, LV-3401',
            'place_type' => 'square',
        ],
        'čakstes laukums' => [
            'name' => 'Jāņa Čakstes laukums',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Jāņa Čakstes laukums, Liepāja, LV-3401',
            'place_type' => 'square',
        ],
        'liepājas muzejs' => [
            'name' => 'Liepājas muzejs',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Kūrmājas prospekts 16/18, Liepāja, LV-3401',
            'place_type' => 'museum',
        ],
        'liepajas muzejs' => [
            'name' => 'Liepājas muzejs',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Kūrmājas prospekts 16/18, Liepāja, LV-3401',
            'place_type' => 'museum',
        ],
        'okupācijas muzejs' => [
            'name' => 'Liepājas 1945.–1990. gada okupācijas muzejs',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'K. Ukstiņa iela 7/9, Liepāja, LV-3401',
            'place_type' => 'museum',
        ],
        'cinamon' => [
            'name' => 'Kinoteātris "Cinamon Liepaja Balle"',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'K. Zāles laukums 8, Liepāja, LV-3401',
            'place_type' => 'cinema',
        ],
        'redāns' => [
            'name' => 'Karostas cietoksnis - Redāns',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => '14. novembra bulvāris 82/86, Liepāja, LV-3402',
            'place_type' => 'museum',
        ],
        'atmodas dārzs' => [
            'name' => 'Kultūras vieta "Atmodas Dārzs"',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Atmodas bulvāris 6a, Liepāja, LV-3402',
            'place_type' => 'cultural_center',
        ],
        'beberliņ' => [
            'name' => 'Ziemas peldvieta Beberliņos',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Lībiešu iela, Beberliņi, Karosta, Liepāja, LV-3414',
            'place_type' => 'park',
        ],
        'jūrmalas parks' => [
            'name' => 'Liepājas Jūrmalas parks',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Jūrmalas parks, Liepāja, LV-3401',
            'place_type' => 'park',
        ],
        'ludviķis' => [
            'name' => 'Mākslas salons "Ludviķis"',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Ludviķa iela 3/5, Liepāja, LV-3401',
            'place_type' => 'gallery',
        ],
        'vinilkoks' => [
            'name' => 'Mūzikas telpa - galerija "Vinilkoks"',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Kūrmājas prospekts 30, Liepāja, LV-3401',
            'place_type' => 'gallery',
        ],
        'elpub' => [
            'name' => 'Krodziņš "Elpub"',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'M. Dzirnavu iela 2, Liepāja, LV-3401',
            'place_type' => 'restaurant',
        ],
        'pastnieka māja' => [
            'name' => 'Restorāns "Pastnieka māja"',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Fr. Brīvzemnieka iela 53, Liepāja, LV-3401',
            'place_type' => 'restaurant',
        ],
        'mūzikas, mākslas un dizaina vidusskola' => [
            'name' => 'Liepājas Mūzikas, mākslas un dizaina vidusskola',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Alejas iela 18, Liepāja, LV-3401',
            'place_type' => 'school',
        ],
        'lmmdv' => [
            'name' => 'Liepājas Mūzikas, mākslas un dizaina vidusskola',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Alejas iela 18, Liepāja, LV-3401',
            'place_type' => 'school',
        ],
        'lodīte' => [
            'name' => 'Pneimatiskā šautuve "Lodīte"',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Graudu iela 43, Liepāja, LV-3401',
            'place_type' => 'sports_complex',
        ],
    ];

    public function getSlug(): string
    {
        return 'liepaja-kalendars';
    }

    public function getName(): string
    {
        return 'Liepājas pasākumu kalendārs';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $baseUrl = $source->url ?: 'https://kalendars.liepaja.lv/lv/';
        $today = Carbon::today('Europe/Riga');
        $maxPages = 15;

        $visitedDetailUrls = [];
        $seenExternalIds = [];

        $currentUrl = $baseUrl;

        for ($page = 1; $page <= $maxPages; $page++) {
            $crawler = $this->fetchCrawler($currentUrl);
            if (!$crawler) {
                Log::warning("LiepajaKalendarsScraper: Failed to fetch page {$currentUrl}");
                break;
            }

            $candidateLinks = [];

            // 1. Featured special offers on page 1
            if ($page === 1) {
                $crawler->filter('ul.special-offers li a')->each(function (Crawler $a) use (&$candidateLinks) {
                    $href = $a->attr('href');
                    if ($href) {
                        $candidateLinks[] = $this->normalizeUrl($href);
                    }
                });
            }

            // 2. Events list on current page
            $crawler->filter('ul#events li a.title')->each(function (Crawler $a) use (&$candidateLinks) {
                $href = $a->attr('href');
                if ($href) {
                    $candidateLinks[] = $this->normalizeUrl($href);
                }
            });

            // Process collected event detail links
            foreach (array_unique($candidateLinks) as $detailUrl) {
                if (isset($visitedDetailUrls[$detailUrl])) {
                    continue;
                }
                $visitedDetailUrls[$detailUrl] = true;

                try {
                    $dto = $this->parseDetailPage($detailUrl, $today);
                    if ($dto) {
                        if (isset($seenExternalIds[$dto->sourceExternalId])) {
                            continue;
                        }
                        $seenExternalIds[$dto->sourceExternalId] = true;
                        $events->push($dto);
                    }
                } catch (\Throwable $e) {
                    Log::warning("LiepajaKalendarsScraper: Failed to parse detail page {$detailUrl}: " . $e->getMessage(), [
                        'exception' => $e,
                    ]);
                }
            }

            Log::info("LiepajaKalendarsScraper: Completed page {$page}, collected events: " . $events->count());

            // Next page pagination
            $nextBtn = $crawler->filter('#events-loadmore-btn');
            if (!$nextBtn->count() || !$nextBtn->attr('data-url')) {
                break;
            }

            $nextDataUrl = trim($nextBtn->attr('data-url'));
            if (empty($nextDataUrl)) {
                break;
            }

            $currentUrl = 'https://kalendars.liepaja.lv' . $nextDataUrl;
        }

        return $events;
    }

    /**
     * Parse a single event detail page into ScrapedEventDTO
     */
    public function parseDetailPage(string $detailUrl, ?Carbon $today = null): ?ScrapedEventDTO
    {
        $today = $today ?: Carbon::today('Europe/Riga');
        $crawler = $this->fetchCrawler($detailUrl);
        if (!$crawler) {
            return null;
        }

        // Title
        $titleNode = $crawler->filter('.event-open h3');
        if (!$titleNode->count()) {
            return null;
        }
        $title = $this->cleanText($titleNode->text());
        if (empty($title)) {
            return null;
        }

        // Extract metadata from info icons list
        $dateText = '';
        $venueRaw = '';
        $addressRaw = '';
        $priceText = '';
        $ticketUrl = null;

        $crawler->filter('ul.event-open-info-icons li')->each(function (Crawler $li) use (&$dateText, &$venueRaw, &$addressRaw, &$priceText, &$ticketUrl) {
            $img = $li->filter('img');
            $iconSrc = $img->count() ? ($img->attr('src') ?? '') : '';

            if (str_contains($iconSrc, 'calendar-icon')) {
                $dateText = trim($li->text());
            } elseif (str_contains($iconSrc, 'pin-icon')) {
                $container = $li->filter('.event-map-container');
                $venueRaw = $container->count() ? trim($container->text()) : trim($li->text());
                $addressInput = $li->filter('input#address');
                if ($addressInput->count()) {
                    $addressRaw = trim($addressInput->attr('value') ?? '');
                }
            } elseif (str_contains($iconSrc, 'ticket-icon')) {
                $priceText = trim($li->text());
            }

            $btn = $li->filter('a.button');
            if ($btn->count()) {
                $href = trim($btn->attr('href') ?? '');
                if ($this->isValidHttpUrl($href)) {
                    $ticketUrl = $this->normalizeUrl($href);
                }
            }
        });

        // Parse date and time
        [$startAt, $endAt] = $this->parseDates($dateText, $today);
        if (!$startAt) {
            return null;
        }

        // Strict AGENTS.md rule: Do not process past events
        if ($endAt && $endAt->lt($today)) {
            return null;
        }
        if (!$endAt && $startAt->lt($today)) {
            return null;
        }

        // Location & Coordinates normalization
        $locationMeta = $this->resolveLocationMeta($venueRaw, $addressRaw, $title);

        // Price & Free status
        [$isFree, $priceMin, $priceMax] = $this->parsePrice($priceText);

        // Additional info (organizer, website, Facebook)
        $organizer = null;
        $externalWebsite = null;
        $crawler->filter('ul.event-info.event-info-list li')->each(function (Crawler $li) use (&$organizer, &$externalWebsite) {
            $text = trim($li->text());
            if (str_contains($text, 'Rīkotājs:')) {
                $organizer = trim(str_replace('Rīkotājs:', '', $text));
            } elseif (str_contains($text, 'Mājaslapa:')) {
                $link = $li->filter('a[href]');
                if ($link->count()) {
                    $externalWebsite = trim($link->attr('href') ?? '');
                }
            }
        });

        // Ticket fallback
        if (!$ticketUrl && $externalWebsite && $this->isValidHttpUrl($externalWebsite)) {
            $lowerSite = mb_strtolower($externalWebsite);
            if (str_contains($lowerSite, 'bilesuparadize') || str_contains($lowerSite, 'bilesuserviss') || str_contains($lowerSite, 'fienta') || str_contains($lowerSite, 'bezrindas')) {
                $ticketUrl = $this->normalizeUrl($externalWebsite);
            }
        }

        // Image parsing (skip generic placeholders)
        $imageUrl = null;
        $imageNode = $crawler->filter('.images img');
        if ($imageNode->count()) {
            $src = trim($imageNode->attr('src') ?? '');
            if (!empty($src) && !str_contains($src, 'defaults/') && !str_contains($src, 'default.jpg')) {
                $imageUrl = $this->normalizeUrl($src);
            }
        }

        // Description parsing
        $descNode = $crawler->filter('.description');
        $description = $descNode->count() ? $this->cleanText($descNode->text()) : $title;
        if (empty($description)) {
            $description = $title;
        }

        // Categories & Entertainment Type (adhering strictly to AGENTS.md)
        $categories = $this->resolveCategories($title, $venueRaw);
        $entertainmentType = $this->resolveEntertainmentType($categories, $title, $venueRaw);

        // External ID extraction
        $externalId = $this->extractExternalId($detailUrl, $startAt);

        return new ScrapedEventDTO(
            title: $title,
            startAt: $startAt,
            endAt: $endAt,
            description: $description,
            shortDescription: Str::limit($description, 160),
            venueName: $locationMeta['name'],
            city: $locationMeta['city'],
            region: $locationMeta['region'],
            address: $locationMeta['address'],
            latitude: $locationMeta['latitude'] ?? null,
            longitude: $locationMeta['longitude'] ?? null,
            placeType: $locationMeta['place_type'],
            categoryNames: $categories,
            entertainmentType: $entertainmentType,
            isFree: $isFree,
            priceMin: $priceMin,
            priceMax: $priceMax,
            currency: 'EUR',
            ticketUrl: $ticketUrl ?: $detailUrl,
            imageUrl: $imageUrl,
            sourceUrl: $detailUrl,
            sourceExternalId: $externalId,
            locale: 'lv',
            rawData: [
                'organizer' => $organizer,
                'venue_raw' => $venueRaw,
                'address_raw' => $addressRaw,
                'date_text' => $dateText,
                'price_text' => $priceText,
            ]
        );
    }

    /**
     * Parse date text into start and end Carbon instances
     */
    public function parseDates(string $dateText, ?Carbon $today = null): array
    {
        $dateText = trim($dateText);
        if (empty($dateText)) {
            return [null, null];
        }

        // 1. Single day with time range: "13.11.2026. 21.00–23.59"
        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})\.?\s+(\d{1,2})[\.:](\d{2})\s*[–\-—]\s*(\d{1,2})[\.:](\d{2})/u', $dateText, $m)) {
            $startAt = Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1], 'Europe/Riga')
                ->setTime((int) $m[4], (int) $m[5], 0);
            $endAt = Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1], 'Europe/Riga')
                ->setTime((int) $m[6], (int) $m[7], 0);

            if ($endAt->lessThan($startAt)) {
                $endAt->addDay();
            } elseif ($endAt->equalTo($startAt)) {
                $endAt = null;
            }

            return [$startAt, $endAt];
        }

        // 2. Single day with single time: "14.11.2026. 18.00"
        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})\.?\s+(\d{1,2})[\.:](\d{2})/u', $dateText, $m)) {
            $startAt = Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1], 'Europe/Riga')
                ->setTime((int) $m[4], (int) $m[5], 0);

            return [$startAt, null];
        }

        // 3. Multi-day range: "13.11.2026.–15.11.2026."
        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})\.?\s*[–\-—]\s*(\d{1,2})\.(\d{1,2})\.(\d{4})\.?/u', $dateText, $m)) {
            $startAt = Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1], 'Europe/Riga')->startOfDay();
            $endAt = Carbon::createFromDate((int) $m[6], (int) $m[5], (int) $m[4], 'Europe/Riga')->endOfDay();

            return [$startAt, $endAt];
        }

        // 4. Single date: "13.11.2026."
        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $dateText, $m)) {
            $startAt = Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1], 'Europe/Riga')->startOfDay();

            return [$startAt, null];
        }

        return [null, null];
    }

    /**
     * Parse price string into [isFree, priceMin, priceMax]
     */
    public function parsePrice(?string $priceText): array
    {
        $clean = trim($priceText ?? '');
        if (empty($clean)) {
            return [false, null, null];
        }

        $lower = mb_strtolower($clean, 'UTF-8');
        if (str_contains($lower, 'bezmaksas')) {
            return [true, 0.0, 0.0];
        }

        // Range: "5–10 €" or "10,00–12.00 €"
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*[–\-—]\s*(\d+(?:[.,]\d+)?)/u', $clean, $m)) {
            $min = (float) str_replace(',', '.', $m[1]);
            $max = (float) str_replace(',', '.', $m[2]);
            return [false, $min, $max];
        }

        // Single price: "12,00 €" or "9 €"
        if (preg_match('/(\d+(?:[.,]\d+)?)/u', $clean, $m)) {
            $val = (float) str_replace(',', '.', $m[1]);
            return [false, $val, $val];
        }

        return [false, null, null];
    }

    /**
     * Resolve location metadata, coordinates, and venue details
     */
    public function resolveLocationMeta(?string $venueRaw, ?string $addressRaw = '', string $title = ''): array
    {
        $venueClean = trim($venueRaw ?? '');
        $venueLower = mb_strtolower($venueClean, 'UTF-8');
        $titleLower = mb_strtolower($title, 'UTF-8');
        $addressClean = trim($addressRaw ?? '');

        // Coordinates check in address
        $latitude = null;
        $longitude = null;
        if (preg_match('/^\s*(\d{2}\.\d+)\s*,\s*(\d{2}\.\d+)\s*$/', $addressClean, $coordMatches)) {
            $latitude = (float) $coordMatches[1];
            $longitude = (float) $coordMatches[2];
            $addressClean = !empty($venueClean) ? "{$venueClean}, Liepāja, LV-3401" : 'Liepāja, LV-3401';
        }

        // Default metadata
        $meta = [
            'name' => !empty($venueClean) ? $venueClean : 'Liepāja',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => !empty($addressClean) ? $this->cleanAddressString($addressClean) : 'Liepāja, Kurzeme',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'place_type' => 'venue',
        ];

        // 1. Direct dictionary match
        foreach ($this->venueMap as $key => $known) {
            if (str_contains($venueLower, $key) || str_contains($titleLower, $key)) {
                $meta['name'] = $known['name'];
                $meta['city'] = $known['city'];
                $meta['region'] = $known['region'];
                $meta['place_type'] = $known['place_type'];
                if (empty($latitude) && !empty($known['address'])) {
                    $meta['address'] = $known['address'];
                }
                return $meta;
            }
        }

        // 2. Generic heuristics
        if (str_contains($venueLower, 'baznīc') || str_contains($venueLower, 'katedrāl')) {
            $meta['place_type'] = 'church';
        } elseif (str_contains($venueLower, 'muzej')) {
            $meta['place_type'] = 'museum';
        } elseif (str_contains($venueLower, 'galerij') || str_contains($venueLower, 'mākslas salon')) {
            $meta['place_type'] = 'gallery';
        } elseif (str_contains($venueLower, 'teātr')) {
            $meta['place_type'] = 'theatre';
        } elseif (str_contains($venueLower, 'kino')) {
            $meta['place_type'] = 'cinema';
        } elseif (str_contains($venueLower, 'sport') || str_contains($venueLower, 'halle') || str_contains($venueLower, 'manēž') || str_contains($venueLower, 'stadion') || str_contains($venueLower, 'šautuv')) {
            $meta['place_type'] = 'sports_complex';
        } elseif (str_contains($venueLower, 'parks') || str_contains($venueLower, 'dārzs')) {
            $meta['place_type'] = 'park';
        } elseif (str_contains($venueLower, 'laukums')) {
            $meta['place_type'] = 'square';
        } elseif (str_contains($venueLower, 'skol') || str_contains($venueLower, 'vidusskol')) {
            $meta['place_type'] = 'school';
        } elseif (str_contains($venueLower, 'klubs') || str_contains($venueLower, 'bārs')) {
            $meta['place_type'] = 'club';
        } elseif (str_contains($venueLower, 'restorān') || str_contains($venueLower, 'kafejnīc') || str_contains($venueLower, 'krodziņ')) {
            $meta['place_type'] = 'restaurant';
        }

        return $meta;
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
            str_contains($venueLower, 'cinamon') ||
            str_contains($venueLower, 'kino ') ||
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
        if (str_contains($venueLower, 'muzejs') || str_contains($venueLower, 'galerija') || str_contains($titleLower, 'izstād') || str_contains($titleLower, 'ekspozīcij') || str_contains($titleLower, 'gleznu') || str_contains($titleLower, 'fotogrāfij') || str_contains($titleLower, 'mākslas darbi')) {
            $cats[] = 'Izstādes';
        }

        // 3. Theater Productions (NEVER cinema/film)
        $isTheatreMention = str_contains($fullText, 'liepājas teātr') || str_contains($fullText, 'leļļu teātr') || str_contains($fullText, 'neatkarīgais teātr') || (str_contains($fullText, 'teātr') && !str_contains($fullText, 'kinoteātr'));
        $isTheatreContent = str_contains($titleLower, 'izrād') || str_contains($titleLower, 'luga') || str_contains($titleLower, 'iestudējum') || str_contains($titleLower, 'stāvizrād') || str_contains($titleLower, 'stand-up') || str_contains($titleLower, 'komēdij') || str_contains($titleLower, 'monoizrād') || str_contains($titleLower, 'monodrām') || str_contains($titleLower, 'opera') || str_contains($titleLower, 'balets');

        if (!$isCinema && !str_contains($fullText, 'kinoteātr') && ($isTheatreMention || $isTheatreContent)) {
            $cats[] = 'Teātris';
        }

        // 4. Music / Concerts
        if (str_contains($titleLower, 'koncert') || str_contains($titleLower, 'dziesm') || str_contains($titleLower, 'orķestr') || str_contains($titleLower, 'koris') || str_contains($titleLower, 'džez') || str_contains($titleLower, 'muzikāl') || str_contains($titleLower, 'rokkoncert') || str_contains($titleLower, 'karaoke') || str_contains($venueLower, 'fontaine palace')) {
            $cats[] = 'Mūzika';
        }

        // 5. Sports
        $isSportsVenue = str_contains($venueLower, 'ledus halle') || str_contains($venueLower, 'tenisa halle') || str_contains($venueLower, 'loc manēž') || str_contains($venueLower, 'slidotava') || (str_contains($venueLower, 'olimpiskais centrs') && !str_contains($titleLower, 'koncert'));
        $isSportsContent = str_contains($titleLower, 'sports') || str_contains($titleLower, 'kauss') || str_contains($titleLower, 'čempionāt') || str_contains($titleLower, 'skrējiens') || str_contains($titleLower, 'turnīrs') || str_contains($titleLower, 'basketbol') || str_contains($titleLower, 'futbol') || str_contains($titleLower, 'hokej') || str_contains($titleLower, 'florbol') || str_contains($titleLower, 'teniss') || str_contains($titleLower, 'peldēšan') || str_contains($titleLower, 'slidotava') || str_contains($titleLower, 'pret ');

        if ($isSportsVenue || $isSportsContent) {
            $cats[] = 'Sports';
        }

        // 6. Kids / Families
        if (str_contains($venueLower, 'leļļu teātris') || str_contains($titleLower, 'bērniem') || str_contains($titleLower, 'mazuļ') || str_contains($titleLower, 'leļļu') || str_contains($titleLower, 'pasak') || str_contains($titleLower, 'ģimenēm')) {
            $cats[] = 'Bērniem';
        }

        // 7. Workshops / Seminars
        if (str_contains($titleLower, 'darbnīca') || str_contains($titleLower, 'meistarklas') || str_contains($titleLower, 'seminār') || str_contains($titleLower, 'lekcij') || str_contains($titleLower, 'nodarbība') || str_contains($titleLower, 'saruna') || str_contains($titleLower, 'diskusija')) {
            $cats[] = 'Semināri';
        }

        // 8. Holidays / Celebrations
        if (str_contains($titleLower, 'svētk') || str_contains($titleLower, 'balle') || str_contains($titleLower, 'tirdziņ') || str_contains($titleLower, 'gadatirg') || str_contains($titleLower, 'egles iedegšan') || str_contains($titleLower, 'gadadien')) {
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

        if (in_array('Kino', $categories, true) || str_contains($venueLower, 'cinamon') || str_contains($titleLower, 'filma') || str_contains($titleLower, 'kinoseans')) {
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
     * Extract external ID deterministically from detail page URL and start timestamp
     */
    protected function extractExternalId(string $detailUrl, Carbon $startAt): string
    {
        if (preg_match('#/lv/(\d{4}-\d{2}-\d{2}):(\d+)(?::(\d+))?#', $detailUrl, $m)) {
            $eventId = $m[2];
            $sessionId = $m[3] ?? null;

            if ($sessionId) {
                return "liepaja-kalendars-{$eventId}-{$sessionId}";
            }

            return "liepaja-kalendars-{$eventId}-" . $startAt->format('YmdHi');
        }

        return 'liepaja-kalendars-' . substr(md5($detailUrl . $startAt->format('YmdHi')), 0, 16);
    }

    /**
     * Clean and normalize absolute URL
     */
    protected function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (str_starts_with($url, '/')) {
            return 'https://kalendars.liepaja.lv' . $url;
        }

        return $url;
    }

    /**
     * Check if a string is a valid absolute HTTP/HTTPS URL
     */
    protected function isValidHttpUrl(?string $url): bool
    {
        if (empty($url)) {
            return false;
        }

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }

    /**
     * Clean address string by removing duplicates like "Liepāja, Liepāja"
     */
    protected function cleanAddressString(string $address): string
    {
        $address = preg_replace('/\bLiepāja,\s*Liepāja\b/u', 'Liepāja', $address);
        $address = preg_replace('/\s+,/u', ',', $address);
        $address = preg_replace('/,{2,}/u', ',', $address);

        return trim($address, " \t\n\r\0\x0B,");
    }
}
