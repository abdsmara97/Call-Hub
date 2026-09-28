<?php

namespace App\Livewire\Admin;

use App\Enums\FormFieldType;
use App\Models\Form;
use App\Services\FormService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Where an administrator writes a form.
 *
 * Writing one sends it nowhere. It lands in the forms list, and reaches people
 * when somebody picks it from a conversation's composer — which is a separate
 * permission and a separate act.
 */
#[Layout('layouts.app')]
class FormBuilder extends Component
{
    public string $title = '';

    public string $description = '';

    public string $closesAt = '';

    /** @var array<int, array{type: string, label: string, help: string, required: bool}> */
    public array $fields = [];

    public function mount(): void
    {
        Gate::authorize('create', Form::class);

        // Open with one question already there. An empty builder makes people
        // hunt for the "add" button before they can start.
        $this->fields = [$this->blankField()];
    }

    /** @return array{type: string, label: string, help: string, required: bool} */
    private function blankField(): array
    {
        return [
            'type' => FormFieldType::ShortText->value,
            'label' => '',
            'help' => '',
            'required' => false,
        ];
    }

    public function addField(): void
    {
        if (count($this->fields) >= FormService::MAX_FIELDS) {
            $this->addError('fields', 'A form can ask at most '.FormService::MAX_FIELDS.' questions.');

            return;
        }

        $this->fields[] = $this->blankField();
        $this->resetErrorBag('fields');
    }

    public function removeField(int $index): void
    {
        // One is the floor: a form with no questions is not a form.
        if (count($this->fields) <= 1) {
            return;
        }

        unset($this->fields[$index]);
        $this->fields = array_values($this->fields);
    }

    /** Moves a question up (-1) or down (+1), so order can be fixed without retyping. */
    public function moveField(int $index, int $direction): void
    {
        $target = $index + $direction;

        if (! isset($this->fields[$index], $this->fields[$target])) {
            return;
        }

        [$this->fields[$index], $this->fields[$target]] = [$this->fields[$target], $this->fields[$index]];
    }

    public function create(FormService $forms): void
    {
        Gate::authorize('create', Form::class);

        $this->validate([
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'closesAt' => ['nullable', 'date', 'after:now'],
            'fields' => ['array', 'min:1', 'max:'.FormService::MAX_FIELDS],
            'fields.*.label' => ['required', 'string', 'max:255'],
            'fields.*.help' => ['nullable', 'string', 'max:255'],
            'fields.*.type' => ['required', 'string', 'in:'.implode(',', array_column(FormFieldType::cases(), 'value'))],
        ], attributes: [
            'title' => 'form title',
            'closesAt' => 'closing time',
            'fields.*.label' => 'question',
            'fields.*.help' => 'hint',
        ]);

        try {
            $forms->create(
                auth()->user(),
                $this->title,
                $this->description,
                $this->fields,
                $this->closesAt !== '' ? CarbonImmutable::parse($this->closesAt) : null,
            );
        } catch (\InvalidArgumentException $e) {
            $this->addError('fields', $e->getMessage());

            return;
        }

        session()->flash('status', 'Form saved. Send it to people by choosing it from a conversation.');

        $this->redirectRoute('admin.forms', navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.form-builder', [
            'types' => FormFieldType::selectable(),
        ]);
    }
}
