<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnel_terminals', function (Blueprint $table): void {
            $table->boolean('access_allowed')->default(true);
            $table->string('sync_status', 24)->default('unverified');
        });
        Schema::table('personnel', function (Blueprint $table): void {
            $table->dateTime('added_at')->nullable();
        });
        DB::table('personnel')->update(['added_at' => DB::raw('created_at')]);
        // Preserve offline assignments from the earlier retry queue.
        foreach (DB::table('pending_terminal_syncs')->get() as $pending) {
            DB::table('personnel_terminals')->updateOrInsert(
                ['personnel_id' => $pending->personnel_id, 'terminal_id' => $pending->terminal_id],
                ['access_allowed' => true, 'sync_status' => 'pending', 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        Schema::table('personnel_terminals', fn (Blueprint $table) => $table->dropColumn(['access_allowed', 'sync_status']));
        Schema::table('personnel', fn (Blueprint $table) => $table->dropColumn('added_at'));
    }
};
