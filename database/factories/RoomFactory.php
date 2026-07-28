<?php

namespace Database\Factories;

use App\Enums\RoomType;
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
