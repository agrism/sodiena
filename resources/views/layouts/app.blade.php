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
    <header class="sticky top-0 z-50 bg-white/90 backdrop-blur-md border-b border-slate-200/80 shadow-xs w-full">
        <div class="w-full px-3.5 sm:px-6 lg:px-8 xl:px-10 2xl:px-12 mx-auto">
            <div class="flex items-center justify-between h-14 sm:h-16 gap-2 sm:gap-4">
                <!-- Logo -->
                <a href="{{ \App\Services\LocaleService::url(app()->getLocale(), route('events.index')) }}" aria-label="Šodiena.lv — {{ __('Find Events') }}" class="flex items-center gap-2 group shrink-0">
                    <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-emerald-600 to-teal-500 flex items-center justify-center text-white shadow-md shadow-emerald-600/20 group-hover:scale-105 transition-transform duration-200 shrink-0">
                        <i data-lucide="compass" class="w-4 h-4 stroke-[2.5]" aria-hidden="true"></i>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="text-base sm:text-xl font-extrabold tracking-tight text-slate-900">Šodiena</span>
                        <span class="hidden sm:inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-extrabold bg-emerald-100 text-emerald-800 border border-emerald-200">LV</span>
                    </div>
                </a>

                <!-- Header Search Form (like pasakumiii.lv) -->
                <div class="flex-1 max-w-xs sm:max-w-sm md:max-w-md lg:max-w-lg min-w-0 mx-1 sm:mx-3">
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
                        
                        <div class="absolute left-2.5 sm:left-3 text-slate-400 pointer-events-none flex items-center">
                            <i data-lucide="search" class="w-3.5 h-3.5 sm:w-4 sm:h-4"></i>
                        </div>

                        <input 
                            type="text" 
                            id="search-input"
                            name="search" 
                            aria-label="{{ __('Search placeholder') }}"
                            value="{{ request('search') }}"
                            placeholder="{{ __('Search events...') }}"
                            class="w-full bg-slate-100/90 hover:bg-slate-100 focus:bg-white border border-slate-200/90 focus:border-slate-400 focus:ring-2 focus:ring-slate-900/10 rounded-lg py-1.5 sm:py-2 pl-8 sm:pl-9 pr-8 text-xs sm:text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none font-medium transition-all"
                            autocomplete="off">

                        <!-- HTMX Loading Indicator -->
                        <div id="header-loading-spinner" class="htmx-indicator absolute right-7 sm:right-8 text-slate-400" aria-hidden="true">
                            <i data-lucide="loader-2" class="w-3.5 h-3.5 sm:w-4 sm:h-4 animate-spin text-emerald-600"></i>
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

                <!-- Navigation & User Menu -->
                <div class="flex items-center gap-1 sm:gap-2.5 shrink-0">
                    <nav class="flex items-center gap-1 sm:gap-1.5" aria-label="Galvenā navigācija">
                        <a href="{{ \App\Services\LocaleService::url(app()->getLocale(), route('events.index')) }}" aria-label="{{ __('Events') }}" class="hidden lg:inline-flex px-3 py-1.5 rounded-lg text-xs sm:text-sm font-bold transition-all {{ request()->routeIs('events.index') || request()->routeIs('localized.events.index') ? 'text-slate-900 bg-slate-100 font-extrabold' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50' }}">
                            <span>{{ __('Events') }}</span>
                        </a>

                        <!-- Add Event Button -->
                        <a href="{{ route('events.create') }}" aria-label="{{ __('Add Event') }}" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 sm:px-3 sm:py-2 rounded-lg text-xs sm:text-sm font-bold transition-all bg-emerald-600 hover:bg-emerald-700 text-white shadow-xs hover:shadow-md hover:scale-[1.02] active:scale-[0.98]">
                            <i data-lucide="plus" class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-white" aria-hidden="true"></i>
                            <span class="hidden sm:inline">{{ __('Add Event') }}</span>
                        </a>

                        @auth
                            <!-- My Events Link -->
                            <a href="{{ route('events.my') }}" aria-label="{{ __('My Events') }}" class="hidden xl:inline-flex px-2.5 py-1.5 rounded-lg text-xs sm:text-sm font-bold transition-all {{ request()->routeIs('events.my') ? 'text-emerald-700 bg-emerald-50 border border-emerald-200' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100' }}" title="{{ __('My Events') }}">
                                <span class="flex items-center gap-1">
                                    <i data-lucide="sparkles" class="w-3.5 h-3.5 text-amber-500" aria-hidden="true"></i>
                                    <span>{{ __('My Events') }}</span>
                                </span>
                            </a>

                            @if(auth()->user()->isAdmin())
                                <!-- Admin: Events Table -->
                                <a href="{{ route('admin.events.index') }}" aria-label="Admin panelis" class="px-2 py-1 sm:px-2.5 sm:py-1.5 rounded-lg text-xs sm:text-sm font-bold transition-all text-purple-700 bg-purple-50 border border-purple-200 hover:bg-purple-100" title="Admin Panelis">
                                    <span class="flex items-center gap-1">
                                        <i data-lucide="shield-check" class="w-3.5 h-3.5 text-purple-600" aria-hidden="true"></i>
                                        <span class="hidden sm:inline">Admin</span>
                                    </span>
                                </a>
                            @endif
                        @endauth
                    </nav>

                    <!-- Language Switcher Dropdown (Globe + Flag + Code + Chevron) -->
                    <x-language-switcher />

                    <!-- Auth Section -->
                    <div class="flex items-center gap-1 sm:gap-2 border-l border-slate-200 pl-1.5 sm:pl-2.5 shrink-0">
                        @guest
                            <a href="{{ route('login') }}" aria-label="{{ __('Sign In') }}" class="px-2 py-1.5 rounded-lg text-xs sm:text-sm font-bold text-slate-700 hover:text-slate-900 hover:bg-slate-100 transition-all whitespace-nowrap">
                                {{ __('Sign In') }}
                            </a>
                            <a href="{{ route('register') }}" aria-label="{{ __('Register') }}" class="hidden sm:inline-flex items-center justify-center gap-1 px-3 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-xs sm:text-sm font-bold transition-all shadow-xs whitespace-nowrap shrink-0" title="{{ __('Register') }}">
                                <i data-lucide="user-plus" class="w-3.5 h-3.5" aria-hidden="true"></i>
                                <span>{{ __('Register') }}</span>
                            </a>
                        @else
                            <div class="flex items-center gap-1 sm:gap-2.5 shrink-0">
                                <a href="{{ route('events.my') }}" class="flex items-center gap-1 sm:gap-2 group cursor-pointer" title="{{ __('My Events') }}">
                                    <div class="w-7 h-7 sm:w-8 sm:h-8 rounded-lg {{ auth()->user()->isAdmin() ? 'bg-purple-100 text-purple-700 border-purple-200' : 'bg-emerald-100 text-emerald-700 border-emerald-200' }} border font-black text-xs flex items-center justify-center shrink-0 shadow-xs group-hover:scale-105 transition-transform">
                                        {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
                                    </div>
                                    <div class="hidden md:block text-left leading-tight">
                                        <p class="text-xs font-bold text-slate-900 group-hover:text-emerald-700 truncate max-w-[100px] transition-colors">{{ auth()->user()->name }}</p>
                                        <span class="text-[10px] font-extrabold {{ auth()->user()->isAdmin() ? 'text-purple-700' : 'text-emerald-700' }} uppercase tracking-wider">
                                            {{ auth()->user()->localized_role_name }}
                                        </span>
                                    </div>
                                </a>

                                <form action="{{ route('logout') }}" method="POST" class="inline">
                                    @csrf
                                    <button 
                                        type="submit" 
                                        aria-label="{{ __('Sign Out') }}"
                                        title="{{ __('Sign Out') }}"
                                        class="p-1.5 sm:p-2 rounded-lg text-slate-500 hover:text-rose-600 hover:bg-rose-50 border border-transparent hover:border-rose-200 transition-all cursor-pointer">
                                        <i data-lucide="log-out" class="w-4 h-4" aria-hidden="true"></i>
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
<div class="w-full px-3.5 sm:px-6 lg:px-8 xl:px-10 2xl:px-12 mx-auto pt-4">
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
<div class="w-full px-3.5 sm:px-6 lg:px-8 xl:px-10 2xl:px-12 mx-auto pt-4">
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
        <div class="w-full px-3.5 sm:px-6 lg:px-8 xl:px-10 2xl:px-12 mx-auto py-10">
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

