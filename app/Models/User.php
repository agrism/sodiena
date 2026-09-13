<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function roles(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function hasRole(string|array $roles): bool
    {
        $roles = is_array($roles) ? $roles : [$roles];
        return $this->roles->contains(fn (Role $role) => in_array($role->slug, $roles, true));
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(Role::ADMIN);
    }

    public function isRegular(): bool
    {
        return $this->hasRole(Role::REGULAR);
    }

    public function assignRole(string|Role $role): void
    {
        $roleModel = $role instanceof Role ? $role : Role::where('slug', $role)->first();
        if ($roleModel && !$this->roles()->where('roles.id', $roleModel->id)->exists()) {
            $this->roles()->attach($roleModel->id);
            $this->load('roles');
        }
    }

    public function removeRole(string|Role $role): void
    {
        $roleModel = $role instanceof Role ? $role : Role::where('slug', $role)->first();
        if ($roleModel) {
            $this->roles()->detach($roleModel->id);
            $this->load('roles');
        }
    }

    public function syncRole(string|Role $role): void
    {
        $roleModel = $role instanceof Role ? $role : Role::where('slug', $role)->first();
        if ($roleModel) {
            $this->roles()->sync([$roleModel->id]);
            $this->load('roles');
        }
    }

    public function getPrimaryRoleAttribute(): ?Role
    {
        if ($this->roles->isEmpty()) {
            return null;
        }

        // Prefer Admin if present
        $admin = $this->roles->firstWhere('slug', Role::ADMIN);
        return $admin ?: $this->roles->first();
    }

    public function getLocalizedRoleNameAttribute(): string
    {
        $role = $this->primary_role;
        return $role ? $role->localized_name : __('Regular');
    }
}
