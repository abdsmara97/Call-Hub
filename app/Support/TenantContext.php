<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Holds the tenant the current request (or job) is acting for.
 *
 * Web requests bind this from the authenticated user (SetTenantContext
 * middleware). Queued jobs and console commands run unbound by default —
 * they operate on ids handed to them by already-scoped flows — but can wrap
 * work in runAs() when they need scoped queries.
 *
 * Registered as a scoped singleton so Octane/queue workers never leak one
 * request's tenant into the next.
 */
class TenantContext
{
    private ?int $tenantId = null;

    public function set(Tenant|int|null $tenant): void
    {
        $this->tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;
    }

    public function id(): ?int
    {
        return $this->tenantId;
    }

    public function bound(): bool
    {
        return $this->tenantId !== null;
    }

    /** Run a callback as a given tenant, restoring whatever was bound before. */
    public function runAs(Tenant|int $tenant, callable $callback): mixed
    {
        $previous = $this->tenantId;
        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $this->tenantId = $previous;
        }
    }
}
