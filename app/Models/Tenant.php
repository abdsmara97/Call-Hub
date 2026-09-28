<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The paying customer. Sits above the Company/Administration org chart: those
 * describe structure *inside* one customer, a Tenant is the boundary *between*
 * customers. Every tenant-owned row carries tenant_id, and the BelongsToTenant
 * scope keeps one customer's queries from ever seeing another's rows.
 */
class Tenant extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug'];

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }
}
