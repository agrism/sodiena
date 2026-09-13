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
            <div class="px-3 py-1.5 bg-emerald-50 border border-emerald-300 text-emerald-900 rounded-lg">
                <span class="text-emerald-700 uppercase font-bold">Nākotnes:</span>
                <span class="font-extrabold ml-1">{{ number_format($stats['upcoming'], 0, '.', ' ') }}</span>
            </div>
            <div class="px-3 py-1.5 bg-amber-50 border border-amber-300 text-amber-900 rounded-lg">
                <span class="text-amber-700 uppercase font-bold">Šodien:</span>
                <span class="font-extrabold ml-1">{{ number_format($stats['today'], 0, '.', ' ') }}</span>
            </div>
            <div class="px-3 py-1.5 bg-blue-50 border border-blue-300 text-blue-900 rounded-lg">
                <span class="text-blue-700 uppercase font-bold">Atlasīti:</span>
                <span class="font-extrabold ml-1">{{ number_format($stats['filtered'], 0, '.', ' ') }}</span>
            </div>
        </div>
    </div>

    <!-- Filter Toolbar -->
    <div class="bg-white p-3.5 rounded-2xl border border-slate-300 shadow-xs">
        <form method="GET" action="{{ route('admin.events.index') }}" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-4 xl:grid-cols-8 gap-2 text-xs">
            
            <!-- Search -->
            <div class="sm:col-span-2 md:col-span-2 lg:col-span-2 xl:col-span-2">
                <label class="block text-[10px] font-bold uppercase text-slate-500 mb-1 font-mono">Meklēt tekstā / ID / Vietā</label>
                <input 
                    type="text" 
                    name="search" 
                    value="{{ $search }}" 
                    placeholder="Nosaukums, apraksts, vieta, ID..." 
                    class="w-full px-2.5 py-1.5 bg-slate-50 border border-slate-300 rounded text-xs focus:bg-white focus:outline-none focus:ring-1 focus:ring-emerald-500">
            </div>

            <!-- Real Origin Website Filter -->
            <div>
                <label class="block text-[10px] font-bold uppercase text-slate-500 mb-1 font-mono">Īstā vietne</label>
                <select name="origin_host" class="w-full px-2 py-1.5 bg-slate-50 border border-slate-300 rounded text-xs focus:outline-none">
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
                <label class="block text-[10px] font-bold uppercase text-slate-500 mb-1 font-mono">Robots / Imports</label>
                <select name="source" class="w-full px-2 py-1.5 bg-slate-50 border border-slate-300 rounded text-xs focus:outline-none">
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
                <label class="block text-[10px] font-bold uppercase text-slate-500 mb-1 font-mono">Kategorija</label>
                <select name="category" class="w-full px-2 py-1.5 bg-slate-50 border border-slate-300 rounded text-xs focus:outline-none">
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
                <label class="block text-[10px] font-bold uppercase text-slate-500 mb-1 font-mono">Pilsēta</label>
                <select name="city" class="w-full px-2 py-1.5 bg-slate-50 border border-slate-300 rounded text-xs focus:outline-none">
                    <option value="all">📍 Visas pilsētas</option>
                    @foreach($cities as $c)
                        <option value="{{ $c }}" {{ $city === $c ? 'selected' : '' }}>
                            {{ $c }}
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Location / Venue Filter (Vieta) -->
            <div>
                <label class="block text-[10px] font-bold uppercase text-slate-500 mb-1 font-mono">Vieta</label>
                <select name="location_id" class="w-full px-2 py-1.5 bg-slate-50 border border-slate-300 rounded text-xs focus:outline-none">
                    <option value="all">🏛️ Visas vietas</option>
                    @if($missingLocationCount > 0)
                        <option value="missing" {{ $locationId === 'missing' ? 'selected' : '' }} class="font-bold text-amber-700">
                            ⚠️ Nav vietas / tukšs ({{ number_format($missingLocationCount, 0, '.', ' ') }})
                        </option>
                    @endif
                    @foreach($locations as $loc)
                        <option value="{{ $loc->id }}" {{ (string)$locationId === (string)$loc->id ? 'selected' : '' }}>
                            {{ $loc->name }}{{ $loc->city ? ' ('.$loc->city.')' : '' }} ({{ $loc->events_count }})
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Timeframe & Actions -->
            <div class="sm:col-span-2 md:col-span-2 lg:col-span-2 xl:col-span-1 flex flex-col justify-between">
                <div>
                    <label class="block text-[10px] font-bold uppercase text-slate-500 mb-1 font-mono">Laiks / Ieraksti</label>
                    <div class="grid grid-cols-2 gap-1">
                        <select name="timeframe" class="w-full px-1.5 py-1.5 bg-slate-50 border border-slate-300 rounded text-[11px] focus:outline-none">
                            <option value="upcoming" {{ $timeframe === 'upcoming' ? 'selected' : '' }}>Aktuālie</option>
                            <option value="today" {{ $timeframe === 'today' ? 'selected' : '' }}>Šodien</option>
                            <option value="this_week" {{ $timeframe === 'this_week' ? 'selected' : '' }}>Šonedēļ</option>
                            <option value="this_month" {{ $timeframe === 'this_month' ? 'selected' : '' }}>Šomēnes</option>
                            <option value="past" {{ $timeframe === 'past' ? 'selected' : '' }}>Pagājušie</option>
                            <option value="all" {{ $timeframe === 'all' ? 'selected' : '' }}>Visi</option>
                        </select>
                        <select name="per_page" class="w-full px-1 py-1.5 bg-slate-50 border border-slate-300 rounded text-[11px] focus:outline-none font-mono">
                            <option value="25" {{ $perPage === 25 ? 'selected' : '' }}>25/lp</option>
                            <option value="50" {{ $perPage === 50 ? 'selected' : '' }}>50/lp</option>
                            <option value="100" {{ $perPage === 100 ? 'selected' : '' }}>100/lp</option>
                            <option value="200" {{ $perPage === 200 ? 'selected' : '' }}>200/lp</option>
                        </select>
                    </div>
                </div>

                <div class="flex items-center gap-1 mt-1.5">
                    <button type="submit" class="flex-grow px-3 py-1.5 bg-slate-900 hover:bg-slate-800 text-white font-bold rounded text-xs transition-colors">
                        Filtrēt
                    </button>

                    @if($search || $sourceSlug !== 'all' || $originHost !== 'all' || $categorySlug !== 'all' || $city !== 'all' || $locationId !== 'all' || $timeframe !== 'upcoming')
                        <a href="{{ route('admin.events.index') }}" class="px-2 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded text-xs transition-colors" title="Notīrīt filtrus">
                            &times;
                        </a>
                    @endif
                </div>
            </div>

        </form>
    </div>

    <!-- Excel-Style Spreadsheet Table Grid -->
    <div class="bg-white rounded-2xl border border-slate-300 shadow-sm overflow-hidden">
        <div class="overflow-x-auto max-h-[75vh]">
            <table class="w-full text-left border-collapse font-sans text-xs">
                
                <!-- Sticky Header -->
                <thead class="sticky top-0 z-10 bg-slate-100 border-b-2 border-slate-300 font-mono text-[11px] font-bold text-slate-700 shadow-xs select-none">
                    <tr>
                        <th class="py-2.5 px-3 border-r border-slate-300 w-16 text-center">
                            <a href="{{ request()->fullUrlWithQuery(['sort_by' => 'id', 'sort_dir' => ($sortBy === 'id' && $sortDir === 'asc') ? 'desc' : 'asc']) }}" class="flex items-center justify-center gap-1 hover:text-emerald-700">
                                ID {!! $sortBy === 'id' ? ($sortDir === 'asc' ? '▲' : '▼') : '' !!}
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
                        <th class="py-2.5 px-3 border-r border-slate-300 min-w-[180px]">Vieta & Pilsēta</th>
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
                        <tr class="hover:bg-amber-50/60 {{ $loop->even ? 'bg-slate-50/40' : 'bg-white' }} transition-colors group">
                            
                            <!-- ID -->
                            <td class="py-2 px-3 border-r border-slate-200 text-center font-bold text-slate-500">
                                #{{ $event->id }}
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
                            <td colspan="11" class="py-12 text-center text-slate-400 font-sans">
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
