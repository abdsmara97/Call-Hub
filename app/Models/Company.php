<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Company extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'name', 'slug'];

    public function administrations(): HasMany
    {
        return $this->hasMany(Administration::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /** The auto-created, undeletable room every employee of this company joins. */
    public function systemRoom(): HasOne
    {
        return $this->hasOne(Room::class)
            ->where('is_system', true)
            ->whereNull('administration_id');
    }
}
