<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'max:2048'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
        ]);

        $request->user()->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
        );

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'max:2048'],
        ]);

        $request->user()->deletePushSubscription($validated['endpoint']);

        return response()->json(['ok' => true]);
    }
}
