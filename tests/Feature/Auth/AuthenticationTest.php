<?php

use App\Models\User;
use Livewire\Volt\Volt;

/*
 * There is no public registration and no dashboard: accounts are admin-issued
 * and every authenticated route lands on the hub.
 */

test('login screen can be rendered', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSeeVolt('pages.auth.login');
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    Volt::test('pages.auth.login')
        ->set('form.email', $user->email)
        ->set('form.password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('hub', absolute: false));

    $this->assertAuthenticated();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    Volt::test('pages.auth.login')
        ->set('form.email', $user->email)
        ->set('form.password', 'wrong-password')
        ->call('login')
        ->assertHasErrors('form.email')
        ->assertNoRedirect();

    $this->assertGuest();
});

test('a suspended account cannot hold a session', function () {
    $user = User::factory()->suspended()->create();

    Volt::test('pages.auth.login')
        ->set('form.email', $user->email)
        ->set('form.password', 'password')
        ->call('login')
        ->assertHasErrors('form.email');

    $this->assertGuest();
});

test('an account suspended mid-session loses it on the next request', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $user->forceFill(['status' => \App\Enums\UserStatus::Suspended->value])->save();

    $this->get(route('hub'))->assertRedirect(route('login'));

    $this->assertGuest();
});

test('a temporary password blocks every screen but the rotation', function () {
    $user = User::factory()->mustRotatePassword()->create();

    $this->actingAs($user);

    $this->get(route('hub'))->assertRedirect(route('password.rotate'));
    $this->get(route('directory'))->assertRedirect(route('password.rotate'));
    $this->get(route('password.rotate'))->assertOk();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
