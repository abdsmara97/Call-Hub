<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emoji reactions on messages.
 *
 * One row per person per emoji per message, so the unique index is what stops
 * someone reacting twice with the same emoji rather than application code
 * having to remember. Reacting with a *different* emoji is allowed — a message
 * can be both funny and important.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Long enough for a multi-codepoint sequence such as a flag or a
            // skin-toned gesture, which can run to several characters.
            $table->string('emoji', 32);

            $table->timestamps();

            $table->unique(['message_id', 'user_id', 'emoji']);

            // Rendering a room reads every reaction for a page of messages.
            $table->index(['message_id', 'emoji']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reactions');
    }
};
