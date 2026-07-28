<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();   // null for DMs — rendered as the other person
            $table->string('slug')->nullable()->unique();
            $table->string('topic')->nullable();
            $table->string('type')->default('public');   // public | private | dm

            // System rooms are created per company and per administration. They
            // cannot be deleted and their membership is managed automatically.
            $table->boolean('is_system')->default(false);
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('administration_id')->nullable()->constrained()->cascadeOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();  // sidebar ordering
            $table->timestamps();

            $table->index(['type', 'is_system']);
            $table->index('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
