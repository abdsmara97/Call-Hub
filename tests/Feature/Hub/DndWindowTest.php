<?php

use App\Models\Administration;
use App\Models\DndWindow;
use App\Models\User;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;

/**
 * Do Not Disturb decides whether an ordinary notification is sent at all, so the
 * window arithmetic is worth pinning down — especially across midnight.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(OrganisationSeeder::class);

    $this->user = User::factory()->inAdministration(Administration::first())->create();
    $this->user->assignRole(Permissions::ROLE_EMPLOYEE);
});

/** @param  int  $day  0 = Sunday .. 6 = Saturday */
function dndWindow(User $user, int $day, string $start, string $end): DndWindow
{
    return DndWindow::create([
        'user_id' => $user->getKey(),
        'day_of_week' => $day,
        'starts_at' => $start,
        'ends_at' => $end,
    ]);
}

/** A date that really does fall on the given weekday. */
function atWeekday(int $day, string $time): CarbonImmutable
{
    // 2026-08-02 is a Sunday, so adding the day index lands on the right one.
    return CarbonImmutable::parse('2026-08-02 '.$time)->addDays($day);
}

it('is quiet inside a same-day window', function () {
    dndWindow($this->user, 1, '09:00:00', '17:00:00'); // Monday

    expect($this->user->isWithinDndWindow(atWeekday(1, '12:00:00')))->toBeTrue();
});

it('is not quiet before or after a same-day window', function () {
    dndWindow($this->user, 1, '09:00:00', '17:00:00');

    expect($this->user->isWithinDndWindow(atWeekday(1, '08:59:00')))->toBeFalse()
        ->and($this->user->isWithinDndWindow(atWeekday(1, '17:01:00')))->toBeFalse();
});

it('is quiet at both edges of a same-day window', function () {
    dndWindow($this->user, 1, '09:00:00', '17:00:00');

    expect($this->user->isWithinDndWindow(atWeekday(1, '09:00:00')))->toBeTrue()
        ->and($this->user->isWithinDndWindow(atWeekday(1, '17:00:00')))->toBeTrue();
});

it('ignores a window set for a different day', function () {
    dndWindow($this->user, 1, '09:00:00', '17:00:00'); // Monday

    expect($this->user->isWithinDndWindow(atWeekday(2, '12:00:00')))->toBeFalse();
});

it('is quiet in the first half of an overnight window', function () {
    dndWindow($this->user, 6, '22:00:00', '06:00:00'); // Saturday night

    expect($this->user->isWithinDndWindow(atWeekday(6, '23:00:00')))->toBeTrue();
});

/*
 * The bug this fixes: an overnight window belongs to the day it starts on, so
 * 02:00 on Sunday is covered by the Saturday row. Matching only on today's
 * weekday let every notification through after midnight.
 */
it('is quiet after midnight, on the day the overnight window ends', function () {
    dndWindow($this->user, 6, '22:00:00', '06:00:00'); // Saturday 22:00 → Sunday 06:00

    expect($this->user->isWithinDndWindow(atWeekday(0, '02:00:00')))->toBeTrue()
        ->and($this->user->isWithinDndWindow(atWeekday(0, '05:59:00')))->toBeTrue();
});

it('is not quiet once an overnight window has ended', function () {
    dndWindow($this->user, 6, '22:00:00', '06:00:00');

    expect($this->user->isWithinDndWindow(atWeekday(0, '06:01:00')))->toBeFalse()
        ->and($this->user->isWithinDndWindow(atWeekday(0, '12:00:00')))->toBeFalse();
});

it('does not leak an overnight window into the evening of the following day', function () {
    dndWindow($this->user, 6, '22:00:00', '06:00:00'); // Saturday only

    // Sunday 23:00 is outside it — there is no Sunday window.
    expect($this->user->isWithinDndWindow(atWeekday(0, '23:00:00')))->toBeFalse();
});

it('is not quiet in the gap between two windows on the same day', function () {
    dndWindow($this->user, 3, '09:00:00', '11:00:00');
    dndWindow($this->user, 3, '14:00:00', '16:00:00');

    expect($this->user->isWithinDndWindow(atWeekday(3, '10:00:00')))->toBeTrue()
        ->and($this->user->isWithinDndWindow(atWeekday(3, '12:30:00')))->toBeFalse()
        ->and($this->user->isWithinDndWindow(atWeekday(3, '15:00:00')))->toBeTrue();
});

it('is never quiet for someone with no windows', function () {
    expect($this->user->isWithinDndWindow(atWeekday(1, '12:00:00')))->toBeFalse()
        ->and($this->user->isWithinDndWindow(atWeekday(0, '03:00:00')))->toBeFalse();
});

it('keeps one person quiet hours to themselves', function () {
    $other = User::factory()->inAdministration(Administration::first())->create();

    dndWindow($this->user, 1, '09:00:00', '17:00:00');

    expect($this->user->isWithinDndWindow(atWeekday(1, '12:00:00')))->toBeTrue()
        ->and($other->isWithinDndWindow(atWeekday(1, '12:00:00')))->toBeFalse();
});
