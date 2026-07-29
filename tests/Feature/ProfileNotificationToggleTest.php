<?php

use App\Livewire\ProfileSettings;
use App\Models\Administration;
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

    $this->actingAs($this->user);
});

it('defaults a new account to being notified', function () {
    expect($this->user->notify_on_message)->toBeTrue();

    Livewire::test(ProfileSettings::class)->assertSet('notify_on_message', true);
});

it('saves the toggle the moment it is flicked, with no separate save step', function () {
    Livewire::test(ProfileSettings::class)
        ->set('notify_on_message', false)
        ->assertSet('notificationStatus', 'Message notifications are off. Emergencies will still reach you.');

    expect($this->user->fresh()->notify_on_message)->toBeFalse();
});

it('turns notifications back on', function () {
    $this->user->forceFill(['notify_on_message' => false])->save();

    Livewire::test(ProfileSettings::class)
        ->assertSet('notify_on_message', false)
        ->set('notify_on_message', true);

    expect($this->user->fresh()->notify_on_message)->toBeTrue();
});

it('says plainly that emergencies still get through', function () {
    Livewire::test(ProfileSettings::class)
        ->set('notify_on_message', false)
        ->assertSee('Emergencies will still reach you');
});

it('shows the toggle and explains quiet hours on the settings page', function () {
    $this->get(route('profile'))
        ->assertOk()
        ->assertSee('Notify me about new messages')
        // A fragment that sits on one line in the template: the rendered HTML
        // keeps the source line breaks, so a longer phrase would not match.
        ->assertSee('Quiet hours below silence these');
});

it('leaves other people preferences alone', function () {
    $other = User::factory()->inAdministration(Administration::first())->create();

    Livewire::test(ProfileSettings::class)->set('notify_on_message', false);

    expect($other->fresh()->notify_on_message)->toBeTrue();
});
