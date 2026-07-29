<?php

use App\Enums\RoomType;
use App\Models\Administration;
use App\Models\Room;
use App\Models\User;
use App\Services\MessageService;
use App\Services\RoomProvisioner;
use App\Support\Permissions;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Attachments are the one place user-supplied bytes leave the server, so both
 * gates are asserted here: the signature, and the live membership check behind
 * it. A signature alone must never be enough.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(OrganisationSeeder::class);

    Storage::fake(config('hub.attachments.disk'));

    $administration = Administration::first();

    $this->member = User::factory()->inAdministration($administration)->create();
    $this->member->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->outsider = User::factory()->inAdministration($administration)->create();
    $this->outsider->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->room = Room::factory()->create(['type' => RoomType::Private->value]);
    $this->room->members()->attach($this->member->id, ['joined_at' => now()]);

    $message = app(MessageService::class)->send(
        $this->member,
        $this->room,
        'Here is the report',
        [UploadedFile::fake()->create('report.pdf', 12, 'application/pdf')],
    );

    $this->attachment = $message->attachments->first();
});

it('stores the upload under a random name on the private disk', function () {
    expect($this->attachment->path)->not->toContain('report.pdf')
        ->and($this->attachment->original_name)->toBe('report.pdf')
        ->and($this->attachment->disk)->toBe(config('hub.attachments.disk'));

    Storage::disk($this->attachment->disk)->assertExists($this->attachment->path);
});

it('refuses an unsigned url outright', function () {
    $this->actingAs($this->member)
        ->get(route('attachments.show', $this->attachment, absolute: false))
        ->assertForbidden();
});

it('serves a signed url to a member of the room', function () {
    $this->actingAs($this->member)
        ->get($this->attachment->temporaryUrl())
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('refuses a guest even with a valid signature', function () {
    $this->get($this->attachment->temporaryUrl())->assertRedirect(route('login'));
});

it('refuses a valid signature to someone outside the room', function () {
    $this->actingAs($this->outsider)
        ->get($this->attachment->temporaryUrl())
        ->assertForbidden();
});

it('stops serving a still-valid signature once the member leaves the room', function () {
    $url = $this->attachment->temporaryUrl();

    $this->actingAs($this->member)->get($url)->assertOk();

    app(RoomProvisioner::class)->removeMember($this->room, $this->member);

    // Same URL, same signature, no longer a member — this is the leak the
    // second gate exists to close.
    $this->actingAs($this->member)->get($url)->assertForbidden();
});

it('never serves a non-image inline', function () {
    $response = $this->actingAs($this->member)->get($this->attachment->temporaryUrl());

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;');
});

it('serves an image inline but still sandboxed', function () {
    $message = app(MessageService::class)->send(
        $this->member,
        $this->room,
        null,
        [UploadedFile::fake()->image('photo.png')],
    );

    $response = $this->actingAs($this->member)->get($message->attachments->first()->temporaryUrl());

    expect($response->headers->get('Content-Disposition'))->toStartWith('inline;')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox');
});

it('returns 404 when the row survives but the file is gone', function () {
    Storage::disk($this->attachment->disk)->delete($this->attachment->path);

    $this->actingAs($this->member)
        ->get($this->attachment->temporaryUrl())
        ->assertNotFound();
});

it('still serves an attachment whose message was soft deleted', function () {
    // The message is gone from the timeline, but the emergency audit trail and
    // any direct link must not 500.
    app(MessageService::class)->delete($this->attachment->message);

    $this->actingAs($this->member)
        ->get($this->attachment->temporaryUrl())
        ->assertOk();
});
