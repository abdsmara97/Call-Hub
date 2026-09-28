<?php

use App\Models\Administration;
use App\Models\User;
use App\Services\RoomProvisioner;
use App\Support\Permissions;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Http;

/**
 * The TURN credential endpoint.
 *
 * Relay bandwidth costs money, so a credential that leaks is a bill. These
 * tests exist to keep three properties true: only a member of the conversation
 * can mint one, the endpoint cannot be drained, and our API token never leaves
 * the server even when the provider fails.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(OrganisationSeeder::class);

    $administration = Administration::first();

    $this->alice = User::factory()->inAdministration($administration)->create();
    $this->alice->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->bob = User::factory()->inAdministration($administration)->create();
    $this->bob->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->dm = app(RoomProvisioner::class)->findOrCreateDm($this->alice, $this->bob);
});

it('mints ice servers for a member of the conversation', function () {
    $this->actingAs($this->alice)
        ->postJson(route('calls.ice-servers', $this->dm))
        ->assertOk()
        ->assertJsonStructure(['ice_servers', 'expires_in']);
});

it('refuses ice servers to someone outside the conversation', function () {
    $carol = User::factory()->inAdministration(Administration::first())->create();
    $carol->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->actingAs($carol)
        ->postJson(route('calls.ice-servers', $this->dm))
        ->assertForbidden();
});

it('refuses ice servers to a guest', function () {
    $this->postJson(route('calls.ice-servers', $this->dm))->assertUnauthorized();
});

/*
 * Without a throttle this is a free relay-credential faucet: anyone with an
 * account could mint indefinitely and hand the results out.
 */
it('throttles credential minting', function () {
    $this->actingAs($this->alice);

    foreach (range(1, 10) as $ignored) {
        $this->postJson(route('calls.ice-servers', $this->dm))->assertOk();
    }

    $this->postJson(route('calls.ice-servers', $this->dm))->assertStatus(429);
});

it('never caches a credential response', function () {
    $this->actingAs($this->alice)
        ->postJson(route('calls.ice-servers', $this->dm))
        ->assertHeader('Cache-Control', 'no-store, private');
});

/*
 * With no provider configured the app still works on public STUN, so a fresh
 * clone can make a call between two browsers on one machine without anybody
 * signing up for anything.
 */
it('falls back to stun when no turn provider is configured', function () {
    config(['services.cloudflare_turn.key_id' => null, 'services.cloudflare_turn.api_token' => null]);

    $response = $this->actingAs($this->alice)
        ->postJson(route('calls.ice-servers', $this->dm))
        ->assertOk();

    expect($response->json('ice_servers.0.urls'))->toContain('stun:');
});

it('reports a provider failure without leaking the api token', function () {
    config([
        'services.cloudflare_turn.key_id' => 'test-key',
        'services.cloudflare_turn.api_token' => 'super-secret-token',
    ]);

    Http::fake(['*' => Http::response(['error' => 'nope'], 500)]);

    $response = $this->actingAs($this->alice)
        ->postJson(route('calls.ice-servers', $this->dm))
        ->assertStatus(503);

    expect($response->getContent())->not->toContain('super-secret-token');
});

it('normalises a provider response into what the browser expects', function () {
    config([
        'services.cloudflare_turn.key_id' => 'test-key',
        'services.cloudflare_turn.api_token' => 'test-token',
    ]);

    Http::fake(['*' => Http::response([
        'iceServers' => [
            'urls' => ['turn:turn.cloudflare.com:3478'],
            'username' => 'u',
            'credential' => 'c',
        ],
    ])]);

    $response = $this->actingAs($this->alice)
        ->postJson(route('calls.ice-servers', $this->dm))
        ->assertOk();

    // RTCPeerConnection wants a list; Cloudflare returns a single object.
    expect($response->json('ice_servers'))->toBeArray()
        ->and($response->json('ice_servers.0.username'))->toBe('u');
});

/*
 * The client refuses to whisper anything larger than its own guard, and the
 * guard is only meaningful if it sits below what the socket will actually
 * carry. If this fails, set REVERB_APP_MAX_MESSAGE_SIZE — a video renegotiation
 * offer will otherwise close the connection mid-call.
 */
it('keeps the whisper guard below the socket message limit', function () {
    $socketLimit = (int) config('reverb.apps.apps.0.max_message_size');

    expect((int) config('hub.calls.max_whisper_bytes'))->toBeLessThan($socketLimit);
});
