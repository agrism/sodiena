@extends('layouts.app')

@section('title', __('Edit Event') . ': ' . $event->title . ' — Šodiena')

@section('content')
<div class="py-8 sm:py-12 px-3 sm:px-6 lg:px-8 max-w-4xl mx-auto">
    <!-- Breadcrumb -->
    <nav class="flex items-center gap-2 text-xs font-semibold text-slate-500 mb-6" aria-label="Breadcrumb">
        <a href="{{ \App\Services\LocaleService::url(app()->getLocale(), route('events.index')) }}" class="hover:text-emerald-700 transition-colors">{{ __('Events') }}</a>
        <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
        <a href="{{ route('events.my') }}" class="hover:text-emerald-700 transition-colors">{{ __('My Events') }}</a>
        <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
        <span class="text-slate-800 font-bold truncate max-w-xs">{{ $event->title }}</span>
    </nav>

    <!-- Header Banner -->
    <div class="bg-gradient-to-r from-slate-900 via-slate-800 to-emerald-950 rounded-3xl p-6 sm:p-8 text-white shadow-lg mb-8 relative overflow-hidden">
        <div class="relative z-10 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/15 backdrop-blur-md text-emerald-300 text-xs font-bold uppercase tracking-wider mb-3">
                    <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                    <span>{{ __('Edit Event') }}</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight">{{ $event->title }}</h1>
                <p class="mt-1 text-slate-300 text-xs sm:text-sm">
                    {{ __('Update information for this event and save changes.') }}
                </p>
            </div>
            <a href="{{ $event->localized_url }}" target="_blank" class="px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-white font-bold text-xs inline-flex items-center gap-2 self-start sm:self-center transition-colors">
                <i data-lucide="external-link" class="w-4 h-4"></i>
                <span>{{ __('View on site') }}</span>
            </a>
        </div>
    </div>

    <!-- Main Form Card -->
    <div class="bg-white rounded-3xl border border-slate-200/90 shadow-sm p-6 sm:p-10">
        <form action="{{ route('events.update', $event) }}" method="POST" enctype="multipart/form-data" class="space-y-8" id="edit-event-form">
            @csrf
            @method('PUT')

            <!-- 1. PAMATINFORMĀCIJA -->
            <div class="space-y-5">
                <div class="flex items-center gap-2.5 pb-2 border-b border-slate-100">
                    <div class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-sm">1</div>
                    <h2 class="text-lg font-extrabold text-slate-900">{{ __('Basic Information') }}</h2>
                </div>

                <!-- Pasākuma nosaukums -->
                <div>
                    <label for="title" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                        {{ __('Event Title') }} <span class="text-rose-500">*</span>
                    </label>
                    <input 
                        type="text" 
                        name="title" 
                        id="title" 
                        value="{{ old('title', $event->title) }}" 
                        required 
                        class="block w-full px-4 py-3 bg-slate-50 border @error('title') border-rose-400 focus:ring-rose-400 @else border-slate-200 focus:ring-emerald-500 @enderror rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                    @error('title')
                        <p class="mt-1.5 text-xs font-bold text-rose-600 flex items-center gap-1">
                            <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i>
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <!-- Kategorijas -->
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                        {{ __('Categories') }} <span class="text-rose-500">*</span>
                    </label>
                    
                    @php
                        $eventCategoryIds = old('categories', $event->categories->pluck('id')->toArray());
                    @endphp
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5 pt-1">
                        @foreach($categories as $category)
                            @php
                                $isChecked = is_array($eventCategoryIds) && in_array($category->id, $eventCategoryIds);
                            @endphp
                            <label class="relative flex items-center gap-2.5 p-3 rounded-2xl border cursor-pointer select-none transition-all duration-150 {{ $isChecked ? 'border-emerald-500 bg-emerald-50/80 text-emerald-900 ring-2 ring-emerald-500/20' : 'border-slate-200 bg-slate-50/70 hover:bg-slate-100/80 text-slate-700' }} category-pill">
                                <input 
                                    type="checkbox" 
                                    name="categories[]" 
                                    value="{{ $category->id }}" 
                                    class="category-checkbox sr-only"
                                    {{ $isChecked ? 'checked' : '' }}>
                                <div class="w-7 h-7 rounded-lg bg-white shadow-2xs border border-slate-200/80 flex items-center justify-center shrink-0">
                                    <i data-lucide="{{ $category->icon ?: 'tag' }}" class="w-4 h-4 text-emerald-600"></i>
                                </div>
                                <span class="text-xs sm:text-sm font-bold truncate">{{ $category->name }}</span>
                                <div class="ml-auto check-indicator {{ $isChecked ? 'opacity-100' : 'opacity-0' }} transition-opacity text-emerald-600">
                                    <i data-lucide="check" class="w-4 h-4 stroke-[3]"></i>
                                </div>
                            </label>
                        @endforeach
                    </div>
                    @error('categories')
                        <p class="mt-1.5 text-xs font-bold text-rose-600 flex items-center gap-1">
                            <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i>
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <!-- Izklaides veids -->
                <div>
                    <label for="entertainment_type" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                        {{ __('Entertainment Type / Format') }}
                    </label>
                    <select 
                        name="entertainment_type" 
                        id="entertainment_type" 
                        class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                        <option value="">{{ __('-- Choose format (or leave empty) --') }}</option>
                        <option value="concert" {{ old('entertainment_type', $event->entertainment_type) === 'concert' ? 'selected' : '' }}>{{ __('Concert / Live Music') }}</option>
                        <option value="theatre" {{ old('entertainment_type', $event->entertainment_type) === 'theatre' ? 'selected' : '' }}>{{ __('Theatre Performance / Play') }}</option>
                        <option value="cinema" {{ old('entertainment_type', $event->entertainment_type) === 'cinema' ? 'selected' : '' }}>{{ __('Cinema Screening / Movie') }}</option>
                        <option value="festival" {{ old('entertainment_type', $event->entertainment_type) === 'festival' ? 'selected' : '' }}>{{ __('Festival / Celebration') }}</option>
                        <option value="exhibition" {{ old('entertainment_type', $event->entertainment_type) === 'exhibition' ? 'selected' : '' }}>{{ __('Exhibition / Expo') }}</option>
                        <option value="workshop" {{ old('entertainment_type', $event->entertainment_type) === 'workshop' ? 'selected' : '' }}>{{ __('Workshop / Seminar') }}</option>
                        <option value="active" {{ old('entertainment_type', $event->entertainment_type) === 'active' ? 'selected' : '' }}>{{ __('Sports / Active Recreation') }}</option>
                        <option value="family" {{ old('entertainment_type', $event->entertainment_type) === 'family' ? 'selected' : '' }}>{{ __('Family & Children Event') }}</option>
                        <option value="party" {{ old('entertainment_type', $event->entertainment_type) === 'party' ? 'selected' : '' }}>{{ __('Party / Club Event') }}</option>
                        <option value="other" {{ old('entertainment_type', $event->entertainment_type) === 'other' ? 'selected' : '' }}>{{ __('Other') }}</option>
                    </select>
                </div>
            </div>

            <!-- 2. DATUMS UN LAIKS -->
            <div class="space-y-5">
                <div class="flex items-center gap-2.5 pb-2 border-b border-slate-100">
                    <div class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-sm">2</div>
                    <h2 class="text-lg font-extrabold text-slate-900">{{ __('Date and Time') }}</h2>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- Sākuma laiks -->
                    <div>
                        <label for="start_at" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                            {{ __('Start Date and Time') }} <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                <i data-lucide="calendar" class="w-4 h-4"></i>
                            </div>
                            <input 
                                type="text" 
                                name="start_at" 
                                id="start_at" 
                                value="{{ old('start_at', $event->start_at?->format('Y-m-d H:i')) }}" 
                                required 
                                class="flatpickr-datetime block w-full pl-10 pr-4 py-3 bg-slate-50 border @error('start_at') border-rose-400 focus:ring-rose-400 @else border-slate-200 focus:ring-emerald-500 @enderror rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                        </div>
                        @error('start_at')
                            <p class="mt-1.5 text-xs font-bold text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Beigu laiks -->
                    <div>
                        <label for="end_at" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                            {{ __('End Date and Time') }}
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                <i data-lucide="clock" class="w-4 h-4"></i>
                            </div>
                            <input 
                                type="text" 
                                name="end_at" 
                                id="end_at" 
                                value="{{ old('end_at', $event->end_at?->format('Y-m-d H:i')) }}" 
                                class="flatpickr-datetime block w-full pl-10 pr-4 py-3 bg-slate-50 border @error('end_at') border-rose-400 focus:ring-rose-400 @else border-slate-200 focus:ring-emerald-500 @enderror rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                        </div>
                        @error('end_at')
                            <p class="mt-1.5 text-xs font-bold text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="pt-1">
                    <label class="flex items-center gap-2.5 cursor-pointer select-none">
                        <input 
                            type="checkbox" 
                            name="all_day" 
                            id="all_day" 
                            value="1" 
                            {{ old('all_day', $event->all_day) ? 'checked' : '' }} 
                            class="w-4 h-4 text-emerald-600 border-slate-300 rounded focus:ring-emerald-500 cursor-pointer">
                        <span class="text-xs sm:text-sm font-semibold text-slate-700">{{ __('All day event') }}</span>
                    </label>
                </div>
            </div>

            <!-- 3. NORISES VIETA -->
            <div class="space-y-5">
                <div class="flex items-center gap-2.5 pb-2 border-b border-slate-100">
                    <div class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-sm">3</div>
                    <h2 class="text-lg font-extrabold text-slate-900">{{ __('Venue') }}</h2>
                </div>

                <div class="space-y-3">
                    <label for="location_id" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                        {{ __('Select venue') }}
                    </label>
                    <select 
                        name="location_id" 
                        id="location_id" 
                        class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                        <option value="">{{ __('-- Choose a popular venue --') }}</option>
                        @foreach($popularLocations as $loc)
                            <option value="{{ $loc->id }}" {{ old('location_id', $event->location_id) == $loc->id ? 'selected' : '' }}>
                                {{ $loc->name }} ({{ $loc->city }}{{ $loc->address ? ' — ' . $loc->address : '' }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <!-- 4. BIĻETES UN CENAS -->
            <div class="space-y-5">
                <div class="flex items-center gap-2.5 pb-2 border-b border-slate-100">
                    <div class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-sm">4</div>
                    <h2 class="text-lg font-extrabold text-slate-900">{{ __('Tickets and Pricing') }}</h2>
                </div>

                <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200">
                    <label class="flex items-center gap-3 cursor-pointer select-none">
                        <input 
                            type="checkbox" 
                            name="is_free" 
                            id="is_free" 
                            value="1" 
                            {{ old('is_free', $event->is_free) ? 'checked' : '' }} 
                            onchange="togglePriceFields(this.checked)"
                            class="w-5 h-5 text-emerald-600 border-slate-300 rounded focus:ring-emerald-500 cursor-pointer">
                        <div>
                            <span class="text-sm font-bold text-slate-900">🎁 {{ __('Free event') }}</span>
                            <span class="text-xs text-slate-500 block">{{ __('Admission to the event is free') }}</span>
                        </div>
                    </label>
                </div>

                <div id="price-fields-container" class="{{ old('is_free', $event->is_free) ? 'hidden' : '' }} grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="price_min" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                            {{ __('Price from (€)') }}
                        </label>
                        <input 
                            type="number" 
                            step="0.01" 
                            min="0" 
                            name="price_min" 
                            id="price_min" 
                            value="{{ old('price_min', $event->price_min) }}" 
                            class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                    </div>
                    <div>
                        <label for="price_max" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                            {{ __('Price to (€)') }}
                        </label>
                        <input 
                            type="number" 
                            step="0.01" 
                            min="0" 
                            name="price_max" 
                            id="price_max" 
                            value="{{ old('price_max', $event->price_max) }}" 
                            class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="ticket_url" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                            {{ __('Ticket Purchase URL') }}
                        </label>
                        <input 
                            type="url" 
                            name="ticket_url" 
                            id="ticket_url" 
                            value="{{ old('ticket_url', $event->ticket_url) }}" 
                            class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                    </div>
                    <div>
                        <label for="source_url" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                            {{ __('Official Website / FB Event') }}
                        </label>
                        <input 
                            type="url" 
                            name="source_url" 
                            id="source_url" 
                            value="{{ old('source_url', $event->source_url) }}" 
                            class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                    </div>
                </div>
            </div>

            <!-- 5. APRAKSTS -->
            <div class="space-y-5">
                <div class="flex items-center gap-2.5 pb-2 border-b border-slate-100">
                    <div class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-sm">5</div>
                    <h2 class="text-lg font-extrabold text-slate-900">{{ __('Description and Content') }}</h2>
                </div>

                <div>
                    <label for="short_description" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                        {{ __('Short Summary') }}
                    </label>
                    <textarea 
                        name="short_description" 
                        id="short_description" 
                        rows="2" 
                        maxlength="500"
                        class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">{{ old('short_description', $event->short_description) }}</textarea>
                </div>

                <div>
                    <label for="description" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                        {{ __('Full Event Description') }} <span class="text-rose-500">*</span>
                    </label>
                    <textarea 
                        name="description" 
                        id="description" 
                        rows="6" 
                        required 
                        class="block w-full px-4 py-3 bg-slate-50 border @error('description') border-rose-400 @else border-slate-200 @enderror focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium leading-relaxed">{{ old('description', $event->description) }}</textarea>
                    @error('description')
                        <p class="mt-1.5 text-xs font-bold text-rose-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- 6. ATTĒLS -->
            <div class="space-y-5">
                <div class="flex items-center gap-2.5 pb-2 border-b border-slate-100">
                    <div class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-sm">6</div>
                    <h2 class="text-lg font-extrabold text-slate-900">{{ __('Event Poster / Image') }}</h2>
                </div>

                @if($event->display_image_url)
                    <div class="flex items-center gap-4 p-3 rounded-2xl bg-slate-50 border border-slate-200">
                        <img src="{{ $event->display_image_url }}" alt="{{ $event->title }}" class="w-20 h-20 object-cover rounded-xl shadow-xs">
                        <div>
                            <span class="text-xs font-bold text-slate-700 block">{{ __('Current image') }}</span>
                            <span class="text-[11px] text-slate-400">{{ __('To change, upload a new file or enter a new link below.') }}</span>
                        </div>
                    </div>
                @endif

                <div class="space-y-4">
                    <div>
                        <label for="image" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                            {{ __('New image file') }}
                        </label>
                        <input id="image" name="image" type="file" accept="image/*" class="block w-full text-xs text-slate-500 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100 cursor-pointer">
                    </div>

                    <div>
                        <label for="image_url" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                            {{ __('Or enter direct image URL') }}
                        </label>
                        <input 
                            type="url" 
                            name="image_url" 
                            id="image_url" 
                            value="{{ old('image_url', $event->image_url) }}" 
                            class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                    </div>
                </div>
            </div>

            <!-- POGAS -->
            <div class="pt-6 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-4">
                <a href="{{ route('events.my') }}" class="w-full sm:w-auto px-6 py-3 rounded-xl border border-slate-200 hover:bg-slate-100 text-slate-700 font-bold text-sm text-center transition-all">
                    {{ __('Cancel') }}
                </a>

                <button 
                    type="submit" 
                    class="w-full sm:w-auto px-8 py-3.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white font-extrabold text-sm flex items-center justify-center gap-2 shadow-md shadow-emerald-600/20 active:scale-[0.99] transition-all cursor-pointer">
                    <i data-lucide="check" class="w-4 h-4"></i>
                    <span>{{ __('Save Changes') }}</span>
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('schema')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Category pill toggle
    document.querySelectorAll('.category-pill').forEach(pill => {
        const checkbox = pill.querySelector('.category-checkbox');
        const indicator = pill.querySelector('.check-indicator');

        pill.addEventListener('click', () => {
            setTimeout(() => {
                if (checkbox.checked) {
                    pill.classList.remove('border-slate-200', 'bg-slate-50/70', 'text-slate-700');
                    pill.classList.add('border-emerald-500', 'bg-emerald-50/80', 'text-emerald-900', 'ring-2', 'ring-emerald-500/20');
                    if (indicator) indicator.classList.remove('opacity-0');
                } else {
                    pill.classList.remove('border-emerald-500', 'bg-emerald-50/80', 'text-emerald-900', 'ring-2', 'ring-emerald-500/20');
                    pill.classList.add('border-slate-200', 'bg-slate-50/70', 'text-slate-700');
                    if (indicator) indicator.classList.add('opacity-0');
                }
            }, 10);
        });
    });

    function setupFormPickers() {
        if (!window.flatpickr) {
            setTimeout(setupFormPickers, 50);
            return;
        }

        const localeObj = (window.flatpickrLocales && window.flatpickrLocales['{{ app()->getLocale() }}']) 
            ? window.flatpickrLocales['{{ app()->getLocale() }}'] 
            : 'default';

        const config = {
            enableTime: true,
            time_24hr: true,
            dateFormat: 'Y-m-d H:i',
            locale: localeObj,
            allowInput: true,
            disableMobile: "true",
        };

        window.flatpickr('#start_at', config);
        window.flatpickr('#end_at', config);
    }

    if (window.flatpickr) {
        setupFormPickers();
    } else {
        window.addEventListener('flatpickr-ready', setupFormPickers);
    }
});

function togglePriceFields(isFree) {
    const container = document.getElementById('price-fields-container');
    if (isFree) {
        container.classList.add('hidden');
    } else {
        container.classList.remove('hidden');
    }
}
</script>
@endpush
