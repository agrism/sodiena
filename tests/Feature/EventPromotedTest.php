<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Event;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventPromotedTest extends TestCase
{
    use RefreshDatabase;

    protected string $apiToken = 'test_secret_bearer_token';

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['slug' => Role::ADMIN], ['name' => 'Administrators']);
        Role::firstOrCreate(['slug' => Role::REGULAR], ['name' => 'Lietotājs']);
        \Illuminate\Support\Facades\Config::set('api.bearer_token', $this->apiToken);
    }

    public function test_event_model_promote_helpers(): void
    {
        $event = Event::create([
            'title' => 'Parasts pasākums',
            'slug' => 'parasts-pasakums',
            'start_at' => now()->addDays(5),
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->assertFalse($event->isPromoted());
        $this->assertFalse($event->is_promoted);

        $event->promote();
        $this->assertTrue($event->fresh()->isPromoted());
        $this->assertTrue($event->fresh()->is_promoted);

        $event->unpromote();
        $this->assertFalse($event->fresh()->isPromoted());
        $this->assertFalse($event->fresh()->is_promoted);
    }

    public function test_promoted_events_appear_at_the_top_in_upcoming_scope_and_homepage(): void
    {
        $today = now()->startOfDay()->addHours(12);

        // Event 1: Today, but NOT promoted
        $eventToday = Event::create([
            'title' => 'Šodienas parastais notikums',
            'slug' => 'sodienas-notikums',
            'start_at' => $today,
            'status' => 'published',
            'published_at' => now(),
            'is_promoted' => false,
        ]);

        // Event 2: Next week, but PROMOTED (should appear BEFORE today's event!)
        $eventNextWeekPromoted = Event::create([
            'title' => 'Nākamās nedēļas izceltais koncerts',
            'slug' => 'nakamas-nedelas-izceltais',
            'start_at' => $today->copy()->addDays(7),
            'status' => 'published',
            'published_at' => now(),
            'is_promoted' => true,
        ]);

        // 1. In Eloquent scope Upcoming, promoted event must be first
        $upcoming = Event::upcoming()->get();
        $this->assertEquals($eventNextWeekPromoted->id, $upcoming->first()->id, 'Promoted event must be at the top');
        $this->assertEquals($eventToday->id, $upcoming->last()->id);

        // 2. On homepage, promoted event must be at the top and have the Izcelts badge
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('Izcelts');
        $response->assertSee('border-amber-300 ring-2 ring-amber-400/50', false);

        $content = $response->getContent();
        $posPromoted = strpos($content, 'Nākamās nedēļas izceltais koncerts');
        $posToday = strpos($content, 'Šodienas parastais notikums');
        $this->assertTrue($posPromoted < $posToday, 'Promoted event should be rendered before regular earlier event');
    }

    public function test_admin_can_toggle_promoted_status(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::ADMIN);

        $regularUser = User::factory()->create();
        $regularUser->assignRole(Role::REGULAR);

        $event = Event::create([
            'title' => 'Izstāde galerijā',
            'slug' => 'izstade-galerija',
            'start_at' => now()->addDays(2),
            'status' => 'published',
            'published_at' => now(),
            'is_promoted' => false,
        ]);

        // Guest cannot toggle promoted
        $this->post("/admin/events/{$event->id}/toggle-promoted")
            ->assertRedirect('/login');

        // Regular user forbidden
        $this->actingAs($regularUser)
            ->post("/admin/events/{$event->id}/toggle-promoted")
            ->assertStatus(403);

        // Admin can toggle promoted via web form redirect
        $this->actingAs($admin)
            ->post("/admin/events/{$event->id}/toggle-promoted")
            ->assertRedirect();

        $this->assertTrue($event->fresh()->is_promoted);

        // Admin can toggle promoted back via AJAX/JSON
        $response = $this->actingAs($admin)
            ->postJson("/admin/events/{$event->id}/toggle-promoted");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'id' => $event->id,
                'is_promoted' => false,
            ]);

        $this->assertFalse($event->fresh()->is_promoted);
    }

    public function test_admin_can_update_promoted_via_category_form(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::ADMIN);

        $category = Category::create([
            'name' => 'Mūzika',
            'slug' => 'muzika',
        ]);

        $event = Event::create([
            'title' => 'Festivāls',
            'slug' => 'festivals',
            'start_at' => now()->addDays(3),
            'status' => 'published',
            'published_at' => now(),
            'is_promoted' => false,
        ]);

        $this->actingAs($admin)
            ->post("/admin/events/{$event->id}/category", [
                'category_ids' => [$category->id],
                'entertainment_type' => 'concert',
                'is_promoted' => '1',
            ])
            ->assertRedirect();

        $this->assertTrue($event->fresh()->is_promoted);
        $this->assertEquals('concert', $event->fresh()->entertainment_type);
    }

    public function test_event_show_page_displays_promoted_badge_and_admin_controls(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::ADMIN);

        $event = Event::create([
            'title' => 'VIP Galā Koncerts',
            'slug' => 'vip-gala-koncerts',
            'start_at' => now()->addDays(4),
            'status' => 'published',
            'published_at' => now(),
            'is_promoted' => true,
        ]);

        // Public visitor sees "Izcelts pasākums" badge
        $this->get('/events/' . $event->slug)
            ->assertStatus(200)
            ->assertSee('Izcelts pasākums')
            ->assertDontSee('Admin vadība');

        // Admin sees "Admin vadība" and "Noņemt izcelšanu (Promoted)" button
        $this->actingAs($admin)
            ->get('/events/' . $event->slug)
            ->assertStatus(200)
            ->assertSee('Admin vadība')
            ->assertSee('Izcelts')
            ->assertSee('Noņemt izcelšanu (Promoted)')
            ->assertSee('Izcelt augšpusē (is_promoted)');
    }

    public function test_api_returns_is_promoted_and_prioritizes_promoted_events(): void
    {
        $eventRegular = Event::create([
            'title' => 'Parasts API pasākums',
            'slug' => 'parasts-api-pasakums',
            'start_at' => now()->addDays(1),
            'status' => 'published',
            'published_at' => now(),
            'is_promoted' => false,
        ]);

        $eventPromoted = Event::create([
            'title' => 'Izcelts API pasākums',
            'slug' => 'izcelts-api-pasakums',
            'start_at' => now()->addDays(10),
            'status' => 'published',
            'published_at' => now(),
            'is_promoted' => true,
        ]);

        // 1. API single event returns is_promoted
        $resSingle = $this->withToken($this->apiToken)->getJson("/api/v1/events/{$eventPromoted->id}");
        $resSingle->assertStatus(200)
            ->assertJsonPath('data.is_promoted', true);

        $resRegular = $this->withToken($this->apiToken)->getJson("/api/v1/events/{$eventRegular->id}");
        $resRegular->assertStatus(200)
            ->assertJsonPath('data.is_promoted', false);

        // 2. API events index returns promoted event at top
        $resList = $this->withToken($this->apiToken)->getJson('/api/v1/events');
        $resList->assertStatus(200)
            ->assertJsonPath('data.0.id', $eventPromoted->id)
            ->assertJsonPath('data.0.is_promoted', true);

        // 3. API filter by is_promoted=true
        $resFiltered = $this->withToken($this->apiToken)->getJson('/api/v1/events?is_promoted=true');
        $resFiltered->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $eventPromoted->id);

        // 4. API filter by is_promoted=false
        $resNotPromoted = $this->withToken($this->apiToken)->getJson('/api/v1/events?is_promoted=false');
        $resNotPromoted->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $eventRegular->id);
    }
}
