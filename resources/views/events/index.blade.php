@extends('layouts.app')

@section('title', 'Šodiena — ' . __('Find Events'))
@section('meta_description', __('Discover future events'))
@section('canonical_url', \App\Services\LocaleService::url(app()->getLocale(), url('/')))

@if(isset($events) && $events->isNotEmpty())
    @push('styles')
        <link rel="preload" as="image" href="{{ $events->first()->display_image_url }}" fetchpriority="high">
    @endpush
@endif

@section('content')
<!-- Category Sub-Nav Bar (Full Width with bottom border, like pasakumiii.lv) -->
<div id="category-carousel-wrapper" class="w-full bg-white border-b border-slate-200/80">
    <div class="w-full px-3.5 sm:px-6 lg:px-8 xl:px-10 2xl:px-12 mx-auto">
        @include('events.partials.category-pills', [
            'categories' => $categories,
            'totalUpcoming' => $totalUpcoming,
            'categorySlug' => $categorySlug
        ])
    </div>
</div>

<!-- Main Page Content -->
<div class="w-full px-3.5 sm:px-6 lg:px-8 xl:px-10 2xl:px-12 mx-auto pt-4 sm:pt-6 pb-12">
    
    <!-- Title & Compact Filter Bar -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 mb-4 sm:mb-5">
        <!-- Heading & Count (like pasakumiii: Meklēšana / Notikumi & 48 rezultāti) -->
        <div>
            <h1 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight" id="page-heading">
                @if(request('search'))
                    {{ __('Search results') }}
                @elseif($categorySlug && ($currentCategory = $categories->firstWhere('slug', $categorySlug)))
                    {{ $currentCategory->name }}
                @else
                    {{ __('Events') }}
                @endif
            </h1>
            <p class="text-xs text-slate-500 font-medium mt-0.5">
                <span id="events-total-count">{{ $totalUpcoming }}</span> {{ __('results') }}
            </p>
        </div>

        <!-- Compact Filter Controls: Quick Dates, Date Picker, City, Price -->
        <div class="flex flex-wrap items-center gap-1.5 sm:gap-2">
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
                    aria-label="{{ $label }}"
                    onclick="applyFilter('period', '{{ $key }}')"
                    class="period-btn px-2.5 py-1 rounded-md text-xs font-semibold transition-colors cursor-pointer {{ (!request('date') && (request('period', 'all') === $key)) ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:text-slate-900 hover:bg-slate-200/70' }}">
                    {{ $label }}
                </button>
            @endforeach

            <!-- Custom Exact Date Picker Button with Flatpickr -->
            <div class="relative inline-flex items-center">
                <button 
                    type="button" 
                    id="date-picker-btn"
                    aria-label="{{ __('Date') }}: {{ request('date') ? \Carbon\Carbon::parse(request('date'))->format('d.m.Y') : __('All dates') }}"
                    class="relative flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-semibold transition-all cursor-pointer {{ request('date') ? 'bg-slate-900 text-white shadow-xs' : 'bg-slate-100 text-slate-600 hover:text-slate-900 hover:bg-slate-200/70' }}">
                    <i data-lucide="calendar" class="w-3.5 h-3.5 {{ request('date') ? 'text-white' : 'text-slate-400' }}"></i>
                    <span id="date-picker-label">{{ request('date') ? \Carbon\Carbon::parse(request('date'))->format('d.m.Y') : __('Date') }}</span>
                    <input 
                        type="text" 
                        id="custom-date-picker" 
                        value="{{ request('date') }}"
                        class="sr-only pointer-events-none"
                        tabindex="-1"
                        aria-label="{{ __('Date') }}"
                        aria-hidden="true">
                </button>
                <button 
                    type="button" 
                    id="clear-date-btn"
                    onclick="clearExactDate()" 
                    aria-label="Notīrīt datumu"
                    title="Notīrīt datumu"
                    class="ml-1 text-slate-400 hover:text-slate-600 p-0.5 rounded-md hover:bg-slate-100 transition-colors {{ request('date') ? 'inline-flex' : 'hidden' }}">
                    <i data-lucide="x" class="w-3.5 h-3.5"></i>
                </button>
            </div>

            <!-- City Selector -->
            <div class="relative inline-block">
                <select 
                    id="filter-city-select"
                    name="city"
                    aria-label="{{ __('All cities') }}"
                    onchange="applyFilter('city', this.value)"
                    class="appearance-none bg-white text-slate-700 text-xs font-semibold py-1 pl-2.5 pr-7 rounded-md border border-slate-200 hover:border-slate-300 focus:outline-none focus:ring-1 focus:ring-slate-900 cursor-pointer">
                    <option value="all" {{ (!request('city') || request('city') === 'all') ? 'selected' : '' }}>{{ __('All cities') }}</option>
                    @foreach($cities as $c)
                        <option value="{{ $c }}" {{ request('city') === $c ? 'selected' : '' }}>📍 {{ $c }}</option>
                    @endforeach
                </select>
                <i data-lucide="chevron-down" class="w-3 h-3 text-slate-400 absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none" aria-hidden="true"></i>
            </div>

            <!-- Price Filter -->
            <div class="relative inline-block">
                <select 
                    id="filter-price-select"
                    name="price"
                    aria-label="{{ __('All prices') }}"
                    onchange="applyFilter('price', this.value)"
                    class="appearance-none bg-white text-slate-700 text-xs font-semibold py-1 pl-2.5 pr-7 rounded-md border border-slate-200 hover:border-slate-300 focus:outline-none focus:ring-1 focus:ring-slate-900 cursor-pointer">
                    <option value="all" {{ (!request('price') || request('price') === 'all') ? 'selected' : '' }}>{{ __('All prices') }}</option>
                    <option value="free" {{ request('price') === 'free' ? 'selected' : '' }}>{{ __('Only Free') }}</option>
                    <option value="paid" {{ request('price') === 'paid' ? 'selected' : '' }}>{{ __('Only Paid') }}</option>
                </select>
                <i data-lucide="chevron-down" class="w-3 h-3 text-slate-400 absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none" aria-hidden="true"></i>
            </div>
        </div>
    </div>

    <!-- Dynamic Events Container (Target of HTMX Requests) -->
    <div id="events-container" class="events-grid-auto">
        @include('events.partials.events-list', ['events' => $events])
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

            if (isActive) {
                btn.className = 'category-btn shrink-0 inline-flex items-center gap-1.5 transition-colors cursor-pointer py-1 text-slate-900 font-bold border-b-2 border-slate-900 -mb-[1px]';
            } else {
                btn.className = 'category-btn shrink-0 inline-flex items-center gap-1.5 transition-colors cursor-pointer py-1 text-slate-600 hover:text-slate-900 font-medium border-b-2 border-transparent';
            }
        });
    }

    function updatePeriodButtons(activePeriod) {
        const targetPeriod = (!activePeriod || activePeriod === '') ? 'all' : activePeriod;
        document.querySelectorAll('[data-filter-period]').forEach(btn => {
            const period = btn.getAttribute('data-filter-period');
            const isActive = (period === targetPeriod && !document.getElementById('hidden-date')?.value);

            if (isActive) {
                btn.className = 'period-btn px-2.5 py-1 rounded-md text-xs font-semibold transition-colors cursor-pointer bg-slate-900 text-white';
            } else {
                btn.className = 'period-btn px-2.5 py-1 rounded-md text-xs font-semibold transition-colors cursor-pointer bg-slate-100 text-slate-600 hover:text-slate-900 hover:bg-slate-200/70';
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
            const hiddenDate = document.getElementById('hidden-date');
            if (hiddenDate) hiddenDate.value = '';
            
            if (fpInstance) {
                fpInstance.clear();
            }

            const dateBtn = document.getElementById('date-picker-btn');
            if (dateBtn) {
                dateBtn.className = 'relative flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-semibold transition-all cursor-pointer bg-slate-100 text-slate-600 hover:text-slate-900 hover:bg-slate-200/70';
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

        document.querySelectorAll('[data-filter-period]').forEach(btn => {
            btn.className = 'period-btn px-2.5 py-1 rounded-md text-xs font-semibold transition-colors cursor-pointer bg-slate-100 text-slate-600 hover:text-slate-900 hover:bg-slate-200/70';
        });

        const parts = dateValue.split('-');
        const formatted = (parts.length === 3) ? `${parts[2]}.${parts[1]}.${parts[0]}` : dateValue;
        
        const dateLabel = document.getElementById('date-picker-label');
        if (dateLabel) dateLabel.textContent = formatted;

        const dateBtn = document.getElementById('date-picker-btn');
        if (dateBtn) {
            dateBtn.className = 'relative flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-semibold transition-all cursor-pointer bg-slate-900 text-white shadow-xs';
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
            dateBtn.className = 'relative flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-semibold transition-all cursor-pointer bg-slate-100 text-slate-600 hover:text-slate-900 hover:bg-slate-200/70';
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
