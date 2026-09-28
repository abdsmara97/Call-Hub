<?php

namespace App\Http\Middleware;

use App\Services\HuddleTokens;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Proves a webhook really came from our LiveKit before the controller sees it.
 *
 * Verification lives in middleware so the controller can be about semantics —
 * the same separation SanitiseSocketId already uses. This route is
 * unauthenticated by necessity: LiveKit has no session and no CSRF token, and
 * this signature is the entire authentication story for the endpoint.
 *
 * LiveKit sends the token in `Authorization` with NO "Bearer" prefix, and puts a
 * base64 SHA-256 of the raw request body in a `sha256` claim.
 *
 * Three things this gets right that a hand-rolled version usually does not:
 *
 *  - The algorithm is pinned by the Key object rather than read from the JWT
 *    header, so `alg: none` and HS256/RS256 confusion are both refused.
 *  - The body hash is compared with hash_equals. Without that check, a valid
 *    token captured from any other event could be replayed carrying an arbitrary
 *    body — which for this endpoint means forging a huddle roster.
 *  - exp and nbf are enforced by the library, so a captured token expires.
 */
class VerifyLiveKitWebhook
{
    public function __construct(private HuddleTokens $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->tokens->isConfigured()) {
            abort(404);
        }

        $token = trim((string) $request->header('Authorization'));

        // Tolerated but not expected: LiveKit sends the bare token.
        if (str_starts_with(strtolower($token), 'bearer ')) {
            $token = substr($token, 7);
        }

        if ($token === '') {
            abort(401);
        }

        try {
            $claims = $this->tokens->verify($token);
        } catch (Throwable) {
            // Expired, wrong secret, wrong algorithm, malformed — all one answer.
            abort(401);
        }

        abort_unless(
            ($claims->iss ?? null) === config('services.livekit.key'),
            401
        );

        $expected = base64_encode(hash('sha256', $request->getContent(), true));

        abort_unless(
            isset($claims->sha256) && hash_equals($expected, (string) $claims->sha256),
            401
        );

        return $next($request);
    }
}
