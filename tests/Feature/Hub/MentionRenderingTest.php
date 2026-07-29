<?php

use App\Enums\RoomType;
use App\Livewire\Hub\Conversation;
use App\Models\Administration;
use App\Models\Room;
use App\Models\User;
use App\Services\MessageService;
use App\Support\Permissions;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Livewire\Livewire;

/**
 * Highlighting a mention is the one place this app was tempted to build HTML
 * around user text. It does not: the body is split into segments and Blade
 * escapes each one. These tests are what keeps that true.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(OrganisationSeeder::class);

    $administration = Administration::first();

    $this->me = User::factory()->inAdministration($administration)->create(['name' => 'Me Myself']);
    $this->me->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->them = User::factory()->inAdministration($administration)->create(['name' => 'Ivan Petrov']);
    $this->them->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->room = Room::factory()->create(['type' => RoomType::Private->value]);
    $this->room->members()->attach([$this->me->id, $this->them->id], ['joined_at' => now()]);

    $this->messages = app(MessageService::class);
    $this->actingAs($this->me);
});

function renderRoom(): string
{
    return Livewire::test(Conversation::class, ['roomId' => test()->room->id])->html();
}

it('escapes markup in an ordinary message', function () {
    $this->messages->send($this->them, $this->room, 'look <script>alert(1)</script> here');

    $html = renderRoom();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;');
});

/*
 * The decisive case. Names are set by an administrator, so a name containing
 * markup is reachable. An escape-then-string-replace highlighter fails here —
 * either it misses the replacement or it re-injects the raw name.
 */
it('escapes markup inside a highlighted mention', function () {
    $this->them->forceFill(['name' => '<b>Bob</b>'])->save();

    $this->messages->send($this->them, $this->room, 'ping @<b>Bob</b> now');

    $html = renderRoom();

    expect($html)->not->toContain('<b>Bob</b>')
        ->and($html)->toContain('&lt;b&gt;Bob&lt;/b&gt;');
});

it('escapes markup sitting next to a mention', function () {
    $this->messages->send($this->them, $this->room, '@Me Myself <img src=x onerror=alert(1)>');

    $html = renderRoom();

    expect($html)->not->toContain('<img src=x onerror=alert(1)>')
        ->and($html)->toContain('&lt;img');
});

it('highlights the mention and leaves the rest of the text alone', function () {
    $message = $this->messages->send($this->them, $this->room, 'Morning @Me Myself, bay two please');

    $segments = $message->fresh()->load('mentions')->bodySegments();

    expect($segments)->toHaveCount(3)
        ->and($segments[0])->toMatchArray(['type' => 'text', 'text' => 'Morning '])
        ->and($segments[1]['type'])->toBe('mention')
        ->and($segments[1]['text'])->toBe('@Me Myself')
        ->and($segments[2])->toMatchArray(['type' => 'text', 'text' => ', bay two please']);
});

/*
 * The container is whitespace-pre-wrap, so a stray newline in the Blade loop
 * becomes visible in the message.
 */
it('renders a plain message without adding whitespace', function () {
    $body = 'no mentions here';

    $message = $this->messages->send($this->them, $this->room, $body);

    $segments = $message->fresh()->bodySegments();

    expect($segments)->toBe([['type' => 'text', 'text' => $body, 'user_id' => null]]);

    // And the rendered element holds the body with no whitespace padded around
    // it. Livewire's morph markers are HTML comments and render as nothing, so
    // they are stripped before the check rather than asserted around.
    $html = preg_replace('/<!--\[if (BLOCK|ENDBLOCK)\]><!\[endif\]-->/', '', renderRoom());

    expect($html)->toContain('>'.$body.'</div>');
});

it('rebuilds the exact body from its segments', function () {
    $body = "Morning @Me Myself and @Ivan Petrov 🚧\nsecond line";

    $message = $this->messages->send($this->them, $this->room, $body);

    $rebuilt = collect($message->fresh()->load('mentions')->bodySegments())
        ->pluck('text')
        ->implode('');

    expect($rebuilt)->toBe($body);
});

it('drops a mention row that no longer fits the body rather than slicing garbage', function () {
    $message = $this->messages->send($this->them, $this->room, 'Morning @Me Myself');

    // Simulate a desynced row: the body shrank without a resync.
    $message->forceFill(['body' => 'hi'])->save();

    $segments = $message->fresh()->load('mentions')->bodySegments();

    expect(collect($segments)->pluck('text')->implode(''))->toBe('hi')
        ->and(collect($segments)->where('type', 'mention'))->toBeEmpty();
});

it('highlights a mention inside a thread reply', function () {
    $root = $this->messages->send($this->them, $this->room, 'Parent');
    $this->messages->send($this->them, $this->room, 'and @Me Myself too', parentId: $root->id);

    $html = Livewire::test(Conversation::class, ['roomId' => $this->room->id])
        ->call('toggleThread', $root->id)
        ->html();

    expect($html)->toContain('@Me Myself');
});
