<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsurePasswordIsRotated;
use App\Http\Middleware\SanitiseSocketId;
use App\Http\Middleware\TrackPresence;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Exactly one route: the LiveKit webhook. It is here rather than in the
        // web group because a webhook has no session and no CSRF token, and the
        // web group would start and persist a session for every one of them.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // A malformed socket id is scrubbed before any request can reach a
        // broadcast with it.
        $middleware->prependToGroup('web', [
            SanitiseSocketId::class,
        ]);

        // Order matters: a suspended account is ejected before anything else
        // runs, and a temporary password blocks every screen but the rotation.
        $middleware->appendToGroup('web', [
            EnsureAccountIsActive::class,
            EnsurePasswordIsRotated::class,
            TrackPresence::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
