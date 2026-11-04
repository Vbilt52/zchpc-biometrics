<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A door is the unit an administrator grants access to. It belongs to exactly
 * one controller channel, which is what a terminal enforces; the building,
 * floor and zone are presentation and filtering metadata.
 *
 * Existing installations only know terminal-level access, so each API-managed
 * terminal is backfilled with the door it physically controls (channel 1,
 * named after the terminal). Nothing is granted by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('code', 40)->unique();
            $table->string('building', 80)->nullable();
            $table->string('floor', 40)->nullable();
            $table->string('zone', 80)->nullable();
            $table->foreignId('terminal_id')->nullable()->constrained('terminals')->nullOnDelete();
            $table->unsignedTinyInteger('door_no')->default(1);
            $table->string('plan_template_no', 16)->default('1');
            $table->string('notes', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['terminal_id', 'door_no']);
        });

        Schema::create('access_group_doors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('access_group_id')->constrained('access_groups')->cascadeOnDelete();
            $table->foreignId('door_id')->constrained('doors')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['access_group_id', 'door_id']);
        });

        $terminals = DB::table('terminals')
            ->where('integration', 'hikvision_isapi')
            ->orderBy('id')
            ->get();

        foreach ($terminals as $index => $terminal) {
            $name = trim((string) ($terminal->location ?: $terminal->name)) ?: 'Door';
            $code = 'T'.$terminal->id.'-D1';

            DB::table('doors')->insert([
                'name' => $name,
                'code' => $code,
                'building' => null,
                'floor' => null,
                'zone' => $terminal->phase ?: null,
                'terminal_id' => $terminal->id,
                'door_no' => 1,
                'plan_template_no' => '1',
                'notes' => 'Registered automatically from the '.$terminal->name.' controller.',
                'is_active' => true,
                'sort_order' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Guard against a code collision in installations whose terminal
            // ids are not unique in the legacy table (defensive, cheap).
            if (DB::table('doors')->where('code', $code)->count() > 1) {
                DB::table('doors')->where('code', $code)->orderByDesc('id')->limit(1)
                    ->update(['code' => $code.'-'.Str::lower(Str::random(4))]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('access_group_doors');
        Schema::dropIfExists('doors');
    }
};
