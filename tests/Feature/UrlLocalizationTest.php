<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Location;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UrlLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_homepage_is_latvian_and_has_canonical_and_hreflang_tags(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $content = $response->getContent();
        $base = url('/');

        $this->assertStringContainsString('<html lang="lv"', $content);
        $this->assertStringContainsString('<link rel="canonical" href="' . $base . '">', $content);
        $this->assertStringContainsString('<link rel="alternate" hreflang="lv" href="' . $base . '">', $content);
        $this->assertStringContainsString('<link rel="alternate" hreflang="en" href="' . $base . '/en">', $content);
        $this->assertStringContainsString('<link rel="alternate" hreflang="ru" href="' . $base . '/ru">', $content);
        $this->assertStringContainsString('<link rel="alternate" hreflang="x-default" href="' . $base . '">', $content);
    }

    public function test_english_homepage_sets_locale_and_canonical(): void
    {
        $response = $this->get('/en');

        $response->assertStatus(200);
        $content = $response->getContent();
        $base = url('/');

        $this->assertStringContainsString('<html lang="en"', $content);
        $this->assertStringContainsString('<link rel="canonical" href="' . $base . '/en">', $content);
        $this->assertStringContainsString('<link rel="alternate" hreflang="lv" href="' . $base . '">', $content);
        $this->assertStringContainsString('<link rel="alternate" hreflang="en" href="' . $base . '/en">', $content);
        $this->assertStringContainsString('<link rel="alternate" hreflang="ru" href="' . $base . '/ru">', $content);
    }

    public function test_russian_homepage_sets_locale_and_canonical(): void
    {
        $response = $this->get('/ru');

        $response->assertStatus(200);
        $content = $response->getContent();
        $base = url('/');

        $this->assertStringContainsString('<html lang="ru"', $content);
        $this->assertStringContainsString('<link rel="canonical" href="' . $base . '/ru">', $content);
    }

    public function test_lv_prefix_redirects_301_to_canonical_root(): void
    {
        $base = url('/');

        $response = $this->get('/lv');
        $response->assertStatus(301);
        $response->assertRedirect($base);

        $responseSub = $this->get('/lv/events/rock-fest');
        $responseSub->assertStatus(301);
        $responseSub->assertRedirect($base . '/events/rock-fest');
    }

    public function test_localized_event_show_page_and_canonical(): void
    {
        $source = Source::create([
            'name' => 'Source',
            'slug' => 'source',
            'url' => 'https://example.com',
            'scraper_class' => \App\Services\Scrapers\BezRindasScraper::class,
            'is_active' => true,
        ]);

        $location = Location::create([
            'name' => 'Rīgas Kongresu nams',
            'city' => 'Rīga',
            'region' => 'Rīga',
            'address' => 'Kr. Valdemāra iela 5',
        ]);

        $event = Event::create([
            'source_id' => $source->id,
            'source_slug' => $source->slug,
            'location_id' => $location->id,
            'title' => 'Džeza Vakars',
            'slug' => 'dzeza-vakars',
            'description' => 'Džeza mūzikas festivāls.',
            'start_at' => now()->addDays(2),
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        $base = url('/');

        // 1. LV event page
        $resLv = $this->get('/events/' . $event->slug);
        $resLv->assertStatus(200);
        $this->assertStringContainsString('<html lang="lv"', $resLv->getContent());
        $this->assertStringContainsString('<link rel="canonical" href="' . $base . '/events/' . $event->slug . '">', $resLv->getContent());
        $this->assertStringContainsString('<link rel="alternate" hreflang="en" href="' . $base . '/en/events/' . $event->slug . '">', $resLv->getContent());

        // 2. EN event page
        $resEn = $this->get('/en/events/' . $event->slug);
        $resEn->assertStatus(200);
        $this->assertStringContainsString('<html lang="en"', $resEn->getContent());
        $this->assertStringContainsString('<link rel="canonical" href="' . $base . '/en/events/' . $event->slug . '">', $resEn->getContent());
        $this->assertStringContainsString('<link rel="alternate" hreflang="lv" href="' . $base . '/events/' . $event->slug . '">', $resEn->getContent());
        $this->assertStringContainsString('<link rel="alternate" hreflang="ru" href="' . $base . '/ru/events/' . $event->slug . '">', $resEn->getContent());

        // 3. RU event page
        $resRu = $this->get('/ru/events/' . $event->slug);
        $resRu->assertStatus(200);
        $this->assertStringContainsString('<html lang="ru"', $resRu->getContent());
        $this->assertStringContainsString('<link rel="canonical" href="' . $base . '/ru/events/' . $event->slug . '">', $resRu->getContent());
    }

    public function test_sitemap_contains_hreflang_tags_for_all_locales(): void
    {
        $source = Source::create([
            'name' => 'Source',
            'slug' => 'source-sitemap',
            'url' => 'https://example.com',
            'scraper_class' => \App\Services\Scrapers\BezRindasScraper::class,
            'is_active' => true,
        ]);

        $event = Event::create([
            'source_id' => $source->id,
            'source_slug' => $source->slug,
            'title' => 'Sitemap Test Event',
            'slug' => 'sitemap-test-event',
            'description' => 'Apraksts',
            'start_at' => now()->addDays(4),
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        $base = rtrim(config('app.url', 'https://sodiena.lv'), '/');

        $response = $this->get('/sitemap.xml');
        $response->assertStatus(200);
        $content = $response->getContent();

        $this->assertStringContainsString('<xhtml:link rel="alternate" hreflang="lv" href="' . $base . '"/>', $content);
        $this->assertStringContainsString('<xhtml:link rel="alternate" hreflang="en" href="' . $base . '/en"/>', $content);
        $this->assertStringContainsString('<xhtml:link rel="alternate" hreflang="ru" href="' . $base . '/ru"/>', $content);
        $this->assertStringContainsString('<xhtml:link rel="alternate" hreflang="en" href="' . $base . '/en/events/' . $event->slug . '"/>', $content);
    }
}
