@extends('layouts.admin')

@section('title', 'Notikumu datu tabula (Admin) — Šodiena')
@section('title_topbar', 'ŠODIENA.LV :: PASĀKUMU TABULA')

@section('content')
<div class="max-w-[1700px] mx-auto px-2 sm:px-4 lg:px-6 py-6 space-y-4">
    
    <!-- Top Bar: Title & Quick Stats -->
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 bg-white p-4 rounded-2xl border border-slate-300 shadow-xs">
        <div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-[11px] font-extrabold uppercase bg-purple-100 text-purple-800 border border-purple-200">
                    <i data-lucide="shield-check" class="w-3.5 h-3.5"></i>
                    Admin Grid
                </span>
                <span class="text-xs text-slate-500 font-semibold font-mono">/admin/events</span>
            </div>
            <h1 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight mt-1">
                Notikumu datu reģistrs
            </h1>
        </div>

        <!-- Metric Badges -->
        <div class="flex flex-wrap items-center gap-2 sm:gap-3 text-xs font-mono">
            <div class="px-3 py-1.5 bg-slate-50 border border-slate-300 rounded-lg">
                <span class="text-slate-400 uppercase font-bold">Kopā:</span>
                <span class="font-extrabold text-slate-900 ml-1">{{ number_format($stats['total'], 0, '.', ' ') }}</span>
            </div>
            <a href="{{ request()->fullUrlWithQuery(['published' => 'published']) }}" class="px-3 py-1.5 bg-emerald-50 border border-emerald-300 text-emerald-900 rounded-lg hover:bg-emerald-100 transition-colors {{ $published === 'published' ? 'ring-2 ring-emerald-500 font-black' : '' }}">
                <span class="text-emerald-700 uppercase font-bold">🟢 Publicēti:</span>
                <span class="font-extrabold ml-1">{{ number_format($stats['published'], 0, '.', ' ') }}</span>
            </a>
            <a href="{{ request()->fullUrlWithQuery(['published' => 'unpublished', 'timeframe' => 'all']) }}" class="px-3 py-1.5 bg-amber-50 border border-amber-300 text-amber-900 rounded-lg hover:bg-amber-100 transition-colors {{ $published === 'unpublished' ? 'ring-2 ring-amber-500 font-black' : '' }}">
                <span class="text-amber-700 uppercase font-bold">⏳ Nepublicēti:</span>
                <span class="font-extrabold ml-1">{{ number_format($stats['unpublished'], 0, '.', ' ') }}</span>
            </a>
            <div class="px-3 py-1.5 bg-blue-50 border border-blue-300 text-blue-900 rounded-lg">
                <span class="text-blue-700 uppercase font-bold">Atlasīti:</span>
                <span class="font-extrabold ml-1">{{ number_format($stats['filtered'], 0, '.', ' ') }}</span>
            </div>
        </div>
    </div>

    <!-- Filter Toolbar -->
    @php
        $isSearchActive = !empty($search);
        $isPublishedActive = ($published !== 'all');
        $isOriginActive = ($originHost !== 'all');
        $isSourceActive = ($sourceSlug !== 'all');
        $isCategoryActive = ($categorySlug !== 'all');
        $isCityActive = ($city !== 'all');
        $isLocationActive = ($locationId !== 'all');
        $isTimeframeActive = ($timeframe !== 'upcoming');
        $isPerPageActive = ($perPage !== 50);

        $hasActiveFilters = ($isSearchActive || $isPublishedActive || $isOriginActive || $isSourceActive || $isCategoryActive || $isCityActive || $isLocationActive || $isTimeframeActive || $isPerPageActive);
    @endphp
    <div class="bg-white p-3.5 rounded-2xl border border-slate-300 shadow-xs">
        <form method="GET" action="{{ route('admin.events.index') }}" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-4 xl:grid-cols-9 gap-2 text-xs">
            
            <!-- Preserve active sorting across filter submissions -->
            <input type="hidden" name="sort_by" value="{{ $sortBy }}">
            <input type="hidden" name="sort_dir" value="{{ $sortDir }}">

            <!-- Search -->
            <div class="sm:col-span-2 md:col-span-2 lg:col-span-2 xl:col-span-2">
                <label class="block text-[10px] font-bold uppercase mb-1 font-mono {{ $isSearchActive ? 'admin-filter-label-active flex items-center gap-1' : 'text-slate-500' }}">
                    @if($isSearchActive) <span class="w-1.5 h-1.5 rounded-full bg-red-600"></span> @endif
                    Meklēt tekstā / ID / Vietā
                </label>
                <input 
                    type="text" 
                    name="search" 
                    value="{{ $search }}" 
                    placeholder="Nosaukums, apraksts, vieta, ID..." 
                    class="w-full px-2.5 py-1.5 rounded text-xs transition-colors {{ $isSearchActive ? 'admin-filter-active' : 'bg-slate-50 border border-slate-300 text-slate-800 focus:bg-white focus:outline-none focus:ring-1 focus:ring-emerald-500' }}">
            </div>

            <!-- Publication Status Filter -->
            <div>
                <label class="block text-[10px] font-bold uppercase mb-1 font-mono {{ $isPublishedActive ? 'admin-filter-label-active flex items-center gap-1' : 'text-slate-500' }}">
                    @if($isPublishedActive) <span class="w-1.5 h-1.5 rounded-full bg-red-600"></span> @endif
                    Publicēts
                </label>
                <select name="published" aria-label="Publicēšanas statuss" class="w-full px-2 py-1.5 rounded text-xs transition-colors {{ $isPublishedActive ? 'admin-filter-active' : 'bg-slate-50 border border-slate-300 text-slate-800 focus:outline-none font-medium' }}">
                    <option value="all">🌐 Visi statusi</option>
                    <option value="published" {{ $published === 'published' ? 'selected' : '' }} class="font-bold text-emerald-700">
                        🟢 Tikai publicēti ({{ number_format($stats['published'], 0, '.', ' ') }})
                    </option>
                    <option value="unpublished" {{ $published === 'unpublished' ? 'selected' : '' }} class="font-bold text-amber-700">
                        ⏳ Nepublicēti / melnraksti ({{ number_format($stats['unpublished'], 0, '.', ' ') }})
                    </option>
                </select>
            </div>

            <!-- Real Origin Website Filter -->
            <div>
                <label class="block text-[10px] font-bold uppercase mb-1 font-mono {{ $isOriginActive ? 'admin-filter-label-active flex items-center gap-1' : 'text-slate-500' }}">
                    @if($isOriginActive) <span class="w-1.5 h-1.5 rounded-full bg-red-600"></span> @endif
                    Īstā vietne
                </label>
                <select name="origin_host" aria-label="Īstā vietne" class="w-full px-2 py-1.5 rounded text-xs transition-colors {{ $isOriginActive ? 'admin-filter-active' : 'bg-slate-50 border border-slate-300 text-slate-800 focus:outline-none' }}">
                    <option value="all">🌐 Visas vietnes</option>
                    <option value="missing" {{ $originHost === 'missing' ? 'selected' : '' }} class="font-bold text-amber-700">
                        ⚠️ Nav vietnes / tukšs ({{ number_format($missingOriginCount, 0, '.', ' ') }})
                    </option>
                    @foreach($originHosts as $host => $cnt)
                        <option value="{{ $host }}" {{ $originHost === $host ? 'selected' : '' }}>
                            {{ $host }} ({{ number_format($cnt, 0, '.', ' ') }})
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Technical Scraper Source Filter -->
            <div>
                <label class="block text-[10px] font-bold uppercase mb-1 font-mono {{ $isSourceActive ? 'admin-filter-label-active flex items-center gap-1' : 'text-slate-500' }}">
                    @if($isSourceActive) <span class="w-1.5 h-1.5 rounded-full bg-red-600"></span> @endif
                    Robots / Imports
                </label>
                <select name="source" aria-label="Robots vai imports" class="w-full px-2 py-1.5 rounded text-xs transition-colors {{ $isSourceActive ? 'admin-filter-active' : 'bg-slate-50 border border-slate-300 text-slate-800 focus:outline-none' }}">
                    <option value="all">🤖 Visi roboti</option>
                    @foreach($sources as $src)
                        <option value="{{ $src->slug }}" {{ $sourceSlug === $src->slug ? 'selected' : '' }}>
                            {{ $src->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Category Filter -->
            <div>
                <label class="block text-[10px] font-bold uppercase mb-1 font-mono {{ $isCategoryActive ? 'admin-filter-label-active flex items-center gap-1' : 'text-slate-500' }}">
                    @if($isCategoryActive) <span class="w-1.5 h-1.5 rounded-full bg-red-600"></span> @endif
                    Kategorija
                </label>
                <select name="category" aria-label="Kategorija" class="w-full px-2 py-1.5 rounded text-xs transition-colors {{ $isCategoryActive ? 'admin-filter-active' : 'bg-slate-50 border border-slate-300 text-slate-800 focus:outline-none' }}">
                    <option value="all">🏷️ Visas kategorijas</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->slug }}" {{ $categorySlug === $cat->slug ? 'selected' : '' }}>
                            {{ $cat->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- City Filter -->
            <div>
                <label class="block text-[10px] font-bold uppercase mb-1 font-mono {{ $isCityActive ? 'admin-filter-label-active flex items-center gap-1' : 'text-slate-500' }}">
                    @if($isCityActive) <span class="w-1.5 h-1.5 rounded-full bg-red-600"></span> @endif
                    Pilsēta
                </label>
                <select name="city" aria-label="Pilsēta" class="w-full px-2 py-1.5 rounded text-xs transition-colors {{ $isCityActive ? 'admin-filter-active' : 'bg-slate-50 border border-slate-300 text-slate-800 focus:outline-none' }}">
                    <option value="all">📍 Visas pilsētas</option>
                    @foreach($cities as $c)
                        <option value="{{ $c }}" {{ $city === $c ? 'selected' : '' }}>
                            {{ $c }}
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Location / Venue Filter (Vieta) with Live Text Search -->
            <div class="relative" id="locationComboboxWrapper">
                <div class="flex items-center justify-between mb-1">
                    <label class="block text-[10px] font-bold uppercase font-mono {{ $isLocationActive ? 'admin-filter-label-active flex items-center gap-1' : 'text-slate-500' }}">
                        @if($isLocationActive) <span class="w-1.5 h-1.5 rounded-full bg-red-600"></span> @endif
                        Vieta
                    </label>
                    <span id="locCountBadge" class="text-[9px] font-mono text-slate-400 font-bold">({{ count($locations) }})</span>
                </div>
                
                <!-- Hidden select for form submission -->
                <select name="location_id" id="adminLocationSelect" aria-label="Pasākuma norises vieta" class="hidden">
                    <option value="all" {{ $locationId === 'all' ? 'selected' : '' }}>🏛️ Visas vietas</option>
                    @if($missingLocationCount > 0)
                        <option value="missing" {{ $locationId === 'missing' ? 'selected' : '' }}>
                            ⚠️ Nav vietas / tukšs ({{ number_format($missingLocationCount, 0, '.', ' ') }})
                        </option>

                    @endif
                    @foreach($locations as $loc)
                        <option value="{{ $loc->id }}" {{ (string)$locationId === (string)$loc->id ? 'selected' : '' }}>
                            {{ $loc->name }}{{ $loc->city ? ' ('.$loc->city.')' : '' }} ({{ $loc->events_count }})
                        </option>
                    @endforeach
                </select>

                <!-- Combobox Trigger Button -->
                <button 
                    type="button" 
                    id="locComboboxBtn"
                    onclick="toggleLocationDropdown()"
                    class="w-full px-2 py-1.5 rounded text-xs text-left flex items-center justify-between focus:outline-none transition-colors cursor-pointer {{ $isLocationActive ? 'admin-filter-active' : 'bg-slate-50 hover:bg-slate-100 border border-slate-300 text-slate-800 focus:ring-1 focus:ring-emerald-500 focus:bg-white' }}">
                    <span id="locComboboxLabel" class="truncate {{ $isLocationActive ? 'font-bold text-red-950' : 'font-medium text-slate-800' }}">
                        @if($locationId === 'missing')
                            ⚠️ Nav vietas / tukšs
                        @elseif($selectedLoc = $locations->firstWhere('id', (int)$locationId))
                            {{ $selectedLoc->name }}{{ $selectedLoc->city ? ' ('.$selectedLoc->city.')' : '' }}
                        @else
                            🏛️ Visas vietas
                        @endif
                    </span>
                    <i data-lucide="chevrons-up-down" class="w-3.5 h-3.5 {{ $isLocationActive ? 'text-red-600' : 'text-slate-400' }} shrink-0 ml-1"></i>
                </button>

                <!-- Dropdown Search Panel -->
                <div 
                    id="locDropdownPanel" 
                    class="hidden absolute left-0 sm:right-auto w-72 sm:w-80 md:w-96 top-full mt-1 bg-white border border-slate-300 rounded-xl shadow-2xl z-50 p-2.5 text-xs font-sans">
                    
                    <div class="relative mb-2">
                        <i data-lucide="search" class="w-3.5 h-3.5 text-slate-400 absolute left-2.5 top-2.5 pointer-events-none"></i>
                        <input 
                            type="text" 
                            id="locSearchInput" 
                            placeholder="Ieraksti vietu vai pilsētu..." 
                            class="w-full pl-8 pr-7 py-1.5 bg-slate-50 border border-slate-300 rounded text-xs focus:bg-white focus:outline-none focus:ring-1 focus:ring-emerald-500"
                            autocomplete="off">
                        <button 
                            type="button" 
                            id="locSearchClearBtn" 
                            onclick="clearLocSearch()" 
                            class="hidden absolute right-2 top-1.5 text-slate-400 hover:text-slate-700 font-bold text-sm">
                            &times;
                        </button>
                    </div>

                    <div class="mb-1 pb-1 border-b border-slate-100 flex items-center justify-between">
                        <button 
                            type="button" 
                            onclick="selectLocation('all', '🏛️ Visas vietas', true)" 
                            class="text-left px-2 py-1 rounded hover:bg-slate-100 text-slate-700 hover:text-slate-900 font-bold flex items-center gap-1.5 text-[11px] {{ $locationId === 'all' ? 'text-emerald-700 font-black' : '' }}">
                            <span>🏛️ Visas vietas</span>
                        </button>
                        @if($missingLocationCount > 0)
                            <button 
                                type="button" 
                                onclick="selectLocation('missing', '⚠️ Nav vietas / tukšs', true)" 
                                class="text-left px-2 py-1 rounded hover:bg-amber-50 text-amber-800 font-bold text-[11px] {{ $locationId === 'missing' ? 'bg-amber-100' : '' }}">
                                <span>⚠️ Bez vietas ({{ $missingLocationCount }})</span>
                            </button>
                        @endif
                    </div>

                    <div id="locOptionsList" class="max-h-56 overflow-y-auto space-y-0.5 font-sans divide-y divide-slate-50">
                        @foreach($locations as $loc)
                            <button 
                                type="button" 
                                data-name="{{ mb_strtolower($loc->name . ' ' . $loc->city, 'UTF-8') }}"
                                onclick="selectLocation('{{ $loc->id }}', '{{ addslashes($loc->name . ($loc->city ? ' (' . $loc->city . ')' : '')) }}', true)" 
                                class="loc-option-item w-full text-left px-2 py-1.5 rounded hover:bg-slate-100 flex items-center justify-between text-slate-800 transition-colors {{ (string)$locationId === (string)$loc->id ? 'bg-emerald-50 text-emerald-900 font-bold' : '' }}">
                                <div class="truncate mr-2">
                                    <span class="block truncate font-medium text-[11px]">{{ $loc->name }}</span>
                                    @if($loc->city)
                                        <span class="block text-[10px] text-slate-400">📍 {{ $loc->city }}</span>
                                    @endif
                                </div>
                                <span class="text-[10px] font-mono text-slate-500 font-semibold bg-slate-100 px-1.5 py-0.5 rounded shrink-0">
                                    {{ $loc->events_count }}
                                </span>
                            </button>
                        @endforeach
                    </div>

                    <div id="locNoResults" class="hidden py-4 text-center text-slate-400 text-xs">
                        Nav atrasta neviena vieta
                    </div>
                </div>
            </div>

            <!-- Timeframe & Actions -->
            <div class="sm:col-span-2 md:col-span-2 lg:col-span-2 xl:col-span-1 flex flex-col justify-between">
                <div>
                    <label class="block text-[10px] font-bold uppercase mb-1 font-mono {{ ($isTimeframeActive || $isPerPageActive) ? 'admin-filter-label-active flex items-center gap-1' : 'text-slate-500' }}">
                        @if($isTimeframeActive || $isPerPageActive) <span class="w-1.5 h-1.5 rounded-full bg-red-600"></span> @endif
                        Laiks / Ieraksti
                    </label>
                    <div class="grid grid-cols-2 gap-1">
                        <select name="timeframe" aria-label="Laika periods" class="w-full px-1.5 py-1.5 rounded text-[11px] focus:outline-none transition-colors {{ $isTimeframeActive ? 'admin-filter-active' : 'bg-slate-50 border border-slate-300 text-slate-800' }}">
                            <option value="upcoming" {{ $timeframe === 'upcoming' ? 'selected' : '' }}>Aktuālie</option>
                            <option value="today" {{ $timeframe === 'today' ? 'selected' : '' }}>Šodien</option>
                            <option value="this_week" {{ $timeframe === 'this_week' ? 'selected' : '' }}>Šonedēļ</option>
                            <option value="this_month" {{ $timeframe === 'this_month' ? 'selected' : '' }}>Šomēnes</option>
                            <option value="past" {{ $timeframe === 'past' ? 'selected' : '' }}>Pagājušie</option>
                            <option value="all" {{ $timeframe === 'all' ? 'selected' : '' }}>Visi</option>
                        </select>
                        <select name="per_page" aria-label="Ierakstu skaits lapā" class="w-full px-1 py-1.5 rounded text-[11px] focus:outline-none font-mono transition-colors {{ $isPerPageActive ? 'admin-filter-active' : 'bg-slate-50 border border-slate-300 text-slate-800' }}">

                            <option value="25" {{ $perPage === 25 ? 'selected' : '' }}>25/lp</option>
                            <option value="50" {{ $perPage === 50 ? 'selected' : '' }}>50/lp</option>
                            <option value="100" {{ $perPage === 100 ? 'selected' : '' }}>100/lp</option>
                            <option value="200" {{ $perPage === 200 ? 'selected' : '' }}>200/lp</option>
                            <option value="300" {{ $perPage === 300 ? 'selected' : '' }}>300/lp</option>
                            <option value="400" {{ $perPage === 400 ? 'selected' : '' }}>400/lp</option>
                            <option value="500" {{ $perPage === 500 ? 'selected' : '' }}>500/lp</option>
                            <option value="600" {{ $perPage === 600 ? 'selected' : '' }}>600/lp</option>
                            <option value="700" {{ $perPage === 700 ? 'selected' : '' }}>700/lp</option>
                            <option value="1000" {{ $perPage === 1000 ? 'selected' : '' }}>1000/lp</option>
                        </select>
                    </div>
                </div>

                <div class="flex items-center gap-1 mt-1.5">
                    <button type="submit" class="flex-grow px-3 py-1.5 bg-slate-900 hover:bg-slate-800 text-white font-bold rounded text-xs transition-colors cursor-pointer">
                        Filtrēt
                    </button>

                    @if($hasActiveFilters)
                        <a href="{{ route('admin.events.index') }}" class="px-2 py-1.5 admin-filter-reset-active rounded text-xs transition-colors shadow-xs" title="Notīrīt visus aktīvos filtrus">
                            &times;
                        </a>
                    @endif
                </div>
            </div>

        </form>
    </div>

    <!-- Floating Bulk Actions Bar -->
    <div id="bulkActionBar" class="hidden sticky top-4 z-40 bg-slate-900 text-white px-4 py-3 rounded-2xl shadow-xl border border-slate-700 flex items-center justify-between gap-4 animate-in fade-in slide-in-from-top duration-200">
        <div class="flex items-center gap-2">
            <span class="w-6 h-6 rounded-full bg-emerald-500 text-slate-950 font-black text-xs flex items-center justify-center" id="selectedCountBadge">0</span>
            <span class="text-xs font-bold font-mono">izvēlēti pasākumi</span>
        </div>

        <div class="flex items-center gap-2">
            <button 
                type="button" 
                onclick="submitBulkAction('publish')"
                class="px-3 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs flex items-center gap-1.5 transition-colors cursor-pointer shadow-xs">
                <i data-lucide="check-circle" class="w-3.5 h-3.5"></i>
                <span>Publicēt atlasītos</span>
            </button>

            <button 
                type="button" 
                onclick="submitBulkAction('unpublish')"
                class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-600 font-bold text-xs flex items-center gap-1.5 transition-colors cursor-pointer shadow-xs">
                <i data-lucide="eye-off" class="w-3.5 h-3.5"></i>
                <span>Noņemt no publikācijas</span>
            </button>

            <button 
                type="button" 
                onclick="clearAllSelections()"
                class="text-xs text-slate-400 hover:text-white px-2 py-1 transition-colors">
                Atcelt
            </button>
        </div>
    </div>

    <!-- Excel-Style Spreadsheet Table Grid -->
    <div class="bg-white rounded-2xl border border-slate-300 shadow-sm overflow-hidden">
        <div class="overflow-x-auto max-h-[75vh]">
            <table class="w-full text-left border-collapse font-sans text-xs">
                
                <!-- Sticky Header -->
                <thead class="sticky top-0 z-10 bg-slate-100 border-b-2 border-slate-300 font-mono text-[11px] font-bold text-slate-700 shadow-xs select-none">
                    <tr>
                        <th class="py-2.5 px-3 border-r border-slate-300 w-10 text-center">
                            <input 
                                type="checkbox" 
                                id="masterSelectAll" 
                                onclick="toggleSelectAll(this)"
                                title="Izvēlēties visus lapas pasākumus"
                                class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500 cursor-pointer">
                        </th>
                        <th class="py-2.5 px-3 border-r border-slate-300 w-16 text-center">
                            <a href="{{ request()->fullUrlWithQuery(['sort_by' => 'id', 'sort_dir' => ($sortBy === 'id' && $sortDir === 'asc') ? 'desc' : 'asc']) }}" class="flex items-center justify-center gap-1 hover:text-emerald-700">
                                ID {!! $sortBy === 'id' ? ($sortDir === 'asc' ? '▲' : '▼') : '' !!}
                            </a>
                        </th>
                        <th class="py-2.5 px-3 border-r border-slate-300 w-36 text-center">
                            <a href="{{ request()->fullUrlWithQuery(['sort_by' => 'published_at', 'sort_dir' => ($sortBy === 'published_at' && $sortDir === 'asc') ? 'desc' : 'asc']) }}" class="flex items-center justify-center gap-1 hover:text-emerald-700">
                                Publicēts {!! $sortBy === 'published_at' ? ($sortDir === 'asc' ? '▲' : '▼') : '' !!}
                            </a>
                        </th>
                        <th class="py-2.5 px-3 border-r border-slate-300 w-44">
                            <a href="{{ request()->fullUrlWithQuery(['sort_by' => 'start_at', 'sort_dir' => ($sortBy === 'start_at' && $sortDir === 'asc') ? 'desc' : 'asc']) }}" class="flex items-center gap-1 hover:text-emerald-700">
                                Sākuma Datums {!! $sortBy === 'start_at' ? ($sortDir === 'asc' ? '▲' : '▼') : '' !!}
                            </a>
                        </th>
                        <th class="py-2.5 px-3 border-r border-slate-300 min-w-[280px]">
                            <a href="{{ request()->fullUrlWithQuery(['sort_by' => 'title', 'sort_dir' => ($sortBy === 'title' && $sortDir === 'asc') ? 'desc' : 'asc']) }}" class="flex items-center gap-1 hover:text-emerald-700">
                                Notikuma Nosaukums {!! $sortBy === 'title' ? ($sortDir === 'asc' ? '▲' : '▼') : '' !!}
                            </a>
                        </th>
                        <th class="py-2.5 px-3 border-r border-slate-300 min-w-[180px]">
                            <a href="{{ request()->fullUrlWithQuery(['sort_by' => 'location', 'sort_dir' => ($sortBy === 'location' && $sortDir === 'asc') ? 'desc' : 'asc']) }}" class="flex items-center gap-1 hover:text-emerald-700">
                                Vieta & Pilsēta {!! $sortBy === 'location' ? ($sortDir === 'asc' ? '▲' : '▼') : '' !!}
                            </a>
                        </th>
                        <th class="py-2.5 px-3 border-r border-slate-300 min-w-[150px]">Kategorija</th>
                        <th class="py-2.5 px-3 border-r border-slate-300 w-28">Tips</th>
                        <th class="py-2.5 px-3 border-r border-slate-300 w-28">
                            <a href="{{ request()->fullUrlWithQuery(['sort_by' => 'price_min', 'sort_dir' => ($sortBy === 'price_min' && $sortDir === 'asc') ? 'desc' : 'asc']) }}" class="flex items-center gap-1 hover:text-emerald-700">
                                Cena {!! $sortBy === 'price_min' ? ($sortDir === 'asc' ? '▲' : '▼') : '' !!}
                            </a>
                        </th>
                        <th class="py-2.5 px-3 border-r border-slate-300 min-w-[160px]">Īstā vietne (Avots)</th>
                        <th class="py-2.5 px-3 border-r border-slate-300 w-28">Robots</th>
                        <th class="py-2.5 px-3 border-r border-slate-300 w-28 text-center">Ārējais ID</th>
                        <th class="py-2.5 px-3 text-center w-24">Saites</th>
                    </tr>
                </thead>

                <!-- Table Rows -->
                <tbody class="divide-y divide-slate-200 font-mono text-[11px] leading-tight">
                    @forelse($events as $event)
                        <tr id="event-row-{{ $event->id }}" class="hover:bg-amber-50/60 {{ $loop->even ? 'bg-slate-50/40' : 'bg-white' }} transition-colors group">
                            
                            <!-- Checkbox -->
                            <td class="py-2 px-3 border-r border-slate-200 text-center">
                                <input 
                                    type="checkbox" 
                                    value="{{ $event->id }}" 
                                    class="event-row-checkbox rounded border-slate-300 text-emerald-600 focus:ring-emerald-500 cursor-pointer"
                                    onchange="updateBulkBar()">
                            </td>

                            <!-- ID -->
                            <td class="py-2 px-3 border-r border-slate-200 text-center font-bold text-slate-500">
                                #{{ $event->id }}
                            </td>

                            <!-- Published Status & Instant Toggle -->
                            <td class="py-2 px-3 border-r border-slate-200 text-center whitespace-nowrap" id="pub-cell-{{ $event->id }}">
                                <button 
                                    type="button" 
                                    onclick="toggleEventPublish({{ $event->id }}, this)"
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-extrabold border transition-all cursor-pointer {{ $event->isPublished() ? 'bg-emerald-50 text-emerald-800 border-emerald-300 hover:bg-emerald-100' : 'bg-slate-100 text-slate-600 border-slate-300 hover:bg-slate-200 hover:text-slate-900' }}"
                                    title="Noklikšķiniet, lai mainītu publicēšanas statusu">
                                    <span class="w-2 h-2 rounded-full {{ $event->isPublished() ? 'bg-emerald-600 animate-pulse' : 'bg-slate-400' }}"></span>
                                    <span>{{ $event->isPublished() ? 'Publicēts' : 'Nepublicēts' }}</span>
                                </button>
                                @if($event->published_at)
                                    <span class="block text-[9px] text-slate-400 font-mono mt-0.5">
                                        {{ $event->published_at->format('d.m.Y H:i') }}
                                    </span>
                                @endif
                            </td>

                            <!-- Start Date & Time -->
                            <td class="py-2 px-3 border-r border-slate-200 whitespace-nowrap">
                                <span class="font-bold text-slate-900">
                                    {{ $event->start_at ? $event->start_at->format('Y-m-d H:i') : '-' }}
                                </span>
                                @if($event->end_at)
                                    <span class="text-[10px] text-slate-400 block font-normal">
                                        līdz {{ $event->end_at->format('Y-m-d H:i') }}
                                    </span>
                                @endif
                            </td>

                            <!-- Title (Link to view) -->
                            <td class="py-2 px-3 border-r border-slate-200 font-sans">
                                <a 
                                    href="{{ route('events.show', $event->slug) }}" 
                                    target="_blank" 
                                    class="font-bold text-slate-900 hover:text-emerald-700 hover:underline inline-flex items-center gap-1.5">
                                    {{ $event->title }}
                                    <i data-lucide="external-link" class="w-3 h-3 text-slate-400 shrink-0 group-hover:text-emerald-600"></i>
                                </a>
                                <span class="block text-[10px] text-slate-400 font-mono mt-0.5 truncate max-w-lg">
                                    /events/{{ $event->slug }}
                                </span>
                            </td>

                            <!-- Location & City -->
                            <td class="py-2 px-3 border-r border-slate-200 font-sans">
                                @if($event->location)
                                    <a 
                                        href="{{ request()->fullUrlWithQuery(['location_id' => $event->location->id]) }}" 
                                        class="font-semibold text-slate-800 hover:text-emerald-700 hover:underline block truncate max-w-xs" 
                                        title="Filtrēt pēc vietas: {{ $event->location->name }}">
                                        {{ $event->location->name }}
                                    </a>
                                    <a 
                                        href="{{ request()->fullUrlWithQuery(['city' => $event->location->city, 'location_id' => 'all']) }}" 
                                        class="text-[10px] text-slate-500 hover:text-emerald-700 hover:underline font-mono block mt-0.5" 
                                        title="Filtrēt pēc pilsētas: {{ $event->location->city }}">
                                        📍 {{ $event->location->city ?: 'Latvija' }}
                                    </a>
                                @else
                                    <span class="text-slate-400 font-sans">-</span>
                                @endif
                            </td>

                            <!-- Categories -->
                            <td class="py-2 px-3 border-r border-slate-200 font-sans">
                                <div class="flex flex-wrap gap-1">
                                    @foreach($event->categories as $category)
                                        <span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-semibold bg-slate-100 text-slate-700 border border-slate-200">
                                             {{ $category->name }}
                                        </span>
                                    @endforeach
                                </div>
                            </td>

                            <!-- Entertainment Type -->
                            <td class="py-2 px-3 border-r border-slate-200">
                                @if($event->entertainment_type)
                                    <span class="px-1.5 py-0.5 rounded text-[10px] font-bold uppercase bg-emerald-50 text-emerald-800 border border-emerald-200">
                                        {{ $event->localized_entertainment_type }}
                                    </span>
                                @else
                                    <span class="text-slate-300">-</span>
                                @endif
                            </td>

                            <!-- Price -->
                            <td class="py-2 px-3 border-r border-slate-200 whitespace-nowrap">
                                @if($event->is_free)
                                    <span class="font-bold text-emerald-700">BEZMAKSAS</span>
                                @elseif($event->price_min)
                                    <span class="font-bold text-slate-800">
                                        €{{ $event->price_min }}{{ $event->price_max && $event->price_max != $event->price_min ? ' - €' . $event->price_max : '' }}
                                    </span>
                                @else
                                    <span class="text-slate-400">Nav norādīta</span>
                                @endif
                            </td>

                            <!-- Real Origin Website -->
                            <td class="py-2 px-3 border-r border-slate-200 font-sans">
                                @if($event->origin_url)
                                    <a 
                                        href="{{ $event->origin_url }}" 
                                        target="_blank" 
                                        rel="noopener noreferrer" 
                                        class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-[11px] font-semibold bg-blue-50 text-blue-800 hover:bg-blue-100 hover:text-blue-950 border border-blue-200 transition-colors max-w-[170px]"
                                        title="{{ $event->origin_url }}">
                                        <i data-lucide="globe" class="w-3.5 h-3.5 text-blue-600 shrink-0"></i>
                                        <span class="truncate">{{ $event->origin_host }}</span>
                                        <i data-lucide="external-link" class="w-2.5 h-2.5 text-blue-400 shrink-0"></i>
                                    </a>
                                @else
                                    <span class="text-slate-400 text-[10px]">-</span>
                                @endif
                            </td>

                            <!-- Robot / Scraper Source -->
                            <td class="py-2 px-3 border-r border-slate-200 font-sans">
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-700 border border-slate-200 block truncate" title="Imports: {{ $event->source?->name ?: $event->source_slug }}">
                                    {{ $event->source?->name ?: $event->source_slug }}
                                </span>
                            </td>

                            <!-- External ID -->
                            <td class="py-2 px-3 border-r border-slate-200 text-center font-mono text-[10px] text-slate-500 truncate max-w-[110px]" title="{{ $event->source_external_id }}">
                                {{ $event->source_external_id ?: '-' }}
                            </td>

                            <!-- Direct Links -->
                            <td class="py-2 px-3 text-center whitespace-nowrap">
                                <div class="flex items-center justify-center gap-1.5">
                                    @if($event->ticket_url)
                                        <a href="{{ $event->ticket_url }}" target="_blank" rel="noopener noreferrer" class="p-1 text-emerald-600 hover:text-emerald-800 hover:bg-emerald-50 rounded" title="Biļešu saite: {{ $event->ticket_url }}">
                                             <i data-lucide="ticket" class="w-3.5 h-3.5"></i>
                                        </a>
                                    @endif
                                    @if($event->source_url)
                                        <a href="{{ $event->source_url }}" target="_blank" rel="noopener noreferrer" class="p-1 text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded" title="Oriģinālā saite: {{ $event->source_url }}">
                                             <i data-lucide="globe" class="w-3.5 h-3.5"></i>
                                        </a>
                                    @endif
                                    <a href="{{ route('events.show', $event->slug) }}" target="_blank" class="p-1 text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded" title="Atvērt lapu">
                                        <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                                    </a>
                                </div>
                            </td>

                        </tr>
                    @empty
                        <tr>
                            <td colspan="13" class="py-12 text-center text-slate-400 font-sans">
                                <i data-lucide="inbox" class="w-8 h-8 mx-auto stroke-1 text-slate-300"></i>
                                <p class="mt-2 text-sm font-semibold">Nav atrasts neviens notikums pēc norādītajiem filtriem.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if($events->hasPages())
            <div class="p-3 bg-slate-50 border-t border-slate-300">
                {{ $events->links() }}
            </div>
        @endif
    </div>

</div>
@endsection

@push('scripts')
<script>
    const csrfToken = '{{ csrf_token() }}';

    function toggleSelectAll(masterCheckbox) {
        const checkboxes = document.querySelectorAll('.event-row-checkbox');
        checkboxes.forEach(cb => {
            cb.checked = masterCheckbox.checked;
        });
        updateBulkBar();
    }

    function updateBulkBar() {
        const checked = document.querySelectorAll('.event-row-checkbox:checked');
        const bulkBar = document.getElementById('bulkActionBar');
        const badge = document.getElementById('selectedCountBadge');
        if (bulkBar && badge) {
            badge.textContent = checked.length;
            if (checked.length > 0) {
                bulkBar.classList.remove('hidden');
            } else {
                bulkBar.classList.add('hidden');
            }
        }
    }

    function clearAllSelections() {
        const master = document.getElementById('masterSelectAll');
        if (master) master.checked = false;
        document.querySelectorAll('.event-row-checkbox').forEach(cb => cb.checked = false);
        updateBulkBar();
    }

    async function toggleEventPublish(eventId, btnElement) {
        if (!eventId || !btnElement) return;

        btnElement.disabled = true;
        btnElement.classList.add('opacity-50');

        try {
            const response = await fetch(`/admin/events/${eventId}/toggle-publish`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                }
            });

            const data = await response.json();
            if (data.success) {
                const cell = document.getElementById(`pub-cell-${eventId}`);
                if (cell) {
                    const isPub = data.is_published;
                    cell.innerHTML = `
                        <button 
                            type="button" 
                            onclick="toggleEventPublish(${eventId}, this)"
                            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-extrabold border transition-all cursor-pointer ${isPub ? 'bg-emerald-50 text-emerald-800 border-emerald-300 hover:bg-emerald-100' : 'bg-slate-100 text-slate-600 border-slate-300 hover:bg-slate-200 hover:text-slate-900'}"
                            title="Noklikšķiniet, lai mainītu publicēšanas statusu">
                            <span class="w-2 h-2 rounded-full ${isPub ? 'bg-emerald-600 animate-pulse' : 'bg-slate-400'}"></span>
                            <span>${isPub ? 'Publicēts' : 'Nepublicēts'}</span>
                        </button>
                        ${data.published_at ? `<span class="block text-[9px] text-slate-400 font-mono mt-0.5">${data.published_at}</span>` : ''}
                    `;
                }
            }
        } catch (e) {
            console.error('Publish toggle failed:', e);
            alert('Neizdevās nomainīt publicēšanas statusu. Lūdzu, mēģiniet vēlreiz.');
        } finally {
            btnElement.disabled = false;
            btnElement.classList.remove('opacity-50');
        }
    }

    async function submitBulkAction(action) {
        const checkedBoxes = Array.from(document.querySelectorAll('.event-row-checkbox:checked'));
        const ids = checkedBoxes.map(cb => cb.value);

        if (ids.length === 0) return;

        const actionText = action === 'publish' ? 'nopublicēt' : 'noņemt no publikācijas';
        if (!confirm(`Vai tiešām vēlaties ${actionText} ${ids.length} atlasītos pasākumus?`)) {
            return;
        }

        try {
            const response = await fetch('{{ route("admin.events.bulk-publish") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    action: action,
                    event_ids: ids,
                })
            });

            const data = await response.json();
            if (data.success) {
                window.location.reload();
            }
        } catch (e) {
            console.error('Bulk action failed:', e);
            alert('Neizdevās veikt masveida darbību.');
        }
    }

    function toggleLocationDropdown() {
        const panel = document.getElementById('locDropdownPanel');
        if (!panel) return;
        const isHidden = panel.classList.contains('hidden');
        if (isHidden) {
            panel.classList.remove('hidden');
            const searchInput = document.getElementById('locSearchInput');
            if (searchInput) {
                searchInput.value = '';
                filterLocOptions('');
                setTimeout(() => searchInput.focus(), 50);
            }
        } else {
            panel.classList.add('hidden');
        }
    }

    function selectLocation(id, label, autoSubmit = false) {
        const select = document.getElementById('adminLocationSelect');
        const labelEl = document.getElementById('locComboboxLabel');
        const panel = document.getElementById('locDropdownPanel');
        if (select) {
            select.value = id;
        }
        if (labelEl) {
            labelEl.textContent = label;
        }
        if (panel) {
            panel.classList.add('hidden');
        }
        if (autoSubmit && select && select.form) {
            select.form.submit();
        }
    }

    function clearLocSearch() {
        const input = document.getElementById('locSearchInput');
        if (input) {
            input.value = '';
            filterLocOptions('');
            input.focus();
        }
    }

    function filterLocOptions(query) {
        const q = (query || '').trim().toLowerCase();
        const clearBtn = document.getElementById('locSearchClearBtn');
        if (clearBtn) {
            clearBtn.classList.toggle('hidden', q === '');
        }

        const items = document.querySelectorAll('.loc-option-item');
        let visibleCount = 0;

        items.forEach(item => {
            const name = item.getAttribute('data-name') || '';
            if (q === '' || name.includes(q)) {
                item.style.display = '';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });

        const noResults = document.getElementById('locNoResults');
        if (noResults) {
            noResults.classList.toggle('hidden', visibleCount > 0 || q === '');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const searchInput = document.getElementById('locSearchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function (e) {
                filterLocOptions(e.target.value);
            });
            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    const panel = document.getElementById('locDropdownPanel');
                    panel?.classList.add('hidden');
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    const firstVisible = Array.from(document.querySelectorAll('.loc-option-item')).find(item => item.style.display !== 'none');
                    if (firstVisible) {
                        firstVisible.click();
                    }
                }
            });
        }

        document.addEventListener('click', function (e) {
            const container = document.getElementById('locationComboboxWrapper');
            const panel = document.getElementById('locDropdownPanel');
            if (container && panel && !container.contains(e.target)) {
                panel.classList.add('hidden');
            }
        });
    });
</script>
@endpush
