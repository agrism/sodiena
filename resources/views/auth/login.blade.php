@extends('layouts.app')

@section('title', __('Sign In') . ' — Šodiena')

@section('content')
<div class="min-h-[calc(100vh-16rem)] flex flex-col justify-center py-12 sm:px-6 lg:px-8">
    <div class="sm:mx-auto sm:w-full sm:max-w-md">
        <div class="flex justify-center">
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-emerald-600 to-teal-500 flex items-center justify-center text-white shadow-lg shadow-emerald-600/25">
                <i data-lucide="lock" class="w-6 h-6 stroke-[2.5]"></i>
            </div>
        </div>
        <h2 class="mt-4 text-center text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">
            {{ __('Sign in to your account') }}
        </h2>
        <p class="mt-2 text-center text-xs sm:text-sm text-slate-500">
            {{ __('Or') }}
            <a href="{{ route('register') }}" class="font-bold text-emerald-700 hover:text-emerald-800 transition-colors">
                {{ __('create a new account') }}
            </a>
        </p>
    </div>

    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md px-4 sm:px-0">
        <div class="bg-white py-8 px-6 sm:px-10 shadow-sm border border-slate-200/90 rounded-3xl">
            <form action="{{ route('login') }}" method="POST" class="space-y-5">
                @csrf

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
                            autofocus 
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
                            placeholder="••••••••"
                            class="block w-full pl-10 pr-4 py-3 bg-slate-50 border @error('password') border-rose-400 focus:ring-rose-400 @else border-slate-200 focus:ring-emerald-500 @enderror rounded-xl text-sm text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:bg-white transition-all">
                    </div>
                    @error('password')
                        <p class="mt-1.5 text-xs font-bold text-rose-600 flex items-center gap-1">
                            <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i>
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <!-- Remember Me -->
                <div class="flex items-center justify-between">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input 
                            type="checkbox" 
                            name="remember" 
                            id="remember"
                            class="w-4 h-4 text-emerald-600 border-slate-300 rounded focus:ring-emerald-500">
                        <span class="text-xs font-semibold text-slate-600">{{ __('Remember me') }}</span>
                    </label>
                </div>

                <!-- Submit Button -->
                <div>
                    <button 
                        type="submit" 
                        class="w-full py-3.5 px-4 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-extrabold text-sm flex items-center justify-center gap-2 hover:from-emerald-700 hover:to-teal-700 transition-all shadow-md shadow-emerald-600/20 active:scale-[0.99]">
                        <i data-lucide="log-in" class="w-4 h-4"></i>
                        {{ __('Sign In') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
