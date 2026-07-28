<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // One level of threading only — replies to replies attach to the root.
            $table->foreignId('parent_id')->nullable()->constrained('messages')->cascadeOnDelete();

            $table->foreignId('emergency_id')->nullable()->constrained()->nullOnDelete();

            $table->text('body')->nullable();   // null when the message is attachment-only
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();   // keeps threads and the emergency trail intact

            $table->index(['room_id', 'id']);
            $table->index('parent_id');
            $table->index('emergency_id');
        });

        // Scout's database driver uses MySQL full-text. SQLite (used for local
        // test runs) has no equivalent, so search falls back to LIKE there.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE messages ADD FULLTEXT messages_body_fulltext (body)');
        }

        // The read cursor points at a message; wire the FK now that it exists.
        Schema::table('room_members', function (Blueprint $table) {
            $table->foreign('last_read_message_id')->references('id')->on('messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            $table->dropForeign(['last_read_message_id']);
        });

        Schema::dropIfExists('messages');
    }
};
