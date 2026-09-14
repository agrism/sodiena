<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\EventImageStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EventImageStorageServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    public function test_mirror_event_image_successfully_uploads_and_updates_event(): void
    {
        // 1x1 transparent PNG binary
        $fakePng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        Http::fake([
            'https://example.com/poster.png' => Http::response($fakePng, 200, ['Content-Type' => 'image/png']),
        ]);

        $event = Event::create([
            'title' => 'Koncerts Lielajā Dzintarā',
            'start_at' => now()->addDays(2),
            'image_url' => 'https://example.com/poster.png',
        ]);

        $this->assertNull($event->internal_image_url);

        $service = app(EventImageStorageService::class);
        $s3Url = $service->mirrorEventImage($event);

        $this->assertNotNull($s3Url);
        $event->refresh();
        $this->assertEquals($s3Url, $event->internal_image_url);
        $this->assertEquals($s3Url, $event->display_image_url);
    }

    public function test_display_image_url_priority(): void
    {
        $event = new Event([
            'title' => 'Test Event',
            'image_url' => 'https://example.com/original.jpg',
            'internal_image_url' => 'https://sodienat.hel1.your-objectstorage.com/events/2026-09/test.jpg',
        ]);

        // Prioritizes internal_image_url over image_url
        $this->assertEquals('https://sodienat.hel1.your-objectstorage.com/events/2026-09/test.jpg', $event->display_image_url);

        // Fallbacks to image_url when internal_image_url is null
        $event->internal_image_url = null;
        $this->assertEquals('https://example.com/original.jpg', $event->display_image_url);

        // Fallbacks to default image when image_url is null or placeholder
        $event->image_url = null;
        $this->assertStringContainsString('default-event.jpg', $event->display_image_url);

        $event->image_url = 'https://example.com/aplis-default-og-img.jpg';
        $this->assertStringContainsString('default-event.jpg', $event->display_image_url);
    }

    public function test_skips_placeholders_and_invalid_images(): void
    {
        $event = Event::create([
            'title' => 'Pasākums ar noklusējuma attēlu',
            'start_at' => now()->addDays(2),
            'image_url' => 'https://afiro.lv/images/aplis-default-og-img.jpg',
        ]);

        $service = app(EventImageStorageService::class);
        $res = $service->mirrorEventImage($event);

        $this->assertNull($res);
        $this->assertNull($event->fresh()->internal_image_url);
    }

    public function test_sync_event_images_artisan_command(): void
    {
        $fakeJpg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');

        Http::fake([
            'https://example.com/sample.jpg' => Http::response($fakeJpg, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $event = Event::create([
            'title' => 'Teātra izrāde',
            'start_at' => now()->addDays(3),
            'image_url' => 'https://example.com/sample.jpg',
        ]);

        $this->artisan('events:sync-images', ['--id' => $event->id])
            ->assertSuccessful();

        $event->refresh();
        $this->assertNotNull($event->internal_image_url);
        $this->assertStringContainsString((string)$event->id, $event->internal_image_url);
        $this->assertStringEndsWith('.webp', $event->internal_image_url);
    }

    public function test_optimize_image_converts_png_and_jpeg_to_webp(): void
    {
        $fakePng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $service = app(EventImageStorageService::class);

        $result = $service->optimizeImage($fakePng, 'image/png');

        $this->assertNotNull($result);
        $this->assertEquals('image/webp', $result['mimeType']);
        $this->assertEquals('webp', $result['extension']);
        $this->assertNotEmpty($result['body']);
    }
}
