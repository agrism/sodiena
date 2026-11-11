@extends('layouts.admin')

@section('title', 'Pasākumu Avoti un Roboti — Šodiena')
@section('title_topbar', 'ŠODIENA.LV :: AVOTI UN ROBOTI')

@section('content')
<div class="max-w-7xl xl:max-w-[1400px] 2xl:max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <!-- Header -->
    <div class="mb-10 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">
                Parsēšanas Avoti & Roboti
            </h1>
            <p class="text-sm text-slate-600 mt-1">
                Pārvaldi un uzraugi automatizētos pasākumu iegūšanas un parsēšanas procesus.
            </p>
        </div>

        <a href="{{ route('events.index') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white border border-slate-200 text-sm font-bold text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors shadow-xs">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>
            Atpakaļ uz kalendāru
        </a>
    </div>

    <!-- Category Filter Tabs by Source Type -->
    <div class="mb-8 flex flex-wrap items-center gap-2 border-b border-slate-200/80 pb-4">
        <a href="{{ route('events.sources') }}" 
           class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold transition-all {{ empty($selectedType) ? 'bg-slate-900 text-white shadow-xs' : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200' }}">
            <i data-lucide="layers" class="w-3.5 h-3.5"></i>
            Visi avoti
            <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] {{ empty($selectedType) ? 'bg-slate-800 text-slate-300' : 'bg-slate-100 text-slate-600' }}">
                {{ $totalCount }}
            </span>
        </a>

        @foreach($types as $t)
            @php
                $count = $typeCounts[$t->value] ?? 0;
                $isActive = ($selectedType === $t->value);
            @endphp
            <a href="{{ route('events.sources', ['type' => $t->value]) }}" 
               class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold transition-all {{ $isActive ? 'bg-slate-900 text-white shadow-xs' : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200' }}">
                <i data-lucide="{{ $t->icon() }}" class="w-3.5 h-3.5"></i>
                {{ $t->label() }}
                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] {{ $isActive ? 'bg-slate-800 text-slate-300' : 'bg-slate-100 text-slate-600' }}">
                    {{ $count }}
                </span>
            </a>
        @endforeach
    </div>

    <!-- Sources Grid -->
    @if($sources->isEmpty())
        <div class="bg-white border border-slate-200 rounded-3xl p-12 text-center text-slate-500">
            <i data-lucide="inbox" class="w-10 h-10 mx-auto text-slate-300 mb-3"></i>
            <p class="font-bold text-slate-700">Šajā kategorijā pagaidām nav reģistrētu avotu.</p>
            <p class="text-xs text-slate-400 mt-1">Izvēlies citu filtru vai apskati visus avotus.</p>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach($sources as $source)
                @include('events.partials.source-card', ['source' => $source])
            @endforeach
        </div>
    @endif

    <!-- Scrape Logs Table -->
    <div class="mt-14">
        <h2 class="text-xl font-extrabold text-slate-900 mb-4 flex items-center gap-2">
            <i data-lucide="history" class="w-5 h-5 text-emerald-600"></i>
            Pēdējie parsēšanas žurnāla ieraksti
        </h2>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-xs">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-700">
                    <thead class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider border-b border-slate-200">
                        <tr>
                            <th class="py-3 px-4">Avots</th>
                            <th class="py-3 px-4">Statuss</th>
                            <th class="py-3 px-4">Atrasti</th>
                            <th class="py-3 px-4">Izveidoti</th>
                            <th class="py-3 px-4">Atjaunoti</th>
                            <th class="py-3 px-4">Ilgums</th>
                            <th class="py-3 px-4">Laiks</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium">
                        @php
                            $allLogs = \App\Models\ScrapeLog::with('source')->orderByDesc('started_at')->take(10)->get();
                        @endphp
                        @forelse($allLogs as $log)
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="py-3.5 px-4 font-bold text-slate-900">{{ $log->source?->name }}</td>
                                <td class="py-3.5 px-4">
                                    @if($log->status === 'success')
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            Veiksmīgs
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                            Kļūda
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4">{{ $log->items_found }}</td>
                                <td class="py-3.5 px-4 text-emerald-700 font-bold">+{{ $log->items_created }}</td>
                                <td class="py-3.5 px-4 text-blue-600 font-semibold">{{ $log->items_updated }}</td>
                                <td class="py-3.5 px-4">{{ $log->duration_seconds }}s</td>
                                <td class="py-3.5 px-4 text-slate-500">{{ $log->started_at->format('d.m.Y H:i:s') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-8 text-center text-slate-400">
                                    Vēl nav reģistrēts neviens parsēšanas žurnāla ieraksts.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
