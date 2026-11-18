<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnel_device_exclusions', fn (Blueprint $table) => $table->boolean('blocks_sync')->default(false));
    }

    public function down(): void
    {
        Schema::table('personnel_device_exclusions', fn (Blueprint $table) => $table->dropColumn('blocks_sync'));
    }
};
