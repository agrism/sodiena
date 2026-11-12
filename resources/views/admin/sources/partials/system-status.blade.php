<div id="system-status-container">
    <!-- Flash Messages (Live HTMX & Session) -->
    @php
        $statusMsg = $flashStatus ?? session('status');
        $errorMsg = $flashError ?? session('error');
    @endphp

    @if($statusMsg)
        <div class="mb-6 p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-900 text-sm font-semibold flex items-center gap-3 shadow-xs">
            <i data-lucide="check-circle-2" class="w-5 h-5 text-emerald-600 shrink-0"></i>
            <div>{{ $statusMsg }}</div>
        </div>
    @endif

    @if($errorMsg)
        <div class="mb-6 p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-900 text-sm font-semibold flex items-center gap-3 shadow-xs">
            <i data-lucide="alert-circle" class="w-5 h-5 text-rose-600 shrink-0"></i>
            <div>{{ $errorMsg }}</div>
        </div>
    @endif

    <!-- Active Running Processes Alerts -->
    @if(!empty($processStatuses['scrape_all']['is_running']))
        <div class="mb-4 p-4 rounded-2xl bg-purple-50 border border-purple-200 text-purple-900 text-xs font-bold flex items-center justify-between shadow-2xs animate-pulse">
            <div class="flex items-center gap-2.5">
                <span class="w-2.5 h-2.5 rounded-full bg-purple-600 animate-ping"></span>
                <span>🤖 <strong>Pilnā pasākumu parsēšana pašlaik darbojas fonā</strong> (sākta {{ $processStatuses['scrape_all']['started_for_humans'] }}). Pēc tās secīgi izpildīsies attēlu un tulkojumu sinhronizācija.</span>
            </div>
            <span class="text-[11px] font-mono text-purple-700">Procesā...</span>
        </div>
    @endif

    @if(!empty($processStatuses['sync_translations']['is_running']))
        <div class="mb-4 p-4 rounded-2xl bg-blue-50 border border-blue-200 text-blue-900 text-xs font-bold flex items-center justify-between shadow-2xs animate-pulse">
            <div class="flex items-center gap-2.5">
                <span class="w-2.5 h-2.5 rounded-full bg-blue-600 animate-ping"></span>
                <span>🌐 <strong>Tulkojumu skripts pašlaik strādā fonā</strong> (sākts {{ $processStatuses['sync_translations']['started_for_humans'] }}). Tiek tulkoti pasākumu nosaukumi un apraksti (LV &rarr; EN, RU).</span>
            </div>
            <span class="text-[11px] font-mono text-blue-700">Tulko...</span>
        </div>
    @endif

    @if(!empty($processStatuses['sync_images']['is_running']))
        <div class="mb-4 p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-900 text-xs font-bold flex items-center justify-between shadow-2xs animate-pulse">
            <div class="flex items-center gap-2.5">
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-600 animate-ping"></span>
                <span>🖼️ <strong>Attēlu sinhronizācija pašlaik strādā fonā</strong> (sākta {{ $processStatuses['sync_images']['started_for_humans'] }}). Attēli tiek lejupielādēti un pārvietoti uz Hetzner S3 krātuvi.</span>
            </div>
            <span class="text-[11px] font-mono text-emerald-700">Lejupielādē...</span>
        </div>
    @endif

    <!-- System Health & Synchronization Tools -->
    <div class="mb-8 p-6 bg-white rounded-3xl border border-slate-200/90 shadow-2xs space-y-6">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-base font-extrabold text-slate-900 flex items-center gap-2">
                    <i data-lucide="wrench" class="w-4.5 h-4.5 text-blue-600"></i>
                    Sistēmas stāvoklis, procesi &amp; sinhronizācija
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    Pēcapstrādes procesi (attēli, tulkojumi, sitemap) izpildās secīgi bez savstarpējas pārklāšanās.
                </p>
            </div>

            <!-- Manual Action Buttons (HTMX Powered) -->
            <div class="flex flex-wrap items-center gap-2">
                <!-- Sync Translations Button -->
                @php $isTransRunning = !empty($processStatuses['sync_translations']['is_running']); @endphp
                <form 
                    hx-post="{{ route('admin.tools.sync-translations') }}" 
                    hx-target="#system-status-container" 
                    hx-swap="outerHTML" 
                    hx-disabled-elt="find button"
                    class="inline-block">
                    @csrf
                    <button type="submit" 
                            {{ $isTransRunning ? 'disabled' : '' }}
                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl {{ $isTransRunning ? 'bg-blue-50 text-blue-600 cursor-not-allowed border border-blue-200' : 'bg-slate-100 hover:bg-blue-600 hover:text-white text-slate-800 border border-slate-200' }} font-bold text-xs transition-colors shadow-2xs cursor-pointer disabled:opacity-50" 
                            title="{{ $isTransRunning ? 'Process pašlaik darbojas' : 'Palaist tulkojumu sinhronizāciju uz EN un RU' }}">
                        <i data-lucide="languages" class="w-3.5 h-3.5 {{ $isTransRunning ? 'animate-spin' : '' }}"></i>
                        <span>{{ $isTransRunning ? 'Tulkošana procesā...' : 'Sinhronizēt tulkojumus' }}</span>
                    </button>
                </form>

                <!-- Sync Images Button -->
                @php $isImgRunning = !empty($processStatuses['sync_images']['is_running']); @endphp
                <form 
                    hx-post="{{ route('admin.tools.sync-images') }}" 
                    hx-target="#system-status-container" 
                    hx-swap="outerHTML" 
                    hx-disabled-elt="find button"
                    class="inline-block">
                    @csrf
                    <button type="submit" 
                            {{ $isImgRunning ? 'disabled' : '' }}
                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl {{ $isImgRunning ? 'bg-emerald-50 text-emerald-600 cursor-not-allowed border border-emerald-200' : 'bg-slate-100 hover:bg-emerald-600 hover:text-white text-slate-800 border border-slate-200' }} font-bold text-xs transition-colors shadow-2xs cursor-pointer disabled:opacity-50" 
                            title="{{ $isImgRunning ? 'Process pašlaik darbojas' : 'Lejupielādēt attēlus un saglabāt Hetzner S3 krātuvē' }}">
                        <i data-lucide="image" class="w-3.5 h-3.5 {{ $isImgRunning ? 'animate-spin' : '' }}"></i>
                        <span>
                            {{ $isImgRunning ? 'Attēli procesā...' : 'Sinhronizēt attēlus' }}
                            @if(!empty($pendingImagesCount) && $pendingImagesCount > 0)
                                <span class="font-mono text-[11px] opacity-80">({{ $pendingImagesCount }})</span>
                            @endif
                        </span>
                    </button>
                </form>

                <!-- Refresh Sitemap Button -->
                <form 
                    hx-post="{{ route('admin.tools.refresh-sitemap') }}" 
                    hx-target="#system-status-container" 
                    hx-swap="outerHTML" 
                    hx-disabled-elt="find button"
                    class="inline-block">
                    @csrf
                    <button type="submit" 
                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-100 hover:bg-purple-600 hover:text-white text-slate-800 font-bold text-xs border border-slate-200 transition-colors shadow-2xs cursor-pointer disabled:opacity-50" 
                            title="Pārlasīt visus pasākumus un nekavējoties atjaunot sitemap.xml">
                        <i data-lucide="globe" class="w-3.5 h-3.5"></i>
                        <span>Atjaunot sitemap</span>
                    </button>
                </form>
            </div>
        </div>

        <!-- Monitoring Widgets Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- Server Disk Space Widget -->
            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">
                        <span class="flex items-center gap-1.5 text-slate-700">
                            <i data-lucide="hard-drive" class="w-3.5 h-3.5 text-blue-600"></i>
                            Servera disks
                        </span>
                        <span class="font-mono text-[11px] {{ $diskSpace['percentage'] > 85 ? 'text-rose-600 font-black' : 'text-slate-600' }}">
                            {{ $diskSpace['percentage'] }}% pilns
                        </span>
                    </div>
                    <div class="text-xl font-black text-slate-900 mt-1">
                        {{ $diskSpace['free_human'] }} <span class="text-xs font-bold text-slate-500">brīvi</span>
                    </div>
                    <div class="text-[11px] text-slate-500 mt-0.5">
                        Kopējā ietilpība: <strong class="text-slate-700">{{ $diskSpace['total_human'] }}</strong>
                    </div>
                </div>

                <!-- Progress Bar -->
                <div class="mt-3">
                    <div class="w-full bg-slate-200 rounded-full h-2 overflow-hidden">
                        <div class="h-2 rounded-full transition-all duration-500 {{ $diskSpace['percentage'] > 88 ? 'bg-rose-500' : ($diskSpace['percentage'] > 70 ? 'bg-amber-500' : 'bg-emerald-500') }}" 
                             style="width: {{ min(100, max(2, $diskSpace['percentage'])) }}%"></div>
                    </div>
                </div>
            </div>

            <!-- Translation Script Status -->
            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">
                        <span class="flex items-center gap-1.5 text-slate-700">
                            <i data-lucide="languages" class="w-3.5 h-3.5 text-blue-600"></i>
                            Tulkojumu skripts
                        </span>
                        @if(!empty($processStatuses['sync_translations']['is_running']))
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-100 text-blue-800 animate-pulse">
                                Strādā
                            </span>
                        @else
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-200/80 text-slate-700">
                                Gaidīšanā
                            </span>
                        @endif
                    </div>
                    <div class="text-sm font-extrabold text-slate-900 mt-1">
                        @if(!empty($processStatuses['sync_translations']['is_running']))
                            <span class="text-blue-700 flex items-center gap-1.5 animate-pulse">
                                <span class="w-2 h-2 rounded-full bg-blue-600 animate-ping"></span>
                                Izpilda tulkošanu...
                            </span>
                        @else
                            <span class="text-slate-800">LV &rarr; EN, RU</span>
                        @endif
                    </div>
                    <div class="text-[11px] text-slate-500 mt-0.5 truncate" title="{{ $processStatuses['sync_translations']['last_message'] ?? '' }}">
                        {{ $processStatuses['sync_translations']['last_message'] ?? 'Nav palaists' }}
                    </div>
                </div>
                <div class="text-[10px] text-slate-400 mt-3 border-t border-slate-200/60 pt-1.5">
                    Pēdējoreiz: <strong>{{ $processStatuses['sync_translations']['finished_for_humans'] ?? 'nav datu' }}</strong>
                </div>
            </div>

            <!-- Image S3 Sync Status -->
            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">
                        <span class="flex items-center gap-1.5 text-slate-700">
                            <i data-lucide="image" class="w-3.5 h-3.5 text-emerald-600"></i>
                            Attēlu S3 krātuve
                        </span>
                        @if(!empty($processStatuses['sync_images']['is_running']))
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 animate-pulse">
                                Strādā
                            </span>
                        @else
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-200/80 text-slate-700">
                                Gaidīšanā
                            </span>
                        @endif
                    </div>
                    <div class="text-sm font-extrabold text-slate-900 mt-1">
                        @if(!empty($processStatuses['sync_images']['is_running']))
                            <span class="text-emerald-700 flex items-center gap-1.5 animate-pulse">
                                <span class="w-2 h-2 rounded-full bg-emerald-600 animate-ping"></span>
                                Kopē uz Hetzner S3...
                            </span>
                        @else
                            <span class="text-slate-800">Hetzner Object Storage</span>
                        @endif
                    </div>
                    <div class="text-[11px] text-slate-500 mt-0.5 truncate" title="{{ $processStatuses['sync_images']['last_message'] ?? '' }}">
                        {{ $processStatuses['sync_images']['last_message'] ?? 'Nav palaists' }}
                    </div>
                </div>
                <div class="text-[10px] text-slate-400 mt-3 border-t border-slate-200/60 pt-1.5">
                    Pēdējoreiz: <strong>{{ $processStatuses['sync_images']['finished_for_humans'] ?? 'nav datu' }}</strong>
                </div>
            </div>

            <!-- Full Scrape & Post-Process Chain Status -->
            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">
                        <span class="flex items-center gap-1.5 text-slate-700">
                            <i data-lucide="bot" class="w-3.5 h-3.5 text-purple-600"></i>
                            Pilnā ķēde (All)
                        </span>
                        @if(!empty($processStatuses['scrape_all']['is_running']))
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-100 text-purple-800 animate-pulse">
                                Aktīva
                            </span>
                        @else
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-200/80 text-slate-700">
                                04:00 Cron
                            </span>
                        @endif
                    </div>
                    <div class="text-sm font-extrabold text-slate-900 mt-1">
                        @if(!empty($processStatuses['scrape_all']['is_running']))
                            <span class="text-purple-700 flex items-center gap-1.5 animate-pulse">
                                <span class="w-2 h-2 rounded-full bg-purple-600 animate-ping"></span>
                                Izpilda pilno ķēdi...
                            </span>
                        @else
                            <span class="text-slate-800">Secīga pēcapstrāde</span>
                        @endif
                    </div>
                    <div class="text-[11px] text-slate-500 mt-0.5 truncate" title="{{ $processStatuses['scrape_all']['last_message'] ?? '' }}">
                        {{ $processStatuses['scrape_all']['last_message'] ?? 'Nav palaista' }}
                    </div>
                </div>
                <div class="text-[10px] text-slate-400 mt-3 border-t border-slate-200/60 pt-1.5">
                    Pēdējoreiz: <strong>{{ $processStatuses['scrape_all']['finished_for_humans'] ?? 'nav datu' }}</strong>
                </div>
            </div>
        </div>
    </div>
</div>

