<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            // Most tests build a single-tenant graph, so joining whatever
            // tenant already exists keeps users, rooms, and companies from
            // silently landing in different tenants. Isolation tests create
            // their tenants explicitly.
            'tenant_id' => fn () => Tenant::query()->value('id') ?? Tenant::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
        ];
    }
}
