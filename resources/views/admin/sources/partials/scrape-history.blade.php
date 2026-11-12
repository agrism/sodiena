<div id="scrape-history-section" class="mt-14 pt-10 border-t border-slate-200">
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6">
        <div>
            <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight flex items-center gap-2.5">
                <i data-lucide="history" class="w-6 h-6 text-emerald-600"></i>
                Parsēšanas vēsture un žurnāls pa dienām
                <span id="history-loading-indicator" class="htmx-indicator ml-2 inline-flex items-center gap-1.5 text-xs font-semibold text-blue-600 bg-blue-50 px-2 py-0.5 rounded-full">
                    <i data-lucide="loader-2" class="w-3.5 h-3.5 animate-spin"></i>
                    <span>Atjaunina...</span>
                </span>
            </h2>
            <p class="text-xs text-slate-500 mt-1">
                Apskati konkrētās dienas robotu darbības rezultātus, statusus (started, failed, success), atrastos/izveidotos/atjaunotos pasākumus un kļūdu iemeslus.
            </p>
        </div>

        <!-- Date Selector Form (HTMX) -->
        <form method="GET" 
              action="{{ route('admin.sources.index') }}" 
              hx-get="{{ route('admin.sources.index') }}" 
              hx-target="#scrape-history-section" 
              hx-swap="outerHTML" 
              hx-push-url="true" 
              hx-indicator="#history-loading-indicator"
              class="flex items-center gap-2">
            @if($selectedType)
                <input type="hidden" name="type" value="{{ $selectedType }}">
            @endif
            @if($selectedStatus)
                <input type="hidden" name="status" value="{{ $selectedStatus }}">
            @endif
            <div class="flex items-center gap-2 bg-white px-3 py-1.5 rounded-xl border border-slate-200 shadow-2xs hover:border-slate-300 transition-colors">
                <i data-lucide="calendar" class="w-4 h-4 text-slate-400"></i>
                <input 
                    type="date" 
                    name="log_date" 
                    value="{{ $selectedDate }}" 
                    hx-get="{{ route('admin.sources.index') }}" 
                    hx-trigger="change" 
                    hx-target="#scrape-history-section" 
                    hx-swap="outerHTML" 
                    hx-push-url="true" 
                    hx-include="closest form"
                    hx-indicator="#history-loading-indicator"
                    class="text-xs font-bold text-slate-800 bg-transparent border-0 focus:ring-0 p-0 cursor-pointer">
            </div>
        </form>
    </div>

    <!-- Date Tabs / Recent Days Navigation (HTMX) -->
    @if($availableDates->isNotEmpty())
        <div class="flex items-center gap-2 overflow-x-auto pb-3 mb-6 scrollbar-none">
            @foreach($availableDates as $dateItem)
                @php
                    $isCurrentTab = ($selectedDate === $dateItem->log_date);
                    $dateObj = \Carbon\Carbon::parse($dateItem->log_date);
                    $dateLabel = $dateObj->isToday() 
                        ? 'Šodien (' . $dateObj->format('d.m.') . ')' 
                        : ($dateObj->isYesterday() ? 'Vakar (' . $dateObj->format('d.m.') . ')' : $dateObj->format('d.m.Y'));
                @endphp
                <a href="{{ route('admin.sources.index', array_filter(['log_date' => $dateItem->log_date, 'type' => $selectedType, 'status' => $selectedStatus])) }}" 
                   hx-get="{{ route('admin.sources.index', array_filter(['log_date' => $dateItem->log_date, 'type' => $selectedType, 'status' => $selectedStatus])) }}"
                   hx-target="#scrape-history-section"
                   hx-swap="outerHTML"
                   hx-push-url="true"
                   hx-indicator="#history-loading-indicator"
                   class="shrink-0 inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer {{ $isCurrentTab ? 'bg-blue-600 text-white shadow-xs' : 'bg-white text-slate-700 hover:bg-slate-50 border border-slate-200' }}">
                    <i data-lucide="calendar" class="w-3.5 h-3.5 opacity-80"></i>
                    {{ $dateLabel }}
                    <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] {{ $isCurrentTab ? 'bg-blue-700 text-white' : 'bg-slate-100 text-slate-600' }}">
                        {{ $dateItem->total_runs }}
                    </span>
                    @if($dateItem->failed_runs > 0)
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-extrabold {{ $isCurrentTab ? 'bg-rose-500 text-white' : 'bg-rose-100 text-rose-700' }}" title="{{ $dateItem->failed_runs }} kļūmes šajā dienā">
                            {{ $dateItem->failed_runs }} kļūdas
                        </span>
                    @endif
                </a>
            @endforeach
        </div>
    @endif

    <!-- Day Summary Stats Cards (Clickable with HTMX filter) -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
        <!-- Total Runs (Reset status filter) -->
        <a href="{{ route('admin.sources.index', array_filter(['log_date' => $selectedDate, 'type' => $selectedType])) }}"
           hx-get="{{ route('admin.sources.index', array_filter(['log_date' => $selectedDate, 'type' => $selectedType])) }}"
           hx-target="#scrape-history-section"
           hx-swap="outerHTML"
           hx-push-url="true"
           hx-indicator="#history-loading-indicator"
           class="bg-white p-4 rounded-2xl border transition-all cursor-pointer hover:shadow-sm {{ empty($selectedStatus) ? 'border-slate-800 ring-2 ring-slate-800/10' : 'border-slate-200' }} shadow-2xs block">
            <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Kopā palaisti</div>
            <div class="text-2xl font-black text-slate-900 mt-1">{{ $dayStats['total'] }}</div>
            <div class="text-[10px] text-slate-400 mt-0.5">parsēšanas reizes</div>
        </a>

        <!-- Success (Filter by success) -->
        <a href="{{ route('admin.sources.index', array_filter(['log_date' => $selectedDate, 'type' => $selectedType, 'status' => 'success'])) }}"
           hx-get="{{ route('admin.sources.index', array_filter(['log_date' => $selectedDate, 'type' => $selectedType, 'status' => 'success'])) }}"
           hx-target="#scrape-history-section"
           hx-swap="outerHTML"
           hx-push-url="true"
           hx-indicator="#history-loading-indicator"
           class="bg-white p-4 rounded-2xl border transition-all cursor-pointer hover:shadow-sm {{ $selectedStatus === 'success' ? 'border-emerald-600 ring-2 ring-emerald-600/20 bg-emerald-50/40' : 'border-emerald-200/80 bg-emerald-50/20' }} shadow-2xs block">
            <div class="text-[11px] font-bold text-emerald-700 uppercase tracking-wider flex items-center gap-1">
                <i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-600"></i> Veiksmīgi
            </div>
            <div class="text-2xl font-black text-emerald-700 mt-1">{{ $dayStats['success'] }}</div>
            <div class="text-[10px] text-emerald-600/70 mt-0.5">bez kļūdām</div>
        </a>

        <!-- Partial (Filter by partial) -->
        <a href="{{ route('admin.sources.index', array_filter(['log_date' => $selectedDate, 'type' => $selectedType, 'status' => 'partial'])) }}"
           hx-get="{{ route('admin.sources.index', array_filter(['log_date' => $selectedDate, 'type' => $selectedType, 'status' => 'partial'])) }}"
           hx-target="#scrape-history-section"
           hx-swap="outerHTML"
           hx-push-url="true"
           hx-indicator="#history-loading-indicator"
           class="bg-white p-4 rounded-2xl border transition-all cursor-pointer hover:shadow-sm {{ $selectedStatus === 'partial' ? 'border-amber-600 ring-2 ring-amber-600/20 bg-amber-50/40' : 'border-amber-200/80 bg-amber-50/20' }} shadow-2xs block">
            <div class="text-[11px] font-bold text-amber-700 uppercase tracking-wider flex items-center gap-1">
                <i data-lucide="alert-triangle" class="w-3.5 h-3.5 text-amber-600"></i> Daļēji
            </div>
            <div class="text-2xl font-black text-amber-700 mt-1">{{ $dayStats['partial'] }}</div>
            <div class="text-[10px] text-amber-600/70 mt-0.5">ar brīdinājumiem</div>
        </a>

        <!-- Failed (Filter by failed) -->
        <a href="{{ route('admin.sources.index', array_filter(['log_date' => $selectedDate, 'type' => $selectedType, 'status' => 'failed'])) }}"
           hx-get="{{ route('admin.sources.index', array_filter(['log_date' => $selectedDate, 'type' => $selectedType, 'status' => 'failed'])) }}"
           hx-target="#scrape-history-section"
           hx-swap="outerHTML"
           hx-push-url="true"
           hx-indicator="#history-loading-indicator"
           class="bg-white p-4 rounded-2xl border transition-all cursor-pointer hover:shadow-sm {{ $selectedStatus === 'failed' ? 'border-rose-600 ring-2 ring-rose-600/20 bg-rose-50/40' : ($dayStats['failed'] > 0 ? 'border-rose-300 bg-rose-50/30' : 'border-slate-200') }} shadow-2xs block">
            <div class="text-[11px] font-bold {{ $dayStats['failed'] > 0 ? 'text-rose-700' : 'text-slate-500' }} uppercase tracking-wider flex items-center gap-1">
                <i data-lucide="alert-circle" class="w-3.5 h-3.5 text-rose-600"></i> Kļūmes
            </div>
            <div class="text-2xl font-black {{ $dayStats['failed'] > 0 ? 'text-rose-700' : 'text-slate-900' }} mt-1">{{ $dayStats['failed'] }}</div>
            <div class="text-[10px] {{ $dayStats['failed'] > 0 ? 'text-rose-600 font-bold' : 'text-slate-400' }} mt-0.5">
                {{ $dayStats['failed'] > 0 ? 'nepieciešama uzmanība' : '0 kļūmju' }}
            </div>
        </a>

        <!-- Started / In Progress (Filter by started) -->
        <a href="{{ route('admin.sources.index', array_filter(['log_date' => $selectedDate, 'type' => $selectedType, 'status' => 'started'])) }}"
           hx-get="{{ route('admin.sources.index', array_filter(['log_date' => $selectedDate, 'type' => $selectedType, 'status' => 'started'])) }}"
           hx-target="#scrape-history-section"
           hx-swap="outerHTML"
           hx-push-url="true"
           hx-indicator="#history-loading-indicator"
           class="bg-white p-4 rounded-2xl border transition-all cursor-pointer hover:shadow-sm {{ $selectedStatus === 'started' ? 'border-blue-600 ring-2 ring-blue-600/20 bg-blue-50/40' : ($dayStats['started'] > 0 ? 'border-blue-300 bg-blue-50/30' : 'border-slate-200') }} shadow-2xs block">
            <div class="text-[11px] font-bold {{ $dayStats['started'] > 0 ? 'text-blue-700' : 'text-slate-500' }} uppercase tracking-wider flex items-center gap-1">
                <i data-lucide="clock" class="w-3.5 h-3.5 text-blue-600"></i> Procesā
            </div>
            <div class="text-2xl font-black {{ $dayStats['started'] > 0 ? 'text-blue-700' : 'text-slate-900' }} mt-1 flex items-center gap-2">
                {{ $dayStats['started'] }}
                @if($dayStats['started'] > 0)
                    <span class="w-2.5 h-2.5 rounded-full bg-blue-500 animate-ping"></span>
                @endif
            </div>
            <div class="text-[10px] text-slate-400 mt-0.5">aktīvi vai nepabeigti</div>
        </a>

        <!-- Changes / Items Found & Created -->
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-2xs">
            <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Pasākumi dienas laikā</div>
            <div class="mt-1 flex items-baseline gap-2">
                <span class="text-lg font-black text-slate-900" title="Atrasti pasākumi">{{ $dayStats['items_found'] }}</span>
                <span class="text-xs font-extrabold text-emerald-600" title="Jauni izveidoti">+{{ $dayStats['items_created'] }}</span>
                <span class="text-xs font-bold text-blue-600" title="Atjaunināti">~{{ $dayStats['items_updated'] }}</span>
            </div>
            <div class="text-[10px] text-slate-400 mt-0.5">Ilgums: {{ $dayStats['total_duration'] }}s</div>
        </div>
    </div>

    <!-- Status Filter Pills (HTMX) -->
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-1.5">
            <span class="text-xs font-bold text-slate-500 mr-1">Filtrēt pēc statusa:</span>
            
            <a href="{{ route('admin.sources.index', array_filter(['log_date' => $selectedDate, 'type' => $selectedType])) }}" 
               hx-get="{{ route('admin.sources.index', array_filter(['log_date' => $selectedDate, 'type' => $selectedType])) }}"
               hx-target="#scrape-history-section"
               hx-swap="outerHTML"
               hx-push-url="true"
               hx-indicator="#history-loading-indicator"
               class="px-2.5 py-1 rounded-lg text-xs font-bold transition-colors cursor-pointer {{ empty($selectedStatus) ? 'bg-slate-800 text-white' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50' }}">
                Visi ({{ $dayStats['total'] }})
            </a>
            
            <a href="{{ route('admin.sources.index', array_filter(['log_date' => $selectedDate, 'type' => $selectedType, 'status' => 'success'])) }}" 
               hx-get="{{ route('admin.sources.index', array_filter(['log_date' => $selectedDate, 'type' => $selectedType, 'status' => 'success'])) }}"
               hx-target="#scrape-history-section"
               hx-swap="outerHTML"
               hx-push-url="true"
               hx-indicator="#history-loading-indicator"
               class="px-2.5 py-1 rounded-lg text-xs font-bold transition-colors cursor-pointer {{ $selectedStatus === 'success' ? 'bg-emerald-600 text-white' : 'bg-white text-emerald-700 border border-emerald-200 hover:bg-emerald-50' }}">
                Veiksmīgie ({{ $dayStats['success'] }})
            </a>

            @if($dayStats['partial'] > 0 || $selectedStatus === 'partial')
                <a href="{{ route('admin.sources.index', array_filter(['log_date' => $selectedDate, 'type' => $selectedType, 'status' => 'partial'])) }}" 
                   hx-get="{{ route('admin.sources.index', array_filter(['log_date' => $selectedDate, 'type' => $selectedType, 'status' => 'partial'])) }}"
                   hx-target="#scrape-history-section"
                   hx-swap="outerHTML"
                   hx-push-url="true"
                   hx-indicator="#history-loading-indicator"
                   class="px-2.5 py-1 rounded-lg text-xs font-bold transition-colors cursor-pointer {{ $selectedStatus === 'partial' ? 'bg-amber-600 text-white' : 'bg-white text-amber-700 border border-amber-200 hover:bg-amber-50' }}">
                    Daļēji ({{ $dayStats['partial'] }})
                </a>
            @endif

            @if($dayStats['failed'] > 0 || $selectedStatus === 'failed')
                <a href="{{ route('admin.sources.index', array_filter(['log_date' => $selectedDate, 'type' => $selectedType, 'status' => 'failed'])) }}" 
                   hx-get="{{ route('admin.sources.index', array_filter(['log_date' => $selectedDate, 'type' => $selectedType, 'status' => 'failed'])) }}"
                   hx-target="#scrape-history-section"
                   hx-swap="outerHTML"
                   hx-push-url="true"
                   hx-indicator="#history-loading-indicator"
                   class="px-2.5 py-1 rounded-lg text-xs font-bold transition-colors cursor-pointer {{ $selectedStatus === 'failed' ? 'bg-rose-600 text-white' : 'bg-white text-rose-700 border border-rose-200 hover:bg-rose-50' }}">
                    Kļūdas ({{ $dayStats['failed'] }})
                </a>
            @endif

            @if($dayStats['started'] > 0 || $selectedStatus === 'started')
                <a href="{{ route('admin.sources.index', array_filter(['log_date' => $selectedDate, 'type' => $selectedType, 'status' => 'started'])) }}" 
                   hx-get="{{ route('admin.sources.index', array_filter(['log_date' => $selectedDate, 'type' => $selectedType, 'status' => 'started'])) }}"
                   hx-target="#scrape-history-section"
                   hx-swap="outerHTML"
                   hx-push-url="true"
                   hx-indicator="#history-loading-indicator"
                   class="px-2.5 py-1 rounded-lg text-xs font-bold transition-colors cursor-pointer {{ $selectedStatus === 'started' ? 'bg-blue-600 text-white' : 'bg-white text-blue-700 border border-blue-200 hover:bg-blue-50' }}">
                    Procesā ({{ $dayStats['started'] }})
                </a>
            @endif
        </div>

        <div class="text-xs text-slate-500 font-medium">
            Izvēlēts datums: <strong class="text-slate-800">{{ \Carbon\Carbon::parse($selectedDate)->format('d.m.Y') }}</strong>
        </div>
    </div>

    <!-- Detailed Scrape Logs Table -->
    <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-700">
                <thead class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider border-b border-slate-200">
                    <tr>
                        <th class="py-3 px-3 w-10 text-center text-slate-400">#</th>
                        <th class="py-3 px-4">Avots</th>
                        <th class="py-3 px-4">Statuss</th>
                        <th class="py-3 px-4">Sākts</th>
                        <th class="py-3 px-4">Ilgums</th>
                        <th class="py-3 px-4">Atrasti</th>
                        <th class="py-3 px-4">Izveidoti</th>
                        <th class="py-3 px-4">Atjaunoti</th>
                        <th class="py-3 px-4 min-w-[200px]">Kļūda / Iemesls</th>
                        <th class="py-3 px-4 text-right">Darbība</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    @forelse($dayLogs as $log)
                        <tr class="hover:bg-slate-50/80 transition-colors {{ $log->isFailed() ? 'bg-rose-50/20' : '' }}">
                            <!-- Row Number -->
                            <td class="py-3.5 px-3 text-center font-mono text-[11px] text-slate-400 font-bold select-none">
                                {{ $loop->iteration }}.
                            </td>

                            <!-- Source Name & Badge -->
                            <td class="py-3.5 px-4">
                                <div class="font-bold text-slate-900 flex items-center gap-1.5">
                                    {{ $log->source?->name ?? 'Nezināms avots #' . $log->source_id }}
                                    @if($log->source?->type)
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-slate-100 text-slate-600">
                                            {{ $log->source->type->label() }}
                                        </span>
                                    @endif
                                </div>
                                @if($log->source?->url)
                                    <a href="{{ $log->source->url }}" target="_blank" rel="noopener noreferrer" class="text-[11px] text-slate-400 hover:text-blue-600 truncate block max-w-xs">
                                        {{ $log->source->url }}
                                    </a>
                                @endif
                            </td>

                            <!-- Status Badge -->
                            <td class="py-3.5 px-4 whitespace-nowrap">
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold border {{ $log->statusBadgeClasses() }}">
                                    @if($log->isStarted())
                                        <span class="w-1.5 h-1.5 rounded-full bg-blue-600 animate-ping"></span>
                                    @else
                                        <i data-lucide="{{ $log->statusIcon() }}" class="w-3 h-3"></i>
                                    @endif
                                    {{ $log->statusLabel() }}
                                </span>
                            </td>

                            <!-- Started At Timestamp -->
                            <td class="py-3.5 px-4 whitespace-nowrap text-slate-600 font-mono text-[11px]">
                                {{ $log->started_at ? $log->started_at->format('H:i:s') : '—' }}
                                <span class="block text-[10px] text-slate-400 font-sans">
                                    {{ $log->started_at ? $log->started_at->format('d.m.Y') : '' }}
                                </span>
                            </td>

                            <!-- Duration -->
                            <td class="py-3.5 px-4 whitespace-nowrap font-mono text-[11px] text-slate-700">
                                {{ $log->getFormattedDuration() }}
                            </td>

                            <!-- Items Found -->
                            <td class="py-3.5 px-4 font-bold text-slate-800">
                                {{ $log->items_found }}
                            </td>

                            <!-- Items Created -->
                            <td class="py-3.5 px-4">
                                @if($log->items_created > 0)
                                    <span class="font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200 text-[11px]">
                                        +{{ $log->items_created }}
                                    </span>
                                @else
                                    <span class="text-slate-400">0</span>
                                @endif
                            </td>

                            <!-- Items Updated -->
                            <td class="py-3.5 px-4">
                                @if($log->items_updated > 0)
                                    <span class="font-bold text-blue-700 bg-blue-50 px-2 py-0.5 rounded border border-blue-200 text-[11px]">
                                        {{ $log->items_updated }}
                                    </span>
                                @else
                                    <span class="text-slate-400">0</span>
                                @endif
                            </td>

                            <!-- Error Reason / Details -->
                            <td class="py-3.5 px-4">
                                @if($log->isFailed() || $log->isPartial())
                                    @php $primaryError = $log->getPrimaryError(); @endphp
                                    <div class="text-rose-700 font-medium text-[11px] max-w-md">
                                        <span>{{ $primaryError ?: 'Parsēšana beidzās ar kļūdu.' }}</span>
                                        
                                        @if(!empty($log->errors) && count($log->errors) > 1)
                                            <details class="mt-1">
                                                <summary class="cursor-pointer text-[10px] font-bold text-rose-600 hover:text-rose-800 select-none">
                                                    Skatīt visas {{ count($log->errors) }} kļūdas
                                                </summary>
                                                <div class="mt-1 p-2 rounded bg-rose-50 border border-rose-200 text-[10px] text-rose-900 font-mono space-y-1 max-h-36 overflow-y-auto">
                                                    @foreach($log->errors as $err)
                                                        <div class="pb-1 border-b border-rose-100 last:border-0 last:pb-0">
                                                            {{ is_array($err) ? json_encode($err, JSON_UNESCAPED_UNICODE) : $err }}
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </details>
                                        @endif
                                    </div>
                                @elseif($log->isStarted())
                                    <span class="text-blue-600 text-[11px] flex items-center gap-1.5 animate-pulse">
                                        <i data-lucide="loader-2" class="w-3 h-3 animate-spin"></i>
                                        Parsēšana pašlaik tiek izpildīta...
                                    </span>
                                @else
                                    <span class="text-emerald-700 text-[11px] flex items-center gap-1">
                                        <i data-lucide="check" class="w-3 h-3 text-emerald-600"></i> Pabeigts veiksmīgi
                                    </span>
                                @endif
                            </td>

                            <!-- Actions: Run Scraper Now (HTMX) -->
                            <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                @if($log->source_id)
                                    <form 
                                        hx-post="{{ route('admin.sources.scrape', $log->source_id) }}" 
                                        hx-target="#scrape-history-section" 
                                        hx-swap="outerHTML"
                                        hx-indicator="#history-loading-indicator"
                                        hx-vals='{"log_date": "{{ $selectedDate }}", "status": "{{ $selectedStatus }}", "type": "{{ $selectedType }}"}'
                                        class="inline-block">
                                        @csrf
                                        <button 
                                            type="submit" 
                                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-blue-600 hover:text-white text-slate-700 text-[11px] font-bold border border-slate-200 transition-colors shadow-2xs cursor-pointer disabled:opacity-50"
                                            title="Palaist parsēšanu avotam '{{ $log->source?->name }}'">
                                            <i data-lucide="refresh-cw" class="w-3 h-3"></i>
                                            Palaist tagad
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="py-10 text-center text-slate-400">
                                <i data-lucide="inbox" class="w-8 h-8 mx-auto text-slate-300 mb-2"></i>
                                Izvēlētajā datumā ({{ \Carbon\Carbon::parse($selectedDate)->format('d.m.Y') }}) nav reģistrēts neviens parsēšanas žurnāla ieraksts.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination with HTMX boost -->
        @if($dayLogs->hasPages())
            <div class="p-4 border-t border-slate-200 bg-slate-50" 
                 hx-boost="true" 
                 hx-target="#scrape-history-section" 
                 hx-swap="outerHTML"
                 hx-indicator="#history-loading-indicator">
                {{ $dayLogs->links() }}
            </div>
        @endif
    </div>
</div>

