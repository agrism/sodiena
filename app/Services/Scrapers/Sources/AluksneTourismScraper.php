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

class AluksneTourismScraper extends BaseScraper
{
    protected array $venueAddressMap = [
        'alūksnes kultūras centrā mazajā zālē' => [
            'name' => 'Alūksnes Kultūras centrs (Mazā zāle)',
            'city' => 'Alūksne',
            'region' => 'Vidzeme',
            'address' => 'Brīvības iela 7, Alūksne, Alūksnes novads, LV-4301',
            'latitude' => 57.4253,
            'longitude' => 27.0494,
            'place_type' => 'cultural_center',
        ],
        'alūksnes kultūras centra mazajā zālē' => [
            'name' => 'Alūksnes Kultūras centrs (Mazā zāle)',
            'city' => 'Alūksne',
            'region' => 'Vidzeme',
            'address' => 'Brīvības iela 7, Alūksne, Alūksnes novads, LV-4301',
            'latitude' => 57.4253,
            'longitude' => 27.0494,
            'place_type' => 'cultural_center',
        ],
        'alūksnes kultūras centrā' => [
            'name' => 'Alūksnes Kultūras centrs',
            'city' => 'Alūksne',
            'region' => 'Vidzeme',
            'address' => 'Brīvības iela 7, Alūksne, Alūksnes novads, LV-4301',
            'latitude' => 57.4253,
            'longitude' => 27.0494,
            'place_type' => 'cultural_center',
        ],
        'alūksnes kultūras centrs' => [
            'name' => 'Alūksnes Kultūras centrs',
            'city' => 'Alūksne',
            'region' => 'Vidzeme',
            'address' => 'Brīvības iela 7, Alūksne, Alūksnes novads, LV-4301',
            'latitude' => 57.4253,
            'longitude' => 27.0494,
            'place_type' => 'cultural_center',
        ],
        'alūksnes sporta centrs' => [
            'name' => 'Alūksnes Sporta centrs',
            'city' => 'Alūksne',
            'region' => 'Vidzeme',
            'address' => 'Jāņkalna iela 17A, Alūksne, Alūksnes novads, LV-4301',
            'latitude' => 57.4206,
            'longitude' => 27.0454,
            'place_type' => 'stadium',
        ],
        'alūksnes novada muzejā' => [
            'name' => 'Alūksnes novada muzejs',
            'city' => 'Alūksne',
            'region' => 'Vidzeme',
            'address' => 'Pils iela 74, Alūksne, Alūksnes novads, LV-4301',
            'latitude' => 57.4269,
            'longitude' => 27.0569,
            'place_type' => 'museum',
        ],
        'alūksnes novada muzejs' => [
            'name' => 'Alūksnes novada muzejs',
            'city' => 'Alūksne',
            'region' => 'Vidzeme',
            'address' => 'Pils iela 74, Alūksne, Alūksnes novads, LV-4301',
            'latitude' => 57.4269,
            'longitude' => 27.0569,
            'place_type' => 'museum',
        ],
        'alūksnes jaunajā pilī' => [
            'name' => 'Alūksnes Jaunā pils',
            'city' => 'Alūksne',
            'region' => 'Vidzeme',
            'address' => 'Pils iela 74, Alūksne, Alūksnes novads, LV-4301',
            'latitude' => 57.4269,
            'longitude' => 27.0569,
            'place_type' => 'castle',
        ],
        'viktora ķirpa ates muzejā “vidzemes lauku sēta”' => [
            'name' => 'Viktora Ķirpa Ates muzejs “Vidzemes lauku sēta”',
            'city' => 'Kalncempji',
            'region' => 'Vidzeme',
            'address' => 'Ate, Kalncempju pagasts, Alūksnes novads, LV-4343',
            'latitude' => 57.3197,
            'longitude' => 26.9056,
            'place_type' => 'museum',
        ],
        'viktora ķirpa ates muzejā' => [
            'name' => 'Viktora Ķirpa Ates muzejs',
            'city' => 'Kalncempji',
            'region' => 'Vidzeme',
            'address' => 'Ate, Kalncempju pagasts, Alūksnes novads, LV-4343',
            'latitude' => 57.3197,
            'longitude' => 26.9056,
            'place_type' => 'museum',
        ],
        'annas kultūras namā' => [
            'name' => 'Annas kultūras nams',
            'city' => 'Anna',
            'region' => 'Vidzeme',
            'address' => 'Anna, Annas pagasts, Alūksnes novads, LV-4342',
            'latitude' => 57.3458,
            'longitude' => 27.0396,
            'place_type' => 'cultural_center',
        ],
        'alsviķu kultūras namā' => [
            'name' => 'Alsviķu kultūras nams',
            'city' => 'Alsviķi',
            'region' => 'Vidzeme',
            'address' => 'Alsviķi, Alsviķu pagasts, Alūksnes novads, LV-4333',
            'latitude' => 57.4398,
            'longitude' => 26.9197,
            'place_type' => 'cultural_center',
        ],
        'ilzenē skiim centrā “dailes”' => [
            'name' => 'Ilzenes SKIIM centrs “Dailes”',
            'city' => 'Ilzene',
            'region' => 'Vidzeme',
            'address' => 'Jaunzemji, Ilzenes pagasts, Alūksnes novads, LV-4345',
            'latitude' => 57.3592,
            'longitude' => 26.7904,
            'place_type' => 'cultural_center',
        ],
        'skiim centrā “dailes”' => [
            'name' => 'Ilzenes SKIIM centrs “Dailes”',
            'city' => 'Ilzene',
            'region' => 'Vidzeme',
            'address' => 'Jaunzemji, Ilzenes pagasts, Alūksnes novads, LV-4345',
            'latitude' => 57.3592,
            'longitude' => 26.7904,
            'place_type' => 'cultural_center',
        ],
        'zeltiņu tautas namā' => [
            'name' => 'Zeltiņu tautas nams',
            'city' => 'Zeltiņi',
            'region' => 'Vidzeme',
            'address' => 'Zeltiņi, Zeltiņu pagasts, Alūksnes novads, LV-4344',
            'latitude' => 57.3542,
            'longitude' => 26.8973,
            'place_type' => 'cultural_center',
        ],
        'zeltiņu kodolraķešu bāzē' => [
            'name' => 'Zeltiņu kodolraķešu bāze',
            'city' => 'Zeltiņi',
            'region' => 'Vidzeme',
            'address' => 'Zeltiņu pagasts, Alūksnes novads, LV-4344',
            'latitude' => 57.3514,
            'longitude' => 26.8394,
            'place_type' => 'attraction',
        ],
        'jaunannas tautas nama mazajā zālē' => [
            'name' => 'Jaunannas tautas nams (Mazā zāle)',
            'city' => 'Jaunanna',
            'region' => 'Vidzeme',
            'address' => 'Centra iela 7, Jaunanna, Jaunannas pagasts, Alūksnes novads, LV-4340',
            'latitude' => 57.3072,
            'longitude' => 27.0784,
            'place_type' => 'cultural_center',
        ],
        'jaunannas tautas namā' => [
            'name' => 'Jaunannas tautas nams',
            'city' => 'Jaunanna',
            'region' => 'Vidzeme',
            'address' => 'Centra iela 7, Jaunanna, Jaunannas pagasts, Alūksnes novads, LV-4340',
            'latitude' => 57.3072,
            'longitude' => 27.0784,
            'place_type' => 'cultural_center',
        ],
        'malienas tautas namā' => [
            'name' => 'Malienas tautas nams',
            'city' => 'Maliena',
            'region' => 'Vidzeme',
            'address' => 'Brenci, Malienas pagasts, Alūksnes novads, LV-4358',
            'latitude' => 57.3826,
            'longitude' => 27.2062,
            'place_type' => 'cultural_center',
        ],
        'mārkalnes tautas namā' => [
            'name' => 'Mārkalnes tautas nams',
            'city' => 'Mārkalne',
            'region' => 'Vidzeme',
            'address' => 'Mārkalne, Mārkalnes pagasts, Alūksnes novads, LV-4354',
            'latitude' => 57.4912,
            'longitude' => 27.2215,
            'place_type' => 'cultural_center',
        ],
        'kolberģa tautas namā' => [
            'name' => 'Kolberģa tautas nams',
            'city' => 'Kolberģis',
            'region' => 'Vidzeme',
            'address' => 'Kolberģis, Jaunalūksnes pagasts, Alūksnes novads, LV-4350',
            'latitude' => 57.4419,
            'longitude' => 27.1068,
            'place_type' => 'cultural_center',
        ],
        'liepnas tautas namā' => [
            'name' => 'Liepnas tautas nams',
            'city' => 'Liepna',
            'region' => 'Vidzeme',
            'address' => 'Liepna, Liepnas pagasts, Alūksnes novads, LV-4357',
            'latitude' => 57.3392,
            'longitude' => 27.4728,
            'place_type' => 'cultural_center',
        ],
        'mālupes saieta namā' => [
            'name' => 'Mālupes Saieta nams',
            'city' => 'Mālupe',
            'region' => 'Vidzeme',
            'address' => 'Mālupe, Mālupes pagasts, Alūksnes novads, LV-4356',
            'latitude' => 57.3242,
            'longitude' => 27.2882,
            'place_type' => 'cultural_center',
        ],
        'māriņkalna tautas namā' => [
            'name' => 'Māriņkalna tautas nams',
            'city' => 'Māriņkalns',
            'region' => 'Vidzeme',
            'address' => 'Māriņkalns, Ziemera pagasts, Alūksnes novads, LV-4334',
            'latitude' => 57.4831,
            'longitude' => 27.0194,
            'place_type' => 'cultural_center',
        ],
        'jaunlaicenes muižas muzejā “malēnieša pasaule”' => [
            'name' => 'Jaunlaicenes muižas muzejs “Malēnieša pasaule”',
            'city' => 'Jaunlaicene',
            'region' => 'Vidzeme',
            'address' => 'Jaunlaicene, Jaunlaicenes pagasts, Alūksnes novads, LV-4336',
            'latitude' => 57.5273,
            'longitude' => 26.8837,
            'place_type' => 'museum',
        ],
        'jaunlaicenes muižas muzejā' => [
            'name' => 'Jaunlaicenes muižas muzejs',
            'city' => 'Jaunlaicene',
            'region' => 'Vidzeme',
            'address' => 'Jaunlaicene, Jaunlaicenes pagasts, Alūksnes novads, LV-4336',
            'latitude' => 57.5273,
            'longitude' => 26.8837,
            'place_type' => 'museum',
        ],
        'jaunlaicenes kultūrtelpā “lazdiņas”' => [
            'name' => 'Jaunlaicenes kultūrtelpa “Lazdiņas”',
            'city' => 'Jaunlaicene',
            'region' => 'Vidzeme',
            'address' => 'Jaunlaicene, Jaunlaicenes pagasts, Alūksnes novads, LV-4336',
            'latitude' => 57.5273,
            'longitude' => 26.8837,
            'place_type' => 'cultural_center',
        ],
        'jaunlaicenes bibliotēkā' => [
            'name' => 'Jaunlaicenes bibliotēka',
            'city' => 'Jaunlaicene',
            'region' => 'Vidzeme',
            'address' => 'Jaunlaicene, Jaunlaicenes pagasts, Alūksnes novads, LV-4336',
            'latitude' => 57.5273,
            'longitude' => 26.8837,
            'place_type' => 'library',
        ],
        'pededzes tautas namā' => [
            'name' => 'Pededzes tautas nams',
            'city' => 'Pededze',
            'region' => 'Vidzeme',
            'address' => 'Pededze, Pededzes pagasts, Alūksnes novads, LV-4353',
            'latitude' => 57.4727,
            'longitude' => 27.4647,
            'place_type' => 'cultural_center',
        ],
        'alūksnē' => [
            'name' => 'Alūksne',
            'city' => 'Alūksne',
            'region' => 'Vidzeme',
            'address' => 'Alūksne, Alūksnes novads, LV-4301',
            'latitude' => 57.4238,
            'longitude' => 27.0448,
            'place_type' => 'venue',
        ],
    ];

    protected array $monthMap = [
        'janvārī' => 1, 'janvār' => 1, 'janvāris' => 1, 'jan' => 1,
        'februārī' => 2, 'februār' => 2, 'februāris' => 2, 'feb' => 2,
        'martā' => 3, 'mart' => 3, 'marts' => 3, 'mar' => 3,
        'aprīlī' => 4, 'aprīl' => 4, 'aprīlis' => 4, 'apr' => 4,
        'maijā' => 5, 'maij' => 5, 'maijs' => 5, 'mai' => 5,
        'jūnijā' => 6, 'jūnij' => 6, 'jūnijs' => 6, 'jūn' => 6,
        'jūlijā' => 7, 'jūlij' => 7, 'jūlijs' => 7, 'jūl' => 7,
        'augustā' => 8, 'august' => 8, 'augusts' => 8, 'aug' => 8,
        'septembrī' => 9, 'septembr' => 9, 'septembris' => 9, 'sep' => 9,
        'oktobrī' => 10, 'oktobr' => 10, 'oktobris' => 10, 'okt' => 10,
        'novembrī' => 11, 'novembr' => 11, 'novembris' => 11, 'nov' => 11,
        'decembrī' => 12, 'decembr' => 12, 'decembris' => 12, 'dec' => 12,
    ];

    protected string $defaultFallbackImage = 'https://visitaluksne.lv/wp-content/uploads/2021/04/2_Aluksnes_ezers_foto-Ainars_Gaidis-600x600.jpg';

    public function getSlug(): string
    {
        return 'aluksne-tourism';
    }

    public function getName(): string
    {
        return 'Visit Alūksne';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $baseUrl = $source->url ?: 'https://visitaluksne.lv/pasakumi-un-turisma-aktualitates/';
        $today = Carbon::today('Europe/Riga');

        $crawler = $this->fetchCrawler($baseUrl);
        if (!$crawler) {
            Log::warning("AluksneTourismScraper: failed to fetch base URL: {$baseUrl}");
            return $events;
        }

        $paragraphs = $crawler->filter('.entry-content p');
        if ($paragraphs->count() === 0) {
            Log::warning("AluksneTourismScraper: no paragraphs found on {$baseUrl}");
            return $events;
        }

        foreach ($paragraphs as $domElement) {
            try {
                $dto = $this->parseEventParagraph($domElement, $baseUrl, $today);

                if (!$dto) {
                    continue;
                }

                // Strict Scope Rule: Current and upcoming events only
                if ($dto->endAt) {
                    if ($dto->endAt->lt($today)) {
                        continue;
                    }
                } elseif ($dto->startAt->lt($today)) {
                    continue;
                }

                $events->push($dto);
            } catch (\Throwable $e) {
                Log::warning("AluksneTourismScraper: Failed to parse event paragraph", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $events;
    }

    public function parseEventParagraph(\DOMElement $element, string $baseUrl, ?Carbon $today = null): ?ScrapedEventDTO
    {
        $today = $today ?: Carbon::today('Europe/Riga');
        $raw = trim($element->textContent);

        // Normalize non-breaking spaces and whitespace
        $raw = preg_replace('/\x{00A0}/u', ' ', $raw);
        $raw = preg_replace('/\s+/u', ' ', $raw);

        // Separate attached numbers and words, e.g. "19.00Alūksnes" or "30.oktobrī"
        $raw = preg_replace('/(\d{1,2}[.:]\d{2})([A-ZĀČĒĢĪĶĻŅŠŪŽ])/u', '$1 $2', $raw);
        $raw = preg_replace('/(\d{1,2})\.([a-zāčēģīķļņšūž])/ui', '$1. $2', $raw);

        // Match leading date pattern e.g. "26.-31. oktobrī", "30. oktobrī 19.00", "7. novembrī no 16.00"
        $datePattern = '/^(\d{1,2}(?:\s*[\.\-–]+\s*\d{1,2})?\.?\s*(?:janvārī|februārī|martā|aprīlī|maijā|jūnijā|jūlijā|augustā|septembrī|oktobrī|novembrī|decembrī)[a-z]*)(?:\s*(?:no\s*|plkst\.?\s*)?(\d{1,2}[.:]\d{2}(?:\s*[-–]\s*\d{1,2}[.:]\d{2})?))?\s*/ui';

        if (!preg_match($datePattern, $raw, $m)) {
            return null;
        }

        $datePart = trim($m[1]);
        $timePart = isset($m[2]) ? trim($m[2]) : null;
        $remainder = trim(mb_substr($raw, mb_strlen($m[0])));

        // Parse date and time
        $parsedDates = $this->parseDates($datePart, $timePart, $today);
        if (!$parsedDates) {
            return null;
        }
        [$startAt, $endAt] = $parsedDates;

        // Skip past events
        if ($endAt ? $endAt->lt($today) : $startAt->lt($today)) {
            return null;
        }

        // Match venue
        $venueInfo = $this->matchVenue($raw);

        // Extract title and description
        $title = $this->extractTitle($remainder, $venueInfo['matched_key'] ?? null);
        if (empty($title)) {
            return null;
        }

        $description = $raw;
        $shortDescription = Str::limit($description, 250);

        // Classify category (strictly respecting AGENTS.md rules)
        $categoryNames = $this->classifyCategory($title, $description, $venueInfo['matched_key'] ?? null);

        // Extract price info
        [$isFree, $priceMin, $priceMax] = $this->parsePrice($raw);

        // Extract ticket URL & specific source URL from links inside the paragraph
        [$ticketUrl, $specificSourceUrl] = $this->extractLinks($element, $baseUrl);

        $sourceUrl = $specificSourceUrl ?: ($baseUrl . '#' . Str::slug($title . '-' . $venueInfo['name']));
        $externalId = 'aluksne-' . $startAt->format('Y-m-d') . '-' . Str::slug($venueInfo['name']) . '-' . Str::slug(mb_substr($title, 0, 30));

        // Find associated image
        $imageUrl = $this->findAssociatedImage($element) ?: $this->defaultFallbackImage;

        return new ScrapedEventDTO(
            title: $title,
            startAt: $startAt,
            endAt: $endAt,
            description: $description,
            shortDescription: $shortDescription,
            venueName: $venueInfo['name'],
            city: $venueInfo['city'],
            region: $venueInfo['region'],
            address: $venueInfo['address'],
            latitude: $venueInfo['latitude'],
            longitude: $venueInfo['longitude'],
            placeType: $venueInfo['place_type'],
            categoryNames: $categoryNames,
            isFree: $isFree,
            priceMin: $priceMin,
            priceMax: $priceMax,
            currency: 'EUR',
            ticketUrl: $ticketUrl,
            imageUrl: $imageUrl,
            sourceUrl: $sourceUrl,
            sourceExternalId: $externalId,
            locale: 'lv',
            rawData: [
                'original_paragraph' => $raw,
                'date_part' => $datePart,
                'time_part' => $timePart,
            ]
        );
    }

    public function parseDates(string $datePart, ?string $timePart, Carbon $today): ?array
    {
        $startDay = null;
        $endDay = null;
        $monthWord = null;

        // Multi-day range, e.g. "26.-31. oktobrī" or "26-31 oktobrī"
        if (preg_match('/^(\d{1,2})\s*[\.\-–]+\s*(\d{1,2})\.?\s*([a-zāčēģīķļņšūž]+)/ui', $datePart, $dm)) {
            $startDay = (int)$dm[1];
            $endDay = (int)$dm[2];
            $monthWord = mb_strtolower($dm[3]);
        } elseif (preg_match('/^(\d{1,2})\.?\s*([a-zāčēģīķļņšūž]+)/ui', $datePart, $dm)) {
            $startDay = (int)$dm[1];
            $monthWord = mb_strtolower($dm[2]);
        }

        if (!$startDay || !$monthWord) {
            return null;
        }

        $month = $this->monthMap[$monthWord] ?? null;
        if (!$month) {
            return null;
        }

        $year = $today->year;
        if ($month < $today->month - 2) {
            // Crossed into next year (e.g. current is Nov, event is Jan)
            $year++;
        }

        $hour = 0;
        $minute = 0;
        $hasTime = false;

        if ($timePart) {
            if (preg_match('/(\d{1,2})[.:](\d{2})/', $timePart, $tm)) {
                $hour = (int)$tm[1];
                $minute = (int)$tm[2];
                $hasTime = true;
            }
        }

        $startAt = Carbon::create($year, $month, $startDay, $hour, $minute, 0, 'Europe/Riga');

        if ($endDay) {
            $endAt = Carbon::create($year, $month, $endDay, 23, 59, 59, 'Europe/Riga');
        } elseif ($hasTime) {
            $endAt = $startAt->copy()->addHours(2);
        } else {
            $endAt = $startAt->copy()->endOfDay();
        }

        return [$startAt, $endAt];
    }

    public function matchVenue(string $text): array
    {
        $lower = mb_strtolower($text);
        foreach ($this->venueAddressMap as $key => $data) {
            if (mb_stripos($lower, $key) !== false) {
                return array_merge($data, ['matched_key' => $key]);
            }
        }

        return [
            'name' => 'Alūksne',
            'city' => 'Alūksne',
            'region' => 'Vidzeme',
            'address' => 'Alūksne, Alūksnes novads, LV-4301',
            'latitude' => 57.4238,
            'longitude' => 27.0448,
            'place_type' => 'venue',
            'matched_key' => null,
        ];
    }

    public function extractTitle(string $remainder, ?string $matchedVenueKey): string
    {
        $text = $remainder;

        // If text starts with venue name (e.g. "Alūksnes novada muzejā Leģendu nakts nedēļa..."), strip venue
        if ($matchedVenueKey && mb_stripos($text, $matchedVenueKey) === 0) {
            $text = trim(mb_substr($text, mb_strlen($matchedVenueKey)));
        } elseif ($matchedVenueKey && mb_stripos($text, 'Tikšanās vieta: ' . $matchedVenueKey) !== false) {
            $pos = mb_stripos($text, 'Tikšanās vieta:');
            $text = trim(mb_substr($text, 0, $pos));
        }

        // Remove leading punctuation / dashes
        $text = preg_replace('/^[\s:–—\-]+/u', '', $text);

        // Normalize missing period between quotes and sentence starters
        $text = preg_replace('/“([^”]+)”\s+(Būs|Aicinām|Piedalās|Ieeja|Biļetes|Pēc|Dalības|Galdiņu|Pieteikšanās)\b/u', '“$1”. $2', $text);

        // Protect abbreviations and periods inside quotes / initials before splitting
        $masked = preg_replace_callback('/“[^”]+”/u', function ($m) {
            return str_replace('.', '###DOT###', $m[0]);
        }, $text);
        $masked = preg_replace('/\b([A-ZĀČĒĢĪĶĻŅŠŪŽ])\./u', '$1###DOT###', $masked);
        $masked = preg_replace('/(\d+)\.(\d+)/u', '$1###DOT###$2', $masked);

        // Split by period / exclamation / question followed by capital letter / number / quote
        $parts = preg_split('/(?<=[.!?])\s+(?=[A-ZĀČĒĢĪĶĻŅŠŪŽ0-9“])/u', $masked, 2);
        $titleCandidate = str_replace('###DOT###', '.', trim($parts[0] ?? $text));
        $titleCandidate = rtrim($titleCandidate, '.');

        // Clean up common phrasing prefixes if any
        if (preg_match('/^viesosies\s+(.+)$/ui', $titleCandidate, $vm)) {
            $titleCandidate = $vm[1];
        }

        // Capitalize first letter
        $firstChar = mb_strtoupper(mb_substr($titleCandidate, 0, 1));
        return $firstChar . mb_substr($titleCandidate, 1);
    }

    public function classifyCategory(string $title, string $description, ?string $venueKey = null): array
    {
        // Strip venue name to avoid classifying museum venue as exhibition when it's a concert or game
        $textToAnalyze = $title . ' ' . $description;
        if ($venueKey) {
            $textToAnalyze = str_ireplace($venueKey, '', $textToAnalyze);
        }
        $t = mb_strtolower($textToAnalyze);

        // Rule 1: Cinema / Kino (Strict & Priority)
        if (preg_match('/\b(filma|filmas|filmu|filmā|kinoseanss|spēlfilma|dokumentālā filma|animācijas filma|kinofestivāls)\b/ui', $t)) {
            return ['Kino'];
        }

        // Kids
        if (preg_match('/\b(bērniem|leļļu izrāde|mazajiem skatītājiem|ģimenēm ar bērniem|rotaļas)\b/ui', $t)) {
            return ['Bērniem'];
        }

        // Rule 2: Theater
        if (preg_match('/\b(izrāde|izrādes|izrādi|izrāžu|luga|lugas|stand-up|komēdija|komēdijizrāde|skeču|amatierteāt[a-zāčēģīķļņšūž]*|viencēlien[a-zāčēģīķļņšūž]*|teātra|teātris)\b/ui', $t)) {
            return ['Teātris'];
        }

        // Exhibitions
        if (preg_match('/\b(izstād[a-zāčēģīķļņšūž]*|ekskursij[a-zāčēģīķļņšūž]*|personālizstād[a-zāčēģīķļņšūž]*)\b/ui', $t)) {
            return ['Izstādes'];
        }

        // Sports
        if (preg_match('/\b(turnīrs|turnīra|sacensības|pārgājiens|čempionāts|kāršu spēle|spēles puns|\bpuna\b|\bpuns\b)\b/ui', $t)) {
            return ['Sports'];
        }

        // Holidays / Festivals
        if (preg_match('/\b(leģendu nakts|muzeju nakts|svētki|svētkos|svētku|lāčplēša diena|gadadiena|proklamēšanas|advente|adventes|andele)\b/ui', $t)) {
            return ['Svētki'];
        }

        // Workshops / Seminars
        if (preg_match('/\b(meistarklase|darbnīca|radošā darbnīca|lekcija|seminārs|erudīcijas spēle|stāstījums)\b/ui', $t)) {
            return ['Semināri'];
        }

        // Music
        if (preg_match('/\b(koncert[a-zāčēģīķļņšūž]*|dziesm[a-zāčēģīķļņšūž]*|koris|kora|koru|ansambl[a-zāčēģīķļņšūž]*|mūzik[a-zāčēģīķļņšūž]*|balle|sadziedās[a-zāčēģīķļņšūž]*|saksofon[a-zāčēģīķļņšūž]*|klavier[a-zāčēģīķļņšūž]*|orķestr[a-zāčēģīķļņšūž]*)\b/ui', $t)) {
            return ['Mūzika'];
        }

        // Rule 3: Fallback
        return ['Cits'];
    }

    public function parsePrice(string $text): array
    {
        $isFree = false;
        $priceMin = null;
        $priceMax = null;

        if (preg_match('/(bez maksas|ieeja:\s*brīva|brīva ieeja|ieeja\s+brīva)/ui', $text)) {
            return [true, 0.0, 0.0];
        }

        // Check for range like "15,00-25,00 EUR"
        if (preg_match('/(\d+(?:[.,]\d{1,2})?)\s*[-–]\s*(\d+(?:[.,]\d{1,2})?)\s*EUR/ui', $text, $m)) {
            $priceMin = (float)str_replace(',', '.', $m[1]);
            $priceMax = (float)str_replace(',', '.', $m[2]);
            return [false, $priceMin, $priceMax];
        }

        // Check all single EUR occurrences
        if (preg_match_all('/(\d+(?:[.,]\d{1,2})?)\s*EUR/ui', $text, $allM)) {
            $prices = array_map(fn($v) => (float)str_replace(',', '.', $v), $allM[1]);
            sort($prices);
            $priceMin = $prices[0];
            $priceMax = end($prices);
            return [$priceMax === 0.0, $priceMin, $priceMax];
        }

        return [$isFree, $priceMin, $priceMax];
    }

    public function extractLinks(\DOMElement $element, string $baseUrl): array
    {
        $ticketUrl = null;
        $specificSourceUrl = null;

        $anchors = $element->getElementsByTagName('a');
        foreach ($anchors as $a) {
            $href = trim($a->getAttribute('href') ?? '');
            if (empty($href) || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                continue;
            }

            // Ticket portal check
            if (preg_match('/(bilesuparadize\.lv|bilesuserviss\.lv|bezrindas\.lv|ticketshop\.lv|passportix\.eu)/i', $href)) {
                // Strip tracking params
                $ticketUrl = preg_replace('/(\?|&)(fbclid|utm_[^=]+)=[^&#]*/i', '', $href);
                $ticketUrl = rtrim($ticketUrl, '?&');
            } elseif (mb_stripos($href, 'visitaluksne.lv') !== false && !str_contains($href, '/wp-content/') && !str_ends_with($href, '/visit/aluksnes-muzejs/')) {
                $specificSourceUrl = $href;
            }
        }

        return [$ticketUrl, $specificSourceUrl];
    }

    public function findAssociatedImage(\DOMElement $element): ?string
    {
        // Traverse following element siblings to find figure.wp-caption
        $sibling = $element->nextSibling;
        while ($sibling) {
            if ($sibling->nodeType === XML_ELEMENT_NODE) {
                if ($sibling->nodeName === 'figure') {
                    // Check <a> tag with direct image link
                    $links = $sibling->getElementsByTagName('a');
                    if ($links->length > 0) {
                        $href = $links->item(0)->getAttribute('href');
                        if (preg_match('/\.(jpe?g|png|webp)/i', $href)) {
                            return $href;
                        }
                    }

                    // Check <img> tag
                    $imgs = $sibling->getElementsByTagName('img');
                    if ($imgs->length > 0) {
                        $img = $imgs->item(0);
                        $dataSrc = $img->getAttribute('data-tf-src');
                        if ($dataSrc && preg_match('/\.(jpe?g|png|webp)/i', $dataSrc)) {
                            return $dataSrc;
                        }
                        $src = $img->getAttribute('src');
                        if ($src && preg_match('/\.(jpe?g|png|webp)/i', $src)) {
                            return $src;
                        }
                    }
                }
                break; // Stop at first element sibling
            }
            $sibling = $sibling->nextSibling;
        }

        return null;
    }
}
