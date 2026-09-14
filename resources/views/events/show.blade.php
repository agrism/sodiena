@extends('layouts.app')

@section('title', $event->title . ' — Šodiena')
@section('meta_description', $event->short_description ?: Str::limit(strip_tags($event->description ?? ''), 160))

@section('content')
<div class="max-w-7xl xl:max-w-[1400px] 2xl:max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12">
    
    <!-- Breadcrumb & Back Link -->
    <div class="mb-6 flex items-center justify-between">
        <a href="{{ route('events.index') }}" class="inline-flex items-center gap-2 text-xs sm:text-sm font-bold text-slate-500 hover:text-emerald-700 transition-colors">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>
            {{ __('Back to all events') }}
        </a>

        <!-- Categories -->
        <div class="flex items-center gap-2">
            @foreach($event->categories as $category)
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-800 border border-slate-200 shadow-xs">
                    <i data-lucide="{{ $category->icon ?: 'tag' }}" class="w-3.5 h-3.5 text-emerald-600"></i>
                    {{ $category->name }}
                </span>
            @endforeach
        </div>
    </div>

    <!-- Main Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 lg:gap-12 items-start">
        
        <!-- Left Column: Hero Image & Description -->
        <div class="lg:col-span-8 space-y-8">
            
            <!-- Hero Image Banner -->
            <div class="relative rounded-3xl overflow-hidden bg-slate-900 border border-slate-200/90 shadow-md aspect-[16/9] sm:aspect-[2/1] md:aspect-[21/9] max-h-[480px]">
                <img 
                    src="{{ $event->display_image_url }}" 
                    alt="{{ $event->title }}"
                    class="w-full h-full object-cover">

                <div class="absolute inset-0 bg-gradient-to-t from-slate-950/80 via-slate-950/20 to-transparent"></div>

                <!-- Admin Source Indicator -->
                @if(auth()->check() && auth()->user()->isAdmin())
                    <div class="absolute top-4 right-4 z-10">
                        @if($event->isAfiro() && $event->afiro_url)
                            <a 
                                href="{{ $event->afiro_url }}" 
                                target="_blank" 
                                rel="noopener noreferrer" 
                                title="Atvērt Afiro notikumu: {{ $event->afiro_url }}"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-white/95 text-red-600 border-2 border-red-500 font-black text-xs shadow-lg hover:bg-red-600 hover:text-white transition-all transform hover:scale-105"
                            >
                                <span class="w-4 h-4 rounded-full border-2 border-current flex items-center justify-center text-[10px] font-black">A</span>
                                <span>Afiro</span>
                                <i data-lucide="external-link" class="w-3 h-3"></i>
                            </a>
                        @else
                            <span 
                                title="Nav Afiro notikums (Avots: {{ $event->source?->name ?: $event->source_slug ?: 'Cits' }})"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-white/90 text-slate-500 border border-slate-300 font-bold text-xs shadow-md"
                            >
                                <span class="w-4 h-4 rounded-full border border-current flex items-center justify-center text-[10px] font-bold">A</span>
                                <span>{{ $event->source?->name ?: 'Nav Afiro' }}</span>
                            </span>
                        @endif
                    </div>
                @endif

                <!-- Floating info on image -->
                <div class="absolute bottom-6 left-6 right-6 flex flex-wrap items-center justify-between gap-3 text-white">
                    <span class="px-3.5 py-1.5 rounded-xl bg-white/95 text-slate-950 text-xs font-extrabold backdrop-blur-md shadow-lg flex items-center gap-2">
                        <i data-lucide="calendar" class="w-4 h-4 text-emerald-600"></i>
                        {{ $event->formatted_date }}
                    </span>

                    @if($event->location?->city)
                        <span class="px-3.5 py-1.5 rounded-xl bg-slate-900/80 text-white text-xs font-bold backdrop-blur-md border border-white/20 flex items-center gap-1.5">
                            <i data-lucide="map-pin" class="w-4 h-4 text-emerald-400"></i>
                            {{ $event->location->name ?: $event->location->city }}
                        </span>
                    @endif
                </div>
            </div>

            <!-- Title & Description Card -->
            <div class="bg-white rounded-3xl p-6 sm:p-10 border border-slate-200/80 shadow-xs space-y-6">
                <div>
                    <h1 class="text-2xl sm:text-4xl font-extrabold text-slate-900 tracking-tight leading-tight">
                        {{ $event->title }}
                    </h1>
                    @if($event->localized_entertainment_type)
                        <div class="mt-3 inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-lg text-xs font-bold uppercase tracking-wider bg-emerald-50 text-emerald-700 border border-emerald-200">
                            {{ $event->localized_entertainment_type }}
                        </div>
                    @endif
                </div>

                @php
                    $cleanShort = trim(preg_replace('/[\s\.\…]+$/u', '', $event->short_description ?? ''));
                    $cleanDesc = trim(preg_replace('/[\s\.\…]+$/u', '', $event->description ?? ''));
                    $isAutoSnippet = !empty($cleanShort) && (
                        $cleanShort === $cleanDesc ||
                        str_starts_with($cleanDesc, $cleanShort) ||
                        (mb_strlen($cleanShort) >= 30 && mb_substr($cleanShort, 0, 30) === mb_substr($cleanDesc, 0, 30))
                    );
                @endphp
                @if(!empty($event->short_description) && !$isAutoSnippet)
                    <p class="text-base sm:text-lg font-medium text-slate-700 leading-relaxed bg-slate-50 p-4 sm:p-5 rounded-2xl border border-slate-200/60">
                        {{ $event->short_description }}
                    </p>
                @endif

                <div class="prose prose-slate max-w-none text-slate-700 leading-relaxed text-sm sm:text-base">
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
                    <div>
                        <p class="text-xs text-slate-400 font-bold uppercase tracking-wider">{{ __('Venue') }}</p>
                        <p class="text-sm font-extrabold text-slate-900 mt-0.5">{{ $event->location?->name ?: 'Latvija' }}</p>
                        @if($event->location?->address)
                            <p class="text-xs text-slate-600 mt-0.5">{{ $event->location->address }}, {{ $event->location->city }}</p>
                        @elseif($event->location?->city)
                            <p class="text-xs text-slate-600 mt-0.5">{{ $event->location->city }} ({{ $event->location->region }})</p>
                        @endif

                        @if($event->location?->latitude && $event->location?->longitude)
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
                    @if($event->ticket_url)
                        <a 
                            href="{{ $event->ticket_url }}" 
                            target="_blank" 
                            rel="noopener noreferrer"
                            class="w-full py-3.5 px-4 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-extrabold text-sm flex items-center justify-center gap-2 hover:from-emerald-700 hover:to-teal-700 transition-all shadow-md shadow-emerald-600/20">
                            <i data-lucide="external-link" class="w-4 h-4"></i>
                            {{ __('Buy tickets') }}
                        </a>
                    @endif

                    @if($event->source_url && $event->source_url !== $event->ticket_url)
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
    @if($relatedEvents->isNotEmpty())
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
                            <img src="{{ $rel->display_image_url }}" alt="{{ $rel->title }}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
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
