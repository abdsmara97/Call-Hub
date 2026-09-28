<?php

use App\Enums\FormFieldType;
use App\Livewire\Admin\FormBuilder;
use App\Livewire\Admin\FormResponses;
use App\Livewire\Hub\Conversation;
use App\Livewire\Hub\FormFill;
use App\Models\Administration;
use App\Models\Form;
use App\Models\FormAnswer;
use App\Models\FormField;
use App\Models\Message;
use App\Models\Room;
use App\Models\User;
use App\Services\FormService;
use App\Support\Permissions;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Forms: an administrator writes a set of questions, sends it into one or more
 * conversations, and the people there answer it.
 *
 * Writing and sending are separate acts, and most of the interesting behaviour
 * lives in that seam — a form belongs to nobody's room until it is sent, and
 * can then belong to several at once while still collecting one set of answers.
 *
 * Two further differences from polls, which occupy the same row in a timeline.
 * Answers carry names, so who may read them is narrower than who may see the
 * form. And a picture answer is a real file, with a storage path and a signed URL.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(OrganisationSeeder::class);

    $administration = Administration::first();

    $this->admin = User::factory()->inAdministration($administration)->create(['name' => 'Ada Admin']);
    $this->admin->assignRole(Permissions::ROLE_ADMIN);

    $this->staff = User::factory()->inAdministration($administration)->create(['name' => 'Sam Staff']);
    $this->staff->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->other = User::factory()->inAdministration($administration)->create(['name' => 'Otto Other']);
    $this->other->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->room = Room::factory()->create(['name' => 'Plant Floor']);
    $this->room->members()->attach(
        [$this->admin->id, $this->staff->id, $this->other->id],
        ['joined_at' => now()],
    );

    $this->forms = app(FormService::class);

    Storage::fake(config('hub.attachments.disk'));
});

/** A form with one question of each type, written but not sent anywhere. */
function draftForm(): Form
{
    return test()->forms->create(
        test()->admin,
        'Site safety check',
        'Before the end of your shift.',
        [
            ['type' => 'short_text', 'label' => 'Which bay?', 'required' => true],
            ['type' => 'long_text', 'label' => 'What did you find?'],
            ['type' => 'number', 'label' => 'How many faults?'],
            ['type' => 'yes_no', 'label' => 'Safe to run overnight?'],
            ['type' => 'picture', 'label' => 'Photos'],
        ],
    );
}

/** The same form, sent into the room, which is what most of these need. */
function fullForm(): Form
{
    $form = draftForm();

    test()->forms->postTo($form, test()->admin, test()->room);

    return $form->fresh(['fields']);
}

function fieldOf(Form $form, string $type): FormField
{
    return $form->fields->firstWhere('type', FormFieldType::from($type));
}

/* ------------------------------------------------------------------ creating */

it('writes a form without sending it anywhere', function () {
    $form = draftForm();

    expect($form->fields)->toHaveCount(5)
        ->and($form->isPosted())->toBeFalse()
        // Nothing has been said in any room. This is the whole point of the
        // split: authoring is not announcing.
        ->and(Message::count())->toBe(0);
});

it('announces the form as a message when it is sent into a room', function () {
    $form = draftForm();

    $posting = $this->forms->postTo($form, $this->admin, $this->room);

    expect($posting->message)->toBeInstanceOf(Message::class)
        ->and($posting->message->body)->toBe('Site safety check')
        ->and($posting->message->room_id)->toBe($this->room->id)
        ->and($form->fresh()->isPosted())->toBeTrue();
});

it('sends one form into several conversations and keeps one set of answers', function () {
    $depot = Room::factory()->create(['name' => 'Depot']);
    $depot->members()->attach($this->staff->id, ['joined_at' => now()]);
    $depot->members()->attach($this->admin->id, ['joined_at' => now()]);

    $form = fullForm();
    $this->forms->postTo($form, $this->admin, $depot);

    expect($form->fresh()->postings()->count())->toBe(2);

    // Sam is in both rooms and answers once.
    Livewire::actingAs($this->staff)
        ->test(FormFill::class, ['form' => $form])
        ->set('values.'.fieldOf($form, 'short_text')->id, 'Bay 4')
        ->call('submit')
        ->assertHasNoErrors();

    expect($form->fresh()->responseCount())->toBe(1)
        // And the audience is de-duplicated, not counted twice.
        ->and($form->fresh()->audience()->pluck('id')->duplicates())->toBeEmpty();
});

it('does not send the same form into the same room twice', function () {
    $form = fullForm();

    $this->forms->postTo($form, $this->admin, $this->room);

    expect($form->fresh()->postings()->count())->toBe(1)
        ->and(Message::where('room_id', $this->room->id)->count())->toBe(1);
});

it('refuses to send a form with no questions', function () {
    $empty = Form::create([
        'created_by' => $this->admin->id,
        'title' => 'Nothing to ask',
    ]);

    expect(fn () => $this->forms->postTo($empty, $this->admin, $this->room))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to send a form that has already closed', function () {
    $form = draftForm();
    $this->forms->close($form);

    expect(fn () => $this->forms->postTo($form->fresh(), $this->admin, $this->room))
        ->toThrow(InvalidArgumentException::class);
});

it('numbers the questions in the order they were built', function () {
    $labels = fullForm()->fields->pluck('label')->all();

    expect($labels)->toBe([
        'Which bay?', 'What did you find?', 'How many faults?',
        'Safe to run overnight?', 'Photos',
    ]);
});

it('drops questions with no label rather than keeping a blank one', function () {
    $form = $this->forms->create($this->admin, 'Checks', null, [
        ['type' => 'short_text', 'label' => 'Which bay?'],
        ['type' => 'short_text', 'label' => '   '],
        ['type' => 'not_a_type', 'label' => 'Ignored'],
    ]);

    expect($form->fields)->toHaveCount(1);
});

it('refuses a form with no answerable question', function () {
    expect(fn () => $this->forms->create($this->admin, 'Empty', null, [
        ['type' => 'short_text', 'label' => ''],
    ]))->toThrow(InvalidArgumentException::class);
});

it('caps how many questions one form may ask', function () {
    $fields = collect(range(1, FormService::MAX_FIELDS + 5))
        ->map(fn (int $i) => ['type' => 'short_text', 'label' => "Question {$i}"])
        ->all();

    $form = $this->forms->create($this->admin, 'Long one', null, $fields);

    expect($form->fields)->toHaveCount(FormService::MAX_FIELDS);
});

it('lets an admin write a form from the builder screen', function () {
    Livewire::actingAs($this->admin)
        ->test(FormBuilder::class)
        ->set('title', 'Vehicle checks')
        ->set('fields', [
            ['type' => 'short_text', 'label' => 'Registration', 'help' => '', 'required' => true],
            ['type' => 'yes_no', 'label' => 'Roadworthy?', 'help' => '', 'required' => false],
        ])
        ->call('create')
        ->assertHasNoErrors();

    expect(Form::where('title', 'Vehicle checks')->exists())->toBeTrue();
});

it('will not save a form whose question was left empty', function () {
    Livewire::actingAs($this->admin)
        ->test(FormBuilder::class)
        ->set('title', 'Vehicle checks')
        ->set('fields', [['type' => 'short_text', 'label' => '', 'help' => '', 'required' => false]])
        ->call('create')
        ->assertHasErrors('fields.0.label');

    expect(Form::count())->toBe(0);
});

/* ------------------------------------------------------------------ answering */

it('records an answer of every type', function () {
    $form = fullForm();

    Livewire::actingAs($this->staff)
        ->test(FormFill::class, ['form' => $form])
        ->set('values.'.fieldOf($form, 'short_text')->id, 'Bay 4')
        ->set('values.'.fieldOf($form, 'long_text')->id, "Loose guard rail.\nReported to Ivan.")
        ->set('values.'.fieldOf($form, 'number')->id, '3')
        ->set('values.'.fieldOf($form, 'yes_no')->id, 'no')
        ->set('uploads.'.fieldOf($form, 'picture')->id, [UploadedFile::fake()->image('rail.jpg')])
        ->call('submit')
        ->assertHasNoErrors();

    $response = $form->fresh()->responseFor($this->staff);

    expect($response->isSubmitted())->toBeTrue()
        ->and($response->answerTo(fieldOf($form, 'short_text'))->value)->toBe('Bay 4')
        ->and($response->answerTo(fieldOf($form, 'long_text'))->value)->toContain('Loose guard rail')
        ->and($response->answerTo(fieldOf($form, 'number'))->value)->toBe('3')
        ->and($response->answerTo(fieldOf($form, 'yes_no'))->value)->toBe('no')
        ->and($response->answerTo(fieldOf($form, 'picture'))->files)->toHaveCount(1);
});

it('stores a picture on the private disk under a name the uploader did not choose', function () {
    $form = fullForm();

    Livewire::actingAs($this->staff)
        ->test(FormFill::class, ['form' => $form])
        ->set('values.'.fieldOf($form, 'short_text')->id, 'Bay 4')
        ->set('uploads.'.fieldOf($form, 'picture')->id, [UploadedFile::fake()->image('../evil.jpg')])
        ->call('submit')
        ->assertHasNoErrors();

    $file = $form->fresh()->responseFor($this->staff)
        ->answerTo(fieldOf($form, 'picture'))->files->first();

    Storage::disk($file->disk)->assertExists($file->path);

    expect($file->path)->toStartWith('forms/'.$form->id.'/')
        ->and($file->path)->not->toContain('evil')
        ->and($file->original_name)->toContain('evil.jpg');
});

it('blocks a submission that leaves a required question blank', function () {
    $form = fullForm();

    Livewire::actingAs($this->staff)
        ->test(FormFill::class, ['form' => $form])
        ->set('values.'.fieldOf($form, 'long_text')->id, 'Nothing to report')
        ->call('submit')
        ->assertHasErrors('values.'.fieldOf($form, 'short_text')->id);

    expect($form->fresh()->responseCount())->toBe(0);
});

it('names the question in the error rather than the array key', function () {
    $form = fullForm();

    $errors = Livewire::actingAs($this->staff)
        ->test(FormFill::class, ['form' => $form])
        ->call('submit')
        ->errors()
        ->get('values.'.fieldOf($form, 'short_text')->id);

    expect($errors[0])->toContain('which bay?')
        ->and($errors[0])->not->toContain('values.');
});

it('refuses a number field that was sent words', function () {
    $form = fullForm();

    Livewire::actingAs($this->staff)
        ->test(FormFill::class, ['form' => $form])
        ->set('values.'.fieldOf($form, 'short_text')->id, 'Bay 4')
        ->set('values.'.fieldOf($form, 'number')->id, 'quite a lot')
        ->call('submit')
        ->assertHasErrors('values.'.fieldOf($form, 'number')->id);
});

it('refuses a yes-or-no field that was sent something else', function () {
    $form = fullForm();

    Livewire::actingAs($this->staff)
        ->test(FormFill::class, ['form' => $form])
        ->set('values.'.fieldOf($form, 'short_text')->id, 'Bay 4')
        ->set('values.'.fieldOf($form, 'yes_no')->id, 'maybe')
        ->call('submit')
        ->assertHasErrors('values.'.fieldOf($form, 'yes_no')->id);
});

it('refuses a document uploaded to a picture field', function () {
    $form = fullForm();

    Livewire::actingAs($this->staff)
        ->test(FormFill::class, ['form' => $form])
        ->set('values.'.fieldOf($form, 'short_text')->id, 'Bay 4')
        ->set('uploads.'.fieldOf($form, 'picture')->id, [
            UploadedFile::fake()->create('report.pdf', 8, 'application/pdf'),
        ])
        ->call('submit')
        ->assertHasErrors('uploads.'.fieldOf($form, 'picture')->id.'.0');
});

/* ------------------------------------------------------------------ correcting */

it('moves the existing response rather than adding a second one', function () {
    $form = fullForm();
    $bay = fieldOf($form, 'short_text');

    $fill = fn (string $value) => Livewire::actingAs($this->staff)
        ->test(FormFill::class, ['form' => $form])
        ->set('values.'.$bay->id, $value)
        ->call('submit')
        ->assertHasNoErrors();

    $fill('Bay 4');
    $fill('Bay 5');

    expect($form->fresh()->responses()->count())->toBe(1)
        ->and($form->fresh()->responseFor($this->staff)->answerTo($bay)->value)->toBe('Bay 5')
        // One answer row per question, not one per submission.
        ->and(FormAnswer::where('form_field_id', $bay->id)->count())->toBe(1);
});

it('reopens what you already sent when you come back', function () {
    $form = fullForm();
    $bay = fieldOf($form, 'short_text');

    Livewire::actingAs($this->staff)
        ->test(FormFill::class, ['form' => $form])
        ->set('values.'.$bay->id, 'Bay 4')
        ->call('submit');

    Livewire::actingAs($this->staff)
        ->test(FormFill::class, ['form' => $form])
        ->assertSet('values.'.$bay->id, 'Bay 4');
});

it('keeps existing pictures through an edit that does not touch them', function () {
    $form = fullForm();
    $bay = fieldOf($form, 'short_text');
    $photos = fieldOf($form, 'picture');

    Livewire::actingAs($this->staff)
        ->test(FormFill::class, ['form' => $form])
        ->set('values.'.$bay->id, 'Bay 4')
        ->set('uploads.'.$photos->id, [UploadedFile::fake()->image('rail.jpg')])
        ->call('submit');

    Livewire::actingAs($this->staff)
        ->test(FormFill::class, ['form' => $form])
        ->set('values.'.$bay->id, 'Bay 5')
        ->call('submit')
        ->assertHasNoErrors();

    expect($form->fresh()->responseFor($this->staff)->answerTo($photos)->files)->toHaveCount(1);
});

it('replaces the pictures when new ones are uploaded', function () {
    $form = fullForm();
    $bay = fieldOf($form, 'short_text');
    $photos = fieldOf($form, 'picture');

    $component = Livewire::actingAs($this->staff)
        ->test(FormFill::class, ['form' => $form])
        ->set('values.'.$bay->id, 'Bay 4')
        ->set('uploads.'.$photos->id, [UploadedFile::fake()->image('one.jpg')])
        ->call('submit');

    $first = $form->fresh()->responseFor($this->staff)->answerTo($photos)->files->first();

    $component
        ->set('uploads.'.$photos->id, [
            UploadedFile::fake()->image('two.jpg'),
            UploadedFile::fake()->image('three.jpg'),
        ])
        ->call('submit')
        ->assertHasNoErrors();

    $files = $form->fresh()->responseFor($this->staff)->answerTo($photos)->files;

    expect($files)->toHaveCount(2);

    // The replaced file is gone from disk too, not just from the table.
    Storage::disk($first->disk)->assertMissing($first->path);
});

/* --------------------------------------------------------------------- closing */

it('refuses an answer once the form has closed', function () {
    $form = fullForm();

    $this->forms->close($form);

    Livewire::actingAs($this->staff)
        ->test(FormFill::class, ['form' => $form->fresh()])
        ->set('values.'.fieldOf($form, 'short_text')->id, 'Bay 4')
        ->call('submit')
        ->assertForbidden();

    expect($form->fresh()->responseCount())->toBe(0);
});

it('keeps the answers that were already given when it closes', function () {
    $form = fullForm();

    Livewire::actingAs($this->staff)
        ->test(FormFill::class, ['form' => $form])
        ->set('values.'.fieldOf($form, 'short_text')->id, 'Bay 4')
        ->call('submit');

    $this->forms->close($form);

    expect($form->fresh()->responseCount())->toBe(1);
});

it('closes on its own once the closing time has passed', function () {
    $form = $this->forms->create($this->admin, 'Timed', null, [
        ['type' => 'short_text', 'label' => 'Which bay?'],
    ], now()->addHour());

    expect($form->isOpen())->toBeTrue();

    $this->travel(2)->hours();

    expect($form->fresh()->isClosed())->toBeTrue();
});

/* ------------------------------------------------------------------- reporting */

it('shows the author who answered and who has not', function () {
    $form = fullForm();

    Livewire::actingAs($this->staff)
        ->test(FormFill::class, ['form' => $form])
        ->set('values.'.fieldOf($form, 'short_text')->id, 'Bay 4')
        ->call('submit');

    Livewire::actingAs($this->admin)
        ->test(FormResponses::class, ['form' => $form])
        ->assertSee('Sam Staff')
        ->assertSee('Bay 4')
        ->assertSee('Still to answer')
        ->assertSee('Otto Other');
});

it('counts a response only once it has been submitted', function () {
    $form = fullForm();

    expect($form->responseCount())->toBe(0);

    Livewire::actingAs($this->staff)
        ->test(FormFill::class, ['form' => $form])
        ->set('values.'.fieldOf($form, 'short_text')->id, 'Bay 4')
        ->call('submit');

    expect($form->fresh()->responseCount())->toBe(1);
});

/* ------------------------------------------------------------ the screens render */

/*
 * Over real HTTP, through the layout, rather than only through Livewire's test
 * harness — a Blade error in the page wrapper does not show up in a component
 * test, and these are screens a person navigates to directly.
 */
it('renders the admin list, the builder, the form and the responses screens', function () {
    $form = fullForm();

    $this->actingAs($this->admin)
        ->get(route('admin.forms'))
        ->assertOk()
        ->assertSee('Site safety check');

    $this->actingAs($this->admin)
        ->get(route('admin.forms.create'))
        ->assertOk()
        ->assertSee('New form');

    $this->actingAs($this->staff)
        ->get(route('forms.show', $form))
        ->assertOk()
        ->assertSee('Site safety check')
        ->assertSee('Which bay?');

    $this->actingAs($this->admin)
        ->get(route('admin.forms.responses', $form))
        ->assertOk()
        ->assertSee('Still to answer');
});

it('shows an unsent form in the admin list as not sent yet', function () {
    draftForm();

    $this->actingAs($this->admin)
        ->get(route('admin.forms'))
        ->assertOk()
        ->assertSee('Not sent yet');
});

it('tells people who can read their answers before they write any', function () {
    $this->actingAs($this->staff)
        ->get(route('forms.show', fullForm()))
        ->assertOk()
        ->assertSee('recorded against your name')
        ->assertSee('Ada Admin');
});

/* -------------------------------------------------------------- in the timeline */

it('shows the form as a card in the room rather than as a bare message', function () {
    $form = fullForm();

    Livewire::actingAs($this->staff)
        ->test(Conversation::class, ['roomId' => $this->room->id])
        ->assertSee('Site safety check')
        ->assertSee('5 questions')
        ->assertSee('Fill this in');
});

it('offers the responses link in the timeline only to the author', function () {
    $form = fullForm();

    Livewire::actingAs($this->admin)
        ->test(Conversation::class, ['roomId' => $this->room->id])
        ->assertSee('Responses');

    Livewire::actingAs($this->staff)
        ->test(Conversation::class, ['roomId' => $this->room->id])
        ->assertDontSee('Responses');
});

it('marks the card answered once you have filled it in', function () {
    $form = fullForm();

    Livewire::actingAs($this->staff)
        ->test(FormFill::class, ['form' => $form])
        ->set('values.'.fieldOf($form, 'short_text')->id, 'Bay 4')
        ->call('submit');

    Livewire::actingAs($this->staff)
        ->test(Conversation::class, ['roomId' => $this->room->id])
        ->assertSee('Answered')
        ->assertSee('Change my answers');
});

/* --------------------------------------------------------- the composer picker */

it('offers an unsent form in the composer and sends it when chosen', function () {
    $form = draftForm();

    $component = Livewire::actingAs($this->admin)
        ->test(Conversation::class, ['roomId' => $this->room->id])
        ->call('openFormPicker');

    expect($component->get('formPickerOpen'))->toBeTrue();

    $component->assertSee('Site safety check')
        ->call('sendForm', $form->id)
        ->assertHasNoErrors();

    expect($form->fresh()->isPosted())->toBeTrue()
        ->and($component->get('formPickerOpen'))->toBeFalse();
});

it('stops offering a form the room already has', function () {
    $form = fullForm();

    Livewire::actingAs($this->admin)
        ->test(Conversation::class, ['roomId' => $this->room->id])
        ->call('openFormPicker')
        // The card is in the timeline, so the title is on the page either way.
        // What must not be there is the option to send it a second time.
        ->assertSee('Every open form has already been sent here');

    expect($form->fresh()->postings()->count())->toBe(1);
});

it('does not offer a closed form or one with no questions', function () {
    $closed = draftForm();
    $this->forms->close($closed);

    Form::create(['created_by' => $this->admin->id, 'title' => 'Questionless']);

    Livewire::actingAs($this->admin)
        ->test(Conversation::class, ['roomId' => $this->room->id])
        ->call('openFormPicker')
        ->assertDontSee('Questionless')
        ->assertSee('Every open form has already been sent here');
});

it('does not show the send-a-form control to an employee', function () {
    draftForm();

    Livewire::actingAs($this->staff)
        ->test(Conversation::class, ['roomId' => $this->room->id])
        ->assertDontSee('Send a form')
        ->call('openFormPicker')
        ->assertForbidden();
});

it('lets the author close the form from the responses screen', function () {
    $form = fullForm();

    Livewire::actingAs($this->admin)
        ->test(FormResponses::class, ['form' => $form])
        ->call('closeForm')
        ->assertHasNoErrors();

    expect($form->fresh()->isClosed())->toBeTrue();
});
