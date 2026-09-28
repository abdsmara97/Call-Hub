<?php

namespace App\Services;

use App\Events\HuddleUpdated;
use App\Jobs\BroadcastHuddleState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Who is in which huddle, right now — and nothing that outlives one.
 *
 * There is no calls table and there is no huddles table. This is the same
 * decision App\Services\CallService documents for 1:1 calls, held to under more
 * pressure: a huddle has a roster, a duration and a participant list, all of
 * which look like things you would store, and none of which anybody needs after
 * the huddle ends. So live state sits in the cache, LiveKit's own memory is the
 * authority behind it, and when the last person leaves there is nothing left.
 *
 * The cost of that choice is honest and bounded: flush the cache mid-huddle and
 * the banner disappears for up to a minute until HuddleReconcile rebuilds it
 * from LiveKit. Anyone already in the huddle is unaffected, because their media
 * path never consults this application at all.
 *
 * Every mutation runs under a lock. Read-modify-write on one shared cache entry
 * loses participants under a join storm, and a join storm is precisely what a
 * company-wide huddle is.
 */
class HuddleRegistry
{
    public function __construct(private HuddleTokens $tokens) {}

    /**
     * The wire snapshot for one room, or the inactive shape if there is none.
     *
     * @return array<string, mixed>
     */
    public function snapshot(int $roomId): array
    {
        return $this->toWire($roomId, Cache::get($this->stateKey($roomId)));
    }

    /** @return list<int> Room ids believed to have a live huddle. */
    public function liveRoomIds(): array
    {
        return array_values(Cache::get(self::LIVE_KEY, []));
    }

    /** Has the live index ever been built? Distinguishes "none" from "flushed". */
    public function liveIndexExists(): bool
    {
        return Cache::has(self::LIVE_KEY);
    }

    /**
     * The read-path safety valve.
     *
     * A missing live index means the cache was genuinely flushed, not that
     * nobody is huddling — an empty index is still an index. Waiting up to a
     * minute for the scheduled sweep would mean every banner in the hub is blank
     * after a `cache:clear`, so the first page load rebuilds it inline.
     *
     * Gated globally rather than per room: a flush is followed by everybody
     * loading a page at once, and this must be one reconcile per minute no
     * matter how many of them arrive.
     */
    public function ensureFresh(): void
    {
        if ($this->liveIndexExists()) {
            return;
        }

        if (! Cache::add('huddle:reconcile:gate', 1, 60)) {
            return;
        }

        Artisan::call('huddles:reconcile');
    }

    /**
     * A huddle exists. Records the start time if we did not already have one.
     *
     * room_started names no participant, so attribution waits for the first
     * join — see markJoined().
     */
    public function markStarted(int $roomId, ?int $startedAt = null): void
    {
        $this->mutate($roomId, function (?array $state) use ($startedAt) {
            $state ??= $this->blank();
            $state['started_at'] ??= $startedAt ?? time();

            return $state;
        });
    }

    /**
     * Someone joined.
     *
     * The name and avatar come from the token metadata LiveKit hands back, not
     * from the database. That is the whole reason this path can survive fifty
     * people clicking Join in five seconds: it is a cache read, an array write
     * and a cache write, with no User, no Room and no room_members query.
     *
     * @param  array<string, mixed>  $participant
     */
    public function markJoined(int $roomId, array $participant): void
    {
        $this->mutate($roomId, function (?array $state) use ($participant) {
            $state ??= $this->blank();
            $state['started_at'] ??= $participant['joined_at'] ?? time();
            $state['participants'][$participant['identity']] = $participant;

            /*
             * First join recorded wins, permanently. room_started carries no
             * identity, so the first participant is the best attribution
             * available — and webhooks are not ordered, so a late-arriving
             * earlier event must not rewrite it.
             */
            $state['started_by'] ??= [
                'id' => $participant['id'] ?? null,
                'name' => $participant['name'] ?? null,
            ];

            return $state;
        });
    }

    /**
     * Someone left — but only if it is the session we have on file.
     *
     * This guard is the subtlest thing in the feature. Identity is derived from
     * the user id, so opening a huddle in a second tab makes LiveKit disconnect
     * the first, and the participant_left for the OLD session can arrive after
     * the participant_joined for the new one. Without matching on the sid, that
     * race silently removes somebody who is sitting in the huddle. LiveKit does
     * not guarantee webhook ordering; this is what makes ordering not matter.
     */
    public function markLeft(int $roomId, string $identity, ?string $sid = null): void
    {
        $this->mutate($roomId, function (?array $state) use ($identity, $sid) {
            if ($state === null) {
                return null;
            }

            $known = $state['participants'][$identity] ?? null;

            if ($known === null) {
                return $state;
            }

            if ($sid !== null && ($known['sid'] ?? null) !== null && $known['sid'] !== $sid) {
                // A stale departure for a session we already replaced.
                return $state;
            }

            unset($state['participants'][$identity]);

            /*
             * Clear the moment the room empties rather than waiting for
             * room_finished, which LiveKit sends empty_timeout seconds later. A
             * banner that lingers a minute after everyone left is a bug people
             * report, and it would be our own doing.
             */
            return $state['participants'] === [] ? null : $state;
        });
    }

    /** The huddle is over. Usually a no-op, because markLeft got there first. */
    public function markFinished(int $roomId): void
    {
        $this->mutate($roomId, fn () => null);
    }

    /**
     * Replace a room's state wholesale from an authoritative participant list.
     *
     * Used by the reconcile sweep. Returns true when the roster actually
     * changed, so the caller only broadcasts when there is news.
     *
     * @param  list<array<string, mixed>>  $participants
     */
    public function replace(int $roomId, array $participants): bool
    {
        $changed = false;

        $this->mutate($roomId, function (?array $state) use ($participants, &$changed) {
            if ($participants === []) {
                $changed = $state !== null;

                return null;
            }

            $keyed = [];

            foreach ($participants as $participant) {
                $keyed[$participant['identity']] = $participant;
            }

            $before = array_keys($state['participants'] ?? []);
            $after = array_keys($keyed);
            sort($before);
            sort($after);
            $changed = $before !== $after;

            $state ??= $this->blank();
            $state['participants'] = $keyed;
            $state['started_at'] ??= min(array_column($participants, 'joined_at') ?: [time()]);
            $state['started_by'] ??= [
                'id' => $participants[0]['id'] ?? null,
                'name' => $participants[0]['name'] ?? null,
            ];

            return $state;
        }, broadcast: false);

        return $changed;
    }

    /**
     * Tell the room, coalescing bursts.
     *
     * The first event in a one-second window broadcasts inline, because the most
     * visible moment in this whole feature is somebody starting a huddle and
     * everyone's banner appearing — that must not wait on a queue hop. Anything
     * after it in the same window queues a single trailing job that re-reads and
     * sends the then-current state.
     *
     * The arithmetic is the justification: fifty joins into a 300-member room is
     * 15,000 socket messages without this and roughly 1,500 with it. Dropping
     * intermediate frames is safe because every payload is a full snapshot.
     */
    public function announce(int $roomId): void
    {
        if (Cache::add($this->beatKey($roomId), 1, 1)) {
            HuddleUpdated::dispatch($roomId, $this->snapshot($roomId));

            return;
        }

        BroadcastHuddleState::dispatch($roomId)->delay(now()->addSeconds(2));
    }

    /**
     * Has this webhook already been handled?
     *
     * LiveKit retries anything that is not a 2xx, and a retried participant_left
     * must not be processed twice.
     */
    public function isDuplicate(string $eventId): bool
    {
        return ! Cache::add($this->eventKey($eventId), 1, 300);
    }

    /**
     * Shape one participant for storage from a webhook payload.
     *
     * Two protojson traps live here: LiveKit's JSON is camelCase, and int64
     * fields arrive as strings.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function participantFrom(array $payload): ?array
    {
        $identity = $payload['identity'] ?? null;

        if (! is_string($identity) || $identity === '') {
            return null;
        }

        $metadata = json_decode((string) ($payload['metadata'] ?? ''), true);

        return [
            'identity' => $identity,
            'sid' => $payload['sid'] ?? null,
            'id' => $this->tokens->userIdFrom($identity),
            'name' => $payload['name'] ?? null,
            'avatar_url' => is_array($metadata) ? ($metadata['avatar_url'] ?? null) : null,
            'joined_at' => (int) ($payload['joinedAt'] ?? $payload['joined_at'] ?? time()),
        ];
    }

    private const LIVE_KEY = 'huddle:live';

    /** @return array<string, mixed> */
    private function blank(): array
    {
        return ['started_at' => null, 'started_by' => null, 'participants' => []];
    }

    /**
     * Read, modify, write, index and announce — all under one lock.
     *
     * @param  callable(array<string, mixed>|null): (array<string, mixed>|null)  $mutator
     */
    private function mutate(int $roomId, callable $mutator, bool $broadcast = true): void
    {
        $lock = Cache::lock($this->lockKey($roomId), 5);

        $lock->block(3, function () use ($roomId, $mutator, $broadcast) {
            $key = $this->stateKey($roomId);
            $next = $mutator(Cache::get($key));

            if ($next === null) {
                Cache::forget($key);
                $this->dropFromIndex($roomId);
            } else {
                $next['version'] = (int) (microtime(true) * 1000);
                Cache::put($key, $next, now()->addMinutes((int) config('hub.huddles.state_ttl_minutes')));
                $this->addToIndex($roomId);
            }

            if ($broadcast) {
                $this->announce($roomId);
            }
        });
    }

    /**
     * Reshape stored state for the wire.
     *
     * @param  array<string, mixed>|null  $state
     * @return array<string, mixed>
     */
    private function toWire(int $roomId, ?array $state): array
    {
        if ($state === null || ($state['participants'] ?? []) === []) {
            return [
                'room_id' => $roomId,
                'active' => false,
                'version' => (int) (microtime(true) * 1000),
                'started_at' => null,
                'started_by' => null,
                'participant_count' => 0,
                'participants' => [],
            ];
        }

        $participants = array_values($state['participants']);
        usort($participants, fn ($a, $b) => ($a['joined_at'] ?? 0) <=> ($b['joined_at'] ?? 0));

        /*
         * Only a facepile travels. Reverb closes a connection rather than
         * truncating an oversized message, so a 200-person roster would take the
         * socket down — the same failure .env.example already warns about for
         * video SDP. The true count rides alongside, which is all the banner
         * needs to say "Ana, Ben, Chloe and 24 others".
         */
        $limit = (int) config('hub.huddles.broadcast_participants');

        return [
            'room_id' => $roomId,
            'active' => true,
            'version' => $state['version'] ?? (int) (microtime(true) * 1000),
            'started_at' => $state['started_at'],
            'started_by' => $state['started_by'],
            'participant_count' => count($participants),
            'participants' => array_slice($participants, 0, $limit),
        ];
    }

    private function addToIndex(int $roomId): void
    {
        $live = Cache::get(self::LIVE_KEY, []);

        if (! in_array($roomId, $live, true)) {
            $live[] = $roomId;
            Cache::put(self::LIVE_KEY, array_values($live), now()->addMinutes((int) config('hub.huddles.state_ttl_minutes')));
        }
    }

    private function dropFromIndex(int $roomId): void
    {
        $live = Cache::get(self::LIVE_KEY, []);
        $next = array_values(array_diff($live, [$roomId]));

        Cache::put(self::LIVE_KEY, $next, now()->addMinutes((int) config('hub.huddles.state_ttl_minutes')));
    }

    /** Rewrite the live index outright. Only the reconcile sweep may do this. */
    public function replaceIndex(array $roomIds): void
    {
        Cache::put(self::LIVE_KEY, array_values($roomIds), now()->addMinutes((int) config('hub.huddles.state_ttl_minutes')));
    }

    private function stateKey(int $roomId): string
    {
        return "huddle:room:{$roomId}";
    }

    private function lockKey(int $roomId): string
    {
        return "huddle:lock:{$roomId}";
    }

    private function beatKey(int $roomId): string
    {
        return "huddle:beat:{$roomId}";
    }

    private function eventKey(string $eventId): string
    {
        return "huddle:evt:{$eventId}";
    }
}
