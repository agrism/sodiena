@extends('layouts.app')

@section('title', __('Create Account') . ' — Šodiena')

@section('content')
<div class="min-h-[calc(100vh-16rem)] flex flex-col justify-center py-12 sm:px-6 lg:px-8">
    <div class="sm:mx-auto sm:w-full sm:max-w-md">
        <div class="flex justify-center">
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-emerald-600 to-teal-500 flex items-center justify-center text-white shadow-lg shadow-emerald-600/25">
                <i data-lucide="user-plus" class="w-6 h-6 stroke-[2.5]"></i>
            </div>
        </div>
        <h2 class="mt-4 text-center text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">
            {{ __('Create a new account') }}
        </h2>
        <p class="mt-2 text-center text-xs sm:text-sm text-slate-500">
            {{ __('Already have an account?') }}
            <a href="{{ route('login') }}" class="font-bold text-emerald-700 hover:text-emerald-800 transition-colors">
                {{ __('Sign In') }}
            </a>
        </p>
    </div>

    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md px-4 sm:px-0">
        <div class="bg-white py-8 px-6 sm:px-10 shadow-sm border border-slate-200/90 rounded-3xl">
            <form action="{{ route('register') }}" method="POST" class="space-y-5">
                @csrf

                <!-- Name Field -->
                <div>
                    <label for="name" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                        {{ __('Full Name') }}
                    </label>
                    <div class="relative rounded-xl">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <i data-lucide="user" class="w-4 h-4"></i>
                        </div>
                        <input 
                            type="text" 
                            name="name" 
                            id="name" 
                            value="{{ old('name') }}" 
                            required 
                            autofocus 
                            placeholder="Jānis Bērziņš"
                            class="block w-full pl-10 pr-4 py-3 bg-slate-50 border @error('name') border-rose-400 focus:ring-rose-400 @else border-slate-200 focus:ring-emerald-500 @enderror rounded-xl text-sm text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:bg-white transition-all">
                    </div>
                    @error('name')
                        <p class="mt-1.5 text-xs font-bold text-rose-600 flex items-center gap-1">
                            <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i>
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <!-- Email Field -->
                <div>
                    <label for="email" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                        {{ __('Email Address') }}
                    </label>
                    <div class="relative rounded-xl">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <i data-lucide="mail" class="w-4 h-4"></i>
                        </div>
                        <input 
                            type="email" 
                            name="email" 
                            id="email" 
                            value="{{ old('email') }}" 
                            required 
                            placeholder="vards@piemers.lv"
                            class="block w-full pl-10 pr-4 py-3 bg-slate-50 border @error('email') border-rose-400 focus:ring-rose-400 @else border-slate-200 focus:ring-emerald-500 @enderror rounded-xl text-sm text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:bg-white transition-all">
                    </div>
                    @error('email')
                        <p class="mt-1.5 text-xs font-bold text-rose-600 flex items-center gap-1">
                            <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i>
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <!-- Password Field -->
                <div>
                    <label for="password" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                        {{ __('Password') }}
                    </label>
                    <div class="relative rounded-xl">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <i data-lucide="key" class="w-4 h-4"></i>
                        </div>
                        <input 
                            type="password" 
                            name="password" 
                            id="password" 
                            required 
                            placeholder="Vismaz 8 simboli"
                            class="block w-full pl-10 pr-4 py-3 bg-slate-50 border @error('password') border-rose-400 focus:ring-rose-400 @else border-slate-200 focus:ring-emerald-500 @enderror rounded-xl text-sm text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:bg-white transition-all">
                    </div>
                    @error('password')
                        <p class="mt-1.5 text-xs font-bold text-rose-600 flex items-center gap-1">
                            <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i>
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <!-- Confirm Password Field -->
                <div>
                    <label for="password_confirmation" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                        {{ __('Confirm Password') }}
                    </label>
                    <div class="relative rounded-xl">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <i data-lucide="check-circle" class="w-4 h-4"></i>
                        </div>
                        <input 
                            type="password" 
                            name="password_confirmation" 
                            id="password_confirmation" 
                            required 
                            placeholder="Atkārtojiet paroli"
                            class="block w-full pl-10 pr-4 py-3 bg-slate-50 border border-slate-200 focus:ring-emerald-500 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:bg-white transition-all">
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="pt-2">
                    <button 
                        type="submit" 
                        class="w-full py-3.5 px-4 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-extrabold text-sm flex items-center justify-center gap-2 hover:from-emerald-700 hover:to-teal-700 transition-all shadow-md shadow-emerald-600/20 active:scale-[0.99]">
                        <i data-lucide="user-plus" class="w-4 h-4"></i>
                        {{ __('Create Account') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
