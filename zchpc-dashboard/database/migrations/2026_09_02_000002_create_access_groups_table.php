<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->timestamps();
        });

        foreach (['Supercomputing', 'Applications', 'Administration', 'ZINGSA'] as $name) {
            DB::table('access_groups')->insert([
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('personnel', function (Blueprint $table) {
            // Keep this as a plain nullable column on SQLite. Laravel's
            // SQLite table-rebuild path for an added constrained column can
            // produce a replacement table containing only the new column.
            $table->unsignedBigInteger('access_group_id')->nullable();
        });

        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('personnel', function (Blueprint $table) {
                $table->foreign('access_group_id')
                    ->references('id')
                    ->on('access_groups')
                    ->nullOnDelete();
            });
        }

        DB::table('personnel')->orderBy('id')->each(function (object $person): void {
            if (! is_string($person->department) || $person->department === '') {
                return;
            }

            $groupId = DB::table('access_groups')->where('name', $person->department)->value('id');
            if ($groupId !== null) {
                DB::table('personnel')->where('id', $person->id)->update(['access_group_id' => $groupId]);
            }
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('personnel', function (Blueprint $table) {
                $table->dropForeign(['access_group_id']);
            });
        }

        Schema::table('personnel', function (Blueprint $table) {
            $table->dropColumn('access_group_id');
        });

        Schema::dropIfExists('access_groups');
    }
};
