<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel', function (Blueprint $table) {
            $table->id();
            $table->string('employee_no', 32)->unique();
            $table->string('name', 64);
            $table->enum('user_type', ['normal', 'visitor', 'blocklist'])->default('normal');
            $table->boolean('valid_enabled')->default(true);
            $table->datetime('begin_time')->nullable();
            $table->datetime('end_time')->nullable();
            $table->string('door_right', 255)->nullable(); // e.g. "1,2,3"
            $table->json('right_plan')->nullable();         // [{doorNo:1,planTemplateNo:"1"},...]
            $table->string('phone_number', 32)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnel');
    }
};
