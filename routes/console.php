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

// Sessions and expired temporary data.
Schedule::command('auth:clear-resets')->daily();
