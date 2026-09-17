<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Location;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoAndSitemapTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_returns_valid_xml_with_events(): void
    {
        $source = Source::create([
            'name' => 'Test Source',
            'slug' => 'test-source',
            'url' => 'https://example.com',
            'scraper_class' => \App\Services\Scrapers\BezRindasScraper::class,
            'is_active' => true,
        ]);

        $location = Location::create([
            'name' => 'Arēna Rīga',
            'city' => 'Rīga',
            'region' => 'Rīga',
            'address' => 'Skanstes iela 21',
            'latitude' => 56.968,
            'longitude' => 24.120,
        ]);

        $event = Event::create([
            'source_id' => $source->id,
            'source_slug' => $source->slug,
            'location_id' => $location->id,
            'title' => 'Prāta Vētra Koncerts Rīgā',
            'slug' => 'prata-vetra-koncerts-riga',
            'description' => 'Lielais vasaras noslēguma koncerts.',
            'start_at' => now()->addDays(5),
            'status' => 'published',
            'published_at' => now()->subDay(),
            'internal_image_url' => 'https://hel1.your-objectstorage.com/sodiena/events/test.webp',
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml; charset=utf-8');
        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $response->getContent());
        $this->assertStringContainsString('<loc>' . route('events.show', $event->slug) . '</loc>', $response->getContent());
        $this->assertStringContainsString('<image:loc>' . $event->internal_image_url . '</image:loc>', $response->getContent());
    }

    public function test_event_show_page_contains_schema_org_json_ld_and_og_tags(): void
    {
        $source = Source::create([
            'name' => 'Bezrindas',
            'slug' => 'bezrindas',
            'url' => 'https://bezrindas.lv',
            'scraper_class' => \App\Services\Scrapers\BezRindasScraper::class,
            'is_active' => true,
        ]);

        $location = Location::create([
            'name' => 'Dailes teātris',
            'city' => 'Rīga',
            'region' => 'Rīga',
            'address' => 'Brīvības iela 75',
            'latitude' => 56.959,
            'longitude' => 24.128,
        ]);

        $event = Event::create([
            'source_id' => $source->id,
            'source_slug' => $source->slug,
            'location_id' => $location->id,
            'title' => 'Teātra Izrāde Spēle',
            'slug' => 'teatra-izrade-spele',
            'description' => 'Aizraujoša drāma.',
            'short_description' => 'Aizraujoša teātra izrāde.',
            'start_at' => now()->addDays(3),
            'status' => 'published',
            'published_at' => now()->subDay(),
            'price_min' => 15.00,
            'internal_image_url' => 'https://hel1.your-objectstorage.com/sodiena/events/spele.webp',
        ]);

        $response = $this->get(route('events.show', $event->slug));

        $response->assertStatus(200);
        $content = $response->getContent();

        // Check canonical & OpenGraph & Twitter
        $this->assertStringContainsString('<link rel="canonical" href="' . route('events.show', $event->slug) . '">', $content);
        $this->assertStringContainsString('<meta name="robots" content="index, follow">', $content);
        $this->assertStringContainsString('<meta property="og:type" content="article">', $content);
        $this->assertStringContainsString('<meta property="og:image" content="' . $event->internal_image_url . '">', $content);

        // Check Schema.org JSON-LD
        $this->assertStringContainsString('"@context": "https://schema.org"', $content);
        $this->assertStringContainsString('"@type": "Event"', $content);
        $this->assertStringContainsString('"name": "Teātra Izrāde Spēle"', $content);
        $this->assertStringContainsString('"addressLocality": "Rīga"', $content);
        $this->assertStringContainsString('"performer"', $content);
        $this->assertStringContainsString('"endDate"', $content);
        $this->assertStringContainsString('"@type": "BreadcrumbList"', $content);
    }

    public function test_homepage_contains_canonical_and_website_schema(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $content = $response->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="' . url('/') . '">', $content);
        $this->assertStringContainsString('"@type": "WebSite"', $content);
        $this->assertStringContainsString('"potentialAction"', $content);
    }

    public function test_event_without_description_generates_fallback_seo_description(): void
    {
        $event = Event::create([
            'title' => 'Donoru diena Aizkrauklē',
            'slug' => 'donoru-diena-aizkraukle-test',
            'description' => '',
            'short_description' => '',
            'start_at' => now()->addDays(2),
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        $this->assertNotEmpty($event->seo_description);
        $this->assertStringContainsString('Donoru diena Aizkrauklē', $event->seo_description);

        $response = $this->get(route('events.show', $event->slug));
        $response->assertStatus(200);
        $content = $response->getContent();

        $this->assertStringContainsString('"description": "Donoru diena Aizkrauklē', $content);
    }
}
