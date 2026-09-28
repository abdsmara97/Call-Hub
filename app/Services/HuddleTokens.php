<?php

namespace App\Services;

use App\Models\Room;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use stdClass;

/**
 * Mints the signed tokens a browser needs to join a huddle.
 *
 * The shape deliberately mirrors App\Services\CallCredentials: config-driven,
 * short-lived, and the secret never leaves this server. The difference is what a
 * leak would cost. A stolen TURN credential buys somebody relay bandwidth; a
 * stolen LiveKit secret lets them join any huddle in the hub as any employee and
 * forge a "Call in progress" banner in every room. Hence the short TTL, and hence
 * the fact that nothing here is ever rendered into a page.
 */
class HuddleTokens
{
    /**
     * HS256 needs 256 bits of key, and the JWT library enforces it.
     *
     * This is not a style rule we invented: firebase/php-jwt v7 refuses to sign
     * with anything shorter, which is what CVE-2025-45769 was about. It has one
     * practical consequence worth knowing before you lose an afternoon to it —
     * `livekit-server --dev` uses the hard-coded secret "secret", which is six
     * bytes, so the --dev server cannot be used with this hub at all. Run
     * deploy/livekit/livekit.local.yaml instead, which carries a long throwaway
     * key (and sends webhooks, which --dev also does not).
     */
    private const MIN_SECRET_BYTES = 32;

    /**
     * Is there a LiveKit to talk to at all?
     *
     * RoomPolicy::huddle() consults this, which is how a fresh clone shows no
     * huddle affordance rather than a button that 503s. Same spirit as
     * CallCredentials falling back to public STUN: the dev loop keeps working,
     * minus the part that genuinely needs infrastructure.
     *
     * The key-length test belongs here rather than at mint time for the same
     * reason. A short secret would otherwise sail through the policy and throw
     * a DomainException from deep inside the JWT library when somebody presses
     * the button — a 500 with a stack trace, where "the feature is not
     * configured" is both true and far more useful.
     */
    public function isConfigured(): bool
    {
        return filled($this->key())
            && strlen($this->secret()) >= self::MIN_SECRET_BYTES
            && filled(config('services.livekit.url'));
    }

    /**
     * The entire mapping between a Room and a LiveKit room.
     *
     * Derived, never stored — this is what makes the whole feature possible with
     * no tables, and it means two huddles cannot coexist in one room by
     * construction. The inverse lives in roomIdFrom() and the two must stay
     * exact opposites.
     */
    public function roomNameFor(Room $room): string
    {
        // The tenant segment keeps one shared LiveKit deployment safe for
        // every customer: names cannot collide across tenants, and the
        // webhook can verify the mapping is internally consistent.
        return $this->prefix().$room->tenant_id.'-'.$room->getKey();
    }

    /**
     * The inverse, for the webhook — which knows only the LiveKit room name.
     *
     * Returns null rather than throwing for anything unrecognised. A webhook
     * naming a room we cannot map is answered with 200 and dropped, because a
     * 4xx makes LiveKit retry it forever.
     *
     * The tenant fence: a name claiming an existing room under the wrong
     * tenant is treated as unrecognised. A room that no longer exists still
     * maps — the name is in our namespace, and the deleted-room case is
     * handled deliberately downstream (reconcile tears the huddle down).
     */
    public function roomIdFrom(string $name): ?int
    {
        $prefix = $this->prefix();

        if (! str_starts_with($name, $prefix)) {
            return null;
        }

        $parts = explode('-', substr($name, strlen($prefix)));

        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return null;
        }

        [$tenantId, $roomId] = [(int) $parts[0], (int) $parts[1]];

        $actualTenant = Room::acrossTenants()->whereKey($roomId)->value('tenant_id');

        return ($actualTenant === null || $actualTenant === $tenantId) ? $roomId : null;
    }

    /** The websocket URL handed to the browser at join time, never bundled. */
    public function url(): string
    {
        return (string) config('services.livekit.url');
    }

    public function ttl(): int
    {
        return (int) config('hub.huddles.token_ttl_seconds');
    }

    /**
     * A join token for one person in one room.
     *
     * Two grants carry more weight than the rest:
     *
     * `canUpdateOwnMetadata` is false because we sign the display name and
     * avatar into the metadata and LiveKit hands that exact string back on the
     * participant_joined webhook. That is what lets the webhook render the join
     * banner without touching the database — and it only holds if a participant
     * cannot rewrite what we signed.
     *
     * `roomRecord` is false on every token ever minted, and no Egress service is
     * installed. Huddles are not recorded, which is the same "leaves no trace"
     * principle App\Services\CallService documents for 1:1 calls.
     */
    public function forParticipant(User $user, Room $room, bool $moderator = false): string
    {
        return $this->encode([
            'sub' => $this->identityFor($user),
            'name' => $user->name,
            'metadata' => json_encode([
                'user_id' => $user->getKey(),
                'avatar_url' => $user->avatar_url,
            ]),
            'video' => [
                'room' => $this->roomNameFor($room),
                'roomJoin' => true,
                'canPublish' => true,
                'canSubscribe' => true,
                'canPublishData' => true,
                'canPublishSources' => ['camera', 'microphone', 'screen_share', 'screen_share_audio'],
                'canUpdateOwnMetadata' => false,
                // Moderators may eject a disruptive participant. In a room with
                // three hundred members that is not a luxury.
                'roomAdmin' => $moderator,
                'roomCreate' => false,
                'roomList' => false,
                'roomRecord' => false,
                'hidden' => false,
            ],
        ], $this->ttl());
    }

    /**
     * An admin token for our own server-to-server calls.
     *
     * Sixty seconds, because it is minted immediately before the HTTP request
     * that spends it and never travels further than loopback.
     */
    public function forServer(): string
    {
        return $this->encode([
            'sub' => 'oaktree-hub',
            'video' => [
                'roomCreate' => true,
                'roomList' => true,
                'roomAdmin' => true,
            ],
        ], 60);
    }

    /**
     * How a Laravel user is named inside LiveKit.
     *
     * Stable and derived, which has a deliberate consequence: opening a huddle in
     * a second tab disconnects the first, because LiveKit refuses two live
     * sessions under one identity. That is the behaviour we want — one person is
     * in a huddle once — but it is also why HuddleRegistry has to guard removals
     * on the participant sid.
     */
    public function identityFor(User $user): string
    {
        return 'u'.$user->getKey();
    }

    /** The inverse of identityFor(), for reading webhook payloads. */
    public function userIdFrom(string $identity): ?int
    {
        if (! str_starts_with($identity, 'u')) {
            return null;
        }

        $id = substr($identity, 1);

        return ctype_digit($id) ? (int) $id : null;
    }

    /**
     * Verify a token LiveKit signed — the webhook path.
     *
     * The algorithm is pinned by the Key object rather than read from the JWT
     * header, which is what refuses `alg: none` and HS256/RS256 confusion. Every
     * failure mode here is a thrown exception, so callers only ever see a valid
     * token or nothing.
     */
    public function verify(string $token): stdClass
    {
        return JWT::decode($token, new Key($this->secret(), 'HS256'));
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function encode(array $claims, int $ttl): string
    {
        $now = time();

        return JWT::encode([
            'iss' => $this->key(),
            // Ten seconds of slack. Both this and the webhook signature are
            // time-bounded, so a drifting clock breaks huddles in both
            // directions with nothing but "invalid token" to go on.
            'nbf' => $now - 10,
            'exp' => $now + $ttl,
            ...$claims,
        ], $this->secret(), 'HS256');
    }

    private function key(): string
    {
        return (string) config('services.livekit.key');
    }

    private function secret(): string
    {
        return (string) config('services.livekit.secret');
    }

    private function prefix(): string
    {
        return (string) config('hub.huddles.room_prefix');
    }
}
