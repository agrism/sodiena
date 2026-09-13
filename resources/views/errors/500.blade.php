@extends('layouts.app')

@section('title', '500 — Servera kļūda | Šodiena')

@section('content')
<div class="min-h-[70vh] flex items-center justify-center py-16 px-4 sm:px-6 lg:px-8">
    <div class="max-w-xl w-full text-center space-y-8">
        
        <!-- Visual 500 Badge & Icon -->
        <div class="relative mx-auto w-32 h-32 flex items-center justify-center">
            <div class="absolute inset-0 rounded-3xl bg-gradient-to-tr from-rose-500/20 to-amber-500/20 blur-2xl -z-10 animate-pulse"></div>
            <div class="w-28 h-28 rounded-3xl bg-white border border-slate-200/80 shadow-xl flex flex-col items-center justify-center text-slate-800">
                <i data-lucide="alert-triangle" class="w-12 h-12 text-rose-600 mb-1"></i>
                <span class="text-xs font-extrabold uppercase tracking-wider text-slate-400">Kļūda 500</span>
            </div>
        </div>

        <!-- Text Description -->
        <div class="space-y-3">
            <h1 class="text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight">
                Pagaidu sistēmas kļūda
            </h1>
            <p class="text-sm sm:text-base text-slate-600 max-w-md mx-auto leading-relaxed">
                Notikusi neparedzēta sistēmas kļūda. Mūsu komanda jau strādā pie tās novēršanas. Lūdzu, mēģiniet vēlreiz pēc mirkļa.
            </p>
        </div>

        <!-- Action CTAs -->
        <div class="flex flex-wrap items-center justify-center gap-3 pt-2">
            <a 
                href="{{ route('events.index') }}" 
                class="inline-flex items-center gap-2 px-6 py-3.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white text-sm font-bold shadow-md shadow-emerald-600/20 hover:from-emerald-700 hover:to-teal-700 hover:shadow-lg transition-all duration-200">
                <i data-lucide="home" class="w-4 h-4"></i>
                <span>Atgriezties uz sākumlapu</span>
            </a>

            <button 
                type="button" 
                onclick="window.location.reload()" 
                class="inline-flex items-center gap-2 px-5 py-3.5 rounded-xl bg-white text-slate-700 text-sm font-bold border border-slate-200 hover:border-slate-300 hover:bg-slate-50 transition-colors shadow-xs">
                <i data-lucide="refresh-cw" class="w-4 h-4 text-emerald-600"></i>
                <span>Pārlādēt lapu</span>
            </button>
        </div>

    </div>
</div>
@endsection
