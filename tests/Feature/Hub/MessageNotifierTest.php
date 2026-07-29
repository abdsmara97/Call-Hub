<?php

use App\Enums\RoomType;
use App\Livewire\Hub\MessageNotifier;
use App\Models\Administration;
use App\Models\DndWindow;
use App\Models\Room;
use App\Models\User;
use App\Services\EmergencyService;
use App\Services\MessageService;
use App\Services\RoomProvisioner;
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

    $this->me = User::factory()->inAdministration($administration)->create(['name' => 'Me']);
    $this->me->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->them = User::factory()->inAdministration($administration)->create(['name' => 'Ivan Petrov']);
    $this->them->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->room = Room::factory()->create(['name' => 'Plant Floor', 'type' => RoomType::Private->value]);
    $this->room->members()->attach([$this->me->id, $this->them->id], ['joined_at' => now()]);

    $this->messages = app(MessageService::class);
    $this->actingAs($this->me);
});

/** @return array<string, mixed> The payload MessageSent puts on the wire. */
function payloadFor(App\Models\Message $message): array
{
    return [
        'id' => $message->id,
        'room_id' => $message->room_id,
        'user_id' => $message->user_id,
        'parent_id' => $message->parent_id,
        'is_emergency' => $message->isEmergency(),
    ];
}

// ------------------------------------------------------------------ subscribes

it('listens to every room the user belongs to', function () {
    $second = Room::factory()->create(['type' => RoomType::Private->value]);
    $second->members()->attach($this->me->id, ['joined_at' => now()]);

    $component = Livewire::test(MessageNotifier::class);

    $method = new ReflectionMethod(MessageNotifier::class, 'getListeners');
    $method->setAccessible(true);

    $listeners = array_keys($method->invoke($component->instance()));

    expect($listeners)->toContain("echo-private:room.{$this->room->id},.message.sent")
        ->and($listeners)->toContain("echo-private:room.{$second->id},.message.sent");
});

// ---------------------------------------------------------------- notifies

it('notifies about a colleague message', function () {
    $message = $this->messages->send($this->them, $this->room, 'Conveyor jam in bay two');

    Livewire::test(MessageNotifier::class)
        ->call('onMessage', payloadFor($message))
        ->assertDispatched('message-notification', function ($event, $params) {
            return $params['sender'] === 'Ivan Petrov'
                && $params['room'] === 'Plant Floor'
                && $params['body'] === 'Conveyor jam in bay two'
                && $params['roomId'] === $this->room->id
                && $params['isDm'] === false
                && str_contains($params['url'], (string) $this->room->id);
        });
});

it('marks a direct message so the sender is not repeated', function () {
    $dm = app(RoomProvisioner::class)->findOrCreateDm($this->me, $this->them);

    $message = $this->messages->send($this->them, $dm, 'Are you on site?');

    Livewire::test(MessageNotifier::class)
        ->call('onMessage', payloadFor($message))
        ->assertDispatched('message-notification', function ($event, $params) {
            return $params['isDm'] === true && $params['room'] === 'Ivan Petrov';
        });
});

it('describes an attachment-only message rather than sending an empty body', function () {
    Illuminate\Support\Facades\Storage::fake(config('hub.attachments.disk'));

    $message = $this->messages->send(
        $this->them,
        $this->room,
        null,
        [Illuminate\Http\UploadedFile::fake()->create('rota.pdf', 8, 'application/pdf')],
    );

    Livewire::test(MessageNotifier::class)
        ->call('onMessage', payloadFor($message))
        ->assertDispatched('message-notification', function ($event, $params) {
            return $params['body'] === 'Sent an attachment';
        });
});

it('truncates a very long message', function () {
    $message = $this->messages->send($this->them, $this->room, str_repeat('a', 400));

    Livewire::test(MessageNotifier::class)
        ->call('onMessage', payloadFor($message))
        ->assertDispatched('message-notification', function ($event, $params) {
            return strlen($params['body']) <= 143; // 140 + the ellipsis
        });
});

// ------------------------------------------------------------------ silent

it('stays silent for your own message', function () {
    $message = $this->messages->send($this->me, $this->room, 'Mine');

    Livewire::test(MessageNotifier::class)
        ->call('onMessage', payloadFor($message))
        ->assertNotDispatched('message-notification');
});

it('stays silent for an emergency, which has its own overlay', function () {
    app(EmergencyService::class)->raiseInRoom($this->them, $this->room, 'Pressure alarm');

    $message = App\Models\Message::whereNotNull('emergency_id')->firstOrFail();

    Livewire::test(MessageNotifier::class)
        ->call('onMessage', payloadFor($message))
        ->assertNotDispatched('message-notification');
});

it('stays silent when the user has set notifications to none', function () {
    $this->me->forceFill(['message_notifications' => 'none'])->save();

    $message = $this->messages->send($this->them, $this->room, 'You will not see this');

    Livewire::test(MessageNotifier::class)
        ->call('onMessage', payloadFor($message))
        ->assertNotDispatched('message-notification');
});

it('stays silent during quiet hours', function () {
    // A window covering right now, whatever "now" happens to be.
    DndWindow::create([
        'user_id' => $this->me->id,
        'day_of_week' => (int) now()->format('w'),
        'starts_at' => '00:00:00',
        'ends_at' => '23:59:59',
    ]);

    $message = $this->messages->send($this->them, $this->room, 'During quiet hours');

    Livewire::test(MessageNotifier::class)
        ->call('onMessage', payloadFor($message))
        ->assertNotDispatched('message-notification');
});

it('still surfaces an emergency during quiet hours', function () {
    DndWindow::create([
        'user_id' => $this->me->id,
        'day_of_week' => (int) now()->format('w'),
        'starts_at' => '00:00:00',
        'ends_at' => '23:59:59',
    ]);

    // The emergency path is a different component entirely, and must not consult
    // the DND window. Assert the recipient really is on the hook.
    $emergency = app(EmergencyService::class)->raiseInRoom($this->them, $this->room, 'Alarm');

    expect($emergency->recipients()->where('user_id', $this->me->id)->exists())->toBeTrue()
        ->and($this->me->fresh()->isWithinDndWindow())->toBeTrue();
});

it('never leaks a message from a room the user cannot see', function () {
    $private = Room::factory()->create(['type' => RoomType::Private->value]);
    $private->members()->attach($this->them->id, ['joined_at' => now()]);

    $message = $this->messages->send($this->them, $private, 'Not for you');

    // A socket can outlive the membership that justified it, so the component
    // re-authorises rather than trusting the subscription.
    Livewire::test(MessageNotifier::class)
        ->call('onMessage', payloadFor($message))
        ->assertNotDispatched('message-notification');
});

it('stops notifying once the user leaves the room', function () {
    app(RoomProvisioner::class)->removeMember($this->room, $this->me);

    $message = $this->messages->send($this->them, $this->room, 'After you left');

    Livewire::test(MessageNotifier::class)
        ->call('onMessage', payloadFor($message))
        ->assertNotDispatched('message-notification');
});

it('ignores a payload for a message that no longer exists', function () {
    Livewire::test(MessageNotifier::class)
        ->call('onMessage', ['id' => 999999, 'room_id' => $this->room->id, 'user_id' => $this->them->id])
        ->assertNotDispatched('message-notification');
});

// ----------------------------------------------------------------- mentions

it('notifies at the mentions level only when you are actually named', function () {
    $this->me->forceFill(['message_notifications' => 'mentions'])->save();

    $ordinary = $this->messages->send($this->them, $this->room, 'General chatter');

    Livewire::test(MessageNotifier::class)
        ->call('onMessage', payloadFor($ordinary))
        ->assertNotDispatched('message-notification');

    $named = $this->messages->send($this->them, $this->room, 'Can @Me look at bay two');

    Livewire::test(MessageNotifier::class)
        ->call('onMessage', payloadFor($named))
        ->assertDispatched('message-notification', function ($event, $params) {
            return $params['isMention'] === true;
        });
});

it('marks an ordinary message as not a mention', function () {
    $message = $this->messages->send($this->them, $this->room, 'General chatter');

    Livewire::test(MessageNotifier::class)
        ->call('onMessage', payloadFor($message))
        ->assertDispatched('message-notification', function ($event, $params) {
            return $params['isMention'] === false;
        });
});

it('still notifies at the all level for a message that names someone else', function () {
    $third = User::factory()->inAdministration(Administration::first())->create(['name' => 'Grace Lin']);
    $this->room->members()->attach($third->id, ['joined_at' => now()]);

    $message = $this->messages->send($this->them, $this->room, 'Over to @Grace Lin');

    Livewire::test(MessageNotifier::class)
        ->call('onMessage', payloadFor($message))
        ->assertDispatched('message-notification', function ($event, $params) {
            return $params['isMention'] === false;
        });
});

it('stays silent at the none level even when you are named', function () {
    $this->me->forceFill(['message_notifications' => 'none'])->save();

    $message = $this->messages->send($this->them, $this->room, 'Urgent @Me please');

    Livewire::test(MessageNotifier::class)
        ->call('onMessage', payloadFor($message))
        ->assertNotDispatched('message-notification');
});

/*
 * Emergencies are the one documented bypass of Do Not Disturb. A mention is
 * not a second one — adding another would devalue the first.
 */
it('lets quiet hours silence a mention', function () {
    $this->me->forceFill(['message_notifications' => 'mentions'])->save();

    DndWindow::create([
        'user_id' => $this->me->id,
        'day_of_week' => (int) now()->format('w'),
        'starts_at' => '00:00:00',
        'ends_at' => '23:59:59',
    ]);

    $message = $this->messages->send($this->them, $this->room, 'Urgent @Me please');

    Livewire::test(MessageNotifier::class)
        ->call('onMessage', payloadFor($message))
        ->assertNotDispatched('message-notification');
});
