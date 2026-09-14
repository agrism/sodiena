<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EventImageStorageService
{
    protected string $disk = 's3';

    /**
     * Mirror image for a given Event model and save internal_image_url.
     */
    public function mirrorEventImage(Event $event, bool $force = false): ?string
    {
        if (empty($event->image_url)) {
            return null;
        }

        // Do not download/mirror images for soft-deleted, deleted, or cancelled events
        if ($event->trashed() || in_array($event->status, ['deleted', 'cancelled'], true)) {
            return null;
        }

        // If internal_image_url is already set and not forcing re-upload, return existing
        if (!$force && !empty($event->internal_image_url)) {
            return $event->internal_image_url;
        }

        // Skip known default/placeholder images
        if (str_contains($event->image_url, 'aplis-default-og-img.jpg') || str_contains($event->image_url, 'default-event.jpg')) {
            return null;
        }

        $s3Url = $this->uploadFromUrl($event->image_url, $event);

        if ($s3Url) {
            $event->update(['internal_image_url' => $s3Url]);
            return $s3Url;
        }

        return null;
    }

    /**
     * Download an image from an external URL and upload it to S3.
     */
    public function uploadFromUrl(string $url, ?Event $event = null): ?string
    {
        $url = trim($url);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            Log::warning("Invalid image URL provided for upload: {$url}");
            return null;
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
            ])->timeout(15)->connectTimeout(10)->get($url);

            if (!$response->successful()) {
                Log::warning("Failed to fetch image from URL: {$url} (Status: {$response->status()})");
                return null;
            }

            $body = $response->body();
            if (empty($body)) {
                Log::warning("Empty image body fetched from URL: {$url}");
                return null;
            }

            $mimeType = $response->header('Content-Type');
            if (empty($mimeType) || !str_starts_with($mimeType, 'image/')) {
                // Try guessing mime type from file content
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->buffer($body);
            }

            // Extract primary mime type without charset
            $mimeType = explode(';', $mimeType)[0];
            $mimeType = trim($mimeType);

            if (!str_starts_with($mimeType, 'image/')) {
                Log::warning("URL {$url} did not return an image. Mime: {$mimeType}");
                return null;
            }

            // Optimize and convert to WebP for maximum web performance
            $optimized = $this->optimizeImage($body, $mimeType);
            if ($optimized) {
                $body = $optimized['body'];
                $mimeType = $optimized['mimeType'];
                $extension = $optimized['extension'];
            } else {
                $extension = $this->extensionFromMimeType($mimeType, $url);
            }

            // Generate structured file path: events/YYYY-MM/{id_or_random}_{hash}.{ext}
            $dateFolder = ($event && $event->start_at) ? $event->start_at->format('Y-m') : now()->format('Y-m');
            $identifier = $event ? (string)$event->id : Str::random(8);
            $contentHash = substr(md5($body), 0, 10);
            $fileName = "{$identifier}_{$contentHash}.{$extension}";
            $s3Path = "events/{$dateFolder}/{$fileName}";

            // Upload to S3 with public read access, mime type header and 1-year cache control
            $stored = Storage::disk($this->disk)->put($s3Path, $body, [
                'visibility' => 'public',
                'ContentType' => $mimeType,
                'CacheControl' => 'max-age=31536000, public',
            ]);

            if (!$stored) {
                Log::error("Failed to store image in S3 at path: {$s3Path}");
                return null;
            }

            return Storage::disk($this->disk)->url($s3Path);
        } catch (\Throwable $e) {
            Log::error("Exception uploading image from {$url} to S3: " . $e->getMessage(), [
                'event_id' => $event?->id,
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Optimize image binary and convert to modern WebP format for fast web delivery.
     *
     * @return array{body: string, mimeType: string, extension: string}|null
     */
    public function optimizeImage(string $binaryData, string $mimeType, int $maxWidth = 1200, int $maxHeight = 1200, int $quality = 82): ?array
    {
        // Don't convert SVG vector images
        if (str_contains($mimeType, 'svg')) {
            return null;
        }

        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            return null;
        }

        try {
            // Read EXIF orientation before manipulating image if JPEG
            $orientation = null;
            if (function_exists('exif_read_data') && str_contains($mimeType, 'jpeg')) {
                $stream = fopen('php://memory', 'r+');
                if ($stream) {
                    fwrite($stream, $binaryData);
                    rewind($stream);
                    $exif = @exif_read_data($stream);
                    fclose($stream);
                    $orientation = $exif['Orientation'] ?? null;
                }
            }

            $img = @imagecreatefromstring($binaryData);
            if (!$img) {
                return null;
            }

            // If image is palette-based (e.g. 8-bit indexed PNG), convert to truecolor for WebP support
            if (!imageistruecolor($img)) {
                imagepalettetotruecolor($img);
            }

            // Fix orientation if needed
            if ($orientation) {
                switch ($orientation) {
                    case 3:
                        $rotated = imagerotate($img, 180, 0);
                        if ($rotated !== false) {
                            imagedestroy($img);
                            $img = $rotated;
                        }
                        break;
                    case 6:
                        $rotated = imagerotate($img, -90, 0);
                        if ($rotated !== false) {
                            imagedestroy($img);
                            $img = $rotated;
                        }
                        break;
                    case 8:
                        $rotated = imagerotate($img, 90, 0);
                        if ($rotated !== false) {
                            imagedestroy($img);
                            $img = $rotated;
                        }
                        break;
                }
            }

            $origW = imagesx($img);
            $origH = imagesy($img);

            if ($origW <= 0 || $origH <= 0) {
                imagedestroy($img);
                return null;
            }

            // Calculate resized dimensions if larger than bounds (never upscale)
            $ratio = min($maxWidth / max($origW, 1), $maxHeight / max($origH, 1), 1.0);
            $newW = (int) round($origW * $ratio);
            $newH = (int) round($origH * $ratio);

            if ($newW !== $origW || $newH !== $origH) {
                $resized = imagecreatetruecolor($newW, $newH);
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
                imagedestroy($img);
                $img = $resized;
            } else {
                imagealphablending($img, false);
                imagesavealpha($img, true);
            }

            ob_start();
            $saved = imagewebp($img, null, $quality);
            $webpData = ob_get_clean();
            imagedestroy($img);

            if ($saved && !empty($webpData)) {
                return [
                    'body' => $webpData,
                    'mimeType' => 'image/webp',
                    'extension' => 'webp',
                ];
            }
        } catch (\Throwable $e) {
            Log::warning("WebP optimization failed, falling back to original: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Resolve appropriate file extension based on mime type or URL.
     */
    protected function extensionFromMimeType(string $mimeType, string $url): string
    {
        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/gif' => 'gif',
            'image/svg+xml' => 'svg',
        ];

        if (isset($mimeMap[$mimeType])) {
            return $mimeMap[$mimeType];
        }

        // Fallback to URL path extension if valid
        $urlPath = parse_url($url, PHP_URL_PATH);
        if ($urlPath) {
            $ext = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif', 'svg'])) {
                return $ext === 'jpeg' ? 'jpg' : $ext;
            }
        }

        return 'jpg';
    }
}
