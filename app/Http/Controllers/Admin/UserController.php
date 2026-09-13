<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    /**
     * Display a listing of users with roles and filters.
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->input('search', ''));
        $roleFilter = $request->input('role', 'all');

        $query = User::with('roles')->latest();

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (!empty($roleFilter) && $roleFilter !== 'all') {
            $query->whereHas('roles', function ($q) use ($roleFilter) {
                $q->where('slug', $roleFilter);
            });
        }

        $users = $query->paginate(20)->withQueryString();
        $roles = Role::all();

        $stats = [
            'total' => User::count(),
            'admins' => User::whereHas('roles', fn ($q) => $q->where('slug', Role::ADMIN))->count(),
            'regulars' => User::whereHas('roles', fn ($q) => $q->where('slug', Role::REGULAR))->count(),
        ];

        return view('admin.users.index', compact('users', 'roles', 'stats', 'search', 'roleFilter'));
    }

    /**
     * Store a new user created by administrator.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)],
            'role' => ['required', 'string', Rule::in([Role::ADMIN, Role::REGULAR])],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'email_verified_at' => now(),
        ]);

        $user->assignRole($validated['role']);

        return redirect()->route('admin.users.index')
            ->with('success', __('User :name created successfully with :role role.', [
                'name' => $user->name,
                'role' => $user->localized_role_name,
            ]));
    }

    /**
     * Update user role.
     */
    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in([Role::ADMIN, Role::REGULAR])],
        ]);

        $newRoleSlug = $validated['role'];

        // Prevent current user from demoting themselves from admin
        if ($user->id === Auth::id() && $newRoleSlug !== Role::ADMIN) {
            return back()->with('error', __('You cannot remove the administrator role from your own account.'));
        }

        $user->syncRole($newRoleSlug);

        return redirect()->route('admin.users.index')
            ->with('success', __('User :name role updated to :role.', [
                'name' => $user->name,
                'role' => $user->localized_role_name,
            ]));
    }

    /**
     * Remove the specified user.
     */
    public function destroy(User $user): RedirectResponse
    {
        if ($user->id === Auth::id()) {
            return back()->with('error', __('You cannot delete your own account.'));
        }

        $name = $user->name;
        $user->delete();

        return redirect()->route('admin.users.index')
            ->with('success', __('User :name was deleted.', ['name' => $name]));
    }
}
