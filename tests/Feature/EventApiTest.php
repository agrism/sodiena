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
}
