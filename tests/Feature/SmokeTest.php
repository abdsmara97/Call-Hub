<?php

use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;

/**
 * Renders every page as a real signed-in user. Cheap, and it catches the class
 * of breakage — a bad view reference, a missing binding — that unit tests miss.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(OrganisationSeeder::class);

    $administration = \App\Models\Administration::first();

    $this->admin = User::factory()->inAdministration($administration)->create();
    $this->admin->assignRole(Permissions::ROLE_ADMIN);

    $this->employee = User::factory()->inAdministration($administration)->create();
    $this->employee->assignRole(Permissions::ROLE_EMPLOYEE);

    app(\App\Services\RoomProvisioner::class)->syncSystemRoomsFor($this->admin);
    app(\App\Services\RoomProvisioner::class)->syncSystemRoomsFor($this->employee);
});

it('shows the sign-in page to guests', function () {
    $this->get(route('login'))->assertOk()->assertSee('Sign in');
});

it('has no public registration route', function () {
    expect(Route::has('register'))->toBeFalse();
});

it('redirects guests away from the hub', function () {
    $this->get(route('hub'))->assertRedirect(route('login'));
});

$employeePages = ['hub', 'directory', 'saved', 'profile'];

foreach ($employeePages as $page) {
    it("renders the {$page} page for an employee", function () use ($page) {
        $this->actingAs($this->employee)->get(route($page))->assertOk();
    });
}

$adminPages = ['admin.users', 'admin.import', 'admin.broadcast', 'admin.emergency-log', 'admin.misuse', 'admin.settings'];

foreach ($adminPages as $page) {
    it("renders the {$page} page for an admin", function () use ($page) {
        $this->actingAs($this->admin)->get(route($page))->assertOk();
    });

    it("blocks an employee from {$page}", function () use ($page) {
        $this->actingAs($this->employee)->get(route($page))->assertForbidden();
    });
}

it('forces a password rotation before anything else', function () {
    $this->employee->forceFill(['must_change_password' => true])->save();

    $this->actingAs($this->employee)
        ->get(route('hub'))
        ->assertRedirect(route('password.rotate'));

    $this->actingAs($this->employee)
        ->get(route('password.rotate'))
        ->assertOk();
});

it('signs a suspended account out on its next request', function () {
    $this->employee->forceFill(['status' => \App\Enums\UserStatus::Suspended->value])->save();

    $this->actingAs($this->employee)
        ->get(route('hub'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
