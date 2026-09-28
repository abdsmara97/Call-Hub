<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forms: a set of questions an administrator writes once, then sends into as
 * many rooms and direct messages as it is needed in.
 *
 * A form is deliberately NOT owned by a conversation. It is authored in the
 * admin area and exists on its own; where it has been sent lives in
 * form_postings, one row per room. That separation is what lets the same
 * "Vehicle checks" form go to three depots and collect one set of answers,
 * instead of becoming three forms with three sets to reconcile.
 *
 * Responses hang off the form rather than the posting, for the same reason:
 * a person answers the form once, wherever they happened to find it.
 *
 * Two deliberate differences from polls, which occupy the same row in a
 * timeline. A response is attributable — a form asks people for their answers,
 * so the answers carry names. And a picture answer is a real file, so it needs
 * its own table rather than a text column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::create('form_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            // Like a poll, a posted form is announced by an ordinary message, so
            // it appears in the timeline, threads and search like anything else.
            // Deleting that message leaves the form and its responses standing.
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('posted_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // Sending the same form into the same room twice would give people
            // two cards that lead to one response. Once per room.
            $table->unique(['form_id', 'room_id']);
            $table->index(['room_id', 'created_at']);
        });

        Schema::create('form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->string('type');                 // App\Enums\FormFieldType
            $table->string('label');
            $table->string('help')->nullable();
            $table->boolean('required')->default(false);
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();

            $table->index(['form_id', 'position']);
        });

        Schema::create('form_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            // One response per person. Editing before the form closes moves the
            // existing row rather than adding a second, exactly as re-voting does.
            $table->unique(['form_id', 'user_id']);
        });

        Schema::create('form_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_response_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_field_id')->constrained()->cascadeOnDelete();
            // Null for a picture field, whose content lives in form_answer_files,
            // and for a question the respondent left blank.
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['form_response_id', 'form_field_id']);
        });

        Schema::create('form_answer_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_answer_id')->constrained()->cascadeOnDelete();

            // Same columns and the same rules as `attachments`: a randomised
            // path on a private disk, with the uploader's filename kept for
            // display only. Separate table rather than a widened attachments
            // one, because an attachment belongs to a message and this does not.
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamps();

            $table->index('form_answer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_answer_files');
        Schema::dropIfExists('form_answers');
        Schema::dropIfExists('form_responses');
        Schema::dropIfExists('form_fields');
        Schema::dropIfExists('form_postings');
        Schema::dropIfExists('forms');
    }
};
