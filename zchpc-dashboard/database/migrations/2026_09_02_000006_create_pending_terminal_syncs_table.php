<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_terminal_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terminal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnDelete();
            $table->string('operation', 16)->default('upsert');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('next_attempt_at')->index();
            $table->timestamps();

            $table->unique(['terminal_id', 'personnel_id'], 'pending_terminal_sync_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_terminal_syncs');
    }
};
