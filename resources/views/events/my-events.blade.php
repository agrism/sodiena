@extends('layouts.app')

@section('title', __('My Events') . ' — Šodiena')

@section('content')
<div class="py-8 sm:py-12 px-3 sm:px-6 lg:px-8 max-w-6xl mx-auto">
    <!-- Header with Action -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">{{ __('My Events') }}</h1>
            <p class="mt-1 text-slate-500 text-xs sm:text-sm">
                {{ __('Manage events you have added to the Šodiena.lv calendar.') }}
            </p>
        </div>

        <a href="{{ route('events.create') }}" class="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-2xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white font-extrabold text-xs sm:text-sm shadow-md shadow-emerald-600/20 active:scale-[0.99] transition-all">
            <i data-lucide="plus-circle" class="w-4 h-4"></i>
            <span>{{ __('Add New Event') }}</span>
        </a>
    </div>

    @if($events->isEmpty())
        <!-- Empty State -->
        <div class="bg-white rounded-3xl border border-slate-200/90 p-8 sm:p-16 text-center shadow-xs">
            <div class="w-16 h-16 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center mx-auto mb-4 border border-emerald-100">
                <i data-lucide="calendar" class="w-8 h-8"></i>
            </div>
            <h2 class="text-lg sm:text-xl font-bold text-slate-900 mb-2">{{ __('You have not added any events yet') }}</h2>
            <p class="text-slate-500 text-xs sm:text-sm max-w-md mx-auto mb-6">
                {{ __('Share concerts, festivals, exhibitions or other events so they reach thousands of visitors across Latvia.') }}
            </p>
            <a href="{{ route('events.create') }}" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold text-sm shadow-xs transition-all">
                <i data-lucide="plus-circle" class="w-4 h-4"></i>
                <span>{{ __('Add your first event') }}</span>
            </a>
        </div>
    @else
        <!-- Events Grid / List -->
        <div class="space-y-4">
            @foreach($events as $event)
                <div class="bg-white rounded-2xl border border-slate-200/90 p-4 sm:p-6 shadow-xs hover:shadow-md transition-shadow flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <!-- Left: Image + Info -->
                    <div class="flex items-start gap-4">
                        <img 
                            src="{{ $event->display_image_url }}" 
                            alt="{{ $event->title }}" 
                            class="w-16 h-16 sm:w-20 sm:h-20 object-cover rounded-xl shrink-0 border border-slate-100 bg-slate-50">
                        
                        <div class="space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                @if($event->isPublished())
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-100 text-emerald-800 border border-emerald-200">
                                        {{ __('Published') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-100 text-amber-800 border border-amber-200">
                                        {{ __('Draft') }}
                                    </span>
                                @endif

                                @if($event->categories->first())
                                    <span class="text-[11px] font-bold text-slate-500">
                                        {{ $event->categories->first()->name }}
                                    </span>
                                @endif
                            </div>

                            <h2 class="text-sm sm:text-base font-extrabold text-slate-900 leading-snug line-clamp-1">
                                <a href="{{ $event->localized_url }}" class="hover:text-emerald-700 transition-colors">
                                    {{ $event->title }}
                                </a>
                            </h2>

                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500 font-medium">
                                <span class="flex items-center gap-1 text-emerald-700 font-bold">
                                    <i data-lucide="calendar" class="w-3.5 h-3.5"></i>
                                    {{ $event->start_at ? $event->start_at->format('d.m.Y H:i') : '' }}
                                </span>

                                @if($event->location)
                                    <span class="flex items-center gap-1">
                                        <i data-lucide="map-pin" class="w-3.5 h-3.5 text-slate-400"></i>
                                        {{ $event->location->name }}, {{ $event->location->city }}
                                    </span>
                                @endif

                                <span>
                                    {{ $event->is_free ? __('Free') : ($event->price_min ? 'no €' . number_format($event->price_min, 2) : '') }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Actions -->
                    <div class="flex items-center gap-2 self-end sm:self-center shrink-0 pt-2 sm:pt-0 border-t sm:border-t-0 border-slate-100 w-full sm:w-auto justify-end">
                        <a href="{{ $event->localized_url }}" class="px-3 py-1.5 rounded-xl border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold text-xs inline-flex items-center gap-1.5 transition-colors" title="{{ __('View') }}">
                            <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                            <span class="hidden sm:inline">{{ __('View') }}</span>
                        </a>

                        <a href="{{ route('events.edit', $event) }}" class="px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold text-xs inline-flex items-center gap-1.5 transition-colors" title="{{ __('Edit') }}">
                            <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                            <span class="hidden sm:inline">{{ __('Edit') }}</span>
                        </a>

                        <form action="{{ route('events.destroy', $event) }}" method="POST" onsubmit="return confirm('{{ __('Are you sure you want to delete this event?') }}');" class="inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="p-1.5 sm:px-3 sm:py-1.5 rounded-xl text-rose-600 hover:bg-rose-50 border border-transparent hover:border-rose-200 font-bold text-xs inline-flex items-center gap-1.5 transition-colors cursor-pointer" title="{{ __('Delete') }}">
                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                <span class="hidden sm:inline">{{ __('Delete') }}</span>
                            </button>
                        </form>
                    </div>
                </div>
            @endforeach

            <!-- Pagination -->
            <div class="pt-4">
                {{ $events->links() }}
            </div>
        </div>
    @endif
</div>
@endsection
