<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel_device_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnel_id')->constrained('personnel')->restrictOnDelete();
            $table->foreignId('terminal_id')->constrained()->restrictOnDelete();
            $table->string('employee_no', 32);
            $table->timestamps();
            $table->unique(['terminal_id', 'employee_no']);
        });
        Schema::create('personnel_device_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terminal_id')->constrained()->restrictOnDelete();
            $table->string('employee_no', 32);
            $table->string('reason');
            $table->timestamps();
            $table->unique(['terminal_id', 'employee_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnel_device_exclusions');
        Schema::dropIfExists('personnel_device_aliases');
    }
};
