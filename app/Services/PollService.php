<?php

namespace App\Services;

use App\Events\PollUpdated;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PollService
{
    /**
     * A poll is posted as an ordinary message carrying the question, so it
     * appears in the timeline, threads, pins and searches like anything else.
     *
     * @param  array<int, string>  $options  Raw labels; blanks and duplicates are dropped.
     */
    public function create(
        User $author,
        Room $room,
        string $question,
        array $options,
        ?\DateTimeInterface $closesAt = null,
    ): Poll {
        $question = trim($question);
        $labels = $this->normaliseOptions($options);

        if ($question === '') {
            throw new \InvalidArgumentException('A poll needs a question.');
        }

        if (count($labels) < 2) {
            throw new \InvalidArgumentException('A poll needs at least two different options.');
        }

        $poll = DB::transaction(function () use ($author, $room, $question, $labels, $closesAt) {
            $message = app(MessageService::class)->send($author, $room, $question);

            $poll = Poll::create([
                'room_id' => $room->getKey(),
                'message_id' => $message->getKey(),
                'created_by' => $author->getKey(),
                'question' => $question,
                'closes_at' => $closesAt,
            ]);

            foreach (array_values($labels) as $position => $label) {
                PollOption::create([
                    'poll_id' => $poll->getKey(),
                    'label' => $label,
                    'position' => $position,
                ]);
            }

            return $poll;
        });

        return $poll->load(['options', 'votes', 'creator']);
    }

    /**
     * One vote per person: re-voting moves the existing row rather than adding
     * a second, which is what the unique(poll_id, user_id) index expects.
     */
    public function vote(Poll $poll, User $user, int $optionId): PollVote
    {
        if ($poll->isClosed()) {
            throw new \InvalidArgumentException('This poll has closed.');
        }

        $option = $poll->options()->whereKey($optionId)->first();

        if (! $option) {
            throw new \InvalidArgumentException('That option does not belong to this poll.');
        }

        $vote = PollVote::updateOrCreate(
            ['poll_id' => $poll->getKey(), 'user_id' => $user->getKey()],
            ['poll_option_id' => $option->getKey()],
        );

        $poll->load('votes');

        broadcast(new PollUpdated($poll))->toOthers();

        return $vote;
    }

    /** Removes the user's vote entirely, leaving them counted as undecided. */
    public function retractVote(Poll $poll, User $user): void
    {
        if ($poll->isClosed()) {
            throw new \InvalidArgumentException('This poll has closed.');
        }

        $poll->votes()->where('user_id', $user->getKey())->delete();

        $poll->load('votes');

        broadcast(new PollUpdated($poll))->toOthers();
    }

    /**
     * Closing is immediate and one-way. Votes are kept — the result is the
     * point of having asked.
     */
    public function close(Poll $poll): Poll
    {
        if ($poll->isOpen()) {
            $poll->forceFill(['closes_at' => now()])->save();

            broadcast(new PollUpdated($poll))->toOthers();
        }

        return $poll;
    }

    /**
     * Trims, drops blanks, removes case-insensitive duplicates and caps the
     * list, so the UI cannot post a poll the results view cannot render.
     *
     * @param  array<int, string>  $options
     * @return array<int, string>
     */
    private function normaliseOptions(array $options): array
    {
        $seen = [];
        $labels = [];

        foreach ($options as $option) {
            $label = trim((string) $option);

            if ($label === '') {
                continue;
            }

            $key = mb_strtolower($label);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $labels[] = mb_substr($label, 0, 120);

            if (count($labels) === 10) {
                break;
            }
        }

        return $labels;
    }
}
