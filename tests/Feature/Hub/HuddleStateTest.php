<?php

use App\Enums\UserStatus;
use App\Models\Administration;
use App\Models\Room;
use App\Models\User;
use App\Services\HuddleRegistry;
use App\Services\RoomProvisioner;
use App\Support\Permissions;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The reconcile sweep.
 *
 * This is the phase the plan marks as not-optional-for-production, and these
 * tests are why. Webhooks are lossy, and two of the things they can lose are
 * unacceptable: a banner that never clears, and a suspended employee who stays
 * inside a live huddle because there is no next HTTP request for
 * EnsureAccountIsActive to run on.
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

    $administration = Administration::first();

    $this->alice = User::factory()->inAdministration($administration)->create();
    $this->alice->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->bob = User::factory()->inAdministration($administration)->create();
    $this->bob->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->room = Room::factory()->private()->create();
    app(RoomProvisioner::class)->addMember($this->room, $this->alice);
    app(RoomProvisioner::class)->addMember($this->room, $this->bob);

    $this->roomName = 'hub-room-'.$this->room->getKey();

    // Mutable fakes: Http::fake() merges rather than replaces, so tests steer
    // these arrays instead of re-faking.
    $this->rooms = [];
    $this->participants = [];
    $this->removed = [];
    $this->deleted = [];
    $this->livekitDown = false;

    Http::fake([
        '*/ListRooms' => fn () => $this->livekitDown
            ? Http::response(['error' => 'down'], 500)
            : Http::response(['rooms' => $this->rooms]),
        '*/ListParticipants' => fn () => Http::response(['participants' => $this->participants]),
        '*/RemoveParticipant' => function ($request) {
            $this->removed[] = $request->data()['identity'];

            return Http::response([]);
        },
        '*/DeleteRoom' => function ($request) {
            $this->deleted[] = $request->data()['room'];

            return Http::response([]);
        },
        '*' => Http::response([]),
    ]);
});

function liveRoom(string $name, int $ageSeconds = 60): array
{
    // protojson hands int64 back as a string, and the sweep has to cope.
    return ['name' => $name, 'creationTime' => (string) (time() - $ageSeconds)];
}

function participant(User $user, string $sid = 'PA_1'): array
{
    return [
        'identity' => 'u'.$user->getKey(),
        'sid' => $sid,
        'name' => $user->name,
        'metadata' => json_encode(['user_id' => $user->getKey(), 'avatar_url' => null]),
        'joinedAt' => (string) time(),
    ];
}

it('rebuilds state from livekit when the cache is cold', function () {
    $this->rooms = [liveRoom($this->roomName)];
    $this->participants = [participant($this->alice)];

    $this->artisan('huddles:reconcile')->assertSuccessful();

    $snapshot = app(HuddleRegistry::class)->snapshot($this->room->getKey());

    expect($snapshot['active'])->toBeTrue()
        ->and($snapshot['participant_count'])->toBe(1)
        ->and($snapshot['participants'][0]['id'])->toBe($this->alice->getKey());
});

/*
 * The phantom-banner case. A lost participant_left leaves state saying a huddle
 * is running when LiveKit knows it is not, and nothing else would ever correct
 * it.
 */
it('clears state for a room livekit no longer has', function () {
    $registry = app(HuddleRegistry::class);
    $registry->markJoined($this->room->getKey(), [
        'identity' => 'u'.$this->alice->getKey(), 'sid' => 'PA_1',
        'id' => $this->alice->getKey(), 'name' => 'Alice', 'avatar_url' => null,
        'joined_at' => time(),
    ]);

    expect($registry->snapshot($this->room->getKey())['active'])->toBeTrue();

    $this->rooms = [];   // LiveKit has nothing

    $this->artisan('huddles:reconcile')->assertSuccessful();

    expect($registry->snapshot($this->room->getKey())['active'])->toBeFalse()
        ->and($registry->liveRoomIds())->toBe([]);
});

it('restores every roster after a cache flush', function () {
    $this->rooms = [liveRoom($this->roomName)];
    $this->participants = [participant($this->alice), participant($this->bob, 'PA_2')];

    $this->artisan('huddles:reconcile')->assertSuccessful();
    expect(app(HuddleRegistry::class)->snapshot($this->room->getKey())['participant_count'])->toBe(2);

    Cache::flush();
    expect(app(HuddleRegistry::class)->snapshot($this->room->getKey())['active'])->toBeFalse();

    $this->artisan('huddles:reconcile')->assertSuccessful();
    expect(app(HuddleRegistry::class)->snapshot($this->room->getKey())['participant_count'])->toBe(2);
});

/*
 * Once inside a huddle there is no next request, so the middleware that ejects a
 * suspended session never runs again. Without this, "suspended" would not mean
 * suspended for anybody already on the call.
 */
it('ejects a suspended account from a live huddle', function () {
    $this->rooms = [liveRoom($this->roomName)];
    $this->participants = [participant($this->alice), participant($this->bob, 'PA_2')];

    $this->bob->update(['status' => UserStatus::Suspended]);

    $this->artisan('huddles:reconcile')->assertSuccessful();

    expect($this->removed)->toContain('u'.$this->bob->getKey())
        ->and($this->removed)->not->toContain('u'.$this->alice->getKey());
});

it('ejects someone removed from a private room mid-huddle', function () {
    $this->rooms = [liveRoom($this->roomName)];
    $this->participants = [participant($this->alice), participant($this->bob, 'PA_2')];

    app(RoomProvisioner::class)->removeMember($this->room, $this->bob);

    $this->artisan('huddles:reconcile')->assertSuccessful();

    expect($this->removed)->toContain('u'.$this->bob->getKey())
        ->and($this->removed)->not->toContain('u'.$this->alice->getKey());
});

it('leaves an ordinary member alone', function () {
    $this->rooms = [liveRoom($this->roomName)];
    $this->participants = [participant($this->alice), participant($this->bob, 'PA_2')];

    $this->artisan('huddles:reconcile')->assertSuccessful();

    expect($this->removed)->toBe([]);
});

/*
 * empty_timeout only fires when a room is EMPTY. A tab left connected overnight
 * is billable egress and a banner nobody can clear.
 */
it('ends a huddle that has run past the maximum duration', function () {
    $maxSeconds = (int) config('hub.huddles.max_duration_minutes') * 60;

    $this->rooms = [liveRoom($this->roomName, $maxSeconds + 600)];
    $this->participants = [participant($this->alice)];

    $this->artisan('huddles:reconcile')->assertSuccessful();

    expect($this->deleted)->toContain($this->roomName)
        ->and(app(HuddleRegistry::class)->snapshot($this->room->getKey())['active'])->toBeFalse();
});

it('leaves a huddle that is still within the duration limit', function () {
    $this->rooms = [liveRoom($this->roomName, 120)];
    $this->participants = [participant($this->alice)];

    $this->artisan('huddles:reconcile')->assertSuccessful();

    expect($this->deleted)->toBe([]);
});

it('ends a huddle whose room has been deleted', function () {
    $roomId = $this->room->getKey();

    $this->rooms = [liveRoom($this->roomName)];
    $this->participants = [participant($this->alice)];

    $this->room->memberships()->delete();
    $this->room->delete();

    $this->artisan('huddles:reconcile')->assertSuccessful();

    expect($this->deleted)->toContain('hub-room-'.$roomId);
});

it('ignores livekit rooms that belong to something else', function () {
    $this->rooms = [liveRoom('some-other-product-42')];

    $this->artisan('huddles:reconcile')->assertSuccessful();

    expect($this->deleted)->toBe([])
        ->and($this->removed)->toBe([]);
});

/*
 * Stale state that heals next minute beats clearing every banner in the hub
 * because the SFU blipped.
 */
it('leaves cached state alone when livekit is unreachable', function () {
    $registry = app(HuddleRegistry::class);
    $registry->markJoined($this->room->getKey(), [
        'identity' => 'u'.$this->alice->getKey(), 'sid' => 'PA_1',
        'id' => $this->alice->getKey(), 'name' => 'Alice', 'avatar_url' => null,
        'joined_at' => time(),
    ]);

    $this->livekitDown = true;

    $this->artisan('huddles:reconcile')->assertFailed();

    expect($registry->snapshot($this->room->getKey())['active'])->toBeTrue();
});

it('does nothing at all when livekit is not configured', function () {
    config(['services.livekit.url' => null, 'services.livekit.key' => null, 'services.livekit.secret' => null]);

    $this->artisan('huddles:reconcile')->assertSuccessful();

    Http::assertNothingSent();
});

/*
 * A flush is followed by everybody loading a page at once. The valve must run
 * one reconcile, not one per visitor.
 */
it('rebuilds a flushed index once and then holds the gate', function () {
    $this->rooms = [liveRoom($this->roomName)];
    $this->participants = [participant($this->alice)];

    $registry = app(HuddleRegistry::class);

    expect($registry->liveIndexExists())->toBeFalse();

    $registry->ensureFresh();
    expect($registry->liveIndexExists())->toBeTrue();

    Cache::forget('huddle:live');
    $registry->ensureFresh();   // gate still held

    expect($registry->liveIndexExists())->toBeFalse();
});
