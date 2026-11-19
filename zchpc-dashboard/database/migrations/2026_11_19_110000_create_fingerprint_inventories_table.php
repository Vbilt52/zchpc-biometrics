<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fingerprint_inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnDelete();
            $table->foreignId('terminal_id')->constrained('terminals')->cascadeOnDelete();
            $table->string('device_employee_no', 32);
            // Slot numbers only. Fingerprint templates and template hashes never leave a terminal.
            $table->json('slots');
            $table->timestamp('checked_at');
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->unique(['personnel_id', 'terminal_id']);
            $table->index(['terminal_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fingerprint_inventories');
    }
};
