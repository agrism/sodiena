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

class VisitJurmalaScraper extends BaseScraper
{
    /**
     * Map of known Jūrmala venues with coordinates, normalized names and place types.
     */
    protected array $venueMap = [
        'dzintaru koncertzāl' => [
            'name' => 'Dzintaru koncertzāle',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Turaidas iela 1, Jūrmala, LV-2015',
            'place_type' => 'concert_hall',
            'lat' => 56.9749,
            'lng' => 23.8114,
        ],
        'skvērā pie dzintaru koncertzāl' => [
            'name' => 'Skvērs pie Dzintaru koncertzāles',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Turaidas iela 1, Jūrmala, LV-2015',
            'place_type' => 'park',
            'lat' => 56.9749,
            'lng' => 23.8114,
        ],
        'jūrmalas teātr' => [
            'name' => 'Jūrmalas teātris',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Muižas iela 7, Jūrmala, LV-2010',
            'place_type' => 'theater',
            'lat' => 56.9839,
            'lng' => 23.8560,
        ],
        'jurmalas teatris' => [
            'name' => 'Jūrmalas teātris',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Muižas iela 7, Jūrmala, LV-2010',
            'place_type' => 'theater',
            'lat' => 56.9839,
            'lng' => 23.8560,
        ],
        'jūrmalas muzejs' => [
            'name' => 'Jūrmalas muzejs',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Tirgoņu iela 29, Jūrmala, LV-2015',
            'place_type' => 'museum',
            'lat' => 56.9723,
            'lng' => 23.7997,
        ],
        'bulduru izstāžu nams' => [
            'name' => 'Bulduru Izstāžu nams',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Muižas iela 6, Jūrmala, LV-2010',
            'place_type' => 'museum',
            'lat' => 56.9839,
            'lng' => 23.8569,
        ],
        'jūrmalas brīvdabas muzejs' => [
            'name' => 'Jūrmalas Brīvdabas muzejs',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Tīklu iela 1A, Jūrmala, LV-2010',
            'place_type' => 'museum',
            'lat' => 56.9856,
            'lng' => 23.8864,
        ],
        'aspazijas māj' => [
            'name' => 'Aspazijas māja',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Z. Meierovica prospekts 18/20, Jūrmala, LV-2015',
            'place_type' => 'museum',
            'lat' => 56.9691,
            'lng' => 23.7742,
        ],
        'raiņa un aspazijas vasarnīc' => [
            'name' => 'Raiņa un Aspazijas vasarnīca',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'J. Pliekšāna iela 5/7, Jūrmala, LV-2015',
            'place_type' => 'museum',
            'lat' => 56.9715,
            'lng' => 23.8160,
        ],
        'mākslas stacija "dubulti"' => [
            'name' => 'Mākslas stacija "Dubulti"',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Zigfrīda Meierovica prospekts 4, Jūrmala, LV-2015',
            'place_type' => 'museum',
            'lat' => 56.9705,
            'lng' => 23.7735,
        ],
        'makslas stacija "dubulti"' => [
            'name' => 'Mākslas stacija "Dubulti"',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Zigfrīda Meierovica prospekts 4, Jūrmala, LV-2015',
            'place_type' => 'museum',
            'lat' => 56.9705,
            'lng' => 23.7735,
        ],
        'inner light' => [
            'name' => 'Vitālija Jermolajeva gleznu teātris "Inner light"',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Omnibusa iela 19, Jūrmala, LV-2015',
            'place_type' => 'museum',
            'lat' => 56.9729,
            'lng' => 23.8055,
        ],
        'jūrmalas kultūras centrs' => [
            'name' => 'Jūrmalas Kultūras centrs',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Jomas iela 35, Jūrmala, LV-2015',
            'place_type' => 'culture_centre',
            'lat' => 56.9720,
            'lng' => 23.7975,
        ],
        'kauguru kultūras nams' => [
            'name' => 'Kauguru kultūras nams',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Raiņa iela 110, Jūrmala, LV-2016',
            'place_type' => 'culture_centre',
            'lat' => 56.9583,
            'lng' => 23.6125,
        ],
        'kultūrtelpas un vides dizaina centrs' => [
            'name' => 'Jūrmalas Kultūrtelpas un vides dizaina centrs',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Pils iela 1, Jūrmala, LV-2015',
            'place_type' => 'culture_centre',
            'lat' => 56.9774,
            'lng' => 23.8242,
        ],
        'ķemeru kūrorta parks' => [
            'name' => 'Ķemeru kūrorta parks',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Tūristu iela 32, Jūrmala, LV-2012',
            'place_type' => 'park',
            'lat' => 56.9535,
            'lng' => 23.5042,
        ],
        'ķemeru ūdenstornis' => [
            'name' => 'Ķemeru ūdenstornis',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Tūristu iela 32, Jūrmala, LV-2012',
            'place_type' => 'park',
            'lat' => 56.9535,
            'lng' => 23.5042,
        ],
        'kauguru parks' => [
            'name' => 'Kauguru parks',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Kauguru parks, Jūrmala, LV-2016',
            'place_type' => 'park',
            'lat' => 56.9590,
            'lng' => 23.6150,
        ],
        'dzintaru mežaparks' => [
            'name' => 'Dzintaru mežaparks',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Lazdona iela 1, Jūrmala, LV-2015',
            'place_type' => 'park',
            'lat' => 56.9770,
            'lng' => 23.8150,
        ],
        'jūrmalas tūrisma informācijas centrs' => [
            'name' => 'Jūrmalas Tūrisma informācijas centrs',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Lienes iela 5, Jūrmala, LV-2015',
            'place_type' => 'info_centre',
            'lat' => 56.9734,
            'lng' => 23.7970,
        ],
        'baltic beach hotel' => [
            'name' => 'Baltic Beach Hotel & SPA',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Jūras iela 23/25, Jūrmala, LV-2015',
            'place_type' => 'hotel',
            'lat' => 56.9744,
            'lng' => 23.7984,
        ],
        'lielupe by semarah' => [
            'name' => 'Lielupe by Semarah Hotels',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Bulduru prospekts 64/68, Jūrmala, LV-2010',
            'place_type' => 'hotel',
            'lat' => 56.9877,
            'lng' => 23.8647,
        ],
        'jūrmala spa' => [
            'name' => 'Hotel Jūrmala SPA',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Jomas iela 47/49, Jūrmala, LV-2015',
            'place_type' => 'hotel',
            'lat' => 56.9730,
            'lng' => 23.8020,
        ],
        'majorenhoff' => [
            'name' => 'Majorenhoff',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Jomas iela 42, Jūrmala, LV-2015',
            'place_type' => 'restaurant',
            'lat' => 56.9723,
            'lng' => 23.8010,
        ],
        'house of light' => [
            'name' => 'Grilbārs "House of Light"',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Jomas iela 63, Jūrmala, LV-2015',
            'place_type' => 'restaurant',
            'lat' => 56.9735,
            'lng' => 23.8060,
        ],
        '36. line grill' => [
            'name' => '36. Line Grill Restaurant',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Baznīcas iela 2b, Jūrmala, LV-2015',
            'place_type' => 'restaurant',
            'lat' => 56.9888,
            'lng' => 23.8690,
        ],
        '36.line grill' => [
            'name' => '36. Line Grill Restaurant',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Baznīcas iela 2b, Jūrmala, LV-2015',
            'place_type' => 'restaurant',
            'lat' => 56.9888,
            'lng' => 23.8690,
        ],
        'stadions "sloka"' => [
            'name' => 'Stadions "Sloka"',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Skolas iela 5, Jūrmala, LV-2011',
            'place_type' => 'sports_facility',
            'lat' => 56.9515,
            'lng' => 23.6060,
        ],
        'marmar cafe' => [
            'name' => 'MarMar Cafe',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Jomas iela 61, Jūrmala, LV-2015',
            'place_type' => 'restaurant',
            'lat' => 56.9735,
            'lng' => 23.8055,
        ],
        'lokāls veranda' => [
            'name' => 'Restorāns "Lokāls Veranda"',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Jomas iela 58-k2, Jūrmala, LV-2015',
            'place_type' => 'restaurant',
            'lat' => 56.9732,
            'lng' => 23.8050,
        ],
        'jūras pērle' => [
            'name' => 'Restorāns "Jūras Pērle"',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Bulduru prospekts 17k-1, Jūrmala, LV-2010',
            'place_type' => 'restaurant',
            'lat' => 56.9845,
            'lng' => 23.8490,
        ],
        'zelta teļš' => [
            'name' => 'Restorāns "Zelta Teļš"',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Jomas iela 55, Jūrmala, LV-2015',
            'place_type' => 'restaurant',
            'lat' => 56.9728,
            'lng' => 23.8035,
        ],
        'o\'sole mio' => [
            'name' => 'Restorāns "O\'Sole Mio"',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Jomas iela 61, Jūrmala, LV-2015',
            'place_type' => 'restaurant',
            'lat' => 56.9734,
            'lng' => 23.8055,
        ],
        'alba' => [
            'name' => 'Kafejnīca "Alba"',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Dārzu iela 9, Jūrmala, LV-2015',
            'place_type' => 'restaurant',
            'lat' => 56.9715,
            'lng' => 23.7940,
        ],
        'neptūns' => [
            'name' => 'Restorāns "Neptūns"',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Jaunķemeru ceļš 1, Jūrmala, LV-2012',
            'place_type' => 'restaurant',
            'lat' => 56.9720,
            'lng' => 23.5350,
        ],
        'jūrmalas mūzikas vidusskola' => [
            'name' => 'Jūrmalas Mūzikas vidusskola',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Strēlnieku prospekts 30, Jūrmala, LV-2015',
            'place_type' => 'education',
            'lat' => 56.9760,
            'lng' => 23.8320,
        ],
    ];

    protected array $latvianMonths = [
        'janvār' => 1, 'janvar' => 1,
        'februār' => 2, 'februar' => 2,
        'mart' => 3,
        'aprīl' => 4, 'april' => 4,
        'maij' => 5,
        'jūnij' => 6, 'junij' => 6,
        'jūlij' => 7, 'julij' => 7,
        'august' => 8,
        'septembr' => 9,
        'oktobr' => 10,
        'novembr' => 11,
        'decembr' => 12,
    ];

    public function getSlug(): string
    {
        return 'visitjurmala';
    }

    public function getName(): string
    {
        return 'Visit Jūrmala';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $today = Carbon::today('Europe/Riga');

        $baseUrl = $source->url ?: 'https://visitjurmala.lv/lv/pasakumi/';
        // Build URL covering upcoming year if no query params provided
        $targetUrl = $baseUrl;
        if (!str_contains($baseUrl, 'from_date=')) {
            $separator = str_contains($baseUrl, '?') ? '&' : '?';
            $targetUrl = $baseUrl . $separator . 'from_date=' . $today->format('d.m.Y') . '&to_date=' . $today->copy()->addYear()->format('d.m.Y');
        }

        $crawler = $this->fetchCrawler($targetUrl);
        if (!$crawler) {
            Log::warning("VisitJurmalaScraper: Failed to fetch crawler for {$targetUrl}");
            return $events;
        }

        $seenExternalIds = [];

        $crawler->filter('.event-list-item')->each(function (Crawler $item) use (&$events, &$seenExternalIds, $today, $targetUrl) {
            $itemId = $item->attr('id') ?? '';
            if (!preg_match('/^event-(\d{4}-\d{2}-\d{2})-(\d+)$/', $itemId, $matches)) {
                return;
            }

            $idDate = $matches[1];
            $internalId = $matches[2];
            $externalId = 'vj-' . $idDate . '-' . $internalId;

            if (isset($seenExternalIds[$externalId])) {
                return;
            }

            // Title
            $titleNode = $item->filter('.event-list-descr h2 a');
            $title = $titleNode->count() ? $this->cleanText($titleNode->text()) : '';
            if (empty($title)) {
                return;
            }

            // Source URL
            $relLink = $titleNode->count() ? trim($titleNode->attr('href')) : '';
            $cleanUrl = $relLink;
            if (empty($cleanUrl) || $cleanUrl === 'https://visitjurmala.lv/lv/pasakumi/' || str_ends_with($cleanUrl, '/pasakumi/')) {
                $cleanUrl = 'https://visitjurmala.lv/lv/pasakumi/#' . $itemId;
            } elseif (!str_starts_with($cleanUrl, 'http://') && !str_starts_with($cleanUrl, 'https://')) {
                $cleanUrl = 'https://visitjurmala.lv' . (str_starts_with($cleanUrl, '/') ? '' : '/') . $cleanUrl;
            }

            // Date and time parsing
            $dateRaw = $item->filter('.event-date')->count() ? trim(preg_replace('/\s+/', ' ', $item->filter('.event-date')->text())) : '';
            [$startAt, $endAt] = $this->parseDates($dateRaw, $idDate, $today);
            if (!$startAt) {
                return;
            }

            // Strict AGENTS.md rule: Do NOT modify or ingest past events
            if ($endAt && $endAt->lt($today)) {
                return;
            }
            if (!$endAt && $startAt->lt($today)) {
                return;
            }

            // Description: extract from .event-more-descr paragraphs
            $pTexts = [];
            $item->filter('.event-more-descr p')->each(function (Crawler $p) use (&$pTexts) {
                $t = $this->cleanText($p->text());
                if (!empty($t)) {
                    $pTexts[] = $t;
                }
            });
            $description = !empty($pTexts) ? implode("\n\n", $pTexts) : $title;
            $shortDescription = Str::limit(strip_tags($description), 160);

            // Venue and Address parsing from contacts list
            $rawVenue = '';
            $rawAddress = '';
            $websiteUrl = null;

            $item->filter('.event-details ul.contacts li')->each(function (Crawler $li) use (&$rawVenue, &$rawAddress, &$websiteUrl) {
                $label = $li->filter('label')->count() ? trim($li->filter('label')->text()) : '';
                if ($label === 'Norises vieta') {
                    if ($li->filter('a')->count() > 0) {
                        $rawVenue = trim($li->filter('a')->first()->text());
                    } else {
                        $rawVenue = $this->cleanText(str_replace('Norises vieta', '', $li->text()));
                    }
                } elseif ($label === 'Adrese') {
                    $rawAddress = $this->cleanText(str_replace('Adrese', '', $li->text()));
                } elseif ($label === 'Mājas lapa') {
                    if ($li->filter('a')->count() > 0) {
                        $websiteUrl = trim($li->filter('a')->first()->attr('href'));
                    }
                }
            });

            // Location Meta Resolution
            $locationMeta = $this->resolveLocationMeta($rawVenue, $rawAddress, $title);

            // Image URL
            $imageUrl = null;
            $imageLinkNode = $item->filter('.event-list-image a.gallery-item, .event-list-image a');
            if ($imageLinkNode->count()) {
                $imageUrl = $imageLinkNode->attr('href');
            }
            if (!$imageUrl && $item->filter('.event-list-image img')->count()) {
                $img = $item->filter('.event-list-image img');
                $imageUrl = $img->attr('data-src') ?: $img->attr('src');
            }
            if ($imageUrl) {
                // Normalize double slashes in paths like https://visitjurmala.lv//images/...
                $imageUrl = preg_replace('#([^:])//+#', '$1/', $imageUrl);
                if (!str_starts_with($imageUrl, 'http://') && !str_starts_with($imageUrl, 'https://')) {
                    $imageUrl = 'https://visitjurmala.lv' . (str_starts_with($imageUrl, '/') ? '' : '/') . $imageUrl;
                }
            }

            // Price & Free Admission detection
            $priceData = $this->parsePrice($description);
            $isFree = $priceData['is_free'];
            $priceMin = $priceData['price_min'];
            $priceMax = $priceData['price_max'];

            // Categories & Entertainment Type resolution (Strict AGENTS.md rules)
            $categories = $this->resolveCategories($title, $description, $locationMeta['name']);
            $entertainmentType = $this->resolveEntertainmentType($categories, $title, $description);

            $dto = new ScrapedEventDTO(
                title: $title,
                startAt: $startAt,
                endAt: $endAt,
                description: $description,
                shortDescription: $shortDescription,
                venueName: $locationMeta['name'],
                city: $locationMeta['city'],
                region: $locationMeta['region'],
                address: $locationMeta['address'],
                latitude: $locationMeta['lat'],
                longitude: $locationMeta['lng'],
                placeType: $locationMeta['place_type'],
                categoryNames: $categories,
                entertainmentType: $entertainmentType,
                isFree: $isFree,
                priceMin: $priceMin,
                priceMax: $priceMax,
                currency: 'EUR',
                ticketUrl: $cleanUrl,
                imageUrl: $imageUrl,
                sourceUrl: $cleanUrl,
                sourceExternalId: $externalId,
                locale: 'lv',
                rawData: [
                    'element_id' => $itemId,
                    'date_raw' => $dateRaw,
                    'venue_raw' => $rawVenue,
                    'address_raw' => $rawAddress,
                    'website_url' => $websiteUrl,
                ]
            );

            $seenExternalIds[$externalId] = true;
            $events->push($dto);
        });

        Log::info("VisitJurmalaScraper: Scraped {$events->count()} events from {$targetUrl}");

        return $events;
    }

    /**
     * Parse date and time from visitjurmala event card.
     */
    public function parseDates(string $dateRaw, string $idDate, ?Carbon $today = null): array
    {
        $today = $today ?: Carbon::today('Europe/Riga');
        $dateRaw = trim($dateRaw);

        if (empty($dateRaw) && !empty($idDate)) {
            return [Carbon::parse("{$idDate} 00:00:00"), null];
        }

        // Multi-day range: e.g. "11.novembris - 22.novembris"
        // or "28.novembris 17:00 - 10.janvāris (2027) 21:00"
        if (preg_match('/(\d{1,2})\.\s*([a-zA-ZāēīūķļņģšžčĀĒĪŪĶĻŅĢŠŽČ]+)(?:\s*\((\d{4})\))?(?:\s*(\d{1,2}:\d{2}))?\s*-\s*(\d{1,2})\.\s*([a-zA-ZāēīūķļņģšžčĀĒĪŪĶĻŅĢŠŽČ]+)(?:\s*\((\d{4})\))?(?:\s*(\d{1,2}:\d{2}))?/u', $dateRaw, $range)) {
            $sDay = (int) $range[1];
            $sMonth = $this->parseLatvianMonth($range[2]);
            $sYear = !empty($range[3]) ? (int) $range[3] : (int) substr($idDate, 0, 4);
            $sTime = !empty($range[4]) ? $range[4] : '00:00';

            $eDay = (int) $range[5];
            $eMonth = $this->parseLatvianMonth($range[6]);
            $eYear = !empty($range[7]) ? (int) $range[7] : null;
            $eTime = !empty($range[8]) ? $range[8] : '23:59:59';

            if (!$eYear) {
                if ($eMonth < $sMonth) {
                    $eYear = $sYear + 1;
                } else {
                    $eYear = $sYear;
                }
                // Ongoing exhibitions from earlier years still running in current year
                if ($eYear < $today->year) {
                    $eYear = $today->year;
                }
            }

            if ($sMonth && $eMonth) {
                $startAt = Carbon::create($sYear, $sMonth, $sDay, ...(explode(':', $sTime)));
                $endAt = Carbon::create($eYear, $eMonth, $eDay, ...(explode(':', $eTime)));
                return [$startAt, $endAt];
            }
        }

        // Single day event: e.g. "Svētdiena, 15.novembris 10:30 - 13:00" or "Svētdiena, 15.novembris 17:00"
        if (preg_match('/(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})/', $dateRaw, $tm)) {
            $startAt = Carbon::parse("{$idDate} {$tm[1]}");
            $endAt = Carbon::parse("{$idDate} {$tm[2]}");
            return [$startAt, $endAt];
        }

        if (preg_match('/(\d{1,2}:\d{2})/', $dateRaw, $tm)) {
            $startAt = Carbon::parse("{$idDate} {$tm[1]}");
            return [$startAt, null];
        }

        $startAt = Carbon::parse("{$idDate} 00:00:00");
        return [$startAt, null];
    }

    /**
     * Parse Latvian month name to integer (1-12).
     */
    protected function parseLatvianMonth(string $text): ?int
    {
        $lower = mb_strtolower($text, 'UTF-8');
        foreach ($this->latvianMonths as $key => $num) {
            if (str_contains($lower, $key)) {
                return $num;
            }
        }
        return null;
    }

    /**
     * Resolve location metadata, matching known venues or formatting parsed addresses.
     */
    public function resolveLocationMeta(?string $rawVenue, ?string $rawAddress, string $title = ''): array
    {
        $rawVenue = trim($rawVenue ?? '');
        $rawAddress = trim($rawAddress ?? '');

        $combined = mb_strtolower($rawVenue . ' ' . $rawAddress . ' ' . $title, 'UTF-8');

        foreach ($this->venueMap as $stem => $meta) {
            if (str_contains($combined, $stem)) {
                return $meta;
            }
        }

        // Fallback resolution
        $name = !empty($rawVenue) ? $rawVenue : (!empty($rawAddress) ? $rawAddress : 'Jūrmala');
        $address = !empty($rawAddress) ? $rawAddress : 'Jūrmala';
        if (!str_contains($address, 'Jūrmala')) {
            $address .= ', Jūrmala';
        }

        return [
            'name' => $name,
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => $address,
            'place_type' => $this->inferPlaceType($name, $title),
            'lat' => 56.9712,
            'lng' => 23.7958,
        ];
    }

    /**
     * Infer place type from venue name and title.
     */
    protected function inferPlaceType(string $venue, string $title): string
    {
        $lower = mb_strtolower($venue . ' ' . $title, 'UTF-8');

        if (str_contains($lower, 'koncertzāl') || str_contains($lower, 'dzintaru')) {
            return 'concert_hall';
        }
        if (str_contains($lower, 'teātr')) {
            return 'theater';
        }
        if (str_contains($lower, 'muzej') || str_contains($lower, 'māj') || str_contains($lower, 'izstāžu nams') || str_contains($lower, 'galerij')) {
            return 'museum';
        }
        if (str_contains($lower, 'kultūras centrs') || str_contains($lower, 'kultūras nams') || str_contains($lower, 'tautas nams')) {
            return 'culture_centre';
        }
        if (str_contains($lower, 'parks') || str_contains($lower, 'dārz') || str_contains($lower, 'skvērs') || str_contains($lower, 'pludmal')) {
            return 'park';
        }
        if (str_contains($lower, 'stadion') || str_contains($lower, 'sport') || str_contains($lower, 'basein') || str_contains($lower, 'halle')) {
            return 'sports_facility';
        }
        if (str_contains($lower, 'hotel') || str_contains($lower, 'viesnīc')) {
            return 'hotel';
        }
        if (str_contains($lower, 'restorān') || str_contains($lower, 'kafejnīc') || str_contains($lower, 'grilbārs') || str_contains($lower, 'cafe') || str_contains($lower, 'bārs')) {
            return 'restaurant';
        }

        return 'venue';
    }

    /**
     * Resolve categories adhering strictly to AGENTS.md rules.
     */
    public function resolveCategories(string $title, string $description, string $venue): array
    {
        $t = mb_strtolower($title, 'UTF-8');
        $d = mb_strtolower($description, 'UTF-8');
        $v = mb_strtolower($venue, 'UTF-8');
        $comb = $t . ' ' . $v;

        // Strip pricing boilerplate mentioning kids to avoid false positives on kids category
        $dStripped = preg_replace('/bērniem\s+(?:līdz|no)\s+\d+[^\n.]*/ui', '', $d);

        // STRICT AGENTS.MD RULE: Cinema / Kino (Priority check)
        $kinoKeywords = [
            'filma', 'filmas', 'filmu', 'filmā', 'kinoseanss', 'filmas seanss',
            'spēlfilma', 'dokumentālā filma', 'animācijas filma', 'kinofestivāls', 'kinoteātr',
        ];
        foreach ($kinoKeywords as $kw) {
            if (str_contains($comb, $kw) || str_contains($d, $kw)) {
                return ['Kino'];
            }
        }

        // STRICT AGENTS.MD RULE: Theaters
        // Only actual theater venues or theater productions
        $isTheaterVenue = str_contains($comb, 'jūrmalas teātr') || str_contains($comb, 'jurmalas teatris');
        $isTheaterProduction = str_contains($comb, 'izrāde') || str_contains($comb, 'izrādes')
            || str_contains($comb, 'iestudējums') || str_contains($comb, 'luga')
            || str_contains($comb, 'balets') || str_contains($comb, 'stand-up') || str_contains($comb, 'komēdija')
            || str_contains($d, 'teātra izrāde') || str_contains($d, 'lugas iestudējums');

        if ($isTheaterVenue || $isTheaterProduction) {
            // If it is a music concert held at the theatre venue
            if (str_contains($t, 'koncerts') && !str_contains($t, 'izrāde') && !str_contains($t, 'luga')) {
                return ['Mūzika', 'Teātris'];
            }
            return ['Teātris'];
        }

        // Music / Concerts
        if (
            str_contains($comb, 'koncerts') || str_contains($comb, 'dziesm') || str_contains($comb, 'mūzik')
            || str_contains($comb, 'orķestr') || str_contains($comb, 'koris') || str_contains($comb, 'dzintaru koncertzāl')
            || str_contains($comb, 'džez') || str_contains($comb, 'kamermūzik') || str_contains($comb, 'klavier')
            || str_contains($comb, 'solists') || str_contains($comb, 'dziedātāj') || str_contains($comb, 'opera')
            || str_contains($d, 'koncertprogrammā') || str_contains($d, 'koncertā')
        ) {
            return ['Mūzika'];
        }

        // Exhibitions & Art
        if (
            str_contains($comb, 'izstād') || str_contains($comb, 'ekspozīcij') || str_contains($comb, 'glezn')
            || str_contains($comb, 'māksl') || str_contains($comb, 'tēlniec') || str_contains($comb, 'muzejs')
            || str_contains($comb, 'fotogrāf') || str_contains($d, 'izstādē') || str_contains($d, 'ekspozīcijā')
        ) {
            return ['Izstādes'];
        }

        // Dedicated Kids & Family events
        if (
            str_contains($t, 'bērn') || str_contains($t, 'ģimen') || str_contains($t, 'rotaļ')
            || str_contains($dStripped, 'pasākums bērniem') || str_contains($dStripped, 'bērnu pasākums')
            || str_contains($dStripped, 'ģimenēm ar bērniem')
        ) {
            return ['Bērniem'];
        }

        // Sports & Active recreation
        if (
            str_contains($comb, 'sports') || str_contains($comb, 'nūjošan') || str_contains($comb, 'skrējiens')
            || str_contains($comb, 'turnīrs') || str_contains($comb, 'čempionāts') || str_contains($comb, 'pārgājiens')
            || str_contains($comb, 'joga') || str_contains($comb, 'velobrauciens')
            || str_contains($d, 'sacensīb') || str_contains($d, 'sporta nodarbīb')
        ) {
            return ['Sports'];
        }

        // Workshops / Seminars
        if (
            str_contains($comb, 'meistarklase') || str_contains($comb, 'lekcij') || str_contains($comb, 'seminār')
            || str_contains($comb, 'nodarbīb') || str_contains($comb, 'darbnīca') || str_contains($comb, 'konferenc')
            || str_contains($d, 'meistarklasē') || str_contains($d, 'lekcijā')
        ) {
            return ['Semināri'];
        }

        // Festivals, Celebrations & Gastronomy
        if (
            str_contains($comb, 'svētk') || str_contains($comb, 'festivāl') || str_contains($comb, 'egles iedegšana')
            || str_contains($comb, 'jaunā gada') || str_contains($comb, 'proklamēšan') || str_contains($comb, 'brančs')
            || str_contains($comb, 'vēlās brokastis') || str_contains($comb, 'degustācij')
            || str_contains($d, 'svētku') || str_contains($d, 'festivāls')
        ) {
            return ['Svētki'];
        }

        // Fallback: Cits per AGENTS.md rules
        return ['Cits'];
    }

    /**
     * Resolve entertainment type.
     */
    public function resolveEntertainmentType(array $categories, string $title, string $description): string
    {
        $lower = mb_strtolower($title . ' ' . $description, 'UTF-8');

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
     * Parse price and free admission from description.
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
