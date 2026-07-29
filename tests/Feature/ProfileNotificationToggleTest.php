<?php

use App\Enums\MessageNotificationLevel;
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

it('defaults a new account to being told about everything', function () {
    expect($this->user->message_notifications)->toBe(MessageNotificationLevel::All);

    Livewire::test(ProfileSettings::class)->assertSet('message_notifications', 'all');
});

it('saves the choice the moment it is made, with no separate save step', function () {
    Livewire::test(ProfileSettings::class)
        ->set('message_notifications', 'mentions')
        ->assertSet('notificationStatus', 'You will only be notified when someone mentions you.');

    expect($this->user->fresh()->message_notifications)->toBe(MessageNotificationLevel::Mentions);
});

it('moves between all three levels', function () {
    $component = Livewire::test(ProfileSettings::class);

    foreach ([MessageNotificationLevel::None, MessageNotificationLevel::Mentions, MessageNotificationLevel::All] as $level) {
        $component->set('message_notifications', $level->value);

        expect($this->user->fresh()->message_notifications)->toBe($level);
    }
});

/*
 * The old control was a bool-typed property, so PHP sanitised anything Livewire
 * sent. A string property has no such protection — a client can $set whatever
 * it likes, and it must not reach the model.
 */
it('refuses a level that is not one of the three', function () {
    Livewire::test(ProfileSettings::class)
        ->set('message_notifications', 'everything')
        // The property snaps back to what is actually stored.
        ->assertSet('message_notifications', 'all');

    expect($this->user->fresh()->message_notifications)->toBe(MessageNotificationLevel::All);
});

it('does not persist an injected level even after a legitimate change', function () {
    $component = Livewire::test(ProfileSettings::class)
        ->set('message_notifications', 'mentions')
        ->set('message_notifications', '"; drop table users; --');

    $component->assertSet('message_notifications', 'mentions');

    expect($this->user->fresh()->message_notifications)->toBe(MessageNotificationLevel::Mentions);
});

it('says plainly that emergencies still get through', function () {
    Livewire::test(ProfileSettings::class)
        ->set('message_notifications', 'none')
        ->assertSee('Emergencies will still reach you');
});

it('shows all three options and explains quiet hours on the settings page', function () {
    $this->get(route('profile'))
        ->assertOk()
        ->assertSee('Tell me about new messages')
        ->assertSee('Only when someone mentions me')
        ->assertSee('Quiet hours below silence all of these');
});

it('leaves other people preferences alone', function () {
    $other = User::factory()->inAdministration(Administration::first())->create();

    Livewire::test(ProfileSettings::class)->set('message_notifications', 'none');

    expect($other->fresh()->message_notifications)->toBe(MessageNotificationLevel::All);
});
