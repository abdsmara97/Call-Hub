<?php

namespace App\Livewire\Admin;

use App\Imports\EmployeeImport;
use App\Jobs\ProcessEmployeeImport;
use App\Models\User;
use App\Support\ImportReport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Bulk employee onboarding from a CSV.
 *
 * The upload is handed straight to the queue: parsing two thousand rows inside a
 * web request is how imports end up half-finished behind a gateway timeout.
 */
#[Layout('layouts.app')]
class UserImport extends Component
{
    use WithFileUploads;

    public $file;

    public string $statusMessage = '';

    public function mount(): void
    {
        $this->authorize('import', User::class);
    }

    /** @return array<string, array<int, mixed>> */
    protected function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'file.mimes' => 'The file must be a .csv or .txt file.',
            'file.max' => 'The file may not be larger than 2 MB.',
        ];
    }

    public function updatedFile(): void
    {
        $this->validateOnly('file');
    }

    public function import(): void
    {
        $this->authorize('import', User::class);

        $this->validate();

        $actorId = (int) Auth::id();

        // Private disk: an employee roster is not something to leave in public storage.
        $path = $this->file->store('imports', 'local');

        Cache::forget(ImportReport::cacheKey($actorId));
        Cache::put(ImportReport::runningKey($actorId), true, now()->addHour());

        ProcessEmployeeImport::dispatch($path, $actorId);

        $this->reset('file');

        $this->statusMessage = 'The file was accepted and is being processed. Results will appear here automatically.';
    }

    public function clearReport(): void
    {
        $this->authorize('import', User::class);

        Cache::forget(ImportReport::cacheKey((int) Auth::id()));

        $this->statusMessage = 'The previous import report was cleared.';
    }

    public function render()
    {
        $actorId = (int) Auth::id();

        $cached = Cache::get(ImportReport::cacheKey($actorId));

        return view('livewire.admin.user-import', [
            'report' => is_array($cached) ? ImportReport::fromArray($cached) : null,
            'running' => (bool) Cache::get(ImportReport::runningKey($actorId), false),
            'headers' => EmployeeImport::HEADERS,
            'sample' => EmployeeImport::sampleCsv(),
            'maxRows' => (int) config('hub.import.max_rows', 2000),
        ]);
    }
}
