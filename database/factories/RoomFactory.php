<?php

namespace Database\Factories;

use App\Enums\RoomType;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Room>
 */
class RoomFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            // Join the graph's existing tenant — see CompanyFactory.
            'tenant_id' => fn () => Tenant::query()->value('id') ?? Tenant::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'topic' => fake()->sentence(),
            'type' => RoomType::Public->value,
            'is_system' => false,
        ];
    }

    public function private(): static
    {
        return $this->state(fn () => ['type' => RoomType::Private->value]);
    }

    public function dm(): static
    {
        return $this->state(fn () => [
            'type' => RoomType::Dm->value,
            'name' => null,
            'topic' => null,
        ]);
    }
}
