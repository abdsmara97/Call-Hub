<?php

use App\Enums\RoomType;
use App\Models\Emergency;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EmergencyService;
use App\Support\Permissions;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/*
 * The billing gate: raising emergencies is the paid add-on; the audit trail
 * never is. While billing is not enforced everything behaves as fully paid.
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(OrganisationSeeder::class);

    $administration = \App\Models\Administration::first();

    $this->admin = User::factory()->inAdministration($administration)->create();
    $this->admin->assignRole(Permissions::ROLE_ADMIN);

    $this->sender = User::factory()->inAdministration($administration)->create();
    $this->sender->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->peer = User::factory()->inAdministration($administration)->create();
    $this->peer->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->room = Room::factory()->create(['type' => RoomType::Private->value]);
    $this->room->members()->attach(
        [$this->sender->getKey(), $this->peer->getKey()],
        ['joined_at' => now()],
    );

    $this->tenant = Tenant::query()->firstOrFail();
});

it('behaves as fully paid while billing is not enforced', function () {
    config(['hub.billing.enforced' => false]);

    expect(Gate::forUser($this->sender)->allows('sendInRoom', [Emergency::class, $this->room]))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('broadcast', Emergency::class))->toBeTrue()
        ->and($this->tenant->hasEmergencyAddon())->toBeTrue();
});

it('locks raising but never the audit trail when unpaid', function () {
    // The emergency predates the lapse — raised while everything was paid.
    $emergency = app(EmergencyService::class)->raiseInRoom($this->sender, $this->room, 'Gas leak, bay 4');

    config(['hub.billing.enforced' => true]);

    expect(Gate::forUser($this->sender)->allows('sendInRoom', [Emergency::class, $this->room]))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('broadcast', Emergency::class))->toBeFalse()
        // Everything about the existing emergency keeps working.
        ->and(Gate::forUser($this->peer)->allows('acknowledge', $emergency))->toBeTrue()
        ->and(Gate::forUser($this->sender)->allows('view', $emergency))->toBeTrue()
        ->and(Gate::forUser($this->sender)->allows('resolve', $emergency))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('viewLog', Emergency::class))->toBeTrue();
});

it('unlocks with an active emergency subscription', function () {
    config(['hub.billing.enforced' => true]);

    DB::table('subscriptions')->insert([
        'tenant_id' => $this->tenant->getKey(),
        'type' => Tenant::SUBSCRIPTION_EMERGENCY,
        'stripe_id' => 'sub_fake_emergency',
        'stripe_status' => 'active',
        'quantity' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($this->tenant->fresh()->hasEmergencyAddon())->toBeTrue()
        ->and(Gate::forUser($this->sender)->allows('sendInRoom', [Emergency::class, $this->room]))->toBeTrue();
});

// ------------------------------------------------------------------ screens

it('shows the billing screen to administrators only', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.billing'))
        ->assertOk()
        ->assertSee('Billing is not enforced');

    $this->actingAs($this->sender)
        ->get(route('admin.billing'))
        ->assertForbidden();
});

it('hides checkout and portal while billing is not enforced', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.billing.checkout', 'seats'))
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->get(route('admin.billing.portal'))
        ->assertNotFound();
});
