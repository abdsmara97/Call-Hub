<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Finds the people named in a message body.
 *
 * Pure: no database, no framework, no side effects — which is the point, because
 * every interesting failure mode lives here and is far cheaper to pin down in a
 * unit test than through a Livewire component.
 *
 * Candidates are always the room's own members. Never fall back to a global user
 * lookup: that would confirm the existence and exact spelling of names across a
 * private room boundary, and would notify someone about a message they cannot
 * open.
 */
class MentionParser
{
    /**
     * @param  Collection<int, \App\Models\User>  $candidates  Members of the room.
     * @return list<array{user_id: int, start: int, length: int}>  Character offsets, ordered by position.
     */
    public function parse(?string $body, Collection $candidates): array
    {
        $body = (string) $body;

        if ($body === '' || $candidates->isEmpty()) {
            return [];
        }

        // Longest name first, so a room holding both "Ivan" and "Ivan Petrov"
        // resolves "@Ivan Petrov" to the right person.
        $names = $candidates
            ->filter(fn ($user) => filled($user->name))
            ->sortByDesc(fn ($user) => mb_strlen($user->name))
            ->values();

        $found = [];
        $length = mb_strlen($body);
        $cursor = 0;

        while ($cursor < $length) {
            $at = mb_strpos($body, '@', $cursor);

            if ($at === false) {
                break;
            }

            // The '@' must start a word. Without this, the domain half of
            // "bob@example.com" reads as a mention.
            if (! $this->isWordStart($body, $at)) {
                $cursor = $at + 1;

                continue;
            }

            $match = $this->matchAt($body, $at, $names, $length);

            if ($match === null) {
                $cursor = $at + 1;

                continue;
            }

            foreach ($match['user_ids'] as $userId) {
                $found[] = ['user_id' => $userId, 'start' => $at, 'length' => $match['length']];
            }

            // Resume past the match so spans never overlap.
            $cursor = $at + $match['length'];
        }

        return $found;
    }

    /**
     * The name(s) sitting immediately after the '@' at $at.
     *
     * @param  Collection<int, \App\Models\User>  $names
     * @return array{user_ids: list<int>, length: int}|null
     */
    private function matchAt(string $body, int $at, Collection $names, int $length): ?array
    {
        foreach ($names as $user) {
            $name = (string) $user->name;
            $candidateLength = 1 + mb_strlen($name);

            if ($at + $candidateLength > $length) {
                continue;
            }

            $slice = mb_substr($body, $at + 1, mb_strlen($name));

            if (mb_strtolower($slice) !== mb_strtolower($name)) {
                continue;
            }

            // The name must end a word too, or "@Ivan Petrov" would claim the
            // first half of "@Ivan Petrovich" and ping the wrong colleague.
            if (! $this->isWordEnd($body, $at + $candidateLength, $length)) {
                continue;
            }

            // Two members genuinely sharing a full name both get it. Notifying
            // two people who share a name beats silently notifying neither.
            $sameName = $names
                ->filter(fn ($other) => mb_strtolower((string) $other->name) === mb_strtolower($name))
                ->map(fn ($other) => (int) $other->getKey())
                ->values()
                ->all();

            return ['user_ids' => $sameName, 'length' => $candidateLength];
        }

        return null;
    }

    /** True when nothing alphanumeric immediately precedes the offset. */
    private function isWordStart(string $body, int $at): bool
    {
        if ($at === 0) {
            return true;
        }

        return preg_match('/[\p{L}\p{N}]/u', mb_substr($body, $at - 1, 1)) !== 1;
    }

    /** True when nothing alphanumeric immediately follows the offset. */
    private function isWordEnd(string $body, int $after, int $length): bool
    {
        if ($after >= $length) {
            return true;
        }

        return preg_match('/[\p{L}\p{N}]/u', mb_substr($body, $after, 1)) !== 1;
    }
}
