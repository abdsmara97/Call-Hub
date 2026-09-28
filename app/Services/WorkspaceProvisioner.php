<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Workspace creation, done only from the platform panel: one call produces
 * a Tenant, its first Company and Administration, and the admin account
 * that owns them — all or nothing. The admin gets a temporary password and
 * a forced rotation, the same doctrine every other issued account follows.
 * The org chart starts minimal ("General"); the admin reshapes it from
 * their own console afterwards.
 */
class WorkspaceProvisioner
{
    public function __construct(
        private readonly RoomProvisioner $rooms,
        private readonly UserProvisioner $users,
    ) {}

    /**
     * @param  array{workspace: string, name: string, email: string}  $input
     * @return array{0: Tenant, 1: User, 2: string} tenant, admin, and their temporary password
     */
    public function create(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $tenant = Tenant::create([
                'name' => $input['workspace'],
                'slug' => $this->uniqueSlug($input['workspace']),
            ]);

            return app(TenantContext::class)->runAs($tenant, function () use ($tenant, $input) {
                $company = Company::create([
                    'tenant_id' => $tenant->getKey(),
                    'name' => $input['workspace'],
                    'slug' => Str::slug($input['workspace']) ?: 'workspace',
                ]);

                $administration = $company->administrations()->create([
                    'tenant_id' => $tenant->getKey(),
                    'name' => 'General',
                    'slug' => 'general',
                ]);

                [$admin, $temporaryPassword] = $this->users->create([
                    'tenant_id' => $tenant->getKey(),
                    'name' => $input['name'],
                    'email' => $input['email'],
                    'company_id' => $company->getKey(),
                    'administration_id' => $administration->getKey(),
                    'job_title' => 'Workspace Administrator',
                    'role' => Permissions::ROLE_ADMIN,
                ]);

                return [$tenant, $admin, $temporaryPassword];
            });
        });
    }

    /** Tenant slugs are global (they name Reverb channels and LiveKit rooms). */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        $suffix = 2;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
