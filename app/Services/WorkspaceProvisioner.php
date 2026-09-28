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
 * Self-serve workspace creation: one signup produces a Tenant, its first
 * Company and Administration, and the admin account that owns them — all or
 * nothing. The org chart starts minimal ("General"); the admin reshapes it
 * from the console afterwards.
 */
class WorkspaceProvisioner
{
    public function __construct(
        private readonly RoomProvisioner $rooms,
    ) {}

    /**
     * @param  array{workspace: string, name: string, email: string, password: string}  $input
     */
    public function create(array $input): User
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

                $user = User::create([
                    'tenant_id' => $tenant->getKey(),
                    'name' => $input['name'],
                    'email' => Str::lower(trim($input['email'])),
                    'password' => $input['password'],
                    'company_id' => $company->getKey(),
                    'administration_id' => $administration->getKey(),
                    'job_title' => 'Workspace Administrator',
                    'must_change_password' => false,
                    'email_verified_at' => now(),
                ]);

                $user->syncRoles([Permissions::ROLE_ADMIN]);

                $this->rooms->syncSystemRoomsFor($user);

                return $user;
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
