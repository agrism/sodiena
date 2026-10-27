<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Event;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class EventApiTest extends TestCase
{
    use RefreshDatabase;

    protected string $token = 'test_secret_bearer_token';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('api.bearer_token', $this->token);
    }

    public function test_api_requires_bearer_token(): void
    {
        // 1. Without token
        $response = $this->getJson('/api/v1/events');
        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated. Invalid or missing Bearer token.',
            ]);

        // 2. With invalid token
        $response = $this->withToken('wrong-token')->getJson('/api/v1/events');
        $response->assertStatus(401);

        // 3. With valid token
        $response = $this->withToken($this->token)->getJson('/api/v1/events');
        $response->assertStatus(200);
    }

    public function test_api_events_index_filters_and_returns_paginated_list(): void
    {
        $categoryMusic = Category::create([
            'name' => 'Mūzika',
            'slug' => 'muzika',
        ]);

        $categoryCinema = Category::create([
            'name' => 'Kino',
            'slug' => 'kino',
        ]);

        $locationRiga = Location::create([
            'name' => 'Arēna Rīga',
            'slug' => 'arena-riga',
            'city' => 'Rīga',
            'region' => 'Rīgas reģions',
        ]);

        $event1 = Event::create([
            'title' => 'Rokkoncerts Rīgā',
            'slug' => 'rokkoncerts-riga',
            'description' => 'Lielisks rokkoncerts',
            'start_at' => now()->addDays(2),
            'status' => 'published',
            'published_at' => now()->subHour(),
            'location_id' => $locationRiga->id,
            'is_free' => false,
            'price_min' => 20.00,
        ]);
        $event1->categories()->attach($categoryMusic->id);

        $event2 = Event::create([
            'title' => 'Filmas pirmizrāde',
            'slug' => 'filmas-pirmizrade',
            'description' => 'Jauna filma',
            'start_at' => now()->addDays(3),
            'status' => 'published',
            'published_at' => now()->subHour(),
            'is_free' => true,
        ]);
        $event2->categories()->attach($categoryCinema->id);

        // Filter by category
        $response = $this->withToken($this->token)->getJson('/api/v1/events?category=muzika');
        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Rokkoncerts Rīgā');

        // Filter by search
        $response = $this->withToken($this->token)->getJson('/api/v1/events?search=' . urlencode('pirmizrāde'));
        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Filmas pirmizrāde');

        // Filter by price
        $response = $this->withToken($this->token)->getJson('/api/v1/events?price=free');
        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Filmas pirmizrāde');
    }

    public function test_api_event_show_by_id(): void
    {
        $event = Event::create([
            'title' => 'Dziesmu svētki',
            'slug' => 'dziesmu-svetki',
            'description' => 'Tradicionālie dziesmu svētki',
            'start_at' => now()->addDays(5),
            'status' => 'published',
            'published_at' => now()->subHour(),
        ]);

        // Success by ID
        $response = $this->withToken($this->token)->getJson("/api/v1/events/{$event->id}");
        $response->assertStatus(200)
            ->assertJsonPath('data.id', $event->id)
            ->assertJsonPath('data.title', 'Dziesmu svētki');

        // 404 for non-existent ID
        $response = $this->withToken($this->token)->getJson('/api/v1/events/999999');
        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_api_promoted_events_returns_7_events(): void
    {
        // Create 10 events (2 featured, 8 regular)
        for ($i = 1; $i <= 10; $i++) {
            Event::create([
                'title' => "Pasākums {$i}",
                'slug' => "pasakums-{$i}",
                'start_at' => now()->addDays($i),
                'status' => 'published',
                'published_at' => now()->subHour(),
                'is_featured' => ($i <= 2),
                'views_count' => $i * 10,
            ]);
        }

        $response = $this->withToken($this->token)->getJson('/api/v1/events/promoted');
        $response->assertStatus(200)
            ->assertJsonCount(7, 'data');
    }

    public function test_api_events_sync_initial_full_sync(): void
    {
        $location = Location::create([
            'name' => 'Alūksnes Kultūras centrs',
            'city' => 'Alūksne',
            'region' => 'Vidzeme',
        ]);

        $event = Event::create([
            'title' => 'Alūksnes pilsētas svētki',
            'slug' => 'aluksnes-pilsetas-svetki',
            'start_at' => now()->addDays(5),
            'status' => 'published',
            'published_at' => now()->subHour(),
            'location_id' => $location->id,
        ]);

        $response = $this->withToken($this->token)->getJson('/api/v1/events/sync');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'sync_timestamp',
                'data',
                'deleted_ids',
                'meta' => [
                    'total_updated',
                    'total_deleted',
                    'current_page',
                    'last_page',
                    'per_page',
                    'has_more',
                ],
                'links',
            ])
            ->assertJsonPath('deleted_ids', [])
            ->assertJsonPath('data.0.id', $event->id)
            ->assertJsonPath('data.0.status', 'published')
            ->assertJsonPath('data.0.location.city_slug', 'aluksne');
    }

    public function test_api_events_sync_incremental_and_deleted_ids(): void
    {
        $cutoff = now()->subHour();

        // 1. Old event before cutoff (not modified)
        $oldEvent = Event::create([
            'title' => 'Vecs pasākums',
            'slug' => 'vecs-pasakums',
            'start_at' => now()->addDays(10),
            'status' => 'published',
            'published_at' => now()->subDays(5),
            'created_at' => now()->subDays(5),
        ]);
        Event::where('id', $oldEvent->id)->update(['updated_at' => now()->subDays(2)]);

        // 2. Newly modified event
        $newEvent = Event::create([
            'title' => 'Jauns pasākums',
            'slug' => 'jauns-pasakums',
            'start_at' => now()->addDays(12),
            'status' => 'published',
            'published_at' => now()->subMinutes(10),
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        // 3. Event that was soft deleted recently
        $deletedEvent = Event::create([
            'title' => 'Dzēsts pasākums',
            'slug' => 'dzests-pasakums',
            'start_at' => now()->addDays(15),
            'status' => 'published',
            'published_at' => now()->subDays(2),
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subMinutes(5),
        ]);
        $deletedEvent->delete(); // sets deleted_at to now

        // 4. Event that was unpublished (status changed to draft) recently
        $unpublishedEvent = Event::create([
            'title' => 'Atcelts pasākums',
            'slug' => 'atcelts-pasakums',
            'start_at' => now()->addDays(16),
            'status' => 'draft',
            'published_at' => null,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subMinutes(5),
        ]);

        $response = $this->withToken($this->token)->getJson('/api/v1/events/sync?since=' . urlencode($cutoff->toIso8601String()));

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newEvent->id)
            ->assertJsonPath('meta.total_updated', 1);

        $deletedIds = $response->json('deleted_ids');
        $this->assertContains($deletedEvent->id, $deletedIds);
        $this->assertContains($unpublishedEvent->id, $deletedIds);
        $this->assertNotContains($oldEvent->id, $deletedIds);
    }

    public function test_api_city_slug_filtering_aluksne(): void
    {
        $locationAluksne = Location::create([
            'name' => 'Alūksnes Pils parks',
            'city' => 'Alūksne',
            'region' => 'Vidzeme',
        ]);

        $locationRiga = Location::create([
            'name' => 'Vērmanes dārzs',
            'city' => 'Rīga',
            'region' => 'Rīga',
        ]);

        $eventAluksne = Event::create([
            'title' => 'Koncerts Alūksnē',
            'slug' => 'koncerts-aluksne',
            'start_at' => now()->addDays(2),
            'status' => 'published',
            'published_at' => now()->subHour(),
            'location_id' => $locationAluksne->id,
        ]);

        $eventRiga = Event::create([
            'title' => 'Koncerts Rīgā',
            'slug' => 'koncerts-riga',
            'start_at' => now()->addDays(2),
            'status' => 'published',
            'published_at' => now()->subHour(),
            'location_id' => $locationRiga->id,
        ]);

        // 1. Search index with city slug "aluksne"
        $response = $this->withToken($this->token)->getJson('/api/v1/events?city=aluksne');
        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $eventAluksne->id)
            ->assertJsonPath('data.0.location.city', 'Alūksne')
            ->assertJsonPath('data.0.location.city_slug', 'aluksne');

        // 2. Search sync with city slug "aluksne"
        $responseSync = $this->withToken($this->token)->getJson('/api/v1/events/sync?city=aluksne');
        $responseSync->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $eventAluksne->id);
    }

    public function test_api_events_index_updated_since_and_sort(): void
    {
        $old = Event::create([
            'title' => 'Pirms stundas',
            'slug' => 'pirms-stundas',
            'start_at' => now()->addDays(3),
            'status' => 'published',
            'published_at' => now()->subHours(2),
            'created_at' => now()->subHours(2),
        ]);
        Event::where('id', $old->id)->update(['updated_at' => now()->subHours(2)]);

        $recent = Event::create([
            'title' => 'Tikko atjaunots',
            'slug' => 'tikko-atjaunots',
            'start_at' => now()->addDays(4),
            'status' => 'published',
            'published_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        $response = $this->withToken($this->token)->getJson('/api/v1/events?updated_since=' . urlencode(now()->subHour()->toIso8601String()) . '&sort=updated_desc');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recent->id);
    }

    public function test_api_events_sync_invalid_since_returns_422(): void
    {
        $response = $this->withToken($this->token)->getJson('/api/v1/events/sync?since=invalid-date');
        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
