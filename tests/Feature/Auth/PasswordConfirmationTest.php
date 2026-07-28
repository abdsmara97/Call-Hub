<?php

use App\Models\User;
use Livewire\Volt\Volt;

test('confirm password screen can be rendered', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('password.confirm'))
        ->assertOk()
        ->assertSeeVolt('pages.auth.confirm-password');
});

test('password can be confirmed', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('pages.auth.confirm-password')
        ->set('password', 'password')
        ->call('confirmPassword')
        ->assertHasNoErrors()
        ->assertRedirect(route('hub', absolute: false));
});

test('password is not confirmed with invalid password', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('pages.auth.confirm-password')
        ->set('password', 'wrong-password')
        ->call('confirmPassword')
        ->assertHasErrors('password')
        ->assertNoRedirect();
});
