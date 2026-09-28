<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Single place where employee accounts come into existence, so the admin form
 * and the CSV import cannot drift apart.
 */
class UserProvisioner
{
    public function __construct(private readonly RoomProvisioner $rooms) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: User, 1: string} the user and their temporary password
     */
    public function create(array $attributes, ?string $temporaryPassword = null): array
    {
        $password = $temporaryPassword ?: $this->temporaryPassword();

        $user = DB::transaction(function () use ($attributes, $password) {
            // The tenant comes from the company the account is placed in, so
            // unbound contexts (seeders, console imports) land correctly too.
            $tenantId = $attributes['tenant_id']
                ?? \App\Models\Company::acrossTenants()->whereKey($attributes['company_id'])->value('tenant_id');

            $user = User::create([
                'tenant_id' => $tenantId,
                'name' => $attributes['name'],
                'email' => Str::lower(trim($attributes['email'])),
                'phone' => $attributes['phone'] ?? null,
                'company_id' => $attributes['company_id'],
                'administration_id' => $attributes['administration_id'],
                'job_title' => $attributes['job_title'] ?? null,
                'status' => $attributes['status'] ?? UserStatus::Active->value,
                'password' => $password,
                'must_change_password' => true,
                'email_verified_at' => now(),
            ]);

            $user->syncRoles([$attributes['role'] ?? Permissions::ROLE_EMPLOYEE]);

            $this->rooms->syncSystemRoomsFor($user);

            return $user;
        });

        return [$user, $password];
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $user, array $attributes): User
    {
        $orgChanged = (isset($attributes['company_id']) && (int) $attributes['company_id'] !== $user->company_id)
            || (isset($attributes['administration_id']) && (int) $attributes['administration_id'] !== $user->administration_id);

        DB::transaction(function () use ($user, $attributes, $orgChanged) {
            $user->fill(collect($attributes)->only([
                'name', 'email', 'phone', 'company_id', 'administration_id',
                'job_title', 'status',
            ])->all());

            if (isset($attributes['email'])) {
                $user->email = Str::lower(trim($attributes['email']));
            }

            $user->save();

            if (isset($attributes['role'])) {
                $user->syncRoles([$attributes['role']]);
            }

            // A transfer moves the person between department rooms.
            if ($orgChanged) {
                $this->rooms->syncSystemRoomsFor($user->fresh());
            }
        });

        return $user->refresh();
    }

    public function suspend(User $user): void
    {
        $user->forceFill(['status' => UserStatus::Suspended->value])->save();
    }

    public function reactivate(User $user): void
    {
        $user->forceFill(['status' => UserStatus::Active->value])->save();
    }

    /** Forces the next sign-in through the rotation screen. */
    public function resetPassword(User $user): string
    {
        $password = $this->temporaryPassword();

        $user->forceFill([
            'password' => $password,
            'must_change_password' => true,
        ])->save();

        return $password;
    }

    public function temporaryPassword(): string
    {
        // Readable enough to be handed over verbally, long enough to be safe
        // for the single sign-in it has to survive.
        return Str::ucfirst(Str::lower(Str::random(6))).'-'.random_int(1000, 9999).'-'.Str::lower(Str::random(4));
    }
}
