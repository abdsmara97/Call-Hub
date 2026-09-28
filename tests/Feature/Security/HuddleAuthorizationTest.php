<?php

use App\Models\Administration;
use App\Models\Room;
use App\Models\Setting;
use App\Models\User;
use App\Services\HuddleTokens;
use App\Services\RoomProvisioner;
use App\Support\Permissions;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Http;

/**
 * The huddle join endpoint.
 *
 * Unlike a 1:1 call, huddle media passes through our own server, so a token that
 * leaks is bandwidth and a presence disclosure rather than just a relay bill.
 * These tests keep four properties true: only a member can join, a huddle is
 * explicitly NOT restricted the way a call is, the caps hold even when the cache
 * is cold, and the LiveKit secret never leaves this server.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(OrganisationSeeder::class);

    config([
        'services.livekit.url' => 'ws://127.0.0.1:7880',
        'services.livekit.api_url' => 'http://127.0.0.1:7880',
        'services.livekit.key' => 'test-key',
        'services.livekit.secret' => 'test-secret-long-enough-for-hs256-signing',
    ]);

    /*
     * An empty, healthy huddle unless a test says otherwise.
     *
     * Driven through instance state rather than by re-faking in each test:
     * Http::fake() MERGES stubs and the first registered match wins, so a second
     * fake() here would be silently ignored. Tests mutate $this->participants or
     * $this->livekitFails instead, and the closures read them at request time.
     */
    $this->participants = [];
    $this->livekitFails = false;

    Http::fake([
        '*/ListParticipants' => fn () => $this->livekitFails
            ? Http::response(['error' => 'nope'], 500)
            : Http::response(['participants' => $this->participants]),
        '*/CreateRoom' => fn () => $this->livekitFails
            ? Http::response(['error' => 'nope'], 500)
            : Http::response(['name' => 'hub-room-1']),
    ]);

    $administration = Administration::first();

    $this->alice = User::factory()->inAdministration($administration)->create();
    $this->alice->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->bob = User::factory()->inAdministration($administration)->create();
    $this->bob->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->room = Room::factory()->private()->create();
    app(RoomProvisioner::class)->addMember($this->room, $this->alice);
    app(RoomProvisioner::class)->addMember($this->room, $this->bob);
});

it('mints a join token for a member of a private room', function () {
    $this->actingAs($this->alice)
        ->postJson(route('huddles.join', $this->room))
        ->assertOk()
        ->assertJsonStructure(['url', 'token', 'room_name', 'identity', 'expires_in', 'max_participants']);
});

/*
 * The anti-call() case, and the reason this file exists.
 *
 * RoomPolicy::call() is direct-message-only and strictly two people, because a
 * peer-to-peer mesh cannot be anything else. A huddle goes through an SFU and has
 * none of those limits. Copying the DM guard across is the natural mistake and
 * this is what catches it.
 */
it('mints a join token in a system room with many members', function () {
    $administration = Administration::first();
    $room = app(RoomProvisioner::class)->ensureAdministrationRoom($administration);

    expect($room->is_system)->toBeTrue();

    foreach (range(1, 12) as $ignored) {
        $member = User::factory()->inAdministration($administration)->create();
        $member->assignRole(Permissions::ROLE_EMPLOYEE);
        app(RoomProvisioner::class)->addMember($room, $member);
    }

    expect($room->members()->count())->toBeGreaterThan(3);

    $this->actingAs($this->alice->fresh());
    app(RoomProvisioner::class)->addMember($room, $this->alice);

    $this->postJson(route('huddles.join', $room))->assertOk();
});

it('mints a join token in a direct message', function () {
    $dm = app(RoomProvisioner::class)->findOrCreateDm($this->alice, $this->bob);

    $this->actingAs($this->alice)
        ->postJson(route('huddles.join', $dm))
        ->assertOk();
});

/*
 * Stricter than view() on purpose. A public room is readable by anyone, but
 * reading a room is not standing in it, and the participant list is a presence
 * disclosure.
 */
it('refuses a non-member of a public room', function () {
    $public = Room::factory()->create();

    $this->actingAs($this->alice)
        ->postJson(route('huddles.join', $public))
        ->assertForbidden();
});

it('refuses a guest', function () {
    $this->postJson(route('huddles.join', $this->room))->assertUnauthorized();
});

/*
 * The two kill switches are independent because the two features fail
 * differently and cost differently: huddle media crosses this server, 1:1 media
 * does not. An administrator must be able to stop the expensive one alone.
 */
it('refuses when huddles are disabled but leaves 1:1 calling working', function () {
    Setting::put('huddles.enabled', false);

    $dm = app(RoomProvisioner::class)->findOrCreateDm($this->alice, $this->bob);

    $this->actingAs($this->alice)
        ->postJson(route('huddles.join', $this->room))
        ->assertForbidden();

    $this->actingAs($this->alice)
        ->postJson(route('calls.ice-servers', $dm))
        ->assertOk();
});

/*
 * 403 from the policy, not 503 from the controller. A fresh clone should render
 * no huddle button at all rather than one that fails when pressed.
 */
it('refuses when livekit is not configured', function () {
    config(['services.livekit.url' => null, 'services.livekit.key' => null, 'services.livekit.secret' => null]);

    $this->actingAs($this->alice)
        ->postJson(route('huddles.join', $this->room))
        ->assertForbidden();
});

/*
 * HS256 needs 256 bits of key and firebase/php-jwt refuses to sign with less.
 * Without this check a short secret would reach the JWT library and surface as a
 * 500; failing closed in the policy is both truer and more useful. This is also
 * why `livekit-server --dev`, whose secret is the six-byte string "secret",
 * cannot be used with this hub.
 */
it('refuses when the livekit secret is too short to sign with', function () {
    config(['services.livekit.secret' => 'secret']);

    $this->actingAs($this->alice)
        ->postJson(route('huddles.join', $this->room))
        ->assertForbidden();
});

it('throttles joining without consuming the calls allowance', function () {
    $this->actingAs($this->alice);

    foreach (range(1, 12) as $ignored) {
        $this->postJson(route('huddles.join', $this->room))->assertOk();
    }

    $this->postJson(route('huddles.join', $this->room))->assertStatus(429);

    // The 1:1 limiter is a separate bucket and must be untouched.
    $dm = app(RoomProvisioner::class)->findOrCreateDm($this->alice, $this->bob);
    $this->postJson(route('calls.ice-servers', $dm))->assertOk();
});

it('never caches a token response', function () {
    $this->actingAs($this->alice)
        ->postJson(route('huddles.join', $this->room))
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('never leaks the livekit secret even when the sfu fails', function () {
    $this->livekitFails = true;

    $response = $this->actingAs($this->alice)
        ->postJson(route('huddles.join', $this->room))
        ->assertStatus(503);

    expect($response->getContent())
        ->not->toContain('test-secret-long-enough-for-hs256-signing');
});

it('signs the grants a participant is allowed and no others', function () {
    $response = $this->actingAs($this->alice)
        ->postJson(route('huddles.join', $this->room))
        ->assertOk();

    $claims = app(HuddleTokens::class)->verify($response->json('token'));

    expect($claims->sub)->toBe('u'.$this->alice->getKey())
        ->and($claims->video->room)->toBe('hub-room-'.$this->room->tenant_id.'-'.$this->room->getKey())
        ->and($claims->video->roomJoin)->toBeTrue()
        ->and($claims->video->canPublish)->toBeTrue()
        // The banner renders the name and avatar we signed here, and reads them
        // straight off the webhook without a query. That only holds if the
        // participant cannot rewrite them.
        ->and($claims->video->canUpdateOwnMetadata)->toBeFalse()
        // Huddles are not recorded, and no Egress service exists to record them.
        ->and($claims->video->roomRecord)->toBeFalse()
        ->and($claims->video->roomAdmin)->toBeFalse()
        ->and($claims->video->roomCreate)->toBeFalse();

    expect(json_decode($claims->metadata, true))
        ->toMatchArray(['user_id' => $this->alice->getKey()]);
});

it('grants room admin to a moderator so they can eject someone', function () {
    $this->alice->givePermissionTo(Permissions::MANAGE_ROOMS);

    $response = $this->actingAs($this->alice->fresh())
        ->postJson(route('huddles.join', $this->room))
        ->assertOk();

    $claims = app(HuddleTokens::class)->verify($response->json('token'));

    expect($claims->video->roomAdmin)->toBeTrue();
});

it('refuses a join once the huddle is at capacity', function () {
    Setting::put('huddles.max_participants', 2);

    $this->participants = [['identity' => 'u998'], ['identity' => 'u999']];

    $this->actingAs($this->alice)
        ->postJson(route('huddles.join', $this->room))
        ->assertStatus(409)
        ->assertJsonPath('reason', 'full');
});

/*
 * The reconnect case, and the one a naive `count >= cap` check breaks. Somebody
 * whose network blipped has to be able to get back into the meeting they were
 * already in — locking them out is the worst behaviour available to us.
 */
it('lets someone already in a full huddle reconnect', function () {
    Setting::put('huddles.max_participants', 2);

    $this->participants = [
        ['identity' => 'u'.$this->alice->getKey()],
        ['identity' => 'u999'],
    ];

    $this->actingAs($this->alice)
        ->postJson(route('huddles.join', $this->room))
        ->assertOk();
});

/*
 * Reads elsewhere in this feature may be stale; the cap check never is. A cold
 * cache must not be able to let a thirty-first person into a thirty-person
 * huddle, which is why the join path always asks LiveKit itself.
 */
it('asks livekit for the participant count rather than trusting the cache', function () {
    Setting::put('huddles.max_participants', 1);

    $this->participants = [['identity' => 'u999']];

    $this->actingAs($this->alice)
        ->postJson(route('huddles.join', $this->room))
        ->assertStatus(409);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'ListParticipants'));
});

/*
 * A relay is a nicety; a token is the feature. If Cloudflare is down the huddle
 * still has to start for everyone whose network does not need a relay.
 */
it('still issues a token when the turn provider is unavailable', function () {
    config([
        'services.cloudflare_turn.key_id' => 'test-key',
        'services.cloudflare_turn.api_token' => 'test-token',
    ]);

    Http::fake([
        '*/ListParticipants' => Http::response(['participants' => []]),
        '*/CreateRoom' => Http::response(['name' => 'hub-room-1']),
        '*rtc.live.cloudflare.com*' => Http::response(['error' => 'nope'], 500),
    ]);

    $this->actingAs($this->alice)
        ->postJson(route('huddles.join', $this->room))
        ->assertOk()
        ->assertJsonPath('ice_servers', []);
});
