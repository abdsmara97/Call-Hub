<?php

use App\Imports\EmployeeImport;
use App\Models\Administration;
use App\Models\User;
use App\Support\ImportReport;
use App\Support\Permissions;
use App\Services\UserProvisioner;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(OrganisationSeeder::class);

    $administration = Administration::first();

    $this->admin = User::factory()->inAdministration($administration)->create();
    $this->admin->assignRole(Permissions::ROLE_ADMIN);

    $this->employee = User::factory()->inAdministration($administration)->create();
    $this->employee->assignRole(Permissions::ROLE_EMPLOYEE);
});

// ------------------------------------------------------------------ contents

it('uses the same columns as the manual create form', function () {
    expect(EmployeeImport::HEADERS)->toBe([
        'name', 'email', 'phone', 'company', 'administration', 'job_title', 'role',
    ]);
});

it('leads with the header row', function () {
    $lines = explode("\n", trim(EmployeeImport::sampleCsv()));

    expect($lines[0])->toBe('name,email,phone,company,administration,job_title,role');
});

/*
 * The template used to name companies and administrations that did not exist
 * ("Oak Tree Ventures", "Finance"), so anyone who filled it in and uploaded it
 * had every row rejected. The names must come from the real organisation.
 */
it('only names companies and administrations that actually exist', function () {
    $rows = array_slice(explode("\n", trim(EmployeeImport::sampleCsv())), 1);

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        [, , , $company, $administration] = str_getcsv($row);

        $found = Administration::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($administration)])
            ->whereHas('company', fn ($q) => $q->whereRaw('lower(name) = ?', [mb_strtolower($company)]))
            ->exists();

        expect($found)->toBeTrue("\"{$administration}\" does not exist inside \"{$company}\"");
    }
});

it('spreads the examples across different companies', function () {
    $rows = array_slice(explode("\n", trim(EmployeeImport::sampleCsv())), 1);

    $companies = array_map(fn ($row) => str_getcsv($row)[3], $rows);

    expect(array_unique($companies))->toHaveCount(count($companies));
});

it('offers a headers-only variant with no example rows', function () {
    $csv = EmployeeImport::sampleCsv(withExamples: false);

    expect(trim($csv))->toBe('name,email,phone,company,administration,job_title,role');
});

it('falls back to placeholders when no company exists', function () {
    // Users reference both tables, so they go first or the FKs refuse.
    App\Models\RoomMember::query()->delete();
    App\Models\Room::query()->delete();
    User::query()->delete();
    Administration::query()->delete();
    App\Models\Company::query()->delete();

    $rows = array_slice(explode("\n", trim(EmployeeImport::sampleCsv())), 1);

    expect(str_getcsv($rows[0])[3])->toBe('Your Company')
        ->and(str_getcsv($rows[0])[4])->toBe('Your Administration');
});

/*
 * The whole point of a template: fill in nothing, upload it as-is, and every
 * row should be accepted.
 */
it('imports cleanly without a single edit', function () {
    $path = sys_get_temp_dir().'/oak-tree-template-test.csv';
    file_put_contents($path, EmployeeImport::sampleCsv());

    $report = new ImportReport;

    Excel::import(
        new EmployeeImport(app(UserProvisioner::class), $report, 2000, 200),
        $path,
    );

    @unlink($path);

    expect($report->failures())->toBe([])
        ->and($report->created())->toBe(3)
        ->and(User::where('email', 'amina.farouk@example.com')->exists())->toBeTrue();
});

it('treats a blank role in the template as employee, never admin', function () {
    $path = sys_get_temp_dir().'/oak-tree-template-role-test.csv';
    file_put_contents($path, EmployeeImport::sampleCsv());

    Excel::import(
        new EmployeeImport(app(UserProvisioner::class), new ImportReport, 2000, 200),
        $path,
    );

    @unlink($path);

    $blankRole = User::where('email', 'youssef.nabil@example.com')->first();

    expect($blankRole)->not->toBeNull()
        ->and($blankRole->hasRole(Permissions::ROLE_EMPLOYEE))->toBeTrue()
        ->and($blankRole->hasRole(Permissions::ROLE_ADMIN))->toBeFalse();
});

// ------------------------------------------------------------------ download

it('downloads the template as a csv attachment', function () {
    $response = $this->actingAs($this->admin)->get(route('admin.import.template'));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    expect($response->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain('saai-employee-import-template.csv');
});

it('opens cleanly in excel by leading with a utf-8 marker', function () {
    $body = $this->actingAs($this->admin)
        ->get(route('admin.import.template'))
        ->getContent();

    expect(str_starts_with($body, "\u{FEFF}"))->toBeTrue()
        ->and($body)->toContain('name,email,phone,company,administration,job_title,role');
});

it('serves a blank variant on request', function () {
    $body = $this->actingAs($this->admin)
        ->get(route('admin.import.template', ['blank' => 1]))
        ->assertOk()
        ->getContent();

    $lines = explode("\n", trim(str_replace("\u{FEFF}", '', $body)));

    expect($lines)->toHaveCount(1);
});

it('refuses the template to a non-admin', function () {
    $this->actingAs($this->employee)
        ->get(route('admin.import.template'))
        ->assertForbidden();
});

it('sends a guest to the login screen', function () {
    $this->get(route('admin.import.template'))->assertRedirect(route('login'));
});

// -------------------------------------------------------------------- screen

it('offers the download and lists the usable names on the import screen', function () {
    $response = $this->actingAs($this->admin)->get(route('admin.import'));

    $response->assertOk()
        ->assertSee('Download template')
        ->assertSee('Names you can use')
        ->assertSee('Oak Tree Technologies')
        ->assertSee('Engineering');
});
