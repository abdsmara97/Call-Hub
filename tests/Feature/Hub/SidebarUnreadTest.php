<?php

use App\Enums\RoomType;
use App\Livewire\Hub\Sidebar;
use App\Models\Administration;
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

    $this->them = User::factory()->inAdministration($administration)->create(['name' => 'Them']);
    $this->them->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->openRoom = Room::factory()->create(['name' => 'Open Room', 'type' => RoomType::Private->value]);
    $this->otherRoom = Room::factory()->create(['name' => 'Other Room', 'type' => RoomType::Private->value]);

    foreach ([$this->openRoom, $this->otherRoom] as $room) {
        $room->members()->attach([$this->me->id, $this->them->id], ['joined_at' => now()]);
    }

    $this->messages = app(MessageService::class);
    $this->actingAs($this->me);
});

it('subscribes to every room the user belongs to, not just the open one', function () {
    $component = Livewire::test(Sidebar::class, ['activeRoomId' => $this->openRoom->id]);

    $method = new ReflectionMethod(Sidebar::class, 'getListeners');
    $method->setAccessible(true);

    $listeners = array_keys($method->invoke($component->instance()));

    expect($listeners)->toContain("echo-private:room.{$this->openRoom->id},.message.sent")
        ->and($listeners)->toContain("echo-private:room.{$this->otherRoom->id},.message.sent");
});

it('shows an unread count for a room the user is not looking at', function () {
    $this->messages->send($this->them, $this->otherRoom, 'First');
    $this->messages->send($this->them, $this->otherRoom, 'Second');

    Livewire::test(Sidebar::class, ['activeRoomId' => $this->openRoom->id])
        ->assertSee('Other Room')
        ->assertSee('2 unread messages');
});

it('uses the singular for a single unread message', function () {
    $this->messages->send($this->them, $this->otherRoom, 'Only one');

    Livewire::test(Sidebar::class, ['activeRoomId' => $this->openRoom->id])
        ->assertSee('1 unread message');
});

it('does not count the user own messages as unread', function () {
    $this->messages->send($this->me, $this->otherRoom, 'Mine');

    Livewire::test(Sidebar::class, ['activeRoomId' => $this->openRoom->id])
        ->assertDontSee('unread message');
});

it('blinks a room when a message lands in it', function () {
    $message = $this->messages->send($this->them, $this->otherRoom, 'Look at me');

    Livewire::test(Sidebar::class, ['activeRoomId' => $this->openRoom->id])
        ->call('onRoomMessage', [
            'id' => $message->id,
            'room_id' => $this->otherRoom->id,
            'user_id' => $this->them->id,
        ])
        ->assertSet("blinking.{$this->otherRoom->id}", true)
        ->assertSee('animate-room-blink', escape: false);
});

it('does not blink the room the user is already reading', function () {
    $message = $this->messages->send($this->them, $this->openRoom, 'In the open room');

    Livewire::test(Sidebar::class, ['activeRoomId' => $this->openRoom->id])
        ->call('onRoomMessage', [
            'id' => $message->id,
            'room_id' => $this->openRoom->id,
            'user_id' => $this->them->id,
        ])
        ->assertSet('blinking', [])
        ->assertDontSee('animate-room-blink', escape: false);
});

it('does not blink for the user own message', function () {
    $message = $this->messages->send($this->me, $this->otherRoom, 'Mine');

    Livewire::test(Sidebar::class, ['activeRoomId' => $this->openRoom->id])
        ->call('onRoomMessage', [
            'id' => $message->id,
            'room_id' => $this->otherRoom->id,
            'user_id' => $this->me->id,
        ])
        ->assertSet('blinking', []);
});

it('stops blinking when the animation finishes', function () {
    Livewire::test(Sidebar::class, ['activeRoomId' => $this->openRoom->id])
        ->call('onRoomMessage', ['room_id' => $this->otherRoom->id, 'user_id' => $this->them->id])
        ->assertSet("blinking.{$this->otherRoom->id}", true)
        ->call('stopBlinking', $this->otherRoom->id)
        ->assertSet('blinking', []);
});

it('stops blinking once the room is opened', function () {
    Livewire::test(Sidebar::class, ['activeRoomId' => $this->openRoom->id])
        ->call('onRoomMessage', ['room_id' => $this->otherRoom->id, 'user_id' => $this->them->id])
        ->assertSet("blinking.{$this->otherRoom->id}", true)
        ->call('clearBlinkForOpenedRoom', $this->otherRoom->id)
        ->assertSet('blinking', []);
});

it('clears the unread count once the room is read', function () {
    $this->messages->send($this->them, $this->otherRoom, 'Unread for now');

    Livewire::test(Sidebar::class, ['activeRoomId' => $this->openRoom->id])
        ->assertSee('1 unread message');

    $this->messages->markRead($this->otherRoom, $this->me);

    Livewire::test(Sidebar::class, ['activeRoomId' => $this->openRoom->id])
        ->assertDontSee('unread message');
});
