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

            @if ($errors->any())
                <div class="p-4 sm:p-5 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800">
                    <div class="flex items-center gap-2 font-bold mb-2 text-rose-900">
                        <i data-lucide="alert-octagon" class="w-5 h-5 text-rose-600 shrink-0"></i>
                        <span>{{ __('Please correct the following errors to save:') }}</span>
                    </div>
                    <ul class="list-disc list-inside text-xs sm:text-sm space-y-1 text-rose-700">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <!-- 1. PAMATINFORMĀCIJA -->
            <div class="space-y-5">
                <div class="flex items-center gap-2.5 pb-2 border-b border-slate-100">
                    <div class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-sm">1</div>
                    <h2 class="text-lg font-extrabold text-slate-900">{{ __('Basic Information') }}</h2>
                </div>

                <!-- Valodu pārslēdzējs saturam -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5 p-3.5 rounded-2xl bg-slate-50 border border-slate-200">
                    <div>
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-800 block">{{ __('Content Language') }} / {{ __('Saturs pēc valodām') }}</span>
                        <span class="text-[11px] text-slate-500">{{ __('Latvian is primary. English and Russian are optional.') }}</span>
                    </div>
                    <div class="inline-flex p-1 bg-white border border-slate-200 rounded-xl gap-1 shadow-2xs">
                        <button type="button" onclick="switchContentLang('lv')" id="btn-lang-lv" class="lang-pill px-3 py-1.5 rounded-lg text-xs font-extrabold bg-emerald-600 text-white shadow-xs flex items-center gap-1.5 transition-all">
                            <span>🇱🇻</span> <span>LV</span> <span class="text-[9px] opacity-80 uppercase tracking-wider">({{ __('Primary') }})</span>
                        </button>
                        <button type="button" onclick="switchContentLang('en')" id="btn-lang-en" class="lang-pill px-3 py-1.5 rounded-lg text-xs font-bold text-slate-600 hover:text-slate-900 flex items-center gap-1.5 transition-all">
                            <span>🇬🇧</span> <span>EN</span>
                        </button>
                        <button type="button" onclick="switchContentLang('ru')" id="btn-lang-ru" class="lang-pill px-3 py-1.5 rounded-lg text-xs font-bold text-slate-600 hover:text-slate-900 flex items-center gap-1.5 transition-all">
                            <span>🇷🇺</span> <span>RU</span>
                        </button>
                    </div>
                </div>

                <!-- Pasākuma nosaukums: LV -->
                <div id="field-title-lv" class="lang-field-lv">
                    <label for="title" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                        {{ __('Event Title') }} <span class="text-rose-500">*</span> <span class="text-slate-400 font-normal lowercase tracking-normal">(Latviešu)</span>
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

                <!-- Pasākuma nosaukums: EN -->
                <div id="field-title-en" class="lang-field-en hidden">
                    <label for="title_en" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                        {{ __('Event Title') }} <span class="text-slate-400 font-normal lowercase tracking-normal">(English, {{ __('optional') }})</span>
                    </label>
                    <input 
                        type="text" 
                        name="translations[en][title]" 
                        id="title_en" 
                        value="{{ old('translations.en.title', $enTrans?->title) }}" 
                        placeholder="e.g., Event title in English" 
                        class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                </div>

                <!-- Pasākuma nosaukums: RU -->
                <div id="field-title-ru" class="lang-field-ru hidden">
                    <label for="title_ru" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                        {{ __('Event Title') }} <span class="text-slate-400 font-normal lowercase tracking-normal">(Русский, {{ __('optional') }})</span>
                    </label>
                    <input 
                        type="text" 
                        name="translations[ru][title]" 
                        id="title_ru" 
                        value="{{ old('translations.ru.title', $ruTrans?->title) }}" 
                        placeholder="например, Название события на русском" 
                        class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
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

                @php
                    $isCustomLoc = old('city') || old('venue_name') || $errors->has('city') || $errors->has('venue_name');
                @endphp

                <!-- Vieta Type Selector (Existing vs Custom) -->
                <div class="flex items-center p-1 bg-slate-100 rounded-2xl w-fit max-w-full">
                    <button 
                        type="button" 
                        id="loc-type-existing-btn" 
                        onclick="switchLocationMode('existing')" 
                        class="px-4 py-2 rounded-xl text-xs sm:text-sm font-bold transition-all {{ !$isCustomLoc ? 'shadow-xs bg-white text-slate-900' : 'text-slate-600 hover:text-slate-900' }}">
                        {{ __('Choose from list') }}
                    </button>
                    <button 
                        type="button" 
                        id="loc-type-custom-btn" 
                        onclick="switchLocationMode('custom')" 
                        class="px-4 py-2 rounded-xl text-xs sm:text-sm font-bold transition-all {{ $isCustomLoc ? 'shadow-xs bg-white text-slate-900' : 'text-slate-600 hover:text-slate-900' }}">
                        {{ __('Enter new venue') }}
                    </button>
                </div>

                <!-- Saraksta izvēle -->
                <div id="loc-existing-container" class="{{ $isCustomLoc ? 'hidden' : '' }} space-y-3">
                    <label for="location_id" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                        {{ __('Select venue') }}
                    </label>
                    <select 
                        name="location_id" 
                        id="location_id" 
                        class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                        <option value="">{{ __('-- Choose a venue --') }}</option>
                        @foreach($popularLocations as $loc)
                            <option value="{{ $loc->id }}" {{ old('location_id', $event->location_id) == $loc->id ? 'selected' : '' }}>
                                {{ $loc->name }} ({{ $loc->city }}{{ $loc->address ? ' — ' . $loc->address : '' }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Jaunas vietas ievade -->
                <div id="loc-custom-container" class="{{ !$isCustomLoc ? 'hidden' : '' }} space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="city" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                                {{ __('City / Municipality') }}
                            </label>
                            <input 
                                type="text" 
                                name="city" 
                                id="city" 
                                list="cities-datalist"
                                value="{{ old('city', $event->location?->city) }}" 
                                placeholder="{{ __('e.g., Riga, Liepaja, Valmiera...') }}" 
                                class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                            <datalist id="cities-datalist">
                                @foreach($cities as $c)
                                    <option value="{{ $c }}">
                                @endforeach
                            </datalist>
                        </div>

                        <div>
                            <label for="venue_name" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                                {{ __('Venue Name') }}
                            </label>
                            <input 
                                type="text" 
                                name="venue_name" 
                                id="venue_name" 
                                value="{{ old('venue_name', $event->location?->name) }}" 
                                placeholder="{{ __('e.g., Great Amber Concert Hall') }}" 
                                class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                        </div>
                    </div>

                    <div>
                        <label for="address" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                            {{ __('Address') }} <span class="text-slate-400 font-normal lowercase tracking-normal">{{ __('(optional)') }}</span>
                        </label>
                        <input 
                            type="text" 
                            name="address" 
                            id="address" 
                            value="{{ old('address', $event->location?->address) }}" 
                            placeholder="{{ __('e.g., Radio street 8') }}" 
                            class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                    </div>
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

                <!-- Valodu pārslēdzējs aprakstam -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5 p-3.5 rounded-2xl bg-slate-50 border border-slate-200">
                    <div>
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-800 block">{{ __('Description Language') }}</span>
                        <span class="text-[11px] text-slate-500">{{ __('Switch between languages to provide multilingual descriptions.') }}</span>
                    </div>
                    <div class="inline-flex p-1 bg-white border border-slate-200 rounded-xl gap-1 shadow-2xs">
                        <button type="button" onclick="switchContentLang('lv')" id="btn-lang-desc-lv" class="lang-pill-desc px-3 py-1.5 rounded-lg text-xs font-extrabold bg-emerald-600 text-white shadow-xs flex items-center gap-1.5 transition-all">
                            <span>🇱🇻</span> <span>LV</span>
                        </button>
                        <button type="button" onclick="switchContentLang('en')" id="btn-lang-desc-en" class="lang-pill-desc px-3 py-1.5 rounded-lg text-xs font-bold text-slate-600 hover:text-slate-900 flex items-center gap-1.5 transition-all">
                            <span>🇬🇧</span> <span>EN</span>
                        </button>
                        <button type="button" onclick="switchContentLang('ru')" id="btn-lang-desc-ru" class="lang-pill-desc px-3 py-1.5 rounded-lg text-xs font-bold text-slate-600 hover:text-slate-900 flex items-center gap-1.5 transition-all">
                            <span>🇷🇺</span> <span>RU</span>
                        </button>
                    </div>
                </div>

                <!-- LV Apraksts -->
                <div class="lang-desc-lv space-y-4">
                    <div>
                        <label for="short_description" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                            {{ __('Short Summary') }} <span class="text-slate-400 font-normal lowercase tracking-normal">(Latviešu, {{ __('optional') }})</span>
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
                            {{ __('Full Event Description') }} <span class="text-rose-500">*</span> <span class="text-slate-400 font-normal lowercase tracking-normal">(Latviešu)</span>
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

                <!-- EN Apraksts -->
                <div class="lang-desc-en hidden space-y-4">
                    <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-600">
                        🇬🇧 <strong>English translation:</strong> Optional. If left empty, the site will automatically use the Latvian description or machine translation.
                    </div>
                    <div>
                        <label for="short_description_en" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                            {{ __('Short Summary') }} <span class="text-slate-400 font-normal lowercase tracking-normal">(English, {{ __('optional') }})</span>
                        </label>
                        <textarea 
                            name="translations[en][short_description]" 
                            id="short_description_en" 
                            rows="2" 
                            maxlength="500"
                            placeholder="Short 1-2 sentence description in English..." 
                            class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">{{ old('translations.en.short_description', $enTrans?->short_description) }}</textarea>
                    </div>

                    <div>
                        <label for="description_en" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                            {{ __('Full Event Description') }} <span class="text-slate-400 font-normal lowercase tracking-normal">(English, {{ __('optional') }})</span>
                        </label>
                        <textarea 
                            name="translations[en][description]" 
                            id="description_en" 
                            rows="6" 
                            placeholder="Detailed event description in English..." 
                            class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium leading-relaxed">{{ old('translations.en.description', $enTrans?->description) }}</textarea>
                    </div>
                </div>

                <!-- RU Apraksts -->
                <div class="lang-desc-ru hidden space-y-4">
                    <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-600">
                        🇷🇺 <strong>Русский перевод:</strong> Необязательно. Если оставить пустым, на сайте будет использоваться текст на латышском языке или машинный перевод.
                    </div>
                    <div>
                        <label for="short_description_ru" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                            {{ __('Short Summary') }} <span class="text-slate-400 font-normal lowercase tracking-normal">(Русский, {{ __('optional') }})</span>
                        </label>
                        <textarea 
                            name="translations[ru][short_description]" 
                            id="short_description_ru" 
                            rows="2" 
                            maxlength="500"
                            placeholder="Краткое описание события на русском..." 
                            class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">{{ old('translations.ru.short_description', $ruTrans?->short_description) }}</textarea>
                    </div>

                    <div>
                        <label for="description_ru" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                            {{ __('Full Event Description') }} <span class="text-slate-400 font-normal lowercase tracking-normal">(Русский, {{ __('optional') }})</span>
                        </label>
                        <textarea 
                            name="translations[ru][description]" 
                            id="description_ru" 
                            rows="6" 
                            placeholder="Подробное описание программы, участников на русском..." 
                            class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium leading-relaxed">{{ old('translations.ru.description', $ruTrans?->description) }}</textarea>
                    </div>
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
                            {{ __('New image file') }} <span class="text-slate-400 font-normal lowercase tracking-normal">{{ __('(JPG, PNG, WEBP up to 5MB)') }}</span>
                        </label>
                        <input id="image" name="image" type="file" accept="image/*" onchange="handleEditImagePreview(this)" class="block w-full text-xs text-slate-500 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100 cursor-pointer">
                        <div id="image-edit-preview-container" class="hidden mt-3 flex items-center gap-3 p-3 rounded-2xl bg-emerald-50/80 border border-emerald-200">
                            <img id="image-edit-preview" src="#" alt="Preview" class="w-16 h-16 object-cover rounded-xl shadow-2xs">
                            <div class="text-xs">
                                <span class="font-bold text-emerald-900 block">{{ __('New image selected') }}</span>
                                <span class="text-emerald-700 font-medium" id="image-edit-preview-size"></span>
                            </div>
                        </div>
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

function switchContentLang(lang) {
    const langs = ['lv', 'en', 'ru'];
    langs.forEach(l => {
        const titleField = document.getElementById('field-title-' + l);
        const topBtn = document.getElementById('btn-lang-' + l);
        const descDiv = document.querySelector('.lang-desc-' + l);
        const descBtn = document.getElementById('btn-lang-desc-' + l);

        const isActive = (l === lang);
        if (titleField) titleField.classList.toggle('hidden', !isActive);
        if (descDiv) descDiv.classList.toggle('hidden', !isActive);

        [topBtn, descBtn].forEach(btn => {
            if (!btn) return;
            if (isActive) {
                btn.className = btn.className.replace(/text-slate-600 hover:text-slate-900 font-bold/g, '') + ' bg-emerald-600 text-white shadow-xs font-extrabold';
            } else {
                btn.className = btn.className.replace(/bg-emerald-600 text-white shadow-xs font-extrabold/g, '') + ' text-slate-600 hover:text-slate-900 font-bold';
            }
        });
    });
}

function togglePriceFields(isFree) {
    const container = document.getElementById('price-fields-container');
    if (isFree) {
        container.classList.add('hidden');
    } else {
        container.classList.remove('hidden');
    }
}

function switchLocationMode(mode) {
    const existingContainer = document.getElementById('loc-existing-container');
    const customContainer = document.getElementById('loc-custom-container');
    const existingBtn = document.getElementById('loc-type-existing-btn');
    const customBtn = document.getElementById('loc-type-custom-btn');
    const locIdSelect = document.getElementById('location_id');

    if (mode === 'custom') {
        if (existingContainer) existingContainer.classList.add('hidden');
        if (customContainer) customContainer.classList.remove('hidden');
        if (customBtn) customBtn.classList.add('bg-white', 'text-slate-900', 'shadow-xs');
        if (customBtn) customBtn.classList.remove('text-slate-600');
        if (existingBtn) existingBtn.classList.remove('bg-white', 'text-slate-900', 'shadow-xs');
        if (existingBtn) existingBtn.classList.add('text-slate-600');
        if (locIdSelect) locIdSelect.value = '';
    } else {
        if (existingContainer) existingContainer.classList.remove('hidden');
        if (customContainer) customContainer.classList.add('hidden');
        if (existingBtn) existingBtn.classList.add('bg-white', 'text-slate-900', 'shadow-xs');
        if (existingBtn) existingBtn.classList.remove('text-slate-600');
        if (customBtn) customBtn.classList.remove('bg-white', 'text-slate-900', 'shadow-xs');
        if (customBtn) customBtn.classList.add('text-slate-600');
    }
}

function handleEditImagePreview(input) {
    const container = document.getElementById('image-edit-preview-container');
    const img = document.getElementById('image-edit-preview');
    const sizeSpan = document.getElementById('image-edit-preview-size');

    if (input.files && input.files[0]) {
        const file = input.files[0];
        const sizeMb = (file.size / 1024 / 1024).toFixed(2);
        if (sizeSpan) sizeSpan.textContent = file.name + ' (' + sizeMb + ' MB)';
        if (container && img) {
            const reader = new FileReader();
            reader.onload = function(e) {
                img.src = e.target.result;
                container.classList.remove('hidden');
            };
            reader.readAsDataURL(file);
        }
    }
}
</script>
@endpush
