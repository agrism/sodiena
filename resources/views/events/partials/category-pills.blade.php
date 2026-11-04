@php
    $activeCategory = $categorySlug ?? request('category', 'all');
@endphp
<nav class="flex items-center gap-5 sm:gap-7 overflow-x-auto no-scrollbar w-full py-2.5 sm:py-3 text-xs sm:text-[13px]" aria-label="Kategoriju izvēle">
    <!-- All category link -->
    <button 
        type="button"
        data-filter-category="all"
        aria-label="{{ __('All categories') }} ({{ $totalUpcoming }})"
        onclick="applyFilter('category', 'all')"
        class="category-btn shrink-0 inline-flex items-center gap-1.5 transition-colors cursor-pointer py-1 {{ (!$activeCategory || $activeCategory === 'all') ? 'text-slate-900 font-bold border-b-2 border-slate-900 -mb-[1px]' : 'text-slate-600 hover:text-slate-900 font-medium border-b-2 border-transparent' }}">
        <span>{{ __('All categories') }}</span>
    </button>

    @foreach($categories as $cat)
        <button 
            type="button"
            data-filter-category="{{ $cat->slug }}"
            aria-label="{{ $cat->name }}{{ $cat->events_count > 0 ? ' (' . $cat->events_count . ')' : '' }}"
            onclick="applyFilter('category', '{{ $cat->slug }}')"
            class="category-btn shrink-0 inline-flex items-center gap-1.5 transition-colors cursor-pointer py-1 {{ $activeCategory === $cat->slug ? 'text-slate-900 font-bold border-b-2 border-slate-900 -mb-[1px]' : 'text-slate-600 hover:text-slate-900 font-medium border-b-2 border-transparent' }}">
            <span>{{ $cat->name }}</span>
        </button>
    @endforeach
</nav>
