<?php

namespace Database\Factories;

use App\Models\Administration;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Invitation>
 */
class InvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Join the graph's existing tenant — see CompanyFactory.
            'tenant_id' => fn () => Tenant::query()->value('id') ?? Tenant::factory(),
            'email' => fake()->unique()->safeEmail(),
            'token' => Invitation::generateToken(),
            'role' => Permissions::ROLE_EMPLOYEE,
            'company_id' => fn (array $attributes) => Company::withoutGlobalScopes()
                ->where('tenant_id', $attributes['tenant_id'])->value('id') ?? Company::factory(),
            'administration_id' => fn (array $attributes) => Administration::withoutGlobalScopes()
                ->where('company_id', $attributes['company_id'])->value('id') ?? Administration::factory(),
            'invited_by' => User::factory(),
            'accepted_at' => null,
            'expires_at' => now()->addDays(7),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function accepted(): static
    {
        return $this->state(fn () => ['accepted_at' => now()]);
    }
}
