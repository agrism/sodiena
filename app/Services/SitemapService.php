<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Facades\Cache;

class SitemapService
{
    public const CACHE_KEY = 'sitemap_xml_v2';
    public const CACHE_TTL = 3600; // 1 hour

    /**
     * Get sitemap XML (from cache or generated on-the-fly).
     */
    public function getSitemapXml(): string
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return $this->generate();
        });
    }

    /**
     * Clear cache and immediately regenerate and cache fresh XML sitemap.
     */
    public function refresh(): string
    {
        Cache::forget(self::CACHE_KEY);
        $xml = $this->generate();
        Cache::put(self::CACHE_KEY, $xml, self::CACHE_TTL);

        return $xml;
    }

    /**
     * Generate fresh sitemap XML content.
     */
    public function generate(): string
    {
        $events = Event::query()
            ->withoutTrashed()
            ->published()
            ->select(['id', 'slug', 'title', 'internal_image_url', 'image_url', 'updated_at', 'start_at'])
            ->orderByDesc('start_at')
            ->get();

        $baseUrl = config('app.url', 'https://sodiena.lv');
        $today = now()->startOfDay();

        $lines = [];
        $lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $lines[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';

        // Homepage
        $lines[] = '  <url>';
        $lines[] = '    <loc>' . htmlspecialchars($baseUrl, ENT_XML1, 'UTF-8') . '</loc>';
        $lines[] = '    <lastmod>' . now()->toAtomString() . '</lastmod>';
        $lines[] = '    <changefreq>hourly</changefreq>';
        $lines[] = '    <priority>1.0</priority>';
        $lines[] = '  </url>';

        // Events
        foreach ($events as $event) {
            $loc = route('events.show', $event->slug);
            $lastmod = ($event->updated_at ?? now())->toAtomString();
            $imageUrl = $event->display_image_url;

            $isUpcoming = $event->start_at ? $event->start_at->gte($today) : true;
            $changefreq = $isUpcoming ? 'daily' : 'monthly';
            $priority = $isUpcoming ? '0.9' : '0.4';

            $lines[] = '  <url>';
            $lines[] = '    <loc>' . htmlspecialchars($loc, ENT_XML1, 'UTF-8') . '</loc>';
            $lines[] = '    <lastmod>' . $lastmod . '</lastmod>';
            $lines[] = '    <changefreq>' . $changefreq . '</changefreq>';
            $lines[] = '    <priority>' . $priority . '</priority>';

            if (!empty($imageUrl) && !str_contains($imageUrl, 'default-event.jpg')) {
                $lines[] = '    <image:image>';
                $lines[] = '      <image:loc>' . htmlspecialchars($imageUrl, ENT_XML1, 'UTF-8') . '</image:loc>';
                $lines[] = '      <image:title>' . htmlspecialchars($event->title, ENT_XML1, 'UTF-8') . '</image:title>';
                $lines[] = '    </image:image>';
            }

            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines);
    }
}
