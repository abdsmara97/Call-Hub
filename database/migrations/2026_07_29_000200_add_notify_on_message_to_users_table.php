<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master switch for desktop pop-ups about ordinary messages.
 *
 * Defaults to on: someone who has gone to the trouble of granting the browser
 * permission is asking to be told. Emergency alerting is not affected by this
 * column and must never consult it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_on_message')->default(true)->after('availability');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notify_on_message');
        });
    }
};
