<?php

use App\Enums\RoomType;
use App\Exceptions\EmergencyRateLimited;
use App\Models\Administration;
use App\Models\Room;
use App\Models\Setting;
use App\Models\User;
use App\Services\EmergencyService;
use App\Support\Permissions;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The emergency flag only stays meaningful if it is scarce, so the limiter is
 * load-bearing product behaviour, not just abuse protection.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(OrganisationSeeder::class);

    $administration = Administration::first();

    $this->sender = User::factory()->inAdministration($administration)->create();
    $this->sender->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->admin = User::factory()->inAdministration($administration)->create();
    $this->admin->assignRole(Permissions::ROLE_ADMIN);

    $this->peer = User::factory()->inAdministration($administration)->create();
    $this->peer->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->room = Room::factory()->create(['type' => RoomType::Private->value]);
    $this->room->members()->attach(
        [$this->sender->id, $this->admin->id, $this->peer->id],
        ['joined_at' => now()],
    );

    $this->emergencies = app(EmergencyService::class);
});

it('allows the first emergency and blocks the immediate second', function () {
    $this->emergencies->raiseInRoom($this->sender, $this->room, 'Line down in bay two');

    expect(fn () => $this->emergencies->raiseInRoom($this->sender, $this->room, 'Again'))
        ->toThrow(EmergencyRateLimited::class);
});

it('tells the sender how long they must wait', function () {
    $this->emergencies->raiseInRoom($this->sender, $this->room, 'Line down in bay two');

    try {
        $this->emergencies->raiseInRoom($this->sender, $this->room, 'Again');
        $this->fail('expected the limiter to refuse the second emergency');
    } catch (EmergencyRateLimited $e) {
        expect($e->getMessage())->not->toBeEmpty();
    }
});

it('limits per sender, not globally', function () {
    $this->emergencies->raiseInRoom($this->sender, $this->room, 'Line down in bay two');

    // A different person is unaffected by the first sender's burst.
    $this->emergencies->raiseInRoom($this->peer, $this->room, 'Separate incident');
})->throwsNoExceptions();

it('exempts administrators from the burst limit', function () {
    $this->emergencies->raiseInRoom($this->admin, $this->room, 'First');
    $this->emergencies->raiseInRoom($this->admin, $this->room, 'Second');
})->throwsNoExceptions();

it('lets the sender through again once the window has passed', function () {
    $this->emergencies->raiseInRoom($this->sender, $this->room, 'Line down in bay two');

    RateLimiter::clear('emergency:burst:'.$this->sender->id);

    $this->emergencies->raiseInRoom($this->sender, $this->room, 'A later, separate incident');
})->throwsNoExceptions();

it('enforces the daily ceiling independently of the burst window', function () {
    Setting::put('emergency.rate_limit.per_day', 2);

    foreach (['One', 'Two'] as $body) {
        $this->emergencies->raiseInRoom($this->sender, $this->room, $body);
        RateLimiter::clear('emergency:burst:'.$this->sender->id);
    }

    expect(fn () => $this->emergencies->raiseInRoom($this->sender, $this->room, 'Three'))
        ->toThrow(EmergencyRateLimited::class);
});

it('does not record an emergency that the limiter refused', function () {
    $this->emergencies->raiseInRoom($this->sender, $this->room, 'Line down in bay two');

    try {
        $this->emergencies->raiseInRoom($this->sender, $this->room, 'Refused');
    } catch (EmergencyRateLimited) {
        // expected
    }

    expect(App\Models\Emergency::where('sender_id', $this->sender->id)->count())->toBe(1)
        ->and(App\Models\Message::where('body', 'Refused')->exists())->toBeFalse();
});

it('snapshots every recipient except the sender', function () {
    $emergency = $this->emergencies->raiseInRoom($this->sender, $this->room, 'Line down in bay two');

    $recipientIds = $emergency->recipients()->pluck('user_id')->sort()->values()->all();

    expect($recipientIds)->toBe(collect([$this->admin->id, $this->peer->id])->sort()->values()->all())
        ->and($recipientIds)->not->toContain($this->sender->id);
});

it('keeps the recipient snapshot fixed when someone joins afterwards', function () {
    $emergency = $this->emergencies->raiseInRoom($this->sender, $this->room, 'Line down in bay two');

    $latecomer = User::factory()->inAdministration(Administration::first())->create();
    $this->room->members()->attach($latecomer->id, ['joined_at' => now()]);

    // The audit trail records who was there at send time, not who is there now.
    expect($emergency->fresh()->recipients()->where('user_id', $latecomer->id)->exists())->toBeFalse();
});
