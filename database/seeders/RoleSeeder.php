<?php

namespace Database\Seeders;

use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (Permissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $admin = Role::findOrCreate(Permissions::ROLE_ADMIN, 'web');
        $admin->syncPermissions(Permissions::all());

        // Employees hold no global permissions. Everything they can do is
        // decided by room membership or by being the subject of the record.
        Role::findOrCreate(Permissions::ROLE_EMPLOYEE, 'web');
    }
}
