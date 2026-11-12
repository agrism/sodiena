<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SystemProcessService
{
    public const PROCESS_SCRAPE_ALL = 'scrape_all';
    public const PROCESS_SYNC_IMAGES = 'sync_images';
    public const PROCESS_SYNC_TRANSLATIONS = 'sync_translations';
    public const PROCESS_REFRESH_SITEMAP = 'refresh_sitemap';

    public const PROCESS_LABELS = [
        self::PROCESS_SCRAPE_ALL => 'Visu avotu parsēšana',
        self::PROCESS_SYNC_IMAGES => 'Attēlu sinhronizācija uz S3',
        self::PROCESS_SYNC_TRANSLATIONS => 'Tulkojumu sinhronizācija (LV/EN/RU)',
        self::PROCESS_REFRESH_SITEMAP => 'Sitemap.xml atjaunošana',
    ];

    /**
     * Stale timeout after which a running process is considered abandoned (in seconds).
     */
    public const STALE_TIMEOUT = 7200; // 2 hours

    /**
     * Run a process with atomic execution lock and status tracking.
     * Prevents overlapping executions.
     */
    public function runWithLock(string $process, callable $callback, int $lockTimeoutSeconds = 3600): array
    {
        $status = $this->getStatus($process);

        if ($status['is_running']) {
            $startedAgo = $status['started_at'] ? Carbon::parse($status['started_at'])->diffForHumans() : 'nesen';
            $label = self::PROCESS_LABELS[$process] ?? $process;
            Log::warning("SystemProcessService: skipped duplicate run for {$process} (already running, started {$startedAgo}).");

            return [
                'executed' => false,
                'status' => 'already_running',
                'message' => "Process '{$label}' pašlaik jau darbojas (sākts {$startedAgo}). Dublēta palaišana novērsta.",
            ];
        }

        // Mark as running
        $startedAt = now();
        $this->updateStatus($process, [
            'is_running' => true,
            'started_at' => $startedAt->toIso8601String(),
            'last_status' => 'running',
            'last_message' => 'Process pašlaik tiek izpildīts...',
        ]);

        $result = null;
        $failed = false;
        $errorMessage = null;

        try {
            $result = $callback();
            return [
                'executed' => true,
                'status' => 'success',
                'result' => $result,
            ];
        } catch (\Throwable $e) {
            $failed = true;
            $errorMessage = $e->getMessage();
            Log::error("SystemProcessService: error during {$process}: " . $errorMessage, [
                'exception' => $e,
            ]);

            throw $e;
        } finally {
            $finishedAt = now();
            $duration = round($finishedAt->diffInSeconds($startedAt, true), 1);

            $this->updateStatus($process, [
                'is_running' => false,
                'started_at' => null,
                'last_finished_at' => $finishedAt->toIso8601String(),
                'last_duration' => $duration,
                'last_status' => $failed ? 'failed' : 'success',
                'last_message' => $failed ? "Kļūda: {$errorMessage}" : "Pabeigts veiksmīgi ({$duration}s)",
            ]);
        }
    }

    /**
     * Check if a specific process is currently running.
     */
    public function isRunning(string $process): bool
    {
        return $this->getStatus($process)['is_running'];
    }

    /**
     * Get process status data.
     */
    public function getStatus(string $process): array
    {
        $cacheKey = "sys_proc_status_{$process}";
        $data = Cache::get($cacheKey, [
            'is_running' => false,
            'started_at' => null,
            'last_finished_at' => null,
            'last_duration' => null,
            'last_status' => 'idle',
            'last_message' => 'Gaidīšanas režīmā',
        ]);

        // Safety check: if marked running but started_at was older than STALE_TIMEOUT, auto-reset
        if (!empty($data['is_running']) && !empty($data['started_at'])) {
            $started = Carbon::parse($data['started_at']);
            if ($started->diffInSeconds(now()) > self::STALE_TIMEOUT) {
                $data['is_running'] = false;
                $data['last_status'] = 'timed_out';
                $data['last_message'] = 'Pārtraukts pēc noildzes limita (2h)';
                Cache::put($cacheKey, $data, 86400 * 7);
            }
        }

        $data['label'] = self::PROCESS_LABELS[$process] ?? $process;
        $data['started_for_humans'] = !empty($data['started_at']) ? Carbon::parse($data['started_at'])->diffForHumans() : null;
        $data['finished_for_humans'] = !empty($data['last_finished_at']) ? Carbon::parse($data['last_finished_at'])->diffForHumans() : null;

        return $data;
    }

    /**
     * Update process status data in cache.
     */
    public function updateStatus(string $process, array $newData): void
    {
        $cacheKey = "sys_proc_status_{$process}";
        $current = Cache::get($cacheKey, []);
        $merged = array_merge($current, $newData);
        Cache::put($cacheKey, $merged, 86400 * 7);
    }

    /**
     * Get all monitored background process statuses.
     */
    public function getAllStatuses(): array
    {
        $statuses = [];
        foreach (self::PROCESS_LABELS as $key => $label) {
            $statuses[$key] = $this->getStatus($key);
        }

        return $statuses;
    }

    /**
     * Inspect server disk space (free, total, used, percentage).
     */
    public function getDiskSpace(): array
    {
        $path = base_path();
        $freeBytes = @disk_free_space($path);
        $totalBytes = @disk_total_space($path);

        if ($freeBytes === false || $totalBytes === false || $totalBytes <= 0) {
            return [
                'available' => false,
                'free_human' => 'Nav pieejams',
                'total_human' => 'Nav pieejams',
                'used_human' => 'Nav pieejams',
                'percentage' => 0,
                'is_low' => false,
            ];
        }

        $usedBytes = max(0, $totalBytes - $freeBytes);
        $percentage = round(($usedBytes / $totalBytes) * 100, 1);
        $freeGb = round($freeBytes / (1024 * 1024 * 1024), 2);

        // Low space threshold: less than 3 GB free or more than 88% used
        $isLow = ($freeGb < 3.0 || $percentage >= 88.0);
        $isCritical = ($freeGb < 1.0 || $percentage >= 95.0);

        return [
            'available' => true,
            'free_bytes' => $freeBytes,
            'total_bytes' => $totalBytes,
            'used_bytes' => $usedBytes,
            'free_human' => $this->formatBytes($freeBytes),
            'total_human' => $this->formatBytes($totalBytes),
            'used_human' => $this->formatBytes($usedBytes),
            'percentage' => $percentage,
            'is_low' => $isLow,
            'is_critical' => $isCritical,
            'status_color' => $isCritical ? 'rose' : ($isLow ? 'amber' : 'emerald'),
        ];
    }

    /**
     * Format bytes into human readable format (GB, MB).
     */
    public function formatBytes(float|int $bytes, int $precision = 1): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

