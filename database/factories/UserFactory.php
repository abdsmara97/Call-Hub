<?php

namespace Database\Factories;

use App\Enums\Availability;
use App\Enums\UserStatus;
use App\Models\Administration;
use App\Models\Company;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'phone' => fake()->numerify('+1 555 0## ####'),

            // Every employee belongs to an administration, and the company is
            // whatever that administration sits under — never an unrelated one.
            'administration_id' => Administration::factory(),
            'company_id' => fn (array $attributes) => Administration::find($attributes['administration_id'])?->company_id
                ?? Company::factory(),
            'job_title' => fake()->jobTitle(),
            'status' => UserStatus::Active->value,
            'availability' => Availability::Available->value,
            'notify_on_message' => true,
            'must_change_password' => false,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => ['email_verified_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => ['status' => UserStatus::Suspended->value]);
    }

    public function mustRotatePassword(): static
    {
        return $this->state(fn (array $attributes) => ['must_change_password' => true]);
    }

    /** Places the user inside an existing administration and its company. */
    public function inAdministration(Administration $administration): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $administration->company_id,
            'administration_id' => $administration->getKey(),
        ]);
    }

    public function admin(): static
    {
        return $this->afterCreating(
            fn (\App\Models\User $user) => $user->assignRole(Permissions::ROLE_ADMIN)
        );
    }

    public function employee(): static
    {
        return $this->afterCreating(
            fn (\App\Models\User $user) => $user->assignRole(Permissions::ROLE_EMPLOYEE)
        );
    }
}
