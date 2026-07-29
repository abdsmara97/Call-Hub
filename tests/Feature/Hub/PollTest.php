<?php

use App\Livewire\Hub\Conversation;
use App\Models\Administration;
use App\Models\Poll;
use App\Models\PollVote;
use App\Models\Room;
use App\Models\User;
use App\Services\PollService;
use App\Support\Permissions;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(OrganisationSeeder::class);

    $administration = Administration::first();

    $this->author = User::factory()->inAdministration($administration)->create();
    $this->author->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->voter = User::factory()->inAdministration($administration)->create();
    $this->voter->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->room = Room::factory()->create();
    $this->room->members()->attach([$this->author->id, $this->voter->id], ['joined_at' => now()]);

    $this->polls = app(PollService::class);
});

// ------------------------------------------------------------------- service

it('posts a poll as a timeline message carrying the question', function () {
    $poll = $this->polls->create($this->author, $this->room, 'Which day suits?', ['Monday', 'Tuesday']);

    expect($poll->message)->not->toBeNull()
        ->and($poll->message->body)->toBe('Which day suits?')
        ->and($poll->message->room_id)->toBe($this->room->id)
        ->and($poll->message->poll->id)->toBe($poll->id)
        ->and($poll->options)->toHaveCount(2);
});

it('numbers options by their given order', function () {
    $poll = $this->polls->create($this->author, $this->room, 'Pick one', ['First', 'Second', 'Third']);

    expect($poll->options->pluck('label')->all())->toBe(['First', 'Second', 'Third'])
        ->and($poll->options->pluck('position')->all())->toBe([0, 1, 2]);
});

it('drops blank and duplicate options before counting them', function () {
    $poll = $this->polls->create(
        $this->author,
        $this->room,
        'Pick one',
        ['Yes', '  ', 'yes', 'No', ''],
    );

    expect($poll->options->pluck('label')->all())->toBe(['Yes', 'No']);
});

it('refuses a poll that has fewer than two distinct options', function () {
    expect(fn () => $this->polls->create($this->author, $this->room, 'Pick one', ['Yes', 'yes', '']))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a poll with no question', function () {
    expect(fn () => $this->polls->create($this->author, $this->room, '   ', ['Yes', 'No']))
        ->toThrow(InvalidArgumentException::class);
});

it('caps a poll at ten options', function () {
    $poll = $this->polls->create(
        $this->author,
        $this->room,
        'Pick one',
        array_map(fn ($i) => "Option {$i}", range(1, 15)),
    );

    expect($poll->options)->toHaveCount(10);
});

it('keeps one vote per person when someone changes their mind', function () {
    $poll = $this->polls->create($this->author, $this->room, 'Pick one', ['Yes', 'No']);
    [$yes, $no] = $poll->options->all();

    $this->polls->vote($poll, $this->voter, $yes->id);
    $this->polls->vote($poll, $this->voter, $no->id);

    expect(PollVote::where('poll_id', $poll->id)->where('user_id', $this->voter->id)->count())->toBe(1)
        ->and($poll->fresh()->chosenOptionIdFor($this->voter))->toBe($no->id);
});

it('retracts a vote and leaves the voter undecided', function () {
    $poll = $this->polls->create($this->author, $this->room, 'Pick one', ['Yes', 'No']);
    $yes = $poll->options->first();

    $this->polls->vote($poll, $this->voter, $yes->id);
    $this->polls->retractVote($poll, $this->voter);

    expect($poll->fresh()->totalVotes())->toBe(0)
        ->and($poll->fresh()->chosenOptionIdFor($this->voter))->toBeNull();
});

it('rejects an option belonging to another poll', function () {
    $poll = $this->polls->create($this->author, $this->room, 'Pick one', ['Yes', 'No']);
    $other = $this->polls->create($this->author, $this->room, 'Something else', ['A', 'B']);

    expect(fn () => $this->polls->vote($poll, $this->voter, $other->options->first()->id))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses votes once the poll has closed', function () {
    $poll = $this->polls->create($this->author, $this->room, 'Pick one', ['Yes', 'No']);
    $option = $poll->options->first();

    $this->polls->close($poll);

    expect($poll->fresh()->isClosed())->toBeTrue()
        ->and(fn () => $this->polls->vote($poll->fresh(), $this->voter, $option->id))
        ->toThrow(InvalidArgumentException::class);
});

it('treats a poll with a past closing time as closed', function () {
    $poll = $this->polls->create(
        $this->author,
        $this->room,
        'Pick one',
        ['Yes', 'No'],
        now()->subMinute(),
    );

    expect($poll->isClosed())->toBeTrue()
        ->and($poll->isOpen())->toBeFalse();
});

it('tallies every option including the ones nobody picked', function () {
    $poll = $this->polls->create($this->author, $this->room, 'Pick one', ['Yes', 'No', 'Maybe']);
    [$yes, $no, $maybe] = $poll->options->all();

    $this->polls->vote($poll, $this->author, $yes->id);
    $this->polls->vote($poll, $this->voter, $yes->id);

    $poll = $poll->fresh()->load(['options', 'votes']);

    expect($poll->tally())->toBe([$yes->id => 2, $no->id => 0, $maybe->id => 0])
        ->and($poll->totalVotes())->toBe(2)
        ->and($poll->shareOf($yes))->toBe(100)
        ->and($poll->shareOf($no))->toBe(0);
});

it('reports a zero share before anyone votes', function () {
    $poll = $this->polls->create($this->author, $this->room, 'Pick one', ['Yes', 'No']);

    expect($poll->shareOf($poll->options->first()))->toBe(0);
});

// -------------------------------------------------------------------- policy

it('lets a member vote but not an outsider', function () {
    $poll = $this->polls->create($this->author, $this->room, 'Pick one', ['Yes', 'No']);
    $outsider = User::factory()->inAdministration(Administration::first())->create();

    expect($this->voter->can('vote', $poll))->toBeTrue()
        ->and($outsider->can('vote', $poll))->toBeFalse();
});

it('lets the author or a moderator close a poll, but not a plain member', function () {
    $poll = $this->polls->create($this->author, $this->room, 'Pick one', ['Yes', 'No']);

    expect($this->author->can('close', $poll))->toBeTrue()
        ->and($this->voter->can('close', $poll))->toBeFalse();

    $this->room->memberships()->where('user_id', $this->voter->id)->update(['role' => 'moderator']);

    expect($this->voter->fresh()->can('close', $poll))->toBeTrue();
});

it('refuses to close a poll that is already closed', function () {
    $poll = $this->polls->create($this->author, $this->room, 'Pick one', ['Yes', 'No']);

    $this->polls->close($poll);

    expect($this->author->can('close', $poll->fresh()))->toBeFalse();
});

// ----------------------------------------------------------------- component

it('creates a poll through the conversation composer', function () {
    $this->actingAs($this->author);

    Livewire::test(Conversation::class, ['roomId' => $this->room->id])
        ->call('openPoll')
        ->assertSet('pollOpen', true)
        ->set('pollQuestion', 'Where are we meeting?')
        ->set('pollOptions', ['The office', 'Remote'])
        ->call('createPoll')
        ->assertHasNoErrors()
        ->assertSet('pollOpen', false);

    expect(Poll::where('room_id', $this->room->id)->count())->toBe(1);
});

it('validates the poll composer', function () {
    $this->actingAs($this->author);

    Livewire::test(Conversation::class, ['roomId' => $this->room->id])
        ->call('openPoll')
        ->set('pollQuestion', 'no')
        ->set('pollOptions', ['Only one', ''])
        ->call('createPoll')
        ->assertHasErrors('pollQuestion');

    expect(Poll::where('room_id', $this->room->id)->count())->toBe(0);
});

it('surfaces a one-option poll as an error rather than creating it', function () {
    $this->actingAs($this->author);

    Livewire::test(Conversation::class, ['roomId' => $this->room->id])
        ->call('openPoll')
        ->set('pollQuestion', 'Which one?')
        ->set('pollOptions', ['Only one', '  '])
        ->call('createPoll')
        ->assertHasErrors('pollOptions');

    expect(Poll::where('room_id', $this->room->id)->count())->toBe(0);
});

it('rejects a closing time in the past', function () {
    $this->actingAs($this->author);

    Livewire::test(Conversation::class, ['roomId' => $this->room->id])
        ->call('openPoll')
        ->set('pollQuestion', 'Which one?')
        ->set('pollOptions', ['Yes', 'No'])
        ->set('pollClosesAt', now()->subDay()->format('Y-m-d\TH:i'))
        ->call('createPoll')
        ->assertHasErrors('pollClosesAt');
});

it('keeps at least two option fields in the composer', function () {
    $this->actingAs($this->author);

    Livewire::test(Conversation::class, ['roomId' => $this->room->id])
        ->call('openPoll')
        ->call('removePollOption', 0)
        ->assertCount('pollOptions', 2)
        ->call('addPollOption')
        ->assertCount('pollOptions', 3)
        ->call('removePollOption', 0)
        ->assertCount('pollOptions', 2);
});

it('votes and retracts by clicking the same option twice', function () {
    $poll = $this->polls->create($this->author, $this->room, 'Pick one', ['Yes', 'No']);
    $yes = $poll->options->first();

    $this->actingAs($this->voter);

    $component = Livewire::test(Conversation::class, ['roomId' => $this->room->id])
        ->call('vote', $poll->id, $yes->id);

    expect($poll->fresh()->chosenOptionIdFor($this->voter))->toBe($yes->id);

    $component->call('vote', $poll->id, $yes->id);

    expect($poll->fresh()->chosenOptionIdFor($this->voter))->toBeNull();
});

it('stops an outsider voting through the component', function () {
    $poll = $this->polls->create($this->author, $this->room, 'Pick one', ['Yes', 'No']);

    // Public rooms are readable without joining, which is exactly the gap the
    // vote policy has to close.
    $outsider = User::factory()->inAdministration(Administration::first())->create();

    $this->actingAs($outsider);

    Livewire::test(Conversation::class, ['roomId' => $this->room->id])
        ->call('vote', $poll->id, $poll->options->first()->id)
        ->assertForbidden();

    expect($poll->fresh()->totalVotes())->toBe(0);
});

it('closes a poll through the component and stops further voting', function () {
    $poll = $this->polls->create($this->author, $this->room, 'Pick one', ['Yes', 'No']);
    $yes = $poll->options->first();

    $this->actingAs($this->author);

    Livewire::test(Conversation::class, ['roomId' => $this->room->id])
        ->call('closePoll', $poll->id);

    expect($poll->fresh()->isClosed())->toBeTrue();

    $this->actingAs($this->voter);

    Livewire::test(Conversation::class, ['roomId' => $this->room->id])
        ->call('vote', $poll->id, $yes->id)
        ->assertForbidden();
});

it('renders the poll card with its options and running tally', function () {
    $poll = $this->polls->create($this->author, $this->room, 'Where are we meeting?', ['Office', 'Remote']);
    $this->polls->vote($poll, $this->author, $poll->options->first()->id);

    $this->actingAs($this->voter);

    $html = Livewire::test(Conversation::class, ['roomId' => $this->room->id])->html();

    expect($html)->toContain('Where are we meeting?')
        ->and($html)->toContain('Office')
        ->and($html)->toContain('Remote')
        ->and($html)->toContain('1 vote')
        // The bar is never the only signal — the share is written out too.
        ->and($html)->toContain('100%');
});

it('shows a poll question once, not twice, when it is also the message body', function () {
    $this->polls->create($this->author, $this->room, 'Where are we meeting?', ['Office', 'Remote']);

    $this->actingAs($this->voter);

    $html = Livewire::test(Conversation::class, ['roomId' => $this->room->id])->html();

    // The card renders the question; the message body block must not repeat it.
    expect($html)->not->toContain('whitespace-pre-wrap break-words text-base');
});
