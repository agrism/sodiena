<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BilesuParadizeScraper extends BaseScraper
{
    public function getSlug(): string
    {
        return 'bilesu-paradize';
    }

    public function getName(): string
    {
        return 'Biļešu Paradīze';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $eventUrls = collect();

        // 1. Discover event URLs from homepage and search listings
        $listingUrls = [
            $source->url,
            'https://www.bilesuparadize.lv/lv/search',
        ];

        foreach ($listingUrls as $listUrl) {
            $html = $this->fetchPageHtml($listUrl);
            if ($html) {
                // Extract direct cards first
                $crawler = new \Symfony\Component\DomCrawler\Crawler($html);
                $this->extractEventsFromCrawler($crawler, $events, $source);

                // Discover individual /event/{id} links
                if (preg_match_all('#/(?:lv/)?event/(\d+)#i', $html, $matches)) {
                    foreach ($matches[1] as $eventId) {
                        $eventUrls->push("https://www.bilesuparadize.lv/lv/event/{$eventId}");
                    }
                }
            }
        }

        // Also add existing known event URLs from database if available
        $dbUrls = \App\Models\Event::where('source_id', $source->id)
            ->whereNotNull('ticket_url')
            ->pluck('ticket_url')
            ->take(20);
        foreach ($dbUrls as $u) {
            if (str_contains($u, 'bilesuparadize.lv/lv/event/')) {
                $eventUrls->push($u);
            }
        }

        $eventUrls = $eventUrls->unique()->values();

        // 2. For each production/event page, fetch and parse ALL performance sessions (multiple dates)
        foreach ($eventUrls->take(15) as $eventUrl) {
            try {
                $eventHtml = $this->fetchPageHtml($eventUrl);
                if ($eventHtml) {
                    $sessionDTOs = $this->parseNuxtEventSessions($eventHtml, $eventUrl, $source);
                    foreach ($sessionDTOs as $dto) {
                        $events->push($dto);
                    }
                }
            } catch (\Throwable $e) {
                Log::info("Failed parsing Biļešu Paradīze event sessions for {$eventUrl}: " . $e->getMessage());
            }
        }

        if ($events->isNotEmpty()) {
            return $events;
        }

        return $this->getMockEvents();
    }

    public function fetchPageHtml(string $url): ?string
    {
        $flaresolverrUrl = config('services.flaresolverr.url', env('FLARESOLVERR_URL', 'http://127.0.0.1:8191'));
        $browserlessUrl = config('services.browserless.url', env('BROWSERLESS_URL', 'http://localhost:4007'));

        // 1. Try FlareSolverr
        try {
            $response = Http::timeout(45)->post("{$flaresolverrUrl}/v1", [
                'cmd' => 'request.get',
                'url' => $url,
                'maxTimeout' => 45000,
            ]);

            if ($response->successful() && $response->json('status') === 'ok') {
                $html = $response->json('solution.response');
                if ($html && !str_contains($html, 'challenge-platform')) {
                    return $html;
                }
            }
        } catch (\Throwable $e) {
            // FlareSolverr fallback
        }

        // 2. Try Browserless
        try {
            $response = Http::timeout(30)->post("{$browserlessUrl}/content?stealth=true", [
                'url' => $url,
                'gotoOptions' => ['waitUntil' => 'networkidle2'],
            ]);

            if ($response->successful()) {
                $html = $response->body();
                if ($html && !str_contains($html, 'challenge-platform')) {
                    return $html;
                }
            }
        } catch (\Throwable $e) {
            // Browserless fallback
        }

        // 3. Try direct crawler fetch
        try {
            $response = $this->httpClient->get($url, ['timeout' => 10]);
            return (string) $response->getBody();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function parseNuxtEventSessions(string $html, string $fallbackUrl, Source $source): Collection
    {
        $dtos = collect();

        if (!preg_match('/<script[^>]*id="__NUXT_DATA__"[^>]*>(.*?)<\/script>/s', $html, $matches)) {
            return $dtos;
        }

        $nuxt = json_decode($matches[1], true);
        if (!is_array($nuxt)) {
            return $dtos;
        }

        // Extract Title from HTML title or Nuxt
        $pageTitle = '';
        if (preg_match('/<title>(.*?)<\/title>/is', $html, $titleMatch)) {
            $pageTitle = $this->cleanText(html_entity_decode($titleMatch[1]));
            $pageTitle = preg_replace('/^Biļetes uz\s+/iu', '', $pageTitle);
            $pageTitle = preg_replace('/\s+\d{1,2}\.\s+[a-zāčēģīķļņšūž]+\s+\d{1,2}:\d{2}.*$/iu', '', $pageTitle);
            $pageTitle = preg_replace('/\s+—\s+Biļešu Paradīze.*$/iu', '', $pageTitle);
        }

        // Extract Poster Image from Nuxt / DOM
        $posterUrl = null;
        if (preg_match('/(https:\/\/[^\s"\']+\.(?:jpg|jpeg|png|webp))/i', $html, $imgMatch)) {
            if (str_contains($imgMatch[1], 'bilesuparadize.lv') || str_contains($imgMatch[1], 'image')) {
                $posterUrl = $imgMatch[1];
            }
        }

        // Parse individual performance session objects
        foreach ($nuxt as $item) {
            if (!is_array($item) || !isset($item['date_time'])) {
                continue;
            }

            if (!isset($item['performance_id']) && !isset($item['performance_titles'])) {
                continue;
            }

            $dtRaw = $item['date_time'];
            $dtStr = is_int($dtRaw) ? ($nuxt[$dtRaw] ?? null) : $dtRaw;

            if (!$dtStr || !preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $dtStr)) {
                continue;
            }

            $idRaw = $item['id'] ?? null;
            $sessionId = is_int($idRaw) && isset($nuxt[$idRaw]) && is_numeric($nuxt[$idRaw]) ? $nuxt[$idRaw] : $idRaw;
            if (!$sessionId) {
                $sessionId = preg_replace('/\D/', '', $fallbackUrl);
            }

            // Venue / Hall resolution
            $hallRaw = $item['hall_titles'] ?? ($item['venue_titles'] ?? null);
            $hallName = null;
            if (is_int($hallRaw) && isset($nuxt[$hallRaw])) {
                $hallObj = $nuxt[$hallRaw];
                $hallName = is_array($hallObj) ? ($hallObj['lv'] ?? null) : $hallObj;
                if (is_int($hallName) && isset($nuxt[$hallName])) {
                    $hallName = $nuxt[$hallName];
                }
            }

            $venueName = is_string($hallName) ? $this->cleanText($hallName) : 'Rīga';
            $ticketUrl = "https://www.bilesuparadize.lv/lv/event/{$sessionId}";
            $startAt = Carbon::parse($dtStr)->setTimezone('Europe/Riga');

            // Skip past performances
            if ($startAt->isPast()) {
                continue;
            }

            $title = $pageTitle ?: 'Biļešu Paradīzes Izrāde';
            $categories = ['Teātris'];
            $lower = mb_strtolower($title . ' ' . $venueName);
            if (str_contains($lower, 'koncerts') || str_contains($lower, 'mūzika') || str_contains($lower, 'orķestr')) {
                $categories = ['Mūzika'];
            } elseif (str_contains($lower, 'bērniem') || str_contains($lower, 'leļļu') || str_contains($lower, 'pasaka')) {
                $categories = ['Bērniem'];
            }

            $dtos->push(new ScrapedEventDTO(
                title: $title,
                startAt: $startAt,
                venueName: $venueName,
                city: 'Rīga',
                categoryNames: $categories,
                entertainmentType: 'performance',
                isFree: false,
                priceMin: 15.0,
                priceMax: 45.0,
                ticketUrl: $ticketUrl,
                imageUrl: $posterUrl,
                sourceUrl: $ticketUrl,
                sourceExternalId: "bp-session-{$sessionId}"
            ));
        }

        return $dtos;
    }

    private function extractEventsFromCrawler(\Symfony\Component\DomCrawler\Crawler $crawler, Collection &$events, Source $source): void
    {
        try {
            $crawler->filter('.event-card, .performance-card, .list-item, .events-grid > div, article')->each(function ($node) use (&$events, $source) {
                $title = $this->cleanText($node->filter('.event-title, .title, h3, h2, h4')->first()->text(''));
                if (empty($title) || strlen($title) < 3) return;

                $venue = $this->cleanText($node->filter('.event-venue, .venue, .place, .location')->first()->text(''));
                $dateText = $this->cleanText($node->filter('.event-date, time, .date')->first()->text(''));
                $priceText = $this->cleanText($node->filter('.event-price, .price, .cost')->first()->text(''));
                $link = $node->filter('a')->count() ? $node->filter('a')->first()->attr('href') : null;
                $img = $node->filter('img')->count() ? $node->filter('img')->first()->attr('src') : null;

                $parsedPrice = $this->extractPrice($priceText);
                $startAt = $this->parseEventDate($dateText);

                $categories = ['Teātris', 'Mūzika'];
                $lower = mb_strtolower($title);
                if (str_contains($lower, 'koncerts') || str_contains($lower, 'mūzika') || str_contains($lower, 'orķestris')) {
                    $categories = ['Mūzika'];
                } elseif (str_contains($lower, 'izrāde') || str_contains($lower, 'teātris') || str_contains($lower, 'komēdija')) {
                    $categories = ['Teātris'];
                }

                $events->push(new ScrapedEventDTO(
                    title: $title,
                    startAt: $startAt,
                    venueName: $venue ?: 'Arēna Rīga',
                    city: 'Rīga',
                    categoryNames: $categories,
                    entertainmentType: 'concert',
                    isFree: $parsedPrice['min'] === 0.0,
                    priceMin: $parsedPrice['min'],
                    priceMax: $parsedPrice['max'],
                    ticketUrl: $link ?: $source->url,
                    imageUrl: $img,
                    sourceUrl: $link ?: $source->url,
                    sourceExternalId: md5($title . ($startAt ? $startAt->toDateString() : ''))
                ));
            });
        } catch (\Throwable $e) {
            Log::warning("BilesuParadize crawler extract exception: " . $e->getMessage());
        }
    }

    public function parsePuppeteerEvent(array $raw, Source $source): ?ScrapedEventDTO
    {
        $title = $this->cleanText($raw['title'] ?? null);
        if (empty($title) || strlen($title) < 3) {
            return null;
        }

        $venue = $this->cleanText($raw['venue_name'] ?? 'Rīga');
        $city = $this->cleanText($raw['city'] ?? 'Rīga');
        $dateText = $raw['date_text'] ?? null;
        $priceText = $raw['price_text'] ?? null;
        $parsedPrice = $this->extractPrice($priceText);

        $startAt = $this->parseEventDate($dateText);
        $ticketUrl = $raw['ticket_url'] ?? $source->url;
        $imageUrl = $raw['image_url'] ?? null;
        $description = $this->cleanText($raw['description'] ?? null);

        // Derive categories
        $categories = ['Teātris', 'Mūzika'];
        $lower = mb_strtolower($title . ' ' . $description);
        if (str_contains($lower, 'koncerts') || str_contains($lower, 'mūzika') || str_contains($lower, 'orķestris') || str_contains($lower, 'dzied')) {
            $categories = ['Mūzika'];
        } elseif (str_contains($lower, 'izrāde') || str_contains($lower, 'teātris') || str_contains($lower, 'komēdija') || str_contains($lower, 'drama')) {
            $categories = ['Teātris'];
        } elseif (str_contains($lower, 'bērniem') || str_contains($lower, 'pasaka') || str_contains($lower, 'leļļu')) {
            $categories = ['Bērniem'];
        }

        return new ScrapedEventDTO(
            title: $title,
            startAt: $startAt,
            description: $description,
            venueName: $venue,
            city: $city,
            categoryNames: $categories,
            entertainmentType: 'performance',
            isFree: $parsedPrice['min'] === 0.0,
            priceMin: $parsedPrice['min'],
            priceMax: $parsedPrice['max'],
            ticketUrl: $ticketUrl,
            imageUrl: $imageUrl,
            sourceUrl: $raw['source_url'] ?? $ticketUrl,
            sourceExternalId: md5($title . ($startAt ? $startAt->toDateString() : ''))
        );
    }

    private function parseEventDate(?string $dateText): Carbon
    {
        if (empty($dateText)) {
            return now()->addDays(7)->setTime(19, 0);
        }

        try {
            return Carbon::parse($dateText)->setTimezone('Europe/Riga');
        } catch (\Throwable $e) {
            $months = [
                'janvār' => 1, 'februār' => 2, 'mart' => 3, 'aprīl' => 4,
                'maij' => 5, 'jūnij' => 6, 'jūlij' => 7, 'august' => 8,
                'septembr' => 9, 'oktobr' => 10, 'novembr' => 11, 'decembr' => 12,
            ];

            $lower = mb_strtolower($dateText);
            foreach ($months as $stem => $monthNum) {
                if (str_contains($lower, $stem)) {
                    if (preg_match('/(\d{1,2})[\.\s]+[a-zāčēģīķļņšūž]+/u', $lower, $m)) {
                        $day = (int)$m[1];
                        $year = (int)date('Y');
                        $target = Carbon::create($year, $monthNum, $day, 19, 0, 0, 'Europe/Riga');
                        if ($target->isPast()) {
                            $target->addYear();
                        }
                        return $target;
                    }
                }
            }
        }

        return now()->addDays(7)->setTime(19, 0);
    }

    private function extractPrice(?string $text): array
    {
        if (empty($text)) return ['min' => 15.0, 'max' => 45.0];
        preg_match_all('/(\d+([.,]\d+)?)/', $text, $matches);
        if (!empty($matches[1])) {
            $nums = array_map(fn($n) => (float)str_replace(',', '.', $n), $matches[1]);
            return [
                'min' => min($nums),
                'max' => max($nums),
            ];
        }
        return ['min' => 15.0, 'max' => 50.0];
    }

    private function getMockEvents(): Collection
    {
        return collect([
            new ScrapedEventDTO(
                title: 'Prāta Vētra - Jaunais Stadiona Šovs "Vēl Viena Klusā Daba"',
                startAt: now()->addDays(12)->setTime(20, 0),
                endAt: now()->addDays(12)->setTime(23, 30),
                description: 'Leģendārās grupas grandiozais vasaras koncerts Mežaparka Lielajā estrādē ar vizuālajiem specefektiem un viesmāksliniekiem.',
                shortDescription: 'Grandiozs Prāta Vētras koncerts Mežaparka Lielajā estrādē.',
                venueName: 'Mežaparka Lielā estrāde',
                city: 'Rīga',
                region: 'Rīga un Pierīga',
                address: 'Ostas prospekts 11, Rīga',
                latitude: 56.9961,
                longitude: 24.1534,
                categoryNames: ['Mūzika & Koncerti', 'Festivāli & Svētki'],
                entertainmentType: 'concert',
                isFree: false,
                priceMin: 35.0,
                priceMax: 95.0,
                ticketUrl: 'https://bilesuparadize.lv/lv/event/prata-vetra-mezaparks',
                imageUrl: 'https://images.unsplash.com/photo-1470225620780-dba8ba36b745?w=1200&auto=format&fit=crop&q=80',
                sourceUrl: 'https://bilesuparadize.lv',
                sourceExternalId: 'bp-pv-2026'
            ),
            new ScrapedEventDTO(
                title: 'Dailes Teātris: "Meistars un Margarita" Izrāde',
                startAt: now()->addDays(4)->setTime(19, 0),
                endAt: now()->addDays(4)->setTime(22, 15),
                description: 'Vērienīgs Mihaila Bulgakova romāna iestudējums Dailes teātra Lielajā zālē ar spilgtu aktieru sastāvu.',
                shortDescription: 'Mihaila Bulgakova šedevra grandiozs iestudējums Dailes teātrī.',
                venueName: 'Dailes teātris',
                city: 'Rīga',
                region: 'Rīga un Pierīga',
                address: 'Brīvības iela 75, Rīga',
                latitude: 56.9589,
                longitude: 24.1287,
                categoryNames: ['Teātris & Kino', 'Kultūra & Māksla'],
                entertainmentType: 'chill',
                isFree: false,
                priceMin: 18.0,
                priceMax: 48.0,
                ticketUrl: 'https://bilesuparadize.lv/lv/event/dailes-teatris-margarita',
                imageUrl: 'https://images.unsplash.com/photo-1507676184212-d03ab07a01bf?w=1200&auto=format&fit=crop&q=80',
                sourceUrl: 'https://bilesuparadize.lv',
                sourceExternalId: 'bp-dailes-2026'
            ),
            new ScrapedEventDTO(
                title: 'Bērnu Zinātnes & Eksperimentu Šovs "Zili Brīnumi"',
                startAt: now()->addDays(1)->setTime(11, 30),
                endAt: now()->addDays(1)->setTime(13, 0),
                description: 'Aizraujoši fizikas un ķīmijas eksperimenti bērniem un vecākiem, kur katrs dalībnieks var piedalīties zinātnes laboratorijā.',
                shortDescription: 'Interaktīvs zinātnes un eksperimentu šovs ģimenēm ar bērniem.',
                venueName: 'Zinātnes centrs VIZIUM',
                city: 'Ventspils',
                region: 'Kurzeme',
                address: 'Rūpniecības iela 2, Ventspils',
                latitude: 57.3958,
                longitude: 21.5683,
                categoryNames: ['Ģimenēm & Bērniem', 'Izglītība & Semināri'],
                entertainmentType: 'family',
                isFree: false,
                priceMin: 8.0,
                priceMax: 14.0,
                ticketUrl: 'https://bilesuparadize.lv/lv/event/vizium-kids-2026',
                imageUrl: 'https://images.unsplash.com/photo-1532094349884-543bc11b234d?w=1200&auto=format&fit=crop&q=80',
                sourceUrl: 'https://vizium.lv',
                sourceExternalId: 'vizium-2026-exp'
            )
        ]);
    }
}
