<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Cashier\Billable;

/**
 * The paying customer. Sits above the Company/Administration org chart: those
 * describe structure *inside* one customer, a Tenant is the boundary *between*
 * customers. Every tenant-owned row carries tenant_id, and the BelongsToTenant
 * scope keeps one customer's queries from ever seeing another's rows.
 *
 * Billing hangs here too — seats are a subscription on the tenant, and the
 * emergency system is the priced add-on.
 */
class Tenant extends Model
{
    use Billable, HasFactory;

    /** Subscription names. Seats are the core tier; emergency is the upgrade. */
    public const SUBSCRIPTION_SEATS = 'default';

    public const SUBSCRIPTION_EMERGENCY = 'emergency';

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

    /**
     * May this workspace use the emergency system?
     *
     * While billing is not enforced (no Stripe configured — development,
     * tests, and the grandfathered single-tenant install) everything is
     * allowed; the flag is what turns the gate on, not the absence of a
     * subscription.
     */
    public function hasEmergencyAddon(): bool
    {
        if (! config('hub.billing.enforced')) {
            return true;
        }

        return $this->subscribed(self::SUBSCRIPTION_EMERGENCY);
    }

    /** Seats currently occupied — what the seat subscription should bill for. */
    public function seatsInUse(): int
    {
        return $this->users()->count();
    }
}
