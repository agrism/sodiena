<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class PassportixScraper extends BaseScraper
{
    protected array $detailCache = [];

    protected array $venueCityMap = [
        'skonto stadions' => [
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Emiļa Melngaiļa iela 1a, Rīga, LV-1010',
            'latitude' => 56.9615,
            'longitude' => 24.1167,
            'place_type' => 'stadium',
        ],
        'lnk sporta parks' => [
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Lejupes iela 5, Rīga, LV-1076',
            'latitude' => 56.9114,
            'longitude' => 24.1627,
            'place_type' => 'sports_complex',
        ],
        'liepājas olimpiskais centrs' => [
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Brīvības iela 39, Liepāja, LV-3401',
            'latitude' => 56.5204,
            'longitude' => 21.0205,
            'place_type' => 'sports_complex',
        ],
        'sporta arēna "ogre"' => [
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Gunāra Astras iela 1, Ogre, LV-5001',
            'latitude' => 56.8182,
            'longitude' => 24.6119,
            'place_type' => 'sports_complex',
        ],
        'sporta arēna ogre' => [
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Gunāra Astras iela 1, Ogre, LV-5001',
            'latitude' => 56.8182,
            'longitude' => 24.6119,
            'place_type' => 'sports_complex',
        ],
        'valmieras olimpiskais centrs' => [
            'city' => 'Valmiera',
            'region' => 'Vidzeme',
            'address' => 'Rīgas iela 91, Valmiera, LV-4201',
            'latitude' => 57.5342,
            'longitude' => 25.4024,
            'place_type' => 'sports_complex',
        ],
        'jelgavas ledus halle' => [
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Rīgas iela 11, Jelgava, LV-3002',
            'latitude' => 56.6578,
            'longitude' => 23.7381,
            'place_type' => 'sports_complex',
        ],
        'mājas spēļu stadions' => [
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => null,
            'latitude' => null,
            'longitude' => null,
            'place_type' => 'stadium',
        ],
        'daugavas stadions' => [
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Augšiela 1, Rīga, LV-1009',
            'latitude' => 56.9546,
            'longitude' => 24.1565,
            'place_type' => 'stadium',
        ],
        'arēna rīga' => [
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Skanstes iela 21, Rīga, LV-1013',
            'latitude' => 56.9680,
            'longitude' => 24.1205,
            'place_type' => 'arena',
        ],
        'elektrum olimpiskais centrs' => [
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Grostonas iela 6b, Rīga, LV-1013',
            'latitude' => 56.9698,
            'longitude' => 24.1256,
            'place_type' => 'sports_complex',
        ],
        'rimi olimpiskais centrs' => [
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Grostonas iela 6b, Rīga, LV-1013',
            'latitude' => 56.9698,
            'longitude' => 24.1256,
            'place_type' => 'sports_complex',
        ],
        'zemgales olimpiskais centrs' => [
            'city' => 'Jelgava',
            'region' => 'Zemgale',
            'address' => 'Kronvalda iela 24, Jelgava, LV-3004',
            'latitude' => 56.6669,
            'longitude' => 23.7431,
            'place_type' => 'sports_complex',
        ],
        'ventspils olimpiskais centrs' => [
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Sporta iela 7/9, Ventspils, LV-3601',
            'latitude' => 57.3912,
            'longitude' => 21.5645,
            'place_type' => 'sports_complex',
        ],
        'daugavpils olimpiskais centrs' => [
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Stadiona iela 1, Daugavpils, LV-5401',
            'latitude' => 55.8776,
            'longitude' => 26.5367,
            'place_type' => 'sports_complex',
        ],
    ];

    public function getSlug(): string
    {
        return 'passportix';
    }

    public function getName(): string
    {
        return 'Passportix';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $baseUrl = $source->url ?: 'https://www.passportix.eu/pasakumi';
        $today = Carbon::today('Europe/Riga');
        $maxPages = 15;

        for ($page = 1; $page <= $maxPages; $page++) {
            $data = $this->fetchAjaxPage($baseUrl, $page);

            if (!$data || empty($data['html'])) {
                break;
            }

            $crawler = new Crawler($data['html'], $baseUrl);
            $articleNodes = $crawler->filter('article.event-article');

            if ($articleNodes->count() === 0) {
                break;
            }

            foreach ($articleNodes as $domElement) {
                try {
                    $node = new Crawler($domElement, $baseUrl);
                    $dto = $this->parseEventArticle($node, $baseUrl);

                    if (!$dto) {
                        continue;
                    }

                    // Strict Scope Rule: Skip events that have already ended in the past
                    if ($dto->endAt) {
                        if ($dto->endAt->lt($today)) {
                            continue;
                        }
                    } elseif ($dto->startAt->lt($today)) {
                        continue;
                    }

                    $events->push($dto);
                } catch (\Throwable $e) {
                    Log::warning("Passportix: Failed to parse event article", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // If backend returned status "hide", there are no more pages
            if (($data['status'] ?? '') === 'hide') {
                break;
            }
        }

        return $events;
    }

    /**
     * Fetch HTML and return a Symfony DomCrawler instance with Browserless fallback
     */
    protected function fetchCrawler(string $url): ?Crawler
    {
        // 1. Try direct HTTP client
        try {
            $response = $this->httpClient->get($url);
            $html = (string) $response->getBody();
            if ($html && !str_contains($html, 'challenge-platform') && !str_contains($html, 'Just a moment...')) {
                return new Crawler($html, $url);
            }
        } catch (\Throwable $e) {
            // Direct request failed (e.g. Cloudflare challenge), fall through to Browserless
        }

        // 2. Try Browserless
        $browserlessUrl = config('services.browserless.url', env('BROWSERLESS_URL', 'http://localhost:4007'));
        try {
            $response = Http::timeout(30)->post("{$browserlessUrl}/content?stealth=true", [
                'url' => $url,
                'gotoOptions' => ['waitUntil' => 'networkidle2'],
            ]);

            if ($response->successful()) {
                $html = $response->body();
                if ($html && !str_contains($html, 'challenge-platform') && !str_contains($html, 'Just a moment...')) {
                    return new Crawler($html, $url);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Passportix: Browserless content fetch failed for {$url}: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Fetch paginated events HTML snippet via POST AJAX
     */
    protected function fetchAjaxPage(string $url, int $page): ?array
    {
        // 1. Try direct HTTP client
        try {
            $response = $this->httpClient->post($url, [
                'headers' => [
                    'Accept' => 'application/json, text/javascript, */*; q=0.01',
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Origin' => 'https://www.passportix.eu',
                    'Referer' => $url,
                ],
                'form_params' => [
                    'act' => 'more_post_ajax',
                    'page' => $page,
                    'loadAll' => 0,
                    'cat' => '',
                    'orderby' => 'date',
                ],
            ]);

            $json = (string) $response->getBody();
            if ($json && !str_contains($json, 'challenge-platform') && !str_contains($json, 'Just a moment...')) {
                $decoded = json_decode($json, true);
                if (is_array($decoded) && !empty($decoded['html'])) {
                    return $decoded;
                }
            }
        } catch (\Throwable $e) {
            // Direct request failed (e.g. Cloudflare challenge), fall through to Browserless
        }

        // 2. Try Browserless function
        $browserlessUrl = config('services.browserless.url', env('BROWSERLESS_URL', 'http://localhost:4007'));
        try {
            $script = "export default async ({ page }) => {
  await page.goto('{$url}', { waitUntil: 'networkidle0' });
  const result = await page.evaluate(async () => {
    const formData = new FormData();
    formData.append('act', 'more_post_ajax');
    formData.append('page', {$page});
    formData.append('loadAll', 0);
    formData.append('cat', '');
    formData.append('orderby', 'date');
    const res = await fetch('{$url}', {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: formData,
    });
    return await res.json();
  });
  return { data: result, type: 'application/json' };
};";

            $response = Http::timeout(35)
                ->withBody($script, 'application/javascript')
                ->post("{$browserlessUrl}/function?stealth=true");

            if ($response->successful()) {
                $data = $response->json('data');
                if (is_array($data) && !empty($data['html'])) {
                    return $data;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Passportix: Browserless AJAX function failed for page {$page}: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Parse single article node from listing
     */
    public function parseEventArticle(Crawler $node, string $baseUrl): ?ScrapedEventDTO
    {
        $linkNode = $node->filter('a.event-article--inner');
        if ($linkNode->count() === 0) {
            return null;
        }

        $rawHref = trim($linkNode->attr('href') ?? '');
        if (empty($rawHref)) {
            return null;
        }

        $eventUrl = $this->normalizeUrl($rawHref);

        // Title from card
        $cardTitle = $node->filter('.event-article--title')->count()
            ? $this->cleanText($node->filter('.event-article--title')->text())
            : null;

        // Card image
        $cardImageNode = $node->filter('picture.event-article--image img, img');
        $cardImageUrl = $cardImageNode->count() ? $this->normalizeUrl($cardImageNode->attr('src')) : null;

        // Card price
        $cardPriceText = $node->filter('.event-article--price')->count()
            ? $this->cleanText($node->filter('.event-article--price')->text())
            : null;

        // Card venue
        $cardPlace = $node->filter('.event-article--place span')->count()
            ? $this->cleanText($node->filter('.event-article--place span')->text())
            : null;

        // Card datetime attribute
        $cardIsoDate = $node->filter('time.event-article--date')->count()
            ? trim($node->filter('time.event-article--date')->attr('datetime') ?? '')
            : null;

        // Fetch detail page for rich data (banner image, direct ticket/checkout link, description, exact dates)
        $details = $this->fetchEventDetails($eventUrl);

        $title = $details['title'] ?: $cardTitle;
        if (empty($title)) {
            return null;
        }

        $imageUrl = $details['imageUrl'] ?: $cardImageUrl;
        $venueName = $details['venueName'] ?: $cardPlace ?: 'Rīga';
        $priceText = $details['priceText'] ?: $cardPriceText;
        $ticketUrl = $details['ticketUrl'] ?: $eventUrl;
        $description = $details['description'];
        $shortDescription = $details['shortDescription'];

        // Dates & Times
        $isoDate = $details['isoDate'] ?: $cardIsoDate;
        $dateText = $details['dateText'];
        $timeText = $details['timeText'];

        [$startAt, $endAt] = $this->parseDates($isoDate, $dateText, $timeText);
        if (!$startAt) {
            return null;
        }

        // External ID extraction
        $externalId = $this->extractExternalId($eventUrl);

        // Location & City
        $locationMeta = $this->detectLocationMeta($venueName);

        // Price parsing
        [$isFree, $priceMin, $priceMax] = $this->parsePrice($priceText);

        // Categories & Entertainment Type
        $categories = ['Sports'];
        $entertainmentType = 'sports';

        // Check if event is cinema or other specific entertainment
        $lowerTitle = mb_strtolower($title, 'UTF-8');
        if (str_contains($lowerTitle, 'filma') || str_contains($lowerTitle, 'kinoseans')) {
            $categories = ['Kino'];
            $entertainmentType = 'movie';
        }

        return new ScrapedEventDTO(
            title: $title,
            startAt: $startAt,
            endAt: $endAt,
            description: $description,
            shortDescription: $shortDescription,
            venueName: $venueName,
            city: $locationMeta['city'],
            region: $locationMeta['region'],
            address: $locationMeta['address'],
            latitude: $locationMeta['latitude'],
            longitude: $locationMeta['longitude'],
            placeType: $locationMeta['place_type'],
            categoryNames: $categories,
            entertainmentType: $entertainmentType,
            isFree: $isFree,
            priceMin: $priceMin,
            priceMax: $priceMax,
            currency: 'EUR',
            ticketUrl: $ticketUrl,
            imageUrl: $imageUrl,
            sourceUrl: $eventUrl,
            sourceExternalId: $externalId,
            locale: 'lv',
            rawData: [
                'card_title' => $cardTitle,
                'card_price' => $cardPriceText,
                'card_place' => $cardPlace,
                'card_iso_date' => $cardIsoDate,
                'details' => $details,
            ]
        );
    }

    /**
     * Fetch detail page attributes
     */
    public function fetchEventDetails(string $url): array
    {
        if (isset($this->detailCache[$url])) {
            return $this->detailCache[$url];
        }

        $crawler = $this->fetchCrawler($url);
        if (!$crawler) {
            $fallback = [
                'title' => null,
                'imageUrl' => null,
                'venueName' => null,
                'priceText' => null,
                'ticketUrl' => null,
                'description' => null,
                'shortDescription' => null,
                'isoDate' => null,
                'dateText' => null,
                'timeText' => null,
            ];
            $this->detailCache[$url] = $fallback;
            return $fallback;
        }

        $titleNode = $crawler->filter('h1');
        $title = $titleNode->count() ? $this->cleanText($titleNode->text()) : null;

        // High resolution header image
        $headerImgNode = $crawler->filter('picture.event-page-layout--header-image img, .event-page-layout--header-image-block img');
        $imageUrl = $headerImgNode->count() ? $this->normalizeUrl($headerImgNode->attr('src')) : null;

        // Venue
        $placeNode = $crawler->filter('.event-attr-item.place p.event-attr-item--place span, .event-attr-item.place p');
        $venueName = $placeNode->count() ? $this->cleanText($placeNode->text()) : null;

        // Price text
        $priceNode = $crawler->filter('.event-attr-item.price p.event-attr-item--price, .event-attr-item.price p');
        $priceText = $priceNode->count() ? $this->cleanText($priceNode->text()) : null;

        // Direct buy/checkout button
        $buyBtnNode = $crawler->filter('.event-attr-item--btn-buy');
        $ticketUrl = $buyBtnNode->count() ? $this->normalizeUrl($buyBtnNode->attr('href')) : null;

        // Description
        $descNode = $crawler->filter('.read-more-block--text');
        if ($descNode->count() === 0) {
            $descNode = $crawler->filter('.event-main-content-card .text-content');
        }
        $description = null;
        if ($descNode->count() > 0) {
            $descHtml = $descNode->html();
            // Remove h3 "Pasākuma apraksts" and read more buttons if present
            $descHtml = preg_replace('/<h[1-6][^>]*>.*?<\/h[1-6]>/is', '', $descHtml);
            $descHtml = preg_replace('/<button[^>]*>.*?<\/button>/is', '', $descHtml);
            $description = $this->cleanText($descHtml);
        }

        $shortDescription = null;
        if (!empty($description)) {
            $firstPara = strtok($description, "\n");
            $shortDescription = Str::limit(strip_tags($firstPara ?: $description), 160);
        }

        // Date attributes
        $dtNode = $crawler->filter('time.event-attr-item--datetime');
        $isoDate = $dtNode->count() ? trim($dtNode->attr('datetime') ?? '') : null;

        $dateSpan = $crawler->filter('.event-attr-item--date span');
        $dateText = $dateSpan->count() ? $this->cleanText($dateSpan->text()) : null;

        $timeSpan = $crawler->filter('.event-attr-item--time span');
        $timeText = $timeSpan->count() ? $this->cleanText($timeSpan->text()) : null;

        $res = [
            'title' => $title,
            'imageUrl' => $imageUrl,
            'venueName' => $venueName,
            'priceText' => $priceText,
            'ticketUrl' => $ticketUrl,
            'description' => $description,
            'shortDescription' => $shortDescription,
            'isoDate' => $isoDate,
            'dateText' => $dateText,
            'timeText' => $timeText,
        ];

        $this->detailCache[$url] = $res;
        return $res;
    }

    /**
     * Parse start and end timestamps from ISO attribute and display text
     */
    public function parseDates(?string $isoStr, ?string $dateText, ?string $timeText): array
    {
        $startAt = null;
        $endAt = null;

        if (!empty($isoStr)) {
            try {
                $startAt = Carbon::parse($isoStr, 'Europe/Riga');
            } catch (\Throwable $e) {
                $startAt = null;
            }
        }

        if (!$startAt && !empty($dateText)) {
            if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{2,4})/', $dateText, $m)) {
                $y = (int) $m[3];
                if ($y < 100) $y += 2000;
                try {
                    $startAt = Carbon::create($y, (int) $m[2], (int) $m[1], 0, 0, 0, 'Europe/Riga');
                } catch (\Throwable $e) {
                    $startAt = null;
                }
            }
        }

        if (!$startAt) {
            return [null, null];
        }

        // Check date range: "Svētdiena, 15/03/26 - 08/11/26"
        if (!empty($dateText) && preg_match('/-\s*(\d{1,2})\/(\d{1,2})\/(\d{2,4})/', $dateText, $m)) {
            $ey = (int) $m[3];
            if ($ey < 100) $ey += 2000;
            try {
                $endAt = Carbon::create($ey, (int) $m[2], (int) $m[1], 23, 59, 59, 'Europe/Riga');
            } catch (\Throwable $e) {
                $endAt = null;
            }
        }

        // Check time range: "15:00 – 17:00" or "19:00 - 21:00"
        if (!empty($timeText) && preg_match('/(?:–|-)\s*(\d{1,2}):(\d{2})/', $timeText, $tm)) {
            $eh = (int) $tm[1];
            $em = (int) $tm[2];

            if ($endAt) {
                $endAt->setTime($eh, $em, 0);
            } else {
                $candEnd = $startAt->copy()->setTime($eh, $em, 0);
                if ($candEnd->greaterThan($startAt)) {
                    $endAt = $candEnd;
                }
            }
        }

        return [$startAt, $endAt];
    }

    /**
     * Parse price string into min/max floats and free boolean
     */
    public function parsePrice(?string $priceText): array
    {
        if (empty($priceText)) {
            return [false, null, null];
        }

        $lower = mb_strtolower($priceText, 'UTF-8');
        if (str_contains($lower, 'bezmaksas') || str_contains($lower, 'brīva') || str_contains($lower, 'free')) {
            return [true, 0.0, 0.0];
        }

        preg_match_all('/(\d+(?:[.,]\d+)?)/', $priceText, $matches);
        if (!empty($matches[1])) {
            $nums = array_map(fn ($n) => (float) str_replace(',', '.', $n), $matches[1]);
            $min = min($nums);
            $max = max($nums);
            return [$min === 0.0, $min, $max];
        }

        return [false, null, null];
    }

    /**
     * Detect city, region, address, coordinates and place type from venue name
     */
    public function detectLocationMeta(?string $venueName): array
    {
        $default = [
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => null,
            'latitude' => null,
            'longitude' => null,
            'place_type' => 'stadium',
        ];

        if (empty($venueName)) {
            return $default;
        }

        $normVenue = mb_strtolower(trim($venueName), 'UTF-8');

        // 1. Direct venue dictionary lookup
        foreach ($this->venueCityMap as $key => $meta) {
            if (str_contains($normVenue, $key)) {
                return $meta;
            }
        }

        // 2. City name pattern matching
        $cityPatterns = [
            'liepāj' => ['city' => 'Liepāja', 'region' => 'Kurzeme'],
            'ventspil' => ['city' => 'Ventspils', 'region' => 'Kurzeme'],
            'kuldīg' => ['city' => 'Kuldīga', 'region' => 'Kurzeme'],
            'sald' => ['city' => 'Saldus', 'region' => 'Kurzeme'],
            'jelgav' => ['city' => 'Jelgava', 'region' => 'Zemgale'],
            'dobel' => ['city' => 'Dobele', 'region' => 'Zemgale'],
            'bausk' => ['city' => 'Bauska', 'region' => 'Zemgale'],
            'valmier' => ['city' => 'Valmiera', 'region' => 'Vidzeme'],
            'cēs' => ['city' => 'Cēsis', 'region' => 'Vidzeme'],
            'siguld' => ['city' => 'Sigulda', 'region' => 'Vidzeme'],
            'ogr' => ['city' => 'Ogre', 'region' => 'Vidzeme'],
            'madon' => ['city' => 'Madona', 'region' => 'Vidzeme'],
            'daugavpil' => ['city' => 'Daugavpils', 'region' => 'Latgale'],
            'rēzekn' => ['city' => 'Rēzekne', 'region' => 'Latgale'],
            'jēkabpil' => ['city' => 'Jēkabpils', 'region' => 'Latgale'],
            'jūrmal' => ['city' => 'Jūrmala', 'region' => 'Rīga un Pierīga'],
            'rīg' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        ];

        foreach ($cityPatterns as $stem => $meta) {
            if (str_contains($normVenue, $stem)) {
                return array_merge($default, $meta);
            }
        }

        return $default;
    }

    /**
     * Extract external identifier from URL
     */
    public function extractExternalId(string $url): string
    {
        $parts = parse_url($url);
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $queryParams);
            if (!empty($queryParams['event'])) {
                return 'passportix-' . $queryParams['event'];
            }
        }

        $path = trim($parts['path'] ?? '', '/');
        $slug = basename($path);
        return 'passportix-' . ($slug ?: hash('xxh64', $url));
    }

    /**
     * Prepend base scheme/domain if URL is relative
     */
    protected function normalizeUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return 'https://www.passportix.eu' . (str_starts_with($url, '/') ? '' : '/') . $url;
    }
}
