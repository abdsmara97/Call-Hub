<?php

use App\Livewire\Platform\Login as PlatformLogin;
use App\Livewire\Platform\Tenants;
use App\Models\Administration;
use App\Models\Company;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
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

    $administration = Administration::first();

    $this->operator = PlatformAdmin::factory()->create();

    $this->admin = User::factory()->inAdministration($administration)->create();
    $this->admin->assignRole(Permissions::ROLE_ADMIN);

    $this->employee = User::factory()->inAdministration($administration)->create();
    $this->employee->assignRole(Permissions::ROLE_EMPLOYEE);
});

// -------------------------------------------------------------------- access

it('lets a signed-in operator open the panel', function () {
    $this->actingAs($this->operator, 'platform')
        ->get(route('platform.tenants'))
        ->assertOk()
        ->assertSee('Workspaces');
});

it('sends an unauthenticated visitor to the platform login, not the hub one', function () {
    $this->get(route('platform.tenants'))
        ->assertRedirect(route('platform.login'));
});

it('refuses a hub session outright — even a workspace admin', function () {
    // Signed into the hub, but not into the platform guard.
    $this->actingAs($this->admin)
        ->get(route('platform.tenants'))
        ->assertRedirect(route('platform.login'));
});

it('gives an operator session nothing inside the hub', function () {
    // Sign in the way an operator actually does — through the platform
    // door, into the platform guard's session. actingAs() would also swap
    // the app's default guard, which no real request ever experiences.
    Livewire::test(PlatformLogin::class)
        ->set('email', $this->operator->email)
        ->set('password', 'password')
        ->call('login');

    expect(auth('platform')->check())->toBeTrue();

    // The hub still treats them as a guest: its auth middleware watches
    // the web guard, and the operator has no session there.
    $this->get(route('hub'))->assertRedirect(route('login'));
});

it('has no public signup route anymore', function () {
    expect(\Illuminate\Support\Facades\Route::has('signup'))->toBeFalse();

    $this->get('/signup')->assertNotFound();
});

// --------------------------------------------------------------------- login

it('signs an operator in through the platform login screen', function () {
    Livewire::test(PlatformLogin::class)
        ->set('email', $this->operator->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('platform.tenants'));

    expect(auth('platform')->id())->toBe($this->operator->id)
        ->and(auth('web')->check())->toBeFalse();
});

it('rejects hub credentials at the platform door', function () {
    Livewire::test(PlatformLogin::class)
        ->set('email', $this->admin->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors('email');

    expect(auth('platform')->check())->toBeFalse();
});

it('signs out to the platform login', function () {
    $this->actingAs($this->operator, 'platform')
        ->post(route('platform.logout'))
        ->assertRedirect(route('platform.login'));

    expect(auth('platform')->check())->toBeFalse();
});

// ------------------------------------------------------------------ creation

it('creates a workspace with its first administrator on a temporary password', function () {
    Livewire::actingAs($this->operator, 'platform')
        ->test(Tenants::class)
        ->set('workspace', 'Acme Logistics')
        ->set('adminName', 'Rana Farouk')
        ->set('adminEmail', 'rana@acme.example')
        ->call('create')
        ->assertHasNoErrors()
        ->assertSet('createdWorkspace', 'Acme Logistics')
        ->assertNotSet('temporaryPassword', null);

    $tenant = Tenant::query()->where('name', 'Acme Logistics')->sole();

    $company = Company::acrossTenants()
        ->where('tenant_id', $tenant->id)->where('name', 'Acme Logistics')->sole();

    $administration = Administration::acrossTenants()
        ->where('tenant_id', $tenant->id)->where('slug', 'general')->sole();

    $admin = User::acrossTenants()->where('email', 'rana@acme.example')->sole();

    expect($admin->tenant_id)->toBe($tenant->id)
        ->and($admin->company_id)->toBe($company->id)
        ->and($admin->administration_id)->toBe($administration->id)
        ->and($admin->hasRole(Permissions::ROLE_ADMIN))->toBeTrue()
        // Issued accounts rotate their password at first sign-in — the
        // workspace admin is no exception.
        ->and($admin->must_change_password)->toBeTrue();
});

it('provisions the new workspace inside its own tenant fence', function () {
    Livewire::actingAs($this->operator, 'platform')
        ->test(Tenants::class)
        ->set('workspace', 'Fence Test Co')
        ->set('adminName', 'A B')
        ->set('adminEmail', 'admin@fence.example')
        ->call('create')
        ->assertHasNoErrors();

    $tenant = Tenant::query()->where('name', 'Fence Test Co')->sole();

    // The new admin's system room landed in the new tenant, not elsewhere.
    $admin = User::acrossTenants()->where('email', 'admin@fence.example')->sole();
    $roomTenants = $admin->rooms()->withoutGlobalScope('tenant')->pluck('tenant_id')->unique();

    expect($roomTenants->all())->toBe([$tenant->id]);
});

it('refuses creation without an operator session', function () {
    Livewire::actingAs($this->admin)
        ->test(Tenants::class)
        ->assertForbidden();
});

it('rejects an administrator email that exists anywhere on the platform', function () {
    Livewire::actingAs($this->operator, 'platform')
        ->test(Tenants::class)
        ->set('workspace', 'Dup Co')
        ->set('adminName', 'Dup')
        ->set('adminEmail', $this->employee->email)
        ->call('create')
        ->assertHasErrors(['adminEmail' => 'unique']);
});

it('gives same-named workspaces distinct slugs', function () {
    $component = Livewire::actingAs($this->operator, 'platform')->test(Tenants::class);

    foreach (['one@same.example', 'two@same.example'] as $email) {
        $component
            ->set('workspace', 'Same Name')
            ->set('adminName', 'Someone')
            ->set('adminEmail', $email)
            ->call('create')
            ->assertHasNoErrors();
    }

    $slugs = Tenant::query()->where('name', 'Same Name')->pluck('slug');

    expect($slugs)->toHaveCount(2)
        ->and($slugs->unique())->toHaveCount(2);
});

// ------------------------------------------------------------------- listing

it('counts every workspace\'s accounts across the fence', function () {
    Livewire::actingAs($this->operator, 'platform')
        ->test(Tenants::class)
        ->set('workspace', 'Counted Co')
        ->set('adminName', 'C')
        ->set('adminEmail', 'c@counted.example')
        ->call('create')
        ->assertHasNoErrors();

    Livewire::actingAs($this->operator, 'platform')
        ->test(Tenants::class)
        ->assertSee('Counted Co')
        ->assertSee('1 account');
});
