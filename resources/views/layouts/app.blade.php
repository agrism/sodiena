<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="h-full bg-slate-50 text-slate-900 overflow-x-hidden">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Šodiena — ' . __('Find Events'))</title>
    <meta name="description" content="@yield('meta_description', __('Discover future events'))">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="@yield('canonical_url', \App\Services\LocaleService::url(app()->getLocale()))">
    @foreach(\App\Services\LocaleService::hreflangUrls() as $lang => $hreflangUrl)
    <link rel="alternate" hreflang="{{ $lang }}" href="{{ $hreflangUrl }}">
    @endforeach
    
    <!-- Open Graph / Meta -->
    <meta property="og:site_name" content="Šodiena">
    <meta property="og:url" content="@yield('canonical_url', \App\Services\LocaleService::url(app()->getLocale()))">
    <meta property="og:title" content="@yield('title', 'Šodiena — ' . __('Find Events'))">
    <meta property="og:description" content="@yield('meta_description', __('Discover future events'))">
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:image" content="@yield('meta_image', asset('images/default-event.jpg'))">
    <meta property="og:locale" content="{{ app()->getLocale() === 'lv' ? 'lv_LV' : (app()->getLocale() === 'ru' ? 'ru_RU' : 'en_US') }}">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@Sodiena_lv">
    <meta name="twitter:creator" content="@Sodiena_lv">
    <meta name="twitter:title" content="@yield('title', 'Šodiena — ' . __('Find Events'))">
    <meta name="twitter:description" content="@yield('meta_description', __('Discover future events'))">
    <meta name="twitter:image" content="@yield('meta_image', asset('images/default-event.jpg'))">

    <!-- Preload Self-Hosted Fonts -->
    <link rel="preload" href="/fonts/plus-jakarta-sans-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/plus-jakarta-sans-latinext.woff2" as="font" type="font/woff2" crossorigin>

    <!-- Preconnect to Image CDN / Object Storage -->
    <link rel="preconnect" href="https://sodiena.hel1.your-objectstorage.com" crossorigin>
    <link rel="dns-prefetch" href="https://sodiena.hel1.your-objectstorage.com">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
    @stack('schema')
</head>
<body class="min-h-full flex flex-col bg-slate-50 text-slate-900 antialiased selection:bg-emerald-500 selection:text-white overflow-x-hidden w-full max-w-full relative">
    <!-- Header -->
    <header class="sticky top-0 z-50 bg-white/95 backdrop-blur-md border-b border-slate-200/80 w-full">
        <div class="{{ (request()->routeIs('events.index') || request()->routeIs('localized.events.index')) ? 'w-full px-3.5 sm:px-6 lg:px-8 xl:px-10 2xl:px-12' : 'max-w-6xl xl:max-w-7xl px-4 sm:px-6 lg:px-8' }} mx-auto">
            <div class="flex items-center justify-between h-14 sm:h-16 gap-3 sm:gap-6">
                <!-- Logo -->
                <a href="{{ \App\Services\LocaleService::url(app()->getLocale(), route('events.index')) }}" aria-label="Šodiena.lv" class="flex items-center gap-2 group shrink-0">
                    <i data-lucide="compass" class="w-5 h-5 text-slate-800 stroke-[2.2] group-hover:text-emerald-700 transition-colors"></i>
                    <span class="text-lg sm:text-xl font-black tracking-tight text-slate-900">šodiena</span>
                </a>

                <!-- Header Search Form (like pasakumiii.lv) -->
                <div class="flex-1 max-w-xs sm:max-w-sm md:max-w-md min-w-0">
                    <form 
                        id="filter-form"
                        action="{{ \App\Services\LocaleService::url(app()->getLocale(), route('events.index')) }}"
                        method="GET"
                        @if(request()->routeIs('events.index') || request()->routeIs('localized.events.index'))
                            hx-get="{{ \App\Services\LocaleService::url(app()->getLocale(), route('events.index')) }}" 
                            hx-target="#events-container" 
                            hx-trigger="submit, keyup changed delay:350ms from:#search-input"
                            hx-indicator="#header-loading-spinner"
                            hx-push-url="true"
                        @endif
                        class="relative flex items-center w-full">
                        
                        <div class="absolute left-2.5 text-slate-400 pointer-events-none flex items-center">
                            <i data-lucide="search" class="w-3.5 h-3.5"></i>
                        </div>

                        <input 
                            type="text" 
                            id="search-input"
                            name="search" 
                            aria-label="{{ __('Search placeholder') }}"
                            value="{{ request('search') }}"
                            placeholder="{{ __('Search events...') }}"
                            class="w-full bg-slate-50 hover:bg-slate-100/70 focus:bg-white border border-slate-200/90 focus:border-slate-400 focus:ring-1 focus:ring-slate-400 rounded-md py-1.5 pl-8 pr-7 text-xs sm:text-[13px] text-slate-900 placeholder:text-slate-400 focus:outline-none font-medium transition-all"
                            autocomplete="off">

                        <!-- HTMX Loading Indicator -->
                        <div id="header-loading-spinner" class="htmx-indicator absolute right-6 text-slate-400" aria-hidden="true">
                            <i data-lucide="loader-2" class="w-3.5 h-3.5 animate-spin text-slate-600"></i>
                        </div>

                        <!-- Clear button -->
                        <button 
                            type="button" 
                            id="search-clear-btn"
                            aria-label="Notīrīt meklēšanu"
                            onclick="clearSearchInput()"
                            class="absolute right-2 text-slate-400 hover:text-slate-700 p-0.5 rounded transition-colors {{ request('search') ? 'inline-flex' : 'hidden' }}">
                            <i data-lucide="x" class="w-3.5 h-3.5"></i>
                        </button>

                        <!-- Hidden inputs for active state preservation -->
                        <input type="hidden" name="category" id="hidden-category" value="{{ request('category', 'all') }}">
                        <input type="hidden" name="period" id="hidden-period" value="{{ request('period', 'all') }}">
                        <input type="hidden" name="date" id="hidden-date" value="{{ request('date', '') }}">
                        <input type="hidden" name="city" id="hidden-city" value="{{ request('city', 'all') }}">
                        <input type="hidden" name="price" id="hidden-price" value="{{ request('price', 'all') }}">
                    </form>
                </div>

                <!-- Navigation & User Menu (Same taste and style as Categories) -->
                <div class="flex items-center gap-3.5 sm:gap-6 shrink-0">
                    <nav class="flex items-center gap-3.5 sm:gap-5" aria-label="Galvenā navigācija">
                        <a href="{{ \App\Services\LocaleService::url(app()->getLocale(), route('events.index')) }}" aria-label="{{ __('Events') }}" class="text-xs sm:text-[13px] font-medium transition-colors {{ request()->routeIs('events.index') || request()->routeIs('localized.events.index') ? 'text-slate-900 font-bold' : 'text-slate-600 hover:text-slate-900' }}">
                            <span>{{ __('Events') }}</span>
                        </a>

                        <a href="{{ route('events.create') }}" aria-label="{{ __('Add Event') }}" class="text-xs sm:text-[13px] font-medium text-slate-600 hover:text-slate-900 transition-colors">
                            <span>{{ __('Add Event') }}</span>
                        </a>

                        @auth
                            <!-- My Events Link -->
                            <a href="{{ route('events.my') }}" aria-label="{{ __('My Events') }}" class="hidden sm:inline-flex text-xs sm:text-[13px] font-medium transition-colors {{ request()->routeIs('events.my') ? 'text-slate-900 font-bold' : 'text-slate-600 hover:text-slate-900' }}" title="{{ __('My Events') }}">
                                <span>{{ __('My Events') }}</span>
                            </a>

                            @if(auth()->user()->isAdmin())
                                <!-- Admin Link -->
                                <a href="{{ route('admin.events.index') }}" aria-label="Admin panelis" class="text-xs sm:text-[13px] font-medium text-slate-500 hover:text-slate-900 transition-colors" title="Admin Panelis">
                                    <span>Admin</span>
                                </a>
                            @endif
                        @endauth
                    </nav>

                    <!-- Language Switcher -->
                    <x-language-switcher />

                    <!-- Auth Section -->
                    <div class="flex items-center gap-2.5 sm:gap-3 border-l border-slate-200 pl-2.5 sm:pl-3 shrink-0">
                        @guest
                            <a href="{{ route('login') }}" aria-label="{{ __('Sign In') }}" class="text-xs sm:text-[13px] font-medium text-slate-600 hover:text-slate-900 transition-colors whitespace-nowrap">
                                {{ __('Sign In') }}
                            </a>
                            <a href="{{ route('register') }}" aria-label="{{ __('Register') }}" class="hidden sm:inline-flex text-xs sm:text-[13px] font-medium text-slate-600 hover:text-slate-900 transition-colors whitespace-nowrap" title="{{ __('Register') }}">
                                <span>{{ __('Register') }}</span>
                            </a>
                        @else
                            <div class="flex items-center gap-2 shrink-0">
                                <a href="{{ route('events.my') }}" class="flex items-center gap-1.5 text-xs sm:text-[13px] font-medium text-slate-700 hover:text-slate-900 transition-colors" title="{{ __('My Events') }}">
                                    <span class="w-6 h-6 rounded-full bg-slate-100 text-slate-700 text-[11px] font-bold border border-slate-200 flex items-center justify-center">
                                        {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
                                    </span>
                                    <span class="hidden md:inline">{{ auth()->user()->name }}</span>
                                </a>

                                <form action="{{ route('logout') }}" method="POST" class="inline">
                                    @csrf
                                    <button 
                                        type="submit" 
                                        aria-label="{{ __('Sign Out') }}"
                                        title="{{ __('Sign Out') }}"
                                        class="p-1 rounded text-slate-400 hover:text-slate-700 transition-colors cursor-pointer">
                                        <i data-lucide="log-out" class="w-3.5 h-3.5" aria-hidden="true"></i>
                                    </button>
                                </form>
                            </div>
                        @endguest
                    </div>
                </div>
            </div>
        </div>
    </header>

<!-- Flash Messages -->
@if(session('success'))
<div class="{{ (request()->routeIs('events.index') || request()->routeIs('localized.events.index')) ? 'w-full px-3.5 sm:px-6 lg:px-8 xl:px-10 2xl:px-12' : 'max-w-6xl xl:max-w-7xl px-4 sm:px-6 lg:px-8' }} mx-auto pt-4">
    <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-900 text-xs sm:text-sm font-semibold flex items-center justify-between shadow-xs animate-in fade-in slide-in-from-top-2 duration-300" role="alert">
        <div class="flex items-center gap-2.5">
            <i data-lucide="check-circle-2" class="w-5 h-5 text-emerald-600 shrink-0" aria-hidden="true"></i>
            <span>{{ session('success') }}</span>
        </div>
        <button type="button" aria-label="Aizvērt paziņojumu" onclick="this.parentElement.remove()" class="text-emerald-600 hover:text-emerald-800 cursor-pointer">
            <i data-lucide="x" class="w-4 h-4" aria-hidden="true"></i>
        </button>
    </div>
</div>
@endif

@if(session('error'))
<div class="{{ (request()->routeIs('events.index') || request()->routeIs('localized.events.index')) ? 'w-full px-3.5 sm:px-6 lg:px-8 xl:px-10 2xl:px-12' : 'max-w-6xl xl:max-w-7xl px-4 sm:px-6 lg:px-8' }} mx-auto pt-4">
    <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-900 text-xs sm:text-sm font-semibold flex items-center justify-between shadow-xs animate-in fade-in slide-in-from-top-2 duration-300" role="alert">
        <div class="flex items-center gap-2.5">
            <i data-lucide="alert-triangle" class="w-5 h-5 text-rose-600 shrink-0" aria-hidden="true"></i>
            <span>{{ session('error') }}</span>
        </div>
        <button type="button" aria-label="Aizvērt kļūdas paziņojumu" onclick="this.parentElement.remove()" class="text-rose-600 hover:text-rose-800 cursor-pointer">
            <i data-lucide="x" class="w-4 h-4" aria-hidden="true"></i>
        </button>
    </div>
</div>
@endif


    <!-- Main Content -->
    <main class="flex-grow">
        @yield('content')
    </main>

    <!-- Footer -->
    <footer class="bg-white border-t border-slate-200 mt-20">
        <div class="{{ (request()->routeIs('events.index') || request()->routeIs('localized.events.index')) ? 'w-full px-3.5 sm:px-6 lg:px-8 xl:px-10 2xl:px-12' : 'max-w-6xl xl:max-w-7xl px-4 sm:px-6 lg:px-8' }} mx-auto py-10">
            <div class="flex flex-col md:flex-row items-center justify-between gap-6">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-700 flex items-center justify-center border border-emerald-200">
                        <i data-lucide="compass" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <p class="text-sm font-bold text-slate-900">Šodiena — {{ __('Disclaimer') }}</p>
                        <p class="text-xs text-slate-500">{{ __('Discover future events') }}</p>
                    </div>
                </div>
                
                <div class="flex items-center gap-6 text-xs text-slate-500 font-medium">
                    <span class="flex items-center gap-1.5 text-emerald-700 font-semibold bg-emerald-50 px-2.5 py-1 rounded-full border border-emerald-200/60">
                        <span class="w-2 h-2 rounded-full bg-emerald-600 animate-pulse"></span>
                        Tiešraides sinhronizācija
                    </span>
                    <span>&copy; {{ date('Y') }} Šodiena.</span>
                </div>
            </div>
        </div>
    </footer>

    @if(config('services.google.analytics_id'))
        <!-- Deferred Google tag (gtag.js) for optimal Core Web Vitals & LCP -->
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', '{{ config('services.google.analytics_id') }}');

            (function() {
                var loaded = false;
                function loadGtag() {
                    if (loaded) return;
                    loaded = true;
                    var script = document.createElement('script');
                    script.async = true;
                    script.src = 'https://www.googletagmanager.com/gtag/js?id={{ config('services.google.analytics_id') }}';
                    document.head.appendChild(script);
                }

                var events = ['pointerdown', 'touchstart', 'scroll', 'keydown', 'mousemove'];
                function triggerAndClean() {
                    loadGtag();
                    events.forEach(function(e) {
                        window.removeEventListener(e, triggerAndClean, { passive: true });
                    });
                }
                events.forEach(function(e) {
                    window.addEventListener(e, triggerAndClean, { once: true, passive: true });
                });

                if ('requestIdleCallback' in window) {
                    window.requestIdleCallback(loadGtag, { timeout: 4000 });
                } else {
                    setTimeout(loadGtag, 4000);
                }
            })();
        </script>
    @endif

    <script>
        function clearSearchInput() {
            const input = document.getElementById('search-input');
            const clearBtn = document.getElementById('search-clear-btn');
            if (input) {
                input.value = '';
                if (clearBtn) clearBtn.classList.add('hidden');
                const form = document.getElementById('filter-form');
                if (form) {
                    if (typeof htmx !== 'undefined' && form.getAttribute('hx-get')) {
                        htmx.trigger(form, 'submit');
                    } else {
                        form.submit();
                    }
                }
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('search-input');
            const clearBtn = document.getElementById('search-clear-btn');
            if (searchInput && clearBtn) {
                searchInput.addEventListener('input', function() {
                    if (this.value.trim().length > 0) {
                        clearBtn.classList.remove('hidden');
                    } else {
                        clearBtn.classList.add('hidden');
                    }
                });
            }
        });
    </script>

    @stack('scripts')
</body>
</html>

