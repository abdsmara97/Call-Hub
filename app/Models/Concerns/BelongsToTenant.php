<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The tenant boundary, applied at the model layer.
 *
 * When a request has a tenant bound (every authenticated web request does),
 * queries on the model are confined to that tenant and new rows inherit its
 * id automatically. When nothing is bound — queue workers, console commands,
 * seeders — queries run unscoped: those paths receive ids from flows that
 * were already scoped, and provisioning code sets tenant_id explicitly.
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            $context = app(TenantContext::class);

            if ($context->bound()) {
                $query->where($query->getModel()->qualifyColumn('tenant_id'), $context->id());
            }
        });

        static::creating(function (Model $model) {
            $context = app(TenantContext::class);

            if ($model->getAttribute('tenant_id') === null && $context->bound()) {
                $model->setAttribute('tenant_id', $context->id());
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Escape hatch for the rare admin/console path that must see every tenant. */
    public static function acrossTenants(): Builder
    {
        return static::query()->withoutGlobalScope('tenant');
    }
}
