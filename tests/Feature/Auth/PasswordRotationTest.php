<?php

use App\Livewire\Auth\RotatePassword;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/*
 * Admin-issued accounts start on a temporary password. Rotating it is the only
 * thing the account can do until it is done.
 */

test('the rotation screen bounces a user who has nothing to rotate', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(RotatePassword::class)
        ->assertRedirect(route('hub', absolute: false));
});

test('a user rotates the temporary password and is released to the hub', function () {
    $user = User::factory()->mustRotatePassword()->create();

    $this->actingAs($user);

    Livewire::test(RotatePassword::class)
        ->set('current_password', 'password')
        ->set('password', 'Sequoia-Harbour-41')
        ->set('password_confirmation', 'Sequoia-Harbour-41')
        ->call('rotate')
        ->assertHasNoErrors()
        ->assertRedirect(route('hub', absolute: false));

    $user->refresh();

    expect($user->must_change_password)->toBeFalse()
        ->and(Hash::check('Sequoia-Harbour-41', $user->password))->toBeTrue();

    $this->get(route('hub'))->assertOk();
});

test('rotation requires the correct temporary password', function () {
    $this->actingAs(User::factory()->mustRotatePassword()->create());

    Livewire::test(RotatePassword::class)
        ->set('current_password', 'not-the-temporary-one')
        ->set('password', 'Sequoia-Harbour-41')
        ->set('password_confirmation', 'Sequoia-Harbour-41')
        ->call('rotate')
        ->assertHasErrors('current_password');
});

test('rotation rejects a short or unconfirmed password', function () {
    $this->actingAs(User::factory()->mustRotatePassword()->create());

    Livewire::test(RotatePassword::class)
        ->set('current_password', 'password')
        ->set('password', 'short')
        ->set('password_confirmation', 'short')
        ->call('rotate')
        ->assertHasErrors('password');

    Livewire::test(RotatePassword::class)
        ->set('current_password', 'password')
        ->set('password', 'Sequoia-Harbour-41')
        ->set('password_confirmation', 'Sequoia-Harbour-42')
        ->call('rotate')
        ->assertHasErrors('password');
});
