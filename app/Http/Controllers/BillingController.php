<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The two hops that must leave the app: Stripe Checkout for starting a
 * subscription, Stripe's Billing Portal for everything after. Both are
 * plain redirects, so they live on a controller rather than in Livewire.
 */
class BillingController extends Controller
{
    public function checkout(Request $request, string $plan): RedirectResponse
    {
        $this->authorize('manage', User::class);

        abort_unless(config('hub.billing.enforced'), 404);

        $tenant = $this->tenant($request);

        [$name, $priceId] = match ($plan) {
            'seats' => [Tenant::SUBSCRIPTION_SEATS, config('hub.billing.seat_price_id')],
            'emergency' => [Tenant::SUBSCRIPTION_EMERGENCY, config('hub.billing.emergency_price_id')],
            default => abort(404),
        };

        abort_if(blank($priceId), 404);

        $builder = $tenant->newSubscription($name, $priceId);

        if ($name === Tenant::SUBSCRIPTION_SEATS) {
            $builder->quantity($tenant->seatsInUse());
        }

        return redirect($builder->checkout([
            'success_url' => route('admin.billing'),
            'cancel_url' => route('admin.billing'),
        ])->url);
    }

    public function portal(Request $request): RedirectResponse
    {
        $this->authorize('manage', User::class);

        abort_unless(config('hub.billing.enforced'), 404);

        return $this->tenant($request)->redirectToBillingPortal(route('admin.billing'));
    }

    private function tenant(Request $request): Tenant
    {
        return Tenant::query()->findOrFail($request->user()->tenant_id);
    }
}
