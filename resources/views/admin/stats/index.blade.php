@extends('layouts.admin')

@section('title', 'Statistika & Izejošie klikšķi :: Šodiena.lv Admin')

@section('content')
<div class="px-4 py-6 sm:px-6 lg:px-8 max-w-7xl mx-auto space-y-6">

    <!-- Header Section -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 pb-2 border-b border-slate-200">
        <div>
            <div class="flex items-center gap-2 text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">
                <a href="{{ route('admin.events.index') }}" class="hover:text-slate-800 transition-colors">Admin</a>
                <span>/</span>
                <span class="text-slate-700 flex items-center gap-1">
                    <i data-lucide="trending-up" class="w-3.5 h-3.5 text-sky-600"></i>
                    Statistika & Klikšķi
                </span>
            </div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight flex items-center gap-2.5">
                <i data-lucide="trending-up" class="w-7 h-7 text-sky-600"></i>
                Plānoto pasākumu & Klikšķu statistika
            </h1>
            <p class="text-sm text-slate-600 mt-1">
                Sodiena.lv un Afiro plānoto (aktīvo) pasākumu salīdzinājums laikā, lineārie trendi un izejošo biļešu saišu analītika
            </p>
        </div>

        <!-- Time Range Selector -->
        <div class="flex items-center gap-1.5 bg-white p-1 rounded-xl border border-slate-200 shadow-xs">
            @foreach([14 => '14 dienas', 30 => '30 dienas', 60 => '60 dienas', 90 => '90 dienas'] as $rangeDays => $rangeLabel)
                <a href="{{ route('admin.stats.index', ['days' => $rangeDays]) }}" 
                   class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all {{ $days === $rangeDays ? 'bg-[#002855] text-white shadow-xs' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100' }}">
                    {{ $rangeLabel }}
                </a>
            @endforeach
        </div>
    </div>

    <!-- 4 KPI Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        
        <!-- Card 1: Sodiena Planned Events -->
        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs relative overflow-hidden">
            <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-sky-500 to-blue-600"></div>
            <div class="flex items-start justify-between">
                <div>
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Sodiena.lv Plānotie</span>
                    <div class="text-2xl font-black text-slate-900 mt-1 tracking-tight">
                        {{ number_format($latestSodienaPlanned, 0, '.', ' ') }}
                    </div>
                </div>
                <div class="w-10 h-10 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center">
                    <i data-lucide="calendar-check-2" class="w-5 h-5"></i>
                </div>
            </div>
            <div class="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
                <span class="text-slate-500 font-medium" title="Kopējais visu laiku ierakstu skaits datubāzē">
                    Arhīvs: <strong>{{ number_format($totalEverCreated, 0, '.', ' ') }}</strong>
                </span>
                <span class="inline-flex items-center gap-1 font-bold {{ $sodienaTrend['daily_growth'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                    <i data-lucide="{{ $sodienaTrend['daily_growth'] >= 0 ? 'trending-up' : 'trending-down' }}" class="w-3.5 h-3.5"></i>
                    {{ $sodienaTrend['daily_growth'] >= 0 ? '+' : '' }}{{ $sodienaTrend['daily_growth'] }} / dienā
                </span>
            </div>
        </div>

        <!-- Card 2: Afiro Planned Events -->
        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs relative overflow-hidden">
            <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-purple-500 to-indigo-600"></div>
            <div class="flex items-start justify-between">
                <div>
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Afiro Katalogs</span>
                    <div class="text-2xl font-black text-slate-900 mt-1 tracking-tight">
                        {{ $latestAfiro ? number_format($latestAfiro, 0, '.', ' ') : '—' }}
                    </div>
                </div>
                <div class="w-10 h-10 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center">
                    <i data-lucide="layers" class="w-5 h-5"></i>
                </div>
            </div>
            <div class="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
                <span class="text-slate-500 font-medium">Aktīvie API pasākumi</span>
                <span class="inline-flex items-center gap-1 font-bold {{ $afiroTrend['daily_growth'] >= 0 ? 'text-emerald-600' : 'text-slate-500' }}">
                    <i data-lucide="{{ $afiroTrend['daily_growth'] >= 0 ? 'trending-up' : 'trending-down' }}" class="w-3.5 h-3.5"></i>
                    {{ $afiroTrend['daily_growth'] >= 0 ? '+' : '' }}{{ $afiroTrend['daily_growth'] }} / dienā
                </span>
            </div>
        </div>

        <!-- Card 3: Ratio Comparison -->
        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs relative overflow-hidden">
            <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-emerald-500 to-teal-600"></div>
            <div class="flex items-start justify-between">
                <div>
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Apjoma Attiecība</span>
                    <div class="text-2xl font-black text-slate-900 mt-1 tracking-tight">
                        @if($ratio)
                            {{ $ratio }}× <span class="text-sm font-semibold text-slate-500">vairāk</span>
                        @else
                            —
                        @endif
                    </div>
                </div>
                <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                    <i data-lucide="scale" class="w-5 h-5"></i>
                </div>
            </div>
            <div class="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
                <span class="text-slate-500 font-medium">Salīdzinot plānotos</span>
                <span class="text-emerald-600 font-bold bg-emerald-50 px-2 py-0.5 rounded-full">
                    Sodiena dominē
                </span>
            </div>
        </div>

        <!-- Card 4: Outbound Clicks -->
        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs relative overflow-hidden">
            <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-amber-500 to-orange-600"></div>
            <div class="flex items-start justify-between">
                <div>
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Izejošie Klikšķi</span>
                    <div class="text-2xl font-black text-slate-900 mt-1 tracking-tight">
                        {{ number_format($totalClicks, 0, '.', ' ') }}
                    </div>
                </div>
                <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                    <i data-lucide="mouse-pointer-click" class="w-5 h-5"></i>
                </div>
            </div>
            <div class="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
                <span class="text-slate-600 font-medium">Biļetes: <strong>{{ $ticketClicks }}</strong></span>
                <span class="text-slate-600 font-medium">Vietne: <strong>{{ $websiteClicks }}</strong></span>
            </div>
        </div>

    </div>

    <!-- MAIN CHART: Sodiena Planned vs Afiro Planned with Trendlines -->
    <div class="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-xs">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-slate-100">
            <div>
                <h2 class="text-lg font-black text-slate-900 tracking-tight flex items-center gap-2">
                    <i data-lucide="line-chart" class="w-5 h-5 text-sky-600"></i>
                    Plānoto pasākumu apjoms un lineārais trends laikā
                </h2>
                <p class="text-xs text-slate-500 mt-0.5">
                    Sodiena.lv plānotie (vēl nenobeigušies) pasākumi pret Afiro API aktīvo apjomu pēdējās {{ $days }} dienās
                </p>
            </div>

            <!-- Chart Display Toggles -->
            <div class="flex flex-wrap items-center gap-3 text-xs">
                <label class="flex items-center gap-1.5 cursor-pointer font-medium text-slate-700 hover:text-slate-900">
                    <input type="checkbox" id="toggleSodienaTrend" checked class="rounded border-slate-300 text-sky-600 focus:ring-sky-500">
                    <span class="flex items-center gap-1">
                        <span class="inline-block w-3 h-0.5 border-t-2 border-dashed border-[#002855]"></span>
                        Sodiena trends ({{ $sodienaTrend['daily_growth'] >= 0 ? '+' : '' }}{{ $sodienaTrend['daily_growth'] }}/d)
                    </span>
                </label>
                <label class="flex items-center gap-1.5 cursor-pointer font-medium text-slate-700 hover:text-slate-900">
                    <input type="checkbox" id="toggleAfiroTrend" checked class="rounded border-slate-300 text-purple-600 focus:ring-purple-500">
                    <span class="flex items-center gap-1">
                        <span class="inline-block w-3 h-0.5 border-t-2 border-dashed border-[#c084fc]"></span>
                        Afiro trends ({{ $afiroTrend['daily_growth'] >= 0 ? '+' : '' }}{{ $afiroTrend['daily_growth'] }}/d)
                    </span>
                </label>
                <label class="flex items-center gap-1.5 cursor-pointer font-medium text-slate-700 hover:text-slate-900">
                    <input type="checkbox" id="toggleArchiveSeries" class="rounded border-slate-300 text-slate-500 focus:ring-slate-500">
                    <span class="flex items-center gap-1">
                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-slate-400"></span>
                        Visi ieraksti (arī beigušies)
                    </span>
                </label>
            </div>
        </div>

        <!-- Chart Container -->
        <div class="mt-5 relative w-full h-80 sm:h-96">
            <canvas id="growthChart"></canvas>
        </div>

        <div class="mt-4 pt-3 border-t border-slate-100 flex flex-wrap items-center justify-between text-xs text-slate-500 gap-2">
            <div class="flex items-center gap-4">
                <span class="flex items-center gap-1.5">
                    <span class="w-3 h-3 rounded-full bg-sky-500 inline-block"></span>
                    Sodiena.lv plānotie pasākumi
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="w-3 h-3 rounded-full bg-purple-500 inline-block"></span>
                    Afiro API aktīvie pasākumi
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="w-3 h-0.5 border-t-2 border-dashed border-slate-600 inline-block"></span>
                    Lineārā regresija (\(y = mx + b\))
                </span>
            </div>
            <div class="text-slate-400">
                1:1 salīdzinājums: tiek rēķināti tikai pasākumi, kas konkrētajā datumā vēl nebija beigušies
            </div>
        </div>
    </div>

    <!-- OUTBOUND CLICKS SECTION: WEEKLY BREAKDOWN BY DOMAIN -->
    <div class="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-xs space-y-5">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 pb-3 border-b border-slate-100">
            <div>
                <h2 class="text-lg font-black text-slate-900 tracking-tight flex items-center gap-2">
                    <i data-lucide="navigation" class="w-5 h-5 text-amber-500"></i>
                    Izejošie klikšķi pa nedēļām un domēniem
                </h2>
                <p class="text-xs text-slate-500 mt-0.5">
                    Lietotāju klikšķi uz "Pirkt Biļeti" un "Oficiālā pasākuma vietne" pogām, sagrupēti pa izejošajiem domēniem
                </p>
            </div>
            <div class="flex items-center gap-2 text-xs">
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-sky-50 text-sky-700 font-bold">
                    <i data-lucide="ticket" class="w-3.5 h-3.5"></i> Biļešu pogas: {{ $ticketClicks }}
                </span>
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-100 text-slate-700 font-bold">
                    <i data-lucide="globe" class="w-3.5 h-3.5"></i> Vietnes pogas: {{ $websiteClicks }}
                </span>
            </div>
        </div>

        @if(empty($domainStats))
            <div class="text-center py-12 px-4 rounded-xl bg-slate-50 border border-dashed border-slate-200">
                <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-500 flex items-center justify-center mx-auto mb-3">
                    <i data-lucide="mouse-pointer-click" class="w-6 h-6"></i>
                </div>
                <h3 class="text-sm font-bold text-slate-800">Pagaidām nav fiksētu izejošo klikšķu</h3>
                <p class="text-xs text-slate-500 max-w-md mx-auto mt-1">
                    Klikšķu uzskaite ar <code>navigator.sendBeacon()</code> un UTM parametriem ir aktivizēta. Tiklīdz apmeklētāji klikšķinās uz biļešu saitēm, statistika parādīsies šeit.
                </p>
            </div>
        @else
            <!-- Domain Stats Table with Weekly Matrix -->
            <div class="overflow-x-auto rounded-xl border border-slate-200">
                <table class="w-full text-left text-xs divide-y divide-slate-200">
                    <thead class="bg-slate-50 text-slate-600 font-bold uppercase tracking-wider">
                        <tr>
                            <th scope="col" class="py-3 px-4">Izejošais Domēns</th>
                            <th scope="col" class="py-3 px-3 text-right">Kopā</th>
                            <th scope="col" class="py-3 px-3 text-right">Biļetes</th>
                            <th scope="col" class="py-3 px-3 text-right">Vietne</th>
                            <th scope="col" class="py-3 px-3 text-center">Ierīces (D/M/T)</th>
                            @foreach($weekBuckets as $wKey => $wData)
                                <th scope="col" class="py-3 px-2 text-center text-[10px] whitespace-nowrap" title="{{ $wData['label'] }}">
                                    {{ $wData['label'] }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white font-medium text-slate-700">
                        @foreach($domainStats as $domain => $stat)
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <td class="py-3 px-4 font-bold text-slate-900 flex items-center gap-2">
                                    <div class="w-2 h-2 rounded-full {{ str_contains($domain, 'bilesu') ? 'bg-amber-500' : (str_contains($domain, 'kino') ? 'bg-purple-500' : 'bg-sky-500') }}"></div>
                                    <span>{{ $domain }}</span>
                                </td>
                                <td class="py-3 px-3 text-right font-black text-slate-900">
                                    {{ number_format($stat['total'], 0, '.', ' ') }}
                                </td>
                                <td class="py-3 px-3 text-right text-amber-700 font-bold">
                                    {{ $stat['ticket'] }}
                                </td>
                                <td class="py-3 px-3 text-right text-sky-700 font-bold">
                                    {{ $stat['website'] }}
                                </td>
                                <td class="py-3 px-3 text-center text-slate-500 text-[11px] whitespace-nowrap">
                                    <span title="Desktop" class="font-bold text-slate-700">{{ $stat['desktop'] }}</span> /
                                    <span title="Mobile" class="font-bold text-slate-700">{{ $stat['mobile'] }}</span> /
                                    <span title="Tablet" class="text-slate-400">{{ $stat['tablet'] }}</span>
                                </td>
                                @foreach($weekBuckets as $wKey => $wData)
                                    @php $wCount = $stat['weekly'][$wKey] ?? 0; @endphp
                                    <td class="py-3 px-2 text-center text-[11px]">
                                        @if($wCount > 0)
                                            <span class="inline-block px-2 py-0.5 rounded-md font-bold bg-sky-100 text-sky-900">
                                                {{ $wCount }}
                                            </span>
                                        @else
                                            <span class="text-slate-300">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-slate-50 font-black text-slate-900 border-t-2 border-slate-200">
                        <tr>
                            <td class="py-3 px-4">Kopā pa nedēļām:</td>
                            <td class="py-3 px-3 text-right text-slate-900 font-black">{{ $totalClicks }}</td>
                            <td class="py-3 px-3 text-right text-amber-800">{{ $ticketClicks }}</td>
                            <td class="py-3 px-3 text-right text-sky-800">{{ $websiteClicks }}</td>
                            <td class="py-3 px-3 text-center text-slate-400">—</td>
                            @foreach($weekBuckets as $wKey => $wData)
                                <td class="py-3 px-2 text-center text-[11px]">
                                    @if($wData['total'] > 0)
                                        <span class="font-extrabold text-[#002855]">{{ $wData['total'] }}</span>
                                    @else
                                        <span class="text-slate-300">0</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Recent Clicks Audit Trail -->
            @if($recentClicks->isNotEmpty())
                <div class="mt-6 pt-4 border-t border-slate-100">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-3 flex items-center gap-1.5">
                        <i data-lucide="history" class="w-4 h-4 text-slate-400"></i>
                        Pēdējie fiksētie klikšķi (Audit Trail)
                    </h3>
                    <div class="overflow-x-auto rounded-xl border border-slate-200">
                        <table class="w-full text-left text-xs divide-y divide-slate-100">
                            <thead class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider">
                                <tr>
                                    <th class="py-2.5 px-3">Laiks</th>
                                    <th class="py-2.5 px-3">Pasākums</th>
                                    <th class="py-2.5 px-3">Darbība</th>
                                    <th class="py-2.5 px-3">Izejošais Domēns / Mērķis</th>
                                    <th class="py-2.5 px-3 text-center">Ierīce</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white text-slate-700 font-medium">
                                @foreach($recentClicks as $act)
                                    <tr class="hover:bg-slate-50/60">
                                        <td class="py-2 px-3 whitespace-nowrap text-slate-500">
                                            {{ $act->created_at ? $act->created_at->format('d.m.Y H:i') : '—' }}
                                        </td>
                                        <td class="py-2 px-3 font-semibold text-slate-900 max-w-xs truncate" title="{{ $act->event?->title }}">
                                            @if($act->event)
                                                <a href="{{ route('events.show', $act->event->slug) }}" target="_blank" class="hover:text-sky-600 transition-colors">
                                                    {{ $act->event->title }}
                                                </a>
                                            @else
                                                <span class="text-slate-400">#{{ $act->event_id }}</span>
                                            @endif
                                        </td>
                                        <td class="py-2 px-3 whitespace-nowrap">
                                            @if($act->action_type === 'ticket')
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 text-amber-800">
                                                    <i data-lucide="ticket" class="w-3 h-3"></i> Biļete
                                                </span>
                                            @else
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-bold bg-sky-100 text-sky-800">
                                                    <i data-lucide="globe" class="w-3 h-3"></i> Vietne
                                                </span>
                                            @endif
                                        </td>
                                        <td class="py-2 px-3 font-mono text-[11px] text-slate-600 max-w-sm truncate" title="{{ $act->url }}">
                                            <span class="font-bold text-slate-800">{{ $act->target_domain }}</span>
                                        </td>
                                        <td class="py-2 px-3 text-center whitespace-nowrap">
                                            <span class="inline-block px-1.5 py-0.5 rounded bg-slate-100 text-slate-600 text-[10px] uppercase font-bold">
                                                {{ $act->device ?: 'desktop' }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        @endif
    </div>

</div>
@endsection

@push('scripts')
<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const ctx = document.getElementById('growthChart');
    if (!ctx) return;

    const labels = @json($dateLabels);
    const sodienaPlanned = @json($sodienaPlannedSeries);
    const sodienaTrend = @json($sodienaTrend['points']);
    const afiroData = @json($afiroSeries);
    const afiroTrend = @json($afiroTrend['points']);
    const sodienaArchive = @json($sodienaArchiveSeries);

    // Build Chart
    const growthChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Sodiena.lv (plānotie)',
                    data: sodienaPlanned,
                    borderColor: '#0284c7',
                    backgroundColor: 'rgba(2, 132, 199, 0.08)',
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.25,
                    pointRadius: 2.5,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#0284c7',
                },
                {
                    label: 'Sodiena trends',
                    data: sodienaTrend,
                    borderColor: '#002855',
                    borderWidth: 2,
                    borderDash: [6, 6],
                    pointRadius: 0,
                    fill: false,
                    tension: 0,
                },
                {
                    label: 'Afiro katalogs (plānotie)',
                    data: afiroData,
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139, 92, 246, 0.05)',
                    borderWidth: 2.5,
                    fill: false,
                    tension: 0.25,
                    pointRadius: 2.5,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#8b5cf6',
                },
                {
                    label: 'Afiro trends',
                    data: afiroTrend,
                    borderColor: '#c084fc',
                    borderWidth: 2,
                    borderDash: [6, 6],
                    pointRadius: 0,
                    fill: false,
                    tension: 0,
                },
                {
                    label: 'Visi ieraksti (arī beigušies)',
                    data: sodienaArchive,
                    borderColor: '#94a3b8',
                    borderWidth: 1.5,
                    borderDash: [3, 3],
                    fill: false,
                    tension: 0.1,
                    pointRadius: 0,
                    hidden: true, // Hidden by default, toggled via checkbox
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    display: false, // Using custom toggles and footer
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.95)',
                    padding: 12,
                    titleFont: { size: 13, weight: 'bold' },
                    bodyFont: { size: 12 },
                    boxPadding: 4,
                    usePointStyle: true,
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.parsed.y !== null) {
                                label += new Intl.NumberFormat('lv-LV').format(context.parsed.y);
                            }
                            return label;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false,
                    },
                    ticks: {
                        font: { size: 11 },
                        color: '#64748b',
                    }
                },
                y: {
                    beginAtZero: false,
                    grid: {
                        color: 'rgba(226, 232, 240, 0.8)',
                    },
                    ticks: {
                        font: { size: 11 },
                        color: '#64748b',
                        callback: function(value) {
                            return new Intl.NumberFormat('lv-LV').format(value);
                        }
                    }
                }
            }
        }
    });

    // Wire up interactive toggles
    const sodienaTrendToggle = document.getElementById('toggleSodienaTrend');
    if (sodienaTrendToggle) {
        sodienaTrendToggle.addEventListener('change', function () {
            growthChart.setDatasetVisibility(1, this.checked);
            growthChart.update();
        });
    }

    const afiroTrendToggle = document.getElementById('toggleAfiroTrend');
    if (afiroTrendToggle) {
        afiroTrendToggle.addEventListener('change', function () {
            growthChart.setDatasetVisibility(3, this.checked);
            growthChart.update();
        });
    }

    const archiveToggle = document.getElementById('toggleArchiveSeries');
    if (archiveToggle) {
        archiveToggle.addEventListener('change', function () {
            growthChart.setDatasetVisibility(4, this.checked);
            growthChart.update();
        });
    }

    // Refresh lucide icons if available
    if (typeof lucide !== 'undefined' && lucide.createIcons) {
        lucide.createIcons();
    }
});
</script>
@endpush
