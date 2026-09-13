<div id="source-card-{{ $source->id }}" class="bg-white rounded-3xl border border-slate-200/90 p-6 flex flex-col justify-between gap-6 hover:border-slate-300 transition-colors shadow-xs">
    <div>
        <div class="flex items-start justify-between gap-4 mb-3">
            <div>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold {{ $source->is_active ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-500' }}">
                    <span class="w-1.5 h-1.5 rounded-full {{ $source->is_active ? 'bg-emerald-600 animate-pulse' : 'bg-slate-400' }}"></span>
                    {{ $source->is_active ? 'Aktīvs avots' : 'Neaktīvs' }}
                </span>
                <h3 class="text-lg font-extrabold text-slate-900 mt-2">{{ $source->name }}</h3>
                <a href="{{ $source->url }}" target="_blank" rel="noopener noreferrer" class="text-xs text-slate-500 hover:text-emerald-700 font-medium truncate block max-w-xs mt-0.5">
                    {{ $source->url }}
                </a>
            </div>

            <div class="text-right">
                <span class="text-2xl font-black text-emerald-600">{{ $source->events_count ?? $source->events()->count() }}</span>
                <span class="text-[10px] text-slate-400 font-bold block uppercase">Notikumi</span>
            </div>
        </div>

        <div class="mt-4 p-3.5 bg-slate-50 rounded-xl border border-slate-200/80 text-xs text-slate-600 space-y-1.5">
            <div class="flex justify-between">
                <span>Pēdējoreiz parsēts:</span>
                <strong class="text-slate-800">{{ $source->last_scraped_at ? $source->last_scraped_at->diffForHumans() : 'Vēl nav palaists' }}</strong>
            </div>
            <div class="flex justify-between">
                <span>Statuss:</span>
                @if($source->last_status === 'success')
                    <span class="text-emerald-700 font-bold flex items-center gap-1">
                        <i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-600"></i> Veiksmīgs
                    </span>
                @elseif($source->last_status === 'failed')
                    <span class="text-rose-600 font-bold flex items-center gap-1">
                        <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i> Kļūda
                    </span>
                @else
                    <span class="text-slate-500">Gaidīšanas režīmā</span>
                @endif
            </div>
            @if($source->last_error)
                <div class="text-rose-600 text-[11px] pt-1 border-t border-slate-200">
                    {{ Str::limit($source->last_error, 100) }}
                </div>
            @endif
        </div>
    </div>

    <!-- Actions -->
    <div class="pt-4 border-t border-slate-100 flex items-center justify-between gap-3">
        <form 
            hx-post="{{ route('events.scrape', $source->id) }}"
            hx-target="#source-card-{{ $source->id }}"
            hx-swap="outerHTML"
            class="w-full">
            @csrf
            <button 
                type="submit" 
                class="w-full py-2.5 px-4 rounded-xl bg-slate-100 hover:bg-emerald-600 hover:text-white text-slate-800 font-bold text-xs flex items-center justify-center gap-2 border border-slate-200 transition-all duration-200 cursor-pointer">
                <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i>
                Palaist parsēšanu tagad
            </button>
        </form>
    </div>
</div>
