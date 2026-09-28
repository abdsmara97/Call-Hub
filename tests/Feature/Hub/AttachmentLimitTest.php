<?php

use App\Livewire\Hub\Conversation;
use App\Models\Administration;
use App\Models\Message;
use App\Models\Room;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The attachment size ceilings, and the fact that four of them have to agree.
 *
 * Raising a limit in one place and not the others produces the worst kind of
 * bug: the setting reads 40 MB, the behaviour is 12 MB, and nothing anywhere
 * says so. The first test here is the one that matters — it is the assertion
 * that would have caught it.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(OrganisationSeeder::class);

    $this->user = User::factory()->inAdministration(Administration::first())->create();
    $this->user->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->room = Room::factory()->create();
    $this->room->members()->attach($this->user->id, ['joined_at' => now()]);

    $this->actingAs($this->user);

    Storage::fake(config('hub.attachments.disk'));
});

/** A fake upload of a given size in kilobytes. */
function upload(int $kilobytes, string $name = 'brief.pdf'): UploadedFile
{
    return UploadedFile::fake()->create($name, $kilobytes, 'application/pdf');
}

function composer(): Testable
{
    return Livewire::test(Conversation::class, ['roomId' => test()->room->id]);
}

/* ---------------------------------------------------------------- the guards */

/*
 * Livewire validates at its temporary-upload endpoint BEFORE any component rule
 * runs, so a file it rejects never reaches config/hub.php. Its default is 12 MB.
 * If this fails, config/livewire.php is out of step and the hub is advertising a
 * limit it cannot honour.
 */
it('does not let the livewire upload gate sit below the advertised limit', function () {
    $rules = config('livewire.temporary_file_upload.rules');

    expect($rules)->not->toBeNull(
        'config/livewire.php must be published, or the 12 MB default silently caps attachments'
    );

    $max = collect((array) $rules)
        ->filter(fn ($rule) => is_string($rule) && str_starts_with($rule, 'max:'))
        ->map(fn ($rule) => (int) substr($rule, 4))
        ->first();

    expect($max)->not->toBeNull('the temporary-upload rules must carry an explicit max:')
        ->and($max)->toBeGreaterThanOrEqual((int) config('hub.attachments.max_kilobytes'));
});

it('does not let a single file be larger than a whole message may carry', function () {
    expect((int) config('hub.attachments.max_batch_kilobytes'))
        ->toBeGreaterThanOrEqual((int) config('hub.attachments.max_kilobytes'));
});

/* ------------------------------------------------------------ the per-file cap */

it('accepts a file at the configured size', function () {
    // Just inside 40 MB. Kept relative to config so the test follows the setting.
    composer()
        ->set('uploads', [upload((int) config('hub.attachments.max_kilobytes') - 16)])
        ->call('send')
        ->assertHasNoErrors();
});

/*
 * Note where this is caught. The temporary-upload rule in config/livewire.php
 * runs at the upload endpoint, so an over-large file never becomes an attachment
 * and never reaches the component's own `uploads.*` rule at all — that rule is
 * belt-and-braces behind this one. It is also exactly why the first test in this
 * file exists: if that rule sat below the advertised limit, this is where a
 * legal 40 MB file would silently die.
 */
it('refuses a file over the configured size before it becomes an attachment', function () {
    $component = composer()
        ->set('uploads', [upload((int) config('hub.attachments.max_kilobytes') + 512)]);

    expect($component->get('uploads'))->toBeEmpty();

    $component->call('send')->assertHasErrors('body');

    expect(Message::count())->toBe(0);
});

/* --------------------------------------------------------------- the batch cap */

/*
 * Every file travels in one request, so the per-file limit alone would allow a
 * 200 MB POST. These two describe the line between "five attachments" and "one
 * person filling the web server's request buffer".
 */
it('accepts several files that together stay under the batch limit', function () {
    $each = (int) floor(config('hub.attachments.max_batch_kilobytes') / 6);

    composer()
        ->set('uploads', collect(range(1, 5))->map(fn ($i) => upload($each, "brief-{$i}.pdf"))->all())
        ->call('send')
        ->assertHasNoErrors();
});

it('rejects files that are each legal but too large together', function () {
    $perFile = (int) config('hub.attachments.max_kilobytes');
    $batch = (int) config('hub.attachments.max_batch_kilobytes');

    // The smallest number of full-size files that overruns the batch. At 40 MB
    // per file and 80 MB per message that is three — two exactly fill it, which
    // is allowed, so two would prove nothing.
    $count = (int) floor($batch / $perFile) + 1;

    // If this ever exceeded five, the count rule would fire instead and the
    // test would pass while saying nothing about the batch rule.
    expect($count)->toBeLessThanOrEqual(5);

    $files = collect(range(1, $count))->map(fn ($i) => upload($perFile, "brief-{$i}.pdf"))->all();

    composer()
        ->set('uploads', $files)
        ->call('send')
        ->assertHasErrors('uploads');
});

it('still refuses more than five attachments', function () {
    composer()
        ->set('uploads', collect(range(1, 6))->map(fn ($i) => upload(8, "brief-{$i}.pdf"))->all())
        ->call('send')
        ->assertHasErrors('uploads');
});

/* ------------------------------------------------------------ the type gate */

/*
 * Raising the size must not have loosened the allow-list. An inline SVG or HTML
 * upload would run in the app's own origin.
 */
it('still rejects a type that is not on the allow-list', function () {
    composer()
        ->set('uploads', [UploadedFile::fake()->create('payload.svg', 4, 'image/svg+xml')])
        ->call('send')
        ->assertHasErrors('uploads.0');
});
