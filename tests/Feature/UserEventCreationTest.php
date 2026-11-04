<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Event;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserEventCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['slug' => Role::ADMIN], ['name' => 'Administrators']);
        Role::firstOrCreate(['slug' => Role::REGULAR], ['name' => 'Lietotājs']);

        Category::firstOrCreate(['slug' => 'kino'], ['name' => 'Kino', 'order' => 1]);
        Category::firstOrCreate(['slug' => 'teatris'], ['name' => 'Teātris', 'order' => 2]);
        Category::firstOrCreate(['slug' => 'muzika'], ['name' => 'Mūzika', 'order' => 3]);
        Category::firstOrCreate(['slug' => 'citi'], ['name' => 'Citi', 'order' => 9]);
    }

    public function test_guest_cannot_access_event_create_page(): void
    {
        $response = $this->get('/events/create');

        $response->assertRedirect('/login');
    }

    public function test_guest_cannot_store_event(): void
    {
        $response = $this->post('/events', [
            'title' => 'Testa pasākums',
            'description' => 'Pasākuma apraksts pietiekami garš.',
            'start_at' => now()->addDays(5)->format('Y-m-d H:i'),
        ]);

        $response->assertRedirect('/login');
        $this->assertDatabaseCount('events', 0);
    }

    public function test_authenticated_user_can_view_event_create_form(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::REGULAR);

        $response = $this->actingAs($user)->get('/events/create');

        $response->assertOk();
        $response->assertSee('Pievienot jaunu pasākumu');
        $response->assertSee('Pasākuma nosaukums');
        $response->assertSee('Kategorijas');
        $response->assertSee('Publicēt pasākumu');
    }

    public function test_event_create_form_renders_in_english_and_russian(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::REGULAR);

        // English version
        $responseEn = $this->actingAs($user)->get('/en/events/create');
        $responseEn->assertOk();
        $responseEn->assertSee('Add New Event');
        $responseEn->assertSee('Event Title');
        $responseEn->assertSee('Publish Event');
        $responseEn->assertSee('Basic Information');

        // Russian version
        $responseRu = $this->actingAs($user)->get('/ru/events/create');
        $responseRu->assertOk();
        $responseRu->assertSee('Добавить новое событие');
        $responseRu->assertSee('Название события');
        $responseRu->assertSee('Опубликовать событие');
        $responseRu->assertSee('Основная информация');
    }

    public function test_authenticated_user_can_create_event_with_custom_location(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $user->assignRole(Role::REGULAR);

        $muzika = Category::where('slug', 'muzika')->first();

        $response = $this->actingAs($user)->post('/events', [
            'title' => 'Džeza vakars pie Daugavas',
            'categories' => [$muzika->id],
            'start_at' => now()->addDays(3)->format('Y-m-d 19:00'),
            'end_at' => now()->addDays(3)->format('Y-m-d 22:00'),
            'description' => 'Aicinām visus uz brīnišķīgu dzīvās džeza mūzikas vakaru gleznainā vietā pie Daugavas.',
            'city' => 'Rīga',
            'venue_name' => 'Spīķeru koncertzāle',
            'address' => 'Maskavas iela 4',
            'is_free' => '1',
            'ticket_url' => 'https://www.bilesuserviss.lv/event123',
            'source_url' => 'https://spikeri.lv/events',
        ]);

        $event = Event::where('title', 'Džeza vakars pie Daugavas')->first();
        $this->assertNotNull($event);

        $response->assertRedirect(route('events.show', $event->slug));
        $response->assertSessionHas('success');

        $this->assertEquals($user->id, $event->user_id);
        $this->assertEquals('published', $event->status);
        $this->assertNotNull($event->published_at);
        $this->assertTrue($event->is_free);
        $this->assertEquals('user', $event->source_slug);

        // Verify Location creation
        $this->assertNotNull($event->location);
        $this->assertEquals('Spīķeru koncertzāle', $event->location->name);
        $this->assertEquals('Rīga', $event->location->city);
        $this->assertEquals('Maskavas iela 4', $event->location->address);

        // Verify Category attachment
        $this->assertTrue($event->categories->contains($muzika));

        // Verify Translation creation
        $this->assertDatabaseHas('event_translations', [
            'event_id' => $event->id,
            'locale' => 'lv',
            'title' => 'Džeza vakars pie Daugavas',
        ]);
    }

    public function test_authenticated_user_can_upload_event_image(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $user->assignRole(Role::REGULAR);

        $muzika = Category::where('slug', 'muzika')->first();
        $location = Location::create([
            'name' => 'Arēna Rīga',
            'city' => 'Rīga',
            'region' => 'Rīga',
        ]);

        $file = UploadedFile::fake()->image('poster.jpg', 600, 400);

        $response = $this->actingAs($user)->post('/events', [
            'title' => 'Koncerts ar plakātu',
            'categories' => [$muzika->id],
            'start_at' => now()->addDays(10)->format('Y-m-d 20:00'),
            'description' => 'Koncerta apraksts ar pievienotu plakātu un papildu informāciju.',
            'location_id' => $location->id,
            'image' => $file,
            'is_free' => '0',
            'price_min' => '15.50',
            'price_max' => '35.00',
        ]);

        $event = Event::where('title', 'Koncerts ar plakātu')->first();
        $this->assertNotNull($event);
        $response->assertRedirect(route('events.show', $event->slug));

        $this->assertNotNull($event->image_url);
        $this->assertStringStartsWith('/storage/events/', $event->image_url);

        $storedPath = str_replace('/storage/', '', $event->image_url);
        Storage::disk('public')->assertExists($storedPath);
        $this->assertEquals(15.50, $event->price_min);
        $this->assertEquals(35.00, $event->price_max);
    }

    public function test_strict_cinema_priority_rule_is_applied_to_user_events(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::REGULAR);

        $kino = Category::where('slug', 'kino')->first();
        $teatris = Category::where('slug', 'teatris')->first();

        // User mistakenly selected 'teatris' for a movie event
        $response = $this->actingAs($user)->post('/events', [
            'title' => 'Jaunā latviešu filma "Zeme" kinoseanss',
            'categories' => [$teatris->id],
            'start_at' => now()->addDays(2)->format('Y-m-d 18:00'),
            'description' => 'Filmas pirmizrāde un tikšanās ar radošo komandu.',
            'city' => 'Rīga',
            'venue_name' => 'Splendid Palace',
        ]);

        $event = Event::where('title', 'Jaunā latviešu filma "Zeme" kinoseanss')->first();
        $this->assertNotNull($event);

        // Per AGENTS.md: Splendid Palace / filma MUST be Kino and NEVER Teātris
        $categorySlugs = $event->categories->pluck('slug')->toArray();
        $this->assertContains('kino', $categorySlugs);
        $this->assertNotContains('teatris', $categorySlugs);
    }

    public function test_user_event_creation_validation(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::REGULAR);

        $response = $this->actingAs($user)->post('/events', [
            'title' => '',
            'description' => '',
        ]);

        $response->assertSessionHasErrors(['title', 'categories', 'start_at', 'description']);
    }

    public function test_user_can_view_my_events_list(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $event1 = Event::create([
            'user_id' => $user1->id,
            'title' => 'Lietotāja 1 pasākums',
            'start_at' => now()->addDays(2),
            'status' => 'published',
            'published_at' => now(),
        ]);

        $event2 = Event::create([
            'user_id' => $user2->id,
            'title' => 'Lietotāja 2 pasākums',
            'start_at' => now()->addDays(3),
            'status' => 'published',
            'published_at' => now(),
        ]);

        $response = $this->actingAs($user1)->get('/my-events');

        $response->assertOk();
        $response->assertSee('Lietotāja 1 pasākums');
        $response->assertDontSee('Lietotāja 2 pasākums');
    }

    public function test_user_can_edit_and_update_own_event(): void
    {
        $user = User::factory()->create();
        $event = Event::create([
            'user_id' => $user->id,
            'title' => 'Sākotnējais nosaukums',
            'description' => 'Sākotnējais apraksts pasākumam.',
            'start_at' => now()->addDays(4),
            'status' => 'published',
            'published_at' => now(),
        ]);

        $muzika = Category::where('slug', 'muzika')->first();

        $response = $this->actingAs($user)->get("/events/{$event->id}/edit");
        $response->assertOk();
        $response->assertSee('Sākotnējais nosaukums');

        $updateResponse = $this->actingAs($user)->put("/events/{$event->id}", [
            'title' => 'Atjaunotais nosaukums',
            'categories' => [$muzika->id],
            'start_at' => now()->addDays(5)->format('Y-m-d 20:00'),
            'description' => 'Atjaunināts detalizēts apraksts pasākumam.',
        ]);

        $updateResponse->assertRedirect(route('events.show', $event->slug));
        $this->assertEquals('Atjaunotais nosaukums', $event->fresh()->title);
    }

    public function test_user_cannot_edit_or_delete_another_users_event(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $event = Event::create([
            'user_id' => $owner->id,
            'title' => 'Īpašnieka pasākums',
            'start_at' => now()->addDays(4),
            'status' => 'published',
            'published_at' => now(),
        ]);

        $responseEdit = $this->actingAs($attacker)->get("/events/{$event->id}/edit");
        $responseEdit->assertForbidden();

        $responseDelete = $this->actingAs($attacker)->delete("/events/{$event->id}");
        $responseDelete->assertForbidden();

        $this->assertDatabaseHas('events', ['id' => $event->id]);
    }

    public function test_user_can_delete_own_event(): void
    {
        $user = User::factory()->create();
        $event = Event::create([
            'user_id' => $user->id,
            'title' => 'Dzēšamais pasākums',
            'start_at' => now()->addDays(4),
            'status' => 'published',
            'published_at' => now(),
        ]);

        $response = $this->actingAs($user)->delete("/events/{$event->id}");

        $response->assertRedirect(route('events.my'));
        $this->assertSoftDeleted('events', ['id' => $event->id]);
    }
}
