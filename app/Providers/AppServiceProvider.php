<?php

namespace App\Providers;

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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
