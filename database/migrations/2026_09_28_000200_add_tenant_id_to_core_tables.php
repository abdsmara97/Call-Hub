<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retrofits the tenant boundary onto the tables that are queried directly.
 * Everything below the room level (messages, attachments, polls, forms
 * content, reactions…) stays transitive — it is only ever reached through a
 * room or a user, both of which are scoped here.
 *
 * Existing single-tenant installs are folded into one default tenant so the
 * upgrade is invisible to them.
 */
return new class extends Migration
{
    /** [table => unique indexes that must become per-tenant] */
    private const TABLES = [
        'companies' => ['companies_name_unique' => ['name'], 'companies_slug_unique' => ['slug']],
        'administrations' => [],
        'users' => [],
        'rooms' => ['rooms_slug_unique' => ['slug']],
        'emergencies' => [],
        'settings' => ['settings_key_unique' => ['key']],
        'forms' => [],
    ];

    public function up(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('tenant_id')->nullable()
                    ->constrained('tenants')->cascadeOnDelete();
            });
        }

        $this->backfillDefaultTenant();

        foreach (self::TABLES as $table => $uniques) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $uniques) {
                foreach ($uniques as $index => $columns) {
                    $blueprint->dropUnique($index);
                    $blueprint->unique(array_merge(['tenant_id'], $columns));
                }

                if ($uniques === []) {
                    $blueprint->index('tenant_id');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $uniques) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $uniques) {
                foreach ($uniques as $index => $columns) {
                    $blueprint->dropUnique(array_merge(['tenant_id'], $columns));
                    $blueprint->unique($columns, $index);
                }

                $blueprint->dropConstrainedForeignId('tenant_id');
            });
        }
    }

    private function backfillDefaultTenant(): void
    {
        $hasData = DB::table('users')->exists() || DB::table('companies')->exists();

        if (! $hasData) {
            return;
        }

        $tenantId = DB::table('tenants')->insertGetId([
            'name' => config('app.name'),
            'slug' => 'default',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (array_keys(self::TABLES) as $table) {
            DB::table($table)->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);
        }
    }
};
