<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_checkouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terminal_id')->constrained()->restrictOnDelete();
            $table->string('employee_no', 32);
            $table->date('attendance_date');
            $table->dateTime('checked_out_at');
            $table->dateTime('last_entry_at');
            $table->string('person_name', 128);
            $table->string('terminal_name');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recorded_by_name');
            $table->string('reason', 500);
            $table->timestamps();
            $table->index(['terminal_id', 'employee_no', 'attendance_date'], 'attendance_checkout_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_checkouts');
    }
};
