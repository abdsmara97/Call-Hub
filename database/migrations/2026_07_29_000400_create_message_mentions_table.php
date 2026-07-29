<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a message names, and where in the text.
 *
 * Resolved once when the message is written and stored as a character span,
 * rather than re-parsed on every render. Two reasons: the body stays exactly
 * what the author typed (the app never renders user content as HTML, and five
 * other things read the body raw), and an old message keeps its meaning after
 * someone is renamed or leaves the room.
 *
 * Offsets are in characters, not bytes — bodies routinely contain emoji.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Character offset of the '@', and the length including it.
            // Bodies are capped at 4,000 characters, so smallint is ample.
            $table->unsignedSmallInteger('start');
            $table->unsignedSmallInteger('length');

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // One span cannot begin twice. Deliberately not unique on
            // (message_id, user_id): naming someone twice in a long message is
            // legitimate, and both occurrences should highlight.
            $table->unique(['message_id', 'start']);

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_mentions');
    }
};
