<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel_terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnDelete();
            $table->foreignId('terminal_id')->constrained('terminals')->cascadeOnDelete();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps(); // required for withTimestamps() on BelongsToMany
            $table->unique(['personnel_id', 'terminal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnel_terminals');
    }
};
