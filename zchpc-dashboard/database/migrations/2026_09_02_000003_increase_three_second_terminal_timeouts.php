<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Existing terminals that used the old application default should receive
     * the new five-second connection timeout. Explicitly configured values are
     * left unchanged.
     */
    public function up(): void
    {
        DB::table('terminals')
            ->where('timeout', 3)
            ->update(['timeout' => 5]);
    }

    public function down(): void
    {
        // The previous value is not recorded per row. Reverting all five-second
        // values could overwrite a timeout that an administrator deliberately
        // chose, so this data migration is intentionally non-destructive.
    }
};
