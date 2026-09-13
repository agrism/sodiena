<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="h-full bg-slate-50 text-slate-900">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Šodiena — ' . __('Find Events'))</title>
    <meta name="description" content="@yield('meta_description', __('Discover future events'))">
    
    <!-- Open Graph / Meta -->
    <meta property="og:title" content="@yield('title', 'Šodiena — ' . __('Find Events'))">
    <meta property="og:description" content="@yield('meta_description', __('Discover future events'))">
    <meta property="og:type" content="website">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
    </style>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="min-h-full flex flex-col bg-slate-50 text-slate-900 antialiased selection:bg-emerald-500 selection:text-white">
    <!-- Header -->
    <header class="sticky top-0 z-50 bg-white/90 backdrop-blur-md border-b border-slate-200/80 shadow-xs">
        <div class="max-w-7xl xl:max-w-[1400px] 2xl:max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16 sm:h-20">
                <!-- Logo -->
                <a href="{{ route('events.index') }}" class="flex items-center gap-3 group">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-emerald-600 to-teal-500 flex items-center justify-center text-white shadow-md shadow-emerald-600/20 group-hover:scale-105 transition-transform duration-200">
                        <i data-lucide="compass" class="w-6 h-6 stroke-[2.5]"></i>
                    </div>
                    <div>
                        <span class="text-xl font-extrabold tracking-tight text-slate-900 flex items-center gap-1.5">
                            Šodiena
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-extrabold bg-emerald-100 text-emerald-800 border border-emerald-200">LATVIJA</span>
                        </span>
                        <p class="text-[11px] text-slate-500 font-medium hidden sm:block">{{ __('Discover future events') }}</p>
                    </div>
                </a>

                <!-- Navigation & Language Selector -->
                <div class="flex items-center gap-2 sm:gap-4">
                    <nav class="flex items-center gap-2 sm:gap-3">
                        <a href="{{ route('events.index') }}" class="px-3.5 py-2 rounded-xl text-sm font-bold transition-all {{ request()->routeIs('events.index') ? 'text-emerald-700 bg-emerald-50 border border-emerald-200/80' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100' }}">
                            <span class="flex items-center gap-1.5">
                                <i data-lucide="calendar" class="w-4 h-4 text-emerald-600"></i>
                                {{ __('Events') }}
                            </span>
                        </a>
                    </nav>

                    <!-- Language Selector (LV / EN / RU) -->
                    <div class="flex items-center gap-0.5 bg-slate-100 p-1 rounded-xl border border-slate-200/90 text-xs font-bold shadow-xs">
                        <a href="{{ route('locale.switch', 'lv') }}" class="px-2.5 py-1 rounded-lg transition-all {{ app()->getLocale() === 'lv' ? 'bg-white text-emerald-700 shadow-xs font-extrabold' : 'text-slate-500 hover:text-slate-900' }}">LV</a>
                        <a href="{{ route('locale.switch', 'en') }}" class="px-2.5 py-1 rounded-lg transition-all {{ app()->getLocale() === 'en' ? 'bg-white text-emerald-700 shadow-xs font-extrabold' : 'text-slate-500 hover:text-slate-900' }}">EN</a>
                        <a href="{{ route('locale.switch', 'ru') }}" class="px-2.5 py-1 rounded-lg transition-all {{ app()->getLocale() === 'ru' ? 'bg-white text-emerald-700 shadow-xs font-extrabold' : 'text-slate-500 hover:text-slate-900' }}">RU</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

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

    @stack('scripts')
</body>
</html>
