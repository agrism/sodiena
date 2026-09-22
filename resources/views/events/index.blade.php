@extends('layouts.app')

@section('title', 'Šodiena — ' . __('Find Events'))
@section('meta_description', __('Discover future events'))
@section('canonical_url', url('/'))

@if(isset($events) && $events->isNotEmpty())
    @push('styles')
        <link rel="preload" as="image" href="{{ $events->first()->display_image_url }}" fetchpriority="high">
    @endpush
@endif

@section('content')
<div class="relative overflow-hidden bg-gradient-to-b from-emerald-50/60 via-slate-50 to-slate-50 pb-12 pt-6 sm:pt-10">
    <div class="max-w-7xl xl:max-w-[1400px] 2xl:max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Hero Header -->
        <div class="text-center max-w-4xl mx-auto mb-10">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-100/80 text-emerald-800 text-xs font-extrabold tracking-wide mb-4 border border-emerald-200">
                <span class="w-2 h-2 rounded-full bg-emerald-600 animate-pulse"></span>
                <span>{{ $totalUpcoming }} {{ __('Events') }}</span>
            </div>
            <h1 class="text-3xl sm:text-5xl font-extrabold tracking-tight text-slate-900 leading-tight">
                {{ __('Find Events') }}
            </h1>
            <p class="mt-3 text-sm sm:text-base text-slate-600 font-medium">
                {{ __('Discover future events') }}
            </p>
        </div>

        <!-- Search Bar -->
        <div class="max-w-4xl xl:max-w-5xl mx-auto mb-8">
            <form 
                id="filter-form"
                hx-get="{{ route('events.index') }}" 
                hx-target="#events-container" 
                hx-trigger="submit, keyup changed delay:400ms from:#search-input, change from:select"
                hx-indicator="#loading-spinner"
                hx-push-url="true"
                class="relative flex items-center bg-white rounded-2xl shadow-sm border border-slate-200/90 p-2 focus-within:ring-2 focus-within:ring-emerald-500/20 focus-within:border-emerald-500 transition-all">
                
                <div class="pl-3.5 pr-2 text-slate-400">
                    <i data-lucide="search" class="w-5 h-5"></i>
                </div>
                
                <input 
                    type="text" 
                    id="search-input"
                    name="search" 
                    value="{{ request('search') }}"
                    placeholder="{{ __('Search placeholder') }}"
                    class="w-full bg-transparent border-0 py-2.5 px-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none font-medium"
                    autocomplete="off">

                <!-- Loading Spinner Indicator -->
                <div id="loading-spinner" class="htmx-indicator pr-3 text-emerald-600">
                    <i data-lucide="loader-2" class="w-5 h-5 animate-spin"></i>
                </div>

                <button 
                    type="submit" 
                    class="px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm transition-colors cursor-pointer shrink-0">
                    {{ __('Search button') }}
                </button>

                <!-- Hidden inputs for active state preservation -->
                <input type="hidden" name="category" id="hidden-category" value="{{ request('category', 'all') }}">
                <input type="hidden" name="period" id="hidden-period" value="{{ request('period', 'all') }}">
                <input type="hidden" name="date" id="hidden-date" value="{{ request('date', '') }}">
                <input type="hidden" name="city" id="hidden-city" value="{{ request('city', 'all') }}">
                <input type="hidden" name="price" id="hidden-price" value="{{ request('price', 'all') }}">
            </form>
        </div>

        <!-- Category Carousel / Pills -->
        <div id="category-carousel-wrapper" class="mb-8 w-full max-w-full overflow-hidden">
            @include('events.partials.category-pills', [
                'categories' => $categories,
                'totalUpcoming' => $totalUpcoming,
                'categorySlug' => $categorySlug
            ])
        </div>

        <!-- Filter Control Bar -->
        <div class="bg-white border border-slate-200/80 rounded-2xl p-3 sm:p-4 mb-8 shadow-xs w-full max-w-full overflow-hidden">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3 w-full">
                
                <!-- Quick Date Filters & Exact Date Picker -->
                <div class="flex flex-wrap items-center gap-1.5 sm:gap-2 w-full lg:w-auto">
                    <span class="text-xs font-bold text-slate-500 uppercase tracking-wider mr-1 hidden sm:inline">{{ __('When') }}</span>
                    @php
                        $periods = [
                            'all' => __('All dates'),
                            'today' => __('Today'),
                            'tomorrow' => __('Tomorrow'),
                            'weekend' => __('Weekend'),
                            'this_week' => __('This week'),
                            'this_month' => __('This month'),
                        ];
                    @endphp

                    @foreach($periods as $key => $label)
                        <button 
                            type="button"
                            data-filter-period="{{ $key }}"
                            onclick="applyFilter('period', '{{ $key }}')"
                            class="period-btn px-2.5 sm:px-3 py-1.5 rounded-lg text-xs font-bold transition-colors cursor-pointer {{ (!request('date') && (request('period', 'all') === $key)) ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100 border border-transparent' }}">
                            {{ $label }}
                        </button>
                    @endforeach

                    <!-- Custom Exact Date Picker Button with Flatpickr -->
                    <div class="relative inline-flex items-center">
                        <button 
                            type="button" 
                            id="date-picker-btn"
                            class="relative flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer {{ request('date') ? 'bg-emerald-50 text-emerald-700 border border-emerald-200 shadow-xs' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100 border border-slate-200/90 bg-slate-50/50' }}">
                            <i data-lucide="calendar" class="w-3.5 h-3.5 {{ request('date') ? 'text-emerald-700' : 'text-slate-400' }}"></i>
                            <span id="date-picker-label">{{ request('date') ? \Carbon\Carbon::parse(request('date'))->format('d.m.Y') : __('Date') }}</span>
                            <input 
                                type="text" 
                                id="custom-date-picker" 
                                value="{{ request('date') }}"
                                class="sr-only pointer-events-none"
                                tabindex="-1"
                                aria-hidden="true">
                        </button>
                        <button 
                            type="button" 
                            id="clear-date-btn"
                            onclick="clearExactDate()" 
                            title="Notīrīt datumu"
                            class="ml-1 text-slate-400 hover:text-slate-600 p-0.5 rounded-md hover:bg-slate-100 transition-colors {{ request('date') ? 'inline-flex' : 'hidden' }}">
                            <i data-lucide="x" class="w-3.5 h-3.5"></i>
                        </button>
                    </div>
                </div>

                <!-- Secondary Filters: City & Price -->
                <div class="flex flex-wrap items-center gap-2 sm:gap-3 w-full lg:w-auto lg:ml-auto">
                    <!-- City Selector -->
                    <div class="relative flex-1 sm:flex-initial">
                        <select 
                            onchange="applyFilter('city', this.value)"
                            class="w-full appearance-none bg-slate-50 text-slate-700 text-xs font-bold py-2 pl-3 pr-8 rounded-xl border border-slate-200 hover:border-slate-300 focus:outline-none focus:ring-1 focus:ring-emerald-500 cursor-pointer">
                            <option value="all" {{ (!request('city') || request('city') === 'all') ? 'selected' : '' }}>{{ __('All cities') }}</option>
                            @foreach($cities as $c)
                                <option value="{{ $c }}" {{ request('city') === $c ? 'selected' : '' }}>📍 {{ $c }}</option>
                            @endforeach
                        </select>
                        <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-slate-500 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
                    </div>

                    <!-- Price Filter -->
                    <div class="relative flex-1 sm:flex-initial">
                        <select 
                            onchange="applyFilter('price', this.value)"
                            class="w-full appearance-none bg-slate-50 text-slate-700 text-xs font-bold py-2 pl-3 pr-8 rounded-xl border border-slate-200 hover:border-slate-300 focus:outline-none focus:ring-1 focus:ring-emerald-500 cursor-pointer">
                            <option value="all" {{ (!request('price') || request('price') === 'all') ? 'selected' : '' }}>{{ __('All prices') }}</option>
                            <option value="free" {{ request('price') === 'free' ? 'selected' : '' }}>{{ __('Only Free') }}</option>
                            <option value="paid" {{ request('price') === 'paid' ? 'selected' : '' }}>{{ __('Only Paid') }}</option>
                        </select>
                        <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-slate-500 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
                    </div>
                </div>

            </div>
        </div>

        <!-- Dynamic Events Container (Target of HTMX Requests) -->
        <div id="events-container" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            @include('events.partials.events-list', ['events' => $events])
        </div>

    </div>
</div>
@endsection

@push('scripts')
<script>
    function updateCategoryButtons(activeSlug) {
        const targetSlug = (!activeSlug || activeSlug === '') ? 'all' : activeSlug;
        document.querySelectorAll('[data-filter-category]').forEach(btn => {
            const slug = btn.getAttribute('data-filter-category');
            const isActive = (slug === targetSlug);
            const badge = btn.querySelector('.cat-badge');
            const icon = btn.querySelector('[data-lucide], svg');

            if (isActive) {
                btn.className = 'category-btn shrink-0 inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs sm:text-sm font-bold transition-all duration-200 bg-emerald-600 text-white shadow-md shadow-emerald-600/25 scale-105';
                if (badge) {
                    badge.className = 'cat-badge px-1.5 py-0.5 rounded-full text-[10px] bg-emerald-800/40 text-white';
                }
                if (icon) {
                    icon.classList.remove('text-emerald-600');
                    icon.classList.add('text-white');
                }
            } else {
                btn.className = 'category-btn shrink-0 inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs sm:text-sm font-bold transition-all duration-200 bg-white text-slate-700 border border-slate-200 hover:border-slate-300 hover:bg-slate-50';
                if (badge) {
                    badge.className = 'cat-badge px-1.5 py-0.5 rounded-full text-[10px] bg-slate-100 text-slate-600';
                }
                if (icon) {
                    icon.classList.remove('text-white');
                    icon.classList.add('text-emerald-600');
                }
            }
        });
    }

    function updatePeriodButtons(activePeriod) {
        const targetPeriod = (!activePeriod || activePeriod === '') ? 'all' : activePeriod;
        document.querySelectorAll('[data-filter-period]').forEach(btn => {
            const period = btn.getAttribute('data-filter-period');
            const isActive = (period === targetPeriod && !document.getElementById('hidden-date')?.value);

            if (isActive) {
                btn.className = 'period-btn px-3 py-1.5 rounded-lg text-xs font-bold transition-colors cursor-pointer bg-emerald-50 text-emerald-700 border border-emerald-200';
            } else {
                btn.className = 'period-btn px-3 py-1.5 rounded-lg text-xs font-bold transition-colors cursor-pointer text-slate-600 hover:text-slate-900 hover:bg-slate-100 border border-transparent';
            }
        });
    }

    let fpInstance = null;

    function setupDatePicker() {
        const dateInput = document.getElementById('custom-date-picker');
        const dateBtn = document.getElementById('date-picker-btn');
        if (!dateInput) return;

        if (!window.flatpickr) {
            setTimeout(setupDatePicker, 50);
            return;
        }

        const currentLocale = '{{ app()->getLocale() }}';
        const localeObj = (window.flatpickrLocales && window.flatpickrLocales[currentLocale]) ? window.flatpickrLocales[currentLocale] : 'default';

        if (fpInstance) {
            try { fpInstance.destroy(); } catch (e) {}
        }

        fpInstance = window.flatpickr(dateInput, {
            locale: localeObj,
            dateFormat: 'Y-m-d',
            defaultDate: document.getElementById('hidden-date')?.value || null,
            positionElement: dateBtn,
            disableMobile: "true",
            onChange: function(selectedDates, dateStr) {
                applyExactDate(dateStr);
            }
        });

        if (dateBtn) {
            dateBtn.onclick = function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (fpInstance) {
                    fpInstance.toggle();
                }
            };
        }
    }

    window.addEventListener('flatpickr-ready', setupDatePicker);
    document.addEventListener('flatpickr-ready', setupDatePicker);
    document.addEventListener('DOMContentLoaded', setupDatePicker);
    setupDatePicker();

    function applyFilter(key, value) {
        const hiddenInput = document.getElementById('hidden-' + key);
        if (hiddenInput) {
            hiddenInput.value = value;
        }

        if (key === 'period') {
            // Clear custom date when clicking quick period buttons
            const hiddenDate = document.getElementById('hidden-date');
            if (hiddenDate) hiddenDate.value = '';
            
            if (fpInstance) {
                fpInstance.clear();
            }

            const dateBtn = document.getElementById('date-picker-btn');
            if (dateBtn) {
                dateBtn.className = 'relative flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer text-slate-600 hover:text-slate-900 hover:bg-slate-100 border border-slate-200/90 bg-slate-50/50';
            }
            const dateLabel = document.getElementById('date-picker-label');
            if (dateLabel) dateLabel.textContent = "{{ __('Date') }}";

            const clearBtn = document.getElementById('clear-date-btn');
            if (clearBtn) clearBtn.classList.add('hidden');

            updatePeriodButtons(value);
        } else if (key === 'category') {
            updateCategoryButtons(value);
        }

        const form = document.getElementById('filter-form');
        if (form) {
            htmx.trigger(form, 'submit');
        }
    }

    function applyExactDate(dateValue) {
        if (!dateValue) return;

        const hiddenDate = document.getElementById('hidden-date');
        if (hiddenDate) hiddenDate.value = dateValue;

        const hiddenPeriod = document.getElementById('hidden-period');
        if (hiddenPeriod) hiddenPeriod.value = '';

        // Update all period buttons to inactive
        document.querySelectorAll('[data-filter-period]').forEach(btn => {
            btn.className = 'period-btn px-3 py-1.5 rounded-lg text-xs font-bold transition-colors cursor-pointer text-slate-600 hover:text-slate-900 hover:bg-slate-100 border border-transparent';
        });

        // Update date button style and label
        const parts = dateValue.split('-');
        const formatted = (parts.length === 3) ? `${parts[2]}.${parts[1]}.${parts[0]}` : dateValue;
        
        const dateLabel = document.getElementById('date-picker-label');
        if (dateLabel) dateLabel.textContent = formatted;

        const dateBtn = document.getElementById('date-picker-btn');
        if (dateBtn) {
            dateBtn.className = 'relative flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer bg-emerald-50 text-emerald-700 border border-emerald-200 shadow-xs';
        }

        const clearBtn = document.getElementById('clear-date-btn');
        if (clearBtn) clearBtn.classList.remove('hidden');

        const form = document.getElementById('filter-form');
        if (form) {
            htmx.trigger(form, 'submit');
        }
    }

    function clearExactDate() {
        const hiddenDate = document.getElementById('hidden-date');
        if (hiddenDate) hiddenDate.value = '';

        if (fpInstance) {
            fpInstance.clear();
        }

        const dateLabel = document.getElementById('date-picker-label');
        if (dateLabel) dateLabel.textContent = "{{ __('Date') }}";

        const dateBtn = document.getElementById('date-picker-btn');
        if (dateBtn) {
            dateBtn.className = 'relative flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer text-slate-600 hover:text-slate-900 hover:bg-slate-100 border border-slate-200/90 bg-slate-50/50';
        }

        const clearBtn = document.getElementById('clear-date-btn');
        if (clearBtn) clearBtn.classList.add('hidden');

        applyFilter('period', 'all');
    }

    // Sync button states if user navigates back/forward with browser history
    window.addEventListener('popstate', function() {
        const params = new URLSearchParams(window.location.search);
        const dateParam = params.get('date');
        if (dateParam) {
            if (fpInstance) {
                fpInstance.setDate(dateParam, false);
            }
            applyExactDate(dateParam);
        } else {
            if (fpInstance) {
                fpInstance.clear();
            }
            updatePeriodButtons(params.get('period') || 'all');
        }
        updateCategoryButtons(params.get('category') || 'all');
    });
</script>
@endpush

@push('schema')
@php
    $websiteSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => 'Šodiena',
        'url' => config('app.url', 'https://sodiena.lv'),
        'description' => __('Discover future events'),
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => [
                '@type' => 'EntryPoint',
                'urlTemplate' => config('app.url', 'https://sodiena.lv') . '/?search={search_term_string}',
            ],
            'query-input' => 'required name=search_term_string',
        ],
    ];
@endphp
<script type="application/ld+json">
{!! json_encode($websiteSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) !!}
</script>
@endpush
