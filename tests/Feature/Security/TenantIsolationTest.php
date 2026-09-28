<?php

use App\Models\Administration;
use App\Models\Company;
use App\Models\Message;
use App\Models\Room;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\HuddleTokens;
use App\Support\Permissions;
use App\Support\TenantContext;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;

/*
 * The tenant boundary itself: two customers on one install must never see
 * each other — not in queries, not on the socket, not in LiveKit, not in
 * settings, not in search.
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(OrganisationSeeder::class);

    // Tenant A is the seeded default; its org chart already exists.
    $this->tenantA = Tenant::query()->where('slug', 'default')->firstOrFail();
    $administrationA = Administration::query()->firstOrFail();

    $this->userA = User::factory()->inAdministration($administrationA)->create(['name' => 'Alice Ours']);
    $this->userA->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->roomA = Room::factory()->create(['tenant_id' => $this->tenantA->getKey()]);
    $this->roomA->members()->attach($this->userA->getKey(), ['joined_at' => now()]);

    // Tenant B is built explicitly — the factories would otherwise join A.
    $this->tenantB = Tenant::factory()->create();

    $companyB = Company::factory()->create(['tenant_id' => $this->tenantB->getKey()]);
    $administrationB = Administration::factory()->create([
        'tenant_id' => $this->tenantB->getKey(),
        'company_id' => $companyB->getKey(),
    ]);

    $this->userB = User::factory()->create([
        'name' => 'Boris Foreign',
        'tenant_id' => $this->tenantB->getKey(),
        'company_id' => $companyB->getKey(),
        'administration_id' => $administrationB->getKey(),
    ]);
    $this->userB->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->roomB = Room::factory()->create(['tenant_id' => $this->tenantB->getKey()]);
    $this->roomB->members()->attach($this->userB->getKey(), ['joined_at' => now()]);
});

// ------------------------------------------------------------------ queries

it('never shows the other tenant in the directory', function () {
    $this->actingAs($this->userA)
        ->get(route('directory'))
        ->assertOk()
        ->assertSee('Alice Ours')
        ->assertDontSee('Boris Foreign');
});

it('404s a room that belongs to another tenant, even a public one', function () {
    expect($this->roomB->type->value)->toBe('public');

    $this->actingAs($this->userA)
        ->get(route('rooms.show', $this->roomB->getKey()))
        ->assertNotFound();
});

it('scopes queries while a context is bound and not otherwise', function () {
    $context = app(TenantContext::class);

    $context->set($this->tenantA);

    expect(User::query()->pluck('tenant_id')->unique()->all())->toBe([$this->tenantA->getKey()])
        ->and(Room::query()->pluck('tenant_id')->unique()->all())->toBe([$this->tenantA->getKey()])
        // The escape hatch sees everything even while bound.
        ->and(Room::acrossTenants()->pluck('tenant_id')->unique()->sort()->values()->all())
        ->toBe([$this->tenantA->getKey(), $this->tenantB->getKey()]);

    $context->set(null);

    expect(User::query()->pluck('tenant_id')->unique()->count())->toBe(2);
});

// ----------------------------------------------------------------- channels

/**
 * Channel auth needs a Pusher-protocol broadcaster; the suite runs on the
 * null driver, which authorises everything. Reverb signs offline.
 */
function usePusherProtocolBroadcaster(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
        'broadcasting.connections.reverb.options.host' => 'localhost',
        'broadcasting.connections.reverb.options.port' => 8080,
        'broadcasting.connections.reverb.options.scheme' => 'http',
        'broadcasting.connections.reverb.options.useTLS' => false,
    ]);

    // Channels were registered on the boot-time null broadcaster; the
    // freshly resolved reverb one starts empty. Re-run the channel routes
    // so the authorisation callbacks exist on the driver under test.
    require base_path('routes/channels.php');
}

it('authorises a member on their own tenant room channel', function () {
    usePusherProtocolBroadcaster();

    $channel = 'private-tenant.'.$this->tenantA->getKey().'.room.'.$this->roomA->getKey();

    $this->actingAs($this->userA)
        ->post('/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '123.456'])
        ->assertOk();
});

it('refuses the other tenant room channel outright', function () {
    usePusherProtocolBroadcaster();

    $channel = 'private-tenant.'.$this->tenantB->getKey().'.room.'.$this->roomB->getKey();

    $this->actingAs($this->userA)
        ->post('/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '123.456'])
        ->assertForbidden();
});

it('keeps workspace presence per tenant', function () {
    usePusherProtocolBroadcaster();

    $own = 'presence-tenant.'.$this->tenantA->getKey().'.presence.online';
    $foreign = 'presence-tenant.'.$this->tenantB->getKey().'.presence.online';

    $this->actingAs($this->userA)
        ->post('/broadcasting/auth', ['channel_name' => $own, 'socket_id' => '123.456'])
        ->assertOk();

    $this->actingAs($this->userA)
        ->post('/broadcasting/auth', ['channel_name' => $foreign, 'socket_id' => '123.456'])
        ->assertForbidden();
});

// ------------------------------------------------------------------ livekit

it('segments livekit room names by tenant and refuses a cross-tenant claim', function () {
    $tokens = app(HuddleTokens::class);
    $prefix = config('hub.huddles.room_prefix');

    $name = $tokens->roomNameFor($this->roomA);

    expect($name)->toBe($prefix.$this->tenantA->getKey().'-'.$this->roomA->getKey())
        ->and($tokens->roomIdFrom($name))->toBe($this->roomA->getKey())
        // The same room claimed under the other tenant's id is unrecognised.
        ->and($tokens->roomIdFrom($prefix.$this->tenantB->getKey().'-'.$this->roomA->getKey()))->toBeNull();
});

// ----------------------------------------------------------------- settings

it('keeps settings per tenant', function () {
    $context = app(TenantContext::class);

    $context->runAs($this->tenantA, fn () => Setting::put('isolation.probe', 'a-only'));

    $fromA = $context->runAs($this->tenantA, fn () => Setting::get('isolation.probe', 'fallback'));
    $fromB = $context->runAs($this->tenantB, fn () => Setting::get('isolation.probe', 'fallback'));

    expect($fromA)->toBe('a-only')
        ->and($fromB)->toBe('fallback');
});

// -------------------------------------------------------------------- scout

it('stamps the tenant into the search payload', function () {
    $message = Message::factory()->create([
        'room_id' => $this->roomA->getKey(),
        'user_id' => $this->userA->getKey(),
        'body' => 'needle in the index',
    ]);

    expect($message->toSearchableArray())
        ->toHaveKey('tenant_id', $this->tenantA->getKey())
        ->toHaveKey('room_id', $this->roomA->getKey());
});
