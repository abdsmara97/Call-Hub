<?php

namespace App\Imports;

use App\Models\Administration;
use App\Models\Company;
use App\Services\UserProvisioner;
use App\Support\ImportReport;
use App\Support\Permissions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Throwable;

/**
 * Row-by-row employee CSV import.
 *
 * Every row is validated on its own and created through UserProvisioner, so an
 * imported account is indistinguishable from one an administrator typed in by
 * hand. A rejected row is recorded, never thrown — a single malformed line must
 * not cost the operator the other nineteen hundred.
 */
class EmployeeImport implements ToCollection, WithChunkReading, WithHeadingRow
{
    /** @var list<string> */
    public const HEADERS = ['name', 'email', 'phone', 'company', 'administration', 'job_title', 'role'];

    /** Data rows consumed so far, across all chunks. */
    private int $cursor = 0;

    /** @var array<string, int>|null lower-cased company name => id */
    private ?array $companies = null;

    /** @var array<string, int>|null "companyId|lower-cased administration name" => id */
    private ?array $administrations = null;

    public function __construct(
        private readonly UserProvisioner $provisioner,
        private readonly ImportReport $report,
        private readonly int $maxRows,
        private readonly int $chunkSize,
    ) {}

    /** @param Collection<int, mixed> $rows */
    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            if ($this->cursor >= $this->maxRows) {
                $this->report->markTruncated();

                return;
            }

            $this->cursor++;

            // The heading occupies line 1, so the first data row is line 2.
            $this->handleRow($this->normalise($row), $this->cursor + 1);
        }
    }

    public function chunkSize(): int
    {
        return max(1, $this->chunkSize);
    }

    /**
     * A ready-to-fill template for this importer.
     *
     * The company and administration columns are filled from the organisation
     * that actually exists, because the importer matches those names and
     * rejects anything it does not recognise. A template carrying invented
     * departments would fail on every single row.
     *
     * @param  bool  $withExamples  false gives the header plus blank rows only.
     */
    public static function sampleCsv(bool $withExamples = true): string
    {
        $lines = [implode(',', self::HEADERS)];

        if (! $withExamples) {
            return implode("\n", $lines)."\n";
        }

        $people = [
            ['Amina Farouk', 'amina.farouk@example.com', '+20 100 555 0101', 'Financial Analyst', 'employee'],
            ['Youssef Nabil', 'youssef.nabil@example.com', '+20 100 555 0102', 'Site Supervisor', ''],
            ['Dina Hassan', 'dina.hassan@example.com', '', 'HR Manager', 'admin'],
        ];

        $pairs = self::examplePairs();

        foreach ($people as $index => [$name, $email, $phone, $jobTitle, $role]) {
            // Fewer real departments than example people is fine — wrap around.
            [$company, $administration] = $pairs[$index % count($pairs)];

            $lines[] = implode(',', [
                self::csvField($name),
                self::csvField($email),
                self::csvField($phone),
                self::csvField($company),
                self::csvField($administration),
                self::csvField($jobTitle),
                self::csvField($role),
            ]);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Real company/administration pairs, spread across different companies so
     * the template shows that both columns matter.
     *
     * @return list<array{string, string}>
     */
    private static function examplePairs(): array
    {
        $pairs = Administration::query()
            ->with('company')
            ->get()
            ->filter(fn (Administration $a) => $a->company !== null)
            ->groupBy('company_id')
            // One per company first, so the examples are not all the same firm.
            ->map(fn ($group) => $group->sortBy('name')->first())
            ->map(fn (Administration $a) => [$a->company->name, $a->name])
            ->values()
            ->all();

        if ($pairs === []) {
            // Nothing set up yet — placeholders, clearly marked as such.
            return [['Your Company', 'Your Administration']];
        }

        return $pairs;
    }

    /** Quotes only when a field would otherwise break the row. */
    private static function csvField(string $value): string
    {
        if (preg_match('/[",\r\n]/', $value) !== 1) {
            return $value;
        }

        return '"'.str_replace('"', '""', $value).'"';
    }

    // ------------------------------------------------------------------ rows

    /** @param array<string, mixed> $row */
    private function handleRow(array $row, int $line): void
    {
        $name = $this->clean($row['name'] ?? null);
        $email = $this->clean($row['email'] ?? null);
        $email = $email === null ? null : Str::lower($email);
        $phone = $this->clean($row['phone'] ?? null);
        $company = $this->clean($row['company'] ?? null);
        $administration = $this->clean($row['administration'] ?? null);
        $jobTitle = $this->clean($row['job_title'] ?? null);

        // A blank role means the safe default, never an admin by accident.
        $role = Str::lower($this->clean($row['role'] ?? null) ?? Permissions::ROLE_EMPLOYEE);

        // Trailing newlines and spacer rows are not worth reporting as errors.
        if ($name === null && $email === null && $company === null && $administration === null) {
            return;
        }

        $companyId = null;
        $administrationId = null;

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'company' => $company,
            'administration' => $administration,
            'job_title' => $jobTitle,
            'role' => $role,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:40'],
            'company' => ['required', 'string'],
            'administration' => ['required', 'string'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'role' => ['required', Rule::in(Permissions::roles())],
        ], [
            'email.unique' => 'An account already exists with this email address.',
            'role.in' => 'Role must be either admin or employee.',
        ], [
            'job_title' => 'job title',
        ]);

        // Organisation names are matched, not created — an unknown department is
        // a typo far more often than it is a new department.
        $validator->after(function ($validator) use ($company, $administration, &$companyId, &$administrationId) {
            if (blank($company) || blank($administration)) {
                return;
            }

            $companyId = $this->companyId($company);

            if ($companyId === null) {
                $validator->errors()->add('company', "Company \"{$company}\" does not exist.");

                return;
            }

            $administrationId = $this->administrationId($companyId, $administration);

            if ($administrationId === null) {
                $validator->errors()->add(
                    'administration',
                    "Administration \"{$administration}\" does not exist in \"{$company}\"."
                );
            }
        });

        if ($validator->fails()) {
            $this->report->recordFailure($line, $email, $validator->errors()->all());

            return;
        }

        try {
            $this->provisioner->create([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'company_id' => $companyId,
                'administration_id' => $administrationId,
                'job_title' => $jobTitle,
                'role' => $role,
            ]);

            $this->report->recordCreated();
        } catch (Throwable $e) {
            report($e);

            $this->report->recordFailure($line, $email, ['The account could not be created: '.$e->getMessage()]);
        }
    }

    // ------------------------------------------------------------- lookups

    private function companyId(string $name): ?int
    {
        $this->companies ??= Company::query()
            ->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $companyName) => [Str::lower(trim((string) $companyName)) => (int) $id])
            ->all();

        return $this->companies[Str::lower(trim($name))] ?? null;
    }

    private function administrationId(int $companyId, string $name): ?int
    {
        $this->administrations ??= Administration::query()
            ->get(['id', 'company_id', 'name'])
            ->mapWithKeys(fn (Administration $a) => [
                $a->company_id.'|'.Str::lower(trim((string) $a->name)) => (int) $a->id,
            ])
            ->all();

        return $this->administrations[$companyId.'|'.Str::lower(trim($name))] ?? null;
    }

    // ------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function normalise(mixed $row): array
    {
        if ($row instanceof Collection) {
            return $row->all();
        }

        return is_array($row) ? $row : (array) $row;
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
