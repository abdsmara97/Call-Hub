<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');

            // restrictOnDelete: an org unit with people in it must not vanish.
            $table->foreignId('company_id')->nullable()->after('phone')
                ->constrained()->restrictOnDelete();
            $table->foreignId('administration_id')->nullable()->after('company_id')
                ->constrained()->restrictOnDelete();

            $table->string('job_title')->nullable()->after('administration_id');
            $table->string('status')->default('active')->after('job_title');

            // Admin-created accounts must rotate the temporary password first.
            $table->boolean('must_change_password')->default(true)->after('status');

            $table->string('avatar_path')->nullable()->after('must_change_password');
            $table->string('status_message', 120)->nullable()->after('avatar_path');
            $table->string('availability')->default('available')->after('status_message');
            $table->timestamp('last_seen_at')->nullable()->after('availability');

            $table->index(['company_id', 'administration_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['administration_id']);
            $table->dropIndex(['company_id', 'administration_id']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'phone', 'company_id', 'administration_id', 'job_title', 'status',
                'must_change_password', 'avatar_path', 'status_message',
                'availability', 'last_seen_at',
            ]);
        });
    }
};
