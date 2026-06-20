<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    /** Role-based access control — this user is an administrator. */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** True when the user holds any of the given roles (admin is allowed everywhere). */
    public function hasRole(string ...$roles): bool
    {
        return $this->role === 'admin' || in_array($this->role, $roles, true);
    }

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }
}
