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

class SiguldaNovadsScraper extends BaseScraper
{
    /**
     * Map of known Siguldas novads venues to normalized names and addresses
     */
    protected array $venueMap = [
        'siguldas devons' => [
            'name' => 'Kultūras centrs "Siguldas DEVONS"',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Pils iela 10, Sigulda, LV-2150',
            'place_type' => 'cultural_center',
        ],
        'baltais flīģelis' => [
            'name' => 'Koncertzāle "Baltais Flīģelis"',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Šveices iela 19, Sigulda, LV-2150',
            'place_type' => 'concert_hall',
        ],
        'siguldas jaunā pils' => [
            'name' => 'Siguldas Jaunā pils',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Pils iela 16, Sigulda, LV-2150',
            'place_type' => 'castle',
        ],
        'siguldas sporta centrs' => [
            'name' => 'Siguldas Sporta centrs',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Ata Kronvalda iela 7A, Sigulda, LV-2150',
            'place_type' => 'sports_complex',
        ],
        'siguldas novada bibliotēka' => [
            'name' => 'Siguldas novada bibliotēka',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Leona Paegles iela 3, Sigulda, LV-2150',
            'place_type' => 'library',
        ],
        'siguldas novada jaunrades centrs' => [
            'name' => 'Siguldas novada Jaunrades centrs',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Mednieku iela 3, Sigulda, LV-2150',
            'place_type' => 'cultural_center',
        ],
        'siguldas valsts ģimnāzija' => [
            'name' => 'Siguldas Valsts ģimnāzija',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Šveices iela 13, Sigulda, LV-2150',
            'place_type' => 'school',
        ],
        'siguldas pagasta kultūras nams' => [
            'name' => 'Siguldas pagasta Kultūras nams',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Zinātnes iela 7, Sigulda, LV-2150',
            'place_type' => 'cultural_center',
        ],
        'siguldas pagasta bibliotēka' => [
            'name' => 'Siguldas pagasta bibliotēka',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Zinātnes iela 7, Sigulda, LV-2150',
            'place_type' => 'library',
        ],
        'kino lora' => [
            'name' => 'Kino Lora',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Strēlnieku iela 2, Sigulda, LV-2150',
            'place_type' => 'cinema',
        ],
        'turaidas muzejrezervāts' => [
            'name' => 'Turaidas muzejrezervāts',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Turaidas iela 10, Sigulda, LV-2150',
            'place_type' => 'museum',
        ],
        'turaidas baznīca' => [
            'name' => 'Turaidas baznīca',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Turaidas iela 6, Sigulda, LV-2150',
            'place_type' => 'church',
        ],
        'stacijas laukums' => [
            'name' => 'Siguldas dzelzceļa stacijas laukums',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => 'Ausekļa iela 6, Sigulda, LV-2150',
            'place_type' => 'square',
        ],
        'inčukalna tautas nams' => [
            'name' => 'Inčukalna Tautas nams',
            'city' => 'Inčukalns',
            'region' => 'Vidzeme',
            'address' => 'Atmodas iela 1A, Inčukalns, LV-2141',
            'place_type' => 'cultural_center',
        ],
        'inčukalna bibliotēka' => [
            'name' => 'Inčukalna bibliotēka',
            'city' => 'Inčukalns',
            'region' => 'Vidzeme',
            'address' => 'Atmodas iela 2, Inčukalns, LV-2141',
            'place_type' => 'library',
        ],
        'inčukalna sporta centrs' => [
            'name' => 'Inčukalna Sporta centrs',
            'city' => 'Inčukalns',
            'region' => 'Vidzeme',
            'address' => 'Zvaigžņu iela 2A, Inčukalns, LV-2141',
            'place_type' => 'sports_complex',
        ],
        'gaujas kopienas centrs' => [
            'name' => 'Gaujas Kopienas centrs',
            'city' => 'Inčukalns',
            'region' => 'Vidzeme',
            'address' => 'Gauja, Inčukalna pagasts, LV-2140',
            'place_type' => 'community_center',
        ],
        'mālpils kultūras centrs' => [
            'name' => 'Mālpils Kultūras centrs',
            'city' => 'Mālpils',
            'region' => 'Vidzeme',
            'address' => 'Nākotnes iela 5, Mālpils, LV-2152',
            'place_type' => 'cultural_center',
        ],
        'mālpils bibliotēka' => [
            'name' => 'Mālpils bibliotēka',
            'city' => 'Mālpils',
            'region' => 'Vidzeme',
            'address' => 'Nākotnes iela 5, Mālpils, LV-2152',
            'place_type' => 'library',
        ],
        'mālpils sporta centrs' => [
            'name' => 'Mālpils Sporta centrs',
            'city' => 'Mālpils',
            'region' => 'Vidzeme',
            'address' => 'Sporta iela 1, Mālpils, LV-2152',
            'place_type' => 'sports_complex',
        ],
        'mālpils muiža' => [
            'name' => 'Mālpils muiža',
            'city' => 'Mālpils',
            'region' => 'Vidzeme',
            'address' => 'Pils iela 6, Mālpils, LV-2152',
            'place_type' => 'manor',
        ],
        'lēdurgas kultūras nams' => [
            'name' => 'Lēdurgas Kultūras nams',
            'city' => 'Lēdurga',
            'region' => 'Vidzeme',
            'address' => 'Emiļa Melngaiļa iela 2, Lēdurga, LV-4012',
            'place_type' => 'cultural_center',
        ],
        'lēdurgas bibliotēka' => [
            'name' => 'Lēdurgas bibliotēka',
            'city' => 'Lēdurga',
            'region' => 'Vidzeme',
            'address' => 'Emiļa Melngaiļa iela 2, Lēdurga, LV-4012',
            'place_type' => 'library',
        ],
        'lēdurgas sporta centrs' => [
            'name' => 'Lēdurgas Sporta centrs',
            'city' => 'Lēdurga',
            'region' => 'Vidzeme',
            'address' => 'Emiļa Melngaiļa iela 1, Lēdurga, LV-4012',
            'place_type' => 'sports_complex',
        ],
        'mores tautas nams' => [
            'name' => 'Mores Tautas nams',
            'city' => 'More',
            'region' => 'Vidzeme',
            'address' => 'Siguldas iela 11, More, LV-2170',
            'place_type' => 'cultural_center',
        ],
        'mores bibliotēka' => [
            'name' => 'Mores bibliotēka',
            'city' => 'More',
            'region' => 'Vidzeme',
            'address' => 'Siguldas iela 11, More, LV-2170',
            'place_type' => 'library',
        ],
        'allažu tautas nams' => [
            'name' => 'Allažu Tautas nams',
            'city' => 'Allaži',
            'region' => 'Vidzeme',
            'address' => 'Birzes iela 4, Allaži, LV-2154',
            'place_type' => 'cultural_center',
        ],
        'allažu bibliotēka' => [
            'name' => 'Allažu bibliotēka',
            'city' => 'Allaži',
            'region' => 'Vidzeme',
            'address' => 'Birzes iela 4, Allaži, LV-2154',
            'place_type' => 'library',
        ],
        'allažu sporta centrs' => [
            'name' => 'Allažu Sporta centrs',
            'city' => 'Allaži',
            'region' => 'Vidzeme',
            'address' => 'Birzes iela 4, Allaži, LV-2154',
            'place_type' => 'sports_complex',
        ],
        'krimuldas tautas nams' => [
            'name' => 'Krimuldas Tautas nams',
            'city' => 'Krimulda',
            'region' => 'Vidzeme',
            'address' => 'Parka iela 1, Ragana, LV-2144',
            'place_type' => 'cultural_center',
        ],
        'krimuldas bibliotēka' => [
            'name' => 'Krimuldas bibliotēka',
            'city' => 'Krimulda',
            'region' => 'Vidzeme',
            'address' => 'Parka iela 1, Ragana, LV-2144',
            'place_type' => 'library',
        ],
        'sunīšu kopienas centrs' => [
            'name' => 'Sunīšu Kopienas centrs',
            'city' => 'Sunīši',
            'region' => 'Vidzeme',
            'address' => 'Sunīši, Krimuldas pagasts, LV-2144',
            'place_type' => 'community_center',
        ],
        'inciema bibliotēka' => [
            'name' => 'Inciema bibliotēka',
            'city' => 'Inciems',
            'region' => 'Vidzeme',
            'address' => 'Anšlava Eglīša iela 5, Inciems, LV-2144',
            'place_type' => 'library',
        ],
        'jūdažu' => [
            'name' => 'Jūdažu Sabiedriskais centrs un bibliotēka',
            'city' => 'Jūdaži',
            'region' => 'Vidzeme',
            'address' => 'Jūdaži, Siguldas pagasts, LV-2150',
            'place_type' => 'community_center',
        ],
    ];

    public function getSlug(): string
    {
        return 'sigulda-novads';
    }

    public function getName(): string
    {
        return 'Siguldas novada pašvaldība';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $baseUrl = $source->url ?: 'https://sigulda.lv/pasakumi/';
        $today = Carbon::today('Europe/Riga');
        $maxPages = 25;

        for ($page = 1; $page <= $maxPages; $page++) {
            $pageUrl = $page === 1 ? $baseUrl : $baseUrl . '?sf_paged=' . $page;
            $crawler = $this->fetchCrawler($pageUrl);

            if (!$crawler) {
                Log::warning("SiguldaNovadsScraper: Failed to fetch page {$pageUrl}");
                break;
            }

            $cards = $crawler->filter('.card-event-wrap');
            if ($cards->count() === 0) {
                break;
            }

            foreach ($cards as $cardNode) {
                try {
                    $cardCrawler = new Crawler($cardNode, $pageUrl);
                    $dto = $this->parseCard($cardCrawler, $baseUrl, $today);

                    if (!$dto) {
                        continue;
                    }

                    // Strict Scope Rule from AGENTS.md: Current and upcoming events only
                    if ($dto->endAt) {
                        if ($dto->endAt->lt($today)) {
                            continue;
                        }
                    } elseif ($dto->startAt->lt($today)) {
                        continue;
                    }

                    $events->push($dto);
                } catch (\Throwable $e) {
                    Log::warning("SiguldaNovadsScraper: Failed to parse event card on page {$page}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // If there's no next page link, terminate pagination
            $hasNextPage = $crawler->filter('a.next.page-numbers')->count() > 0;
            if (!$hasNextPage) {
                break;
            }
        }

        return $events;
    }

    /**
     * Parse single card element from listing
     */
    public function parseCard(Crawler $card, string $baseUrl = 'https://sigulda.lv/pasakumi/', ?Carbon $today = null): ?ScrapedEventDTO
    {
        $today = $today ?: Carbon::today('Europe/Riga');

        $titleNode = $card->filter('.card-small-title');
        if (!$titleNode->count()) {
            return null;
        }

        $title = $this->cleanText($titleNode->text());
        if (empty($title)) {
            return null;
        }

        // Image
        $imgNode = $card->filter('img.card-small-image');
        $imageUrl = $imgNode->count() ? $this->normalizeUrl($imgNode->attr('src')) : null;

        // Event URL and External ID
        $onclick = $card->filter('[onclick]')->count() ? $card->filter('[onclick]')->attr('onclick') : '';
        $eventUrl = null;
        if (preg_match('/window\.location\.href=[\'"]([^\'"]+)[\'"]/', $onclick, $m)) {
            $eventUrl = $m[1];
        } elseif ($card->filter('a[href*="/pasakums/"]')->count()) {
            $eventUrl = $card->filter('a[href*="/pasakums/"]')->attr('href');
        }
        $eventUrl = $eventUrl ? $this->normalizeUrl($eventUrl) : $baseUrl;

        $externalId = null;
        if (preg_match('/\/pasakums\/([^\/\'\?]+)/', $eventUrl, $m)) {
            $externalId = 'sigulda-' . $m[1];
        } else {
            $externalId = 'sigulda-' . Str::slug($title);
        }

        // Details: Dates, Time, Venue
        $details = $card->filter('.card-event-detail')->each(fn (Crawler $d) => trim($d->text()));
        $dates = [];
        $timeStr = null;
        $venueRaw = null;

        foreach ($details as $d) {
            if (preg_match('/^\s*-?\s*\d{1,2}\.\s*[a-zēīūāčšģķļžņ]{3,}/ui', $d)) {
                $dates[] = $d;
            } elseif (preg_match('/\d{1,2}:\d{2}/', $d)) {
                $timeStr = $d;
            } else {
                $venueRaw = $d;
            }
        }

        [$startAt, $endAt] = $this->parseDates($dates, $timeStr, $today);
        if (!$startAt) {
            return null;
        }

        // Venue & City Resolution
        $locationMeta = $this->resolveLocationMeta($venueRaw, $title);

        // Tickets & Price
        $actionWrap = $card->filter('.event-list-action-wrap');
        $isFree = false;
        $priceMin = null;
        $priceMax = null;
        $ticketUrl = null;

        if ($actionWrap->count()) {
            $freeNode = $actionWrap->filter('.event-action-free');
            $freeText = $freeNode->count() ? mb_strtolower(trim($freeNode->text()), 'UTF-8') : '';
            if (str_contains($freeText, 'bezmaksas')) {
                $isFree = true;
                $priceMin = 0.0;
                $priceMax = 0.0;
            }

            $linkNode = $actionWrap->filter('a[href]');
            if ($linkNode->count()) {
                $href = trim($linkNode->attr('href') ?? '');
                if (!empty($href)) {
                    $ticketUrl = $this->normalizeUrl($href);
                }
            }
        }

        // Categories & Entertainment Type (adhering strictly to AGENTS.md)
        $categories = $this->resolveCategories($title, $venueRaw);
        $entertainmentType = $this->resolveEntertainmentType($categories, $title, $venueRaw);

        $description = $title;
        if (!empty($locationMeta['name']) && $locationMeta['name'] !== 'Sigulda') {
            $description .= "\nNorises vieta: " . $locationMeta['name'];
        }

        return new ScrapedEventDTO(
            title: $title,
            startAt: $startAt,
            endAt: $endAt,
            description: $description,
            shortDescription: Str::limit($title, 160),
            venueName: $locationMeta['name'],
            city: $locationMeta['city'],
            region: $locationMeta['region'],
            address: $locationMeta['address'],
            placeType: $locationMeta['place_type'],
            categoryNames: $categories,
            entertainmentType: $entertainmentType,
            isFree: $isFree,
            priceMin: $priceMin,
            priceMax: $priceMax,
            currency: 'EUR',
            ticketUrl: $ticketUrl ?: $eventUrl,
            imageUrl: $imageUrl,
            sourceUrl: $eventUrl,
            sourceExternalId: $externalId,
            locale: 'lv',
            rawData: [
                'dates' => $dates,
                'time' => $timeStr,
                'venue' => $venueRaw,
                'action_text' => $actionWrap->count() ? trim($actionWrap->text()) : null,
            ]
        );
    }

    /**
     * Parse date array and time string into startAt and endAt Carbon instances
     */
    public function parseDates(array $dates, ?string $timeStr, ?Carbon $today = null): array
    {
        $today = $today ?: Carbon::today('Europe/Riga');
        $currentYear = $today->year;
        $currentMonth = $today->month;

        $sDay = null;
        $sMon = null;
        $eDay = null;
        $eMon = null;

        if (count($dates) >= 2) {
            if (preg_match('/(\d{1,2})\.\s*([a-zēīūāčšģķļžņ]+)/ui', $dates[0], $m1)) {
                $sDay = (int) $m1[1];
                $sMon = $this->parseMonth($m1[2]);
            }
            if (preg_match('/(\d{1,2})\.\s*([a-zēīūāčšģķļžņ]+)/ui', $dates[1], $m2)) {
                $eDay = (int) $m2[1];
                $eMon = $this->parseMonth($m2[2]);
            }
        } elseif (count($dates) === 1) {
            if (preg_match('/(\d{1,2})\.\s*([a-zēīūāčšģķļžņ]+)/ui', $dates[0], $m)) {
                $sDay = (int) $m[1];
                $sMon = $this->parseMonth($m[2]);
            }
        }

        if (!$sDay || !$sMon) {
            return [null, null];
        }

        // Multi-day range (e.g. exhibitions or festivals)
        if ($eDay && $eMon) {
            if ($eMon < $currentMonth - 2) {
                // End month is early in the year, but we're near year end -> ends next year
                $eYear = $currentYear + 1;
                $sYear = ($sMon > $eMon) ? $currentYear : $eYear;
            } else {
                $eYear = $currentYear;
                $sYear = ($sMon > $eMon) ? ($currentYear - 1) : $currentYear;
            }

            try {
                $startAt = Carbon::create($sYear, $sMon, $sDay, 0, 0, 0, 'Europe/Riga');
                $endAt = Carbon::create($eYear, $eMon, $eDay, 23, 59, 59, 'Europe/Riga');
            } catch (\Throwable $e) {
                return [null, null];
            }
        } else {
            // Single-day event
            $year = ($sMon < $currentMonth - 2) ? ($currentYear + 1) : $currentYear;
            try {
                $startAt = Carbon::create($year, $sMon, $sDay, 0, 0, 0, 'Europe/Riga');
                $endAt = null;
            } catch (\Throwable $e) {
                return [null, null];
            }
        }

        // Apply time if present (e.g. "18:00 - 20:00" or "19:00")
        if (!empty($timeStr) && preg_match('/(\d{1,2})[.:](\d{2})(?:\s*[-–]\s*(\d{1,2})[.:](\d{2}))?/', $timeStr, $tm)) {
            $sh = (int) $tm[1];
            $sm = (int) $tm[2];
            $startAt->setTime($sh, $sm, 0);

            if (!empty($tm[3]) && isset($tm[4])) {
                $eh = (int) $tm[3];
                $em = (int) $tm[4];
                if ($endAt) {
                    $endAt->setTime($eh, $em, 0);
                } else {
                    $candEnd = $startAt->copy()->setTime($eh, $em, 0);
                    if ($candEnd->greaterThan($startAt)) {
                        $endAt = $candEnd;
                    }
                }
            }
        }

        return [$startAt, $endAt];
    }

    /**
     * Map Latvian month name prefix to integer (1-12)
     */
    public function parseMonth(string $str): ?int
    {
        $s = mb_strtolower(trim($str), 'UTF-8');
        $s = mb_substr($s, 0, 3, 'UTF-8');

        $map = [
            'jan' => 1,
            'feb' => 2,
            'mar' => 3,
            'apr' => 4,
            'mai' => 5,
            'may' => 5,
            'jūn' => 6,
            'jun' => 6,
            'jūl' => 7,
            'jul' => 7,
            'aug' => 8,
            'sep' => 9,
            'okt' => 10,
            'oct' => 10,
            'nov' => 11,
            'dec' => 12,
        ];

        return $map[$s] ?? null;
    }

    /**
     * Resolve venue, city, parish, address and place type
     */
    public function resolveLocationMeta(?string $venueRaw, string $title = ''): array
    {
        $raw = trim($venueRaw ?? '');
        $venueLower = mb_strtolower($raw, 'UTF-8');
        $titleLower = mb_strtolower($title, 'UTF-8');

        $default = [
            'name' => !empty($raw) ? $raw : 'Sigulda',
            'city' => 'Sigulda',
            'region' => 'Vidzeme',
            'address' => !empty($raw) ? $raw . ', Siguldas novads' : 'Siguldas novads',
            'place_type' => 'venue',
        ];

        // 1. Direct dictionary match
        foreach ($this->venueMap as $key => $meta) {
            if (str_contains($venueLower, $key) || str_contains($titleLower, $key)) {
                return $meta;
            }
        }

        // 2. Parish / Town heuristics across Siguldas novads
        if (str_contains($venueLower, 'inčukaln')) {
            $default['city'] = 'Inčukalns';
            $default['place_type'] = str_contains($venueLower, 'sport') ? 'sports_complex' : (str_contains($venueLower, 'bibliot') ? 'library' : 'cultural_center');
        } elseif (str_contains($venueLower, 'mālpil')) {
            $default['city'] = 'Mālpils';
            $default['place_type'] = str_contains($venueLower, 'sport') ? 'sports_complex' : (str_contains($venueLower, 'bibliot') ? 'library' : 'cultural_center');
        } elseif (str_contains($venueLower, 'lēdurg')) {
            $default['city'] = 'Lēdurga';
            $default['place_type'] = str_contains($venueLower, 'sport') ? 'sports_complex' : (str_contains($venueLower, 'bibliot') ? 'library' : 'cultural_center');
        } elseif (str_contains($venueLower, 'more') || str_contains($venueLower, 'morē')) {
            $default['city'] = 'More';
            $default['place_type'] = str_contains($venueLower, 'bibliot') ? 'library' : 'cultural_center';
        } elseif (str_contains($venueLower, 'allaž')) {
            $default['city'] = 'Allaži';
            $default['place_type'] = str_contains($venueLower, 'sport') ? 'sports_complex' : (str_contains($venueLower, 'bibliot') ? 'library' : 'cultural_center');
        } elseif (str_contains($venueLower, 'krimuld') || str_contains($venueLower, 'ragan')) {
            $default['city'] = 'Krimulda';
            $default['place_type'] = str_contains($venueLower, 'bibliot') ? 'library' : 'cultural_center';
        } elseif (str_contains($venueLower, 'sunīš')) {
            $default['city'] = 'Sunīši';
            $default['place_type'] = 'community_center';
        } elseif (str_contains($venueLower, 'inciem')) {
            $default['city'] = 'Inciems';
            $default['place_type'] = 'library';
        } elseif (str_contains($venueLower, 'jūdaž')) {
            $default['city'] = 'Jūdaži';
            $default['place_type'] = 'community_center';
        } elseif (str_contains($venueLower, 'bibliotēka')) {
            $default['place_type'] = 'library';
        } elseif (str_contains($venueLower, 'sporta')) {
            $default['place_type'] = 'sports_complex';
        } elseif (str_contains($venueLower, 'tautas nams') || str_contains($venueLower, 'kultūras')) {
            $default['place_type'] = 'cultural_center';
        }

        return $default;
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
            str_contains($venueLower, 'kino lora') ||
            str_contains($venueLower, 'k.suns') || str_contains($venueLower, 'k suns') ||
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
        if (str_contains($titleLower, 'izstād') || str_contains($titleLower, 'ekspozīcij') || str_contains($titleLower, 'gleznu') || str_contains($titleLower, 'fotogrāfiju')) {
            $cats[] = 'Izstādes';
        }

        // 3. Music / Concerts
        if (str_contains($titleLower, 'koncert') || str_contains($titleLower, 'dziesm') || str_contains($titleLower, 'orķestr') || str_contains($titleLower, 'koris') || str_contains($titleLower, 'džez') || str_contains($titleLower, 'muzikāl')) {
            $cats[] = 'Mūzika';
        }

        // 4. Theater Productions (Never cinema/film)
        if (!$isCinema && (str_contains($titleLower, 'izrād') || str_contains($titleLower, 'luga') || str_contains($titleLower, 'iestudējum') || str_contains($titleLower, 'komēdija') || str_contains($titleLower, 'stand-up') || str_contains($titleLower, 'stāvizrāde'))) {
            $cats[] = 'Teātris';
        }

        // 5. Sports
        if (str_contains($titleLower, 'sports') || str_contains($titleLower, 'kauss') || str_contains($titleLower, 'čempionāt') || str_contains($titleLower, 'skrējiens') || str_contains($titleLower, 'turnīrs') || str_contains($titleLower, 'vingrošana') || str_contains($titleLower, 'badminton') || str_contains($titleLower, 'basketbol') || str_contains($titleLower, 'futbol') || str_contains($venueLower, 'sporta centrs')) {
            $cats[] = 'Sports';
        }

        // 6. Kids / Families
        if (str_contains($titleLower, 'bērniem') || str_contains($titleLower, 'mazuļ') || str_contains($titleLower, 'leļļu') || str_contains($titleLower, 'pasaku') || str_contains($titleLower, 'ģimenēm')) {
            $cats[] = 'Bērniem';
        }

        // 7. Workshops / Seminars
        if (str_contains($titleLower, 'darbnīca') || str_contains($titleLower, 'meistarklas') || str_contains($titleLower, 'seminār') || str_contains($titleLower, 'lekcij') || str_contains($titleLower, 'nodarbība')) {
            $cats[] = 'Semināri';
        }

        // 8. Holidays / Celebrations
        if (str_contains($titleLower, 'svētk') || str_contains($titleLower, 'balle') || str_contains($titleLower, 'tirdziņ') || str_contains($titleLower, 'gadatirg')) {
            $cats[] = 'Svētki';
        }

        // 9. Fallback Rule from AGENTS.md
        if (empty($cats)) {
            $cats[] = 'Cits';
        }

        return array_values(array_unique($cats));
    }

    /**
     * Resolve entertainment type
     */
    public function resolveEntertainmentType(array $categories, string $title, ?string $venueRaw = ''): string
    {
        $titleLower = mb_strtolower($title, 'UTF-8');
        $venueLower = mb_strtolower($venueRaw ?? '', 'UTF-8');

        if (in_array('Kino', $categories, true) || str_contains($venueLower, 'kino lora') || str_contains($titleLower, 'filma') || str_contains($titleLower, 'kinoseans')) {
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
            return 'https://sigulda.lv' . $url;
        }

        return $url;
    }
}
