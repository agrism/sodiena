<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventAction;
use App\Models\Role;
use App\Models\ScrapeLog;
use App\Models\Source;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminStatsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['slug' => Role::ADMIN], ['name' => 'Administrators']);
        Role::firstOrCreate(['slug' => Role::REGULAR], ['name' => 'Lietotājs']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::ADMIN);

        $this->regularUser = User::factory()->create();
        $this->regularUser->assignRole(Role::REGULAR);
    }

    public function test_stats_page_requires_authentication(): void
    {
        $response = $this->get('/admin/stats');
        $response->assertRedirect('/login');
    }

    public function test_stats_page_forbidden_for_regular_users(): void
    {
        $response = $this->actingAs($this->regularUser)->get('/admin/stats');
        $response->assertForbidden();
    }

    public function test_stats_page_accessible_by_admin(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/stats');

        $response->assertOk();
        $response->assertSee('Pasākumu datubāzes');
        $response->assertSee('Klikšķu statistika');
        $response->assertViewHas([
            'days',
            'dateLabels',
            'sodienaTotalSeries',
            'sodienaTrend',
            'afiroSeries',
            'afiroTrend',
            'totalClicks',
            'domainStats',
        ]);
    }

    public function test_stats_page_calculates_sodiena_and_afiro_trends_and_ratio(): void
    {
        $afiroSource = Source::create([
            'name' => 'Afiro Pasākumu API',
            'slug' => 'afiro-api',
            'url' => 'https://api.afiro.eu',
            'scraper_class' => 'App\Services\Scrapers\Sources\AfiroApiScraper',
            'is_active' => true,
        ]);

        // Create scrape logs for Afiro over recent days
        ScrapeLog::create([
            'source_id' => $afiroSource->id,
            'status' => 'success',
            'items_found' => 2000,
            'items_created' => 10,
            'items_updated' => 100,
            'started_at' => Carbon::now()->subDays(5),
            'created_at' => Carbon::now()->subDays(5),
        ]);

        ScrapeLog::create([
            'source_id' => $afiroSource->id,
            'status' => 'success',
            'items_found' => 2500,
            'items_created' => 15,
            'items_updated' => 120,
            'started_at' => Carbon::now(),
            'created_at' => Carbon::now(),
        ]);

        // Create events in Sodiena
        for ($i = 0; $i < 5; $i++) {
            Event::create([
                'title' => "Pasākums {$i}",
                'slug' => "pasakums-{$i}",
                'start_at' => Carbon::now()->addDays($i + 1),
                'source_id' => $afiroSource->id,
                'source_slug' => 'afiro-api',
                'created_at' => Carbon::now()->subDays(4 - $i),
            ]);
        }

        $response = $this->actingAs($this->admin)->get('/admin/stats?days=30');

        $response->assertOk();
        $this->assertEquals(30, $response->viewData('days'));
        $this->assertCount(30, $response->viewData('dateLabels'));
        $this->assertCount(30, $response->viewData('sodienaTotalSeries'));
        $this->assertCount(30, $response->viewData('afiroSeries'));

        // Check trend calculation
        $sodienaTrend = $response->viewData('sodienaTrend');
        $this->assertArrayHasKey('slope', $sodienaTrend);
        $this->assertArrayHasKey('daily_growth', $sodienaTrend);
        $this->assertArrayHasKey('points', $sodienaTrend);
        $this->assertCount(30, $sodienaTrend['points']);

        $afiroTrend = $response->viewData('afiroTrend');
        $this->assertArrayHasKey('points', $afiroTrend);
        $this->assertGreaterThan(0, $response->viewData('latestAfiro'));
    }

    public function test_stats_page_aggregates_weekly_outbound_clicks_by_domain(): void
    {
        $source = Source::create([
            'name' => 'Biļešu Paradīze',
            'slug' => 'bilesu-paradize',
            'url' => 'https://www.bilesuparadize.lv',
            'scraper_class' => 'App\Services\Scrapers\Sources\BilesuParadizeScraper',
            'is_active' => true,
        ]);

        $event = Event::create([
            'title' => 'Simfoniskais koncerts',
            'slug' => 'simfoniskais-koncerts',
            'start_at' => Carbon::now()->addDays(3),
            'source_id' => $source->id,
            'source_slug' => 'bilesu-paradize',
        ]);

        // Create outbound clicks across different weeks and domains
        EventAction::create([
            'event_id' => $event->id,
            'action_type' => 'ticket',
            'url' => 'https://www.bilesuparadize.lv/lv/event/999',
            'target_domain' => 'bilesuparadize.lv',
            'device' => 'desktop',
            'created_at' => Carbon::now(),
        ]);

        EventAction::create([
            'event_id' => $event->id,
            'action_type' => 'ticket',
            'url' => 'https://www.bilesuparadize.lv/lv/event/999',
            'target_domain' => 'bilesuparadize.lv',
            'device' => 'mobile',
            'created_at' => Carbon::now()->subWeek(),
        ]);

        EventAction::create([
            'event_id' => $event->id,
            'action_type' => 'website',
            'url' => 'https://www.latvijaskoncerti.lv/info',
            'target_domain' => 'latvijaskoncerti.lv',
            'device' => 'desktop',
            'created_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($this->admin)->get('/admin/stats');

        $response->assertOk();
        $this->assertEquals(3, $response->viewData('totalClicks'));
        $this->assertEquals(2, $response->viewData('ticketClicks'));
        $this->assertEquals(1, $response->viewData('websiteClicks'));

        $domainStats = $response->viewData('domainStats');
        $this->assertArrayHasKey('bilesuparadize.lv', $domainStats);
        $this->assertArrayHasKey('latvijaskoncerti.lv', $domainStats);

        $bp = $domainStats['bilesuparadize.lv'];
        $this->assertEquals(2, $bp['total']);
        $this->assertEquals(2, $bp['ticket']);
        $this->assertEquals(0, $bp['website']);
        $this->assertEquals(1, $bp['desktop']);
        $this->assertEquals(1, $bp['mobile']);

        $response->assertSee('bilesuparadize.lv');
        $response->assertSee('latvijaskoncerti.lv');
    }

    public function test_stats_page_handles_custom_time_range(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/stats?days=14');

        $response->assertOk();
        $this->assertEquals(14, $response->viewData('days'));
        $this->assertCount(14, $response->viewData('dateLabels'));
        $this->assertCount(14, $response->viewData('sodienaTotalSeries'));
    }
}
