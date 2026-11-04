<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terminal_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 64)->unique();
            $table->string('employee_no', 32)->nullable()->index();
            $table->string('person_name', 128)->nullable();
            $table->dateTime('occurred_at')->nullable()->index();
            $table->unsignedInteger('major')->nullable();
            $table->unsignedInteger('minor')->nullable();
            $table->string('verify_mode', 64)->nullable();
            $table->string('event_type', 64)->nullable();
            $table->json('raw_payload');
            $table->timestamps();

            $table->index(['terminal_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_events');
    }
};
