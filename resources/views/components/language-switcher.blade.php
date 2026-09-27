@php
    $currentLocale = app()->getLocale();
    $locales = [
        'en' => [
            'name' => 'English',
            'code' => 'EN',
            'label' => 'English language',
        ],
        'lv' => [
            'name' => 'Latviešu',
            'code' => 'LV',
            'label' => 'Latviešu valoda',
        ],
        'ru' => [
            'name' => 'Русский',
            'code' => 'RU',
            'label' => 'Русский язык',
        ],
    ];
    $active = $locales[$currentLocale] ?? $locales['lv'];
    $switcherId = 'lang_switcher_' . uniqid();
@endphp

<div class="relative inline-block text-left" id="{{ $switcherId }}">
    <!-- Trigger Button -->
    <button 
        type="button"
        id="{{ $switcherId }}_btn"
        aria-haspopup="true"
        aria-expanded="false"
        aria-label="{{ __('Language') }}: {{ $active['name'] }}"
        class="inline-flex items-center gap-1.5 sm:gap-2 px-2.5 py-1.5 sm:px-3 sm:py-1.5 rounded-xl bg-white border border-slate-200/90 hover:border-slate-300 text-slate-800 font-bold text-xs sm:text-sm shadow-xs transition-all hover:bg-slate-50/80 cursor-pointer select-none focus:outline-hidden focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500">
        
        <!-- Globe Icon -->
        <i data-lucide="globe" class="w-4 h-4 text-slate-500 stroke-[1.8] shrink-0" aria-hidden="true"></i>

        <!-- Active Flag -->
        @if($currentLocale === 'en')
            <svg class="w-[19px] h-[13px] rounded-[2px] shadow-xs border border-slate-200/80 shrink-0 inline-block overflow-hidden" viewBox="0 0 60 30" aria-hidden="true">
                <clipPath id="uk_clip_btn_{{ $switcherId }}">
                    <path d="M0 0v30h60V0z"/>
                </clipPath>
                <g clip-path="url(#uk_clip_btn_{{ $switcherId }})">
                    <path d="M0 0v30h60V0z" fill="#012169"/>
                    <path d="M0 0l60 30m0-30L0 30" stroke="#fff" stroke-width="6"/>
                    <path d="M0 0l30 15m30 15L30 15m0-15l30 15m-60 15l30-15" stroke="#C8102E" stroke-width="4"/>
                    <path d="M30 0v30M0 15h60" stroke="#fff" stroke-width="10"/>
                    <path d="M30 0v30M0 15h60" stroke="#C8102E" stroke-width="6"/>
                </g>
            </svg>
        @elseif($currentLocale === 'ru')
            <svg class="w-[19px] h-[13px] rounded-[2px] shadow-xs border border-slate-200/80 shrink-0 inline-block overflow-hidden" viewBox="0 0 20 14" fill="none" aria-hidden="true">
                <rect width="20" height="14" fill="#FFFFFF"/>
                <rect y="4.66" width="20" height="4.66" fill="#0052B4"/>
                <rect y="9.33" width="20" height="4.67" fill="#D52B1E"/>
            </svg>
        @else
            <svg class="w-[19px] h-[13px] rounded-[2px] shadow-xs border border-slate-200/80 shrink-0 inline-block overflow-hidden" viewBox="0 0 20 14" fill="none" aria-hidden="true">
                <rect width="20" height="14" fill="#9E1B32"/>
                <rect y="5.6" width="20" height="2.8" fill="#FFFFFF"/>
            </svg>
        @endif

        <!-- Locale Code -->
        <span class="tracking-wide text-xs sm:text-sm font-bold text-slate-800">{{ $active['code'] }}</span>

        <!-- Chevron Down Icon -->
        <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-slate-400 stroke-[2.2] shrink-0 transition-transform duration-200" id="{{ $switcherId }}_arrow" aria-hidden="true"></i>
    </button>

    <!-- Dropdown Menu -->
    <div 
        id="{{ $switcherId }}_menu"
        role="menu"
        aria-orientation="vertical"
        tabindex="-1"
        class="hidden absolute right-0 mt-1.5 w-44 rounded-2xl bg-white border border-slate-200/90 shadow-xl p-1 z-50 animate-in fade-in zoom-in-95 duration-150">
        
        <div class="space-y-0.5">
            @foreach($locales as $code => $locale)
                @php $isSelected = ($currentLocale === $code); @endphp
                <a 
                    href="{{ \App\Services\LocaleService::url($code) }}"
                    role="menuitem"
                    aria-label="{{ $locale['label'] }}"
                    class="flex items-center justify-between px-3 py-2 rounded-xl text-xs sm:text-sm font-semibold transition-colors {{ $isSelected ? 'bg-slate-50/90 text-slate-900' : 'text-slate-700 hover:bg-slate-50 hover:text-slate-900' }}">
                    
                    <div class="flex items-center gap-2.5">
                        @if($code === 'en')
                            <svg class="w-[20px] h-[14px] rounded-[2px] shadow-xs border border-slate-200/80 shrink-0 inline-block overflow-hidden" viewBox="0 0 60 30" aria-hidden="true">
                                <clipPath id="uk_clip_menu_{{ $code }}_{{ $switcherId }}">
                                    <path d="M0 0v30h60V0z"/>
                                </clipPath>
                                <g clip-path="url(#uk_clip_menu_{{ $code }}_{{ $switcherId }})">
                                    <path d="M0 0v30h60V0z" fill="#012169"/>
                                    <path d="M0 0l60 30m0-30L0 30" stroke="#fff" stroke-width="6"/>
                                    <path d="M0 0l30 15m30 15L30 15m0-15l30 15m-60 15l30-15" stroke="#C8102E" stroke-width="4"/>
                                    <path d="M30 0v30M0 15h60" stroke="#fff" stroke-width="10"/>
                                    <path d="M30 0v30M0 15h60" stroke="#C8102E" stroke-width="6"/>
                                </g>
                            </svg>
                        @elseif($code === 'ru')
                            <svg class="w-[20px] h-[14px] rounded-[2px] shadow-xs border border-slate-200/80 shrink-0 inline-block overflow-hidden" viewBox="0 0 20 14" fill="none" aria-hidden="true">
                                <rect width="20" height="14" fill="#FFFFFF"/>
                                <rect y="4.66" width="20" height="4.66" fill="#0052B4"/>
                                <rect y="9.33" width="20" height="4.67" fill="#D52B1E"/>
                            </svg>
                        @else
                            <svg class="w-[20px] h-[14px] rounded-[2px] shadow-xs border border-slate-200/80 shrink-0 inline-block overflow-hidden" viewBox="0 0 20 14" fill="none" aria-hidden="true">
                                <rect width="20" height="14" fill="#9E1B32"/>
                                <rect y="5.6" width="20" height="2.8" fill="#FFFFFF"/>
                            </svg>
                        @endif

                        <span class="truncate">{{ $locale['name'] }}</span>
                    </div>

                    @if($isSelected)
                        <i data-lucide="check" class="w-4 h-4 text-slate-900 stroke-[2.5] shrink-0" aria-hidden="true"></i>
                    @endif
                </a>
            @endforeach
        </div>
    </div>
</div>

<script>
(function() {
    const container = document.getElementById('{{ $switcherId }}');
    if (!container) return;
    const btn = document.getElementById('{{ $switcherId }}_btn');
    const menu = document.getElementById('{{ $switcherId }}_menu');
    const arrow = document.getElementById('{{ $switcherId }}_arrow');

    function toggleMenu(show) {
        const isCurrentlyHidden = menu.classList.contains('hidden');
        const willShow = (typeof show === 'boolean') ? show : isCurrentlyHidden;

        if (willShow) {
            menu.classList.remove('hidden');
            btn.setAttribute('aria-expanded', 'true');
            if (arrow) arrow.classList.add('rotate-180');
        } else {
            menu.classList.add('hidden');
            btn.setAttribute('aria-expanded', 'false');
            if (arrow) arrow.classList.remove('rotate-180');
        }
    }

    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        toggleMenu();
    });

    document.addEventListener('click', function(e) {
        if (!container.contains(e.target)) {
            toggleMenu(false);
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            toggleMenu(false);
        }
    });
})();
</script>
