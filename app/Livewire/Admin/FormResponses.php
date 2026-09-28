<?php

namespace App\Livewire\Admin;

use App\Models\Form;
use App\Models\FormResponse;
use App\Services\FormService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * What people answered — for the form's author and administrators only.
 *
 * Also shows who has not answered yet, which is usually the reason anyone opens
 * this screen. That list is the membership of every room the form was sent
 * into, minus the people who have responded, so it stays honest when the form
 * goes somewhere new or somebody joins a room after the fact.
 */
#[Layout('layouts.app')]
class FormResponses extends Component
{
    public int $formId;

    public string $status = '';

    public function mount(Form $form): void
    {
        $this->formId = $form->getKey();

        Gate::authorize('viewResponses', $form);
    }

    public function form(): Form
    {
        return Form::with(['fields', 'creator', 'postings.room'])->findOrFail($this->formId);
    }

    public function closeForm(FormService $forms): void
    {
        $form = $this->form();

        Gate::authorize('close', $form);

        $forms->close($form);

        $this->status = 'Form closed. Nobody can add or change an answer now.';
    }

    /** @return Collection<int, FormResponse> */
    private function responses(): Collection
    {
        return $this->form()->responses()
            ->whereNotNull('submitted_at')
            ->with(['user', 'answers.files', 'answers.field'])
            ->latest('submitted_at')
            ->get();
    }

    public function render()
    {
        $form = $this->form();
        $responses = $this->responses();

        $answered = $responses->pluck('user_id')->all();

        return view('livewire.admin.form-responses', [
            'form' => $form,
            'responses' => $responses,
            'outstanding' => $form->audience()
                ->reject(fn ($member) => in_array($member->getKey(), $answered, true))
                ->sortBy('name')
                ->values(),
        ]);
    }
}
