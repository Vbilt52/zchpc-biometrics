<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnel', function (Blueprint $table) {
            $table->string('email', 120)->nullable();
            $table->text('notes')->nullable();
            $table->string('photo_path', 255)->nullable();
            // active | suspended | draft. Existing rows become active, which
            // is what they have always been treated as.
            $table->string('status', 16)->default('active');
        });

        Schema::table('terminals', function (Blueprint $table) {
            // unknown until the device is asked; supported/unsupported after a
            // capability read. Never guessed from the terminal model.
            $table->string('fp_capability', 16)->default('unknown');
            $table->timestamp('fp_checked_at')->nullable();
            $table->text('fp_detail')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personnel', function (Blueprint $table) {
            $table->dropColumn(['email', 'notes', 'photo_path', 'status']);
        });

        Schema::table('terminals', function (Blueprint $table) {
            $table->dropColumn(['fp_capability', 'fp_checked_at', 'fp_detail']);
        });
    }
};
