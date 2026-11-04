<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAccessSyncJob;
use App\Models\PersonnelDoor;
use App\Services\AccessAuditLogger;
use App\Services\DoorAccessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Per-door grants can carry their own expiry. A controller only understands
 * the person's current plan, so an expired grant must shrink that plan on the
 * device as well; otherwise the door keeps enforcing access nobody approved.
 *
 * The queue is written before the local grant is removed: if queueing fails,
 * the grant remains visible and the command retries later. Conversely, a
 * controller that is offline leaves a pending revocation, which the normal
 * retry scheduler keeps trying.
 */
class ExpireDoorGrants extends Command
{
    protected $signature = 'access:expire-grants {--limit=500 : Maximum expired grants handled per run}';

    protected $description = 'Remove expired door grants and queue the affected controllers';

    public function handle(DoorAccessService $access, AccessAuditLogger $audit): int
    {
        $grants = PersonnelDoor::query()
            ->with(['door.terminal', 'personnel'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($grants->isEmpty()) {
            $this->info('No expired door grants.');

            return self::SUCCESS;
        }

        $personnelIds = [];
        $orphaned = [];

        foreach ($grants->groupBy('personnel_id') as $personId => $rows) {
            $person = $rows->first()->personnel;

            if ($person === null) {
                $orphaned = array_merge($orphaned, $rows->pluck('id')->all());

                continue;
            }

            $personnelIds[] = (int) $personId;

            foreach ($rows as $grant) {
                $audit->log(
                    'permission_expired',
                    'warning',
                    'Access to '.($grant->door?->name ?? 'a removed door').' expired for '.$person->name
                        .' and was queued for removal from its controller.',
                    [
                        'door' => $grant->door?->name,
                        'expires_at' => $grant->expires_at?->toIso8601String(),
                        'granted_at' => $grant->granted_at?->toIso8601String(),
                    ],
                    null,
                    $person,
                    $grant->door,
                    $grant->door?->terminal,
                );
            }
        }

        if ($orphaned !== []) {
            PersonnelDoor::query()->whereIn('id', $orphaned)->delete();
        }

        $queued = $personnelIds === [] ? 0 : $access->queueBatch($personnelIds);

        if ($queued > 0) {
            ProcessAccessSyncJob::dispatch();
        }

        // Queued first so a failure above cannot make an expired grant vanish
        // without a pending controller write behind it.
        DB::transaction(fn () => PersonnelDoor::query()
            ->whereIn('id', $grants->pluck('id')->all())
            ->delete());

        $this->info(sprintf(
            'Removed %d expired grant(s) for %d person(s); queued %d controller write(s).',
            $grants->count(),
            count($personnelIds),
            $queued,
        ));

        return self::SUCCESS;
    }
}
