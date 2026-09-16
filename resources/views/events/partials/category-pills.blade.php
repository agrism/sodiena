@php
    $activeCategory = $categorySlug ?? request('category', 'all');
@endphp
<div class="flex items-center gap-2 overflow-x-auto pb-3 pt-1 no-scrollbar w-full">
    <!-- All category pill -->
    <button 
        type="button"
        data-filter-category="all"
        onclick="applyFilter('category', 'all')"
        class="category-btn shrink-0 inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs sm:text-sm font-bold transition-all duration-200 {{ (!$activeCategory || $activeCategory === 'all') ? 'bg-emerald-600 text-white shadow-md shadow-emerald-600/25 scale-105' : 'bg-white text-slate-700 border border-slate-200 hover:border-slate-300 hover:bg-slate-50' }}">
        <i data-lucide="layout-grid" class="w-4 h-4 {{ (!$activeCategory || $activeCategory === 'all') ? 'text-white' : 'text-emerald-600' }}"></i>
        {{ __('All categories') }}
        <span class="cat-badge px-1.5 py-0.5 rounded-full text-[10px] {{ (!$activeCategory || $activeCategory === 'all') ? 'bg-emerald-800/40 text-white' : 'bg-slate-100 text-slate-600' }}">
            {{ $totalUpcoming }}
        </span>
    </button>

    @foreach($categories as $cat)
        <button 
            type="button"
            data-filter-category="{{ $cat->slug }}"
            onclick="applyFilter('category', '{{ $cat->slug }}')"
            class="category-btn shrink-0 inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs sm:text-sm font-bold transition-all duration-200 {{ $activeCategory === $cat->slug ? 'bg-emerald-600 text-white shadow-md shadow-emerald-600/25 scale-105' : 'bg-white text-slate-700 border border-slate-200 hover:border-slate-300 hover:bg-slate-50' }}">
            <i data-lucide="{{ $cat->icon ?: 'tag' }}" class="w-4 h-4 {{ $activeCategory === $cat->slug ? 'text-white' : 'text-emerald-600' }}"></i>
            <span>{{ $cat->name }}</span>
            @if($cat->events_count > 0)
                <span class="cat-badge px-1.5 py-0.5 rounded-full text-[10px] {{ $activeCategory === $cat->slug ? 'bg-emerald-800/40 text-white' : 'bg-slate-100 text-slate-600' }}">
                    {{ $cat->events_count }}
                </span>
            @endif
        </button>
    @endforeach
</div>
