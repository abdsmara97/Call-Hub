<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit record. Nothing updates a row here except escalation
     * bookkeeping and resolution — there is deliberately no delete path.
     */
    public function up(): void
    {
        Schema::create('emergencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();

            // Canonical copy of the text. Survives even if the rendered message
            // is later soft-deleted, so the audit trail cannot be hollowed out.
            $table->text('body');

            $table->string('scope');   // room | dm | company | administration | all
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('administration_id')->nullable()->constrained()->nullOnDelete();

            // Snapshotted at send time so later settings changes cannot rewrite
            // what the escalation policy was for a historical emergency.
            $table->unsignedSmallInteger('escalation_interval_minutes');
            $table->unsignedTinyInteger('max_escalations');
            $table->unsignedTinyInteger('escalation_count')->default(0);
            $table->timestamp('last_escalated_at')->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['sender_id', 'created_at']);
            $table->index(['scope', 'created_at']);
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergencies');
    }
};
