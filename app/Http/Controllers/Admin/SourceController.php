<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SourceType;
use App\Http\Controllers\Controller;
use App\Models\ScrapeLog;
use App\Models\Source;
use App\Services\Scrapers\EventIngestionService;
use App\Services\SystemProcessService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SourceController extends Controller
{
    /**
     * Source Scraper Status Dashboard & Logs by Day (Admin Sector)
     */
    public function index(Request $request, SystemProcessService $processService)
    {
        $sourcesData = $this->getSourcesData($request);
        $historyData = $this->getHistoryData($request);
        $systemStatusData = $this->getSystemStatusData($processService);

        // Return HTMX partial if request specifically targets a sub-section
        if ($request->header('HX-Request')) {
            $target = $request->header('HX-Target');
            if ($target === 'sources-registry-section') {
                return view('admin.sources.partials.sources-registry', $sourcesData);
            }
            if ($target === 'system-status-container') {
                return view('admin.sources.partials.system-status', $systemStatusData);
            }
            if ($target === 'scrape-history-section' || $request->query('partial') === 'history') {
                return view('admin.sources.partials.scrape-history', $historyData);
            }
        }

        return view('admin.sources.index', array_merge(
            $sourcesData,
            $historyData,
            $systemStatusData
        ));
    }

    /**
     * Helper to compute sources list, categories and counts
     */
    protected function getSourcesData(Request $request): array
    {
        $selectedType = $request->input('type');
        $validType = SourceType::tryFrom((string) $selectedType);

        $query = Source::with(['scrapeLogs' => function ($q) {
            $q->take(5);
        }])->withCount('events');

        if ($validType) {
            $query->where('type', $validType->value);
        }

        $sources = $query->orderBy('name')->get();

        $typeCounts = Source::query()
            ->selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->all();

        $totalCount = array_sum($typeCounts);
        $types = SourceType::cases();

        $today = now('Europe/Riga')->toDateString();
        $selectedDate = (string) $request->input('log_date', $today);
        $selectedStatus = $request->input('status');

        return compact(
            'sources',
            'selectedType',
            'typeCounts',
            'totalCount',
            'types',
            'selectedDate',
            'selectedStatus'
        );
    }

    /**
     * Helper to compute system health, process statuses and pending images count
     */
    protected function getSystemStatusData(SystemProcessService $processService, ?string $flashStatus = null, ?string $flashError = null): array
    {
        $diskSpace = $processService->getDiskSpace();
        $processStatuses = $processService->getAllStatuses();
        $pendingImagesCount = $this->getPendingImagesCount();

        return compact(
            'diskSpace',
            'processStatuses',
            'pendingImagesCount',
            'flashStatus',
            'flashError'
        );
    }

    /**
     * Calculate count of upcoming events needing Hetzner S3 image upload
     */
    protected function getPendingImagesCount(): int
    {
        try {
            $todayStart = now()->startOfDay();
            return \App\Models\Event::query()
                ->withoutTrashed()
                ->whereNotIn('status', ['deleted', 'cancelled'])
                ->whereNotNull('image_url')
                ->where('image_url', '!=', '')
                ->where('image_url', 'not like', '%aplis-default-og-img.jpg%')
                ->where(function ($q) use ($todayStart) {
                    $q->where('start_at', '>=', $todayStart)
                      ->orWhere(function ($sub) {
                          $sub->whereNotNull('end_at')->where('end_at', '>=', now());
                      });
                })
                ->where(function ($q) {
                    $q->whereNull('internal_image_url')
                      ->orWhere('internal_image_url', '');
                })
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Helper to compute daily history logs, available dates and day metrics
     */
    protected function getHistoryData(Request $request): array
    {
        $today = now('Europe/Riga')->toDateString();

        // Get available log dates (last 14 days where logs exist)
        $availableDates = ScrapeLog::query()
            ->selectRaw('DATE(started_at) as log_date, count(*) as total_runs, sum(case when status = "failed" then 1 else 0 end) as failed_runs')
            ->groupBy('log_date')
            ->orderByDesc('log_date')
            ->take(14)
            ->get();

        if (!$availableDates->contains('log_date', $today)) {
            $availableDates->prepend((object) [
                'log_date' => $today,
                'total_runs' => 0,
                'failed_runs' => 0,
            ]);
        }

        $selectedDate = (string) $request->input('log_date', $today);
        $selectedStatus = $request->input('status'); // all, started, success, partial, failed
        $selectedType = $request->input('type');

        $logsQuery = ScrapeLog::with('source')
            ->whereDate('started_at', $selectedDate)
            ->orderByDesc('started_at');

        if ($selectedStatus && in_array($selectedStatus, ['started', 'running', 'success', 'partial', 'failed'], true)) {
            if ($selectedStatus === 'started') {
                $logsQuery->whereIn('status', ['started', 'running']);
            } else {
                $logsQuery->where('status', $selectedStatus);
            }
        }

        $dayLogs = $logsQuery->paginate(50)->withQueryString();

        // Overall stats for the selected date
        $allDayLogs = ScrapeLog::whereDate('started_at', $selectedDate)->get();
        $dayStats = [
            'total' => $allDayLogs->count(),
            'success' => $allDayLogs->where('status', 'success')->count(),
            'partial' => $allDayLogs->where('status', 'partial')->count(),
            'failed' => $allDayLogs->where('status', 'failed')->count(),
            'started' => $allDayLogs->filter(fn ($l) => in_array($l->status, ['started', 'running'], true))->count(),
            'items_found' => (int) $allDayLogs->sum('items_found'),
            'items_created' => (int) $allDayLogs->sum('items_created'),
            'items_updated' => (int) $allDayLogs->sum('items_updated'),
            'total_duration' => round((float) $allDayLogs->sum('duration_seconds'), 1),
        ];

        return compact(
            'availableDates',
            'selectedDate',
            'selectedStatus',
            'selectedType',
            'dayLogs',
            'dayStats'
        );
    }

    /**
     * Trigger immediate scraper execution via HTMX or standard POST
     */
    public function scrape(Source $source, EventIngestionService $service, Request $request)
    {
        @set_time_limit(300);

        $log = $service->ingest($source);

        // Pre-warm fresh sitemap whenever events are created, updated, or scrape succeeds
        if ($log->items_created > 0 || $log->items_updated > 0 || $log->isSuccess()) {
            try {
                app(\App\Services\SitemapService::class)->refresh();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Sitemap refresh failed after admin scrape: " . $e->getMessage());
            }
        }

        if ($request->header('HX-Request')) {
            if ($request->header('HX-Target') === 'scrape-history-section') {
                $historyData = $this->getHistoryData($request);
                return view('admin.sources.partials.scrape-history', $historyData);
            }

            $loopIndex = $request->input('loop_index');
            return view('admin.sources.partials.source-card', [
                'source' => $source->fresh(['scrapeLogs']),
                'loopIndex' => $loopIndex,
            ]);
        }

        $statusMsg = "Avota '{$source->name}' parsēšana pabeigta! Statuss: {$log->statusLabel()} (Atrasti: {$log->items_found}, Izveidoti: +{$log->items_created}, Atjaunoti: {$log->items_updated}, Ilgums: {$log->getFormattedDuration()})";
        if ($log->isFailed() && $log->getPrimaryError()) {
            $statusMsg .= " — Kļūda: " . $log->getPrimaryError();
        }

        return redirect()->back()->with($log->isFailed() ? 'error' : 'status', $statusMsg);
    }

    /**
     * Trigger manual translations synchronization (LV/EN/RU)
     */
    public function syncTranslations(Request $request, SystemProcessService $processService)
    {
        @set_time_limit(300);

        if ($processService->isRunning(SystemProcessService::PROCESS_SYNC_TRANSLATIONS)) {
            $status = $processService->getStatus(SystemProcessService::PROCESS_SYNC_TRANSLATIONS);
            $msg = "⚠️ Tulkojumu skripts pašlaik jau tiek izpildīts (sākts {$status['started_for_humans']})! Lūdzu uzgaidiet, kamēr tas pabeidz darbu.";
            if ($request->header('HX-Request')) {
                return view('admin.sources.partials.system-status', $this->getSystemStatusData($processService, null, $msg));
            }
            return redirect()->back()->with('error', $msg);
        }

        try {
            \Illuminate\Support\Facades\Artisan::call('events:sync-translations', ['--limit' => 50]);
            $output = trim(\Illuminate\Support\Facades\Artisan::output());
            $cleanOutput = \Illuminate\Support\Str::limit(strip_tags($output), 250);

            // Pre-warm fresh sitemap
            app(\App\Services\SitemapService::class)->refresh();

            $msg = '✅ Tulkojumu sinhronizācija veiksmīgi pabeigta! ' . ($cleanOutput ? "({$cleanOutput})" : '');
            if ($request->header('HX-Request')) {
                return view('admin.sources.partials.system-status', $this->getSystemStatusData($processService, $msg, null));
            }
            return redirect()->back()->with('status', $msg);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Admin syncTranslations failed: ' . $e->getMessage());
            $msg = 'Tulkojumu sinhronizācijas kļūda: ' . $e->getMessage();
            if ($request->header('HX-Request')) {
                return view('admin.sources.partials.system-status', $this->getSystemStatusData($processService, null, $msg));
            }
            return redirect()->back()->with('error', $msg);
        }
    }

    /**
     * Trigger manual event images upload to Hetzner S3
     */
    public function syncImages(Request $request, SystemProcessService $processService)
    {
        @set_time_limit(300);

        if ($processService->isRunning(SystemProcessService::PROCESS_SYNC_IMAGES)) {
            $status = $processService->getStatus(SystemProcessService::PROCESS_SYNC_IMAGES);
            $msg = "⚠️ Attēlu sinhronizācija uz Hetzner S3 pašlaik jau tiek izpildīta (sākta {$status['started_for_humans']})! Lūdzu uzgaidiet, kamēr tā pabeidz darbu.";
            if ($request->header('HX-Request')) {
                return view('admin.sources.partials.system-status', $this->getSystemStatusData($processService, null, $msg));
            }
            return redirect()->back()->with('error', $msg);
        }

        try {
            \Illuminate\Support\Facades\Artisan::call('events:sync-images', ['--limit' => 50]);
            $output = trim(\Illuminate\Support\Facades\Artisan::output());
            $cleanOutput = \Illuminate\Support\Str::limit(strip_tags($output), 250);

            // Pre-warm fresh sitemap
            app(\App\Services\SitemapService::class)->refresh();

            $msg = '✅ Attēlu sinhronizācija uz Hetzner S3 veiksmīgi pabeigta! ' . ($cleanOutput ? "({$cleanOutput})" : '');
            if ($request->header('HX-Request')) {
                return view('admin.sources.partials.system-status', $this->getSystemStatusData($processService, $msg, null));
            }
            return redirect()->back()->with('status', $msg);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Admin syncImages failed: ' . $e->getMessage());
            $msg = 'Attēlu sinhronizācijas kļūda: ' . $e->getMessage();
            if ($request->header('HX-Request')) {
                return view('admin.sources.partials.system-status', $this->getSystemStatusData($processService, null, $msg));
            }
            return redirect()->back()->with('error', $msg);
        }
    }

    /**
     * Trigger manual sitemap regeneration
     */
    public function refreshSitemap(Request $request)
    {
        try {
            $xml = app(\App\Services\SitemapService::class)->refresh();
            $urlCount = substr_count($xml, '<url>');

            $msg = "✅ Sitemap.xml veiksmīgi uzģenerēta un saglabāta kešatmiņā ({$urlCount} adreses indeksētas)!";
            if ($request->header('HX-Request')) {
                return view('admin.sources.partials.system-status', $this->getSystemStatusData(app(SystemProcessService::class), $msg, null));
            }
            return redirect()->back()->with('status', $msg);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Admin refreshSitemap failed: ' . $e->getMessage());
            $msg = 'Sitemap ģenerēšanas kļūda: ' . $e->getMessage();
            if ($request->header('HX-Request')) {
                return view('admin.sources.partials.system-status', $this->getSystemStatusData(app(SystemProcessService::class), null, $msg));
            }
            return redirect()->back()->with('error', $msg);
        }
    }

    /**
     * Synchronize sources registry from code (SourceSeeder)
     */
    public function syncRegistry(Request $request)
    {
        try {
            $seeder = new \Database\Seeders\SourceSeeder();
            $seeder->run();
            $count = Source::count();

            $msg = "✅ Avotu reģistrs veiksmīgi sinhronizēts ar kodu! Sistēmā reģistrēti {$count} avoti.";
            if ($request->header('HX-Request')) {
                $sourcesData = $this->getSourcesData($request);
                return view('admin.sources.partials.sources-registry', array_merge($sourcesData, [
                    'registryStatus' => $msg,
                ]));
            }

            return redirect()->route('admin.sources.index')->with('status', $msg);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Admin syncRegistry failed: ' . $e->getMessage());
            $msg = 'Avotu sinhronizācijas kļūda: ' . $e->getMessage();
            if ($request->header('HX-Request')) {
                $sourcesData = $this->getSourcesData($request);
                return view('admin.sources.partials.sources-registry', array_merge($sourcesData, [
                    'registryError' => $msg,
                ]));
            }

            return redirect()->back()->with('error', $msg);
        }
    }
}

