<?php

namespace App\Livewire\Hub;

use App\Enums\FormFieldType;
use App\Models\Form;
use App\Models\FormAnswerFile;
use App\Models\Room;
use App\Services\FormService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The form screen: one person answering one form.
 *
 * Reachable by anyone who can see the room, but only answerable by a member
 * while the form is open — a public room a person has not joined shows them the
 * questions without letting them submit, which mirrors how polls behave.
 *
 * Coming back re-opens what you sent. Answers can be corrected until the form
 * closes, because the alternative is people asking an administrator to delete
 * their response so they can send it again.
 */
#[Layout('layouts.app')]
class FormFill extends Component
{
    use WithFileUploads;

    public int $formId;

    /** @var array<int|string, mixed> field id => answer */
    public array $values = [];

    /** @var array<int|string, array<int, TemporaryUploadedFile>> */
    public array $uploads = [];

    public string $status = '';

    public function mount(Form $form): void
    {
        $this->formId = $form->getKey();

        Gate::authorize('view', $form);

        $this->loadExistingAnswers();
    }

    public function form(): Form
    {
        return Form::with(['fields', 'creator'])->findOrFail($this->formId);
    }

    /**
     * Where to send someone when they are done.
     *
     * A form can have been sent into several conversations, so "back" is not a
     * single place. Their own room wins over one they can merely read, and if
     * neither exists — an admin previewing a form nobody has been sent yet —
     * there is nothing to go back to, and the view says so instead.
     */
    public function contextRoom(): ?Room
    {
        $rooms = $this->form()->rooms()->with('members')->get();

        return $rooms->first(fn (Room $room) => auth()->user()->belongsToRoom($room))
            ?? $rooms->first(fn (Room $room) => auth()->user()->can('view', $room));
    }

    /** Your own response, with everything the screen needs to redraw it. */
    public function response()
    {
        return $this->form()->responses()
            ->where('user_id', auth()->id())
            ->with(['answers.files', 'answers.field'])
            ->first();
    }

    private function loadExistingAnswers(): void
    {
        $response = $this->response();

        if (! $response) {
            return;
        }

        foreach ($response->answers as $answer) {
            if ($answer->field?->type->isFile()) {
                continue;
            }

            $this->values[$answer->form_field_id] = $answer->value;
        }
    }

    /**
     * Validation is built from the questions rather than declared, because the
     * questions are data. Required means something different for a picture than
     * for a string: a photo already on the answer satisfies it, so re-saving a
     * correction to the text does not force a re-upload.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rulesFor(Form $form): array
    {
        $response = $this->response();
        $rules = [];

        foreach ($form->fields as $field) {
            $key = $field->type->isFile()
                ? 'uploads.'.$field->getKey()
                : 'values.'.$field->getKey();

            if ($field->type->isFile()) {
                $alreadyHas = (bool) $response?->answerTo($field)?->files()->exists();

                $rules[$key] = array_merge(
                    [$field->required && ! $alreadyHas ? 'required' : 'nullable'],
                    $field->type->rules(),
                );

                // Pictures only. The message composer's allow-list includes
                // documents, which is not what this field is asking for.
                $rules[$key.'.*'] = [
                    'image',
                    'mimes:jpg,jpeg,png,gif,webp',
                    'max:'.config('hub.attachments.max_kilobytes'),
                ];

                continue;
            }

            $rules[$key] = array_merge(
                [$field->required ? 'required' : 'nullable'],
                $field->type->rules(),
            );
        }

        return $rules;
    }

    /** @return array<string, string> */
    private function attributesFor(Form $form): array
    {
        $attributes = [];

        foreach ($form->fields as $field) {
            $key = $field->type->isFile()
                ? 'uploads.'.$field->getKey()
                : 'values.'.$field->getKey();

            // Without this every message reads "the values.7 field is required".
            $attributes[$key] = mb_strtolower($field->label);
            $attributes[$key.'.*'] = mb_strtolower($field->label);
        }

        return $attributes;
    }

    public function submit(FormService $forms): void
    {
        $form = $this->form();

        Gate::authorize('respond', $form);

        $this->validate($this->rulesFor($form), attributes: $this->attributesFor($form));

        try {
            $forms->submit($form, auth()->user(), $this->values, $this->uploads);
        } catch (\InvalidArgumentException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->reset('uploads');

        $this->status = 'Your answers have been recorded. You can change them until the form closes.';
    }

    public function removePicture(int $fileId, FormService $forms): void
    {
        $file = FormAnswerFile::with('answer.response.form')->findOrFail($fileId);

        // Your own answer, and only while there is still time to redo it.
        abort_unless($file->answer?->response?->user_id === auth()->id(), 403);

        Gate::authorize('respond', $file->answer->response->form);

        $forms->removeFile($file);

        $this->status = 'Picture removed.';
    }

    public function render()
    {
        $form = $this->form();

        return view('livewire.hub.form-fill', [
            'form' => $form,
            'room' => $this->contextRoom(),
            'response' => $this->response(),
            'canRespond' => Gate::allows('respond', $form),
            'canViewResponses' => Gate::allows('viewResponses', $form),
            'types' => FormFieldType::class,
        ]);
    }
}
