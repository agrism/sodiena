@extends('layouts.admin')

@section('title', 'Nepublicētie pasākumi & Cenu verifikācija — Šodiena Admin')
@section('title_topbar', 'ŠODIENA.LV :: NEPUBLICĒTIE & CENAS')

@section('content')
<div class="max-w-[1700px] mx-auto px-2 sm:px-4 lg:px-6 py-6 space-y-5">
    
    <!-- Top Bar: Title & Summary Metrics -->
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 bg-white p-4 sm:p-5 rounded-2xl border border-slate-200 shadow-xs">
        <div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-extrabold uppercase bg-amber-100 text-amber-900 border border-amber-200">
                    <i data-lucide="shield-alert" class="w-3.5 h-3.5 text-amber-700"></i>
                    Verifikācijas panelis
                </span>
                <span class="text-xs text-slate-400 font-semibold font-mono">/admin/unpublished</span>
            </div>
            <h1 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight mt-1">
                Nepublicētie pasākumi & Cenu pārbaude
            </h1>
            <p class="text-xs text-slate-500 mt-0.5">
                Salīdziniet mūsu vietnes cenas un visu valodu (LV, EN, RU) aprakstus ar reālās biļešu tirdzniecības lapas datiem.
            </p>
        </div>

        <!-- Metric Badges -->
        <div class="flex flex-wrap items-center gap-2 sm:gap-3 text-xs font-mono">
            <div class="px-3.5 py-2 bg-amber-50/80 border border-amber-200 text-amber-950 rounded-xl flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                <span class="text-slate-500 uppercase font-bold text-[10px]">Gaidot pārbaudi:</span>
                <span id="statUnpublishedCount" class="font-black text-sm text-amber-900">{{ number_format($unpublishedCount, 0, '.', ' ') }}</span>
            </div>
            <div class="px-3.5 py-2 bg-blue-50/80 border border-blue-200 text-blue-950 rounded-xl flex items-center gap-2">
                <i data-lucide="ticket" class="w-4 h-4 text-blue-600"></i>
                <span class="text-slate-500 uppercase font-bold text-[10px]">Ar biļešu saiti:</span>
                <span class="font-black text-sm text-blue-900">{{ number_format($withTicketUrlCount, 0, '.', ' ') }}</span>
            </div>
            <a href="{{ route('admin.events.index', ['published' => 'published']) }}" class="px-3.5 py-2 bg-slate-50 border border-slate-200 text-slate-700 rounded-xl hover:bg-slate-100 transition-colors flex items-center gap-1.5 font-bold">
                <i data-lucide="table-2" class="w-4 h-4 text-slate-500"></i>
                <span>Pilnā tabula</span>
            </a>
        </div>
    </div>

    <!-- Filter & Search Toolbar (HTMX Driven) -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
        <form id="unpublishedFilterForm" 
              hx-get="{{ route('admin.events.unpublished.list') }}" 
              hx-target="#unpublishedListContainer" 
              hx-indicator="#unpublishedLoadingIndicator"
              hx-push-url="false"
              class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 xl:grid-cols-12 gap-2.5 text-xs">
            
            <!-- Search Text -->
            <div class="sm:col-span-2 md:col-span-3 lg:col-span-3 xl:col-span-4">
                <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1 font-mono">
                    Meklēt tekstā / vietā / saitē
                </label>
                <div class="relative">
                    <input type="text" 
                           id="unpublishedSearchInput"
                           name="search" 
                           placeholder="Ierakstiet nosaukumu, vietu vai URL..." 
                           hx-trigger="keyup changed delay:300ms, search"
                           class="w-full pl-8 pr-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-800 placeholder-slate-400 focus:bg-white focus:outline-hidden focus:border-[#002855] focus:ring-1 focus:ring-[#002855] transition-all">
                    <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-2.5 top-2.5"></i>
                </div>
            </div>

            <!-- Source Filter -->
            <div class="sm:col-span-1 md:col-span-1 lg:col-span-1 xl:col-span-2">
                <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1 font-mono">
                    Avots
                </label>
                <select name="source" 
                        hx-trigger="change"
                        class="w-full px-2.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-800 focus:bg-white focus:outline-hidden focus:border-[#002855] focus:ring-1 focus:ring-[#002855] transition-all">
                    <option value="all">Visi avoti</option>
                    @foreach($sources as $source)
                        <option value="{{ $source->slug }}">{{ $source->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Category Filter -->
            <div class="sm:col-span-1 md:col-span-1 lg:col-span-1 xl:col-span-2">
                <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1 font-mono">
                    Kategorija
                </label>
                <select name="category" 
                        hx-trigger="change"
                        class="w-full px-2.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-800 focus:bg-white focus:outline-hidden focus:border-[#002855] focus:ring-1 focus:ring-[#002855] transition-all">
                    <option value="all">Visas kategorijas</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->slug }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Ticket URL Filter -->
            <div class="sm:col-span-1 md:col-span-1 lg:col-span-1 xl:col-span-2">
                <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1 font-mono">
                    Biļešu saite
                </label>
                <select name="has_ticket_url" 
                        hx-trigger="change"
                        class="w-full px-2.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-800 focus:bg-white focus:outline-hidden focus:border-[#002855] focus:ring-1 focus:ring-[#002855] transition-all">
                    <option value="all">Visi ieraksti</option>
                    <option value="yes">Tikai ar biļešu saiti</option>
                    <option value="no">Bez biļešu saites</option>
                </select>
            </div>

            <!-- Per Page -->
            <div class="sm:col-span-1 md:col-span-1 lg:col-span-1 xl:col-span-1">
                <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1 font-mono">
                    Skaits
                </label>
                <select name="per_page" 
                        hx-trigger="change"
                        class="w-full px-2 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-800 focus:bg-white focus:outline-hidden focus:border-[#002855] focus:ring-1 focus:ring-[#002855] transition-all">
                    <option value="5">5</option>
                    <option value="10" selected>10</option>
                    <option value="20">20</option>
                    <option value="50">50</option>
                </select>
            </div>

            <!-- Sorting -->
            <div class="sm:col-span-1 md:col-span-1 lg:col-span-1 xl:col-span-1 flex items-end">
                <button type="submit" 
                        class="w-full py-2 px-3 bg-[#002855] hover:bg-[#003875] text-white rounded-xl text-xs font-bold transition-all shadow-xs flex items-center justify-center gap-1.5 cursor-pointer">
                    <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i>
                    <span>Ielādēt</span>
                </button>
            </div>
        </form>
    </div>

    <!-- Loading Indicator -->
    <div id="unpublishedLoadingIndicator" class="htmx-indicator flex items-center justify-center py-12 gap-3 text-slate-500 bg-white/70 rounded-2xl border border-slate-200">
        <div class="w-6 h-6 border-3 border-amber-500 border-t-transparent rounded-full animate-spin"></div>
        <span class="text-xs font-bold font-mono">Ielādē nepublicētos pasākumus un cenu lapas datus...</span>
    </div>

    <!-- Dynamic HTMX Container for Event Cards -->
    <div id="unpublishedListContainer" 
         hx-get="{{ route('admin.events.unpublished.list') }}" 
         hx-trigger="load" 
         hx-indicator="#unpublishedLoadingIndicator"
         class="space-y-6">
        <!-- Event cards will be dynamically loaded here via HTMX -->
    </div>

</div>

<script>
    // Handle publishing event from card and visual removal
    document.addEventListener('htmx:afterRequest', function(evt) {
        if (evt.detail && evt.detail.successful && evt.detail.elt && evt.detail.elt.classList.contains('btn-quick-publish')) {
            const card = evt.detail.elt.closest('.unpublished-event-card');
            if (card) {
                card.classList.add('opacity-40', 'scale-[0.98]', 'pointer-events-none', 'transition-all', 'duration-300');
                const badge = card.querySelector('.publish-status-pill');
                if (badge) {
                    badge.className = 'publish-status-pill inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-extrabold bg-emerald-100 text-emerald-800 border border-emerald-300';
                    badge.innerHTML = '<i data-lucide="check-circle-2" class="w-3.5 h-3.5"></i> Publicēts!';
                    lucide.createIcons();
                }
                setTimeout(() => {
                    card.style.display = 'none';
                }, 1200);
            }
        }
    });

    function toggleIframe(id) {
        const frameContainer = document.getElementById('iframe-box-' + id);
        const btn = document.getElementById('btn-iframe-toggle-' + id);
        if (frameContainer) {
            frameContainer.classList.toggle('hidden');
            if (btn) {
                const isHidden = frameContainer.classList.contains('hidden');
                btn.innerHTML = isHidden 
                    ? '<i data-lucide="eye" class="w-3.5 h-3.5"></i> Parādīt iframe' 
                    : '<i data-lucide="eye-off" class="w-3.5 h-3.5"></i> Slēpt iframe';
                lucide.createIcons();
            }
        }
    }

    function reloadIframe(id) {
        const frame = document.getElementById('iframe-el-' + id);
        if (frame) {
            const src = frame.src;
            frame.src = '';
            setTimeout(() => { frame.src = src; }, 50);
        }
    }
</script>
@endsection
