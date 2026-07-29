<?php

use App\Enums\RoomType;
use App\Models\Administration;
use App\Models\Room;
use App\Models\User;
use App\Services\RoomProvisioner;
use App\Support\Permissions;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(OrganisationSeeder::class);

    $administration = Administration::first();

    $this->alice = User::factory()->inAdministration($administration)->create(['name' => 'Alice']);
    $this->alice->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->bob = User::factory()->inAdministration($administration)->create(['name' => 'Bob']);
    $this->bob->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->carol = User::factory()->inAdministration($administration)->create(['name' => 'Carol']);
    $this->carol->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->rooms = app(RoomProvisioner::class);
});

it('creates a dm on first contact', function () {
    $room = $this->rooms->findOrCreateDm($this->alice, $this->bob);

    expect($room->type)->toBe(RoomType::Dm)
        ->and($room->members()->count())->toBe(2)
        ->and($room->members->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->alice->id, $this->bob->id])->sort()->values()->all());
});

it('returns the same dm whichever direction it is opened from', function () {
    $first = $this->rooms->findOrCreateDm($this->alice, $this->bob);
    $second = $this->rooms->findOrCreateDm($this->bob, $this->alice);

    expect($second->id)->toBe($first->id)
        ->and(Room::where('type', RoomType::Dm->value)->count())->toBe(1);
});

it('keeps separate dms per pair', function () {
    $ab = $this->rooms->findOrCreateDm($this->alice, $this->bob);
    $ac = $this->rooms->findOrCreateDm($this->alice, $this->carol);

    expect($ac->id)->not->toBe($ab->id)
        ->and(Room::where('type', RoomType::Dm->value)->count())->toBe(2);
});

/*
 * The lookup used to be withCount() + having(), which is invalid without a
 * GROUP BY — SQLite refused it outright with "HAVING clause on a non-aggregate
 * query" and opening any DM threw a QueryException.
 */
it('never matches a group room that happens to contain both people', function () {
    $group = Room::factory()->create(['type' => RoomType::Private->value]);
    $group->members()->attach(
        [$this->alice->id, $this->bob->id, $this->carol->id],
        ['joined_at' => now()],
    );

    $dm = $this->rooms->findOrCreateDm($this->alice, $this->bob);

    expect($dm->id)->not->toBe($group->id)
        ->and($dm->type)->toBe(RoomType::Dm);
});

it('does not match a dm that only one of the pair belongs to', function () {
    $ab = $this->rooms->findOrCreateDm($this->alice, $this->bob);

    $ac = $this->rooms->findOrCreateDm($this->alice, $this->carol);

    expect($ac->id)->not->toBe($ab->id);
});

it('refuses a direct message to yourself', function () {
    expect(fn () => $this->rooms->findOrCreateDm($this->alice, $this->alice))
        ->toThrow(InvalidArgumentException::class);
});

it('opens a dm through the route and lands on the conversation', function () {
    $this->actingAs($this->alice)
        ->get(route('dm.start', $this->bob))
        ->assertRedirect();

    expect(Room::where('type', RoomType::Dm->value)->count())->toBe(1);
});

it('reuses the same room when the route is hit twice', function () {
    $this->actingAs($this->alice)->get(route('dm.start', $this->bob));
    $this->actingAs($this->alice)->get(route('dm.start', $this->bob));
    $this->actingAs($this->bob)->get(route('dm.start', $this->alice));

    expect(Room::where('type', RoomType::Dm->value)->count())->toBe(1);
});

it('shows the other person as the dm name', function () {
    $room = $this->rooms->findOrCreateDm($this->alice, $this->bob);

    expect($room->displayNameFor($this->alice))->toBe('Bob')
        ->and($room->displayNameFor($this->bob))->toBe('Alice');
});
