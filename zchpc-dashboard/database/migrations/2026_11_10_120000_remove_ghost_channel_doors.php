<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Remove ghost channel doors that were artificially created for secondary
     * channels on single-door controllers (e.g. Main Entrance Channel 2, 3, 4).
     */
    public function up(): void
    {
        $ghostDoorIds = DB::table('doors')
            ->where('notes', 'Registered for a pre-existing controller channel.')
            ->pluck('id');

        if ($ghostDoorIds->isNotEmpty()) {
            DB::table('personnel_door')->whereIn('door_id', $ghostDoorIds)->delete();
            DB::table('access_group_doors')->whereIn('door_id', $ghostDoorIds)->delete();
            DB::table('doors')->whereIn('id', $ghostDoorIds)->delete();
        }
    }

    public function down(): void
    {
        // Ghost doors should not be restored.
    }
};

