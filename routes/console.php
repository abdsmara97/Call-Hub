<?php

use Illuminate\Support\Facades\Schedule;

/*
 * The escalation backstop. Delayed jobs do the real work; this catches anything
 * that slipped through so an unacknowledged emergency is never silently dropped.
 */
Schedule::command('emergency:sweep')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * The huddle backstop, and it is not optional.
 *
 * Webhooks are the fast path and they are lossy. Without this, a dropped
 * participant_left leaves a "Call in progress" banner nobody can clear, a cache
 * flush loses every roster, and — the one that matters — a suspended account
 * stays inside a live huddle indefinitely, because there is no next HTTP request
 * for EnsureAccountIsActive to run on.
 */
Schedule::command('huddles:reconcile')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Sessions and expired temporary data.
Schedule::command('auth:clear-resets')->daily();

/*
 * Housekeeping. Horizon's metrics graph is driven by snapshots, and both the
 * failed-job table and the Horizon key space grow without bound otherwise.
 */
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// A fortnight is long enough to investigate a failure, short enough to bound
// the table. Emergency delivery failures are separately visible in the log.
Schedule::command('queue:prune-failed --hours=336')->daily();

Schedule::command('queue:prune-batches --hours=336')->daily();
