<?php

use App\Enums\RoomType;
use App\Livewire\Hub\Conversation;
use App\Models\Administration;
use App\Models\Room;
use App\Models\User;
use App\Services\MessageService;
use App\Services\RoomProvisioner;
use App\Support\Permissions;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Livewire\Livewire;

/**
 * Read state is a per-member cursor, not a row per message per person, so
 * "who has seen this" is derived rather than stored. These pin that derivation.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(OrganisationSeeder::class);

    $administration = Administration::first();

    $this->me = User::factory()->inAdministration($administration)->create(['name' => 'Me Myself']);
    $this->me->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->ivan = User::factory()->inAdministration($administration)->create(['name' => 'Ivan Petrov']);
    $this->ivan->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->grace = User::factory()->inAdministration($administration)->create(['name' => 'Grace Lin']);
    $this->grace->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->room = Room::factory()->create(['name' => 'Plant Floor', 'type' => RoomType::Private->value]);
    $this->room->members()->attach(
        [$this->me->id, $this->ivan->id, $this->grace->id],
        ['joined_at' => now()],
    );

    $this->messages = app(MessageService::class);
    $this->actingAs($this->me);
});

// No return type: `use Livewire\Livewire` aliases the namespace, so an
// unqualified Livewire\… hint here resolves to Livewire\Livewire\….
function conversation()
{
    return Livewire::test(Conversation::class, ['roomId' => test()->room->id]);
}

it('shows nothing until somebody has read the message', function () {
    $this->messages->send($this->me, $this->room, 'Anyone about?');

    conversation()->assertDontSee('Seen by');
});

it('names the one person who has read it', function () {
    $message = $this->messages->send($this->me, $this->room, 'Anyone about?');

    $this->messages->markRead($this->room, $this->ivan, $message->id);

    conversation()->assertSee('Seen by Ivan Petrov');
});

it('counts once more than one person has read it', function () {
    $message = $this->messages->send($this->me, $this->room, 'Anyone about?');

    $this->messages->markRead($this->room, $this->ivan, $message->id);
    $this->messages->markRead($this->room, $this->grace, $message->id);

    conversation()
        ->assertSee('Seen by 2')
        // The names are still available, in the tooltip and to screen readers.
        ->assertSee('Grace Lin, Ivan Petrov');
});

it('does not count the author as having seen their own message', function () {
    $message = $this->messages->send($this->me, $this->room, 'Mine');

    // send() already moves the author's own cursor.
    $seen = conversation()->instance()->seenBy($message->id, $this->me->id);

    expect($seen)->not->toContain('Me Myself');
});

it('does not show receipts on somebody else message', function () {
    $message = $this->messages->send($this->ivan, $this->room, 'Theirs');

    $this->messages->markRead($this->room, $this->grace, $message->id);

    // Grace has read it, but this is not my message, so no receipt is rendered.
    conversation()->assertDontSee('Seen by');
});

it('treats a cursor further ahead as having seen the earlier message', function () {
    $first = $this->messages->send($this->me, $this->room, 'First');
    $second = $this->messages->send($this->me, $this->room, 'Second');

    $this->messages->markRead($this->room, $this->ivan, $second->id);

    $component = conversation()->instance();

    expect($component->seenBy($first->id, $this->me->id))->toBe(['Ivan Petrov'])
        ->and($component->seenBy($second->id, $this->me->id))->toBe(['Ivan Petrov']);
});

it('does not treat an older cursor as having seen a newer message', function () {
    $first = $this->messages->send($this->me, $this->room, 'First');

    $this->messages->markRead($this->room, $this->ivan, $first->id);

    $second = $this->messages->send($this->me, $this->room, 'Second');

    expect(conversation()->instance()->seenBy($second->id, $this->me->id))->toBe([]);
});

it('says just Seen in a direct message, where the reader is obvious', function () {
    $dm = app(RoomProvisioner::class)->findOrCreateDm($this->me, $this->ivan);

    $message = $this->messages->send($this->me, $dm, 'Are you on site?');

    $this->messages->markRead($dm, $this->ivan, $message->id);

    Livewire::test(Conversation::class, ['roomId' => $dm->id])
        ->assertSee('Seen')
        ->assertDontSee('Seen by');
});

it('ignores a reader who is no longer a member', function () {
    $message = $this->messages->send($this->me, $this->room, 'Anyone about?');

    $this->messages->markRead($this->room, $this->ivan, $message->id);

    app(RoomProvisioner::class)->removeMember($this->room, $this->ivan);

    expect(conversation()->instance()->seenBy($message->id, $this->me->id))->toBe([]);
});

/*
 * Rendering a room does issue a query per row for the pin/react policy checks,
 * which predates receipts. What matters here is that receipts add nothing: they
 * are derived from two arrays the component has already loaded.
 */
it('resolves a receipt without touching the database', function () {
    $ids = collect(range(1, 10))
        ->map(fn (int $i) => $this->messages->send($this->me, $this->room, "Message {$i}")->id);

    $this->messages->markRead($this->room, $this->ivan, $ids->last());

    $component = conversation()->instance();

    // Warm the two computed properties the way a render would.
    $component->seenBy($ids->first(), $this->me->id);

    DB::enableQueryLog();

    foreach ($ids as $id) {
        $component->seenBy($id, $this->me->id);
    }

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0);
});
