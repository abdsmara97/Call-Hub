<?php

use App\Events\HuddleUpdated;
use App\Jobs\BroadcastHuddleState;
use App\Services\HuddleRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * What actually goes down the socket when a huddle changes.
 *
 * Two failure modes are being kept away here, and both of them only appear at
 * the scale this feature is allowed to run at — a company-wide room. One is an
 * oversized payload, which Reverb answers by closing the connection rather than
 * truncating. The other is a broadcast storm: fifty joins into a 300-member room
 * is 15,000 socket messages if nothing coalesces them.
 */
beforeEach(function () {
    config([
        'services.livekit.url' => 'ws://127.0.0.1:7880',
        'services.livekit.key' => 'test-key',
        'services.livekit.secret' => 'test-secret-long-enough-for-hs256-signing',
    ]);

    Cache::flush();
});

function fillHuddle(int $roomId, int $count): void
{
    $registry = app(HuddleRegistry::class);

    foreach (range(1, $count) as $n) {
        $registry->markJoined($roomId, [
            'identity' => 'u'.$n,
            'sid' => 'PA_'.$n,
            'id' => $n,
            // A realistically long name and avatar path: the size guard is
            // worthless if it is measured against "Bob".
            'name' => 'Alexandra Konstantinopoulos '.$n,
            'avatar_url' => '/storage/avatars/'.str_repeat('a', 40).'-'.$n.'.jpg',
            'joined_at' => 1754236800 + $n,
        ]);
    }
}

it('sends only a facepile but always the true participant count', function () {
    fillHuddle(7, 40);

    $snapshot = app(HuddleRegistry::class)->snapshot(7);

    expect($snapshot['participant_count'])->toBe(40)
        ->and($snapshot['participants'])->toHaveCount(config('hub.huddles.broadcast_participants'));
});

it('orders the facepile by who joined first', function () {
    fillHuddle(7, 12);

    $names = array_column(app(HuddleRegistry::class)->snapshot(7)['participants'], 'id');

    expect($names)->toBe([1, 2, 3, 4, 5, 6, 7, 8]);
});

/*
 * The structural mirror of the whisper-guard test in CallAuthorizationTest, and
 * for the same reason: Reverb closes a connection rather than truncating an
 * oversized message. If this fails, the symptom in production is every member of
 * a large room being disconnected the moment a huddle fills up.
 */
it('keeps a full broadcast payload under the socket message limit', function () {
    fillHuddle(7, 300);

    $payload = json_encode(app(HuddleRegistry::class)->snapshot(7));
    $socketLimit = (int) config('reverb.apps.apps.0.max_message_size');

    expect(strlen($payload))->toBeLessThan($socketLimit);
});

it('broadcasts the first change in a window immediately', function () {
    Event::fake([HuddleUpdated::class]);

    app(HuddleRegistry::class)->markStarted(7);

    Event::assertDispatched(HuddleUpdated::class, fn ($e) => $e->roomId === 7 && $e->snapshot['room_id'] === 7);
});

/*
 * The coalescing gate. Without it, a burst of joins is a broadcast per join to
 * every subscriber in the room.
 */
it('queues a trailing job instead of broadcasting again inside the window', function () {
    Queue::fake();
    Event::fake([HuddleUpdated::class]);

    $registry = app(HuddleRegistry::class);

    $registry->markStarted(7);              // inline
    $registry->markJoined(7, [              // suppressed, queues the trailing job
        'identity' => 'u1', 'sid' => 'PA_1', 'id' => 1,
        'name' => 'Grace', 'avatar_url' => null, 'joined_at' => time(),
    ]);

    Event::assertDispatchedTimes(HuddleUpdated::class, 1);
    Queue::assertPushed(BroadcastHuddleState::class, fn ($job) => $job->roomId === 7);
});

it('gives the trailing job a per-room unique id so a burst collapses to one', function () {
    expect((new BroadcastHuddleState(7))->uniqueId())->toBe('huddle-broadcast:7')
        ->and((new BroadcastHuddleState(8))->uniqueId())->not->toBe('huddle-broadcast:7');
});

/*
 * Every payload is a complete snapshot, so a dropped or out-of-order delivery is
 * harmless — but only if the client can tell which is newer.
 */
it('stamps a monotonic version on successive snapshots', function () {
    $registry = app(HuddleRegistry::class);

    $registry->markStarted(7);
    $first = $registry->snapshot(7)['version'];

    usleep(2000);

    $registry->markJoined(7, [
        'identity' => 'u1', 'sid' => 'PA_1', 'id' => 1,
        'name' => 'Grace', 'avatar_url' => null, 'joined_at' => time(),
    ]);
    $second = $registry->snapshot(7)['version'];

    expect($second)->toBeGreaterThan($first);
});

it('reports an inactive snapshot for a room with no huddle', function () {
    $snapshot = app(HuddleRegistry::class)->snapshot(999);

    expect($snapshot['active'])->toBeFalse()
        ->and($snapshot['participant_count'])->toBe(0)
        ->and($snapshot['participants'])->toBe([])
        ->and($snapshot['room_id'])->toBe(999);
});

it('broadcasts on the room private channel that already gates membership', function () {
    $room = \App\Models\Room::factory()->create();

    $event = new HuddleUpdated($room->id, ['room_id' => $room->id]);

    expect($event->broadcastOn()[0]->name)->toBe("private-tenant.{$room->tenant_id}.room.{$room->id}")
        ->and($event->broadcastAs())->toBe('huddle.updated');
});
