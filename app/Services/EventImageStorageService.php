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

        $isLocal = $this->isLocalPath($event->image_url);

        // If internal_image_url is already set to S3 and not forcing re-upload, return existing
        if (!$force && !empty($event->internal_image_url) && !$this->isLocalPath($event->internal_image_url) && !$isLocal) {
            return $event->internal_image_url;
        }

        // Skip known default/placeholder images
        if (str_contains($event->image_url, 'aplis-default-og-img.jpg') || str_contains($event->image_url, 'default-event.jpg')) {
            return null;
        }

        $s3Url = $isLocal
            ? $this->uploadFromLocalPath($event->image_url, $event)
            : $this->uploadFromUrl($event->image_url, $event);

        if ($s3Url) {
            $updates = ['internal_image_url' => $s3Url];
            if ($isLocal) {
                // If it was a local storage file, point image_url directly to S3 as well
                $updates['image_url'] = $s3Url;
            }
            $event->update($updates);
            return $s3Url;
        }

        return null;
    }

    /**
     * Determine whether a given URL or path points to a local file.
     */
    public function isLocalPath(string $url): bool
    {
        $trimmed = trim($url);

        if (str_starts_with($trimmed, '/storage/') || str_starts_with($trimmed, 'storage/')) {
            return true;
        }

        if (preg_match('#^https?://[^/]+/storage/#i', $trimmed)) {
            return true;
        }

        if (!str_starts_with($trimmed, 'http://') && !str_starts_with($trimmed, 'https://')) {
            return true;
        }

        return false;
    }

    /**
     * Upload an image binary to S3 with WebP optimization and public cache headers.
     */
    public function uploadBinary(string $body, string $mimeType, ?Event $event = null, ?string $originalExtension = null): ?string
    {
        try {
            if (empty($body)) {
                Log::warning("Empty image binary passed for S3 upload");
                return null;
            }

            // Optimize and convert to WebP
            $optimized = $this->optimizeImage($body, $mimeType);
            if ($optimized) {
                $body = $optimized['body'];
                $mimeType = $optimized['mimeType'];
                $extension = $optimized['extension'];
            } else {
                $extension = $originalExtension ?: $this->extensionFromMimeType($mimeType, '');
            }

            $dateFolder = ($event && $event->start_at) ? $event->start_at->format('Y-m') : now()->format('Y-m');
            $identifier = $event ? (string) $event->id : Str::random(8);
            $contentHash = substr(md5($body), 0, 10);
            $fileName = "{$identifier}_{$contentHash}.{$extension}";
            $s3Path = "events/{$dateFolder}/{$fileName}";

            $stored = Storage::disk($this->disk)->put($s3Path, $body, [
                'visibility' => 'public',
                'ContentType' => $mimeType,
                'CacheControl' => 'max-age=31536000, public',
            ]);

            if ($stored) {
                return Storage::disk($this->disk)->url($s3Path);
            }

            Log::error("Failed to store image in S3 at path: {$s3Path}");
        } catch (\Throwable $e) {
            Log::error("Exception uploading image binary to S3: " . $e->getMessage(), [
                'event_id' => $event?->id,
                'exception' => $e,
            ]);
        }

        return null;
    }

    /**
     * Upload a local storage file to S3.
     */
    public function uploadFromLocalPath(string $path, ?Event $event = null): ?string
    {
        $path = trim($path);

        // Strip host/protocol if a full URL like https://sodiena.lv/storage/... was passed
        $path = preg_replace('#^https?://[^/]+/#', '/', $path);

        $possiblePaths = [];

        if (str_starts_with($path, '/storage/') || str_starts_with($path, 'storage/')) {
            $storageRel = preg_replace('#^/?storage/#', '', $path);
            $possiblePaths[] = Storage::disk('public')->path($storageRel);
            $possiblePaths[] = public_path('storage/' . $storageRel);
            $possiblePaths[] = storage_path('app/public/' . $storageRel);
        } else {
            $possiblePaths[] = public_path(ltrim($path, '/'));
            $possiblePaths[] = storage_path('app/public/' . ltrim($path, '/'));
            $possiblePaths[] = $path;
        }

        $realPath = null;
        foreach ($possiblePaths as $candidate) {
            if (file_exists($candidate) && is_file($candidate)) {
                $realPath = $candidate;
                break;
            }
        }

        if (!$realPath) {
            Log::warning("Local image file not found for S3 upload: {$path}");
            return null;
        }

        $body = @file_get_contents($realPath);
        if (empty($body)) {
            Log::warning("Local image file is empty: {$realPath}");
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($body) ?: 'image/jpeg';
        $originalExt = pathinfo($realPath, PATHINFO_EXTENSION);

        return $this->uploadBinary($body, $mimeType, $event, $originalExt);
    }

    /**
     * Upload an uploaded file to S3 (with fallback to local storage on S3 failure).
     */
    public function uploadFile(\Illuminate\Http\UploadedFile $file, ?Event $event = null): ?string
    {
        try {
            $body = file_get_contents($file->getRealPath());
            if (!empty($body)) {
                $mimeType = $file->getMimeType() ?: 'image/jpeg';
                $ext = $file->getClientOriginalExtension() ?: 'webp';
                $s3Url = $this->uploadBinary($body, $mimeType, $event, $ext);
                if ($s3Url) {
                    return $s3Url;
                }
            }
        } catch (\Throwable $e) {
            Log::error("Exception reading uploaded file for S3: " . $e->getMessage(), [
                'event_id' => $event?->id,
                'exception' => $e,
            ]);
        }

        // Fallback to local storage if S3 fails
        $path = $file->store('events', 'public');
        return '/storage/' . $path;
    }

    /**
     * Upload from either local path or remote URL to S3.
     */
    public function uploadLocalOrRemote(string $urlOrPath, ?Event $event = null): ?string
    {
        if ($this->isLocalPath($urlOrPath)) {
            return $this->uploadFromLocalPath($urlOrPath, $event);
        }

        return $this->uploadFromUrl($urlOrPath, $event);
    }

    /**
     * Download an image from an external URL and upload it to S3.
     */
    public function uploadFromUrl(string $url, ?Event $event = null): ?string
    {
        $url = trim($url);

        if ($this->isLocalPath($url)) {
            return $this->uploadFromLocalPath($url, $event);
        }

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

            $extension = $this->extensionFromMimeType($mimeType, $url);

            return $this->uploadBinary($body, $mimeType, $event, $extension);
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
    public function optimizeImage(string $binaryData, string $mimeType, int $maxWidth = 960, int $maxHeight = 600, int $quality = 75): ?array
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
