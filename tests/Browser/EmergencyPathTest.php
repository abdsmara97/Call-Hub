<?php

use App\Enums\RoomType;
use App\Models\Administration;
use App\Models\Emergency;
use App\Models\Room;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Laravel\Dusk\Browser;

/**
 * The emergency paths, driven in a real browser.
 *
 * These are the M3 acceptance criteria: a recipient who is looking at a
 * different room still gets alerted, acknowledging clears it, the sender sees
 * the acknowledgement live, and an unacknowledged emergency escalates.
 *
 * Requires a running Reverb server and ChromeDriver — see the README. `php
 * artisan test` does not include this directory; run `php artisan dusk`.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(OrganisationSeeder::class);

    $administration = Administration::first();

    $this->sender = User::factory()->inAdministration($administration)->create([
        'name' => 'Dana Sender',
        'password' => bcrypt('password'),
        'must_change_password' => false,
    ]);
    $this->sender->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->recipient = User::factory()->inAdministration($administration)->create([
        'name' => 'Remy Recipient',
        'password' => bcrypt('password'),
        'must_change_password' => false,
    ]);
    $this->recipient->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->admin = User::factory()->inAdministration($administration)->create([
        'name' => 'Avery Admin',
        'password' => bcrypt('password'),
        'must_change_password' => false,
    ]);
    $this->admin->assignRole(Permissions::ROLE_ADMIN);

    // The incident room, and a second room for the recipient to be sitting in
    // when the emergency fires — the whole point of the personal channel.
    $this->incidentRoom = Room::factory()->create([
        'name' => 'Plant Floor',
        'type' => RoomType::Public->value,
    ]);
    $this->elsewhere = Room::factory()->create([
        'name' => 'Watercooler',
        'type' => RoomType::Public->value,
    ]);

    foreach ([$this->incidentRoom, $this->elsewhere] as $room) {
        $room->members()->attach(
            [$this->sender->id, $this->recipient->id],
            ['joined_at' => now()],
        );
    }
});

it('alerts a recipient who is looking at a different room, and clears on acknowledge', function () {
    $this->browse(function (Browser $senderBrowser, Browser $recipientBrowser) {
        $recipientBrowser->loginAs($this->recipient)
            ->visit(route('rooms.show', $this->elsewhere))
            ->waitForText('Watercooler');

        $senderBrowser->loginAs($this->sender)
            ->visit(route('rooms.show', $this->incidentRoom))
            ->waitForText('Plant Floor')
            ->press('Emergency')
            ->waitForText('Emergency mode is on.')
            ->type('#composer', 'Conveyor jam in bay two, need hands now')
            ->press('Send emergency')
            ->waitForText('Conveyor jam in bay two');

        // The recipient never left the other room, and is still alerted.
        $recipientBrowser->waitForText('Conveyor jam in bay two', 15)
            ->assertSee('EMERGENCY')
            ->assertSee('Remy Recipient')
            ->press('Acknowledge')
            ->waitUntilMissingText('Conveyor jam in bay two', 15);

        // And the sender sees the acknowledgement without reloading.
        $senderBrowser->waitForText('Remy Recipient', 15);
    });
});

it('keeps the banner up until it is actually acknowledged', function () {
    $this->browse(function (Browser $senderBrowser, Browser $recipientBrowser) {
        $recipientBrowser->loginAs($this->recipient)
            ->visit(route('hub'))
            ->waitForText('Watercooler');

        $senderBrowser->loginAs($this->sender)
            ->visit(route('rooms.show', $this->incidentRoom))
            ->waitForText('Plant Floor')
            ->press('Emergency')
            ->waitForText('Emergency mode is on.')
            ->type('#composer', 'Coolant leak on line three')
            ->press('Send emergency')
            ->waitForText('Coolant leak on line three');

        $recipientBrowser->waitForText('Coolant leak on line three', 15)
            // Navigating away must not dismiss it — an emergency is not a toast.
            ->visit(route('directory'))
            ->waitForText('Coolant leak on line three', 15)
            ->visit(route('saved'))
            ->waitForText('Coolant leak on line three', 15);
    });
});

it('re-alerts a recipient who has not acknowledged', function () {
    $this->browse(function (Browser $recipientBrowser) {
        $recipientBrowser->loginAs($this->recipient)
            ->visit(route('hub'))
            ->waitForText('Watercooler');

        $emergency = app(App\Services\EmergencyService::class)
            ->raiseInRoom($this->sender, $this->incidentRoom, 'Pressure alarm in the boiler room');

        $recipientBrowser->waitForText('Pressure alarm in the boiler room', 15)
            ->assertDontSee('Re-alert');

        // Run the escalation now rather than making the suite wait out the
        // configured interval. The job takes only the emergency id.
        (new App\Jobs\EscalateEmergency($emergency->id))->handle();

        $recipientBrowser->waitForText('Re-alert', 15);

        expect($emergency->fresh()->escalation_count)->toBe(1);
    });
});

it('takes the whole screen over for an administrator broadcast', function () {
    $this->browse(function (Browser $adminBrowser, Browser $recipientBrowser) {
        $recipientBrowser->loginAs($this->recipient)
            ->visit(route('hub'))
            ->waitForText('Watercooler');

        // Two-step by design: review, then send.
        $adminBrowser->loginAs($this->admin)
            ->visit(route('admin.broadcast'))
            ->waitForText('Emergency broadcast')
            ->type('#broadcast-body', 'Site evacuation, assemble at the north gate')
            ->press('Review broadcast')
            ->waitForText('It cannot be recalled')
            ->press('Send emergency broadcast')
            ->waitForText('Broadcast sent.');

        $recipientBrowser->waitForText('Site evacuation, assemble at the north gate', 15)
            // Blocking by design: an acknowledgement, and nothing else.
            ->assertPresent('[role="alertdialog"]')
            ->press('I have read this')
            ->waitUntilMissing('[role="alertdialog"]', 15);
    });
});

it('records the acknowledgement in the exportable log', function () {
    $this->browse(function (Browser $recipientBrowser, Browser $adminBrowser) {
        $recipientBrowser->loginAs($this->recipient)
            ->visit(route('hub'))
            ->waitForText('Watercooler');

        app(App\Services\EmergencyService::class)
            ->raiseInRoom($this->sender, $this->incidentRoom, 'Forklift blocking the fire door');

        $recipientBrowser->waitForText('Forklift blocking the fire door', 15)
            ->press('Acknowledge')
            ->waitUntilMissingText('Forklift blocking the fire door', 15);

        $adminBrowser->loginAs($this->admin)
            ->visit(route('admin.emergency-log'))
            ->waitForText('Forklift blocking the fire door')
            ->assertSee('Remy Recipient');

        expect(Emergency::where('body', 'Forklift blocking the fire door')
            ->first()
            ->recipients()
            ->whereNotNull('acknowledged_at')
            ->count())->toBe(1);
    });
});
