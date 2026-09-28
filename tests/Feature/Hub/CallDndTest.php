<?php

use App\Enums\Availability;
use App\Enums\CallDisposition;
use App\Enums\UserStatus;
use App\Events\CallRinging;
use App\Models\Administration;
use App\Models\DndWindow;
use App\Models\User;
use App\Services\CallService;
use App\Services\RoomProvisioner;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Event;

/**
 * Where a call sits between an ordinary message and an emergency.
 *
 * The doctrine everywhere else in this app is that emergencies ignore Do Not
 * Disturb and messages obey it. A call does neither: it still arrives, but
 * silently, and the caller is told so they can judge whether it is worth the
 * emergency flag instead. Being off shift is the one case that blocks outright,
 * because that is a roster fact rather than a preference.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(OrganisationSeeder::class);

    $administration = Administration::first();

    $this->alice = User::factory()->inAdministration($administration)->create(['name' => 'Alice']);
    $this->alice->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->bob = User::factory()->inAdministration($administration)->create(['name' => 'Bob']);
    $this->bob->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->dm = app(RoomProvisioner::class)->findOrCreateDm($this->alice, $this->bob);
    $this->calls = app(CallService::class);
});

it('rings normally for someone who is available', function () {
    expect($this->calls->dispositionFor($this->bob))->toBe(CallDisposition::Ring);
});

it('rings quietly for someone marked busy', function () {
    $this->bob->update(['availability' => Availability::Busy]);

    expect($this->calls->dispositionFor($this->bob->fresh()))->toBe(CallDisposition::Quiet);
});

/*
 * "Away" is a passive inference that goes stale — someone who stepped out for
 * two minutes is still reachable. Unlike DND it was never actually asked for,
 * so it does not silence anything.
 */
it('still rings at full volume for someone marked away', function () {
    $this->bob->update(['availability' => Availability::Away]);

    expect($this->calls->dispositionFor($this->bob->fresh()))->toBe(CallDisposition::Ring);
});

it('rings quietly inside a do not disturb window', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-03 12:00:00')); // a Monday

    DndWindow::create([
        'user_id' => $this->bob->getKey(),
        'day_of_week' => 1,
        'starts_at' => '09:00:00',
        'ends_at' => '17:00:00',
    ]);

    expect($this->calls->dispositionFor($this->bob->fresh()))->toBe(CallDisposition::Quiet);

    CarbonImmutable::setTestNow();
});

/*
 * The overnight case, which is where the DND arithmetic has been wrong before:
 * 02:00 on Sunday belongs to the window that started on Saturday night.
 */
it('rings quietly after midnight inside an overnight window', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-02 02:00:00')); // Sunday

    DndWindow::create([
        'user_id' => $this->bob->getKey(),
        'day_of_week' => 6, // Saturday 22:00 → Sunday 06:00
        'starts_at' => '22:00:00',
        'ends_at' => '06:00:00',
    ]);

    expect($this->calls->dispositionFor($this->bob->fresh()))->toBe(CallDisposition::Quiet);

    CarbonImmutable::setTestNow();
});

it('marks a quiet ring as quiet in the broadcast', function () {
    $this->bob->update(['availability' => Availability::Busy]);

    Event::fake([CallRinging::class]);

    $this->actingAs($this->alice)
        ->postJson(route('calls.ring', $this->dm))
        ->assertOk()
        ->assertJson(['quiet' => true]);

    Event::assertDispatched(CallRinging::class, fn (CallRinging $e) => $e->quiet === true);
});

it('refuses to ring someone who is off shift', function () {
    $this->bob->update(['availability' => Availability::OffShift]);

    Event::fake([CallRinging::class]);

    $this->actingAs($this->alice)
        ->postJson(route('calls.ring', $this->dm))
        ->assertStatus(409)
        ->assertJson(['reason' => 'off_shift']);

    Event::assertNothingDispatched();
});

it('refuses to ring a suspended account', function () {
    $this->bob->update(['status' => UserStatus::Suspended]);

    Event::fake([CallRinging::class]);

    $this->actingAs($this->alice)
        ->postJson(route('calls.ring', $this->dm))
        ->assertStatus(409)
        ->assertJson(['reason' => 'inactive']);

    Event::assertNothingDispatched();
});

/*
 * 409 rather than 403 throughout: the caller is allowed to call this person,
 * just not at this moment. The client uses that distinction to decide whether
 * to offer "send a message instead".
 */
it('separates being unavailable from being unauthorised', function () {
    $this->bob->update(['availability' => Availability::OffShift]);

    $this->actingAs($this->alice)
        ->postJson(route('calls.ring', $this->dm))
        ->assertStatus(409);
});
