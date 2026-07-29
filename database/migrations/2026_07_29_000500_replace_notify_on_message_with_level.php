<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widens the message-notification preference from on/off to three levels, so
 * "only when someone needs me" becomes expressible.
 *
 * The backfill runs between the add and the drop, so nobody's existing choice
 * is lost: on became `all`, off became `none`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('message_notifications', 16)->default('all')->after('availability');
        });

        DB::table('users')->where('notify_on_message', false)->update(['message_notifications' => 'none']);
        DB::table('users')->where('notify_on_message', true)->update(['message_notifications' => 'all']);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notify_on_message');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_on_message')->default(true)->after('availability');
        });

        // `mentions` collapses to on, since it is closer to on than to off.
        DB::table('users')->where('message_notifications', 'none')->update(['notify_on_message' => false]);
        DB::table('users')->where('message_notifications', '!=', 'none')->update(['notify_on_message' => true]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('message_notifications');
        });
    }
};
