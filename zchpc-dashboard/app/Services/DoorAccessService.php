<?php

namespace App\Services;

use App\Models\Door;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Door-level permission management.
 *
 * The local grant is written first and is the record of intent. Each affected
 * controller is then queued and attempted; a failure leaves the grant intact
 * but marks that controller pending, which is the difference between "the
 * administrator asked for this" and "the door enforces it".
 */
class DoorAccessService
{
    public function __construct(
        private readonly PersonnelDeviceAccessService $credentials,
        private readonly AccessAuditLogger $audit,
        private readonly AccessProvisioner $provisioner,
    ) {}

    /**
     * @param  list<int>  $doorIds
     * @return array{terminals: list<Terminal>, synced: int, pending: int}
     */
    public function grant(
        Personnel $personnel,
        array $doorIds,
        ?User $actor = null,
        ?CarbonInterface $expiresAt = null,
        ?string $planTemplate = null,
        ?string $ipAddress = null,
    ): array {
        $this->assertCanHoldAccess($personnel);

        $doorIds = array_values(array_unique(array_map('intval', $doorIds)));
        if ($doorIds === []) {
            throw ValidationException::withMessages(['doors' => 'Select at least one door to grant.']);
        }

        $doors = Door::query()->whereIn('id', $doorIds)->get();
        if ($doors->count() !== count($doorIds)) {
            throw ValidationException::withMessages(['doors' => 'One or more selected doors no longer exist.']);
        }

        foreach ($doors as $door) {
            if (! $door->is_active) {
                throw ValidationException::withMessages(['doors' => $door->name.' is out of service and cannot be granted.']);
            }

            if (! $door->isDeployable()) {
                throw ValidationException::withMessages(['doors' => $door->name.' has no controller, so access cannot be deployed.']);
            }
        }

        DB::transaction(function () use ($personnel, $doors, $actor, $expiresAt, $planTemplate): void {
            foreach ($doors as $door) {
                $personnel->doors()->syncWithoutDetaching([$door->id => [
                    'plan_template_no' => $planTemplate ?: $door->plan_template_no,
                    'granted_by' => $actor?->id,
                    'granted_by_name' => $actor?->name,
                    'granted_at' => now(),
                    'expires_at' => $expiresAt,
                ]]);
            }
        });

        $this->audit->log('permission_granted', 'success',
            'Granted '.$doors->count().' door(s) to '.$personnel->name.'.',
            [
                'doors' => $doors->pluck('name')->all(),
                'terminals' => $doors->pluck('terminal.name')->filter()->unique()->values()->all(),
                'expires_at' => $expiresAt?->toIso8601String(),
            ],
            $actor, $personnel, ipAddress: $ipAddress);

        return $this->apply($personnel->refresh(), $actor, $doors->pluck('terminal_id')->filter()->unique()->values()->all());
    }

    /**
     * @param  list<int>  $doorIds
     * @return array{terminals: list<Terminal>, synced: int, pending: int}
     */
    public function revoke(Personnel $personnel, array $doorIds, ?User $actor = null, ?string $ipAddress = null): array
    {
        $doorIds = array_values(array_unique(array_map('intval', $doorIds)));
        $doors = $personnel->doors()->whereIn('doors.id', $doorIds)->get();

        if ($doors->isEmpty()) {
            throw ValidationException::withMessages(['doors' => 'None of the selected doors are granted to this person.']);
        }

        $terminalIds = $doors->pluck('terminal_id')->filter()->unique()->values()->all();

        DB::transaction(function () use ($personnel, $doors): void {
            $personnel->doors()->detach($doors->pluck('id')->all());
        });

        $this->audit->log('permission_revoked', 'success',
            'Revoked '.$doors->count().' door(s) from '.$personnel->name.'.',
            ['doors' => $doors->pluck('name')->all()],
            $actor, $personnel, ipAddress: $ipAddress);

        return $this->apply($personnel->refresh(), $actor, $terminalIds);
    }

    public function suspend(Personnel $personnel, ?User $actor = null, ?string $ipAddress = null): array
    {
        if ($personnel->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Finish or discard this draft before suspending it.']);
        }

        $personnel->forceFill(['status' => Personnel::STATUS_SUSPENDED])->save();

        $this->audit->log('permission_suspended', 'warning',
            'All door access suspended for '.$personnel->name.'; door grants and credentials were retained.',
            ['doors' => $personnel->effectiveDoors()->pluck('name')->all()],
            $actor, $personnel, ipAddress: $ipAddress);

        return $this->apply($personnel->refresh(), $actor);
    }

    public function reactivate(Personnel $personnel, ?User $actor = null, ?string $ipAddress = null): array
    {
        if ($personnel->isDraft()) {
            throw ValidationException::withMessages(['status' => 'This person has not completed onboarding.']);
        }

        $personnel->forceFill(['status' => Personnel::STATUS_ACTIVE])->save();

        $this->audit->log('permission_reactivated', 'success',
            'Access reactivated for '.$personnel->name.'. Only currently granted doors were restored.',
            ['doors' => $personnel->effectiveDoors()->pluck('name')->all()],
            $actor, $personnel, ipAddress: $ipAddress);

        return $this->apply($personnel->refresh(), $actor);
    }

    /**
     * Push the current local intent for one person to every relevant controller.
     *
     * @return array{terminals: list<Terminal>, synced: int, pending: int}
     */
    public function syncPersonnel(Personnel $personnel, ?User $actor = null): array
    {
        $this->queueAssignments($personnel);

        $result = $this->credentials->syncNow($personnel->refresh(), $actor);

        return ['terminals' => $personnel->terminals()->get()->all(), ...$result];
    }

    /**
     * Queue controller writes for a batch without touching hardware; the queued
     * job performs the bounded attempts.
     *
     * @param  list<int>  $personnelIds
     */
    public function queueBatch(array $personnelIds): int
    {
        $queued = 0;

        Personnel::query()
            ->onboarded()
            ->whereIn('id', array_map('intval', $personnelIds))
            ->orderBy('id')
            ->chunkById(50, function ($people) use (&$queued): void {
                foreach ($people as $person) {
                    $queued += count($this->queueAssignments($person)['terminals']);
                }
            });

        return $queued;
    }

    public function syncMany(array $personnelIds, ?User $actor = null): array
    {
        $queued = $this->queueBatch($personnelIds);

        return ['queued' => $queued];
    }

    /** Full deletion: every controller must confirm removal before the row goes. */
    public function deleteUser(Personnel $personnel, ?User $actor = null, ?string $ipAddress = null): void
    {
        $name = $personnel->name;
        $employeeNo = $personnel->employee_no;

        $this->audit->log('user_deleted', 'info',
            'Deletion requested for '.$name.'; every controller must confirm removal.',
            ['employee_no' => $employeeNo], $actor, $personnel, ipAddress: $ipAddress);

        try {
            $this->credentials->remove($personnel);
        } catch (\Throwable $error) {
            $this->audit->log('user_deleted', 'failure',
                'Deletion of '.$name.' was not completed: '.$error->getMessage(),
                ['employee_no' => $employeeNo], $actor, $personnel, ipAddress: $ipAddress);

            throw $error;
        }

        $this->deletePhoto($personnel);

        $this->audit->log('user_deleted', 'success',
            'Deletion of '.$name.' confirmed on all assigned controllers and the local record was removed.',
            ['employee_no' => $employeeNo], $actor, null, ipAddress: $ipAddress, subjectLabel: $name);
    }

    /** Discard a setup that never reached the final step. */
    public function discardDraft(Personnel $personnel, ?User $actor = null, ?string $ipAddress = null): void
    {
        if (! $personnel->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Only an unfinished draft can be discarded here.']);
        }

        try {
            $terminalIds = $personnel->fingerprintEnrollments()->whereNotNull('terminal_id')->pluck('terminal_id')
                ->merge($personnel->terminals()->pluck('terminals.id'))
                ->unique();

            foreach (Terminal::query()->whereIn('id', $terminalIds)->get() as $terminal) {
                $this->provisioner->removeIdentity($terminal, $personnel->employee_no);
            }

            // Deletes the local row once every remaining pivot is confirmed.
            $this->credentials->remove($personnel);
        } catch (\Throwable $error) {
            $this->audit->log('draft_discarded', 'failure',
                'Draft '.$personnel->name.' could not be discarded: '.$error->getMessage(),
                [], $actor, $personnel, ipAddress: $ipAddress);

            throw $error;
        }

        $this->deletePhoto($personnel);

        $this->audit->log('draft_discarded', 'success',
            'Draft onboarding for '.$personnel->name.' was discarded.',
            [], $actor, null, ipAddress: $ipAddress, subjectLabel: $personnel->name);
    }

    /**
     * Per-controller state for a profile or the post-onboarding verification
     * screen. This separates "granted" from "confirmed by the controller".
     *
     * @return list<array{terminal: Terminal, doors: Collection, state: string, label: string, tone: string, message: ?string}>
     */
    public function controllerStates(Personnel $personnel): array
    {
        $personnel->loadMissing(['terminals', 'pendingSyncs']);
        $effective = $personnel->effectiveDoors()->groupBy('terminal_id');

        return Terminal::apiManaged()->orderBy('phase')->orderBy('name')->get()->map(function (Terminal $terminal) use ($personnel, $effective): array {
            $pivot = $personnel->terminals->firstWhere('id', $terminal->id);
            $doors = $effective->get($terminal->id, collect());
            $pending = $personnel->pendingSyncs->firstWhere('terminal_id', $terminal->id);

            if ($pivot === null && $doors->isEmpty()) {
                return ['terminal' => $terminal, 'doors' => $doors, 'state' => 'unassigned',
                    'label' => 'No access configured', 'tone' => 'muted', 'message' => null];
            }

            if ($pivot !== null && ! (bool) $pivot->pivot->access_allowed) {
                $confirmed = $pivot->pivot->sync_status === 'confirmed';

                return ['terminal' => $terminal, 'doors' => $doors, 'state' => $confirmed ? 'revoked' : 'revoking',
                    'label' => $confirmed ? 'Access revoked' : 'Revocation pending',
                    'tone' => $confirmed ? 'muted' : 'warning',
                    'message' => $pending?->last_error];
            }

            if ($pivot !== null && $pivot->pivot->sync_status === 'confirmed') {
                return ['terminal' => $terminal, 'doors' => $doors, 'state' => 'confirmed',
                    'label' => 'Confirmed by controller', 'tone' => 'success', 'message' => null];
            }

            // Assignments that predate door-level permissions were never
            // written by this application. They are configured, but nobody has
            // confirmed that the controller currently holds them.
            if ($pivot !== null && in_array((string) $pivot->pivot->sync_status, ['unverified', 'imported'], true)) {
                return ['terminal' => $terminal, 'doors' => $doors, 'state' => 'unverified',
                    'label' => 'Existing access · not confirmed', 'tone' => 'warning', 'message' => null];
            }

            return ['terminal' => $terminal, 'doors' => $doors, 'state' => 'pending',
                'label' => 'Pending synchronization', 'tone' => 'warning',
                'message' => $pending?->last_error];
        })->values()->all();
    }

    /**
     * @param  list<int>|null  $onlyTerminalIds
     * @return array{terminals: list<Terminal>, synced: int, pending: int}
     */
    private function apply(Personnel $personnel, ?User $actor, ?array $onlyTerminalIds = null): array
    {
        $queue = $this->queueAssignments($personnel, $onlyTerminalIds);

        if ($queue['terminals'] === []) {
            return ['terminals' => [], 'synced' => 0, 'pending' => 0];
        }

        $result = $this->credentials->syncNow($personnel->refresh(), $actor);

        return ['terminals' => $queue['terminals'], ...$result];
    }

    /**
     * Write the desired per-controller assignment and queue it, without
     * contacting the device yet.
     *
     * @param  list<int>|null  $onlyTerminalIds
     * @return array{terminals: list<Terminal>}
     */
    private function queueAssignments(Personnel $personnel, ?array $onlyTerminalIds = null): array
    {
        if ($personnel->isDraft()) {
            return ['terminals' => []];
        }

        $effective = $personnel->effectiveDoors()->groupBy('terminal_id');
        $eligible = ! $personnel->isSuspended()
            && $personnel->valid_enabled
            && ! $personnel->isBlocklisted();

        $targets = [];

        foreach (Terminal::apiManaged()->orderBy('id')->get() as $terminal) {
            if ($onlyTerminalIds !== null && ! in_array($terminal->id, $onlyTerminalIds, true)) {
                continue;
            }

            $hasDoors = $effective->has($terminal->id);
            $pivot = $personnel->terminals()->whereKey($terminal->id)->first();

            // Never create an assignment for a controller this person has no
            // business on; stale assignments from an earlier edit are revoked.
            if (! $hasDoors && $pivot === null) {
                continue;
            }

            $desired = $hasDoors && $eligible;

            if ($pivot === null) {
                $personnel->terminals()->attach($terminal->id, [
                    'access_allowed' => $desired,
                    'sync_status' => 'pending',
                ]);
            } elseif ((bool) $pivot->pivot->access_allowed !== $desired) {
                $personnel->terminals()->updateExistingPivot($terminal->id, ['access_allowed' => $desired]);
            }

            $this->credentials->queue(
                $personnel,
                $terminal,
                $desired ? 'Waiting for controller confirmation.' : 'Waiting for revocation confirmation.',
            );

            $targets[] = $terminal;
        }

        return ['terminals' => $targets];
    }

    private function assertCanHoldAccess(Personnel $personnel): void
    {
        if ($personnel->isDraft()) {
            throw ValidationException::withMessages(['doors' => 'Complete onboarding before granting door access.']);
        }

        if ($personnel->isSuspended()) {
            throw ValidationException::withMessages(['doors' => 'Reactivate this person before granting more doors.']);
        }

        if ($personnel->isBlocklisted()) {
            throw ValidationException::withMessages(['doors' => 'A blocklisted person cannot be granted door access.']);
        }
    }

    private function deletePhoto(Personnel $personnel): void
    {
        if (blank($personnel->photo_path)) {
            return;
        }

        Storage::disk((string) config('access.photos.disk', 'local'))->delete($personnel->photo_path);
    }
}
