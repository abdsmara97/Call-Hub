<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Administration extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'company_id', 'name', 'slug'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function systemRoom(): HasOne
    {
        return $this->hasOne(Room::class)->where('is_system', true);
    }
}
