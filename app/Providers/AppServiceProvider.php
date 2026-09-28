<?php

namespace App\Providers;

use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * The tenant boundary for the current request or job. Scoped, not a
         * plain singleton, so long-lived workers reset it between requests
         * instead of leaking one tenant's context into the next.
         */
        $this->app->scoped(TenantContext::class);

        // The paying customer is the Tenant, not an individual User.
        \Laravel\Cashier\Cashier::useCustomerModel(\App\Models\Tenant::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * The platform panel. Not a spatie permission on purpose: tenant
         * admins manage their workspace, the platform operator manages
         * workspaces themselves, and the flag is deliberately outside
         * $fillable so no import or form can ever grant it.
         */
        \Illuminate\Support\Facades\Gate::define(
            'manage-platform',
            fn (\App\Models\User $user) => $user->is_super_admin
        );

        /*
         * Call signalling and TURN credential minting.
         *
         * Unlike the emergency limiter this is not product doctrine and is not
         * administrator-tunable — it exists only so the credential endpoint
         * cannot be drained for free relay bandwidth. Ten a minute absorbs a
         * redial after no answer plus a mid-call ICE restart.
         */
        RateLimiter::for('calls', fn (Request $request) => Limit::perMinute(10)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        /*
         * Joining a huddle.
         *
         * Separate from 'calls' above on purpose: a join storm in a 300-person
         * room must not exhaust the allowance somebody needs to place a 1:1
         * call, and the two want tuning independently. Twelve a minute covers
         * rejoining after a dropped connection and hopping between rooms.
         */
        RateLimiter::for('huddles', fn (Request $request) => Limit::perMinute(12)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        /*
         * LiveKit webhooks.
         *
         * Deliberately generous. A company all-hands legitimately produces
         * hundreds of events a minute, and dropping them would be far worse than
         * the flood this stops — a dropped participant_left is a banner that
         * never clears. The real authentication here is the signature, not this.
         */
        RateLimiter::for('huddle-webhook', fn (Request $request) => Limit::perMinute(600)
            ->by($request->ip()));
    }
}
