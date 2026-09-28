<?php

namespace App\Services;

use App\Exceptions\CallCredentialsUnavailable;
use Illuminate\Support\Facades\Http;

/**
 * Mints the ICE server list a browser needs to place a call.
 *
 * STUN alone gets roughly four calls in five through ordinary NAT. The rest —
 * symmetric NAT, strict corporate firewalls, which is most of this hub's users
 * on an office network — need a TURN relay, and a relay costs bandwidth, which
 * is why its credentials are short-lived and minted per call rather than shipped
 * in the front-end bundle.
 */
class CallCredentials
{
    /** Free, unlimited, and useful even when TURN is not configured. */
    private const FALLBACK_STUN = 'stun:stun.cloudflare.com:3478';

    /**
     * @return list<array{urls: list<string>|string, username?: string, credential?: string}>
     *
     * @throws CallCredentialsUnavailable
     */
    public function mint(): array
    {
        $keyId = config('services.cloudflare_turn.key_id');
        $token = config('services.cloudflare_turn.api_token');

        // Local development, and any install that has not bought TURN yet.
        // Two browsers on one machine connect over host candidates, so the dev
        // loop still works; what will not work is a real call across networks.
        if (blank($keyId) || blank($token)) {
            return [['urls' => self::FALLBACK_STUN]];
        }

        $endpoint = rtrim((string) config('services.cloudflare_turn.endpoint'), '/');

        $response = Http::asJson()
            ->withToken($token)
            ->timeout(4)
            ->post("{$endpoint}/{$keyId}/credentials/generate", [
                'ttl' => $this->ttl(),
            ]);

        if ($response->failed()) {
            throw CallCredentialsUnavailable::upstream($response->status());
        }

        return $this->normalise($response->json('iceServers'));
    }

    public function ttl(): int
    {
        return (int) config('hub.calls.credential_ttl_seconds');
    }

    /**
     * Cloudflare returns a single object; RTCPeerConnection wants a list. Keep
     * the public STUN server in the list as well — if the TURN allocation is
     * exhausted or misconfigured, a call that could have gone peer-to-peer
     * should still go peer-to-peer rather than failing outright.
     *
     * @param  mixed  $iceServers
     * @return list<array<string, mixed>>
     */
    private function normalise($iceServers): array
    {
        if (! is_array($iceServers) || $iceServers === []) {
            throw CallCredentialsUnavailable::malformed();
        }

        // Already a list of servers.
        if (array_is_list($iceServers)) {
            return $iceServers;
        }

        if (! isset($iceServers['urls'])) {
            throw CallCredentialsUnavailable::malformed();
        }

        return [$iceServers, ['urls' => self::FALLBACK_STUN]];
    }
}
