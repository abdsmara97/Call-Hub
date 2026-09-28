<?php

namespace App\Livewire\Admin;

use App\Models\Form;
use App\Services\FormService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every form in the hub, and what has become of it.
 *
 * The list an administrator works from: write a form here, see where it has
 * been sent and how many people have answered, read the answers, close it. What
 * it deliberately does not do is send one anywhere — a form reaches people from
 * the conversation it belongs in, chosen in the composer.
 */
#[Layout('layouts.app')]
class FormManager extends Component
{
    use WithPagination;

    public string $status = '';

    public string $filter = 'open';

    private const PER_PAGE = 15;

    public function mount(): void
    {
        Gate::authorize('create', Form::class);
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function closeForm(int $formId, FormService $forms): void
    {
        $form = Form::findOrFail($formId);

        Gate::authorize('close', $form);

        $forms->close($form);

        $this->status = '“'.$form->title.'” is closed. Nobody can add or change an answer now.';
    }

    /**
     * Deleting takes the responses with it, so it is only offered while there
     * are none. After that, closing is the honest option.
     */
    public function deleteForm(int $formId): void
    {
        $form = Form::findOrFail($formId);

        Gate::authorize('delete', $form);

        $title = $form->title;
        $form->delete();

        $this->status = '“'.$title.'” was deleted.';
    }

    public function render()
    {
        $forms = Form::query()
            ->with(['creator', 'postings.room'])
            ->withCount([
                'fields',
                'responses as response_count' => fn ($q) => $q->whereNotNull('submitted_at'),
            ])
            ->when($this->filter === 'open', fn ($q) => $q->where(
                fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>', now())
            ))
            ->when($this->filter === 'closed', fn ($q) => $q->whereNotNull('closes_at')
                ->where('closes_at', '<=', now()))
            ->latest()
            ->paginate(self::PER_PAGE);

        return view('livewire.admin.form-manager', [
            'forms' => $forms,
        ]);
    }
}
