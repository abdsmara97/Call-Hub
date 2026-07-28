<?php

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;

/**
 * The outcome of one employee CSV import run.
 *
 * Deliberately a plain, array-round-trippable value object: the queue worker
 * builds it and the Livewire screen reads it back out of the cache, so it must
 * survive a serialise/deserialise cycle without dragging Eloquent along.
 */
class ImportReport implements Arrayable
{
    /** @var list<array{row: int, email: string|null, errors: list<string>}> */
    protected array $failures = [];

    protected int $created = 0;

    /** True when the file was longer than hub.import.max_rows and was cut short. */
    protected bool $truncated = false;

    /** Where a finished report for this administrator is parked. */
    public static function cacheKey(int $userId): string
    {
        return "hub.import.report.{$userId}";
    }

    /** Set while a run is in flight, so the screen knows to keep polling. */
    public static function runningKey(int $userId): string
    {
        return "hub.import.running.{$userId}";
    }

    public function recordCreated(): void
    {
        $this->created++;
    }

    /**
     * A row that produced no account. Recorded rather than thrown so that one
     * bad line can never abort the rest of the file.
     *
     * @param  int  $row  1-based line number in the uploaded file (1 is the header).
     * @param  iterable<string>  $errors
     */
    public function recordFailure(int $row, ?string $email, iterable $errors): void
    {
        $this->failures[] = [
            'row' => $row,
            'email' => blank($email) ? null : (string) $email,
            'errors' => array_values(array_map('strval', is_array($errors) ? $errors : iterator_to_array($errors))),
        ];
    }

    public function markTruncated(): void
    {
        $this->truncated = true;
    }

    public function created(): int
    {
        return $this->created;
    }

    public function skipped(): int
    {
        return count($this->failures);
    }

    /** @return list<array{row: int, email: string|null, errors: list<string>}> */
    public function failures(): array
    {
        return $this->failures;
    }

    public function wasTruncated(): bool
    {
        return $this->truncated;
    }

    public function isEmpty(): bool
    {
        return $this->created === 0 && $this->failures === [];
    }

    /** @return array{created: int, skipped: int, truncated: bool, failures: list<array{row: int, email: string|null, errors: list<string>}>} */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'skipped' => $this->skipped(),
            'truncated' => $this->truncated,
            'failures' => $this->failures,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $report = new self;
        $report->created = (int) ($data['created'] ?? 0);
        $report->truncated = (bool) ($data['truncated'] ?? false);

        foreach ((array) ($data['failures'] ?? []) as $failure) {
            $report->failures[] = [
                'row' => (int) ($failure['row'] ?? 0),
                'email' => $failure['email'] ?? null,
                'errors' => array_values((array) ($failure['errors'] ?? [])),
            ];
        }

        return $report;
    }
}
