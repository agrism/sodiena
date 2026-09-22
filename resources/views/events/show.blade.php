@extends('layouts.app')

@section('title', $event->title . ' — Šodiena')
@section('meta_description', Str::limit($event->seo_description, 160))
@section('og_type', 'article')
@section('meta_image', $event->display_image_url)
@section('canonical_url', route('events.show', $event->slug))

@push('styles')
    <link rel="preload" as="image" href="{{ $event->display_image_url }}" fetchpriority="high">
@endpush

@section('content')
<div class="max-w-7xl xl:max-w-[1400px] 2xl:max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12">
    
    <!-- Breadcrumb & Back Link -->
    <div class="mb-4 sm:mb-6 flex flex-wrap items-center justify-between gap-2.5">
        <a href="{{ route('events.index') }}" class="inline-flex items-center gap-1.5 text-xs sm:text-sm font-bold text-slate-500 hover:text-emerald-700 transition-colors shrink-0">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>
            <span>{{ __('Back to all events') }}</span>
        </a>

        <!-- Categories -->
        <div class="flex flex-wrap items-center gap-1.5 sm:gap-2">
            @foreach($event->categories as $category)
                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 sm:px-3 sm:py-1 rounded-full text-[11px] sm:text-xs font-bold bg-slate-100 text-slate-800 border border-slate-200 shadow-xs">
                    <i data-lucide="{{ $category->icon ?: 'tag' }}" class="w-3.5 h-3.5 text-emerald-600"></i>
                    {{ $category->name }}
                </span>
            @endforeach
        </div>
    </div>

    <!-- Main Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 lg:gap-12 items-start">
        
        <!-- Left Column: Hero Image & Description -->
        <div class="lg:col-span-8 space-y-8 min-w-0">
            
            <!-- Hero Image Banner -->
            <div class="relative rounded-3xl overflow-hidden bg-slate-900 border border-slate-200/90 shadow-md aspect-[16/9] sm:aspect-[2/1] md:aspect-[21/9] max-h-[480px]">
                <img 
                    src="{{ $event->display_image_url }}" 
                    alt="{{ $event->title }}"
                    width="1200"
                    height="600"
                    loading="eager"
                    fetchpriority="high"
                    class="w-full h-full object-cover">

                <div class="absolute inset-0 bg-gradient-to-t from-slate-950/80 via-slate-950/20 to-transparent"></div>

                <!-- Admin Source Indicator -->
                @if(auth()->check() && auth()->user()->isAdmin())
                    <div class="absolute top-3 right-3 sm:top-4 sm:right-4 z-10">
                        @if($event->isAfiro() && $event->afiro_url)
                            <a 
                                href="{{ $event->afiro_url }}" 
                                target="_blank" 
                                rel="noopener noreferrer" 
                                title="Atvērt Afiro notikumu: {{ $event->afiro_url }}"
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 sm:px-3 sm:py-1.5 rounded-full bg-white/95 text-red-600 border-2 border-red-500 font-black text-xs shadow-lg hover:bg-red-600 hover:text-white transition-all transform hover:scale-105"
                            >
                                <span class="w-4 h-4 rounded-full border-2 border-current flex items-center justify-center text-[10px] font-black">A</span>
                                <span>Afiro</span>
                                <i data-lucide="external-link" class="w-3 h-3"></i>
                            </a>
                        @else
                            <span 
                                title="Nav Afiro notikums (Avots: {{ $event->source?->name ?: $event->source_slug ?: 'Cits' }})"
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 sm:px-3 sm:py-1.5 rounded-full bg-white/90 text-slate-500 border border-slate-300 font-bold text-xs shadow-md"
                            >
                                <span class="w-4 h-4 rounded-full border border-current flex items-center justify-center text-[10px] font-bold">A</span>
                                <span>{{ $event->source?->name ?: 'Nav Afiro' }}</span>
                            </span>
                        @endif
                    </div>
                @endif

                <!-- Floating info on image -->
                <div class="absolute bottom-3 left-3 right-3 sm:bottom-6 sm:left-6 sm:right-6 flex flex-wrap items-center justify-between gap-2 text-white">
                    <span class="px-2.5 py-1 sm:px-3.5 sm:py-1.5 rounded-xl bg-white/95 text-slate-950 text-[11px] sm:text-xs font-extrabold backdrop-blur-md shadow-lg flex items-center gap-1.5 sm:gap-2">
                        <i data-lucide="calendar" class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-emerald-600 shrink-0"></i>
                        <span>{{ $event->formatted_date }}</span>
                    </span>

                    @if($event->location?->city)
                        <span class="px-2.5 py-1 sm:px-3.5 sm:py-1.5 rounded-xl bg-slate-900/80 text-white text-[11px] sm:text-xs font-bold backdrop-blur-md border border-white/20 flex items-center gap-1.5">
                            <i data-lucide="map-pin" class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-emerald-400 shrink-0"></i>
                            <span class="truncate max-w-[180px] sm:max-w-none">{{ $event->display_venue }}</span>
                        </span>
                    @endif
                </div>
            </div>

            <!-- Title & Description Card -->
            <div class="bg-white rounded-3xl p-5 sm:p-10 border border-slate-200/80 shadow-xs space-y-6 overflow-hidden">
                <div>
                    <h1 class="text-2xl sm:text-4xl font-extrabold text-slate-900 tracking-tight leading-tight break-words">
                        {{ $event->title }}
                    </h1>
                    @if($event->localized_entertainment_type)
                        <div class="mt-3 inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-lg text-xs font-bold uppercase tracking-wider bg-emerald-50 text-emerald-700 border border-emerald-200">
                            {{ $event->localized_entertainment_type }}
                        </div>
                    @endif
                </div>

                <div class="prose prose-slate max-w-none text-slate-700 leading-relaxed text-sm sm:text-base break-words overflow-hidden">
                    {!! $event->formatted_description_html !!}
                </div>
            </div>

        </div>

        <!-- Right Column: Quick Details & Actions Sidebar -->
        <div class="lg:col-span-4 space-y-6 sticky top-24">
            
            <div class="bg-white rounded-3xl p-6 sm:p-7 border border-slate-200/80 shadow-xs space-y-6">
                <h3 class="text-base font-extrabold text-slate-900 tracking-tight pb-4 border-b border-slate-100">
                    Notikuma Informācija
                </h3>

                <!-- Date & Time -->
                <div class="flex items-start gap-3.5">
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-700 flex items-center justify-center shrink-0 border border-emerald-200">
                        <i data-lucide="calendar" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <p class="text-xs text-slate-400 font-bold uppercase tracking-wider">{{ __('Date & Time') }}</p>
                        <p class="text-sm font-extrabold text-slate-900 mt-0.5">{{ $event->formatted_date }}</p>
                        @if($event->end_at)
                            <p class="text-xs text-slate-500 mt-0.5">{{ __('Until') }}: {{ $event->end_at->format('d.m.Y H:i') }}</p>
                        @endif
                    </div>
                </div>

                <!-- Venue & Location -->
                <div class="flex items-start gap-3.5">
                    <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-700 flex items-center justify-center shrink-0 border border-blue-200">
                        <i data-lucide="map-pin" class="w-5 h-5"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs text-slate-400 font-bold uppercase tracking-wider">{{ __('Venue') }}</p>
                        <p class="text-sm font-extrabold text-slate-900 mt-0.5">{{ $event->display_venue }}</p>
                        @if($event->location?->address && $event->location->address !== 'Latvia' && $event->location->address !== 'Latvija')
                            <p class="text-xs text-slate-600 mt-0.5">{{ $event->location->address }}, {{ $event->location->city }}</p>
                        @elseif($event->location?->city)
                            <p class="text-xs text-slate-600 mt-0.5">{{ $event->location->city }} ({{ $event->location->region }})</p>
                        @endif

                        @if($event->location?->latitude && $event->location?->longitude && $event->location->name !== 'Riga' && $event->location->name !== 'Latvija')
                            <a 
                                href="https://www.google.com/maps/search/?api=1&query={{ $event->location->latitude }},{{ $event->location->longitude }}" 
                                target="_blank" 
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 text-xs text-emerald-700 hover:text-emerald-800 font-bold mt-2">
                                <i data-lucide="external-link" class="w-3 h-3"></i>
                                {{ __('View on Google Maps') }}
                            </a>
                        @endif
                    </div>
                </div>

                <!-- Organizer (if available) -->
                @if(!empty($event->organizer_display_name))
                    <div class="flex items-start gap-3.5">
                        <div class="w-10 h-10 rounded-xl bg-slate-50 text-slate-700 flex items-center justify-center shrink-0 border border-slate-200">
                            <i data-lucide="user-check" class="w-5 h-5"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-slate-400 font-bold uppercase tracking-wider">{{ __('Organizer') }}</p>
                            <p class="text-sm font-extrabold text-slate-900 mt-0.5">{{ $event->organizer_display_name }}</p>
                            @if(!empty($event->organizer_url))
                                <a 
                                    href="{{ $event->organizer_url }}" 
                                    target="_blank" 
                                    rel="noopener noreferrer" 
                                    class="inline-flex items-center gap-1 text-xs text-emerald-700 hover:text-emerald-800 font-bold mt-1">
                                    <i data-lucide="external-link" class="w-3 h-3"></i>
                                    <span>{{ __('Organizer website') }}</span>
                                </a>
                            @endif
                        </div>
                    </div>
                @endif

                <!-- Opening Hours -->
                @if(!empty($event->raw_data['opening_hours']))
                    @php
                        $openingHoursList = $event->raw_data['opening_hours'];
                        if (is_array($openingHoursList)) {
                            $dayOrder = [
                                'Pirmdiena' => 1,
                                'Otrdiena' => 2,
                                'Trešdiena' => 3,
                                'Ceturtdiena' => 4,
                                'Piektdiena' => 5,
                                'Sestdiena' => 6,
                                'Svētdiena' => 7,
                                'Pirmdiena – Piektdiena' => 1,
                                'Otrdiena – Piektdiena' => 2,
                                'Trešdiena – Piektdiena' => 3,
                                'Sestdiena, Svētdiena' => 6,
                                'Sestdien, svētdien' => 6,
                            ];
                            uksort($openingHoursList, fn($a, $b) => ($dayOrder[$a] ?? 99) <=> ($dayOrder[$b] ?? 99));
                        }
                    @endphp
                    <div class="flex items-start gap-3.5">
                        <div class="w-10 h-10 rounded-xl bg-purple-50 text-purple-700 flex items-center justify-center shrink-0 border border-purple-200">
                            <i data-lucide="clock" class="w-5 h-5"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs text-slate-400 font-bold uppercase tracking-wider">{{ __('Opening hours') }}</p>
                            <div class="mt-2 space-y-1.5 text-xs text-slate-700 font-medium">
                                @if(is_array($openingHoursList))
                                    @foreach($openingHoursList as $day => $time)
                                        <div class="flex items-center justify-between gap-2 py-0.5 border-b border-slate-100 last:border-0">
                                            @if(is_string($day) && !is_numeric($day))
                                                <span class="text-slate-500 font-medium truncate">{{ $day }}</span>
                                                <span class="font-bold text-slate-900 shrink-0">{{ $time }}</span>
                                            @else
                                                <span class="font-bold text-slate-900">{{ $time }}</span>
                                            @endif
                                        </div>
                                    @endforeach
                                @else
                                    <p class="font-bold text-slate-900">{{ $openingHoursList }}</p>
                                @endif
                            </div>
                            @if(!empty($event->raw_data['opening_hours_source']))
                                <a 
                                    href="{{ $event->raw_data['opening_hours_source'] }}" 
                                    target="_blank" 
                                    rel="noopener noreferrer" 
                                    class="inline-flex items-center gap-1 text-[11px] text-emerald-700 hover:text-emerald-800 font-bold mt-2.5">
                                    <i data-lucide="external-link" class="w-3 h-3"></i>
                                    {{ __('View all museum hours') }}
                                </a>
                            @endif
                        </div>
                    </div>
                @endif

                <!-- Price & Info -->
                <div class="flex items-start gap-3.5">
                    <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-700 flex items-center justify-center shrink-0 border border-amber-200">
                        <i data-lucide="ticket" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <p class="text-xs text-slate-400 font-bold uppercase tracking-wider">{{ __('Admission') }}</p>
                        <p class="text-sm font-extrabold text-slate-900 mt-0.5">{{ $event->formatted_price }}</p>
                    </div>
                </div>

                <!-- Direct External Action Buttons -->
                <div class="pt-2 flex flex-col gap-3">
                    @php
                        $ticketLinks = $event->ticket_links;
                    @endphp

                    @if(count($ticketLinks) > 1)
                        <div class="space-y-2">
                            <p class="text-[11px] text-slate-500 font-extrabold uppercase tracking-wider flex items-center gap-1.5">
                                <i data-lucide="ticket" class="w-3.5 h-3.5 text-emerald-600"></i>
                                <span>{{ __('Seansi un biļetes kinoteātros') }}</span>
                            </p>
                            <div class="grid grid-cols-1 gap-2">
                                @foreach($ticketLinks as $tLink)
                                    <a 
                                        href="{{ $tLink['url'] }}" 
                                        target="_blank" 
                                        rel="noopener noreferrer"
                                        class="w-full py-3 px-4 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-extrabold text-xs sm:text-sm flex items-center justify-between gap-2 hover:from-emerald-700 hover:to-teal-700 transition-all shadow-md shadow-emerald-600/20">
                                        <span class="truncate">{{ $tLink['label'] }}</span>
                                        <i data-lucide="external-link" class="w-4 h-4 shrink-0"></i>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @elseif(count($ticketLinks) === 1)
                        <a 
                            href="{{ $ticketLinks[0]['url'] }}" 
                            target="_blank" 
                            rel="noopener noreferrer"
                            class="w-full py-3.5 px-4 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-extrabold text-sm flex items-center justify-center gap-2 hover:from-emerald-700 hover:to-teal-700 transition-all shadow-md shadow-emerald-600/20">
                            <i data-lucide="external-link" class="w-4 h-4"></i>
                            <span>{{ $ticketLinks[0]['label'] }}</span>
                        </a>
                    @elseif($event->ticket_url)
                        <a 
                            href="{{ $event->ticket_url }}" 
                            target="_blank" 
                            rel="noopener noreferrer"
                            class="w-full py-3.5 px-4 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-extrabold text-sm flex items-center justify-center gap-2 hover:from-emerald-700 hover:to-teal-700 transition-all shadow-md shadow-emerald-600/20">
                            <i data-lucide="external-link" class="w-4 h-4"></i>
                            {{ __('Buy tickets') }}
                        </a>
                    @endif

                    @if($event->source_url && $event->source_url !== $event->ticket_url && !in_array($event->source_url, array_column($ticketLinks, 'url')))
                        <a 
                            href="{{ $event->source_url }}" 
                            target="_blank" 
                            rel="noopener noreferrer"
                            class="w-full py-3.5 px-4 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold text-sm flex items-center justify-center gap-2 border border-slate-200 transition-all">
                            <i data-lucide="globe" class="w-4 h-4 text-slate-600"></i>
                            {{ __('Official event website') }}
                        </a>
                    @endif
                </div>

                <!-- Admin Publication & Category Controls (Visible only to administrators) -->
                @if(auth()->check() && auth()->user()->isAdmin())
                    <div class="pt-4 pb-1 border-t border-slate-200">
                        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200/90 shadow-xs space-y-3.5">
                            <div class="flex items-center justify-between">
                                <span class="text-[11px] font-extrabold uppercase tracking-wider text-slate-500 flex items-center gap-1.5">
                                    <i data-lucide="shield" class="w-3.5 h-3.5 text-purple-600"></i>
                                    <span>{{ __('Admin vadība') }}</span>
                                </span>
                                
                                @if($event->isPublished())
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[11px] font-extrabold bg-emerald-100 text-emerald-800 border border-emerald-200">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-600"></span>
                                        <span>Publicēts</span>
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[11px] font-extrabold bg-amber-100 text-amber-800 border border-amber-200">
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-600"></span>
                                        <span>Melnraksts</span>
                                    </span>
                                @endif
                            </div>

                            @if(session('status'))
                                <div class="p-2.5 rounded-xl bg-emerald-50 text-emerald-800 text-xs font-bold border border-emerald-200 flex items-center gap-2">
                                    <i data-lucide="check" class="w-4 h-4 text-emerald-600 shrink-0"></i>
                                    <span>{{ session('status') }}</span>
                                </div>
                            @endif

                            <!-- Publish / Unpublish Button -->
                            <form action="{{ route('admin.events.toggle-publish', $event->id) }}" method="POST">
                                @csrf
                                @if($event->isPublished())
                                    <button 
                                        type="submit" 
                                        class="w-full py-2.5 px-4 rounded-xl bg-red-600 hover:bg-red-700 text-white font-extrabold text-xs flex items-center justify-center gap-2 shadow-md shadow-red-600/20 transition-all cursor-pointer">
                                        <i data-lucide="eye-off" class="w-3.5 h-3.5"></i>
                                        <span>{{ __('Atsaukt publicēšanu') }}</span>
                                    </button>
                                @else
                                    <button 
                                        type="submit" 
                                        class="w-full py-2.5 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold text-xs flex items-center justify-center gap-2 shadow-md shadow-emerald-600/25 transition-all cursor-pointer">
                                        <i data-lucide="check-circle" class="w-3.5 h-3.5"></i>
                                        <span>{{ __('Publicēt') }}</span>
                                    </button>
                                @endif
                            </form>

                            <!-- Category and Entertainment Type Selector Form -->
                            <form action="{{ route('admin.events.update-category', $event->id) }}" method="POST" class="pt-3 border-t border-slate-200/80 space-y-3">
                                @csrf
                                <div>
                                    <div class="flex items-center justify-between mb-1.5">
                                        <label class="block text-[11px] font-bold text-slate-600">
                                            {{ __('Pasākuma kategorijas / birkas') }}:
                                        </label>
                                        <span class="text-[10px] font-normal text-slate-400">Atzīmējiet visas atbilstošās</span>
                                    </div>
                                    
                                    <div class="grid grid-cols-2 gap-1.5 p-2 bg-white rounded-xl border border-slate-200 shadow-2xs max-h-48 overflow-y-auto">
                                        @if(isset($allCategories))
                                            @foreach($allCategories as $cat)
                                                @php
                                                    $isChecked = $event->categories->contains('id', $cat->id);
                                                @endphp
                                                <label class="flex items-center gap-2 p-1.5 rounded-lg border text-xs font-semibold cursor-pointer transition-colors select-none {{ $isChecked ? 'bg-emerald-50 text-emerald-900 border-emerald-300 font-bold' : 'bg-slate-50/50 text-slate-600 border-slate-200/80 hover:bg-slate-100' }}">
                                                    <input 
                                                        type="checkbox" 
                                                        name="category_ids[]" 
                                                        value="{{ $cat->id }}" 
                                                        {{ $isChecked ? 'checked' : '' }}
                                                        class="w-3.5 h-3.5 text-emerald-600 rounded border-slate-300 focus:ring-emerald-500 cursor-pointer">
                                                    <span class="truncate">{{ $cat->name }}</span>
                                                </label>
                                            @endforeach
                                        @endif
                                    </div>
                                </div>

                                <div>
                                    <label for="admin-type-select" class="block text-[11px] font-bold text-slate-600 mb-1 flex items-center justify-between">
                                        <span>{{ __('Izklaides veids') }}:</span>
                                    </label>
                                    <div class="relative">
                                        <select 
                                            id="admin-type-select" 
                                            name="entertainment_type" 
                                            class="w-full appearance-none bg-white text-slate-800 text-xs font-bold py-2 pl-3 pr-8 rounded-xl border border-slate-300 hover:border-slate-400 focus:outline-none focus:ring-1 focus:ring-emerald-500 cursor-pointer shadow-xs">
                                            <option value="" {{ empty($event->entertainment_type) ? 'selected' : '' }}>— Nav norādīts —</option>
                                            <option value="performance" {{ $event->entertainment_type === 'performance' ? 'selected' : '' }}>Izrāde (Performance)</option>
                                            <option value="concert" {{ $event->entertainment_type === 'concert' ? 'selected' : '' }}>Koncerts</option>
                                            <option value="exhibition" {{ $event->entertainment_type === 'exhibition' ? 'selected' : '' }}>Izstāde</option>
                                            <option value="movie" {{ $event->entertainment_type === 'movie' ? 'selected' : '' }}>Filma / Kino</option>
                                            <option value="workshop" {{ $event->entertainment_type === 'workshop' ? 'selected' : '' }}>Meistarklase / Seminārs</option>
                                            <option value="family" {{ $event->entertainment_type === 'family' ? 'selected' : '' }}>Ģimenei / Bērniem</option>
                                            <option value="active" {{ $event->entertainment_type === 'active' ? 'selected' : '' }}>Sports / Aktīvā atpūta</option>
                                            <option value="party" {{ $event->entertainment_type === 'party' ? 'selected' : '' }}>Ballīte / Festivāls</option>
                                            <option value="show" {{ $event->entertainment_type === 'show' ? 'selected' : '' }}>Šovs</option>
                                            <option value="chill" {{ $event->entertainment_type === 'chill' ? 'selected' : '' }}>Atpūta</option>
                                        </select>
                                        <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-slate-500 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
                                    </div>
                                </div>

                                <button 
                                    type="submit" 
                                    class="w-full py-2 px-3 rounded-xl bg-slate-800 hover:bg-slate-900 text-white font-bold text-xs flex items-center justify-center gap-1.5 shadow-sm transition-all cursor-pointer">
                                    <i data-lucide="check" class="w-3.5 h-3.5 text-emerald-400"></i>
                                    <span>{{ __('Saglabāt birkas') }}</span>
                                </button>
                            </form>
                        </div>
                    </div>
                @endif

                <!-- Informational Disclaimer Note -->
                <div class="p-3.5 rounded-xl bg-emerald-50/70 border border-emerald-200/80 text-[11px] text-emerald-900 leading-relaxed flex items-start gap-2.5">
                    <i data-lucide="info" class="w-4 h-4 text-emerald-700 shrink-0 mt-0.5"></i>
                    <span>
                        {{ __('Disclaimer') }}
                    </span>
                </div>
            </div>

        </div>

    </div>

    <!-- Related Events -->
    @if(isset($relatedEvents) && $relatedEvents->isNotEmpty())
        <div class="mt-16 pt-12 border-t border-slate-200">
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h2 class="text-2xl font-extrabold text-slate-900">{{ __('Similar events') }}</h2>
                    <p class="text-sm text-slate-500">{{ __('Other events in this region') }}</p>
                </div>

                <a href="{{ route('events.index') }}" class="text-sm font-bold text-emerald-700 hover:text-emerald-800">
                    {{ __('View all') }} &rarr;
                </a>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                @foreach($relatedEvents as $rel)
                    <a href="{{ route('events.show', $rel->slug) }}" class="group block bg-white rounded-2xl border border-slate-200/90 overflow-hidden shadow-xs hover:shadow-md hover:border-slate-300 transition-all">
                        <div class="relative aspect-video bg-slate-100 overflow-hidden">
                            <img 
                                src="{{ $rel->display_image_url }}" 
                                alt="{{ $rel->title }}" 
                                width="400"
                                height="225"
                                loading="lazy"
                                decoding="async"
                                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                            <div class="absolute inset-0 bg-gradient-to-t from-slate-950/70 via-transparent to-transparent"></div>
                            <span class="absolute bottom-2 left-2 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-white/95 text-slate-900 backdrop-blur-md shadow-xs">
                                {{ $rel->formatted_date }}
                            </span>
                        </div>
                        <div class="p-4">
                            <h4 class="text-sm font-bold text-slate-900 group-hover:text-emerald-700 line-clamp-2">{{ $rel->title }}</h4>
                            <p class="text-xs text-slate-500 mt-1">{{ $rel->location?->city ?: 'Latvija' }} &bull; <span class="text-emerald-700 font-semibold">{{ $rel->formatted_price }}</span></p>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection

@push('schema')
@php
    $rawPerformer = $event->raw_data['performer'] ?? $event->raw_data['artist'] ?? null;
    $performerName = $rawPerformer ?: ($event->location?->name ?: $event->title);

    $endDate = $event->end_at 
        ? $event->end_at->toIso8601String() 
        : ($event->start_at ? ($event->all_day ? $event->start_at->copy()->endOfDay()->toIso8601String() : $event->start_at->copy()->addHours(2)->toIso8601String()) : null);

    $sourceName = $event->source?->name ?? '';
    $isAggregatorSource = preg_match('/(api|scraper|bezrindas|paradize|serviss|afiro)/i', $sourceName);
    $organizerName = (!$isAggregatorSource && !empty($sourceName)) 
        ? $sourceName 
        : ($event->location?->name ?: 'Šodiena');

    $schemaData = [
        '@context' => 'https://schema.org',
        '@type' => 'Event',
        'name' => $event->title,
        'description' => $event->seo_description,
        'image' => [
            $event->display_image_url,
        ],
        'startDate' => $event->start_at ? $event->start_at->toIso8601String() : null,
        'eventStatus' => 'https://schema.org/EventScheduled',
        'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        'performer' => [
            '@type' => 'PerformingGroup',
            'name' => $performerName,
        ],
        'location' => [
            '@type' => 'Place',
            'name' => $event->location?->name ?: ($event->location?->city ?: 'Latvija'),
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => $event->location?->address ?: '',
                'addressLocality' => $event->location?->city ?: 'Rīga',
                'addressCountry' => 'LV',
            ],
        ],
        'offers' => [
            '@type' => 'Offer',
            'url' => $event->ticket_url ?: route('events.show', $event->slug),
            'price' => (string) ($event->is_free ? '0' : ($event->price_min ?? '0')),
            'priceCurrency' => 'EUR',
            'availability' => 'https://schema.org/InStock',
            'validFrom' => $event->created_at ? $event->created_at->toIso8601String() : now()->toIso8601String(),
        ],
        'organizer' => [
            '@type' => 'Organization',
            'name' => $organizerName,
            'url' => config('app.url', 'https://sodiena.lv'),
        ],
    ];

    if ($endDate) {
        $schemaData['endDate'] = $endDate;
    }

    if ($event->location?->latitude && $event->location?->longitude) {
        $schemaData['location']['geo'] = [
            '@type' => 'GeoCoordinates',
            'latitude' => (float) $event->location->latitude,
            'longitude' => (float) $event->location->longitude,
        ];
    }

    $breadcrumbData = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            [
                '@type' => 'ListItem',
                'position' => 1,
                'name' => 'Šodiena',
                'item' => config('app.url', 'https://sodiena.lv'),
            ],
            [
                '@type' => 'ListItem',
                'position' => 2,
                'name' => $event->categories->first()?->name ?? 'Pasākumi',
                'item' => config('app.url', 'https://sodiena.lv'),
            ],
            [
                '@type' => 'ListItem',
                'position' => 3,
                'name' => $event->title,
                'item' => route('events.show', $event->slug),
            ],
        ],
    ];
@endphp
<script type="application/ld+json">
{!! json_encode($schemaData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) !!}
</script>
<script type="application/ld+json">
{!! json_encode($breadcrumbData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) !!}
</script>
@endpush
