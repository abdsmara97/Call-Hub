<?php

use App\Events\CallCancelled;
use App\Events\CallRinging;
use App\Livewire\Hub\CallPanel;
use App\Models\Administration;
use App\Models\Room;
use App\Models\Setting;
use App\Models\User;
use App\Services\RoomProvisioner;
use App\Support\Permissions;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Ringing is the only part of a call the server sees. Everything after it —
 * accept, decline, SDP, ICE, hangup — is a client whisper, so these tests are
 * the whole of the server-side surface.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(OrganisationSeeder::class);

    $administration = Administration::first();

    $this->alice = User::factory()->inAdministration($administration)->create(['name' => 'Alice']);
    $this->alice->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->bob = User::factory()->inAdministration($administration)->create(['name' => 'Bob']);
    $this->bob->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->dm = app(RoomProvisioner::class)->findOrCreateDm($this->alice, $this->bob);
});

it('rings the other person', function () {
    Event::fake([CallRinging::class]);

    $this->actingAs($this->alice)
        ->postJson(route('calls.ring', $this->dm))
        ->assertOk()
        ->assertJsonStructure(['call_id', 'quiet', 'ring_seconds']);

    Event::assertDispatched(CallRinging::class, fn (CallRinging $e) => $e->callee->is($this->bob)
        && $e->caller->is($this->alice)
        && $e->quiet === false);
});

/*
 * The whole reason the ring is a server event rather than a whisper: it has to
 * find someone who is reading a different room. Broadcasting it on the room
 * channel as well would tell the caller something they already know.
 */
it('rings on the personal channel only, never the room channel', function () {
    Event::fake([CallRinging::class]);

    $this->actingAs($this->alice)->postJson(route('calls.ring', $this->dm))->assertOk();

    Event::assertDispatched(CallRinging::class, function (CallRinging $e) {
        $names = collect($e->broadcastOn())->map->name;

        return $names->contains('private-App.Models.User.'.$this->bob->getKey())
            && $names->doesntContain('private-room.'.$this->dm->getKey());
    });
});

it('tells the callee only what the overlay needs', function () {
    $this->bob->update(['phone' => '0700000000']);

    Event::fake([CallRinging::class]);

    $this->actingAs($this->alice)->postJson(route('calls.ring', $this->dm))->assertOk();

    Event::assertDispatched(CallRinging::class, function (CallRinging $e) {
        $payload = $e->broadcastWith();

        return $payload['caller_name'] === 'Alice'
            && ! array_key_exists('caller_email', $payload)
            && ! array_key_exists('caller_phone', $payload);
    });
});

it('refuses to ring anything that is not a direct message', function () {
    $room = Room::factory()->create();
    $room->members()->attach([$this->alice->getKey(), $this->bob->getKey()]);

    Event::fake([CallRinging::class]);

    $this->actingAs($this->alice)
        ->postJson(route('calls.ring', $room))
        ->assertForbidden();

    Event::assertNothingDispatched();
});

/*
 * Belt and braces against a direct message that somehow gained a third member.
 * Group calling needs a forwarding unit rather than this peer-to-peer mesh, so
 * the right behaviour is to fail closed rather than half-work.
 */
it('refuses to ring a direct message that has grown a third member', function () {
    $carol = User::factory()->inAdministration(Administration::first())->create();
    $carol->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->dm->members()->attach($carol->getKey());

    Event::fake([CallRinging::class]);

    $this->actingAs($this->alice)
        ->postJson(route('calls.ring', $this->dm))
        ->assertForbidden();

    Event::assertNothingDispatched();
});

it('refuses to ring a room you are not in', function () {
    $carol = User::factory()->inAdministration(Administration::first())->create();
    $carol->assignRole(Permissions::ROLE_EMPLOYEE);

    Event::fake([CallRinging::class]);

    $this->actingAs($carol)
        ->postJson(route('calls.ring', $this->dm))
        ->assertForbidden();

    Event::assertNothingDispatched();
});

it('stops ringing entirely when an administrator switches calling off', function () {
    Setting::put('calls.enabled', false);

    Event::fake([CallRinging::class]);

    $this->actingAs($this->alice)
        ->postJson(route('calls.ring', $this->dm))
        ->assertForbidden();

    Event::assertNothingDispatched();
});

it('cancels a ring that has not been answered', function () {
    Event::fake([CallCancelled::class]);

    $callId = (string) Str::uuid();

    $this->actingAs($this->alice)
        ->postJson(route('calls.cancel', $this->dm), ['call_id' => $callId])
        ->assertOk();

    Event::assertDispatched(CallCancelled::class, fn (CallCancelled $e) => $e->callId === $callId
        && $e->callee->is($this->bob));
});

it('throttles a caller who will not stop redialling', function () {
    $this->actingAs($this->alice);

    foreach (range(1, 10) as $ignored) {
        $this->postJson(route('calls.ring', $this->dm))->assertOk();
    }

    $this->postJson(route('calls.ring', $this->dm))->assertStatus(429);
});

it('hands the ring to the browser rather than rendering it server-side', function () {
    Livewire::actingAs($this->bob)
        ->test(CallPanel::class)
        ->call('onRinging', ['call_id' => 'abc', 'caller_name' => 'Alice'])
        ->assertDispatched('call-incoming');
});
