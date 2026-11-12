<div id="sources-registry-section">
    <!-- Optional Flash Status from Sync Registry -->
    @php
        $statusMsg = $registryStatus ?? session('status');
        $errorMsg = $registryError ?? session('error');
    @endphp

    @if($statusMsg && !request()->header('HX-Target') || request()->header('HX-Target') === 'sources-registry-section' && $statusMsg)
        <div class="mb-6 p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-900 text-sm font-semibold flex items-center gap-3 shadow-xs">
            <i data-lucide="check-circle-2" class="w-5 h-5 text-emerald-600 shrink-0"></i>
            <div>{{ $statusMsg }}</div>
        </div>
    @endif

    @if($errorMsg && !request()->header('HX-Target') || request()->header('HX-Target') === 'sources-registry-section' && $errorMsg)
        <div class="mb-6 p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-900 text-sm font-semibold flex items-center gap-3 shadow-xs">
            <i data-lucide="alert-circle" class="w-5 h-5 text-rose-600 shrink-0"></i>
            <div>{{ $errorMsg }}</div>
        </div>
    @endif

    <!-- Category Filter Tabs by Source Type (HTMX Powered) -->
    <div class="mb-8 flex flex-wrap items-center gap-2 border-b border-slate-200/80 pb-4">
        <a href="{{ route('admin.sources.index', array_filter(['log_date' => $selectedDate, 'status' => $selectedStatus])) }}" 
           hx-get="{{ route('admin.sources.index', array_filter(['log_date' => $selectedDate, 'status' => $selectedStatus])) }}"
           hx-target="#sources-registry-section"
           hx-swap="outerHTML"
           hx-push-url="true"
           hx-indicator="#registry-loading-indicator"
           class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer {{ empty($selectedType) ? 'bg-slate-900 text-white shadow-xs' : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200' }}">
            <i data-lucide="layout-grid" class="w-3.5 h-3.5"></i>
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
            <a href="{{ route('admin.sources.index', array_filter(['type' => $t->value, 'log_date' => $selectedDate, 'status' => $selectedStatus])) }}" 
               hx-get="{{ route('admin.sources.index', array_filter(['type' => $t->value, 'log_date' => $selectedDate, 'status' => $selectedStatus])) }}"
               hx-target="#sources-registry-section"
               hx-swap="outerHTML"
               hx-push-url="true"
               hx-indicator="#registry-loading-indicator"
               class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer {{ $isActive ? 'bg-slate-900 text-white shadow-xs' : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200' }}">
                <i data-lucide="{{ $t->icon() }}" class="w-3.5 h-3.5"></i>
                {{ $t->label() }}
                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] {{ $isActive ? 'bg-slate-800 text-slate-300' : 'bg-slate-100 text-slate-600' }}">
                    {{ $count }}
                </span>
            </a>
        @endforeach
    </div>

    <!-- Sources Grid -->
    <div class="mb-14">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
            <div>
                <h2 class="text-xl font-extrabold text-slate-900 flex items-center gap-2">
                    <i data-lucide="bot" class="w-5 h-5 text-blue-600"></i>
                    <span>Reģistrētie roboti un avoti ({{ $sources->count() }})</span>
                    <span id="registry-loading-indicator" class="htmx-indicator inline-flex items-center gap-1.5 text-xs font-semibold text-blue-600 bg-blue-50 px-2 py-0.5 rounded-full">
                        <i data-lucide="loader-2" class="w-3.5 h-3.5 animate-spin"></i>
                        <span>Ielādē...</span>
                    </span>
                </h2>
                <p class="text-xs text-slate-500 font-medium mt-0.5">Katru avotu var palaist atsevišķi jebkurā brīdī</p>
            </div>

            <!-- Sync Sources Registry Button (HTMX Powered) -->
            <form 
                hx-post="{{ route('admin.sources.sync-registry') }}" 
                hx-target="#sources-registry-section" 
                hx-swap="outerHTML" 
                hx-disabled-elt="find button"
                hx-indicator="#registry-loading-indicator"
                class="shrink-0">
                @csrf
                @if($selectedType)
                    <input type="hidden" name="type" value="{{ $selectedType }}">
                @endif
                @if($selectedDate)
                    <input type="hidden" name="log_date" value="{{ $selectedDate }}">
                @endif
                @if($selectedStatus)
                    <input type="hidden" name="status" value="{{ $selectedStatus }}">
                @endif
                <button type="submit" 
                        class="py-2 px-3.5 rounded-xl bg-white hover:bg-slate-100 text-slate-700 font-bold text-xs flex items-center gap-2 border border-slate-200 shadow-2xs transition-colors cursor-pointer disabled:opacity-50"
                        title="Nolasa visus definētos skreiperus no koda (SourceSeeder) un reģistrē trūkstošos datubāzē">
                    <i data-lucide="refresh-cw" class="w-3.5 h-3.5 text-blue-600"></i>
                    <span>Sinhronizēt avotus no koda</span>
                </button>
            </form>
        </div>

        @if($sources->isEmpty())
            <div class="bg-white border border-slate-200 rounded-3xl p-12 text-center text-slate-500">
                <i data-lucide="inbox" class="w-10 h-10 mx-auto text-slate-300 mb-3"></i>
                <p class="font-bold text-slate-700">Šajā kategorijā pagaidām nav reģistrētu avotu.</p>
                <p class="text-xs text-slate-400 mt-1">Izvēlies citu filtru vai apskati visus avotus.</p>
            </div>
        @else
            <div class="space-y-2.5">
                @foreach($sources as $source)
                    @include('admin.sources.partials.source-row', ['source' => $source, 'loopIndex' => $loop->iteration])
                @endforeach
            </div>
        @endif
    </div>
</div>

