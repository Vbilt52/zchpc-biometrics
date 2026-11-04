<?php

namespace App\Services;

use App\Models\Door;
use App\Models\Terminal;
use Illuminate\Support\Collection;

/**
 * Keeps the door registry in step with the controllers that exist.
 *
 * Registration never grants anything: it only makes a controller channel
 * selectable in the permission screens. Existing doors are left alone.
 */
class DoorCatalog
{
    /**
     * Register channel 1 for every API-managed terminal that has no door yet.
     *
     * @return list<Door>
     */
    public function registerMissingControllerDoors(): array
    {
        $created = [];

        foreach (Terminal::apiManaged()->orderBy('id')->get() as $index => $terminal) {
            if ($terminal->doors()->exists()) {
                continue;
            }

            $created[] = Door::query()->create([
                'name' => trim((string) ($terminal->location ?: $terminal->name)) ?: 'Door',
                'code' => 'T'.$terminal->id.'-D1',
                'zone' => $terminal->phase ?: null,
                'terminal_id' => $terminal->id,
                'door_no' => 1,
                'plan_template_no' => '1',
                'notes' => 'Registered from the '.$terminal->name.' controller.',
                'is_active' => true,
                'sort_order' => $index + 1,
            ]);
        }

        return $created;
    }

    /**
     * Doors for the selection screens, grouped by building → floor → zone.
     *
     * @return Collection<string, Collection<string, Collection<string, Collection<int, Door>>>>
     */
    public function groupedDoors(): Collection
    {
        return Door::query()
            ->active()
            ->with('terminal')
            ->orderBy('building')
            ->orderBy('floor')
            ->orderBy('zone')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Door $door): string => $door->building ?: 'Unassigned building')
            ->map(fn ($byBuilding) => $byBuilding
                ->groupBy(fn (Door $door): string => $door->floor ?: 'Unassigned floor')
                ->map(fn ($byFloor) => $byFloor
                    ->groupBy(fn (Door $door): string => $door->zone ?: 'Unassigned zone')));
    }
}
