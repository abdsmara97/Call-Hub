<?php

use App\Livewire\Hub\Conversation;
use App\Models\Administration;
use App\Models\Room;
use App\Models\Setting;
use App\Models\User;
use App\Services\HuddleRegistry;
use App\Services\RoomProvisioner;
use App\Support\Permissions;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * The conversation surface for huddles.
 *
 * These exist mostly to compile the blades. Every huddle view sits behind a
 * "huddle" gate check, which is false unless LiveKit is configured — so without
 * a test that configures it, a syntax error or a missing icon in the banner or
 * the grid would ship green and only appear in a browser.
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

    Cache::flush();

    Http::fake([
        '*/ListRooms' => Http::response(['rooms' => []]),
        '*/ListParticipants' => Http::response(['participants' => []]),
        '*' => Http::response([]),
    ]);

    $administration = Administration::first();

    $this->alice = User::factory()->inAdministration($administration)->create();
    $this->alice->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->room = Room::factory()->create();
    app(RoomProvisioner::class)->addMember($this->room, $this->alice);
});

it('renders the huddle affordances in a room a member can huddle in', function () {
    Livewire::actingAs($this->alice)
        ->test(Conversation::class, ['roomId' => $this->room->id])
        ->assertOk()
        ->assertSee('Start a huddle in this room')
        ->assertSeeHtml('huddleBanner');
});

it('renders nothing about huddles when livekit is unconfigured', function () {
    config(['services.livekit.url' => null, 'services.livekit.key' => null, 'services.livekit.secret' => null]);

    Livewire::actingAs($this->alice)
        ->test(Conversation::class, ['roomId' => $this->room->id])
        ->assertOk()
        ->assertDontSee('Start a huddle in this room')
        ->assertDontSeeHtml('huddleBanner');
});

it('renders nothing about huddles when they are switched off', function () {
    Setting::put('huddles.enabled', false);

    Livewire::actingAs($this->alice)
        ->test(Conversation::class, ['roomId' => $this->room->id])
        ->assertOk()
        ->assertDontSee('Start a huddle in this room');
});

/*
 * The banner is seeded from the server once and then updates itself over the
 * room channel. If this snapshot were missing, somebody opening a room during a
 * live huddle would see nothing until the next join or leave.
 */
it('seeds the banner with the huddle already in progress', function () {
    app(HuddleRegistry::class)->markJoined($this->room->getKey(), [
        'identity' => 'u999',
        'sid' => 'PA_1',
        'id' => 999,
        'name' => 'Grace Okafor',
        'avatar_url' => null,
        'joined_at' => time(),
    ]);

    Livewire::actingAs($this->alice)
        ->test(Conversation::class, ['roomId' => $this->room->id])
        ->assertOk()
        ->assertSeeHtml('Grace Okafor');
});

it('seeds an inactive snapshot when nothing is happening', function () {
    $component = Livewire::actingAs($this->alice)
        ->test(Conversation::class, ['roomId' => $this->room->id])
        ->assertOk();

    expect($component->viewData('huddleSnapshot')['active'])->toBeFalse();
});

it('passes no snapshot at all when the viewer cannot huddle', function () {
    config(['services.livekit.url' => null]);

    $component = Livewire::actingAs($this->alice)
        ->test(Conversation::class, ['roomId' => $this->room->id])
        ->assertOk();

    expect($component->viewData('huddleSnapshot'))->toBeNull();
});

/*
 * The dock, which every test above misses.
 *
 * Livewire::test renders the component; the huddle dock is included by the
 * layout, so the pill, the grid, the controls and the device menu are compiled
 * by nothing else in the suite. A full page GET is the only thing that touches
 * them — without this, a syntax error in huddle-controls ships green.
 */
it('renders the huddle dock and its controls on a full page load', function () {
    $response = $this->actingAs($this->alice)
        ->get(route('rooms.show', $this->room))
        ->assertOk();

    $response
        ->assertSeeHtml('huddleSession(')
        ->assertSeeHtml('x-huddle-audio')
        ->assertSeeHtml('toggleScreen()');
});

/*
 * The device menu. People arriving from a desk phone or a headset need it on
 * day one, and the browser default is rarely the device they want.
 */
it('renders the device picker inside the huddle controls', function () {
    $response = $this->actingAs($this->alice)
        ->get(route('rooms.show', $this->room))
        ->assertOk();

    $response
        ->assertSeeHtml('id="huddle-devices"')
        ->assertSeeHtml('deviceKinds')
        ->assertSeeHtml('selectDevice(kind')
        ->assertSeeHtml('huddle.devices[kind]')
        ->assertSeeHtml('huddle.selected[kind]');
});

/*
 * The dock mounts for every signed-in person, on purpose, even with no LiveKit
 * to talk to — it is inert until something starts a huddle.
 *
 * The tempting optimisation is to gate the @persist include on the same policy
 * as the buttons. That breaks the feature: wire:navigate swaps the body when you
 * click another room, and a subtree that renders in one room and not the next is
 * not persisted at all — it is destroyed and rebuilt, taking the LiveKit Room in
 * the Alpine closure with it. The gate belongs on the entry points, which the
 * two tests above cover, never on the dock.
 */
it('mounts the dock for any signed-in user, with no entry point when livekit is unconfigured', function () {
    config(['services.livekit.url' => null, 'services.livekit.key' => null, 'services.livekit.secret' => null]);

    $this->actingAs($this->alice)
        ->get(route('rooms.show', $this->room))
        ->assertOk()
        ->assertSeeHtml('huddleSession(')
        ->assertDontSee('Start a huddle in this room');
});

/*
 * The read-path valve. A flushed cache must not leave every banner in the hub
 * blank until the scheduled sweep next runs.
 */
it('rebuilds a flushed index on the first page load', function () {
    Cache::flush();

    expect(app(HuddleRegistry::class)->liveIndexExists())->toBeFalse();

    Livewire::actingAs($this->alice)
        ->test(Conversation::class, ['roomId' => $this->room->id])
        ->assertOk();

    expect(app(HuddleRegistry::class)->liveIndexExists())->toBeTrue();
});
