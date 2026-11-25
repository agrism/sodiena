<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventAction;
use App\Models\Source;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventActionTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $source = Source::create([
            'name' => 'Biļešu Paradīze',
            'slug' => 'bilesu-paradize',
            'url' => 'https://www.bilesuparadize.lv',
            'scraper_class' => 'App\Services\Scrapers\Sources\BilesuParadizeScraper',
            'is_active' => true,
        ]);

        $this->event = Event::create([
            'title' => 'Koncerts Lielajā Ģildē',
            'slug' => 'koncerts-lielaja-gilde-123',
            'start_at' => Carbon::now()->addDays(5),
            'end_at' => Carbon::now()->addDays(5)->addHours(2),
            'source_id' => $source->id,
            'source_slug' => 'bilesu-paradize',
            'ticket_url' => 'https://www.bilesuparadize.lv/lv/event/180123',
            'source_url' => 'https://www.orķestris.lv/koncerts',
            'description' => 'Brīnišķīgs koncerts.',
        ]);
    }

    public function test_append_utm_appends_sodiena_parameters(): void
    {
        $url = 'https://www.apollokino.lv/websales/show/535297';
        $tracked = Event::appendUtm($url, 'ticket_button');

        $this->assertStringContainsString('utm_source=sodiena.lv', $tracked);
        $this->assertStringContainsString('utm_medium=referral', $tracked);
        $this->assertStringContainsString('utm_campaign=event_detail', $tracked);
        $this->assertStringContainsString('utm_content=ticket_button', $tracked);
    }

    public function test_append_utm_strips_alien_utm_and_preserves_query_params(): void
    {
        $url = 'https://www.bilesuparadize.lv/lv/event/180123?id=99&utm_source=afiro&utm_medium=cpc&locale=lv#seats';
        $tracked = Event::appendUtm($url, 'ticket_button');

        // Alien UTM should be stripped and replaced
        $this->assertStringNotContainsString('afiro', $tracked);
        $this->assertStringNotContainsString('cpc', $tracked);

        // Sodiena UTM must be present
        $this->assertStringContainsString('utm_source=sodiena.lv', $tracked);
        $this->assertStringContainsString('utm_medium=referral', $tracked);
        $this->assertStringContainsString('utm_content=ticket_button', $tracked);

        // Existing query params and fragment preserved
        $this->assertStringContainsString('id=99', $tracked);
        $this->assertStringContainsString('locale=lv', $tracked);
        $this->assertStringEndsWith('#seats', $tracked);
    }

    public function test_event_model_tracked_urls(): void
    {
        $ticketTracked = $this->event->getTrackedTicketUrl();
        $this->assertStringContainsString('https://www.bilesuparadize.lv/lv/event/180123?', $ticketTracked);
        $this->assertStringContainsString('utm_source=sodiena.lv', $ticketTracked);
        $this->assertStringContainsString('utm_content=ticket_button', $ticketTracked);

        $sourceTracked = $this->event->getTrackedSourceUrl();
        $this->assertStringContainsString('utm_source=sodiena.lv', $sourceTracked);
        $this->assertStringContainsString('utm_content=website_button', $sourceTracked);
    }

    public function test_api_act_endpoint_stores_action_and_returns_204(): void
    {
        $payload = [
            'event_id' => $this->event->id,
            'action_type' => 'ticket',
            'url' => 'https://www.bilesuparadize.lv/lv/event/180123?utm_source=sodiena.lv',
            'referer' => '/events/koncerts-lielaja-gilde-123',
        ];

        $response = $this->postJson('/api/act', $payload, [
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148',
        ]);

        $response->assertNoContent(204);

        $this->assertDatabaseHas('event_actions', [
            'event_id' => $this->event->id,
            'action_type' => 'ticket',
            'target_domain' => 'bilesuparadize.lv',
            'referer' => '/events/koncerts-lielaja-gilde-123',
            'device' => 'mobile',
        ]);

        $action = EventAction::first();
        $this->assertEquals($payload['url'], $action->url);
        $this->assertEquals('bilesuparadize.lv', $action->target_domain);
        $this->assertEquals('mobile', $action->device);
        $this->assertNotNull($action->created_at);
    }

    public function test_api_go_alias_endpoint_works(): void
    {
        $payload = [
            'event_id' => $this->event->id,
            'action_type' => 'website',
            'url' => 'https://www.orķestris.lv/koncerts?utm_source=sodiena.lv',
            'referer' => '/events/koncerts-lielaja-gilde-123',
        ];

        $response = $this->postJson('/api/go', $payload, [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36',
        ]);

        $response->assertNoContent(204);

        $this->assertDatabaseHas('event_actions', [
            'event_id' => $this->event->id,
            'action_type' => 'website',
            'device' => 'desktop',
        ]);
    }

    public function test_api_act_validation_fails_on_missing_fields(): void
    {
        $response = $this->postJson('/api/act', [
            'action_type' => 'ticket',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['event_id', 'url']);
    }
}
