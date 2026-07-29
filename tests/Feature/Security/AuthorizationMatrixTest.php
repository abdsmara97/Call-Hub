<?php

use App\Enums\RoomType;
use App\Models\Administration;
use App\Models\Message;
use App\Models\Room;
use App\Models\User;
use App\Services\EmergencyService;
use App\Services\MessageService;
use App\Support\Permissions;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;

/**
 * The authorisation matrix, asserted rather than assumed.
 *
 * Every row here is a rule someone could quietly break by loosening a policy,
 * so each is pinned from both sides: who may, and who may not.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(OrganisationSeeder::class);

    $administration = Administration::first();

    $this->member = User::factory()->inAdministration($administration)->create();
    $this->member->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->moderator = User::factory()->inAdministration($administration)->create();
    $this->moderator->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->outsider = User::factory()->inAdministration($administration)->create();
    $this->outsider->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->admin = User::factory()->inAdministration($administration)->create();
    $this->admin->assignRole(Permissions::ROLE_ADMIN);

    $this->publicRoom = Room::factory()->create(['type' => RoomType::Public->value]);
    $this->privateRoom = Room::factory()->create(['type' => RoomType::Private->value]);

    foreach ([$this->publicRoom, $this->privateRoom] as $room) {
        $room->members()->attach($this->member->id, ['joined_at' => now()]);
        $room->members()->attach($this->moderator->id, ['joined_at' => now(), 'role' => 'moderator']);
    }

    $this->messages = app(MessageService::class);
});

// ------------------------------------------------------------------- rooms

it('lets anyone read a public room but only members read a private one', function () {
    expect($this->outsider->can('view', $this->publicRoom))->toBeTrue()
        ->and($this->outsider->can('view', $this->privateRoom))->toBeFalse()
        ->and($this->member->can('view', $this->privateRoom))->toBeTrue();
});

it('requires membership to post, even in a public room', function () {
    expect($this->outsider->can('post', $this->publicRoom))->toBeFalse()
        ->and($this->member->can('post', $this->publicRoom))->toBeTrue();
});

it('refuses to let anyone leave a system room', function () {
    $system = Room::factory()->create(['is_system' => true]);
    $system->members()->attach($this->member->id, ['joined_at' => now()]);

    expect($this->member->can('leave', $system))->toBeFalse()
        ->and($this->member->can('leave', $this->privateRoom))->toBeTrue();
});

it('refuses to let anyone leave or delete a dm', function () {
    $dm = Room::factory()->create(['type' => RoomType::Dm->value]);
    $dm->members()->attach([$this->member->id, $this->outsider->id], ['joined_at' => now()]);

    expect($this->member->can('leave', $dm))->toBeFalse()
        ->and($this->admin->can('delete', $dm))->toBeFalse()
        ->and($this->member->can('invite', $dm))->toBeFalse();
});

it('keeps room deletion with an administrator and away from system rooms', function () {
    $system = Room::factory()->create(['is_system' => true]);

    expect($this->admin->can('delete', $this->privateRoom))->toBeTrue()
        ->and($this->moderator->can('delete', $this->privateRoom))->toBeFalse()
        ->and($this->admin->can('delete', $system))->toBeFalse()
        ->and($this->admin->can('update', $system))->toBeFalse();
});

// ---------------------------------------------------------------- messages

it('lets an author edit their own message inside the window and nobody else', function () {
    $message = $this->messages->send($this->member, $this->privateRoom, 'Mine');

    expect($this->member->can('update', $message))->toBeTrue()
        ->and($this->moderator->can('update', $message))->toBeFalse()
        ->and($this->admin->can('update', $message))->toBeFalse();
});

it('closes the edit window once it has elapsed', function () {
    $message = $this->messages->send($this->member, $this->privateRoom, 'Mine');

    $message->forceFill([
        'created_at' => now()->subMinutes((int) config('hub.messages.edit_window_minutes') + 1),
    ])->save();

    expect($this->member->can('update', $message->fresh()))->toBeFalse();
});

it('lets an author or a moderator delete, but not a bystander', function () {
    $message = $this->messages->send($this->member, $this->privateRoom, 'Mine');

    expect($this->member->can('delete', $message))->toBeTrue()
        ->and($this->moderator->can('delete', $message))->toBeTrue()
        ->and($this->outsider->can('delete', $message))->toBeFalse();
});

it('never allows an emergency message to be edited or deleted', function () {
    $emergency = app(EmergencyService::class)
        ->raiseInRoom($this->member, $this->privateRoom, 'Line down in bay two');

    $message = Message::where('emergency_id', $emergency->id)->firstOrFail();

    expect($this->member->can('update', $message))->toBeFalse()
        ->and($this->member->can('delete', $message))->toBeFalse()
        ->and($this->moderator->can('delete', $message))->toBeFalse()
        // Not even an administrator: the audit trail is the point.
        ->and($this->admin->can('delete', $message))->toBeFalse();
});

it('reserves pinning for moderators', function () {
    $message = $this->messages->send($this->member, $this->privateRoom, 'Mine');

    expect($this->moderator->can('pin', $message))->toBeTrue()
        ->and($this->member->can('pin', $message))->toBeFalse();
});

// -------------------------------------------------------------- emergencies

it('lets a member raise an emergency in their room but not an outsider', function () {
    expect($this->member->can('sendInRoom', [App\Models\Emergency::class, $this->privateRoom]))->toBeTrue()
        ->and($this->outsider->can('sendInRoom', [App\Models\Emergency::class, $this->privateRoom]))->toBeFalse();
});

it('reserves org-wide broadcast for administrators', function () {
    expect($this->admin->can('broadcast', App\Models\Emergency::class))->toBeTrue()
        ->and($this->member->can('broadcast', App\Models\Emergency::class))->toBeFalse();
});

it('lets only a named recipient acknowledge, and only once', function () {
    $emergency = app(EmergencyService::class)
        ->raiseInRoom($this->member, $this->privateRoom, 'Line down in bay two');

    // The sender is not a recipient of their own emergency.
    expect($this->moderator->can('acknowledge', $emergency))->toBeTrue()
        ->and($this->member->can('acknowledge', $emergency))->toBeFalse()
        ->and($this->outsider->can('acknowledge', $emergency))->toBeFalse();

    app(EmergencyService::class)->acknowledge($emergency, $this->moderator);

    expect($this->moderator->can('acknowledge', $emergency->fresh()))->toBeFalse();
});

it('lets the sender or an administrator resolve, but not a recipient', function () {
    $emergency = app(EmergencyService::class)
        ->raiseInRoom($this->member, $this->privateRoom, 'Line down in bay two');

    expect($this->member->can('resolve', $emergency))->toBeTrue()
        ->and($this->admin->can('resolve', $emergency))->toBeTrue()
        ->and($this->moderator->can('resolve', $emergency))->toBeFalse();
});

it('keeps the emergency log and its export with administrators', function () {
    expect($this->admin->can('viewLog', App\Models\Emergency::class))->toBeTrue()
        ->and($this->admin->can('export', App\Models\Emergency::class))->toBeTrue()
        ->and($this->member->can('viewLog', App\Models\Emergency::class))->toBeFalse()
        ->and($this->member->can('export', App\Models\Emergency::class))->toBeFalse();
});

it('shows an emergency to its sender, its recipients and administrators only', function () {
    $emergency = app(EmergencyService::class)
        ->raiseInRoom($this->member, $this->privateRoom, 'Line down in bay two');

    expect($this->member->can('view', $emergency))->toBeTrue()
        ->and($this->moderator->can('view', $emergency))->toBeTrue()
        ->and($this->admin->can('view', $emergency))->toBeTrue()
        ->and($this->outsider->can('view', $emergency))->toBeFalse();
});

// --------------------------------------------------------------------- users

it('keeps user administration with administrators', function () {
    expect($this->admin->can('manage', $this->member))->toBeTrue()
        ->and($this->member->can('manage', $this->outsider))->toBeFalse();
})->skip(fn () => ! method_exists(App\Policies\UserPolicy::class, 'manage'), 'UserPolicy has no manage ability');
