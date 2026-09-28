<?php

namespace App\Livewire\Admin;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The workspace's plan at a glance: seat count, subscription state, and the
 * emergency add-on. All mutation happens on Stripe's side — this screen only
 * reads, plus links out to Checkout and the Billing Portal.
 */
#[Layout('layouts.app')]
class Billing extends Component
{
    public function mount(): void
    {
        Gate::authorize('manage', User::class);
    }

    public function render()
    {
        $tenant = Tenant::query()->findOrFail(auth()->user()->tenant_id);
        $enforced = (bool) config('hub.billing.enforced');

        return view('livewire.admin.billing', [
            'tenant' => $tenant,
            'enforced' => $enforced,
            'seatsInUse' => $tenant->seatsInUse(),
            'seatsSubscribed' => $enforced && $tenant->subscribed(Tenant::SUBSCRIPTION_SEATS),
            'emergencyActive' => $tenant->hasEmergencyAddon(),
            'emergencySubscribed' => $enforced && $tenant->subscribed(Tenant::SUBSCRIPTION_EMERGENCY),
        ])->title('Billing');
    }
}
