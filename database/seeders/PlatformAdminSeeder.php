<?php

namespace Database\Seeders;

use App\Models\PlatformAdmin;
use Illuminate\Database\Seeder;

/**
 * The installation's operator account. Same well-known demo password as
 * every other seeded account — change it before anything faces the world.
 */
class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        PlatformAdmin::firstOrCreate(
            ['email' => 'operator@saai.app'],
            ['name' => 'Platform Operator', 'password' => 'password'],
        );
    }
}
