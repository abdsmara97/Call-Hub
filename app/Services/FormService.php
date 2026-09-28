<?php

namespace App\Services;

use App\Enums\FormFieldType;
use App\Events\FormUpdated;
use App\Models\Form;
use App\Models\FormAnswer;
use App\Models\FormAnswerFile;
use App\Models\FormField;
use App\Models\FormPosting;
use App\Models\FormResponse;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FormService
{
    /** A form nobody could finish is not a form. */
    public const MAX_FIELDS = 20;

    /**
     * Writes a form. Nothing is sent anywhere — a form is authored in the admin
     * area and sits there until somebody posts it into a conversation, which is
     * a separate act with its own permission.
     *
     * @param  array<int, array{type: string, label: string, help?: ?string, required?: bool}>  $fields
     */
    public function create(
        User $author,
        string $title,
        ?string $description,
        array $fields,
        ?\DateTimeInterface $closesAt = null,
    ): Form {
        $title = trim($title);
        $description = trim((string) $description) ?: null;
        $fields = $this->normaliseFields($fields);

        if ($title === '') {
            throw new \InvalidArgumentException('A form needs a title.');
        }

        if ($fields === []) {
            throw new \InvalidArgumentException('A form needs at least one question.');
        }

        $form = DB::transaction(function () use ($author, $title, $description, $fields, $closesAt) {
            $form = Form::create([
                'created_by' => $author->getKey(),
                'title' => $title,
                'description' => $description,
                'closes_at' => $closesAt,
            ]);

            foreach ($fields as $position => $field) {
                FormField::create([
                    'form_id' => $form->getKey(),
                    'type' => $field['type'],
                    'label' => $field['label'],
                    'help' => $field['help'],
                    'required' => $field['required'],
                    'position' => $position,
                ]);
            }

            return $form;
        });

        return $form->load(['fields', 'creator']);
    }

    /**
     * Sends a form into a conversation.
     *
     * Announced by an ordinary message carrying the title, so it appears in the
     * timeline, threads, pins and searches like anything else — the arrangement
     * polls use. Sending the same form into the same room twice is a no-op
     * rather than an error: two cards leading to one response would only
     * confuse the people looking at them.
     */
    public function postTo(Form $form, User $sender, Room $room): FormPosting
    {
        if ($form->isClosed()) {
            throw new \InvalidArgumentException('This form has closed, so it cannot be sent anywhere.');
        }

        if ($form->fields()->doesntExist()) {
            throw new \InvalidArgumentException('This form has no questions yet.');
        }

        $existing = FormPosting::query()
            ->where('form_id', $form->getKey())
            ->where('room_id', $room->getKey())
            ->first();

        if ($existing) {
            return $existing;
        }

        $posting = DB::transaction(function () use ($form, $sender, $room) {
            $message = app(MessageService::class)->send($sender, $room, $form->title);

            return FormPosting::create([
                'form_id' => $form->getKey(),
                'room_id' => $room->getKey(),
                'message_id' => $message->getKey(),
                'posted_by' => $sender->getKey(),
            ]);
        });

        broadcast(new FormUpdated($form, $room->getKey()))->toOthers();

        return $posting;
    }

    /**
     * Records one person's answers.
     *
     * Re-submitting moves the existing response rather than adding a second,
     * which is what the unique(form_id, user_id) index expects — so somebody who
     * spots a mistake can correct it until the form closes.
     *
     * Text answers are written for every field, including blank ones, so a
     * deliberate "I left this empty" is distinguishable from a question that
     * was added after the fact. Pictures are only replaced when new ones are
     * uploaded: an edit that does not touch the photos keeps them.
     *
     * @param  array<int, mixed>  $values  field id => answer
     * @param  array<int, array<int, UploadedFile>>  $uploads  field id => files
     */
    public function submit(Form $form, User $user, array $values, array $uploads = []): FormResponse
    {
        if ($form->isClosed()) {
            throw new \InvalidArgumentException('This form has closed.');
        }

        $fields = $form->fields()->get();

        $response = DB::transaction(function () use ($form, $user, $fields, $values, $uploads) {
            $response = FormResponse::firstOrCreate(
                ['form_id' => $form->getKey(), 'user_id' => $user->getKey()],
            );

            foreach ($fields as $field) {
                $answer = FormAnswer::updateOrCreate(
                    ['form_response_id' => $response->getKey(), 'form_field_id' => $field->getKey()],
                    ['value' => $this->normaliseValue($field, $values[$field->getKey()] ?? null)],
                );

                if (! $field->type->isFile()) {
                    continue;
                }

                $files = array_values(array_filter(
                    $uploads[$field->getKey()] ?? [],
                    fn ($file) => $file instanceof UploadedFile,
                ));

                if ($files !== []) {
                    $this->replaceFiles($answer, $files, $form->getKey());
                }
            }

            $response->forceFill(['submitted_at' => now()])->save();

            return $response;
        });

        $form->load('responses');

        broadcast(new FormUpdated($form))->toOthers();

        return $response->load('answers.files');
    }

    /**
     * Removes one picture from an answer, so a respondent can drop a photo
     * without having to re-upload the rest.
     */
    public function removeFile(FormAnswerFile $file): void
    {
        Storage::disk($file->disk)->delete($file->path);

        $file->delete();
    }

    /**
     * Closing is immediate and one-way. Responses are kept — the answers are
     * the point of having asked.
     */
    public function close(Form $form): Form
    {
        if ($form->isOpen()) {
            $form->forceFill(['closes_at' => now()])->save();

            broadcast(new FormUpdated($form))->toOthers();
        }

        return $form;
    }

    /**
     * Swaps in a new set of pictures, deleting the files the old rows pointed
     * at. Done inside the caller's transaction so a failure cannot leave an
     * answer pointing at a file that is no longer on disk.
     *
     * @param  array<int, UploadedFile>  $files
     */
    private function replaceFiles(FormAnswer $answer, array $files, int $formId): void
    {
        foreach ($answer->files()->get() as $existing) {
            $this->removeFile($existing);
        }

        $disk = config('hub.attachments.disk');

        foreach (array_slice($files, 0, FormFieldType::MAX_PICTURES) as $file) {
            // store() generates a random name — the respondent's filename is
            // kept for display only and never touches the filesystem.
            $path = $file->store('forms/'.$formId, $disk);

            $dimensions = @getimagesize($file->getRealPath()) ?: null;

            FormAnswerFile::create([
                'form_answer_id' => $answer->getKey(),
                'disk' => $disk,
                'path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize() ?: 0,
                'width' => $dimensions[0] ?? null,
                'height' => $dimensions[1] ?? null,
            ]);
        }
    }

    /**
     * Coerces one submitted value into what the column should hold. A picture
     * field stores nothing here; its content is in form_answer_files.
     */
    private function normaliseValue(FormField $field, mixed $value): ?string
    {
        if ($field->type->isFile()) {
            return null;
        }

        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return match ($field->type) {
            // Stored as the literal 'yes'/'no' rather than 1/0, so a dump of the
            // answers reads as answers.
            FormFieldType::YesNo => in_array($value, ['yes', 'no'], true) ? $value : null,
            FormFieldType::Number => is_numeric($value) ? (string) $value : null,
            default => mb_substr(trim((string) $value), 0, $field->type->maxLength()),
        };
    }

    /**
     * Trims, drops questions with no label, rejects unknown types and caps the
     * list — so the builder cannot post a form the fill screen cannot render.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return list<array{type: string, label: string, help: ?string, required: bool}>
     */
    private function normaliseFields(array $fields): array
    {
        $clean = [];

        foreach ($fields as $field) {
            $label = trim((string) ($field['label'] ?? ''));

            if ($label === '') {
                continue;
            }

            $type = FormFieldType::tryFrom((string) ($field['type'] ?? ''));

            if (! $type) {
                continue;
            }

            $help = trim((string) ($field['help'] ?? ''));

            $clean[] = [
                'type' => $type->value,
                'label' => mb_substr($label, 0, 255),
                'help' => $help === '' ? null : mb_substr($help, 0, 255),
                'required' => (bool) ($field['required'] ?? false),
            ];

            if (count($clean) === self::MAX_FIELDS) {
                break;
            }
        }

        return $clean;
    }
}
