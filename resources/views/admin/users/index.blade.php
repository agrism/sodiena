@extends('layouts.app')

@section('title', __('User & Permissions Registry') . ' — Šodiena')

@section('content')
<div class="max-w-7xl xl:max-w-[1400px] 2xl:max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12 space-y-8">
    
    <!-- Header & Action Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md text-xs font-bold bg-purple-50 text-purple-700 border border-purple-200">
                    <i data-lucide="shield-check" class="w-3.5 h-3.5"></i>
                    Admin Panel
                </span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight mt-1.5">
                {{ __('User & Permissions Registry') }}
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 mt-0.5">
                {{ __('Manage system users, administrator access, and account permissions.') }}
            </p>
        </div>

        <button 
            type="button"
            onclick="document.getElementById('createUserModal').classList.remove('hidden')"
            class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-extrabold text-xs sm:text-sm hover:from-emerald-700 hover:to-teal-700 transition-all shadow-md shadow-emerald-600/20">
            <i data-lucide="user-plus" class="w-4 h-4"></i>
            {{ __('Add New User') }}
        </button>
    </div>

    <!-- Stats Overview Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6">
        <div class="bg-white p-5 rounded-2xl border border-slate-200/90 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">{{ __('Total Users') }}</p>
                <p class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-1">{{ $stats['total'] }}</p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-slate-100 text-slate-700 flex items-center justify-center border border-slate-200">
                <i data-lucide="users" class="w-6 h-6"></i>
            </div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200/90 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-purple-600 uppercase tracking-wider">{{ __('Administrators') }}</p>
                <p class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-1">{{ $stats['admins'] }}</p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-purple-50 text-purple-700 flex items-center justify-center border border-purple-200">
                <i data-lucide="shield-check" class="w-6 h-6"></i>
            </div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200/90 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-emerald-600 uppercase tracking-wider">{{ __('Regular Users') }}</p>
                <p class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-1">{{ $stats['regulars'] }}</p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-700 flex items-center justify-center border border-emerald-200">
                <i data-lucide="user" class="w-6 h-6"></i>
            </div>
        </div>
    </div>

    <!-- Filter & Search Bar -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200/90 shadow-xs">
        <form method="GET" action="{{ route('admin.users.index') }}" class="flex flex-col sm:flex-row gap-3">
            <div class="relative flex-grow">
                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                    <i data-lucide="search" class="w-4 h-4"></i>
                </div>
                <input 
                    type="text" 
                    name="search" 
                    value="{{ $search }}" 
                    placeholder="{{ __('Search by name or email...') }}" 
                    class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:bg-white transition-all">
            </div>

            <div class="flex items-center gap-2">
                <select 
                    name="role" 
                    onchange="this.form.submit()"
                    class="px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm font-semibold text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    <option value="all" {{ $roleFilter === 'all' ? 'selected' : '' }}>{{ __('All Roles') }}</option>
                    <option value="admin" {{ $roleFilter === 'admin' ? 'selected' : '' }}>{{ __('Administrators') }}</option>
                    <option value="regular" {{ $roleFilter === 'regular' ? 'selected' : '' }}>{{ __('Regular Users') }}</option>
                </select>

                <button 
                    type="submit" 
                    class="px-4 py-2.5 rounded-xl bg-slate-900 text-white text-xs sm:text-sm font-bold hover:bg-slate-800 transition-all">
                    {{ __('Filter') }}
                </button>

                @if($search || $roleFilter !== 'all')
                    <a 
                        href="{{ route('admin.users.index') }}" 
                        class="px-3 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs sm:text-sm font-semibold transition-all">
                        {{ __('Reset') }}
                    </a>
                @endif
            </div>
        </form>
    </div>

    <!-- Users Table -->
    <div class="bg-white rounded-3xl border border-slate-200/90 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-600">
                <thead class="bg-slate-50/80 border-b border-slate-200/80 text-[11px] uppercase tracking-wider font-extrabold text-slate-400">
                    <tr>
                        <th class="py-3.5 px-6">{{ __('User') }}</th>
                        <th class="py-3.5 px-6">{{ __('Role / Permissions') }}</th>
                        <th class="py-3.5 px-6">{{ __('Change Role') }}</th>
                        <th class="py-3.5 px-6">{{ __('Registered At') }}</th>
                        <th class="py-3.5 px-6 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    @forelse($users as $user)
                        <tr class="hover:bg-slate-50/60 transition-colors">
                            <!-- User Info -->
                            <td class="py-4 px-6">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-xl {{ $user->isAdmin() ? 'bg-purple-100 text-purple-700 border-purple-200' : 'bg-emerald-100 text-emerald-700 border-emerald-200' }} border font-extrabold text-xs flex items-center justify-center shrink-0">
                                        {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                                    </div>
                                    <div>
                                        <p class="font-bold text-slate-900 flex items-center gap-1.5">
                                            {{ $user->name }}
                                            @if($user->id === auth()->id())
                                                <span class="text-[10px] font-extrabold uppercase tracking-wide bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded border border-slate-200">
                                                    {{ __('You') }}
                                                </span>
                                            @endif
                                        </p>
                                        <p class="text-xs text-slate-500">{{ $user->email }}</p>
                                    </div>
                                </div>
                            </td>

                            <!-- Role Badge -->
                            <td class="py-4 px-6">
                                @if($user->isAdmin())
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-bold bg-purple-50 text-purple-700 border border-purple-200">
                                        <i data-lucide="shield-check" class="w-3.5 h-3.5"></i>
                                        {{ $user->localized_role_name }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        <i data-lucide="user" class="w-3.5 h-3.5"></i>
                                        {{ $user->localized_role_name }}
                                    </span>
                                @endif
                            </td>

                            <!-- Change Role Form -->
                            <td class="py-4 px-6">
                                <form action="{{ route('admin.users.role', $user) }}" method="POST" class="inline-flex items-center gap-2">
                                    @csrf
                                    <select 
                                        name="role" 
                                        onchange="this.form.submit()" 
                                        {{ $user->id === auth()->id() ? 'disabled' : '' }}
                                        class="px-2.5 py-1 bg-slate-50 border border-slate-200 rounded-lg text-xs font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 disabled:opacity-50 disabled:cursor-not-allowed">
                                        <option value="regular" {{ $user->isRegular() ? 'selected' : '' }}>{{ __('Regular') }}</option>
                                        <option value="admin" {{ $user->isAdmin() ? 'selected' : '' }}>{{ __('Admin') }}</option>
                                    </select>
                                </form>
                            </td>

                            <!-- Registered Date -->
                            <td class="py-4 px-6 text-xs text-slate-500">
                                {{ $user->created_at ? $user->created_at->format('d.m.Y H:i') : '-' }}
                            </td>

                            <!-- Actions -->
                            <td class="py-4 px-6 text-right">
                                @if($user->id !== auth()->id())
                                    <form action="{{ route('admin.users.destroy', $user) }}" method="POST" onsubmit="return confirm('{{ __('Are you sure you want to delete user :name?', ['name' => $user->name]) }}');" class="inline-block">
                                        @csrf
                                        @method('DELETE')
                                        <button 
                                            type="submit" 
                                            title="{{ __('Delete User') }}"
                                            class="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition-colors">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        </button>
                                    </form>
                                @else
                                    <span class="text-xs text-slate-400 italic">{{ __('Active session') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-12 text-center text-slate-400">
                                <i data-lucide="users" class="w-8 h-8 mx-auto stroke-1 text-slate-300"></i>
                                <p class="mt-2 text-sm font-semibold">{{ __('No users found matching your search.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($users->hasPages())
            <div class="p-4 border-t border-slate-100">
                {{ $users->links() }}
            </div>
        @endif
    </div>

</div>

<!-- Create User Modal -->
<div id="createUserModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-950/50 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl max-w-md w-full p-6 sm:p-8 shadow-2xl border border-slate-200 relative animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div class="flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-700 flex items-center justify-center border border-emerald-200">
                    <i data-lucide="user-plus" class="w-5 h-5"></i>
                </div>
                <h3 class="font-extrabold text-slate-900 text-base sm:text-lg">
                    {{ __('Create New User') }}
                </h3>
            </div>
            <button 
                type="button" 
                onclick="document.getElementById('createUserModal').classList.add('hidden')"
                class="p-1.5 rounded-xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-all">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>

        <form action="{{ route('admin.users.store') }}" method="POST" class="mt-5 space-y-4">
            @csrf

            <!-- Name -->
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                    {{ __('Full Name') }}
                </label>
                <input 
                    type="text" 
                    name="name" 
                    required 
                    placeholder="Vārds Uzvārds"
                    class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
            </div>

            <!-- Email -->
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                    {{ __('Email Address') }}
                </label>
                <input 
                    type="email" 
                    name="email" 
                    required 
                    placeholder="lietotajs@sodiena.lv"
                    class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
            </div>

            <!-- Password -->
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                    {{ __('Password') }}
                </label>
                <input 
                    type="password" 
                    name="password" 
                    required 
                    placeholder="Vismaz 8 simboli"
                    class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
            </div>

            <!-- Role Selection -->
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                    {{ __('Role & Permissions') }}
                </label>
                <select 
                    name="role" 
                    required 
                    class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    <option value="regular">{{ __('Regular User') }}</option>
                    <option value="admin">{{ __('Administrator (Full access)') }}</option>
                </select>
            </div>

            <div class="pt-3 flex items-center justify-end gap-2">
                <button 
                    type="button" 
                    onclick="document.getElementById('createUserModal').classList.add('hidden')"
                    class="px-4 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold transition-all">
                    {{ __('Cancel') }}
                </button>
                <button 
                    type="submit" 
                    class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white text-xs font-extrabold hover:from-emerald-700 hover:to-teal-700 transition-all shadow-md shadow-emerald-600/20">
                    {{ __('Create User') }}
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
