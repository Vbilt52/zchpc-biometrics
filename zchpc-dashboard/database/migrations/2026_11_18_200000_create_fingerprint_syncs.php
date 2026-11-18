<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fingerprint_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnel_id')->constrained('personnel')->restrictOnDelete();
            $table->foreignId('source_terminal_id')->constrained('terminals')->restrictOnDelete();
            $table->foreignId('terminal_id')->constrained()->restrictOnDelete();
            $table->string('source_employee_no', 32);
            $table->string('target_employee_no', 32);
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('message')->nullable();
            $table->json('confirmed_slots')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['personnel_id', 'terminal_id']);
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fingerprint_syncs');
    }
};
