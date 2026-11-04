@if($events->isEmpty())
    <div class="col-span-full py-16 text-center">
        <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-slate-100 flex items-center justify-center text-slate-400 border border-slate-200">
            <i data-lucide="calendar-x-2" class="w-8 h-8"></i>
        </div>
        <h2 class="text-lg font-bold text-slate-800">{{ __('No events found') }}</h2>
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
            <article class="event-card group relative bg-white rounded-lg border {{ $event->is_promoted ? 'border-amber-300 ring-2 ring-amber-400/50 shadow-md shadow-amber-500/10' : 'border-slate-200/85 hover:border-slate-300 shadow-xs hover:shadow-md' }} overflow-hidden transition-all duration-300 flex flex-col h-fit">
                <!-- Image Area: (aspect-[1/1] square) filling 100% of container -->
                <div class="relative aspect-[1/1] w-full overflow-hidden bg-slate-100">
                    <!-- Clickable Image Link -->
                    <a href="{{ $event->localized_url }}" class="relative z-1 block w-full h-full focus:outline-none" aria-label="{{ $event->title }}">
                        <img 
                            src="{{ $event->display_image_url }}" 
                            alt="{{ $event->title }}"
                            width="480"
                            height="480"
                            @if($loop->iteration <= 4)
                                loading="eager"
                                fetchpriority="high"
                            @else
                                loading="lazy"
                            @endif
                            decoding="async"
                            class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300 ease-out">

                        <!-- Subtle hover dark gradient overlay -->
                        <div class="absolute inset-0 bg-gradient-to-t from-slate-950/60 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300 pointer-events-none"></div>
                    </a>

                    <!-- Top Badges Row -->
                    <div class="absolute top-2 left-2 right-2 flex items-center justify-between gap-1.5 pointer-events-none z-10">
                        <div class="flex items-center gap-1 flex-wrap">
                            @if($event->is_promoted)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-black bg-gradient-to-r from-amber-500 to-orange-500 text-white shadow-xs border border-amber-300/40 select-none tracking-wide">
                                    <i data-lucide="sparkles" class="w-3 h-3 fill-amber-100 text-amber-100"></i>
                                    <span>{{ __('Izcelts') }}</span>
                                </span>
                            @endif
                        </div>

                        <div class="flex items-center gap-1 pointer-events-auto">
                            <!-- Admin Afiro Indicator -->
                            @if(auth()->check() && auth()->user()->isAdmin())
                                @if($event->isAfiro() && $event->afiro_url)
                                    <a 
                                        href="{{ $event->afiro_url }}" 
                                        target="_blank" 
                                        rel="noopener noreferrer" 
                                        title="Atvērt Afiro notikumu: {{ $event->afiro_url }}"
                                        class="w-6 h-6 rounded-full bg-white text-red-600 border border-red-500 flex items-center justify-center font-black text-[10px] shadow-sm hover:bg-red-600 hover:text-white transition-all transform hover:scale-110 shrink-0 cursor-pointer"
                                    >
                                        A
                                    </a>
                                @else
                                    <span 
                                        title="Nav Afiro notikums (Avots: {{ $event->source?->name ?: $event->source_slug ?: 'Cits' }})"
                                        class="w-6 h-6 rounded-full bg-white/90 text-slate-400 border border-slate-300 flex items-center justify-center font-bold text-[10px] shadow-xs shrink-0 cursor-default select-none"
                                    >
                                        A
                                    </span>
                                @endif
                            @endif
                        </div>
                    </div>

                    <!-- Poster Bottom Pills (Free badge) -->
                    @if($event->is_free)
                        <div class="absolute bottom-2 left-2 z-10 pointer-events-none">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-emerald-600 text-white shadow-xs select-none uppercase tracking-wider">
                                {{ __('Free') }}
                            </span>
                        </div>
                    @endif
                </div>

                <!-- Details Area: Proportional compact typography -->
                <div class="p-3 flex flex-col gap-1.5">
                    <!-- Title: Proportional font, consistent 2-line height -->
                    <h3 class="line-clamp-2 text-sm sm:text-[15px] font-bold leading-snug min-h-[2.4rem] sm:min-h-[2.6rem] text-slate-900 group-hover:text-emerald-700 transition-colors" title="{{ $event->title }}">
                        <a href="{{ $event->localized_url }}" class="focus:outline-none hover:text-emerald-700 after:absolute after:inset-0 after:z-0">
                            {{ $event->title }}
                        </a>
                    </h3>

                    <div class="flex flex-col gap-1">
                        <!-- Date & Time (Left) + Price (Right) in single readable row -->
                        <div class="flex items-center justify-between text-xs gap-1">
                            <span class="font-semibold text-emerald-700 truncate">
                                {{ $event->card_formatted_date }}
                                @if(!$event->all_day && $event->start_at)
                                    <span class="text-slate-400 font-normal">·</span>
                                    <span class="text-slate-600 font-medium">{{ $event->start_at->format('H:i') }}</span>
                                @endif
                            </span>
                            @if(!$event->is_free && $event->formatted_price)
                                <span class="font-bold text-slate-800 tabular-nums shrink-0">
                                    {{ $event->formatted_price }}
                                </span>
                            @endif
                        </div>

                        <!-- Location (Left) + Category Badge (Right) in single readable row -->
                        @php
                            $city = $event->location?->city;
                            $venue = $event->location?->name;
                            $category = $event->categories->first();
                        @endphp
                        <div class="flex items-center justify-between text-xs text-slate-500 gap-1 mt-0.5">
                            <span class="truncate flex items-center gap-1" title="{{ $city ? ($venue && $venue !== $city ? $city . ' · ' . $venue : $city) : $venue }}">
                                <i data-lucide="map-pin" class="w-3 h-3 text-slate-400 shrink-0"></i>
                                <span class="truncate">
                                    @if($city && $venue && $venue !== $city)
                                        {{ $city }} <span class="opacity-60">·</span> {{ $venue }}
                                    @else
                                        {{ $city ?: $venue ?: 'Latvija' }}
                                    @endif
                                </span>
                            </span>

                            @if($category)
                                <span class="h-4.5 inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-100 text-slate-600 border border-slate-200/60 shrink-0 truncate max-w-[85px] sm:max-w-[95px]" title="{{ $category->name }}">
                                    {{ $category->name }}
                                </span>
                            @endif
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

