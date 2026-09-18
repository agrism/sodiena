<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="h-full bg-slate-100 overflow-x-hidden">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Šodiena.lv :: Administrācijas panelis')</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body hx-headers='{"X-CSRF-TOKEN": "{{ csrf_token() }}"}' class="min-h-full bg-slate-100 text-slate-900 antialiased font-sans flex flex-col overflow-x-hidden w-full max-w-full relative">

<div class="eds-app-wrapper flex min-h-screen">
    
    <!-- Left Navigation Sidebar (Closed/Offcanvas by default, opens on toggle) -->
    <aside id="adminLeftSidebar" class="eds-sidebar w-72 min-w-[18rem] max-w-[18rem] bg-white border-r border-slate-200 flex flex-col fixed top-0 bottom-0 left-0 h-screen z-50 transform -translate-x-full transition-transform duration-200 ease-in-out shadow-2xl">
        
        <!-- Brand Header with Close Button -->
        <div class="eds-brand-header p-4 border-b border-slate-200 bg-slate-50/50 flex items-center justify-between">
            <a href="{{ route('admin.events.index') }}" class="eds-brand-link flex items-center gap-2 group">
                <div class="eds-logo-container flex items-center gap-2">
                    <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-[#002855] to-[#0284c7] flex items-center justify-center text-white shadow-md shadow-blue-950/20 group-hover:scale-105 transition-transform duration-150">
                        <i data-lucide="compass" class="w-4.5 h-4.5 stroke-[2.5]"></i>
                    </div>
                    <div class="eds-logo-text leading-none text-left">
                        <span class="text-sm font-black text-[#002855] tracking-tight">ŠODIENA</span><span class="text-sm font-black text-[#0284c7]">.LV</span>
                    </div>
                </div>
            </a>
            <button type="button" 
                    onclick="toggleAdminSidebar()" 
                    class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-200/60 transition-colors cursor-pointer" 
                    title="Aizvērt izvēlni">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>

        <!-- Sidebar Navigation Menu -->
        <div class="flex-1 overflow-y-auto py-2">
            <div class="px-4 py-1.5 text-[10px] font-extrabold uppercase tracking-wider text-slate-400">
                Pārskats & Dati
            </div>
            <ul class="eds-sidebar-menu space-y-0.5 px-2">
                
                <!-- Events Grid Table -->
                <li>
                    <a href="{{ route('admin.events.index') }}" 
                       class="eds-menu-link flex items-center justify-between px-3 py-2.5 rounded-xl text-xs font-bold transition-all {{ request()->routeIs('admin.events.index') ? 'bg-[#002855] text-white shadow-sm' : 'text-slate-700 hover:bg-slate-100 hover:text-[#002855]' }}">
                        <div class="flex items-center gap-2.5">
                            <i data-lucide="table-2" class="w-4 h-4 {{ request()->routeIs('admin.events.index') ? 'text-cyan-300' : 'text-slate-500' }}"></i>
                            <span>Pasākumu tabula</span>
                        </div>
                    </a>
                </li>

                <!-- Unpublished Events & Pricing Review -->
                <li>
                    <a href="{{ route('admin.events.unpublished') }}" 
                       class="eds-menu-link flex items-center justify-between px-3 py-2.5 rounded-xl text-xs font-bold transition-all {{ request()->routeIs('admin.events.unpublished*') ? 'bg-[#002855] text-white shadow-sm' : 'text-slate-700 hover:bg-slate-100 hover:text-[#002855]' }}">
                        <div class="flex items-center gap-2.5">
                            <i data-lucide="shield-alert" class="w-4 h-4 {{ request()->routeIs('admin.events.unpublished*') ? 'text-amber-400' : 'text-amber-500' }}"></i>
                            <span>Nepublicētie & Cenas</span>
                        </div>
                        @php
                            $unpubBadgeCount = \App\Models\Event::where(function ($q) {
                                $q->whereNull('published_at')->orWhere('published_at', '>', now())->orWhere('status', '!=', 'published');
                            })->count();
                        @endphp
                        @if($unpubBadgeCount > 0)
                            <span class="inline-flex items-center justify-center px-2 py-0.5 text-[10px] font-extrabold rounded-full {{ request()->routeIs('admin.events.unpublished*') ? 'bg-amber-400 text-slate-900' : 'bg-amber-100 text-amber-800' }}">
                                {{ $unpubBadgeCount }}
                            </span>
                        @endif
                    </a>
                </li>

                <!-- Users & Roles -->
                <li>
                    <a href="{{ route('admin.users.index') }}" 
                       class="eds-menu-link flex items-center justify-between px-3 py-2.5 rounded-xl text-xs font-bold transition-all {{ request()->routeIs('admin.users.*') ? 'bg-[#002855] text-white shadow-sm' : 'text-slate-700 hover:bg-slate-100 hover:text-[#002855]' }}">
                        <div class="flex items-center gap-2.5">
                            <i data-lucide="users" class="w-4 h-4 {{ request()->routeIs('admin.users.*') ? 'text-cyan-300' : 'text-slate-500' }}"></i>
                            <span>Lietotāji un lomas</span>
                        </div>
                    </a>
                </li>

                <!-- Sources & Robots -->
                <li>
                    <a href="{{ route('events.sources') }}" 
                       class="eds-menu-link flex items-center justify-between px-3 py-2.5 rounded-xl text-xs font-bold transition-all {{ request()->routeIs('events.sources') ? 'bg-[#002855] text-white shadow-sm' : 'text-slate-700 hover:bg-slate-100 hover:text-[#002855]' }}">
                        <div class="flex items-center gap-2.5">
                            <i data-lucide="bot" class="w-4 h-4 {{ request()->routeIs('events.sources') ? 'text-cyan-300' : 'text-slate-500' }}"></i>
                            <span>Avoti un roboti</span>
                        </div>
                    </a>
                </li>

            </ul>

            <div class="my-3 border-t border-slate-200 mx-3"></div>

            <div class="px-4 py-1.5 text-[10px] font-extrabold uppercase tracking-wider text-slate-400">
                Publiskā vietne
            </div>
            <ul class="eds-sidebar-menu space-y-0.5 px-2">
                <li>
                    <a href="{{ route('events.index') }}" 
                       class="eds-menu-link flex items-center justify-between px-3 py-2.5 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-100 hover:text-emerald-700 transition-all">
                        <div class="flex items-center gap-2.5">
                            <i data-lucide="calendar" class="w-4 h-4 text-emerald-600"></i>
                            <span>Pasākumu kalendārs</span>
                        </div>
                        <i data-lucide="external-link" class="w-3.5 h-3.5 text-slate-400"></i>
                    </a>
                </li>
            </ul>
        </div>

        <!-- Sidebar Footer -->
        <div class="eds-sidebar-footer p-3 border-t border-slate-200 bg-slate-50/50">
            <form action="{{ route('logout') }}" method="POST" class="w-full">
                @csrf
                <button type="submit" 
                        class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-xl text-xs font-bold text-rose-600 hover:bg-rose-50 hover:text-rose-700 border border-rose-200/60 transition-all">
                    <i data-lucide="log-out" class="w-4 h-4"></i>
                    <span>Iziet no sistēmas</span>
                </button>
            </form>
        </div>
    </aside>

    <!-- Sidebar Backdrop -->
    <div id="adminSidebarBackdrop" 
         class="fixed inset-0 bg-slate-950/50 z-40 hidden backdrop-blur-xs transition-opacity" 
         onclick="toggleAdminSidebar()"></div>

    <!-- Main Layout Container -->
    <div class="eds-main-layout flex-1 flex flex-col min-w-0 min-h-screen">
        
        <!-- Admin Top Navigation Header -->
        <header class="eds-topbar sticky top-0 z-30 bg-[#002855] text-white px-4 sm:px-6 py-2.5 min-h-[56px] flex items-center justify-between shadow-md border-b border-blue-950/40">
            
            <!-- Left: Toggle & Page Title -->
            <div class="flex items-center gap-3 min-w-0">
                <button type="button" 
                        onclick="toggleAdminSidebar()" 
                        class="p-1.5 rounded-lg text-white hover:bg-white/10 transition-colors flex items-center gap-1.5 cursor-pointer border border-white/10" 
                        title="Atvērt navigācijas izvēlni">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                    <span class="text-xs font-bold hidden sm:inline">Izvēlne</span>
                </button>
                
                <h1 class="eds-topbar-title text-xs sm:text-sm md:text-base font-extrabold uppercase tracking-wide text-white truncate">
                    @yield('title_topbar', 'ŠODIENA.LV :: ADMINISTRĀCIJAS PANELIS')
                </h1>
            </div>

            <!-- Right Controls: Portal link, Locales, User Offcanvas Trigger -->
            <div class="eds-topbar-controls flex items-center gap-2 sm:gap-3 shrink-0">
                
                <!-- Client Portal Button -->
                <a href="{{ route('events.index') }}" 
                   class="eds-topbar-btn inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-white text-xs font-bold transition-all"
                   title="Pāriet uz publisko portālu">
                    <i data-lucide="arrow-left" class="w-4 h-4"></i>
                    <span class="hidden md:inline">Publiskais portāls</span>
                </a>

                <!-- Language Switcher -->
                <div class="flex items-center gap-0.5 bg-black/20 p-0.5 rounded-lg text-[11px] font-extrabold">
                    <a href="{{ route('locale.switch', 'lv') }}" class="px-2 py-0.5 rounded transition-all {{ app()->getLocale() === 'lv' ? 'bg-white text-[#002855] shadow-xs' : 'text-white/70 hover:text-white' }}">LV</a>
                    <a href="{{ route('locale.switch', 'en') }}" class="px-2 py-0.5 rounded transition-all {{ app()->getLocale() === 'en' ? 'bg-white text-[#002855] shadow-xs' : 'text-white/70 hover:text-white' }}">EN</a>
                    <a href="{{ route('locale.switch', 'ru') }}" class="px-2 py-0.5 rounded transition-all {{ app()->getLocale() === 'ru' ? 'bg-white text-[#002855] shadow-xs' : 'text-white/70 hover:text-white' }}">RU</a>
                </div>

                <!-- User Profile Trigger Button (Opens Right Sidebar) -->
                @auth
                    <button type="button" 
                            onclick="toggleUserSidebar()" 
                            class="eds-topbar-btn eds-user-block inline-flex items-center gap-2 px-2.5 py-1 rounded-lg bg-white/10 hover:bg-white/20 text-white transition-all cursor-pointer border border-white/10"
                            title="{{ auth()->user()->name }}">
                        <div class="w-7 h-7 rounded-lg bg-gradient-to-tr from-amber-400 to-amber-500 text-slate-950 font-black text-xs flex items-center justify-center shadow-xs">
                            {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
                        </div>
                        <div class="eds-topbar-text-group text-left hidden sm:block">
                            <span class="eds-topbar-sublabel block text-[9px] font-extrabold uppercase tracking-wider text-amber-300 leading-none">
                                LIETOTĀJS
                            </span>
                            <span class="eds-topbar-mainval text-xs font-extrabold text-white leading-tight truncate max-w-[130px] block">
                                {{ strtoupper(explode(' ', auth()->user()->name)[0]) }}
                            </span>
                        </div>
                        <i data-lucide="chevron-right" class="w-4 h-4 text-white/70"></i>
                    </button>
                @endauth

            </div>
        </header>

        <!-- Flash Messages -->
        @if(session('success'))
            <div class="px-4 sm:px-6 pt-4">
                <div class="p-3.5 rounded-xl bg-emerald-50 border border-emerald-300 text-emerald-900 text-xs sm:text-sm font-semibold flex items-center justify-between shadow-xs">
                    <div class="flex items-center gap-2.5">
                        <i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-600 shrink-0"></i>
                        <span>{{ session('success') }}</span>
                    </div>
                    <button type="button" onclick="this.parentElement.remove()" class="text-emerald-700 hover:text-emerald-900">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </div>
            </div>
        @endif

        @if(session('error'))
            <div class="px-4 sm:px-6 pt-4">
                <div class="p-3.5 rounded-xl bg-rose-50 border border-rose-300 text-rose-900 text-xs sm:text-sm font-semibold flex items-center justify-between shadow-xs">
                    <div class="flex items-center gap-2.5">
                        <i data-lucide="alert-triangle" class="w-4 h-4 text-rose-600 shrink-0"></i>
                        <span>{{ session('error') }}</span>
                    </div>
                    <button type="button" onclick="this.parentElement.remove()" class="text-rose-700 hover:text-rose-900">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </div>
            </div>
        @endif

        <!-- Main Content Area -->
        <main class="flex-1 p-3 sm:p-5 lg:p-6">
            @yield('content')
        </main>
    </div>

</div>

<!-- Full-Height Right User Sidebar / Offcanvas -->
@auth
<div id="userSidebarOffcanvas" 
     class="fixed top-0 right-0 bottom-0 w-80 sm:w-96 bg-white z-50 shadow-2xl border-l border-slate-200 transform translate-x-full transition-transform duration-300 ease-in-out flex flex-col">
    
    <!-- Offcanvas Header -->
    <div class="bg-gradient-to-br from-[#002855] to-[#001838] text-white p-5 border-b border-blue-950 flex items-start justify-between">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-12 h-12 rounded-2xl bg-white text-[#002855] font-black text-lg flex items-center justify-center shadow-md shrink-0">
                {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
            </div>
            <div class="min-w-0">
                <h3 class="font-extrabold text-white text-base leading-snug truncate">
                    {{ auth()->user()->name }}
                </h3>
                <p class="text-xs text-blue-200/80 truncate">
                    {{ auth()->user()->email }}
                </p>
                <div class="mt-1.5">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-extrabold uppercase bg-amber-400 text-slate-950 shadow-xs">
                        <i data-lucide="shield-check" class="w-3 h-3"></i>
                        Administrators
                    </span>
                </div>
            </div>
        </div>
        <button type="button" 
                onclick="toggleUserSidebar()" 
                class="p-1 rounded-lg text-white/70 hover:text-white hover:bg-white/10 transition-colors"
                title="Aizvērt">
            <i data-lucide="x" class="w-5 h-5"></i>
        </button>
    </div>

    <!-- Offcanvas Body -->
    <div class="flex-1 overflow-y-auto p-4 space-y-5 bg-slate-50">
        
        <!-- Administrator Quick Tools -->
        <div>
            <div class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400 px-1 mb-2">
                Administratora navigācija
            </div>
            <div class="space-y-1.5">
                
                <!-- Events Table -->
                <a href="{{ route('admin.events.index') }}" 
                   onclick="toggleUserSidebar()"
                   class="flex items-center gap-3 p-3 bg-white rounded-xl border border-slate-200 hover:border-[#002855] hover:bg-blue-50/50 transition-all group">
                    <div class="w-9 h-9 rounded-lg bg-blue-50 text-[#002855] flex items-center justify-center group-hover:bg-[#002855] group-hover:text-white transition-colors">
                        <i data-lucide="table-2" class="w-4 h-4"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-xs font-bold text-slate-900 group-hover:text-[#002855]">Pasākumu tabula</div>
                        <div class="text-[11px] text-slate-400">Visi pasākumi tabulas skatā</div>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-slate-400 group-hover:text-[#002855] group-hover:translate-x-0.5 transition-all"></i>
                </a>

                <!-- Users & Roles -->
                <a href="{{ route('admin.users.index') }}" 
                   onclick="toggleUserSidebar()"
                   class="flex items-center gap-3 p-3 bg-white rounded-xl border border-slate-200 hover:border-[#002855] hover:bg-blue-50/50 transition-all group">
                    <div class="w-9 h-9 rounded-lg bg-purple-50 text-purple-700 flex items-center justify-center group-hover:bg-purple-700 group-hover:text-white transition-colors">
                        <i data-lucide="users" class="w-4 h-4"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-xs font-bold text-slate-900 group-hover:text-purple-700">Lietotāju reģistrs</div>
                        <div class="text-[11px] text-slate-400">Lietotāji, tiesības un lomas</div>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-slate-400 group-hover:text-purple-700 group-hover:translate-x-0.5 transition-all"></i>
                </a>

                <!-- Sources & Scrapers -->
                <a href="{{ route('events.sources') }}" 
                   onclick="toggleUserSidebar()"
                   class="flex items-center gap-3 p-3 bg-white rounded-xl border border-slate-200 hover:border-[#002855] hover:bg-blue-50/50 transition-all group">
                    <div class="w-9 h-9 rounded-lg bg-emerald-50 text-emerald-700 flex items-center justify-center group-hover:bg-emerald-700 group-hover:text-white transition-colors">
                        <i data-lucide="bot" class="w-4 h-4"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-xs font-bold text-slate-900 group-hover:text-emerald-700">Avoti un roboti</div>
                        <div class="text-[11px] text-slate-400">Parsēšanas procesi un žurnāli</div>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-slate-400 group-hover:text-emerald-700 group-hover:translate-x-0.5 transition-all"></i>
                </a>

            </div>
        </div>

        <!-- Public Portal Section -->
        <div>
            <div class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400 px-1 mb-2">
                Publiskais portāls
            </div>
            <a href="{{ route('events.index') }}" 
               class="flex items-center gap-3 p-3 bg-white rounded-xl border border-slate-200 hover:border-emerald-500 hover:bg-emerald-50/50 transition-all group">
                <div class="w-9 h-9 rounded-lg bg-emerald-50 text-emerald-700 flex items-center justify-center group-hover:bg-emerald-600 group-hover:text-white transition-colors">
                    <i data-lucide="calendar" class="w-4 h-4"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-xs font-bold text-slate-900 group-hover:text-emerald-700">Publiskais kalendārs</div>
                    <div class="text-[11px] text-slate-400">Atvērt apmeklētāju skatu</div>
                </div>
                <i data-lucide="external-link" class="w-4 h-4 text-slate-400 group-hover:text-emerald-700"></i>
            </a>
        </div>

        <!-- System Details -->
        <div class="p-3 bg-slate-100 rounded-xl border border-slate-200 text-[11px] text-slate-600 space-y-1 font-mono">
            <div class="flex items-center justify-between">
                <span class="text-slate-400">Vide:</span>
                <span class="font-bold text-slate-800">{{ app()->environment() }}</span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-slate-400">Domēns:</span>
                <span class="font-bold text-slate-800">{{ request()->getHost() }}</span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-slate-400">Valoda:</span>
                <span class="font-bold uppercase text-slate-800">{{ app()->getLocale() }}</span>
            </div>
        </div>

    </div>

    <!-- Offcanvas Footer: Logout -->
    <div class="p-4 border-t border-slate-200 bg-white">
        <form action="{{ route('logout') }}" method="POST">
            @csrf
            <button type="submit" 
                    class="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-extrabold transition-all shadow-md shadow-rose-600/20">
                <i data-lucide="log-out" class="w-4 h-4"></i>
                <span>Izrakstīties no konta</span>
            </button>
        </form>
    </div>

</div>

<!-- Right Sidebar Backdrop -->
<div id="userSidebarBackdrop" 
     class="fixed inset-0 bg-slate-950/50 z-40 hidden backdrop-blur-xs transition-opacity" 
     onclick="toggleUserSidebar()"></div>
@endauth

<!-- Scripts for Sidebars -->
<script>
    function toggleAdminSidebar() {
        const sidebar = document.getElementById('adminLeftSidebar');
        const backdrop = document.getElementById('adminSidebarBackdrop');
        if (!sidebar) return;
        
        const isHidden = sidebar.classList.contains('-translate-x-full');
        if (isHidden) {
            sidebar.classList.remove('-translate-x-full');
            backdrop?.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        } else {
            sidebar.classList.add('-translate-x-full');
            backdrop?.classList.add('hidden');
            document.body.style.overflow = '';
        }
    }

    function toggleUserSidebar() {
        const offcanvas = document.getElementById('userSidebarOffcanvas');
        const backdrop = document.getElementById('userSidebarBackdrop');
        if (!offcanvas) return;

        const isHidden = offcanvas.classList.contains('translate-x-full');
        if (isHidden) {
            offcanvas.classList.remove('translate-x-full');
            backdrop?.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        } else {
            offcanvas.classList.add('translate-x-full');
            backdrop?.classList.add('hidden');
            document.body.style.overflow = '';
        }
    }

    // Close sidebars on Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            const offcanvas = document.getElementById('userSidebarOffcanvas');
            if (offcanvas && !offcanvas.classList.contains('translate-x-full')) {
                toggleUserSidebar();
            }
            const leftSidebar = document.getElementById('adminLeftSidebar');
            if (leftSidebar && !leftSidebar.classList.contains('-translate-x-full')) {
                toggleAdminSidebar();
            }
        }
    });
</script>

@stack('scripts')
</body>
</html>
