@if($events->isEmpty())
    <div class="bg-white rounded-2xl border border-slate-200 p-12 text-center shadow-xs">
        <div class="w-16 h-16 rounded-full bg-amber-50 border border-amber-200 flex items-center justify-center mx-auto text-amber-600 mb-4">
            <i data-lucide="check-circle-2" class="w-8 h-8"></i>
        </div>
        <h3 class="text-base font-extrabold text-slate-900 mb-1">Nav atrasts neviens nepublicēts pasākums</h3>
        <p class="text-xs text-slate-500 max-w-md mx-auto mb-5">
            Pēc izvēlētajiem filtriem visi pasākumi ir nopublicēti vai nav pieejami. Mēģiniet mainīt meklēšanas kritērijus vai avotu.
        </p>
        <button type="button" 
                onclick="document.getElementById('unpublishedFilterForm').reset(); htmx.trigger('#unpublishedFilterForm', 'submit');"
                class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition-all inline-flex items-center gap-2 cursor-pointer">
            <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i>
            <span>Notīrīt filtrus</span>
        </button>
    </div>
@else
    <!-- Result Counter -->
    <div class="flex items-center justify-between text-xs text-slate-500 px-1 font-mono">
        <div>
            Rāda <span class="font-bold text-slate-800">{{ $events->firstItem() }}-{{ $events->lastItem() }}</span> no <span class="font-black text-amber-900">{{ $events->total() }}</span> nepublicētajiem pasākumiem
        </div>
        <div>
            Lapa <span class="font-bold text-slate-800">{{ $events->currentPage() }}</span> no <span class="font-bold text-slate-800">{{ $events->lastPage() }}</span>
        </div>
    </div>

    <!-- Event Cards Grid -->
    <div class="space-y-6">
        @foreach($events as $event)
            @php
                $translationsByLocale = $event->translations->keyBy('locale');
                $lvTrans = $translationsByLocale->get('lv');
                $enTrans = $translationsByLocale->get('en');
                $ruTrans = $translationsByLocale->get('ru');

                $descLv = $lvTrans?->description ?? $event->getRawOriginal('description') ?? '';
                $descEn = $enTrans?->description ?? '';
                $descRu = $ruTrans?->description ?? '';

                $descLvTrimmed = mb_substr(strip_tags($descLv), 0, 20) . (mb_strlen(strip_tags($descLv)) > 20 ? '...' : '');
                $descEnTrimmed = !empty($descEn) ? (mb_substr(strip_tags($descEn), 0, 20) . (mb_strlen(strip_tags($descEn)) > 20 ? '...' : '')) : null;
                $descRuTrimmed = !empty($descRu) ? (mb_substr(strip_tags($descRu), 0, 20) . (mb_strlen(strip_tags($descRu)) > 20 ? '...' : '')) : null;

                $ticketLink = $event->ticket_url ?: $event->source_url ?: $event->origin_url ?: ($event->ticket_links[0]['url'] ?? null);
                $ticketHost = $ticketLink ? parse_url($ticketLink, PHP_URL_HOST) : null;
            @endphp

            <div id="unpublished-event-card-{{ $event->id }}" 
                 class="unpublished-event-card bg-white rounded-2xl border border-slate-200 shadow-xs hover:shadow-md transition-all overflow-hidden">
                
                <!-- Card Header -->
                <div class="p-4 sm:p-5 border-b border-slate-100 bg-slate-50/40">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
                        
                        <!-- Left: Status & Tags -->
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="publish-status-pill inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-extrabold bg-amber-100 text-amber-900 border border-amber-200">
                                <i data-lucide="eye-off" class="w-3.5 h-3.5 text-amber-700"></i>
                                Melnraksts (Nepublicēts)
                            </span>

                            @if($event->source)
                                <span class="px-2.5 py-1 rounded-lg bg-slate-100 text-slate-700 text-[11px] font-mono font-bold border border-slate-200" title="Avots">
                                    {{ $event->source->name }}
                                </span>
                            @elseif($event->source_slug)
                                <span class="px-2.5 py-1 rounded-lg bg-slate-100 text-slate-700 text-[11px] font-mono font-bold border border-slate-200">
                                    {{ $event->source_slug }}
                                </span>
                            @endif

                            @foreach($event->categories as $cat)
                                <span class="px-2 py-0.5 rounded-lg bg-blue-50 text-blue-800 text-[11px] font-bold border border-blue-200">
                                    {{ $cat->name }}
                                </span>
                            @endforeach

                            <span class="text-xs text-slate-500 font-mono ml-auto lg:ml-0 flex items-center gap-1">
                                <i data-lucide="calendar" class="w-3.5 h-3.5 text-slate-400"></i>
                                {{ $event->start_at ? $event->start_at->format('d.m.Y H:i') : 'Nav norādīts datums' }}
                            </span>

                            @if($event->location || $event->display_venue)
                                <span class="text-xs text-slate-600 font-medium flex items-center gap-1">
                                    <i data-lucide="map-pin" class="w-3.5 h-3.5 text-slate-400"></i>
                                    {{ $event->location?->name ?? $event->display_venue }}
                                    @if($event->location?->city)
                                        <span class="text-slate-400">({{ $event->location->city }})</span>
                                    @endif
                                </span>
                            @endif
                        </div>

                        <!-- Right: Action Buttons -->
                        <div class="flex items-center gap-2 shrink-0">
                            <!-- Direct link to our event page -->
                            <a href="{{ route('events.show', $event->slug) }}" 
                               target="_blank" 
                               rel="noopener noreferrer" 
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-300 rounded-xl text-xs font-bold transition-all"
                               title="Atvērt pasākuma lapu vietnē Šodiena.lv">
                                <i data-lucide="external-link" class="w-3.5 h-3.5 text-slate-600"></i>
                                <span>Mūsu lapa</span>
                            </a>

                            @if($ticketLink)
                                <a href="{{ $ticketLink }}" 
                                   target="_blank" 
                                   rel="noopener noreferrer" 
                                   class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 rounded-xl text-xs font-bold transition-all"
                                   title="Atvērt biļešu lapu jaunā logā">
                                    <i data-lucide="ticket" class="w-3.5 h-3.5"></i>
                                    <span>Biļešu lapa</span>
                                </a>
                            @endif

                            <button type="button"
                                    hx-post="{{ route('admin.events.toggle-publish', $event) }}"
                                    hx-swap="none"
                                    class="btn-quick-publish inline-flex items-center gap-1.5 px-4 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-extrabold transition-all shadow-xs cursor-pointer">
                                <i data-lucide="check" class="w-4 h-4 stroke-[2.5]"></i>
                                <span>Publicēt</span>
                            </button>
                        </div>
                    </div>

                    <!-- Event Title with Clickable Link -->
                    <div class="mt-3">
                        <h2 class="text-base sm:text-lg font-black text-slate-900 leading-snug">
                            <a href="{{ route('events.show', $event->slug) }}" 
                               target="_blank" 
                               rel="noopener noreferrer"
                               class="hover:text-blue-600 hover:underline transition-colors inline-flex items-center gap-2 group cursor-pointer"
                               title="Atvērt pasākuma lapu jaunā cilnē">
                                <span>{{ $event->getRawOriginal('title') ?: $event->title }}</span>
                                <i data-lucide="external-link" class="w-4 h-4 text-slate-400 group-hover:text-blue-600 transition-colors shrink-0"></i>
                            </a>
                        </h2>
                        <div class="flex flex-wrap items-center gap-2 text-[11px] text-slate-400 font-mono mt-1">
                            <span>ID: #{{ $event->id }}</span>
                            <span>•</span>
                            <a href="{{ route('events.show', $event->slug) }}" 
                               target="_blank" 
                               rel="noopener noreferrer"
                               class="text-blue-600 hover:text-blue-800 hover:underline flex items-center gap-1 bg-blue-50/80 border border-blue-200 px-2 py-0.5 rounded-md transition-colors font-bold"
                               title="Atvērt lapu /events/{{ $event->slug }}">
                                <span>Slug: {{ $event->slug }}</span>
                                <i data-lucide="external-link" class="w-3 h-3"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Price Comparison & Information Section -->
                <div class="p-4 sm:p-5 bg-white space-y-4">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-slate-50 p-4 rounded-xl border border-slate-200">
                        
                        <!-- Box 1: Our site price -->
                        <div>
                            <div class="flex items-center justify-between">
                                <span class="text-[11px] font-extrabold uppercase tracking-wider text-slate-500 font-mono flex items-center gap-1.5">
                                    <i data-lucide="tag" class="w-3.5 h-3.5 text-emerald-600"></i>
                                    Mūsu lapā uzrādītā cena
                                </span>
                                @if($event->is_free)
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-emerald-100 text-emerald-800 uppercase">Bezmaksas</span>
                                @endif
                            </div>
                            
                            <div class="mt-2 flex items-center gap-3">
                                <div class="px-3.5 py-1.5 rounded-xl bg-emerald-100/70 border border-emerald-300 text-emerald-950 font-mono text-lg font-black tracking-tight">
                                    {{ $event->formatted_price }}
                                </div>
                                <div class="text-[11px] font-mono text-slate-600 space-y-0.5">
                                    <div>Min cena: <strong class="text-slate-900">{{ $event->price_min !== null ? '€' . number_format($event->price_min, 2) : '—' }}</strong></div>
                                    <div>Max cena: <strong class="text-slate-900">{{ $event->price_max !== null ? '€' . number_format($event->price_max, 2) : '—' }}</strong></div>
                                </div>
                            </div>
                        </div>

                        <!-- Box 2: Ticket vendor & comparison link -->
                        <div>
                            <span class="text-[11px] font-extrabold uppercase tracking-wider text-slate-500 font-mono flex items-center gap-1.5">
                                <i data-lucide="ticket" class="w-3.5 h-3.5 text-blue-600"></i>
                                Biļešu tirdzniecības vieta & saite
                            </span>
                            
                            <div class="mt-2 flex flex-col gap-1.5">
                                @if($ticketLink)
                                    <div class="flex items-center gap-2">
                                        <a href="{{ $ticketLink }}" 
                                           target="_blank" 
                                           rel="noopener noreferrer" 
                                           class="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-[#002855] hover:bg-[#003875] text-white rounded-xl text-xs font-bold transition-all shadow-xs">
                                            <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
                                            <span>Atvērt cenu lapu ({{ $ticketHost ?: 'Biļešu saite' }})</span>
                                        </a>
                                        <span class="text-[11px] font-mono text-slate-400 truncate max-w-[200px]" title="{{ $ticketLink }}">
                                            {{ $ticketLink }}
                                        </span>
                                    </div>
                                    <div class="text-[10px] text-slate-500">
                                        Salīdziniet cenu zemāk ielādētajā iframe logā vai atveriet tiešo saiti.
                                    </div>
                                @else
                                    <div class="text-xs text-amber-700 italic flex items-center gap-1.5 bg-amber-50 p-2 rounded-lg border border-amber-200">
                                        <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i>
                                        <span>Nav pieejama biļešu vai avota tīmekļa saite.</span>
                                    </div>
                                @endif
                            </div>
                        </div>

                    </div>

                    <!-- Multilingual Descriptions Block (LV, EN, RU trimmed to 20 symbols) -->
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-[11px] font-extrabold uppercase tracking-wider text-slate-500 font-mono flex items-center gap-1.5">
                                <i data-lucide="languages" class="w-3.5 h-3.5 text-indigo-600"></i>
                                Apraksti visās valodās (notrimoti līdz 20 zīmēm)
                            </span>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            
                            <!-- LV (Latvian) Description -->
                            <div class="bg-slate-50/80 border border-slate-200 rounded-xl p-3">
                                <div class="flex items-center justify-between mb-1.5">
                                    <span class="text-xs font-bold text-slate-800 flex items-center gap-1.5">
                                        <span class="text-sm">🇱🇻</span>
                                        <span>Latviešu (LV)</span>
                                    </span>
                                    <span class="text-[10px] font-mono font-bold text-slate-400">
                                        {{ mb_strlen(strip_tags($descLv)) }} zīmes
                                    </span>
                                </div>
                                <div class="text-xs font-mono font-semibold text-slate-900 bg-white p-2 rounded-lg border border-slate-200 min-h-[36px] flex items-center">
                                    @if(!empty($descLvTrimmed))
                                        <span class="text-slate-800" title="{{ strip_tags($descLv) }}">{{ $descLvTrimmed }}</span>
                                    @else
                                        <span class="text-slate-400 italic text-[11px]">Nav LV apraksta</span>
                                    @endif
                                </div>
                                @if(mb_strlen(strip_tags($descLv)) > 20)
                                    <details class="mt-1.5 text-[11px] text-slate-600">
                                        <summary class="cursor-pointer font-bold text-[#002855] hover:underline">Rādīt pilno LV tekstu</summary>
                                        <div class="mt-1.5 p-2 bg-white rounded-lg border border-slate-200 text-xs leading-relaxed max-h-36 overflow-y-auto font-sans">
                                            {{ strip_tags($descLv) }}
                                        </div>
                                    </details>
                                @endif
                            </div>

                            <!-- EN (English) Description -->
                            <div class="bg-slate-50/80 border border-slate-200 rounded-xl p-3">
                                <div class="flex items-center justify-between mb-1.5">
                                    <span class="text-xs font-bold text-slate-800 flex items-center gap-1.5">
                                        <span class="text-sm">🇬🇧</span>
                                        <span>English (EN)</span>
                                    </span>
                                    <span class="text-[10px] font-mono font-bold text-slate-400">
                                        {{ mb_strlen(strip_tags($descEn)) }} zīmes
                                    </span>
                                </div>
                                <div class="text-xs font-mono font-semibold text-slate-900 bg-white p-2 rounded-lg border border-slate-200 min-h-[36px] flex items-center">
                                    @if(!empty($descEnTrimmed))
                                        <span class="text-slate-800" title="{{ strip_tags($descEn) }}">{{ $descEnTrimmed }}</span>
                                    @else
                                        <span class="text-slate-400 italic text-[11px]">Nav EN tulkojuma</span>
                                    @endif
                                </div>
                                @if(mb_strlen(strip_tags($descEn)) > 20)
                                    <details class="mt-1.5 text-[11px] text-slate-600">
                                        <summary class="cursor-pointer font-bold text-[#002855] hover:underline">Rādīt pilno EN tekstu</summary>
                                        <div class="mt-1.5 p-2 bg-white rounded-lg border border-slate-200 text-xs leading-relaxed max-h-36 overflow-y-auto font-sans">
                                            {{ strip_tags($descEn) }}
                                        </div>
                                    </details>
                                @endif
                            </div>

                            <!-- RU (Russian) Description -->
                            <div class="bg-slate-50/80 border border-slate-200 rounded-xl p-3">
                                <div class="flex items-center justify-between mb-1.5">
                                    <span class="text-xs font-bold text-slate-800 flex items-center gap-1.5">
                                        <span class="text-sm">🇷🇺</span>
                                        <span>Русский (RU)</span>
                                    </span>
                                    <span class="text-[10px] font-mono font-bold text-slate-400">
                                        {{ mb_strlen(strip_tags($descRu)) }} zīmes
                                    </span>
                                </div>
                                <div class="text-xs font-mono font-semibold text-slate-900 bg-white p-2 rounded-lg border border-slate-200 min-h-[36px] flex items-center">
                                    @if(!empty($descRuTrimmed))
                                        <span class="text-slate-800" title="{{ strip_tags($descRu) }}">{{ $descRuTrimmed }}</span>
                                    @else
                                        <span class="text-slate-400 italic text-[11px]">Nav RU tulkojuma</span>
                                    @endif
                                </div>
                                @if(mb_strlen(strip_tags($descRu)) > 20)
                                    <details class="mt-1.5 text-[11px] text-slate-600">
                                        <summary class="cursor-pointer font-bold text-[#002855] hover:underline">Rādīt pilno RU tekstu</summary>
                                        <div class="mt-1.5 p-2 bg-white rounded-lg border border-slate-200 text-xs leading-relaxed max-h-36 overflow-y-auto font-sans">
                                            {{ strip_tags($descRu) }}
                                        </div>
                                    </details>
                                @endif
                            </div>

                        </div>
                    </div>

                    <!-- Embedded Ticket Page Iframe -->
                    @if($ticketLink)
                        <div class="border border-slate-200 rounded-xl overflow-hidden shadow-xs">
                            
                            <!-- Iframe Toolbar -->
                            <div class="px-3.5 py-2.5 bg-slate-100 border-b border-slate-200 flex flex-wrap items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                                    <span class="text-xs font-black text-slate-800 font-mono flex items-center gap-1">
                                        <i data-lucide="globe" class="w-3.5 h-3.5 text-slate-500"></i>
                                        Iframe: {{ $ticketHost }}
                                    </span>
                                    <span class="text-[10px] text-slate-400 font-mono truncate max-w-[280px]">
                                        {{ $ticketLink }}
                                    </span>
                                </div>

                                <div class="flex items-center gap-1.5">
                                    <button type="button" 
                                            onclick="reloadIframe({{ $event->id }})"
                                            class="px-2.5 py-1 bg-white hover:bg-slate-50 border border-slate-200 text-slate-700 rounded-lg text-[11px] font-bold transition-all flex items-center gap-1 cursor-pointer">
                                        <i data-lucide="refresh-cw" class="w-3 h-3"></i>
                                        <span>Pārlādēt</span>
                                    </button>

                                    <button type="button" 
                                            id="btn-iframe-toggle-{{ $event->id }}"
                                            onclick="toggleIframe({{ $event->id }})"
                                            class="px-2.5 py-1 bg-white hover:bg-slate-50 border border-slate-200 text-slate-700 rounded-lg text-[11px] font-bold transition-all flex items-center gap-1 cursor-pointer">
                                        <i data-lucide="eye-off" class="w-3 h-3"></i>
                                        <span>Slēpt iframe</span>
                                    </button>

                                    <a href="{{ $ticketLink }}" 
                                       target="_blank" 
                                       rel="noopener noreferrer" 
                                       class="px-2.5 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-[11px] font-bold transition-all flex items-center gap-1">
                                        <i data-lucide="external-link" class="w-3 h-3"></i>
                                        <span>Atvērt ↗</span>
                                    </a>
                                </div>
                            </div>

                            <!-- Iframe Container -->
                            <div id="iframe-box-{{ $event->id }}" class="relative bg-slate-900">
                                <iframe id="iframe-el-{{ $event->id }}"
                                        src="{{ $ticketLink }}" 
                                        sandbox="allow-scripts allow-same-origin allow-popups allow-forms"
                                        loading="lazy"
                                        class="w-full h-[460px] bg-white border-0">
                                </iframe>
                                
                                <div class="px-3 py-1.5 bg-slate-50 border-t border-slate-200 text-[10px] text-slate-500 flex items-center justify-between font-mono">
                                    <span>Piezīme: Ja biļešu vietne liedz tiešu iegulšanu (CSP/X-Frame-Options), lūdzu izmantojiet pogu 'Atvērt ↗'.</span>
                                    <a href="{{ $ticketLink }}" target="_blank" class="text-blue-600 font-bold hover:underline">Atvērt cilnē</a>
                                </div>
                            </div>

                        </div>
                    @endif

                </div>

            </div>
        @endforeach
    </div>

    <!-- Pagination Links (HTMX Driven) -->
    @if($events->hasPages())
        <div class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-3 bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
            <div class="text-xs text-slate-500 font-mono">
                Rāda {{ $events->firstItem() }}-{{ $events->lastItem() }} no {{ $events->total() }} ierakstiem
            </div>
            
            <div class="flex items-center gap-1 font-mono text-xs" 
                 hx-target="#unpublishedListContainer" 
                 hx-indicator="#unpublishedLoadingIndicator">
                
                {{-- Previous Page Link --}}
                @if ($events->onFirstPage())
                    <span class="px-3 py-1.5 rounded-xl bg-slate-100 text-slate-400 cursor-not-allowed">« Iepriekšējā</span>
                @else
                    <a href="{{ $events->previousPageUrl() }}" 
                       hx-get="{{ $events->previousPageUrl() }}"
                       class="px-3 py-1.5 rounded-xl bg-slate-50 hover:bg-slate-100 text-slate-700 border border-slate-200 font-bold transition-all">« Iepriekšējā</a>
                @endif

                {{-- Page Numbers --}}
                @foreach ($events->getUrlRange(max(1, $events->currentPage() - 2), min($events->lastPage(), $events->currentPage() + 2)) as $page => $url)
                    @if ($page == $events->currentPage())
                        <span class="px-3 py-1.5 rounded-xl bg-[#002855] text-white font-black">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" 
                           hx-get="{{ $url }}"
                           class="px-3 py-1.5 rounded-xl bg-slate-50 hover:bg-slate-100 text-slate-700 border border-slate-200 font-semibold transition-all">{{ $page }}</a>
                    @endif
                @endforeach

                {{-- Next Page Link --}}
                @if ($events->hasMorePages())
                    <a href="{{ $events->nextPageUrl() }}" 
                       hx-get="{{ $events->nextPageUrl() }}"
                       class="px-3 py-1.5 rounded-xl bg-slate-50 hover:bg-slate-100 text-slate-700 border border-slate-200 font-bold transition-all">Nākamā »</a>
                @else
                    <span class="px-3 py-1.5 rounded-xl bg-slate-100 text-slate-400 cursor-not-allowed">Nākamā »</span>
                @endif
            </div>
        </div>
    @endif
@endif
