<?php

use App\Livewire\Auth\RegisterWorkspace;
use App\Models\Administration;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WorkspaceProvisioner;
use App\Support\Permissions;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Livewire\Livewire;

/*
 * Self-serve workspace creation: one signup produces a tenant, its first
 * company and administration, and the admin who owns them — atomically.
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

const STRONG_PASSWORD = 'correct-horse-battery-staple-9';

it('renders for guests', function () {
    $this->get(route('signup'))
        ->assertOk()
        ->assertSee('Create a workspace');
});

it('redirects the already signed in', function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(OrganisationSeeder::class);

    $user = User::factory()->inAdministration(Administration::first())->create();

    $this->actingAs($user)->get(route('signup'))->assertRedirect();
});

it('creates the tenant, org chart, and admin in one stroke', function () {
    Livewire::test(RegisterWorkspace::class)
        ->set('workspace', 'Acme Rockets')
        ->set('name', 'Rana Founder')
        ->set('email', 'rana@acme.example')
        ->set('password', STRONG_PASSWORD)
        ->set('password_confirmation', STRONG_PASSWORD)
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect(route('hub'));

    $tenant = Tenant::query()->where('name', 'Acme Rockets')->firstOrFail();
    $company = Company::acrossTenants()->where('tenant_id', $tenant->getKey())->firstOrFail();
    $administration = Administration::acrossTenants()->where('tenant_id', $tenant->getKey())->firstOrFail();
    $user = User::acrossTenants()->where('email', 'rana@acme.example')->firstOrFail();

    expect($company->name)->toBe('Acme Rockets')
        ->and($administration->name)->toBe('General')
        ->and($user->tenant_id)->toBe($tenant->getKey())
        ->and($user->company_id)->toBe($company->getKey())
        ->and($user->administration_id)->toBe($administration->getKey())
        ->and($user->hasRole(Permissions::ROLE_ADMIN))->toBeTrue()
        ->and($user->must_change_password)->toBeFalse()
        // The org-chart rooms exist from the first second.
        ->and($user->rooms()->where('is_system', true)->count())->toBe(2);

    $this->assertAuthenticatedAs($user);
});

it('gives two workspaces with one name distinct slugs', function () {
    $workspaces = app(WorkspaceProvisioner::class);

    $workspaces->create([
        'workspace' => 'Acme Rockets',
        'name' => 'First Founder',
        'email' => 'first@acme.example',
        'password' => STRONG_PASSWORD,
    ]);

    $workspaces->create([
        'workspace' => 'Acme Rockets',
        'name' => 'Second Founder',
        'email' => 'second@acme.example',
        'password' => STRONG_PASSWORD,
    ]);

    expect(Tenant::query()->pluck('slug')->all())
        ->toBe(['acme-rockets', 'acme-rockets-2']);
});

it('rejects an email that exists in any tenant', function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(OrganisationSeeder::class);

    User::factory()->inAdministration(Administration::first())
        ->create(['email' => 'taken@example.com']);

    Livewire::test(RegisterWorkspace::class)
        ->set('workspace', 'Another Org')
        ->set('name', 'Somebody Else')
        ->set('email', 'taken@example.com')
        ->set('password', STRONG_PASSWORD)
        ->set('password_confirmation', STRONG_PASSWORD)
        ->call('register')
        ->assertHasErrors(['email']);

    expect(Tenant::query()->where('name', 'Another Org')->exists())->toBeFalse();
});
