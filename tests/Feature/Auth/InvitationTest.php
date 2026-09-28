<?php

use App\Livewire\Admin\Invitations;
use App\Livewire\Auth\AcceptInvitation;
use App\Mail\StaffInvitation;
use App\Models\Administration;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
 * Email invitations: the token is the whole credential — single-use,
 * expiring, replaced on re-invite, and scoped to one workspace.
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(OrganisationSeeder::class);

    $this->administration = Administration::first();

    $this->admin = User::factory()->inAdministration($this->administration)->create();
    $this->admin->assignRole(Permissions::ROLE_ADMIN);

    $this->employee = User::factory()->inAdministration($this->administration)->create();
    $this->employee->assignRole(Permissions::ROLE_EMPLOYEE);
});

// ------------------------------------------------------------------ sending

it('sends an invitation that carries its tenant and placement', function () {
    Mail::fake();

    Livewire::actingAs($this->admin)
        ->test(Invitations::class)
        ->set('email', 'newhire@example.com')
        ->call('invite')
        ->assertHasNoErrors();

    $invitation = Invitation::acrossTenants()->where('email', 'newhire@example.com')->firstOrFail();

    expect($invitation->tenant_id)->toBe($this->admin->tenant_id)
        ->and($invitation->token)->not->toBeEmpty()
        ->and($invitation->invited_by)->toBe($this->admin->getKey())
        ->and($invitation->expires_at->isFuture())->toBeTrue();

    Mail::assertSent(StaffInvitation::class, fn (StaffInvitation $mail) => $mail->hasTo('newhire@example.com'));
});

it('replaces the token on re-invite instead of stacking rows', function () {
    Mail::fake();

    $component = Livewire::actingAs($this->admin)->test(Invitations::class);

    $component->set('email', 'newhire@example.com')->call('invite');
    $first = Invitation::acrossTenants()->where('email', 'newhire@example.com')->firstOrFail()->token;

    $component->set('email', 'newhire@example.com')->call('invite');

    $rows = Invitation::acrossTenants()->where('email', 'newhire@example.com')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->token)->not->toBe($first);
});

it('keeps the invitations screen for administrators', function () {
    $this->actingAs($this->employee)
        ->get(route('admin.invitations'))
        ->assertForbidden();
});

// ---------------------------------------------------------------- accepting

it('creates the account inside the invited tenant on acceptance', function () {
    $invitation = Invitation::factory()->create([
        'email' => 'newhire@example.com',
        'invited_by' => $this->admin->getKey(),
    ]);

    Livewire::test(AcceptInvitation::class, ['token' => $invitation->token])
        ->set('name', 'New Hire')
        ->set('password', 'correct-horse-battery-staple-9')
        ->set('password_confirmation', 'correct-horse-battery-staple-9')
        ->call('accept')
        ->assertHasNoErrors()
        ->assertRedirect(route('hub'));

    $user = User::acrossTenants()->where('email', 'newhire@example.com')->firstOrFail();

    expect($user->tenant_id)->toBe($invitation->tenant_id)
        ->and($user->company_id)->toBe($invitation->company_id)
        ->and($user->administration_id)->toBe($invitation->administration_id)
        ->and($user->hasRole(Permissions::ROLE_EMPLOYEE))->toBeTrue()
        ->and($user->must_change_password)->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();

    $this->assertAuthenticatedAs($user);
});

it('refuses an expired invitation', function () {
    $invitation = Invitation::factory()->expired()->create([
        'invited_by' => $this->admin->getKey(),
    ]);

    $this->get(route('invitations.accept', $invitation->token))->assertStatus(410);
});

it('refuses an invitation that was already accepted', function () {
    $invitation = Invitation::factory()->accepted()->create([
        'invited_by' => $this->admin->getKey(),
    ]);

    $this->get(route('invitations.accept', $invitation->token))->assertStatus(410);
});

// -------------------------------------------------------------- cross-tenant

it('lets two workspaces invite the same address independently', function () {
    $tenantB = Tenant::factory()->create();
    $companyB = Company::factory()->create(['tenant_id' => $tenantB->getKey()]);
    $administrationB = Administration::factory()->create([
        'tenant_id' => $tenantB->getKey(),
        'company_id' => $companyB->getKey(),
    ]);
    $adminB = User::factory()->create([
        'tenant_id' => $tenantB->getKey(),
        'company_id' => $companyB->getKey(),
        'administration_id' => $administrationB->getKey(),
    ]);

    Invitation::factory()->create([
        'email' => 'shared@example.com',
        'invited_by' => $this->admin->getKey(),
    ]);

    Invitation::factory()->create([
        'email' => 'shared@example.com',
        'tenant_id' => $tenantB->getKey(),
        'company_id' => $companyB->getKey(),
        'administration_id' => $administrationB->getKey(),
        'invited_by' => $adminB->getKey(),
    ]);

    expect(Invitation::acrossTenants()->where('email', 'shared@example.com')->count())->toBe(2);
});
