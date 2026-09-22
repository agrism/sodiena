@if($events->isEmpty())
    <div class="col-span-full py-16 text-center">
        <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-slate-100 flex items-center justify-center text-slate-400 border border-slate-200">
            <i data-lucide="calendar-x-2" class="w-8 h-8"></i>
        </div>
        <h3 class="text-lg font-bold text-slate-800">{{ __('No events found') }}</h3>
        <p class="text-sm text-slate-500 mt-1 max-w-sm mx-auto">Mēģiniet mainīt meklēšanas vārdus vai filtrus.</p>
        <button 
            type="button" 
            onclick="window.location.href='{{ route('events.index') }}'"
            class="mt-4 px-4 py-2 rounded-xl bg-emerald-50 text-emerald-700 text-xs font-bold border border-emerald-200 hover:bg-emerald-100 transition-colors">
            {{ __('Reset filters') }}
        </button>
    </div>
@else
    <div class="contents">
        @foreach($events as $event)
            <article class="group bg-white rounded-3xl border border-slate-200/90 hover:border-slate-300 overflow-hidden shadow-xs hover:shadow-xl hover:-translate-y-1 transition-all duration-300 flex flex-col justify-between">
                
                <!-- Event Image & Badges -->
                <div class="relative aspect-[16/10] bg-slate-100 overflow-hidden group/img">
                    <!-- Clickable Link to Event Details -->
                    <a href="{{ route('events.show', $event->slug) }}" class="block w-full h-full focus:outline-none" aria-label="{{ $event->title }}">
                        <img 
                            src="{{ $event->display_image_url }}" 
                            alt="{{ $event->title }}"
                            width="640"
                            height="400"
                            @if($loop->iteration <= 2)
                                loading="eager"
                                fetchpriority="high"
                            @else
                                loading="lazy"
                                decoding="async"
                            @endif
                            class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 ease-out">

                        <!-- Subtle overlay gradient -->
                        <div class="absolute inset-0 bg-gradient-to-t from-slate-950/80 via-slate-950/20 to-transparent pointer-events-none"></div>
                    </a>

                    <!-- Top Badges Row (Solid Z-Index, Direct Interaction) -->
                    <div class="absolute top-3 left-3 right-3 flex items-center justify-between gap-2 z-30">
                        <!-- Date Badge -->
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-white/95 text-slate-900 backdrop-blur-md border border-slate-200/80 shadow-md select-none">
                            <i data-lucide="calendar" class="w-3.5 h-3.5 text-emerald-600"></i>
                            {{ $event->formatted_date }}
                        </span>

                        <div class="flex items-center gap-2">
                            <!-- Admin Afiro Indicator -->
                            @if(auth()->check() && auth()->user()->isAdmin())
                                @if($event->isAfiro() && $event->afiro_url)
                                    <a 
                                        href="{{ $event->afiro_url }}" 
                                        target="_blank" 
                                        rel="noopener noreferrer" 
                                        onclick="event.stopPropagation(); event.stopImmediatePropagation();"
                                        title="Atvērt Afiro notikumu: {{ $event->afiro_url }}"
                                        class="w-7 h-7 rounded-full bg-white text-red-600 border-2 border-red-500 flex items-center justify-center font-black text-xs shadow-md hover:bg-red-600 hover:text-white hover:border-red-600 transition-all transform hover:scale-110 shrink-0 cursor-pointer"
                                    >
                                        A
                                    </a>
                                @else
                                    <span 
                                        title="Nav Afiro notikums (Avots: {{ $event->source?->name ?: $event->source_slug ?: 'Cits' }})"
                                        class="w-7 h-7 rounded-full bg-white/85 text-slate-400 border border-slate-300 flex items-center justify-center font-bold text-xs shadow-xs shrink-0 cursor-default select-none"
                                    >
                                        A
                                    </span>
                                @endif
                            @endif

                            <!-- Price Badge -->
                            @if($event->is_free)
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-extrabold bg-emerald-600 text-white shadow-md select-none">
                                    {{ __('Free') }}
                                </span>
                            @else
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-white/95 text-emerald-700 backdrop-blur-md border border-emerald-200 shadow-md select-none">
                                    {{ $event->formatted_price }}
                                </span>
                            @endif
                        </div>
                    </div>

                    <!-- City & Venue pill bottom left of image -->
                    <div class="absolute bottom-3 left-3 right-3 flex items-center gap-2 z-20 pointer-events-none">
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold bg-slate-900/85 text-white backdrop-blur-md border border-white/10 truncate max-w-full">
                            <i data-lucide="map-pin" class="w-3 h-3 text-emerald-400 shrink-0"></i>
                            <span class="truncate">{{ $event->location?->name ?: $event->location?->city ?: 'Latvija' }}</span>
                        </span>
                    </div>
                </div>

                <!-- Content Area -->
                <div class="p-5 flex flex-col flex-grow justify-between gap-4">
                    <div>
                        <!-- Category Tags -->
                        <div class="flex flex-wrap items-center gap-1.5 mb-2.5">
                            @foreach($event->categories->take(2) as $category)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold bg-slate-100 text-slate-700 border border-slate-200">
                                    <i data-lucide="{{ $category->icon ?: 'tag' }}" class="w-3 h-3 text-emerald-600"></i>
                                    {{ $category->name }}
                                </span>
                            @endforeach
                            @if($event->localized_entertainment_type)
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    {{ $event->localized_entertainment_type }}
                                </span>
                            @endif
                        </div>

                        <!-- Title -->
                        <h3 class="text-base font-bold text-slate-900 group-hover:text-emerald-700 transition-colors line-clamp-2 leading-snug">
                            <a href="{{ route('events.show', $event->slug) }}" class="focus:outline-none">
                                {{ $event->title }}
                            </a>
                        </h3>

                        <!-- Short Description -->
                        @if($event->short_description)
                            <p class="text-xs text-slate-500 mt-2 line-clamp-2 leading-relaxed font-normal">
                                {{ $event->short_description }}
                            </p>
                        @endif
                    </div>

                    <!-- Card Footer: Time & Action CTAs -->
                    <div class="pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
                        <span class="flex items-center gap-1 text-[11px] text-slate-400">
                            <i data-lucide="clock" class="w-3 h-3 text-emerald-600 shrink-0"></i>
                            <span>{{ $event->start_at ? $event->start_at->format('H:i') : '' }}</span>
                        </span>

                        <div class="flex items-center gap-2 shrink-0">
                            @if($event->ticket_url)
                                <a 
                                    href="{{ $event->ticket_url }}" 
                                    target="_blank" 
                                    rel="noopener noreferrer"
                                    title="Doties uz biļešu iegādi pie organizatora"
                                    class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-emerald-50 text-emerald-700 hover:bg-emerald-600 hover:text-white font-bold text-[11px] border border-emerald-200 transition-colors">
                                    <span>{{ __('Tickets') }}</span>
                                    <i data-lucide="external-link" class="w-3 h-3"></i>
                                </a>
                            @endif

                            <a 
                                href="{{ route('events.show', $event->slug) }}" 
                                class="inline-flex items-center gap-1 text-slate-600 hover:text-emerald-700 font-bold transition-colors">
                                {{ __('Details') }}
                                <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </article>
        @endforeach
    </div>

    <!-- Infinite Scroll Sentinel -->
    @if($events->hasMorePages())
        <div 
            hx-get="{{ $events->nextPageUrl() }}" 
            hx-trigger="revealed" 
            hx-swap="outerHTML"
            class="col-span-full py-12 flex flex-col items-center justify-center">
            <div class="inline-flex items-center gap-2.5 px-5 py-2.5 rounded-full bg-white text-emerald-700 font-bold text-xs shadow-md border border-slate-200 animate-pulse">
                <svg class="animate-spin -ml-1 mr-1 h-4 w-4 text-emerald-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>{{ __('Loading more events...') }}</span>
            </div>
        </div>
    @else
        <div class="col-span-full py-12 text-center text-xs font-semibold text-slate-400">
            <span>✨ {{ __('All events shown') }}</span>
        </div>
    @endif
@endif

@if(isset($categories) && isset($totalUpcoming) && $events->currentPage() === 1)
    <div id="category-carousel-wrapper" hx-swap-oob="true" class="mb-8 w-full max-w-full overflow-hidden">
        @include('events.partials.category-pills', [
            'categories' => $categories,
            'totalUpcoming' => $totalUpcoming,
            'categorySlug' => $categorySlug ?? request('category', 'all')
        ])
    </div>
@endif

