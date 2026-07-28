<?php

use App\Livewire\Admin\HubSettings;
use App\Livewire\Admin\UserImport;
use App\Livewire\Admin\UserManager;
use App\Models\Administration;
use App\Models\Company;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

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

it('renders the user manager', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.users'))
        ->assertOk()
        ->assertSee('Employees')
        ->assertSee('New employee')
        ->assertSee('Import from CSV');
});

it('filters the user manager by name', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.users', ['search' => $this->admin->name]))
        ->assertOk()
        ->assertSee($this->admin->email);
});

it('renders the import screen', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.import'))
        ->assertOk()
        ->assertSee('Expected format')
        ->assertSee('job_title');
});

it('renders hub settings', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.settings'))
        ->assertOk()
        ->assertSee('Escalation interval')
        ->assertSee('Misuse threshold');
});

it('renders the misuse report empty state', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.misuse'))
        ->assertOk()
        ->assertSee('No one has exceeded the threshold in the last 7 days.');
});

it('blocks a non-admin from the user manager', function () {
    $this->actingAs($this->employee)
        ->get(route('admin.users'))
        ->assertForbidden();
});

it('validates the create-user form', function () {
    $this->actingAs($this->admin);

    Livewire::test(UserManager::class)
        ->call('create')
        ->set('name', '')
        ->set('email', 'nope')
        ->set('formCompanyId', '')
        ->set('formAdministrationId', '')
        ->call('save')
        ->assertHasErrors(['name', 'email', 'formCompanyId', 'formAdministrationId']);
});

it('rejects an administration belonging to a different company', function () {
    $this->actingAs($this->admin);

    $company = Company::first();
    $foreign = Administration::where('company_id', '!=', $company->id)->first();

    expect($foreign)->not->toBeNull('seed needs at least two companies with administrations');

    Livewire::test(UserManager::class)
        ->call('create')
        ->set('name', 'Cross Company')
        ->set('email', 'cross.company@example.test')
        ->set('formCompanyId', (string) $company->id)
        ->set('formAdministrationId', (string) $foreign->id)
        ->set('formRole', 'employee')
        ->call('save')
        ->assertHasErrors('formAdministrationId');
});

it('refuses to let an admin suspend themselves', function () {
    $this->actingAs($this->admin);

    Livewire::test(UserManager::class)
        ->call('suspend', $this->admin->id)
        ->assertForbidden();
});

it('validates hub settings ranges', function () {
    $this->actingAs($this->admin);

    Livewire::test(HubSettings::class)
        ->set('escalationIntervalMinutes', 0)
        ->set('maxEscalations', 21)
        ->set('rateLimitPerWindow', 11)
        ->set('rateLimitWindowMinutes', 121)
        ->set('rateLimitPerDay', 0)
        ->set('misuseThresholdPerWeek', 51)
        ->call('save')
        ->assertHasErrors([
            'escalationIntervalMinutes', 'maxEscalations', 'rateLimitPerWindow',
            'rateLimitWindowMinutes', 'rateLimitPerDay', 'misuseThresholdPerWeek',
        ]);
});

it('queues the import job and rejects a non-CSV upload', function () {
    $this->actingAs($this->admin);

    Queue::fake();
    Storage::fake('local');

    Livewire::test(UserImport::class)
        ->set('file', UploadedFile::fake()->create('people.pdf', 10, 'application/pdf'))
        ->call('import')
        ->assertHasErrors('file');

    Livewire::test(UserImport::class)
        ->set('file', UploadedFile::fake()->createWithContent(
            'people.csv',
            "name,email,phone,company,administration,job_title,role\nA,a@example.test,,X,Y,,employee\n"
        ))
        ->call('import')
        ->assertHasNoErrors();

    Queue::assertPushed(App\Jobs\ProcessEmployeeImport::class);
});
