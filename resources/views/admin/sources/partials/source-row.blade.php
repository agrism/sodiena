<div id="source-row-{{ $source->id }}" class="bg-white rounded-2xl border border-slate-200/90 shadow-2xs hover:border-slate-300 transition-all overflow-hidden">
    <!-- Main Compact Row (Clickable to Expand) -->
    <div class="p-4 sm:p-4.5 flex flex-col lg:flex-row lg:items-center justify-between gap-4 cursor-pointer select-none hover:bg-slate-50/60 transition-colors"
         onclick="toggleSourceDetails({{ $source->id }})">
        
        <!-- Left: Source Identity & Badges -->
        <div class="flex items-center gap-3 sm:gap-3.5 min-w-0">
            <!-- Row Sequence Number (Kārtas Nr.) -->
            <span class="w-6 sm:w-7 text-right font-mono text-xs font-bold text-slate-400 shrink-0 select-none">
                {{ isset($loopIndex) && $loopIndex !== '' ? "{$loopIndex}." : (isset($loop) && isset($loop->iteration) ? "{$loop->iteration}." : '') }}
            </span>

            <!-- Active Status Indicator Dot -->
            <div class="shrink-0">
                <span class="relative flex h-3 w-3" title="{{ $source->is_active ? 'Aktīvs avots' : 'Neaktīvs avots' }}">
                    @if($source->is_active)
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-3 w-3 bg-emerald-500"></span>
                    @else
                        <span class="relative inline-flex rounded-full h-3 w-3 bg-slate-300"></span>
                    @endif
                </span>
            </div>

            <!-- Title, URL & Type -->
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-sm font-extrabold text-slate-900 truncate">
                        {{ $source->name }}
                    </h3>

                    @if($source->type)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold border {{ $source->type->badgeClasses() }}">
                            <i data-lucide="{{ $source->type->icon() }}" class="w-3 h-3"></i>
                            {{ $source->type->label() }}
                        </span>
                    @endif
                </div>

                @if($source->url)
                    <a href="{{ $source->url }}" target="_blank" rel="noopener noreferrer" 
                       onclick="event.stopPropagation()"
                       class="text-[11px] text-slate-400 hover:text-blue-600 truncate block max-w-md mt-0.5">
                        {{ $source->url }}
                    </a>
                @endif
            </div>
        </div>

        <!-- Right: Status, Last Run, Events Count & Actions -->
        <div class="flex flex-wrap items-center justify-between lg:justify-end gap-4 shrink-0 border-t lg:border-t-0 pt-3 lg:pt-0 border-slate-100">
            <!-- Status Badge -->
            <div class="min-w-[110px]">
                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-0.5">Statuss</div>
                <div>
                    @if(in_array($source->last_status, ['started', 'running'], true))
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-blue-50 text-blue-700 border border-blue-200 animate-pulse">
                            <span class="w-1.5 h-1.5 rounded-full bg-blue-600 animate-ping"></span>
                            Procesā...
                        </span>
                    @elseif($source->last_status === 'success')
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                            <i data-lucide="check-circle-2" class="w-3 h-3 text-emerald-600"></i>
                            Veiksmīgs
                        </span>
                    @elseif($source->last_status === 'partial')
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                            <i data-lucide="alert-triangle" class="w-3 h-3 text-amber-600"></i>
                            Daļējs
                        </span>
                    @elseif($source->last_status === 'failed')
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                            <i data-lucide="alert-circle" class="w-3 h-3 text-rose-600"></i>
                            Kļūda
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-slate-100 text-slate-600">
                            Gaidīšanā
                        </span>
                    @endif
                </div>
            </div>

            <!-- Last Scraped Time -->
            <div class="min-w-[130px]">
                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-0.5">Pēdējoreiz parsēts</div>
                <div class="text-xs font-bold text-slate-700">
                    {{ $source->last_scraped_at ? $source->last_scraped_at->diffForHumans() : 'Vēl nav palaists' }}
                </div>
            </div>

            <!-- Events Count Metric -->
            <div class="min-w-[80px] text-right">
                <span class="text-xl font-black text-emerald-600">{{ $source->events_count ?? $source->events()->count() }}</span>
                <span class="text-[10px] text-slate-400 font-bold block uppercase -mt-0.5">Notikumi</span>
            </div>

            <!-- Action Button: Trigger Scrape Now -->
            <div class="shrink-0" onclick="event.stopPropagation()">
                <form 
                    hx-post="{{ route('admin.sources.scrape', $source->id) }}"
                    hx-target="#source-row-{{ $source->id }}"
                    hx-swap="outerHTML"
                    hx-disabled-elt="find button"
                    @if(isset($loopIndex))
                        hx-vals='{"loop_index": "{{ $loopIndex }}"}'
                    @endif
                    class="inline-block">
                    @csrf
                    <button 
                        type="submit" 
                        class="py-2 px-3 rounded-xl bg-slate-100 hover:bg-emerald-600 hover:text-white text-slate-800 font-bold text-xs flex items-center gap-1.5 border border-slate-200 transition-all duration-200 cursor-pointer disabled:opacity-80 disabled:cursor-wait disabled:bg-blue-50 disabled:text-blue-700 disabled:border-blue-200 shadow-2xs"
                        title="Palaist parsēšanu avotam '{{ $source->name }}'">
                        <span class="htmx-normal inline-flex items-center gap-1.5">
                            <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i>
                            <span>Palaist</span>
                        </span>
                        <span class="htmx-loading items-center gap-1.5 text-blue-700 font-bold">
                            <i data-lucide="loader-2" class="w-3.5 h-3.5 animate-spin text-blue-600"></i>
                            <span>Parsē...</span>
                        </span>
                    </button>
                </form>
            </div>

            <!-- Expand / Details Chevron Button -->
            <div class="shrink-0 pl-1">
                <button type="button" 
                        class="p-1.5 rounded-xl hover:bg-slate-200/60 text-slate-400 hover:text-slate-700 transition-colors"
                        title="Rādīt detalizētu informāciju">
                    <i id="source-icon-{{ $source->id }}" data-lucide="chevron-down" class="w-4 h-4 transition-transform duration-200"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Collapsible Details Panel (Sliding Downwards) -->
    <div id="source-details-{{ $source->id }}" class="hidden border-t border-slate-200 bg-slate-50/80 p-5 space-y-4">
        <!-- Grid with Details -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
            <!-- Source Technical Info -->
            <div class="bg-white p-3.5 rounded-xl border border-slate-200/80 shadow-2xs space-y-1.5">
                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Tehniskā informācija</div>
                <div class="flex justify-between">
                    <span class="text-slate-500">Skreipera klase:</span>
                    <span class="font-mono text-[11px] text-slate-800 truncate max-w-[180px]" title="{{ $source->scraper_class }}">
                        {{ class_basename($source->scraper_class) }}
                    </span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-500">Datu avota ID:</span>
                    <strong class="text-slate-800 font-mono">#{{ $source->id }} ({{ $source->slug }})</strong>
                </div>
                @if($source->type)
                    <div class="flex justify-between pt-1 border-t border-slate-100">
                        <span class="text-slate-500">Prioritāte deduplikācijā:</span>
                        <strong class="text-slate-800">{{ $source->type->priority() }}/100</strong>
                    </div>
                @endif
            </div>

            <!-- Execution Status & Timing -->
            <div class="bg-white p-3.5 rounded-xl border border-slate-200/80 shadow-2xs space-y-1.5">
                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Parsēšanas laiki</div>
                <div class="flex justify-between">
                    <span class="text-slate-500">Pēdējais sākums:</span>
                    <strong class="text-slate-800 font-mono text-[11px]">
                        {{ $source->last_scraped_at ? $source->last_scraped_at->format('d.m.Y H:i:s') : '—' }}
                    </strong>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-500">Kopā datubāzē:</span>
                    <strong class="text-emerald-700 font-bold">{{ $source->events_count ?? $source->events()->count() }} pasākumi</strong>
                </div>
                <div class="flex justify-between pt-1 border-t border-slate-100">
                    <span class="text-slate-500">Avota saite:</span>
                    <a href="{{ $source->url }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline inline-flex items-center gap-1 font-semibold">
                        Atvērt mājaslapu <i data-lucide="external-link" class="w-3 h-3"></i>
                    </a>
                </div>
            </div>

            <!-- Actions Card -->
            <div class="bg-white p-3.5 rounded-xl border border-slate-200/80 shadow-2xs flex flex-col justify-between">
                <div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Darbības ar robotu</div>
                    <p class="text-[11px] text-slate-500">
                        Palaid šī avota datu nolasīšanu ar tūlītēju atjaunināšanu un automātisku sitemap sagatavošanu.
                    </p>
                </div>

                <div class="pt-2">
                    <form 
                        hx-post="{{ route('admin.sources.scrape', $source->id) }}"
                        hx-target="#source-row-{{ $source->id }}"
                        hx-swap="outerHTML"
                        hx-disabled-elt="find button"
                        @if(isset($loopIndex))
                            hx-vals='{"loop_index": "{{ $loopIndex }}"}'
                        @endif
                        class="w-full">
                        @csrf
                        <button 
                            type="submit" 
                            class="w-full py-2 px-3 rounded-xl bg-slate-900 hover:bg-emerald-600 text-white font-bold text-xs flex items-center justify-center gap-2 transition-colors cursor-pointer disabled:opacity-80 disabled:cursor-wait disabled:bg-blue-600 shadow-2xs">
                            <span class="htmx-normal inline-flex items-center gap-2">
                                <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i>
                                <span>Palaist parsēšanu tagad</span>
                            </span>
                            <span class="htmx-loading items-center gap-2 text-white font-bold">
                                <i data-lucide="loader-2" class="w-3.5 h-3.5 animate-spin"></i>
                                <span>Parsēšana procesā...</span>
                            </span>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Last Error Block (if any) -->
        @if($source->last_error)
            <div class="p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-900 text-xs">
                <div class="font-bold flex items-center gap-1.5 text-rose-800 mb-1">
                    <i data-lucide="alert-circle" class="w-4 h-4 text-rose-600"></i>
                    Pēdējā reģistrētā kļūda šim avotam:
                </div>
                <div class="font-mono text-[11px] text-rose-800 bg-white/70 p-2 rounded-lg border border-rose-200/80 overflow-x-auto">
                    {{ $source->last_error }}
                </div>
            </div>
        @endif

        <!-- Quick Link to Scrape Logs History -->
        <div class="pt-2 flex items-center justify-between text-[11px] text-slate-500 border-t border-slate-200/80">
            <span class="inline-flex items-center gap-1.5">
                <i data-lucide="info" class="w-3.5 h-3.5 text-slate-400"></i>
                Pilnu vēstures žurnālu ar atsevišķu palaišanas reižu kļūdām un laikiem skatiet zemāk tabulā.
            </span>
            <a href="#scrape-logs-history" class="text-blue-600 hover:text-blue-800 font-semibold inline-flex items-center gap-1">
                Doties uz žurnālu <i data-lucide="arrow-down" class="w-3 h-3"></i>
            </a>
        </div>
    </div>
</div>
