<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="h-full bg-slate-50 text-slate-900 overflow-x-hidden">
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
<body class="min-h-full flex flex-col bg-slate-50 text-slate-900 antialiased selection:bg-emerald-500 selection:text-white overflow-x-hidden w-full max-w-full relative">
    <!-- Header -->
    <header class="sticky top-0 z-50 bg-white/90 backdrop-blur-md border-b border-slate-200/80 shadow-xs w-full overflow-hidden">
        <div class="max-w-7xl xl:max-w-[1400px] 2xl:max-w-[1600px] mx-auto px-3 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-14 sm:h-20 gap-2">
                <!-- Logo -->
                <a href="{{ route('events.index') }}" class="flex items-center gap-2 sm:gap-3 group shrink-0 min-w-0">
                    <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-xl bg-gradient-to-tr from-emerald-600 to-teal-500 flex items-center justify-center text-white shadow-md shadow-emerald-600/20 group-hover:scale-105 transition-transform duration-200 shrink-0">
                        <i data-lucide="compass" class="w-5 h-5 sm:w-6 sm:h-6 stroke-[2.5]"></i>
                    </div>
                    <div class="min-w-0">
                        <span class="text-base sm:text-xl font-extrabold tracking-tight text-slate-900 flex items-center gap-1.5">
                            Šodiena
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] sm:text-[10px] font-extrabold bg-emerald-100 text-emerald-800 border border-emerald-200">LATVIJA</span>
                        </span>
                        <p class="text-[11px] text-slate-500 font-medium hidden md:block">{{ __('Discover future events') }}</p>
                    </div>
                </a>

                <!-- Navigation & User Menu -->
                <div class="flex items-center gap-1.5 sm:gap-3 shrink-0">
                    <nav class="flex items-center gap-1 sm:gap-2">
                        <a href="{{ route('events.index') }}" class="hidden md:inline-flex px-3 py-1.5 sm:px-3.5 sm:py-2 rounded-xl text-xs sm:text-sm font-bold transition-all {{ request()->routeIs('events.index') ? 'text-emerald-700 bg-emerald-50 border border-emerald-200/80' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100' }}">
                            <span class="flex items-center gap-1.5">
                                <i data-lucide="calendar" class="w-4 h-4 text-emerald-600"></i>
                                <span>{{ __('Events') }}</span>
                            </span>
                        </a>

                        @auth
                            @if(auth()->user()->isAdmin())
                                <!-- Admin: Events Table -->
                                <a href="{{ route('admin.events.index') }}" class="px-2 py-1 sm:px-3 sm:py-1.5 rounded-lg sm:rounded-xl text-xs sm:text-sm font-bold transition-all text-purple-700 bg-purple-50 border border-purple-200 hover:bg-purple-100" title="Admin Panelis">
                                    <span class="flex items-center gap-1">
                                        <i data-lucide="shield-check" class="w-3.5 h-3.5 text-purple-600"></i>
                                        <span class="hidden sm:inline">Admin</span>
                                    </span>
                                </a>
                            @endif
                        @endauth
                    </nav>

                    <!-- Language Selector (LV / EN / RU) -->
                    <div class="flex items-center gap-0.5 bg-slate-100 p-0.5 sm:p-1 rounded-xl border border-slate-200/90 text-[11px] sm:text-xs font-bold shadow-xs">
                        <a href="{{ route('locale.switch', 'lv') }}" class="px-1.5 py-0.5 sm:px-2.5 sm:py-1 rounded-lg transition-all {{ app()->getLocale() === 'lv' ? 'bg-white text-emerald-700 shadow-xs font-extrabold' : 'text-slate-500 hover:text-slate-900' }}">LV</a>
                        <a href="{{ route('locale.switch', 'en') }}" class="px-1.5 py-0.5 sm:px-2.5 sm:py-1 rounded-lg transition-all {{ app()->getLocale() === 'en' ? 'bg-white text-emerald-700 shadow-xs font-extrabold' : 'text-slate-500 hover:text-slate-900' }}">EN</a>
                        <a href="{{ route('locale.switch', 'ru') }}" class="px-1.5 py-0.5 sm:px-2.5 sm:py-1 rounded-lg transition-all {{ app()->getLocale() === 'ru' ? 'bg-white text-emerald-700 shadow-xs font-extrabold' : 'text-slate-500 hover:text-slate-900' }}">RU</a>
                    </div>

                    <!-- Auth Section -->
                    <div class="flex items-center gap-1 sm:gap-2 border-l border-slate-200 pl-1.5 sm:pl-3">
                        @guest
                            <a href="{{ route('login') }}" class="px-2 py-1 sm:px-3 sm:py-1.5 rounded-lg text-xs sm:text-sm font-bold text-slate-700 hover:text-slate-900 hover:bg-slate-100 transition-all whitespace-nowrap">
                                {{ __('Sign In') }}
                            </a>
                            <a href="{{ route('register') }}" class="inline-flex items-center gap-1 px-2.5 py-1 sm:px-3.5 sm:py-1.5 rounded-lg sm:rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-xs sm:text-sm font-extrabold transition-all shadow-xs whitespace-nowrap shrink-0">
                                <i data-lucide="user-plus" class="w-3.5 h-3.5 hidden sm:inline"></i>
                                <span>{{ __('Register') }}</span>
                            </a>
                        @else
                            <div class="flex items-center gap-1.5 sm:gap-3">
                                <div class="flex items-center gap-1.5 sm:gap-2">
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
                                        title="{{ __('Sign Out') }}"
                                        class="p-1.5 sm:p-2 rounded-xl text-slate-500 hover:text-rose-600 hover:bg-rose-50 border border-transparent hover:border-rose-200 transition-all">
                                        <i data-lucide="log-out" class="w-4 h-4"></i>
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
            <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-900 text-xs sm:text-sm font-semibold flex items-center justify-between shadow-xs animate-in fade-in slide-in-from-top-2 duration-300">
                <div class="flex items-center gap-2.5">
                    <i data-lucide="check-circle-2" class="w-5 h-5 text-emerald-600 shrink-0"></i>
                    <span>{{ session('success') }}</span>
                </div>
                <button type="button" onclick="this.parentElement.remove()" class="text-emerald-600 hover:text-emerald-800">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>
        </div>
    @endif

    @if(session('error'))
        <div class="max-w-7xl xl:max-w-[1400px] 2xl:max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8 pt-4">
            <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-900 text-xs sm:text-sm font-semibold flex items-center justify-between shadow-xs animate-in fade-in slide-in-from-top-2 duration-300">
                <div class="flex items-center gap-2.5">
                    <i data-lucide="alert-triangle" class="w-5 h-5 text-rose-600 shrink-0"></i>
                    <span>{{ session('error') }}</span>
                </div>
                <button type="button" onclick="this.parentElement.remove()" class="text-rose-600 hover:text-rose-800">
                    <i data-lucide="x" class="w-4 h-4"></i>
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

    @stack('scripts')
</body>
</html>
