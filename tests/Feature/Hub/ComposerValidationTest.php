<?php

use App\Livewire\Hub\Conversation;
use App\Models\Administration;
use App\Models\Room;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(OrganisationSeeder::class);

    $this->user = User::factory()->inAdministration(Administration::first())->create();
    $this->user->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->room = Room::factory()->create();
    $this->room->members()->attach($this->user->id, ['joined_at' => now()]);

    $this->actingAs($this->user);
});

it('reports an empty message as a validation error', function () {
    Livewire::test(Conversation::class, ['roomId' => $this->room->id])
        ->set('body', '')
        ->call('send')
        ->assertHasErrors('body');
});

it('reports an over-long message as a validation error', function () {
    Livewire::test(Conversation::class, ['roomId' => $this->room->id])
        ->set('body', str_repeat('a', config('hub.messages.max_length') + 1))
        ->call('send')
        ->assertHasErrors('body');
});

it('requires an armed emergency to actually say something', function () {
    Livewire::test(Conversation::class, ['roomId' => $this->room->id])
        ->set('emergencyArmed', true)
        ->set('body', 'ab')
        ->call('send')
        ->assertHasErrors('body');
});
