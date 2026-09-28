<?php

namespace App\Services;

use App\Exceptions\HuddleUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The four calls we make to LiveKit's server API, and nothing else.
 *
 * LiveKit speaks Twirp, which accepts plain JSON when asked, so this is an
 * ordinary Http::withToken() client of exactly the shape CallCredentials already
 * uses for Cloudflare. That is why there is no LiveKit SDK in composer.json: a
 * generated-protobuf dependency that has to move in lockstep with a server binary
 * deploy.sh deliberately never upgrades would be a trap, and this is the whole of
 * what we would have used it for.
 *
 * Everything here talks to loopback. The SFU binds 127.0.0.1 precisely so that
 * RemoveParticipant and DeleteRoom are not reachable from the internet.
 *
 * Two protojson traps are handled in the readers below: LiveKit returns
 * camelCase field names, and int64 fields arrive as JSON *strings*.
 */
class LiveKitApi
{
    private const TIMEOUT = 4;

    public function __construct(private HuddleTokens $tokens) {}

    /**
     * Create the room if it does not exist. Idempotent — LiveKit returns the
     * existing room rather than erroring.
     *
     * The cap passed here is the authoritative one. HuddleTokenController also
     * checks it before minting, but two people at cap-minus-one can both pass
     * that check, and this is what refuses the second at connect time. Note the
     * consequence: max_participants is fixed when the room is created, so
     * raising the admin setting does not affect a huddle already running.
     *
     * @return array<string, mixed>
     */
    public function createRoom(string $name, int $maxParticipants, int $emptyTimeout): array
    {
        return $this->call('CreateRoom', [
            'name' => $name,
            'maxParticipants' => $maxParticipants,
            'emptyTimeout' => $emptyTimeout,
        ]);
    }

    /**
     * The authoritative participant list for one room.
     *
     * The join path calls this every time rather than reading the cache. Reads
     * may be stale; the cap check never is.
     *
     * @return list<array<string, mixed>>
     */
    public function listParticipants(string $room): array
    {
        $response = $this->call('ListParticipants', ['room' => $room]);

        return array_values($response['participants'] ?? []);
    }

    /**
     * Every room LiveKit currently has. One call, and it is what makes the
     * reconcile sweep cheap.
     *
     * @return list<array<string, mixed>>
     */
    public function listRooms(): array
    {
        $response = $this->call('ListRooms', ['names' => []]);

        return array_values($response['rooms'] ?? []);
    }

    /** Eject someone. Used by the sweep for suspended or removed users. */
    public function removeParticipant(string $room, string $identity): void
    {
        $this->call('RemoveParticipant', ['room' => $room, 'identity' => $identity]);
    }

    /** End a huddle outright. Used by the sweep past max_duration_minutes. */
    public function deleteRoom(string $room): void
    {
        $this->call('DeleteRoom', ['room' => $room]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws HuddleUnavailable
     */
    private function call(string $method, array $payload): array
    {
        $endpoint = rtrim((string) config('services.livekit.api_url'), '/');

        try {
            $response = Http::asJson()
                ->withToken($this->tokens->forServer())
                ->timeout(self::TIMEOUT)
                ->post("{$endpoint}/twirp/livekit.RoomService/{$method}", $payload);
        } catch (ConnectionException) {
            // The SFU is down or the port moved. Callers turn this into a 503;
            // the message never carries the request, which was signed with the
            // secret that signs every join token in the hub.
            throw HuddleUnavailable::unreachable();
        }

        if ($response->failed()) {
            throw HuddleUnavailable::upstream($response->status());
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw HuddleUnavailable::malformed();
        }

        return $json;
    }
}
