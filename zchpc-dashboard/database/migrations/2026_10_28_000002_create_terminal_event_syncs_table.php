<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminal_event_syncs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('terminal_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('through_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_event_syncs');
    }
};
