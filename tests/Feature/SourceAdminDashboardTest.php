<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Role;
use App\Models\ScrapeLog;
use App\Models\Source;
use App\Models\User;
use App\Services\Scrapers\Contracts\EventScraperInterface;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class FeatureCrashingScraperStub implements EventScraperInterface
{
    public function getSlug(): string
    {
        return 'feature-crashing-stub';
    }

    public function getName(): string
    {
        return 'Feature Crashing Stub';
    }

    public function scrape(Source $source): Collection
    {
        throw new \RuntimeException('Simulated catastrophic fatal crash during scraping!');
    }
}

class FeatureSuccessfulScraperStub implements EventScraperInterface
{
    public function getSlug(): string
    {
        return 'feature-successful-stub';
    }

    public function getName(): string
    {
        return 'Feature Successful Stub';
    }

    public function scrape(Source $source): Collection
    {
        return collect([
            new ScrapedEventDTO(
                title: 'Successful Event From Stub',
                startAt: Carbon::now('Europe/Riga')->addDays(2),
                endAt: Carbon::now('Europe/Riga')->addDays(2)->addHours(2),
                description: 'Description for successful event',
                shortDescription: 'Short description',
                venueName: 'Test Hall',
                city: 'Rīga',
                region: 'Rīga un Pierīga',
                address: 'Brīvības iela 1',
                latitude: 56.95,
                longitude: 24.11,
                placeType: 'venue',
                categoryNames: ['Sports'],
                entertainmentType: 'sports',
                isFree: true,
                priceMin: null,
                priceMax: null,
                imageUrl: 'https://example.com/image.jpg',
                sourceUrl: 'https://example.com/event',
                sourceExternalId: 'stub_feat_1',
                locale: 'lv'
            )
        ]);
    }
}

class SourceAdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Category::updateOrCreate(['slug' => 'sports'], ['name' => 'Sports']);

        Role::firstOrCreate(['slug' => Role::ADMIN], ['name' => 'Administrators']);
        Role::firstOrCreate(['slug' => Role::REGULAR], ['name' => 'Lietotājs']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::ADMIN);
    }

    public function test_legacy_sources_url_redirects_to_admin_sources(): void
    {
        $response = $this->actingAs($this->admin)->get('/sources');
        $response->assertRedirect(route('admin.sources.index'));
    }

    public function test_sources_dashboard_displays_sources_and_daily_logs(): void
    {
        $source = Source::create([
            'name' => 'Test Source Portal',
            'slug' => 'test-source-portal',
            'url' => 'https://portal.example.com',
            'scraper_class' => FeatureSuccessfulScraperStub::class,
            'is_active' => true,
        ]);

        $today = Carbon::now('Europe/Riga');
        $yesterday = Carbon::now('Europe/Riga')->subDay();

        ScrapeLog::create([
            'source_id' => $source->id,
            'status' => 'success',
            'items_found' => 25,
            'items_created' => 5,
            'items_updated' => 20,
            'duration_seconds' => 14.2,
            'started_at' => $today->copy()->setTime(10, 0, 0),
            'completed_at' => $today->copy()->setTime(10, 0, 14),
        ]);

        ScrapeLog::create([
            'source_id' => $source->id,
            'status' => 'failed',
            'items_found' => 0,
            'items_created' => 0,
            'items_updated' => 0,
            'duration_seconds' => 3.1,
            'errors' => ['Connection timed out to 65.21.182.7 after 30s'],
            'started_at' => $yesterday->copy()->setTime(9, 30, 0),
            'completed_at' => $yesterday->copy()->setTime(9, 30, 3),
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.sources.index'));

        $response->assertOk();
        $response->assertSee('Administrācijas sektors');
        $response->assertSee('Parsēšanas Avoti');
        $response->assertSee('Test Source Portal');
        $response->assertSee('Parsēšanas vēsture un žurnāls pa dienām');
        $response->assertSee('25'); // items found today
        $response->assertSee('Veiksmīgi');
    }

    public function test_sources_dashboard_filters_by_specific_date(): void
    {
        $source = Source::create([
            'name' => 'Date Filter Source',
            'slug' => 'date-filter-source',
            'url' => 'https://date.example.com',
            'scraper_class' => FeatureSuccessfulScraperStub::class,
            'is_active' => true,
        ]);

        $targetDate = Carbon::parse('2026-11-10 12:00:00', 'Europe/Riga');

        ScrapeLog::create([
            'source_id' => $source->id,
            'status' => 'failed',
            'items_found' => 12,
            'items_created' => 0,
            'items_updated' => 0,
            'duration_seconds' => 5.5,
            'errors' => ['SSL certificate error on date filter'],
            'started_at' => $targetDate,
            'completed_at' => $targetDate->copy()->addSeconds(5),
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.sources.index', ['log_date' => '2026-11-10']));

        $response->assertOk();
        $response->assertSee('SSL certificate error on date filter');
        $response->assertSee('10.11.2026');
    }

    public function test_sources_dashboard_filters_by_status(): void
    {
        $source = Source::create([
            'name' => 'Status Filter Source',
            'slug' => 'status-filter-source',
            'url' => 'https://status.example.com',
            'scraper_class' => FeatureSuccessfulScraperStub::class,
            'is_active' => true,
        ]);

        $today = Carbon::now('Europe/Riga');

        ScrapeLog::create([
            'source_id' => $source->id,
            'status' => 'success',
            'items_found' => 10,
            'items_created' => 2,
            'items_updated' => 8,
            'duration_seconds' => 4.0,
            'started_at' => $today->copy()->setTime(11, 0, 0),
            'completed_at' => $today->copy()->setTime(11, 0, 4),
        ]);

        ScrapeLog::create([
            'source_id' => $source->id,
            'status' => 'failed',
            'items_found' => 0,
            'items_created' => 0,
            'items_updated' => 0,
            'duration_seconds' => 1.5,
            'errors' => ['Specific 403 Forbidden Cloudflare block'],
            'started_at' => $today->copy()->setTime(12, 0, 0),
            'completed_at' => $today->copy()->setTime(12, 0, 1),
        ]);

        $successOnlyResponse = $this->actingAs($this->admin)
            ->get(route('admin.sources.index', ['log_date' => $today->toDateString(), 'status' => 'success']));
        $successOnlyResponse->assertOk();
        $successOnlyResponse->assertDontSee('Specific 403 Forbidden Cloudflare block');

        $failedOnlyResponse = $this->actingAs($this->admin)
            ->get(route('admin.sources.index', ['log_date' => $today->toDateString(), 'status' => 'failed']));
        $failedOnlyResponse->assertOk();
        $failedOnlyResponse->assertSee('Specific 403 Forbidden Cloudflare block');
    }

    public function test_trigger_scrape_executes_scraper_and_logs_results(): void
    {
        $source = Source::create([
            'name' => 'Triggerable Source',
            'slug' => 'triggerable-source',
            'url' => 'https://triggerable.example.com',
            'scraper_class' => FeatureSuccessfulScraperStub::class,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.sources.scrape', $source->id));

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $log = ScrapeLog::where('source_id', $source->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEquals('success', $log->status);
        $this->assertEquals(1, $log->items_found);
        $this->assertEquals(1, $log->items_created);
        $this->assertGreaterThan(0, $log->duration_seconds);

        // Verify sitemap was refreshed and cached with the new event
        $sitemapXml = \Illuminate\Support\Facades\Cache::get(\App\Services\SitemapService::CACHE_KEY);
        $this->assertNotNull($sitemapXml);
        $this->assertStringContainsString('events/successful-event-from-stub', $sitemapXml);
    }

    public function test_trigger_scrape_handles_failure_cleanly(): void
    {
        $source = Source::create([
            'name' => 'Failing Source',
            'slug' => 'failing-source',
            'url' => 'https://failing.example.com',
            'scraper_class' => FeatureCrashingScraperStub::class,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.sources.scrape', $source->id));

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $log = ScrapeLog::where('source_id', $source->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEquals('failed', $log->status);
        $this->assertStringContainsString('Simulated catastrophic fatal crash', $log->getPrimaryError());
    }

    public function test_admin_can_trigger_sync_translations(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.tools.sync-translations'));

        $response->assertRedirect();
        $response->assertSessionHas('status');
    }

    public function test_admin_can_trigger_sync_images(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.tools.sync-images'));

        $response->assertRedirect();
        $response->assertSessionHas('status');
    }

    public function test_admin_can_trigger_refresh_sitemap(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.tools.refresh-sitemap'));

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $this->assertTrue(\Illuminate\Support\Facades\Cache::has(\App\Services\SitemapService::CACHE_KEY));
    }

    public function test_admin_can_sync_sources_registry(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.sources.sync-registry'));

        $response->assertRedirect(route('admin.sources.index'));
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('sources', [
            'slug' => 'volejbols-lv',
        ]);
    }

    public function test_htmx_can_fetch_scrape_history_partial(): void
    {
        $response = $this->actingAs($this->admin)
            ->withHeaders([
                'HX-Request' => 'true',
                'HX-Target' => 'scrape-history-section',
            ])
            ->get(route('admin.sources.index', ['status' => 'success']));

        $response->assertOk();
        $response->assertSee('id="scrape-history-section"', false);
        $response->assertSee('Parsēšanas vēsture un žurnāls pa dienām');
        $response->assertDontSee('<!DOCTYPE html>', false);
        $response->assertDontSee('Reģistrētie roboti un avoti');
    }

    public function test_htmx_scrape_from_history_targets_history_section(): void
    {
        $source = Source::create([
            'name' => 'History Target Source',
            'slug' => 'history-target-source',
            'url' => 'https://history-target.example.com',
            'scraper_class' => FeatureSuccessfulScraperStub::class,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->withHeaders([
                'HX-Request' => 'true',
                'HX-Target' => 'scrape-history-section',
            ])
            ->post(route('admin.sources.scrape', $source->id));

        $response->assertOk();
        $response->assertSee('id="scrape-history-section"', false);
        $response->assertSee('History Target Source');
        $response->assertDontSee('<!DOCTYPE html>', false);
    }

    public function test_htmx_can_filter_sources_by_category(): void
    {
        Source::create([
            'name' => 'Sports HTMX Club',
            'slug' => 'sports-htmx-club',
            'url' => 'https://sports.example.com',
            'scraper_class' => FeatureSuccessfulScraperStub::class,
            'type' => \App\Enums\SourceType::SPORTS->value,
            'is_active' => true,
        ]);

        Source::create([
            'name' => 'Theatre HTMX Hall',
            'slug' => 'theatre-htmx-hall',
            'url' => 'https://theatre.example.com',
            'scraper_class' => FeatureSuccessfulScraperStub::class,
            'type' => \App\Enums\SourceType::VENUE->value,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->withHeaders([
                'HX-Request' => 'true',
                'HX-Target' => 'sources-registry-section',
            ])
            ->get(route('admin.sources.index', ['type' => 'sports']));

        $response->assertOk();
        $response->assertSee('id="sources-registry-section"', false);
        $response->assertSee('Sports HTMX Club');
        $response->assertDontSee('Theatre HTMX Hall');
        $response->assertDontSee('<!DOCTYPE html>', false);
    }

    public function test_htmx_can_sync_sources_registry(): void
    {
        $response = $this->actingAs($this->admin)
            ->withHeaders([
                'HX-Request' => 'true',
                'HX-Target' => 'sources-registry-section',
            ])
            ->post(route('admin.sources.sync-registry'));

        $response->assertOk();
        $response->assertSee('id="sources-registry-section"', false);
        $response->assertSee('Avotu reģistrs veiksmīgi sinhronizēts');
        $response->assertDontSee('<!DOCTYPE html>', false);
    }

    public function test_htmx_can_trigger_sync_translations(): void
    {
        $response = $this->actingAs($this->admin)
            ->withHeaders([
                'HX-Request' => 'true',
                'HX-Target' => 'system-status-container',
            ])
            ->post(route('admin.tools.sync-translations'));

        $response->assertOk();
        $response->assertSee('id="system-status-container"', false);
        $response->assertSee('Tulkojumu sinhronizācija veiksmīgi pabeigta');
        $response->assertDontSee('<!DOCTYPE html>', false);
    }

    public function test_htmx_can_trigger_sync_images(): void
    {
        $response = $this->actingAs($this->admin)
            ->withHeaders([
                'HX-Request' => 'true',
                'HX-Target' => 'system-status-container',
            ])
            ->post(route('admin.tools.sync-images'));

        $response->assertOk();
        $response->assertSee('id="system-status-container"', false);
        $response->assertSee('Attēlu sinhronizācija uz Hetzner S3 veiksmīgi pabeigta');
        $response->assertDontSee('<!DOCTYPE html>', false);
    }

    public function test_htmx_can_trigger_refresh_sitemap(): void
    {
        $response = $this->actingAs($this->admin)
            ->withHeaders([
                'HX-Request' => 'true',
                'HX-Target' => 'system-status-container',
            ])
            ->post(route('admin.tools.refresh-sitemap'));

        $response->assertOk();
        $response->assertSee('id="system-status-container"', false);
        $response->assertSee('Sitemap.xml veiksmīgi uzģenerēta');
        $response->assertDontSee('<!DOCTYPE html>', false);
    }
}
