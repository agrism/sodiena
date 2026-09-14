<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthAndRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['slug' => Role::ADMIN], ['name' => 'Administrators']);
        Role::firstOrCreate(['slug' => Role::REGULAR], ['name' => 'Lietotājs']);
    }

    public function test_user_can_register_and_receives_regular_role(): void
    {
        $response = $this->post('/register', [
            'name' => 'Jānis Bērziņš',
            'email' => 'janis@example.com',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ]);

        $response->assertRedirect(route('events.index'));
        $this->assertAuthenticated();

        $user = User::where('email', 'janis@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->isRegular());
        $this->assertFalse($user->isAdmin());
    }

    public function test_user_can_login_and_logout(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);
        $user->assignRole(Role::REGULAR);

        // Login
        $response = $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('events.index'));
        $this->assertAuthenticatedAs($user);

        // Logout
        $logoutResponse = $this->post('/logout');
        $logoutResponse->assertRedirect(route('events.index'));
        $this->assertGuest();
    }

    public function test_guest_and_regular_user_cannot_access_admin_users_registry(): void
    {
        // Guest redirected to login
        $this->get('/admin/users')->assertRedirect(route('login'));

        // Regular user receives 403 Forbidden
        $regularUser = User::create([
            'name' => 'Regular User',
            'email' => 'regular@example.com',
            'password' => Hash::make('password123'),
        ]);
        $regularUser->assignRole(Role::REGULAR);

        $this->actingAs($regularUser)->get('/admin/users')->assertStatus(403);
    }

    public function test_admin_can_access_users_registry_and_change_roles(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
        ]);
        $admin->assignRole(Role::ADMIN);

        $targetUser = User::create([
            'name' => 'Target User',
            'email' => 'target@example.com',
            'password' => Hash::make('password123'),
        ]);
        $targetUser->assignRole(Role::REGULAR);

        // Admin views users registry
        $response = $this->actingAs($admin)->get('/admin/users');
        $response->assertStatus(200);
        $response->assertSee('Target User');

        // Admin promotes target user to admin
        $roleUpdateResponse = $this->actingAs($admin)->post("/admin/users/{$targetUser->id}/role", [
            'role' => Role::ADMIN,
        ]);
        $roleUpdateResponse->assertRedirect(route('admin.users.index'));

        $this->assertTrue($targetUser->fresh()->isAdmin());

        // Admin creates a new user
        $createResponse = $this->actingAs($admin)->post('/admin/users', [
            'name' => 'New Staff',
            'email' => 'staff@example.com',
            'password' => 'password123',
            'role' => Role::ADMIN,
        ]);
        $createResponse->assertRedirect(route('admin.users.index'));
        $newUser = User::where('email', 'staff@example.com')->first();
        $this->assertNotNull($newUser);
        $this->assertTrue($newUser->isAdmin());

        // Admin deletes target user
        $deleteResponse = $this->actingAs($admin)->delete("/admin/users/{$targetUser->id}");
        $deleteResponse->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseMissing('users', ['id' => $targetUser->id]);
    }

    public function test_admin_cannot_demote_or_delete_themselves(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
        ]);
        $admin->assignRole(Role::ADMIN);

        // Attempt self demotion
        $demoteResponse = $this->actingAs($admin)->post("/admin/users/{$admin->id}/role", [
            'role' => Role::REGULAR,
        ]);
        $demoteResponse->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->isAdmin());

        // Attempt self deletion
        $deleteResponse = $this->actingAs($admin)->delete("/admin/users/{$admin->id}");
        $deleteResponse->assertSessionHas('error');
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_artisan_make_admin_command(): void
    {
        $user = User::create([
            'name' => 'Cli User',
            'email' => 'cli@example.com',
            'password' => Hash::make('password123'),
        ]);
        $user->assignRole(Role::REGULAR);

        $this->assertFalse($user->isAdmin());

        $this->artisan('app:make-admin cli@example.com')
            ->expectsOutputToContain('has been granted [admin] role successfully.')
            ->assertExitCode(0);

        $this->assertTrue($user->fresh()->isAdmin());
    }

    public function test_admin_can_access_events_grid_and_filter(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin_events@example.com',
            'password' => Hash::make('password123'),
        ]);
        $admin->assignRole(Role::ADMIN);

        $testEvent = \App\Models\Event::create([
            'title' => 'Grid Test Event 101',
            'slug' => 'grid-test-event-101',
            'start_at' => now()->addDays(3),
            'fingerprint' => 'grid-fp-101',
            'status' => 'published',
            'source_slug' => 'afiro-api',
            'source_url' => 'https://www.liveriga.com/lv/apmekle/pasakumi/izstade-test-101',
        ]);

        $this->assertEquals('liveriga.com', $testEvent->origin_host);
        $this->assertEquals('https://www.liveriga.com/lv/apmekle/pasakumi/izstade-test-101', $testEvent->origin_url);

        // Regular user blocked
        $regular = User::create([
            'name' => 'Reg User',
            'email' => 'reg_user@example.com',
            'password' => Hash::make('password123'),
        ]);
        $regular->assignRole(Role::REGULAR);

        $this->actingAs($regular)->get('/admin/events')->assertStatus(403);

        // Admin can view, see origin website, and search by domain
        $response = $this->actingAs($admin)->get('/admin/events?search=liveriga');
        $response->assertStatus(200);
        $response->assertSee('Grid Test Event 101');
        $response->assertSee('Īstā vietne (Avots)');
        $response->assertSee('liveriga.com');
        $response->assertSee('https://www.liveriga.com/lv/apmekle/pasakumi/izstade-test-101');

        // Admin can filter by origin_host dropdown parameter
        $filterResponse = $this->actingAs($admin)->get('/admin/events?origin_host=liveriga.com');
        $filterResponse->assertStatus(200);
        $filterResponse->assertSee('Grid Test Event 101');
        $filterResponse->assertSee('liveriga.com');

        // Admin can filter for events missing origin website
        \App\Models\Event::create([
            'title' => 'Missing Origin Event 999',
            'slug' => 'missing-origin-event-999',
            'start_at' => now()->addDays(2),
            'fingerprint' => 'missing-origin-fp-999',
            'status' => 'published',
            'source_slug' => 'test-source',
            'source_url' => null,
            'ticket_url' => null,
        ]);

        // Admin can filter by location_id
        $loc = \App\Models\Location::create([
            'name' => 'Lielā Ģilde',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
        ]);
        $testEvent->update(['location_id' => $loc->id]);

        $locResponse = $this->actingAs($admin)->get("/admin/events?location_id={$loc->id}");
        $locResponse->assertStatus(200);
        $locResponse->assertSee('Grid Test Event 101');
        $locResponse->assertSee('Lielā Ģilde');
        $locResponse->assertSee('Vieta');
        $locResponse->assertDontSee('Missing Origin Event 999');

        // Admin can sort by location
        $sortResponse = $this->actingAs($admin)->get('/admin/events?sort_by=location&sort_dir=asc');
        $sortResponse->assertStatus(200);
        $sortResponse->assertSee('Vieta & Pilsēta', false);
        $sortResponse->assertSee('Grid Test Event 101');
    }

    public function test_admin_pages_render_left_and_right_sidebars(): void
    {
        $admin = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'password' => Hash::make('password123'),
        ]);
        $admin->assignRole(Role::ADMIN);

        $response = $this->actingAs($admin)->get('/admin/events');
        $response->assertStatus(200);
        
        // Assert left sidebar
        $response->assertSee('id="adminLeftSidebar"', false);
        $response->assertSee('Pasākumu tabula');
        $response->assertSee('Lietotāji un lomas');
        $response->assertSee('Avoti un roboti');
        
        // Assert topbar
        $response->assertSee('class="eds-topbar', false);
        $response->assertSee('Publiskais portāls');
        
        // Assert right user offcanvas sidebar
        $response->assertSee('id="userSidebarOffcanvas"', false);
        $response->assertSee('superadmin@example.com');
        $response->assertSee('Administrators');
    }

    public function test_admin_can_toggle_and_bulk_publish_events(): void
    {
        $admin = User::create([
            'name' => 'Publisher Admin',
            'email' => 'publisher@example.com',
            'password' => Hash::make('password123'),
        ]);
        $admin->assignRole(Role::ADMIN);

        $event = \App\Models\Event::create([
            'title' => 'Draft Event Toggle',
            'slug' => 'draft-event-toggle',
            'start_at' => now()->addDays(5),
            'status' => 'draft',
            'published_at' => null,
            'fingerprint' => 'toggle-fp-1',
        ]);

        $this->assertFalse($event->isPublished());

        // Toggle publish via AJAX/JSON
        $response = $this->actingAs($admin)
            ->postJson("/admin/events/{$event->id}/toggle-publish");

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'is_published' => true]);
        $this->assertTrue($event->fresh()->isPublished());

        // Toggle back to unpublish
        $response2 = $this->actingAs($admin)
            ->postJson("/admin/events/{$event->id}/toggle-publish");

        $response2->assertStatus(200);
        $response2->assertJson(['success' => true, 'is_published' => false]);
        $this->assertFalse($event->fresh()->isPublished());

        // Bulk publish
        $event2 = \App\Models\Event::create([
            'title' => 'Draft Event Bulk 2',
            'slug' => 'draft-event-bulk-2',
            'start_at' => now()->addDays(6),
            'status' => 'draft',
            'published_at' => null,
            'fingerprint' => 'toggle-fp-2',
        ]);

        $bulkRes = $this->actingAs($admin)
            ->postJson('/admin/events/bulk-publish', [
                'action' => 'publish',
                'event_ids' => [$event->id, $event2->id],
            ]);

        $bulkRes->assertStatus(200);
        $bulkRes->assertJson(['success' => true]);
        $this->assertTrue($event->fresh()->isPublished());
        $this->assertTrue($event2->fresh()->isPublished());
    }
}
