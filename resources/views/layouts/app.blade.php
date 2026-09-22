<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="h-full bg-slate-50 text-slate-900 overflow-x-hidden">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Šodiena — ' . __('Find Events'))</title>
    <meta name="description" content="@yield('meta_description', __('Discover future events'))">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="@yield('canonical_url', url()->current())">
    
    <!-- Open Graph / Meta -->
    <meta property="og:site_name" content="Šodiena">
    <meta property="og:url" content="@yield('canonical_url', url()->current())">
    <meta property="og:title" content="@yield('title', 'Šodiena — ' . __('Find Events'))">
    <meta property="og:description" content="@yield('meta_description', __('Discover future events'))">
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:image" content="@yield('meta_image', asset('images/default-event.jpg'))">
    <meta property="og:locale" content="{{ app()->getLocale() === 'lv' ? 'lv_LV' : (app()->getLocale() === 'ru' ? 'ru_RU' : 'en_US') }}">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
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
        <div class="max-w-7xl xl:max-w-[1400px] 2xl:max-w-[1600px] mx-auto px-2.5 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-14 sm:h-20 gap-1.5 sm:gap-4">
        <!-- Logo -->
        <a href="{{ route('events.index') }}" aria-label="Šodiena.lv — {{ __('Find Events') }}" class="flex items-center gap-2 sm:gap-2.5 group shrink-0">
            <div class="w-8 h-8 sm:w-9 sm:h-9 rounded-xl bg-gradient-to-tr from-emerald-600 to-teal-500 flex items-center justify-center text-white shadow-md shadow-emerald-600/20 group-hover:scale-105 transition-transform duration-200 shrink-0">
                <i data-lucide="compass" class="w-4 h-4 sm:w-5 sm:h-5 stroke-[2.5]" aria-hidden="true"></i>
            </div>
            <div class="flex items-center gap-1.5">
                <span class="text-base sm:text-xl font-extrabold tracking-tight text-slate-900">Šodiena</span>
                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] sm:text-[10px] font-extrabold bg-emerald-100 text-emerald-800 border border-emerald-200">LATVIJA</span>
            </div>
        </a>

        <!-- Navigation & User Menu -->
        <div class="flex items-center gap-1 sm:gap-3 shrink-0">
            <nav class="flex items-center gap-1 sm:gap-2" aria-label="Galvenā navigācija">
                <a href="{{ route('events.index') }}" aria-label="{{ __('Events') }}" class="hidden md:inline-flex px-3 py-1.5 sm:px-3.5 sm:py-2 rounded-xl text-xs sm:text-sm font-bold transition-all {{ request()->routeIs('events.index') ? 'text-emerald-700 bg-emerald-50 border border-emerald-200/80' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100' }}">
                    <span class="flex items-center gap-1.5">
                        <i data-lucide="calendar" class="w-4 h-4 text-emerald-600" aria-hidden="true"></i>
                        <span>{{ __('Events') }}</span>
                    </span>
                </a>

                @auth
                    @if(auth()->user()->isAdmin())
                        <!-- Admin: Events Table -->
                        <a href="{{ route('admin.events.index') }}" aria-label="Admin panelis" class="px-2 py-1 sm:px-3 sm:py-1.5 rounded-lg sm:rounded-xl text-xs sm:text-sm font-bold transition-all text-purple-700 bg-purple-50 border border-purple-200 hover:bg-purple-100" title="Admin Panelis">
                            <span class="flex items-center gap-1">
                                <i data-lucide="shield-check" class="w-3.5 h-3.5 text-purple-600" aria-hidden="true"></i>
                                <span class="hidden sm:inline">Admin</span>
                            </span>
                        </a>
                    @endif
                @endauth
            </nav>

            <!-- Language Selector (LV / EN / RU) -->
            <div class="flex items-center gap-0.5 bg-slate-100 p-0.5 sm:p-1 rounded-xl border border-slate-200/90 text-[10px] sm:text-xs font-bold shadow-xs shrink-0" role="group" aria-label="Valodas izvēle">
                <a href="{{ route('locale.switch', 'lv') }}" aria-label="Latviešu valoda" class="px-1.5 py-0.5 sm:px-2.5 sm:py-1 rounded-lg transition-all {{ app()->getLocale() === 'lv' ? 'bg-white text-emerald-700 shadow-xs font-extrabold' : 'text-slate-500 hover:text-slate-900' }}">LV</a>
                <a href="{{ route('locale.switch', 'en') }}" aria-label="English language" class="px-1.5 py-0.5 sm:px-2.5 sm:py-1 rounded-lg transition-all {{ app()->getLocale() === 'en' ? 'bg-white text-emerald-700 shadow-xs font-extrabold' : 'text-slate-500 hover:text-slate-900' }}">EN</a>
                <a href="{{ route('locale.switch', 'ru') }}" aria-label="Русский язык" class="px-1.5 py-0.5 sm:px-2.5 sm:py-1 rounded-lg transition-all {{ app()->getLocale() === 'ru' ? 'bg-white text-emerald-700 shadow-xs font-extrabold' : 'text-slate-500 hover:text-slate-900' }}">RU</a>
            </div>

            <!-- Auth Section -->
            <div class="flex items-center gap-1 sm:gap-2 border-l border-slate-200 pl-1 sm:pl-3 shrink-0">
                @guest
                    <a href="{{ route('login') }}" aria-label="{{ __('Sign In') }}" class="px-1.5 py-1 sm:px-3 sm:py-1.5 rounded-lg text-[11px] sm:text-sm font-bold text-slate-700 hover:text-slate-900 hover:bg-slate-100 transition-all whitespace-nowrap">
                        {{ __('Sign In') }}
                    </a>
                    <a href="{{ route('register') }}" aria-label="{{ __('Register') }}" class="inline-flex items-center justify-center gap-1 px-2 py-1 sm:px-3.5 sm:py-1.5 rounded-lg sm:rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-[11px] sm:text-sm font-extrabold transition-all shadow-xs whitespace-nowrap shrink-0" title="{{ __('Register') }}">
                        <i data-lucide="user-plus" class="w-3.5 h-3.5" aria-hidden="true"></i>
                        <span class="hidden md:inline">{{ __('Register') }}</span>
                    </a>
                @else
                    <div class="flex items-center gap-1 sm:gap-3 shrink-0">
                        <div class="flex items-center gap-1 sm:gap-2">
                            <div class="w-7 h-7 sm:w-8 sm:h-8 rounded-xl {{ auth()->user()->isAdmin() ? 'bg-purple-100 text-purple-700 border-purple-200' : 'bg-emerald-100 text-emerald-700 border-emerald-200' }} border font-extrabold text-xs flex items-center justify-center shrink-0 shadow-xs">
                                {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
                            </div>
                            <div class="hidden sm:block text-left leading-tight">
                                <p class="text-xs font-bold text-slate-900 truncate max-w-[100px] sm:max-w-[120px]">{{ auth()->user()->name }}</p>
                                <span class="text-[10px] font-extrabold {{ auth()->user()->isAdmin() ? 'text-purple-700' : 'text-emerald-700' }} uppercase tracking-wider">
                                    {{ auth()->user()->localized_role_name }}
                                </span>
                            </div>
                        </div>

                        <form action="{{ route('logout') }}" method="POST" class="inline">
                            @csrf
                            <button 
                                type="submit" 
                                aria-label="{{ __('Sign Out') }}"
                                title="{{ __('Sign Out') }}"
                                class="p-1.5 sm:p-2 rounded-xl text-slate-500 hover:text-rose-600 hover:bg-rose-50 border border-transparent hover:border-rose-200 transition-all cursor-pointer">
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
<div class="max-w-7xl xl:max-w-[1400px] 2xl:max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8 pt-4">
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
<div class="max-w-7xl xl:max-w-[1400px] 2xl:max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8 pt-4">
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
        <div class="max-w-7xl xl:max-w-[1400px] 2xl:max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8 py-10">
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

    @stack('scripts')
</body>
</html>

