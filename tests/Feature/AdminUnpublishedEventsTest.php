<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Event;
use App\Models\EventTranslation;
use App\Models\Location;
use App\Models\Role;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUnpublishedEventsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['slug' => Role::ADMIN], ['name' => 'Administrators']);
        Role::firstOrCreate(['slug' => Role::REGULAR], ['name' => 'Lietotājs']);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
        ]);
        $this->admin->assignRole(Role::ADMIN);

        $this->regularUser = User::create([
            'name' => 'Regular User',
            'email' => 'user@example.com',
            'password' => Hash::make('password123'),
        ]);
        $this->regularUser->assignRole(Role::REGULAR);
    }

    public function test_guest_and_regular_user_cannot_access_unpublished_events_section(): void
    {
        $response = $this->get(route('admin.events.unpublished'));
        $response->assertRedirect('/login');

        $response = $this->actingAs($this->regularUser)->get(route('admin.events.unpublished'));
        $response->assertStatus(403);

        $response = $this->actingAs($this->regularUser)->get(route('admin.events.unpublished.list'));
        $response->assertStatus(403);
    }

    public function test_admin_can_access_unpublished_events_shell_page(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.events.unpublished'));

        $response->assertStatus(200);
        $response->assertSee('Nepublicētie pasākumi & Cenu pārbaude', false);
        $response->assertSee('unpublishedFilterForm', false);
        $response->assertSee('unpublishedListContainer', false);
        $response->assertSee(route('admin.events.unpublished.list'), false);
    }

    public function test_htmx_unpublished_list_renders_locale_descriptions_trimmed_to_20_chars_and_pricing_iframe(): void
    {
        $source = Source::create([
            'name' => 'Biļešu Paradīze',
            'slug' => 'bilesu-paradize',
            'url' => 'https://www.bilesuparadize.lv',
            'scraper_class' => \App\Services\Scrapers\Sources\BilesuParadizeScraper::class,
            'is_active' => true,
        ]);

        $location = Location::create([
            'name' => 'Dailes teātris',
            'city' => 'Rīga',
            'region' => 'Rīga',
            'place_type' => 'theatre',
        ]);

        $category = Category::create([
            'name' => 'Teātris',
            'slug' => 'teatris',
            'icon' => 'theater',
            'color' => '#f59e0b',
            'order' => 1,
        ]);

        $event = Event::create([
            'title' => 'Laimīgas laulības noslēpums',
            'slug' => 'laimigas-laulibas-noslepums-test123',
            'source_id' => $source->id,
            'source_slug' => $source->slug,
            'location_id' => $location->id,
            'start_at' => now()->addDays(2),
            'price_min' => 15.00,
            'price_max' => 35.00,
            'ticket_url' => 'https://www.bilesuparadize.lv/lv/event/146030',
            'status' => 'draft',
            'published_at' => null,
        ]);

        $event->categories()->sync([$category->id]);

        EventTranslation::create([
            'event_id' => $event->id,
            'locale' => 'lv',
            'title' => 'Laimīgas laulības noslēpums',
            'description' => 'Aizraujoša franču bulvāru komēdija par laulības noslēpumiem un cilvēku vājībām.',
        ]);

        EventTranslation::create([
            'event_id' => $event->id,
            'locale' => 'en',
            'title' => 'The Secret of a Happy Marriage',
            'description' => 'An exciting French boulevard comedy about marriage secrets and human weaknesses.',
        ]);

        EventTranslation::create([
            'event_id' => $event->id,
            'locale' => 'ru',
            'title' => 'Секрет счастливого брака',
            'description' => 'Увлекательная французская бульварная комедия о тайнах брака и человеческих слабостях.',
        ]);

        // Fetch HTMX partial
        $response = $this->actingAs($this->admin)->get(route('admin.events.unpublished.list'), [
            'HX-Request' => 'true',
        ]);

        $response->assertStatus(200);
        $response->assertSeeText('Laimīgas laulības noslēpums');
        
        // Assert our site price is displayed
        $response->assertSeeText('Mūsu lapā uzrādītā cena');
        $response->assertSee('€15 - €35');

        // Assert direct ticket link is present
        $response->assertSee('https://www.bilesuparadize.lv/lv/event/146030');
        $response->assertSee('bilesuparadize.lv');

        // Assert 20 character trimmed descriptions
        $response->assertSee('Aizraujoša franču bu...');
        $response->assertSee('An exciting French b...');
        $response->assertSee('Увлекательная францу...');

        // Assert iframe is rendered
        $response->assertSee('iframe id="iframe-el-' . $event->id, false);
        $response->assertSee('src="https://www.bilesuparadize.lv/lv/event/146030"', false);
    }
}
