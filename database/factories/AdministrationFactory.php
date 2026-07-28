<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Administration>
 */
class AdministrationFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->randomElement([
            'Engineering', 'Operations', 'Dispatch', 'Warehouse',
            'Maintenance', 'Security', 'IT Support', 'Product & Design',
        ]);

        return [
            'company_id' => Company::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
        ];
    }
}
