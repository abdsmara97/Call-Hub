<?php

use App\Enums\RoomType;
use App\Livewire\Hub\Conversation;
use App\Models\Administration;
use App\Models\MessageReaction;
use App\Models\Room;
use App\Models\User;
use App\Services\MessageService;
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

    $this->me = User::factory()->inAdministration($administration)->create(['name' => 'Me']);
    $this->me->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->them = User::factory()->inAdministration($administration)->create(['name' => 'Ivan']);
    $this->them->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->room = Room::factory()->create(['type' => RoomType::Public->value]);
    $this->room->members()->attach([$this->me->id, $this->them->id], ['joined_at' => now()]);

    $this->messages = app(MessageService::class);
    $this->message = $this->messages->send($this->them, $this->room, 'Conveyor jam cleared');

    $this->actingAs($this->me);
});

// ------------------------------------------------------------------- service

it('adds a reaction', function () {
    $added = $this->messages->toggleReaction($this->message, $this->me, '👍');

    expect($added)->toBeTrue()
        ->and(MessageReaction::where('message_id', $this->message->id)->count())->toBe(1);
});

it('removes the reaction when the same person taps the same emoji again', function () {
    $this->messages->toggleReaction($this->message, $this->me, '👍');
    $added = $this->messages->toggleReaction($this->message, $this->me, '👍');

    expect($added)->toBeFalse()
        ->and(MessageReaction::where('message_id', $this->message->id)->count())->toBe(0);
});

it('lets one person react with several different emoji', function () {
    $this->messages->toggleReaction($this->message, $this->me, '👍');
    $this->messages->toggleReaction($this->message, $this->me, '🎉');

    expect(MessageReaction::where('message_id', $this->message->id)->count())->toBe(2);
});

it('counts the same emoji from different people separately', function () {
    $this->messages->toggleReaction($this->message, $this->me, '👍');
    $this->messages->toggleReaction($this->message, $this->them, '👍');

    $summary = $this->message->fresh()->load('reactions.user')->reactionSummary($this->me);

    expect($summary)->toHaveCount(1)
        ->and($summary[0]['emoji'])->toBe('👍')
        ->and($summary[0]['count'])->toBe(2)
        ->and($summary[0]['mine'])->toBeTrue();
});

/*
 * An exclusion list was the first attempt and it let `<script>` through, since
 * angle brackets are symbols rather than punctuation. Validation is positive
 * now: it must be a pictograph.
 */
it('rejects plain text dressed up as a reaction', function () {
    $notEmoji = ['lol', '123', '<script>', '   ', '.', '<b>x</b>', 'a👍', '👍 lol', '&#128077;'];

    $accepted = [];

    foreach ($notEmoji as $candidate) {
        try {
            $this->messages->toggleReaction($this->message, $this->me, $candidate);
            $accepted[] = $candidate;
        } catch (InvalidArgumentException) {
            // expected
        }
    }

    // Named, so a regression says which one slipped through.
    expect($accepted)->toBe([])
        ->and(MessageReaction::count())->toBe(0);
});

it('accepts the emoji the picker actually offers', function () {
    $offered = ['👍', '👎', '👏', '🙏', '❤️', '🔥', '🎉', '✅', '❌', '👀', '💯', '🚀',
        '⚠️', '🚨', '🚧', '⛔', '🟢', '❗', '❓', '☕', '⭐', '⏰', '🦺', '🧯'];

    foreach ($offered as $emoji) {
        $this->messages->toggleReaction($this->message, $this->me, $emoji);
    }

    expect(MessageReaction::where('message_id', $this->message->id)->count())
        ->toBe(count($offered));
});

it('rejects an over-long string', function () {
    expect(fn () => $this->messages->toggleReaction($this->message, $this->me, str_repeat('👍', 20)))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts a multi-codepoint emoji', function () {
    // Skin tone and ZWJ sequences are several characters long.
    $this->messages->toggleReaction($this->message, $this->me, '👍🏽');

    expect(MessageReaction::where('emoji', '👍🏽')->exists())->toBeTrue();
});

// -------------------------------------------------------------------- summary

it('reports who reacted, naming the viewer as You', function () {
    $this->messages->toggleReaction($this->message, $this->me, '👍');
    $this->messages->toggleReaction($this->message, $this->them, '👍');

    $summary = $this->message->fresh()->load('reactions.user')->reactionSummary($this->me);

    expect($summary[0]['who'])->toContain('You')
        ->and($summary[0]['who'])->toContain('Ivan');
});

it('does not mark a reaction as mine when it is somebody else', function () {
    $this->messages->toggleReaction($this->message, $this->them, '🎉');

    $summary = $this->message->fresh()->load('reactions.user')->reactionSummary($this->me);

    expect($summary[0]['mine'])->toBeFalse()
        ->and($summary[0]['who'])->toBe('Ivan');
});

it('returns nothing for a message no one has reacted to', function () {
    expect($this->message->reactionSummary($this->me))->toBe([]);
});

// ------------------------------------------------------------------ component

it('reacts through the conversation', function () {
    Livewire::test(Conversation::class, ['roomId' => $this->room->id])
        ->call('react', $this->message->id, '👍')
        ->assertHasNoErrors();

    expect(MessageReaction::where('user_id', $this->me->id)->where('emoji', '👍')->exists())->toBeTrue();
});

it('toggles off through the conversation', function () {
    $component = Livewire::test(Conversation::class, ['roomId' => $this->room->id])
        ->call('react', $this->message->id, '👍');

    $component->call('react', $this->message->id, '👍');

    expect(MessageReaction::count())->toBe(0);
});

it('surfaces a rejected reaction as an error rather than storing it', function () {
    Livewire::test(Conversation::class, ['roomId' => $this->room->id])
        ->call('react', $this->message->id, 'not an emoji')
        ->assertHasErrors('reaction');

    expect(MessageReaction::count())->toBe(0);
});

it('renders the chip with its count', function () {
    $this->messages->toggleReaction($this->message, $this->them, '🎉');

    Livewire::test(Conversation::class, ['roomId' => $this->room->id])
        ->assertSee('🎉', escape: false)
        ->assertSee('Ivan reacted with');
});

// -------------------------------------------------------------------- policy

it('stops someone who has not joined a public room from reacting', function () {
    $outsider = User::factory()->inAdministration(Administration::first())->create();
    $outsider->assignRole(Permissions::ROLE_EMPLOYEE);

    // A public room is readable without joining — the reaction policy is what
    // closes that gap, exactly as poll voting does.
    expect($outsider->can('view', $this->room))->toBeTrue()
        ->and($outsider->can('react', $this->message))->toBeFalse();

    $this->actingAs($outsider);

    Livewire::test(Conversation::class, ['roomId' => $this->room->id])
        ->call('react', $this->message->id, '👍')
        ->assertForbidden();

    expect(MessageReaction::count())->toBe(0);
});

it('does not let anyone react to a deleted message', function () {
    $this->messages->delete($this->message);

    expect($this->me->can('react', $this->message->fresh()))->toBeFalse();
});

it('drops the reactions when the message is hard deleted', function () {
    $this->messages->toggleReaction($this->message, $this->me, '👍');

    $this->message->forceDelete();

    expect(MessageReaction::count())->toBe(0);
});
