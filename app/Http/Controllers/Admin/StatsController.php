<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventAction;
use App\Models\Source;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StatsController extends Controller
{
    /**
     * Display the Admin Statistics Dashboard:
     * 1) Time-series comparison of Sodiena DB events vs Afiro catalog with linear regression trendlines.
     * 2) Outbound click tracking metrics broken down weekly and by destination domain.
     */
    public function index(Request $request): View
    {
        $days = (int) $request->input('days', 30);
        if (!in_array($days, [14, 30, 60, 90, 180], true)) {
            $days = 30;
        }

        // ==========================================
        // 1. TIME-SERIES DATA: SODIENA vs AFIRO
        // ==========================================
        $startDate = now()->subDays($days - 1)->startOfDay();

        // Historical cumulative Sodiena events count
        $initialTotalEvents = Event::withTrashed()->where('created_at', '<', $startDate)->count();
        $initialActiveEvents = Event::where('created_at', '<', $startDate)->count();

        $dailyCreated = Event::withTrashed()
            ->where('created_at', '>=', $startDate)
            ->selectRaw('DATE(created_at) as created_date, COUNT(*) as count')
            ->groupBy('created_date')
            ->pluck('count', 'created_date');

        $dailyActiveCreated = Event::where('created_at', '>=', $startDate)
            ->selectRaw('DATE(created_at) as created_date, COUNT(*) as count')
            ->groupBy('created_date')
            ->pluck('count', 'created_date');

        // Historical Afiro returned events from scrape_logs
        $afiroLogs = DB::table('scrape_logs')
            ->join('sources', 'sources.id', '=', 'scrape_logs.source_id')
            ->whereIn('sources.slug', ['afiro-api', 'afiro'])
            ->where('scrape_logs.created_at', '>=', $startDate)
            ->selectRaw('DATE(scrape_logs.created_at) as log_date, MAX(scrape_logs.items_found) as found')
            ->groupBy('log_date')
            ->pluck('found', 'log_date');

        $dateLabels = [];
        $sodienaTotalSeries = [];
        $sodienaActiveSeries = [];
        $afiroSeries = [];

        $runningTotal = $initialTotalEvents;
        $runningActive = $initialActiveEvents;
        $lastKnownAfiro = null;

        // Build continuous daily sequence
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dateFormatted = now()->subDays($i)->format('d.m');
            $dateLabels[] = $dateFormatted;

            $runningTotal += ($dailyCreated[$date] ?? 0);
            $runningActive += ($dailyActiveCreated[$date] ?? 0);

            $sodienaTotalSeries[] = $runningTotal;
            $sodienaActiveSeries[] = $runningActive;

            if (isset($afiroLogs[$date]) && $afiroLogs[$date] > 0) {
                $lastKnownAfiro = (int) $afiroLogs[$date];
                $afiroSeries[] = $lastKnownAfiro;
            } else {
                // If scraping wasn't run on that day, retain last known catalog size
                $afiroSeries[] = $lastKnownAfiro;
            }
        }

        // Calculate Linear Regression Trendlines
        $sodienaTrend = $this->calculateLinearRegression($sodienaTotalSeries);
        $afiroTrend = $this->calculateLinearRegression(array_filter($afiroSeries, fn($v) => !is_null($v)));

        // Summary Metric Cards
        $latestSodienaTotal = end($sodienaTotalSeries) ?: 0;
        $latestSodienaActive = end($sodienaActiveSeries) ?: 0;
        $latestAfiro = end($afiroSeries) ?: 0;
        $ratio = $latestAfiro > 0 ? round($latestSodienaTotal / $latestAfiro, 1) : null;
        $activeRatio = $latestAfiro > 0 ? round($latestSodienaActive / $latestAfiro, 1) : null;

        // ==========================================
        // 2. OUTBOUND CLICK TRACKING (WEEKLY BY DOMAIN)
        // ==========================================
        $weeksBack = 8;
        $clicksStartDate = now()->subWeeks($weeksBack - 1)->startOfWeek();

        $actions = EventAction::with('event:id,title,slug')
            ->where('created_at', '>=', $clicksStartDate)
            ->orderBy('created_at', 'desc')
            ->get();

        $totalClicks = $actions->count();
        $ticketClicks = $actions->where('action_type', 'ticket')->count();
        $websiteClicks = $actions->where('action_type', 'website')->count();

        // Build list of recent weeks (e.g. "47. nedēļa (17.11 - 23.11)")
        $weekBuckets = [];
        $weekLabels = [];
        for ($w = $weeksBack - 1; $w >= 0; $w--) {
            $weekStart = now()->subWeeks($w)->startOfWeek();
            $weekEnd = (clone $weekStart)->endOfWeek();
            $weekKey = $weekStart->format('o-W');
            $label = $weekStart->isoWeek() . '. ned. (' . $weekStart->format('d.m') . ' - ' . $weekEnd->format('d.m') . ')';

            $weekBuckets[$weekKey] = [
                'key' => $weekKey,
                'label' => $label,
                'total' => 0,
            ];
            $weekLabels[$weekKey] = $label;
        }

        // Domain breakdown
        $domainStats = [];
        foreach ($actions as $action) {
            $domain = $action->target_domain ?: 'cits/nezināms';
            $actionWeek = Carbon::parse($action->created_at)->startOfWeek()->format('o-W');

            if (!isset($domainStats[$domain])) {
                $domainStats[$domain] = [
                    'domain' => $domain,
                    'total' => 0,
                    'ticket' => 0,
                    'website' => 0,
                    'desktop' => 0,
                    'mobile' => 0,
                    'tablet' => 0,
                    'weekly' => array_fill_keys(array_keys($weekBuckets), 0),
                ];
            }

            $domainStats[$domain]['total']++;
            if ($action->action_type === 'ticket') {
                $domainStats[$domain]['ticket']++;
            } else {
                $domainStats[$domain]['website']++;
            }

            $device = in_array($action->device, ['desktop', 'mobile', 'tablet'], true) ? $action->device : 'desktop';
            $domainStats[$domain][$device]++;

            if (isset($domainStats[$domain]['weekly'][$actionWeek])) {
                $domainStats[$domain]['weekly'][$actionWeek]++;
            }
            if (isset($weekBuckets[$actionWeek])) {
                $weekBuckets[$actionWeek]['total']++;
            }
        }

        // Sort domains by total clicks descending
        uasort($domainStats, fn($a, $b) => $b['total'] <=> $a['total']);

        // Recent 20 click records for audit log
        $recentClicks = $actions->take(20);

        return view('admin.stats.index', [
            'days' => $days,
            'dateLabels' => $dateLabels,
            'sodienaTotalSeries' => $sodienaTotalSeries,
            'sodienaActiveSeries' => $sodienaActiveSeries,
            'sodienaTrend' => $sodienaTrend,
            'afiroSeries' => $afiroSeries,
            'afiroTrend' => $afiroTrend,
            'latestSodienaTotal' => $latestSodienaTotal,
            'latestSodienaActive' => $latestSodienaActive,
            'latestAfiro' => $latestAfiro,
            'ratio' => $ratio,
            'activeRatio' => $activeRatio,
            // Outbound click metrics
            'totalClicks' => $totalClicks,
            'ticketClicks' => $ticketClicks,
            'websiteClicks' => $websiteClicks,
            'weekBuckets' => $weekBuckets,
            'domainStats' => $domainStats,
            'recentClicks' => $recentClicks,
        ]);
    }

    /**
     * Compute Ordinary Least Squares Linear Regression (y = mx + b)
     */
    private function calculateLinearRegression(array $series): array
    {
        $cleanSeries = array_values($series);
        $n = count($cleanSeries);

        if ($n < 2) {
            $val = $cleanSeries[0] ?? 0;
            return [
                'slope' => 0,
                'intercept' => $val,
                'points' => array_fill(0, max(1, $n), $val),
                'daily_growth' => 0,
                'direction' => 'flat',
            ];
        }

        $sumX = 0;
        $sumY = 0;
        $sumXY = 0;
        $sumXX = 0;

        foreach ($cleanSeries as $x => $y) {
            $y = (float) $y;
            $sumX += $x;
            $sumY += $y;
            $sumXY += ($x * $y);
            $sumXX += ($x * $x);
        }

        $denom = ($n * $sumXX) - ($sumX * $sumX);
        $slope = $denom != 0 ? (($n * $sumXY) - ($sumX * $sumY)) / $denom : 0;
        $intercept = ($sumY - ($slope * $sumX)) / $n;

        $trendPoints = [];
        for ($i = 0; $i < $n; $i++) {
            $trendPoints[] = round($slope * $i + $intercept, 1);
        }

        $dailyGrowth = round($slope, 1);
        $direction = $slope > 0.1 ? 'up' : ($slope < -0.1 ? 'down' : 'flat');

        return [
            'slope' => round($slope, 2),
            'intercept' => round($intercept, 2),
            'points' => $trendPoints,
            'daily_growth' => $dailyGrowth,
            'direction' => $direction,
        ];
    }
}
