<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // Correct the initial allocation scope. Archived users stay archived.
            DB::table('personnel')->update(['personnel_code' => null]);
            $number = 0;
            foreach (DB::table('personnel')->whereNull('deleted_at')->orderBy('id')->get(['id']) as $person) {
                DB::table('personnel')->where('id', $person->id)->update([
                    'personnel_code' => 'HPC'.str_pad((string) ++$number, 3, '0', STR_PAD_LEFT),
                ]);
            }
            DB::table('personnel_number_sequences')->where('id', 1)->update(['last_number' => $number]);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a reviewed numbering backup to reverse this scope correction.');
    }
};
