<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_INVESTIGATOR = 'investigator';
    public const ROLE_VIEWER = 'viewer';
    public const ROLES = [self::ROLE_ADMIN, self::ROLE_INVESTIGATOR, self::ROLE_VIEWER];

    protected $fillable = ['name', 'email', 'password', 'role', 'is_active'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function passkeys(): HasMany
    {
        return $this->hasMany(WebauthnCredential::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'is_active' => $this->is_active,
            'passkeys_count' => $this->passkeys()->count(),
        ];
    }
}
