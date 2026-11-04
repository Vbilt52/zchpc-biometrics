<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminal_super_fingerprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terminal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnDelete();
            $table->unsignedTinyInteger('finger_id');
            $table->string('status', 32);
            $table->text('last_response')->nullable();
            $table->timestamps();

            $table->unique(['terminal_id', 'personnel_id', 'finger_id'], 'super_fingerprint_assignment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_super_fingerprints');
    }
};
