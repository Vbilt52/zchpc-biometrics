<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Existing installations granted access per terminal channel, not per door.
 *
 * Without this backfill the access module would show those people as having no
 * door access while their controllers still open doors, and the first edit via
 * the new module would replace their plan with whatever doors happened to be
 * selected. Every pre-existing terminal assignment therefore becomes a grant
 * for the channel (or channels) that assignment already used.
 *
 * These grants are marked "Legacy import" and the pivot keeps its existing
 * synchronization state, so nothing is presented as newly confirmed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $terminals = DB::table('terminals')->where('integration', 'hikvision_isapi')->get();

        foreach ($terminals as $terminal) {
            $pivots = DB::table('personnel_terminals')->where('terminal_id', $terminal->id)->get();

            if ($pivots->isEmpty()) {
                continue;
            }

            $plans = [];
            $channels = [1 => true];

            foreach ($pivots as $pivot) {
                $person = DB::table('personnel')->where('id', $pivot->personnel_id)->first();

                if ($person === null) {
                    continue;
                }

                $plan = $this->planFor($person);
                $plans[$person->id] = $plan;

                foreach ($plan as $entry) {
                    $channels[(int) $entry['doorNo']] = true;
                }
            }

            foreach (array_keys($channels) as $channel) {
                if (DB::table('doors')->where('terminal_id', $terminal->id)->where('door_no', $channel)->exists()) {
                    continue;
                }

                DB::table('doors')->insert([
                    'name' => $terminal->name.' · Channel '.$channel,
                    'code' => 'T'.$terminal->id.'-D'.$channel,
                    'building' => null,
                    'floor' => null,
                    'zone' => $terminal->phase ?: null,
                    'terminal_id' => $terminal->id,
                    'door_no' => $channel,
                    'plan_template_no' => '1',
                    'notes' => 'Registered for a pre-existing controller channel.',
                    'is_active' => true,
                    'sort_order' => 100 + (int) $channel,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($pivots as $pivot) {
                if (! (bool) $pivot->access_allowed) {
                    continue;
                }

                $plan = $plans[$pivot->personnel_id] ?? [['doorNo' => 1, 'planTemplateNo' => '1']];

                foreach ($plan as $entry) {
                    $doorId = DB::table('doors')
                        ->where('terminal_id', $terminal->id)
                        ->where('door_no', (int) $entry['doorNo'])
                        ->value('id');

                    if ($doorId === null) {
                        continue;
                    }

                    DB::table('personnel_door')->insertOrIgnore([
                        'personnel_id' => $pivot->personnel_id,
                        'door_id' => $doorId,
                        'plan_template_no' => (string) $entry['planTemplateNo'],
                        'granted_by' => null,
                        'granted_by_name' => 'Legacy import',
                        'granted_at' => now(),
                        'expires_at' => null,
                        'notes' => 'Backfilled from the terminal assignment that existed before door-level permissions.',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('personnel_door')->where('granted_by_name', 'Legacy import')->delete();
        DB::table('doors')->where('notes', 'Registered for a pre-existing controller channel.')->delete();
    }

    /**
     * The channels and plan templates this person already uses.
     *
     * @return list<array{doorNo: int, planTemplateNo: string}>
     */
    private function planFor(object $person): array
    {
        $plan = json_decode((string) ($person->right_plan ?? ''), true);

        if (is_array($plan) && $plan !== []) {
            $normalized = [];

            foreach ($plan as $entry) {
                if (! is_array($entry) || ! isset($entry['doorNo'])) {
                    continue;
                }

                $channel = (int) $entry['doorNo'];
                if ($channel < 1 || $channel > 64) {
                    continue;
                }

                $normalized[$channel] = [
                    'doorNo' => $channel,
                    'planTemplateNo' => (string) ($entry['planTemplateNo'] ?? '1'),
                ];
            }

            if ($normalized !== []) {
                return array_values($normalized);
            }
        }

        $channels = array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string) ($person->door_right ?? '1'))),
            static fn (int $channel): bool => $channel >= 1 && $channel <= 64,
        )));

        if ($channels === []) {
            $channels = [1];
        }

        return array_map(
            static fn (int $channel): array => ['doorNo' => $channel, 'planTemplateNo' => '1'],
            $channels,
        );
    }
};
