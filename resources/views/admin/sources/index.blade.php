@extends('layouts.admin')

@section('title', 'Pasākumu Avoti un Roboti — Administrācija')
@section('title_topbar', 'ŠODIENA.LV :: AVOTI UN ROBOTI')

@section('content')
<div class="max-w-7xl xl:max-w-[1400px] 2xl:max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <!-- Header -->
    <div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">
                <span>Administrācijas sektors</span>
                <span>/</span>
                <span class="text-blue-600">Avoti & Roboti</span>
            </div>
            <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                <i data-lucide="bot" class="w-8 h-8 text-blue-600"></i>
                Parsēšanas Avoti & Roboti
            </h1>
            <p class="text-sm text-slate-600 mt-1">
                Pārvaldi, palaid un uzraugi automatizētos pasākumu iegūšanas un parsēšanas procesus pa dienām.
            </p>
        </div>

        <div class="flex items-center gap-3">
            <a href="{{ route('admin.events.index') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white border border-slate-200 text-sm font-bold text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors shadow-xs">
                <i data-lucide="arrow-left" class="w-4 h-4"></i>
                Atpakaļ uz pasākumu tabulu
            </a>
        </div>
    </div>

    <!-- System Health & Synchronization Tools (HTMX Powered) -->
    @include('admin.sources.partials.system-status')

    <!-- Sources & Categories Registry (HTMX Powered) -->
    @include('admin.sources.partials.sources-registry')

    <!-- Daily Scrape History Section (HTMX Powered) -->
    @include('admin.sources.partials.scrape-history')
</div>

@push('scripts')
<script>
    function toggleSourceDetails(id) {
        const details = document.getElementById('source-details-' + id);
        const icon = document.getElementById('source-icon-' + id);
        if (!details) return;

        const isHidden = details.classList.contains('hidden');
        if (isHidden) {
            details.classList.remove('hidden');
            if (icon) icon.classList.add('rotate-180');
        } else {
            details.classList.add('hidden');
            if (icon) icon.classList.remove('rotate-180');
        }
    }
</script>
@endpush
@endsection

