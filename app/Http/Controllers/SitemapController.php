<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    /**
     * Generate dynamic XML sitemap with all active events and static pages.
     */
    public function index(): Response
    {
        $xml = Cache::remember('sitemap_xml_v1', 3600, function () {
            $events = Event::query()
                ->withoutTrashed()
                ->published()
                ->select(['id', 'slug', 'title', 'internal_image_url', 'image_url', 'updated_at', 'start_at'])
                ->orderByDesc('updated_at')
                ->get();

            $baseUrl = config('app.url', 'https://sodiena.lv');

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

                $lines[] = '  <url>';
                $lines[] = '    <loc>' . htmlspecialchars($loc, ENT_XML1, 'UTF-8') . '</loc>';
                $lines[] = '    <lastmod>' . $lastmod . '</lastmod>';
                $lines[] = '    <changefreq>daily</changefreq>';
                $lines[] = '    <priority>0.8</priority>';

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
        });

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'X-Robots-Tag' => 'noindex, follow',
        ]);
    }
}
