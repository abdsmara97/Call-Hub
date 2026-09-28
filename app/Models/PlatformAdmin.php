<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A platform operator. Deliberately NOT a User: different table, different
 * guard, different login page. Operators have no tenant, no rooms, and no
 * place in any workspace — their one capability is the platform panel.
 */
class PlatformAdmin extends Authenticatable
{
    use HasFactory;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}
