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

class VefkpScraper extends BaseScraper
{
    public const BASE_URL = 'https://www.vefkp.lv';
    public const EVENTS_URL = 'https://www.vefkp.lv/pasakumi/';
    public const AJAX_URL = 'https://www.vefkp.lv/wp-admin/admin-ajax.php';

    public function getSlug(): string
    {
        return 'vef-kulturas-pils';
    }

    public function getName(): string
    {
        return 'VEF Kultūras pils';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $today = Carbon::today('Europe/Riga');
        $seenKeys = [];
        $detailCache = [];

        // 1. Fetch initial events page to extract WordPress nonce
        $nonce = $this->extractNonce();
        if (!$nonce) {
            Log::error('VEF Kultūras pils scraper: could not extract nonce from ' . self::EVENTS_URL);
            return $events;
        }

        // 2. Iterate upcoming months starting from current month (up to 12 months ahead)
        $monthCursor = Carbon::today('Europe/Riga')->startOfMonth();
        $consecutiveEmptyMonths = 0;

        for ($i = 0; $i < 12; $i++) {
            $dateParam = $monthCursor->format('m/01/Y');
            $archiveHtml = $this->fetchMonthArchive($nonce, $dateParam);

            if (empty($archiveHtml)) {
                $consecutiveEmptyMonths++;
                if ($consecutiveEmptyMonths >= 3) {
                    break;
                }
                $monthCursor->addMonth();
                continue;
            }

            $consecutiveEmptyMonths = 0;
            $monthEvents = $this->parseArchiveHtml(
                $archiveHtml,
                $today,
                $seenKeys,
                $detailCache
            );

            foreach ($monthEvents as $dto) {
                $events->push($dto);
            }

            $monthCursor->addMonth();
        }

        Log::info("VEF Kultūras pils scraper completed. Total events scraped: {$events->count()}", [
            'scraper' => static::class,
        ]);

        return $events;
    }

    /**
     * Extract WordPress AJAX nonce from the events page HTML
     */
    protected function extractNonce(): ?string
    {
        try {
            $response = $this->httpClient->get(self::EVENTS_URL);
            $html = (string) $response->getBody();

            if (preg_match('/"nonce"\s*:\s*"([^"]+)"/i', $html, $matches)) {
                return $matches[1];
            }
        } catch (\Throwable $e) {
            Log::error('VEF Kultūras pils scraper nonce request error: ' . $e->getMessage(), [
                'scraper' => static::class,
            ]);
        }

        return null;
    }

    /**
     * Fetch event archive HTML for a specific month via admin-ajax.php
     */
    protected function fetchMonthArchive(string $nonce, string $dateParam): ?string
    {
        $this->politeDelay(400, 150);

        try {
            $response = $this->httpClient->post(self::AJAX_URL, [
                'form_params' => [
                    'action' => 'event_days',
                    'nonce' => $nonce,
                    'date' => $dateParam,
                ],
            ]);

            $json = json_decode((string) $response->getBody(), true);
            if (is_array($json) && !empty($json['archive'])) {
                return $json['archive'];
            }
        } catch (\Throwable $e) {
            Log::warning("VEF Kultūras pils month archive fetch failed for {$dateParam}: " . $e->getMessage(), [
                'scraper' => static::class,
            ]);
        }

        return null;
    }

    /**
     * Parse month archive HTML containing loop-event cards
     *
     * @return array<ScrapedEventDTO>
     */
    protected function parseArchiveHtml(
        string $html,
        Carbon $today,
        array &$seenKeys,
        array &$detailCache
    ): array {
        $parsed = [];
        $crawler = new Crawler($html);

        $crawler->filter('div.loop-event')->each(function (Crawler $card) use (
            &$parsed,
            $today,
            &$seenKeys,
            &$detailCache
        ) {
            // Extract raw fields
            $titleNode = $card->filter('.event-title');
            $title = $titleNode->count() ? $this->cleanText($titleNode->text()) : '';
            if (empty($title)) {
                return;
            }

            $dateNode = $card->filter('.event-date');
            $dateText = $dateNode->count() ? $this->cleanText($dateNode->text()) : '';

            $hallNode = $card->filter('.event-description');
            $hall = $hallNode->count() ? $this->cleanText($hallNode->text()) : null;

            $imageNode = $card->filter('.event-image img');
            $imageUrl = $imageNode->count() ? $imageNode->attr('src') : null;
            if ($imageUrl && !str_starts_with($imageUrl, 'http')) {
                $imageUrl = rtrim(self::BASE_URL, '/') . '/' . ltrim($imageUrl, '/');
            }

            // Ticket and detail links
            $ticketUrl = null;
            $detailUrl = null;
            $isFree = false;

            $card->filter('.event-links a')->each(function (Crawler $a) use (&$ticketUrl, &$detailUrl, &$isFree) {
                $href = trim($a->attr('href') ?? '');
                $text = mb_strtolower(trim($a->text()), 'UTF-8');
                $classes = $a->attr('class') ?? '';

                if (str_contains($text, 'bez maksas') || str_contains($classes, 'disabled')) {
                    $isFree = true;
                }

                if ($this->isTicketingUrl($href)) {
                    $ticketUrl = $href;
                } elseif (str_contains($href, 'vefkp.lv/pasakumi/')) {
                    $detailUrl = $href;
                }
            });

            // Date parsing
            [$startAt, $endAt, $allDay] = $this->parseEventDates($dateText, $card);

            if (!$startAt && !$endAt) {
                return;
            }

            // Skip past events per AGENTS.md rule
            if ($endAt && $endAt->lessThan($today)) {
                return;
            }
            if (!$endAt && $startAt && $startAt->lessThan($today)) {
                return;
            }

            // Slug and unique source_id
            $slug = '';
            if ($detailUrl) {
                $path = trim(parse_url($detailUrl, PHP_URL_PATH) ?? '', '/');
                $slug = basename($path);
            }
            if (empty($slug)) {
                $slug = Str::slug($title);
            }

            $sourceId = 'vefkp_' . $slug . ($startAt ? '_' . $startAt->format('YmdHi') : '');

            // Deduplicate across months
            if (isset($seenKeys[$sourceId])) {
                return;
            }
            $seenKeys[$sourceId] = true;

            // Fetch rich detail page if available
            $description = null;
            if ($detailUrl) {
                $detailData = $this->fetchDetailPage($detailUrl, $detailCache);
                if ($detailData) {
                    $description = $detailData['description'] ?? null;
                    if (empty($ticketUrl) && !empty($detailData['ticket_url'])) {
                        $ticketUrl = $detailData['ticket_url'];
                    }
                    if (!$isFree && !empty($detailData['is_free'])) {
                        $isFree = true;
                    }
                    if (empty($hall) && !empty($detailData['hall'])) {
                        $hall = $detailData['hall'];
                    }
                }
            }

            // Fallback description
            if (empty($description)) {
                $description = $title . ($hall ? " ({$hall})" : '') . ' VEF Kultūras pilī.';
            }

            // If hall is known, prepend venue hall indicator
            if ($hall && !str_contains($description, $hall)) {
                $description = "Norises vieta: {$hall}\n\n" . $description;
            }

            // Categories
            $catIdsAttr = $card->attr('data-event_categories');
            $categories = $this->resolveCategories($catIdsAttr, $title, $description);

            $parsed[] = new ScrapedEventDTO(
                title: $title,
                startAt: $startAt,
                endAt: $endAt,
                description: $description,
                shortDescription: mb_strlen($description) <= 220 ? $description : Str::limit(strip_tags($description), 160),
                venueName: 'VEF Kultūras pils',
                city: 'Rīga',
                region: 'Rīga un Pierīga',
                address: 'Ropažu iela 2, Rīga, LV-1039',
                latitude: 56.9745,
                longitude: 24.16605,
                placeType: 'cultural_centre',
                categoryNames: $categories,
                isFree: $isFree,
                priceMin: $isFree ? 0.0 : null,
                priceMax: $isFree ? 0.0 : null,
                currency: 'EUR',
                ticketUrl: $ticketUrl,
                imageUrl: $imageUrl,
                sourceUrl: $detailUrl ?: self::EVENTS_URL,
                sourceExternalId: $sourceId,
                rawData: [
                    'hall' => $hall,
                    'date_text' => $dateText,
                    'wp_categories' => $catIdsAttr,
                    'ticket_url' => $ticketUrl,
                    'detail_url' => $detailUrl,
                    'all_day' => $allDay,
                ]
            );
        });

        return $parsed;
    }

    /**
     * Fetch and parse event detail page
     */
    protected function fetchDetailPage(string $url, array &$detailCache): ?array
    {
        if (isset($detailCache[$url])) {
            return $detailCache[$url];
        }

        $this->politeDelay(300, 100);

        try {
            $crawler = $this->fetchCrawler($url);
            if (!$crawler) {
                $detailCache[$url] = null;
                return null;
            }

            // Description
            $descNode = $crawler->filter('.article-content');
            $description = null;
            if ($descNode->count()) {
                $rawDesc = $this->cleanText($descNode->text());
                // Strip common photo/video recording disclaimer at the bottom
                $cleanDesc = preg_replace('/Pasākuma organizators informē.*$/us', '', $rawDesc);
                $description = trim($cleanDesc ?? '');
            }

            // Ticket URL
            $ticketUrl = null;
            $isFree = false;
            $crawler->filter('.hero-links a')->each(function (Crawler $a) use (&$ticketUrl, &$isFree) {
                $href = trim($a->attr('href') ?? '');
                $text = mb_strtolower(trim($a->text()), 'UTF-8');
                $classes = $a->attr('class') ?? '';

                if (str_contains($text, 'bez maksas') || str_contains($classes, 'disabled')) {
                    $isFree = true;
                }

                if ($this->isTicketingUrl($href)) {
                    $ticketUrl = $href;
                }
            });

            // Hall
            $hallNode = $crawler->filter('.hero-description');
            $hall = $hallNode->count() ? $this->cleanText($hallNode->text()) : null;

            $data = [
                'description' => $description,
                'ticket_url' => $ticketUrl,
                'is_free' => $isFree,
                'hall' => $hall,
            ];

            $detailCache[$url] = $data;
            return $data;
        } catch (\Throwable $e) {
            Log::warning("VEF Kultūras pils detail fetch error for {$url}: " . $e->getMessage(), [
                'scraper' => static::class,
            ]);
        }

        $detailCache[$url] = null;
        return null;
    }

    /**
     * Check if a URL belongs to a ticketing provider
     */
    protected function isTicketingUrl(string $url): bool
    {
        if (empty($url)) {
            return false;
        }

        return str_contains($url, 'bilesuparadize.lv')
            || str_contains($url, 'bilesuserviss.lv')
            || str_contains($url, 'bezrindas.lv')
            || str_contains($url, 'ticketbest.eu')
            || str_contains($url, 'ticketbest.lv')
            || str_contains($url, 'ticketshop.lv')
            || str_contains($url, 'aula.lv')
            || str_contains($url, 'passportix.eu')
            || str_contains($url, 'fienta.com');
    }

    /**
     * Parse date strings from card markup
     *
     * @return array{0: ?Carbon, 1: ?Carbon, 2: bool} [startAt, endAt, allDay]
     */
    public function parseEventDates(string $dateText, ?Crawler $card = null): array
    {
        $dateText = trim($dateText);
        $startAt = null;
        $endAt = null;
        $allDay = false;

        // Pattern 1: D. DD.MM.YYYY. HH:MM - D. DD.MM.YYYY. HH:MM
        if (preg_match('/(?:[A-Z][a-z]?\.\s*)?(\d{1,2})\.(\d{1,2})\.(\d{4})\.\s*(\d{1,2}:\d{2})\s*-\s*(?:[A-Z][a-z]?\.\s*)?(\d{1,2})\.(\d{1,2})\.(\d{4})\.\s*(\d{1,2}:\d{2})/u', $dateText, $m)) {
            $startAt = Carbon::createFromFormat('d.m.Y H:i', sprintf('%02d.%02d.%04d %s', $m[1], $m[2], $m[3], $m[4]), 'Europe/Riga');
            $endAt = Carbon::createFromFormat('d.m.Y H:i', sprintf('%02d.%02d.%04d %s', $m[5], $m[6], $m[7], $m[8]), 'Europe/Riga');
        }
        // Pattern 2: D. DD.MM.YYYY. HH:MM - HH:MM
        elseif (preg_match('/(?:[A-Z][a-z]?\.\s*)?(\d{1,2})\.(\d{1,2})\.(\d{4})\.\s*(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})/u', $dateText, $m)) {
            $startAt = Carbon::createFromFormat('d.m.Y H:i', sprintf('%02d.%02d.%04d %s', $m[1], $m[2], $m[3], $m[4]), 'Europe/Riga');
            $endAt = Carbon::createFromFormat('d.m.Y H:i', sprintf('%02d.%02d.%04d %s', $m[1], $m[2], $m[3], $m[5]), 'Europe/Riga');
        }
        // Pattern 3: D. DD.MM.YYYY. HH:MM
        elseif (preg_match('/(?:[A-Z][a-z]?\.\s*)?(\d{1,2})\.(\d{1,2})\.(\d{4})\.\s*(\d{1,2}:\d{2})/u', $dateText, $m)) {
            $startAt = Carbon::createFromFormat('d.m.Y H:i', sprintf('%02d.%02d.%04d %s', $m[1], $m[2], $m[3], $m[4]), 'Europe/Riga');
        }
        // Pattern 4: D. DD.MM.YYYY. - D. DD.MM.YYYY.
        elseif (preg_match('/(?:[A-Z][a-z]?\.\s*)?(\d{1,2})\.(\d{1,2})\.(\d{4})\.?\s*-\s*(?:[A-Z][a-z]?\.\s*)?(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $dateText, $m)) {
            $startAt = Carbon::createFromFormat('d.m.Y', sprintf('%02d.%02d.%04d', $m[1], $m[2], $m[3]), 'Europe/Riga')->startOfDay();
            $endAt = Carbon::createFromFormat('d.m.Y', sprintf('%02d.%02d.%04d', $m[4], $m[5], $m[6]), 'Europe/Riga')->endOfDay();
            $allDay = true;
        }
        // Pattern 5: D. DD.MM.YYYY.
        elseif (preg_match('/(?:[A-Z][a-z]?\.\s*)?(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $dateText, $m)) {
            $startAt = Carbon::createFromFormat('d.m.Y', sprintf('%02d.%02d.%04d', $m[1], $m[2], $m[3]), 'Europe/Riga')->startOfDay();
            $allDay = true;
        }

        // Fallback to data-date-from and data-date-to attributes on card (format MM/DD/YYYY)
        if (!$startAt && $card) {
            $dateFrom = $card->attr('data-date-from');
            $dateTo = $card->attr('data-date-to');

            if ($dateFrom && preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', trim($dateFrom), $fm)) {
                $startAt = Carbon::createFromFormat('m/d/Y', sprintf('%02d/%02d/%04d', $fm[1], $fm[2], $fm[3]), 'Europe/Riga')->startOfDay();
                $allDay = true;
            }
            if ($dateTo && preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', trim($dateTo), $tm)) {
                $endAt = Carbon::createFromFormat('m/d/Y', sprintf('%02d/%02d/%04d', $tm[1], $tm[2], $tm[3]), 'Europe/Riga')->endOfDay();
                $allDay = true;
            }
        }

        return [$startAt, $endAt, $allDay];
    }

    /**
     * Resolve categories complying strictly with AGENTS.md rules
     */
    public function resolveCategories(?string $catIdsString, string $title, ?string $description): array
    {
        $titleLower = mb_strtolower($title, 'UTF-8');
        $descLower = mb_strtolower($description ?? '', 'UTF-8');
        $combined = $titleLower . ' ' . $descLower;

        // 1. Strict Kino rule: If title or description indicates film/cinema
        if (
            preg_match('/\b(?:kinoseanss|filmas seanss|spēlfilma|dokumentālā filma|animācijas filma|kinofestivāls)\b/u', $combined) ||
            preg_match('/\b(?:filma|filmas|filmu|filmā)\b/u', $combined)
        ) {
            return ['kino'];
        }

        $categories = [];

        // Map WP category IDs if present
        if (!empty($catIdsString)) {
            $ids = explode(';', $catIdsString);
            foreach ($ids as $id) {
                $id = trim($id);
                match ($id) {
                    '44' => $categories[] = 'muzika',
                    '45' => $categories[] = 'teatris',
                    '41' => $categories[] = 'izstades',
                    '261' => $categories[] = 'berniem',
                    '314', '54', '368' => $categories[] = 'seminari',
                    '407' => $categories[] = 'svetki',
                    default => null,
                };
            }
        }

        // Strict Theatre rule per AGENTS.md:
        // Only actual theater productions (*izrāde*, *luga*, *iestudējums*, *opera*, *balets*)
        if (in_array('teatris', $categories, true)) {
            $isActualTheater = preg_match('/\b(?:izrāde|izrādes|luga|iestudējums|iestudējumi|opera|operete|balets|stāvizrāde|stand-up izrāde)\b/u', $combined);
            if (!$isActualTheater) {
                $categories = array_values(array_diff($categories, ['teatris']));
            }
        }

        // If 'teatris' was not in WP categories, infer from title
        if (!in_array('teatris', $categories, true)) {
            if (preg_match('/\b(?:izrāde|luga|iestudējums|operete|balets|stand-up izrāde|stāvizrāde)\b/u', $titleLower)) {
                $categories[] = 'teatris';
            }
        }

        // Kids / berniem
        if (!in_array('berniem', $categories, true)) {
            if (preg_match('/\b(?:bērniem|bērnu|ģimenēm|pasaka|leļļu)\b/u', $titleLower)) {
                $categories[] = 'berniem';
            }
        }

        // Music / muzika
        if (!in_array('muzika', $categories, true)) {
            if (preg_match('/\b(?:koncerts|koncertuzvedums|kamermūzika|koris|orķestris|dziesmas|dziesmu|opera|operete|džezs|rokopera|dzied)\b/u', $titleLower)) {
                $categories[] = 'muzika';
            }
        }

        // Exhibitions / izstades
        if (!in_array('izstades', $categories, true)) {
            if (preg_match('/\b(?:izstāde|gleznas|grafika|personālizstāde|ekspozīcija)\b/u', $titleLower)) {
                $categories[] = 'izstades';
            }
        }

        $categories = array_values(array_unique($categories));

        if (empty($categories)) {
            $categories = ['citi'];
        }

        return $categories;
    }
}
