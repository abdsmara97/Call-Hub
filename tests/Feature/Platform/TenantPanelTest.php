<?php

use App\Livewire\Platform\Tenants;
use App\Models\Administration;
use App\Models\Company;
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

    $this->operator = User::factory()->inAdministration($administration)->create();
    $this->operator->assignRole(Permissions::ROLE_ADMIN);
    $this->operator->forceFill(['is_super_admin' => true])->save();

    $this->admin = User::factory()->inAdministration($administration)->create();
    $this->admin->assignRole(Permissions::ROLE_ADMIN);

    $this->employee = User::factory()->inAdministration($administration)->create();
    $this->employee->assignRole(Permissions::ROLE_EMPLOYEE);
});

// -------------------------------------------------------------------- access

it('lets the platform operator open the panel', function () {
    $this->actingAs($this->operator)
        ->get(route('platform.tenants'))
        ->assertOk()
        ->assertSee('Workspaces');
});

it('refuses a tenant admin — running a workspace is not running the platform', function () {
    $this->actingAs($this->admin)
        ->get(route('platform.tenants'))
        ->assertForbidden();
});

it('refuses an employee', function () {
    $this->actingAs($this->employee)
        ->get(route('platform.tenants'))
        ->assertForbidden();
});

it('sends guests to the login screen', function () {
    $this->get(route('platform.tenants'))->assertRedirect(route('login'));
});

it('has no public signup route anymore', function () {
    expect(\Illuminate\Support\Facades\Route::has('signup'))->toBeFalse();

    $this->get('/signup')->assertNotFound();
});

// ------------------------------------------------------------------ creation

it('creates a workspace with its first administrator on a temporary password', function () {
    Livewire::actingAs($this->operator)
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
        ->and($admin->is_super_admin)->toBeFalse()
        // Issued accounts rotate their password at first sign-in — the
        // workspace admin is no exception.
        ->and($admin->must_change_password)->toBeTrue();
});

it('provisions the new workspace inside its own tenant fence', function () {
    Livewire::actingAs($this->operator)
        ->test(Tenants::class)
        ->set('workspace', 'Fence Test Co')
        ->set('adminName', 'A B')
        ->set('adminEmail', 'admin@fence.example')
        ->call('create')
        ->assertHasNoErrors();

    $tenant = Tenant::query()->where('name', 'Fence Test Co')->sole();

    // The new admin's system room landed in the new tenant, not the operator's.
    $admin = User::acrossTenants()->where('email', 'admin@fence.example')->sole();
    $roomTenants = $admin->rooms()->withoutGlobalScope('tenant')->pluck('tenant_id')->unique();

    expect($roomTenants->all())->toBe([$tenant->id]);
});

it('refuses creation by anyone but the operator, even a tenant admin', function () {
    Livewire::actingAs($this->admin)
        ->test(Tenants::class)
        ->assertForbidden();
});

it('rejects an administrator email that exists anywhere on the platform', function () {
    Livewire::actingAs($this->operator)
        ->test(Tenants::class)
        ->set('workspace', 'Dup Co')
        ->set('adminName', 'Dup')
        ->set('adminEmail', $this->employee->email)
        ->call('create')
        ->assertHasErrors(['adminEmail' => 'unique']);
});

it('gives same-named workspaces distinct slugs', function () {
    $component = Livewire::actingAs($this->operator)->test(Tenants::class);

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
    Livewire::actingAs($this->operator)
        ->test(Tenants::class)
        ->set('workspace', 'Counted Co')
        ->set('adminName', 'C')
        ->set('adminEmail', 'c@counted.example')
        ->call('create')
        ->assertHasNoErrors();

    // The listing shows the other tenant's single account, which would read
    // 0 if the users count were run inside the operator's tenant scope.
    Livewire::actingAs($this->operator)
        ->test(Tenants::class)
        ->assertSee('Counted Co')
        ->assertSee('1 account');
});
