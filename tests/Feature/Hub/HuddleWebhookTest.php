<?php

use App\Events\HuddleUpdated;
use App\Services\HuddleRegistry;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The LiveKit webhook.
 *
 * This endpoint is unauthenticated by necessity — LiveKit has no session and no
 * CSRF token — so the signature is the whole of its security, and the first four
 * tests here are the ones that matter. The rest keep two liveness properties
 * true: LiveKit must never be given a reason to retry forever, and a participant
 * who is actually in a huddle must never be removed from the roster by a race.
 */
const SECRET = 'test-secret-long-enough-for-hs256-signing';

beforeEach(function () {
    config([
        'services.livekit.url' => 'ws://127.0.0.1:7880',
        'services.livekit.key' => 'test-key',
        'services.livekit.secret' => SECRET,
    ]);

    Cache::flush();

    // roomIdFrom() now verifies the parsed room really belongs to the parsed
    // tenant, so the webhook only maps names of rooms that exist.
    $this->room = \App\Models\Room::factory()->create();
    $this->roomName = 'hub-room-'.$this->room->tenant_id.'-'.$this->room->getKey();
});

/**
 * Signs a body the way LiveKit does: bare token in Authorization, and a base64
 * SHA-256 of the exact bytes in a `sha256` claim.
 */
function signed(array $body, array $overrides = []): array
{
    $raw = json_encode($body);

    $claims = array_merge([
        'iss' => 'test-key',
        'nbf' => time() - 10,
        'exp' => time() + 300,
        'sha256' => base64_encode(hash('sha256', $raw, true)),
    ], $overrides);

    return [$raw, JWT::encode($claims, $overrides['secret'] ?? SECRET, 'HS256')];
}

function postWebhook(array $body, array $overrides = [], ?string $rawOverride = null)
{
    [$raw, $token] = signed($body, $overrides);

    return test()->call(
        'POST',
        '/api/webhooks/livekit',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/webhook+json',
            'HTTP_AUTHORIZATION' => $token,
        ],
        $rawOverride ?? $raw,
    );
}

function joinEvent(int $userId = 12, string $sid = 'PA_first', ?int $roomId = null): array
{
    $roomId ??= test()->room->getKey();

    return [
        'id' => 'evt-'.$sid.'-'.$userId,
        'event' => 'participant_joined',
        'room' => ['name' => 'hub-room-'.test()->room->tenant_id.'-'.$roomId, 'creationTime' => '1754236800'],
        'participant' => [
            'identity' => 'u'.$userId,
            'sid' => $sid,
            'name' => 'Grace Okafor',
            'metadata' => json_encode(['user_id' => $userId, 'avatar_url' => '/avatars/g.png']),
            'joinedAt' => '1754236800',
        ],
    ];
}

// ---------------------------------------------------------------- signature

it('refuses a webhook with no authorization header', function () {
    $this->call('POST', '/api/webhooks/livekit', [], [], [], [
        'CONTENT_TYPE' => 'application/webhook+json',
    ], json_encode(joinEvent()))->assertStatus(401);
});

it('refuses a webhook signed with the wrong secret', function () {
    postWebhook(joinEvent(), ['secret' => 'a-different-secret-also-long-enough-yes'])
        ->assertStatus(401);
});

it('refuses an expired webhook token', function () {
    postWebhook(joinEvent(), ['exp' => time() - 60, 'nbf' => time() - 120])
        ->assertStatus(401);
});

/*
 * The replay case. Without the body-hash check a valid token captured from any
 * other event could be resent carrying an arbitrary body — which on this
 * endpoint means forging a huddle roster in any room in the company.
 */
it('refuses a valid token whose body has been tampered with', function () {
    $tampered = json_encode(joinEvent(99, 'PA_evil'));

    postWebhook(joinEvent(), [], $tampered)->assertStatus(401);
});

it('refuses a token signed with an unexpected issuer', function () {
    postWebhook(joinEvent(), ['iss' => 'somebody-else'])->assertStatus(401);
});

// ---------------------------------------------------------------- semantics

it('marks a room live and broadcasts when a huddle starts', function () {
    Event::fake([HuddleUpdated::class]);

    postWebhook([
        'id' => 'evt-start',
        'event' => 'room_started',
        'room' => ['name' => $this->roomName, 'creationTime' => '1754236800'],
    ])->assertOk();

    Event::assertDispatched(HuddleUpdated::class, fn ($e) => $e->roomId === $this->room->getKey());
});

it('records a participant from the token metadata alone', function () {
    postWebhook(joinEvent())->assertOk();

    $snapshot = app(HuddleRegistry::class)->snapshot($this->room->getKey());

    expect($snapshot['active'])->toBeTrue()
        ->and($snapshot['participant_count'])->toBe(1)
        ->and($snapshot['participants'][0]['name'])->toBe('Grace Okafor')
        ->and($snapshot['participants'][0]['avatar_url'])->toBe('/avatars/g.png')
        ->and($snapshot['participants'][0]['id'])->toBe(12);
});

/*
 * The property that makes a join storm survivable. Fifty people clicking Join in
 * five seconds must not be fifty rounds of User, Room and room_members queries —
 * which is exactly why the name and avatar are signed into the token and read
 * back off the webhook rather than looked up.
 *
 * The tenant boundary added exactly two indexed lookups per event, both O(1)
 * and neither per-participant: roomIdFrom() verifying the room really belongs
 * to the tenant in the name, and the broadcast channel name resolving the
 * room's tenant. Pinned so a third query is still a regression.
 */
it('handles a join with only the two tenant-fence lookups', function () {
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    postWebhook(joinEvent())->assertOk();

    expect($queries)->toBe(2);
})->skip(
    fn () => config('cache.default') === 'database',
    'The database cache store issues queries of its own; run with an array or redis cache.'
);

/*
 * The duplicate-identity race, and the subtlest bug this feature can have.
 *
 * Identity is derived from the user id, so a second tab makes LiveKit disconnect
 * the first — and the participant_left for the OLD session can arrive after the
 * participant_joined for the new one. Without the sid guard this silently
 * removes somebody who is sitting in the huddle.
 */
it('ignores a departure for a session that has already been replaced', function () {
    postWebhook(joinEvent(12, 'PA_first'))->assertOk();
    postWebhook(joinEvent(12, 'PA_second'))->assertOk();

    // The late departure of the first tab.
    postWebhook([
        'id' => 'evt-late-left',
        'event' => 'participant_left',
        'room' => ['name' => $this->roomName],
        'participant' => ['identity' => 'u12', 'sid' => 'PA_first'],
    ])->assertOk();

    expect(app(HuddleRegistry::class)->snapshot($this->room->getKey())['participant_count'])->toBe(1);
});

it('removes a participant when the current session leaves', function () {
    postWebhook(joinEvent(12, 'PA_first'))->assertOk();
    postWebhook(joinEvent(13, 'PA_other'))->assertOk();

    postWebhook([
        'id' => 'evt-left-13',
        'event' => 'participant_left',
        'room' => ['name' => $this->roomName],
        'participant' => ['identity' => 'u13', 'sid' => 'PA_other'],
    ])->assertOk();

    expect(app(HuddleRegistry::class)->snapshot($this->room->getKey())['participant_count'])->toBe(1);
});

/*
 * room_finished arrives empty_timeout seconds later — sixty by default. A banner
 * that lingers a minute after the last person left is a bug users report, so the
 * state clears on the last departure instead.
 */
it('clears the huddle the moment the last participant leaves', function () {
    postWebhook(joinEvent(12, 'PA_first'))->assertOk();

    postWebhook([
        'id' => 'evt-left-last',
        'event' => 'participant_left',
        'room' => ['name' => $this->roomName],
        'participant' => ['identity' => 'u12', 'sid' => 'PA_first'],
    ])->assertOk();

    $snapshot = app(HuddleRegistry::class)->snapshot($this->room->getKey());

    expect($snapshot['active'])->toBeFalse()
        ->and($snapshot['participant_count'])->toBe(0)
        ->and(app(HuddleRegistry::class)->liveRoomIds())->not->toContain($this->room->getKey());
});

it('clears the huddle when the room finishes', function () {
    postWebhook(joinEvent(12, 'PA_first'))->assertOk();

    postWebhook([
        'id' => 'evt-finished',
        'event' => 'room_finished',
        'room' => ['name' => $this->roomName],
    ])->assertOk();

    expect(app(HuddleRegistry::class)->snapshot($this->room->getKey())['active'])->toBeFalse();
});

/*
 * Anything that is not a 2xx makes LiveKit retry indefinitely. An event type we
 * do not care about must therefore be accepted, not rejected.
 */
it('accepts an event type it does not handle', function () {
    postWebhook([
        'id' => 'evt-track',
        'event' => 'track_published',
        'room' => ['name' => $this->roomName],
    ])->assertOk();
});

it('accepts a room name it cannot map to a room', function () {
    postWebhook([
        'id' => 'evt-foreign',
        'event' => 'room_started',
        'room' => ['name' => 'some-other-product-room-7'],
    ])->assertOk();
});

it('processes a repeated event id only once', function () {
    postWebhook(joinEvent(12, 'PA_first'))->assertOk();

    postWebhook([
        'id' => 'evt-PA_first-12', // same id as the join above
        'event' => 'participant_left',
        'room' => ['name' => $this->roomName],
        'participant' => ['identity' => 'u12', 'sid' => 'PA_first'],
    ])->assertOk();

    // The retry was dropped, so the participant is still there.
    expect(app(HuddleRegistry::class)->snapshot($this->room->getKey())['participant_count'])->toBe(1);
});

it('needs no session, no csrf token and no authenticated user', function () {
    postWebhook(joinEvent())->assertOk();

    expect(auth()->check())->toBeFalse();
});

it('is invisible when livekit is not configured', function () {
    config(['services.livekit.url' => null, 'services.livekit.key' => null, 'services.livekit.secret' => null]);

    postWebhook(joinEvent())->assertNotFound();
});
