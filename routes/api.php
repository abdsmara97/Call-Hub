<?php

use App\Http\Controllers\LiveKitWebhookController;
use App\Http\Middleware\VerifyLiveKitWebhook;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| One route, and it exists because of what the `web` group would do to it.
|
| A webhook has no session, no cookie and no CSRF token. Putting this in
| routes/web.php with a CSRF exclusion would still run the whole web stack
| against it: a session started and written per event — hundreds of useless
| session rows during a company all-hands — plus TrackPresence and both auth
| middlewares executing against a null user every single time.
|
| Laravel's `api` group is SubstituteBindings and nothing else. A webhook is
| stateless, and this is the stateless group.
|
| The trap worth knowing: this group applies NO throttle by default in Laravel
| 11 and 12, so the limiter below is named explicitly rather than assumed.
|
*/

Route::post('webhooks/livekit', LiveKitWebhookController::class)
    ->middleware(['throttle:huddle-webhook', VerifyLiveKitWebhook::class])
    ->name('webhooks.livekit');
