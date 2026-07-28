<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recipient snapshot taken at send time. Doubles as the live acknowledgement
     * list and as the permanent, exportable audit trail — there is no separate
     * log table that could drift out of sync with reality.
     */
    public function up(): void
    {
        Schema::create('emergency_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emergency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->timestamp('notified_at')->nullable();       // push actually dispatched
            $table->timestamp('acknowledged_at')->nullable();   // explicit user action
            $table->unsignedTinyInteger('alert_count')->default(0);
            $table->timestamp('last_alerted_at')->nullable();

            $table->timestamps();

            $table->unique(['emergency_id', 'user_id']);
            $table->index(['user_id', 'acknowledged_at']);
            $table->index(['emergency_id', 'acknowledged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_recipients');
    }
};
