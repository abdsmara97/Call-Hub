<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            SettingsSeeder::class,
            PlatformAdminSeeder::class,
            OrganisationSeeder::class,
            DemoStaffSeeder::class,
        ]);
    }
}
