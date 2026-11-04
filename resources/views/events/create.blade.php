@extends('layouts.app')

@section('title', __('Add Event') . ' — Šodiena')

@section('content')
<div class="py-8 sm:py-12 px-3 sm:px-6 lg:px-8 max-w-4xl mx-auto">
    <!-- Breadcrumb -->
    <nav class="flex items-center gap-2 text-xs font-semibold text-slate-500 mb-6" aria-label="Breadcrumb">
        <a href="{{ route('events.index') }}" class="hover:text-emerald-700 transition-colors">{{ __('Events') }}</a>
        <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
        <span class="text-slate-800 font-bold">{{ __('Add Event') }}</span>
    </nav>

    <!-- Header Banner -->
    <div class="bg-gradient-to-r from-emerald-600 via-emerald-700 to-teal-700 rounded-3xl p-6 sm:p-8 text-white shadow-lg shadow-emerald-700/20 mb-8 relative overflow-hidden">
        <div class="absolute -right-8 -top-8 w-48 h-48 bg-white/10 rounded-full blur-2xl pointer-events-none"></div>
        <div class="relative z-10">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/15 backdrop-blur-md text-emerald-100 text-xs font-bold uppercase tracking-wider mb-3">
                <i data-lucide="plus-circle" class="w-3.5 h-3.5"></i>
                <span>{{ __('New Event') }}</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight">Pievienot jaunu pasākumu</h1>
            <p class="mt-2 text-emerald-100/90 text-xs sm:text-sm max-w-xl">
                Aizpildiet informāciju par savu pasākumu. Pēc pievienošanas pasākums uzreiz kļūs pieejams visiem Šodiena.lv apmeklētājiem.
            </p>
        </div>
    </div>

    <!-- Main Form Card -->
    <div class="bg-white rounded-3xl border border-slate-200/90 shadow-sm p-6 sm:p-10">
        <form action="{{ route('events.store') }}" method="POST" enctype="multipart/form-data" class="space-y-8" id="create-event-form">
            @csrf

            <!-- 1. PAMATINFORMĀCIJA -->
            <div class="space-y-5">
                <div class="flex items-center gap-2.5 pb-2 border-b border-slate-100">
                    <div class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-sm">1</div>
                    <h2 class="text-lg font-extrabold text-slate-900">Pamatinformācija</h2>
                </div>

                <!-- Pasākuma nosaukums -->
                <div>
                    <label for="title" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                        Pasākuma nosaukums <span class="text-rose-500">*</span>
                    </label>
                    <input 
                        type="text" 
                        name="title" 
                        id="title" 
                        value="{{ old('title') }}" 
                        required 
                        placeholder="piemēram: Prāta Vētras lielkoncerts Mežaparka estrādē" 
                        class="block w-full px-4 py-3 bg-slate-50 border @error('title') border-rose-400 focus:ring-rose-400 @else border-slate-200 focus:ring-emerald-500 @enderror rounded-xl text-sm text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
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
                        Kategorijas <span class="text-rose-500">*</span>
                        <span class="text-slate-400 font-normal lowercase tracking-normal ml-1">(izvēlieties vienu vai vairākas)</span>
                    </label>
                    
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5 pt-1">
                        @foreach($categories as $category)
                            @php
                                $isChecked = is_array(old('categories')) && in_array($category->id, old('categories'));
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
                        Izklaides veids / Formāts <span class="text-slate-400 font-normal lowercase tracking-normal">(neobligāts)</span>
                    </label>
                    <select 
                        name="entertainment_type" 
                        id="entertainment_type" 
                        class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                        <option value="">-- Izvēlieties formātu (vai atstājiet tukšu) --</option>
                        <option value="concert" {{ old('entertainment_type') === 'concert' ? 'selected' : '' }}>Koncerts / Dzīvā mūzika</option>
                        <option value="theatre" {{ old('entertainment_type') === 'theatre' ? 'selected' : '' }}>Teātra izrāde / Iestudējums</option>
                        <option value="cinema" {{ old('entertainment_type') === 'cinema' ? 'selected' : '' }}>Kino seanss / Filma</option>
                        <option value="festival" {{ old('entertainment_type') === 'festival' ? 'selected' : '' }}>Festivāls / Svētki</option>
                        <option value="exhibition" {{ old('entertainment_type') === 'exhibition' ? 'selected' : '' }}>Izstāde / Ekspozīcija</option>
                        <option value="workshop" {{ old('entertainment_type') === 'workshop' ? 'selected' : '' }}>Meistarklase / Seminārs</option>
                        <option value="active" {{ old('entertainment_type') === 'active' ? 'selected' : '' }}>Sports / Aktīvā atpūta</option>
                        <option value="family" {{ old('entertainment_type') === 'family' ? 'selected' : '' }}>Pasākums ģimenei un bērniem</option>
                        <option value="party" {{ old('entertainment_type') === 'party' ? 'selected' : '' }}>Ballīte / Klubu pasākums</option>
                        <option value="other" {{ old('entertainment_type') === 'other' ? 'selected' : '' }}>Cits</option>
                    </select>
                </div>
            </div>

            <!-- 2. DATUMS UN LAIKS -->
            <div class="space-y-5">
                <div class="flex items-center gap-2.5 pb-2 border-b border-slate-100">
                    <div class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-sm">2</div>
                    <h2 class="text-lg font-extrabold text-slate-900">Datums un laiks</h2>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- Sākuma laiks -->
                    <div>
                        <label for="start_at" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                            Sākuma datums un laiks <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                <i data-lucide="calendar" class="w-4 h-4"></i>
                            </div>
                            <input 
                                type="text" 
                                name="start_at" 
                                id="start_at" 
                                value="{{ old('start_at') }}" 
                                required 
                                placeholder="YYYY-MM-DD HH:MM" 
                                class="flatpickr-datetime block w-full pl-10 pr-4 py-3 bg-slate-50 border @error('start_at') border-rose-400 focus:ring-rose-400 @else border-slate-200 focus:ring-emerald-500 @enderror rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                        </div>
                        @error('start_at')
                            <p class="mt-1.5 text-xs font-bold text-rose-600 flex items-center gap-1">
                                <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i>
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <!-- Beigu laiks -->
                    <div>
                        <label for="end_at" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                            Beigu datums un laiks <span class="text-slate-400 font-normal lowercase tracking-normal">(neobligāts)</span>
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                <i data-lucide="clock" class="w-4 h-4"></i>
                            </div>
                            <input 
                                type="text" 
                                name="end_at" 
                                id="end_at" 
                                value="{{ old('end_at') }}" 
                                placeholder="YYYY-MM-DD HH:MM" 
                                class="flatpickr-datetime block w-full pl-10 pr-4 py-3 bg-slate-50 border @error('end_at') border-rose-400 focus:ring-rose-400 @else border-slate-200 focus:ring-emerald-500 @enderror rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                        </div>
                        @error('end_at')
                            <p class="mt-1.5 text-xs font-bold text-rose-600 flex items-center gap-1">
                                <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i>
                                {{ $message }}
                            </p>
                        @enderror
                    </div>
                </div>

                <!-- Visas dienas pasākums -->
                <div class="pt-1">
                    <label class="flex items-center gap-2.5 cursor-pointer select-none">
                        <input 
                            type="checkbox" 
                            name="all_day" 
                            id="all_day" 
                            value="1" 
                            {{ old('all_day') ? 'checked' : '' }} 
                            class="w-4 h-4 text-emerald-600 border-slate-300 rounded focus:ring-emerald-500 cursor-pointer">
                        <span class="text-xs sm:text-sm font-semibold text-slate-700">Visas dienas pasākums (bez konkrēta sākuma pulksteņlaika)</span>
                    </label>
                </div>
            </div>

            <!-- 3. NORISES VIETA -->
            <div class="space-y-5">
                <div class="flex items-center gap-2.5 pb-2 border-b border-slate-100">
                    <div class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-sm">3</div>
                    <h2 class="text-lg font-extrabold text-slate-900">Norises vieta</h2>
                </div>

                <!-- Vieta Type Selector (Existing vs Custom) -->
                <div class="flex items-center p-1 bg-slate-100 rounded-2xl w-fit max-w-full">
                    <button 
                        type="button" 
                        id="loc-type-existing-btn" 
                        onclick="switchLocationMode('existing')" 
                        class="px-4 py-2 rounded-xl text-xs sm:text-sm font-bold transition-all shadow-xs bg-white text-slate-900">
                        Izvēlēties no saraksta
                    </button>
                    <button 
                        type="button" 
                        id="loc-type-custom-btn" 
                        onclick="switchLocationMode('custom')" 
                        class="px-4 py-2 rounded-xl text-xs sm:text-sm font-bold transition-all text-slate-600 hover:text-slate-900">
                        Ievadīt jaunu vietu
                    </button>
                </div>

                <!-- Saraksta izvēle -->
                <div id="loc-existing-container" class="space-y-3">
                    <label for="location_id" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                        Izvēlieties norises vietu <span class="text-rose-500">*</span>
                    </label>
                    <select 
                        name="location_id" 
                        id="location_id" 
                        class="block w-full px-4 py-3 bg-slate-50 border @error('location_id') border-rose-400 @else border-slate-200 @enderror focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                        <option value="">-- Izvēlieties populāru vietu --</option>
                        @foreach($popularLocations as $loc)
                            <option value="{{ $loc->id }}" {{ old('location_id') == $loc->id ? 'selected' : '' }}>
                                {{ $loc->name }} ({{ $loc->city }}{{ $loc->address ? ' — ' . $loc->address : '' }})
                            </option>
                        @endforeach
                    </select>
                    @error('location_id')
                        <p class="mt-1.5 text-xs font-bold text-rose-600 flex items-center gap-1">
                            <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i>
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <!-- Jaunas vietas ievade -->
                <div id="loc-custom-container" class="hidden space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Pilsēta -->
                        <div>
                            <label for="city" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                                Pilsēta / Apdzīvota vieta <span class="text-rose-500">*</span>
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                    <i data-lucide="map-pin" class="w-4 h-4"></i>
                                </div>
                                <input 
                                    type="text" 
                                    name="city" 
                                    id="city" 
                                    list="cities-datalist"
                                    value="{{ old('city') }}" 
                                    placeholder="piemēram: Rīga, Liepāja, Valmiera..." 
                                    class="block w-full pl-10 pr-4 py-3 bg-slate-50 border @error('city') border-rose-400 @else border-slate-200 @enderror focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                                <datalist id="cities-datalist">
                                    @foreach($cities as $c)
                                        <option value="{{ $c }}">
                                    @endforeach
                                </datalist>
                            </div>
                            @error('city')
                                <p class="mt-1.5 text-xs font-bold text-rose-600 flex items-center gap-1">
                                    <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i>
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                        <!-- Vietas nosaukums -->
                        <div>
                            <label for="venue_name" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                                Vietas nosaukums <span class="text-rose-500">*</span>
                            </label>
                            <input 
                                type="text" 
                                name="venue_name" 
                                id="venue_name" 
                                value="{{ old('venue_name') }}" 
                                placeholder="piemēram: Hanzas Perons, Dzintaru koncertzāle" 
                                class="block w-full px-4 py-3 bg-slate-50 border @error('venue_name') border-rose-400 @else border-slate-200 @enderror focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                            @error('venue_name')
                                <p class="mt-1.5 text-xs font-bold text-rose-600 flex items-center gap-1">
                                    <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i>
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>
                    </div>

                    <!-- Adrese -->
                    <div>
                        <label for="address" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                            Adrese <span class="text-slate-400 font-normal lowercase tracking-normal">(neobligāts)</span>
                        </label>
                        <input 
                            type="text" 
                            name="address" 
                            id="address" 
                            value="{{ old('address') }}" 
                            placeholder="piemēram: Hanzas iela 16a" 
                            class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                    </div>
                </div>
            </div>

            <!-- 4. BIĻETES UN CENAS -->
            <div class="space-y-5">
                <div class="flex items-center gap-2.5 pb-2 border-b border-slate-100">
                    <div class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-sm">4</div>
                    <h2 class="text-lg font-extrabold text-slate-900">Biļetes un cenas</h2>
                </div>

                <!-- Bezmaksas pasākums slēdzis -->
                <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200">
                    <label class="flex items-center gap-3 cursor-pointer select-none">
                        <input 
                            type="checkbox" 
                            name="is_free" 
                            id="is_free" 
                            value="1" 
                            {{ old('is_free') ? 'checked' : '' }} 
                            onchange="togglePriceFields(this.checked)"
                            class="w-5 h-5 text-emerald-600 border-slate-300 rounded focus:ring-emerald-500 cursor-pointer">
                        <div>
                            <span class="text-sm font-bold text-slate-900 flex items-center gap-1.5">
                                🎁 Bezmaksas pasākums
                            </span>
                            <span class="text-xs text-slate-500 block">Ieeja pasākumā ir bez maksas</span>
                        </div>
                    </label>
                </div>

                <!-- Cenas lauki -->
                <div id="price-fields-container" class="{{ old('is_free') ? 'hidden' : '' }} grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="price_min" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                            Cena no (€) <span class="text-slate-400 font-normal lowercase tracking-normal">(neobligāts)</span>
                        </label>
                        <input 
                            type="number" 
                            step="0.01" 
                            min="0" 
                            name="price_min" 
                            id="price_min" 
                            value="{{ old('price_min') }}" 
                            placeholder="piemēram: 15.00" 
                            class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                    </div>
                    <div>
                        <label for="price_max" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                            Cena līdz (€) <span class="text-slate-400 font-normal lowercase tracking-normal">(neobligāts)</span>
                        </label>
                        <input 
                            type="number" 
                            step="0.01" 
                            min="0" 
                            name="price_max" 
                            id="price_max" 
                            value="{{ old('price_max') }}" 
                            placeholder="piemēram: 45.00" 
                            class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                    </div>
                </div>

                <!-- Saites -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- Biļešu saite -->
                    <div>
                        <label for="ticket_url" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                            Biļešu iegādes saite <span class="text-slate-400 font-normal lowercase tracking-normal">(neobligāts)</span>
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                <i data-lucide="ticket" class="w-4 h-4"></i>
                            </div>
                            <input 
                                type="url" 
                                name="ticket_url" 
                                id="ticket_url" 
                                value="{{ old('ticket_url') }}" 
                                placeholder="https://www.bilesuserviss.lv/..." 
                                class="block w-full pl-10 pr-4 py-3 bg-slate-50 border @error('ticket_url') border-rose-400 @else border-slate-200 @enderror focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                        </div>
                        @error('ticket_url')
                            <p class="mt-1.5 text-xs font-bold text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Oficiālā saite -->
                    <div>
                        <label for="source_url" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                            Oficiālā mājaslapa / FB notikums <span class="text-slate-400 font-normal lowercase tracking-normal">(neobligāts)</span>
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                <i data-lucide="link" class="w-4 h-4"></i>
                            </div>
                            <input 
                                type="url" 
                                name="source_url" 
                                id="source_url" 
                                value="{{ old('source_url') }}" 
                                placeholder="https://..." 
                                class="block w-full pl-10 pr-4 py-3 bg-slate-50 border @error('source_url') border-rose-400 @else border-slate-200 @enderror focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                        </div>
                        @error('source_url')
                            <p class="mt-1.5 text-xs font-bold text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <!-- 5. APRAKSTS UN DETAĻAS -->
            <div class="space-y-5">
                <div class="flex items-center gap-2.5 pb-2 border-b border-slate-100">
                    <div class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-sm">5</div>
                    <h2 class="text-lg font-extrabold text-slate-900">Apraksts un saturs</h2>
                </div>

                <!-- Īss apraksts -->
                <div>
                    <label for="short_description" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                        Īss kopsavilkums <span class="text-slate-400 font-normal lowercase tracking-normal">(neobligāts, tiks ģenerēts automātiski, ja atstāts tukšs)</span>
                    </label>
                    <textarea 
                        name="short_description" 
                        id="short_description" 
                        rows="2" 
                        maxlength="500"
                        placeholder="Īss 1-2 teikumu apraksts pasākumu saraksta kartītēm..." 
                        class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">{{ old('short_description') }}</textarea>
                </div>

                <!-- Pilns apraksts -->
                <div>
                    <label for="description" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                        Pilns pasākuma apraksts <span class="text-rose-500">*</span>
                    </label>
                    <textarea 
                        name="description" 
                        id="description" 
                        rows="6" 
                        required 
                        placeholder="Detalizēts pasākuma apraksts, programma, dalībnieki, noderīga informācija apmeklētājiem..." 
                        class="block w-full px-4 py-3 bg-slate-50 border @error('description') border-rose-400 focus:ring-rose-400 @else border-slate-200 focus:ring-emerald-500 @enderror rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium leading-relaxed">{{ old('description') }}</textarea>
                    @error('description')
                        <p class="mt-1.5 text-xs font-bold text-rose-600 flex items-center gap-1">
                            <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i>
                            {{ $message }}
                        </p>
                    @enderror
                </div>
            </div>

            <!-- 6. PLAKĀTS / ATTĒLS -->
            <div class="space-y-5">
                <div class="flex items-center gap-2.5 pb-2 border-b border-slate-100">
                    <div class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-sm">6</div>
                    <h2 class="text-lg font-extrabold text-slate-900">Pasākuma plakāts / Attēls</h2>
                </div>

                <div class="space-y-4">
                    <!-- Faila augšupielāde -->
                    <div>
                        <label for="image" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                            Augšupielādēt attēlu no datora <span class="text-slate-400 font-normal lowercase tracking-normal">(JPG, PNG, WEBP līdz 5MB)</span>
                        </label>
                        <div class="flex items-center justify-center w-full">
                            <label for="image" class="flex flex-col items-center justify-center w-full h-36 border-2 border-slate-300 border-dashed rounded-2xl cursor-pointer bg-slate-50 hover:bg-slate-100 transition-all">
                                <div class="flex flex-col items-center justify-center pt-5 pb-6">
                                    <i data-lucide="upload" class="w-8 h-8 text-slate-400 mb-2"></i>
                                    <p class="text-xs sm:text-sm text-slate-600 font-semibold">
                                        <span class="text-emerald-700 underline font-bold">Nospiediet šeit</span> vai ievelciet attēlu
                                    </p>
                                    <p class="text-[11px] text-slate-400 mt-1" id="file-name-preview">PNG, JPG, WEBP vai GIF (maks. 5MB)</p>
                                </div>
                                <input 
                                    id="image" 
                                    name="image" 
                                    type="file" 
                                    accept="image/*" 
                                    onchange="handleImageFileSelected(this)"
                                    class="hidden">
                            </label>
                        </div>
                        @error('image')
                            <p class="mt-1.5 text-xs font-bold text-rose-600 flex items-center gap-1">
                                <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i>
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <!-- Vai alternatīvi Attēla saite -->
                    <div class="pt-1">
                        <label for="image_url" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                            Vai ievadiet tiešo attēla saiti (URL) <span class="text-slate-400 font-normal lowercase tracking-normal">(neobligāts)</span>
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                <i data-lucide="image" class="w-4 h-4"></i>
                            </div>
                            <input 
                                type="url" 
                                name="image_url" 
                                id="image_url" 
                                value="{{ old('image_url') }}" 
                                placeholder="https://domain.lv/images/plakats.jpg" 
                                class="block w-full pl-10 pr-4 py-3 bg-slate-50 border @error('image_url') border-rose-400 @else border-slate-200 @enderror focus:ring-emerald-500 rounded-xl text-sm text-slate-900 focus:outline-none focus:ring-2 focus:bg-white transition-all font-medium">
                        </div>
                        @error('image_url')
                            <p class="mt-1.5 text-xs font-bold text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <!-- DARBĪBU POGAS -->
            <div class="pt-6 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-4">
                <a href="{{ route('events.index') }}" class="w-full sm:w-auto px-6 py-3 rounded-xl border border-slate-200 hover:bg-slate-100 text-slate-700 font-bold text-sm text-center transition-all">
                    Atcelt
                </a>

                <button 
                    type="submit" 
                    id="submit-btn" 
                    class="w-full sm:w-auto px-8 py-3.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white font-extrabold text-sm flex items-center justify-center gap-2 shadow-md shadow-emerald-600/20 active:scale-[0.99] transition-all cursor-pointer">
                    <i data-lucide="check-circle" class="w-4 h-4"></i>
                    <span>Publicēt pasākumu</span>
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('styles')
<style>
.category-checkbox:checked + div {
    border-color: #10b981;
}
</style>
@endpush

@push('schema')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Category pill click handler
    document.querySelectorAll('.category-pill').forEach(pill => {
        const checkbox = pill.querySelector('.category-checkbox');
        const indicator = pill.querySelector('.check-indicator');

        pill.addEventListener('click', (e) => {
            // Let the label native checkbox toggle
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

    // Setup Flatpickr for start_at and end_at
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

        const startPicker = window.flatpickr('#start_at', config);
        const endPicker = window.flatpickr('#end_at', config);

        // All day toggle adjustment
        const allDayCheckbox = document.getElementById('all_day');
        if (allDayCheckbox) {
            allDayCheckbox.addEventListener('change', function () {
                const enableTime = !this.checked;
                if (startPicker) {
                    startPicker.set('enableTime', enableTime);
                    startPicker.set('dateFormat', enableTime ? 'Y-m-d H:i' : 'Y-m-d');
                }
                if (endPicker) {
                    endPicker.set('enableTime', enableTime);
                    endPicker.set('dateFormat', enableTime ? 'Y-m-d H:i' : 'Y-m-d');
                }
            });
        }
    }

    if (window.flatpickr) {
        setupFormPickers();
    } else {
        window.addEventListener('flatpickr-ready', setupFormPickers);
    }
});

function switchLocationMode(mode) {
    const existingContainer = document.getElementById('loc-existing-container');
    const customContainer = document.getElementById('loc-custom-container');
    const existingBtn = document.getElementById('loc-type-existing-btn');
    const customBtn = document.getElementById('loc-type-custom-btn');
    const locIdSelect = document.getElementById('location_id');

    if (mode === 'custom') {
        existingContainer.classList.add('hidden');
        customContainer.classList.remove('hidden');
        customBtn.classList.add('bg-white', 'text-slate-900', 'shadow-xs');
        customBtn.classList.remove('text-slate-600');
        existingBtn.classList.remove('bg-white', 'text-slate-900', 'shadow-xs');
        existingBtn.classList.add('text-slate-600');
        if (locIdSelect) locIdSelect.value = '';
    } else {
        existingContainer.classList.remove('hidden');
        customContainer.classList.add('hidden');
        existingBtn.classList.add('bg-white', 'text-slate-900', 'shadow-xs');
        existingBtn.classList.remove('text-slate-600');
        customBtn.classList.remove('bg-white', 'text-slate-900', 'shadow-xs');
        customBtn.classList.add('text-slate-600');
    }
}

function togglePriceFields(isFree) {
    const container = document.getElementById('price-fields-container');
    const minInput = document.getElementById('price_min');
    const maxInput = document.getElementById('price_max');
    if (isFree) {
        container.classList.add('hidden');
        if (minInput) minInput.value = '';
        if (maxInput) maxInput.value = '';
    } else {
        container.classList.remove('hidden');
    }
}

function handleImageFileSelected(input) {
    const previewText = document.getElementById('file-name-preview');
    if (input.files && input.files[0]) {
        const file = input.files[0];
        previewText.textContent = 'Izvēlēts fails: ' + file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)';
        previewText.classList.add('text-emerald-700', 'font-bold');
        previewText.classList.remove('text-slate-400');
    }
}
</script>
@endpush

