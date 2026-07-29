<?php

namespace App\Jobs;

use App\Imports\EmployeeImport;
use App\Services\UserProvisioner;
use App\Support\ImportReport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Parses an uploaded employee CSV off the request cycle.
 *
 * The upload is a one-shot artefact: it is deleted the moment the run ends,
 * successfully or not, so a file full of personal data never lingers on disk.
 */
class ProcessEmployeeImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    /** A retry would re-import whatever the first attempt already created. */
    public int $tries = 1;

    public function __construct(
        public readonly string $path,
        public readonly int $actorId,
    ) {
        // Its own queue: a 2,000-row import runs for minutes, and must never
        // sit in front of an emergency fan-out or a broadcast event.
        $this->onQueue('imports');
    }

    public function handle(UserProvisioner $provisioner): void
    {
        $report = new ImportReport;

        try {
            Excel::import(
                new EmployeeImport(
                    $provisioner,
                    $report,
                    (int) config('hub.import.max_rows', 2000),
                    (int) config('hub.import.chunk_size', 200),
                ),
                $this->path,
                'local'
            );
        } catch (Throwable $e) {
            report($e);

            // Row 0 marks a whole-file problem rather than a bad line.
            $report->recordFailure(0, null, ['The file could not be read: '.$e->getMessage()]);
        } finally {
            $this->publish($report);
            $this->discardUpload();
        }
    }

    public function failed(?Throwable $e): void
    {
        $report = new ImportReport;
        $report->recordFailure(0, null, ['The import stopped unexpectedly. Please try again.']);

        $this->publish($report);
        $this->discardUpload();
    }

    private function publish(ImportReport $report): void
    {
        Cache::put(ImportReport::cacheKey($this->actorId), $report->toArray(), now()->addHour());
        Cache::forget(ImportReport::runningKey($this->actorId));
    }

    private function discardUpload(): void
    {
        Storage::disk('local')->delete($this->path);
    }
}
