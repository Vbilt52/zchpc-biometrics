<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnel', function (Blueprint $table) {
            $table->string('personnel_code', 32)->nullable()->unique();
            $table->string('job_title', 120)->nullable();
        });
        Schema::create('personnel_number_sequences', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('last_number');
        });
        DB::transaction(function () {
            $number = 0;
            foreach (DB::table('personnel')->orderBy('id')->get(['id']) as $person) {
                DB::table('personnel')->where('id', $person->id)->update([
                    'personnel_code' => 'HPC'.str_pad((string) ++$number, 3, '0', STR_PAD_LEFT),
                    // 1 January 2026, midnight Africa/Harare, stored in UTC.
                    'begin_time' => '2025-12-31 22:00:00',
                ]);
            }
            DB::table('personnel_number_sequences')->insert(['id' => 1, 'last_number' => $number]);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Personnel numbering is a data migration. Restore a reviewed backup rather than discard assigned identifiers.');
    }
};
