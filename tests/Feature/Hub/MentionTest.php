<?php

use App\Enums\RoomType;
use App\Livewire\Mentions;
use App\Models\Administration;
use App\Models\MessageMention;
use App\Models\Room;
use App\Models\User;
use App\Services\MessageService;
use App\Services\RoomProvisioner;
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

    $this->me = User::factory()->inAdministration($administration)->create(['name' => 'Me Myself']);
    $this->me->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->them = User::factory()->inAdministration($administration)->create(['name' => 'Ivan Petrov']);
    $this->them->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->outsider = User::factory()->inAdministration($administration)->create(['name' => 'Far Away']);
    $this->outsider->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->room = Room::factory()->create(['name' => 'Plant Floor', 'type' => RoomType::Private->value]);
    $this->room->members()->attach([$this->me->id, $this->them->id], ['joined_at' => now()]);

    $this->messages = app(MessageService::class);
    $this->actingAs($this->me);
});

// -------------------------------------------------------------------- writing

it('records a mention when a message names a member', function () {
    $message = $this->messages->send($this->them, $this->room, 'Can @Me Myself check bay two');

    expect($message->mentions)->toHaveCount(1)
        ->and($message->mentions->first()->user_id)->toBe($this->me->id);
});

it('records nothing for someone who is not in the room', function () {
    $message = $this->messages->send($this->them, $this->room, 'Ask @Far Away about it');

    expect($message->mentions)->toHaveCount(0);
});

it('records nothing when the body has no at-sign', function () {
    $message = $this->messages->send($this->them, $this->room, 'Me Myself should check bay two');

    expect($message->mentions)->toHaveCount(0);
});

it('records both people when a message names two members', function () {
    $message = $this->messages->send($this->them, $this->room, '@Me Myself and @Ivan Petrov');

    expect($message->mentions->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$this->me->id, $this->them->id])->sort()->values()->all());
});

it('records two rows when the same person is named twice', function () {
    $message = $this->messages->send($this->them, $this->room, '@Me Myself then @Me Myself again');

    expect($message->mentions)->toHaveCount(2);
});

// -------------------------------------------------------------------- editing

it('adds a mention when one is edited in', function () {
    $message = $this->messages->send($this->them, $this->room, 'Nothing here yet');

    expect($message->mentions)->toHaveCount(0);

    $this->messages->edit($message, 'Now with @Me Myself');

    expect($message->fresh()->mentions)->toHaveCount(1);
});

it('removes a mention when it is edited out', function () {
    $message = $this->messages->send($this->them, $this->room, 'Hello @Me Myself');

    expect($message->fresh()->mentions)->toHaveCount(1);

    $this->messages->edit($message, 'Hello everyone');

    expect($message->fresh()->mentions)->toHaveCount(0);
});

/*
 * Fixing a typo must not resurface a mention the recipient already dealt with,
 * which is why syncMentions diffs rather than deleting and reinserting.
 */
it('keeps a mention marked read when an unrelated edit happens', function () {
    $message = $this->messages->send($this->them, $this->room, 'Hello @Me Myself and the team');

    $message->fresh()->mentions->first()->forceFill(['read_at' => now()])->save();

    $this->messages->edit($message, 'Hello @Me Myself and the teams');

    expect($message->fresh()->mentions->first()->read_at)->not->toBeNull();
});

// ------------------------------------------------------------------ lifecycle

it('leaves the mention alone when the named person leaves the room', function () {
    $message = $this->messages->send($this->them, $this->room, 'Hello @Me Myself');

    app(RoomProvisioner::class)->removeMember($this->room, $this->me);

    // The message still says what it said; rewriting history on departure would
    // silently un-highlight old messages.
    expect($message->fresh()->mentions)->toHaveCount(1);
});

it('does not retroactively mention someone who joins later', function () {
    $message = $this->messages->send($this->them, $this->room, 'Ask @Far Away about it');

    $this->room->members()->attach($this->outsider->id, ['joined_at' => now()]);

    expect($message->fresh()->mentions)->toHaveCount(0);
});

it('drops the mentions when the message is hard deleted', function () {
    $message = $this->messages->send($this->them, $this->room, 'Hello @Me Myself');

    $message->forceDelete();

    expect(MessageMention::count())->toBe(0);
});

// ---------------------------------------------------------------------- inbox

it('lists a mention in the inbox', function () {
    $this->messages->send($this->them, $this->room, 'Can @Me Myself check bay two');

    Livewire::test(Mentions::class)
        ->assertSee('Ivan Petrov')
        ->assertSee('Plant Floor')
        ->assertSee('bay two');
});

it('shows an empty state when nobody has mentioned you', function () {
    Livewire::test(Mentions::class)->assertSee('Nobody has mentioned you yet');
});

it('does not list a mention you wrote yourself', function () {
    $this->messages->send($this->me, $this->room, 'Note to @Me Myself');

    Livewire::test(Mentions::class)->assertSee('Nobody has mentioned you yet');
});

it('drops a mention from the inbox once the message is deleted', function () {
    $message = $this->messages->send($this->them, $this->room, 'Hello @Me Myself');

    $this->messages->delete($message);

    Livewire::test(Mentions::class)->assertSee('Nobody has mentioned you yet');
});

it('hides a mention in a private room the user has left', function () {
    $this->messages->send($this->them, $this->room, 'Hello @Me Myself');

    app(RoomProvisioner::class)->removeMember($this->room, $this->me);

    Livewire::test(Mentions::class)->assertSee('Nobody has mentioned you yet');
});

it('keeps a mention in a public room the user has left, since they can still read it', function () {
    $public = Room::factory()->create(['type' => RoomType::Public->value]);
    $public->members()->attach([$this->me->id, $this->them->id], ['joined_at' => now()]);

    $this->messages->send($this->them, $public, 'Hello @Me Myself');

    app(RoomProvisioner::class)->removeMember($public, $this->me);

    Livewire::test(Mentions::class)->assertDontSee('Nobody has mentioned you yet');
});

it('never shows another person mentions', function () {
    $this->messages->send($this->me, $this->room, 'Over to @Ivan Petrov');

    Livewire::test(Mentions::class)->assertSee('Nobody has mentioned you yet');
});

it('marks everything read and clears the badge', function () {
    $this->messages->send($this->them, $this->room, 'Hello @Me Myself');

    expect(MessageMention::where('user_id', $this->me->id)->unread()->count())->toBe(1);

    Livewire::test(Mentions::class)
        ->call('markAllRead')
        ->assertSet('status', 'All mentions marked as read.');

    expect(MessageMention::where('user_id', $this->me->id)->unread()->count())->toBe(0);
});

it('says so when there was nothing unread to mark', function () {
    Livewire::test(Mentions::class)
        ->call('markAllRead')
        ->assertSet('status', 'You had no unread mentions.');
});

it('requires a signed-in user', function () {
    auth()->logout();

    $this->get(route('mentions'))->assertRedirect(route('login'));
});
