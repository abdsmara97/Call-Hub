<?php

use App\Enums\RoomType;
use App\Livewire\Admin\FormBuilder;
use App\Livewire\Admin\FormResponses;
use App\Livewire\Hub\Conversation;
use App\Livewire\Hub\FormFill;
use App\Models\Administration;
use App\Models\Form;
use App\Models\Room;
use App\Models\User;
use App\Services\FormService;
use App\Services\RoomProvisioner;
use App\Support\Permissions;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Who may write a form, who may send one into a conversation, and who may read
 * what people wrote in it. Three separate rights, and every test here is a line
 * between two of them.
 *
 * A form is more sensitive than a poll and the rules are deliberately not the
 * same. A poll is anonymous and anyone who can post may start one; a form
 * records answers against names, so both writing and sending one are
 * administrative acts, and reading the answers is narrower than seeing the form.
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

    $this->nosy = User::factory()->inAdministration($administration)->create(['name' => 'Nina Nosy']);
    $this->nosy->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->outsider = User::factory()->inAdministration($administration)->create(['name' => 'Olly Outside']);
    $this->outsider->assignRole(Permissions::ROLE_EMPLOYEE);

    $this->room = Room::factory()->create(['name' => 'Plant Floor', 'type' => RoomType::Private->value]);
    $this->room->members()->attach(
        [$this->admin->id, $this->staff->id, $this->nosy->id],
        ['joined_at' => now()],
    );

    $this->forms = app(FormService::class);

    Storage::fake(config('hub.attachments.disk'));
});

/** A one-question form, sent into a room so there is somebody to protect it from. */
function simpleForm(?Room $room = null): Form
{
    $form = test()->forms->create(
        test()->admin,
        'Site safety check',
        null,
        [['type' => 'short_text', 'label' => 'Which bay?', 'required' => true]],
    );

    test()->forms->postTo($form, test()->admin, $room ?? test()->room);

    return $form->fresh(['fields']);
}

/** A form whose only question asks for pictures, sent into the room. */
function pictureForm(): Form
{
    $form = test()->forms->create(test()->admin, 'Photos', null, [
        ['type' => 'picture', 'label' => 'Photos'],
    ]);

    test()->forms->postTo($form, test()->admin, test()->room);

    return $form->fresh(['fields']);
}

/** Sam answers the form, so there is something worth protecting. */
function answerAs(User $user, Form $form, string $value = 'Bay 4'): void
{
    Livewire::actingAs($user)
        ->test(FormFill::class, ['form' => $form])
        ->set('values.'.$form->fields->first()->id, $value)
        ->call('submit')
        ->assertHasNoErrors();
}

/* ------------------------------------------------------------- who may write */

it('does not let an ordinary employee open the builder', function () {
    Livewire::actingAs($this->staff)
        ->test(FormBuilder::class)
        ->assertForbidden();
});

it('does not let an employee reach the admin form screens directly', function () {
    // Hiding the button is a suggestion; the policy is the rule. These are the
    // requests that skip the button entirely.
    foreach (['admin.forms', 'admin.forms.create'] as $route) {
        $this->actingAs($this->staff)->get(route($route))->assertForbidden();
    }

    expect(Form::count())->toBe(0);
});

/* -------------------------------------------------------------- who may send */

it('does not let an employee send a form into their own room', function () {
    $form = test()->forms->create($this->admin, 'Unsent', null, [
        ['type' => 'short_text', 'label' => 'Which bay?'],
    ]);

    Livewire::actingAs($this->staff)
        ->test(Conversation::class, ['roomId' => $this->room->id])
        ->call('sendForm', $form->id)
        ->assertForbidden();

    expect($form->fresh()->isPosted())->toBeFalse();
});

it('does not let an admin send a form into a room they are not in', function () {
    $elsewhere = Room::factory()->create(['type' => RoomType::Private->value]);

    $form = test()->forms->create($this->admin, 'Unsent', null, [
        ['type' => 'short_text', 'label' => 'Which bay?'],
    ]);

    // The permission grants the authority; membership is still required, so an
    // admin cannot drop a form into a private room from the outside.
    expect(Gate::forUser($this->admin)->allows('postIn', [Form::class, $elsewhere]))->toBeFalse();

    Livewire::actingAs($this->admin)
        ->test(Conversation::class, ['roomId' => $elsewhere->id])
        ->assertForbidden();

    expect($form->fresh()->isPosted())->toBeFalse();
});

it('lets an admin send a form into a direct message', function () {
    $dm = app(RoomProvisioner::class)->findOrCreateDm($this->admin, $this->staff);

    $form = simpleForm($dm);

    expect($form->rooms()->pluck('rooms.id')->all())->toBe([$dm->id]);

    // And the other person in the direct message can answer it.
    answerAs($this->staff, $form);

    expect($form->fresh()->responseCount())->toBe(1);
});

/* ------------------------------------------------------------- who may answer */

it('does not let someone outside the room answer', function () {
    $form = simpleForm();

    Livewire::actingAs($this->outsider)
        ->test(FormFill::class, ['form' => $form])
        ->assertForbidden();
});

it('lets a member of a public room read a form without being able to answer it', function () {
    $public = Room::factory()->create(['type' => RoomType::Public->value]);
    $public->members()->attach($this->admin->id, ['joined_at' => now()]);

    $form = simpleForm($public);

    // Nina can see the public room, but has not joined it.
    Livewire::actingAs($this->nosy)
        ->test(FormFill::class, ['form' => $form])
        ->assertOk()
        ->call('submit')
        ->assertForbidden();

    expect($form->fresh()->responseCount())->toBe(0);
});

/* -------------------------------------------------------- who may read answers */

it('does not let another member read what people answered', function () {
    $form = simpleForm();
    answerAs($this->staff, $form);

    Livewire::actingAs($this->nosy)
        ->test(FormResponses::class, ['form' => $form])
        ->assertForbidden();
});

it('does not let a respondent read the responses screen either', function () {
    $form = simpleForm();
    answerAs($this->staff, $form);

    // Having answered a form is not a right to read everyone else's answers.
    Livewire::actingAs($this->staff)
        ->test(FormResponses::class, ['form' => $form])
        ->assertForbidden();
});

it('lets the author and any admin read the responses', function () {
    $form = simpleForm();
    answerAs($this->staff, $form);

    $secondAdmin = User::factory()->inAdministration(Administration::first())->create();
    $secondAdmin->assignRole(Permissions::ROLE_ADMIN);

    Livewire::actingAs($this->admin)
        ->test(FormResponses::class, ['form' => $form])
        ->assertOk()
        ->assertSee('Bay 4');

    Livewire::actingAs($secondAdmin)
        ->test(FormResponses::class, ['form' => $form])
        ->assertOk()
        ->assertSee('Bay 4');
});

it("does not leak another person's answer into the fill screen", function () {
    $form = simpleForm();
    answerAs($this->staff, $form, 'Bay 4');

    // Nina opens the same form. She must see an empty one, not Sam's.
    Livewire::actingAs($this->nosy)
        ->test(FormFill::class, ['form' => $form])
        ->assertDontSee('Bay 4')
        ->assertSet('values', []);
});

it("does not let one person overwrite another person's response", function () {
    $form = simpleForm();
    answerAs($this->staff, $form, 'Bay 4');
    answerAs($this->nosy, $form, 'Bay 9');

    expect($form->fresh()->responses()->count())->toBe(2)
        ->and($form->fresh()->responseFor($this->staff)->answerTo($form->fields->first())->value)->toBe('Bay 4')
        ->and($form->fresh()->responseFor($this->nosy)->answerTo($form->fields->first())->value)->toBe('Bay 9');
});

/* -------------------------------------------------------------- picture answers */

it('serves a picture answer to the author but not to another member', function () {
    $form = pictureForm();

    Livewire::actingAs($this->staff)
        ->test(FormFill::class, ['form' => $form])
        ->set('uploads.'.$form->fields->first()->id, [UploadedFile::fake()->image('rail.jpg')])
        ->call('submit')
        ->assertHasNoErrors();

    $file = $form->fresh()->responseFor($this->staff)
        ->answerTo($form->fields->first())->files->first();

    $url = $file->temporaryUrl();

    // The person who uploaded it, and the form's author, may read it.
    $this->actingAs($this->staff)->get($url)->assertOk();
    $this->actingAs($this->admin)->get($url)->assertOk();

    // Another member of the same room may not — a signature is not enough.
    $this->actingAs($this->nosy)->get($url)->assertForbidden();
});

it('refuses a picture answer whose url was not signed', function () {
    $form = pictureForm();

    Livewire::actingAs($this->staff)
        ->test(FormFill::class, ['form' => $form])
        ->set('uploads.'.$form->fields->first()->id, [UploadedFile::fake()->image('rail.jpg')])
        ->call('submit');

    $file = $form->fresh()->responseFor($this->staff)
        ->answerTo($form->fields->first())->files->first();

    $this->actingAs($this->staff)
        ->get(route('forms.files.show', $file))
        ->assertForbidden();
});

it("does not let someone delete a picture from another person's answer", function () {
    $form = pictureForm();

    Livewire::actingAs($this->staff)
        ->test(FormFill::class, ['form' => $form])
        ->set('uploads.'.$form->fields->first()->id, [UploadedFile::fake()->image('rail.jpg')])
        ->call('submit');

    $file = $form->fresh()->responseFor($this->staff)
        ->answerTo($form->fields->first())->files->first();

    Livewire::actingAs($this->nosy)
        ->test(FormFill::class, ['form' => $form])
        ->call('removePicture', $file->id)
        ->assertForbidden();

    expect($file->fresh())->not->toBeNull();
    Storage::disk($file->disk)->assertExists($file->path);
});

/* --------------------------------------------------------------------- closing */

it('does not let an ordinary member close a form', function () {
    $form = simpleForm();

    Livewire::actingAs($this->nosy)
        ->test(FormResponses::class, ['form' => $form])
        ->assertForbidden();

    expect($form->fresh()->isOpen())->toBeTrue();
});
