<?php

namespace App\Services;

use App\Models\DeviceSyncEvent;
use App\Models\PendingTerminalSync;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;
use Shaykhnazar\HikvisionIsapi\Client\HikvisionClient;

class PersonnelDeviceAccessService
{
    /**
     * The logger is resolved lazily so existing callers and tests that build
     * this service with only a DeviceManager keep working.
     */
    public function __construct(
        private readonly DeviceManager $deviceManager,
        private ?DeviceSyncLogger $syncLogger = null,
    ) {}

    /** Save the desired permissions before contacting hardware, so failures are durable. */
    public function save(Personnel $person, array $attributes, array $selected): array
    {
        return Cache::lock('person-access:'.$person->id, 600)->block(3, function () use ($person, $attributes, $selected): array {
            $person->refresh();
            $this->assertNotRemoved($person);
            if (isset($attributes['employee_no']) && $attributes['employee_no'] !== $person->employee_no && $person->terminals()->exists()) {
                throw ValidationException::withMessages(['employee_no' => 'Employee ID is linked to device fingerprints and history. Keep this ID; edit the name, dates and device access below.']);
            }
            DB::transaction(function () use ($person, $attributes, $selected): void {
                $person->update($attributes);
                foreach (Terminal::apiManaged()->get() as $terminal) {
                    $allowed = in_array($terminal->id, array_map('intval', $selected), true);
                    $person->terminals()->syncWithoutDetaching([$terminal->id => ['access_allowed' => $allowed, 'sync_status' => 'pending']]);
                    $this->queue($person, $terminal, 'Waiting for device confirmation.');
                }
            });

            return $this->attemptPerson($person);
        });
    }

    /** Retry existing assignments only; this must never grant access to another device. */
    public function retry(Personnel $person): array
    {
        return Cache::lock('person-access:'.$person->id, 600)->block(3, function () use ($person): array {
            $person->refresh();
            $this->assertNotRemoved($person);
            foreach ($person->terminals()->get() as $terminal) {
                if ($terminal->supports('personnel_sync')) {
                    $this->queue($person, $terminal, 'Waiting for device confirmation.');
                }
            }

            return $this->attemptPerson($person);
        });
    }

    /** Archive locally first; keep identity/history and durable controller removals. */
    public function remove(Personnel $person): array
    {
        return Cache::lock('person-access:'.$person->id, 600)->block(3, fn (): array => $this->archiveForRemoval($person));
    }

    /**
     * Lock the entire selection, then archive and audit it atomically without device calls.
     *
     * Everything is written as a small fixed number of statements regardless of
     * how many people are selected: a 100-person removal must not fan out to a
     * dozen queries per identity.
     */
    public function removeMany(array $ids, ?User $actor = null, ?string $ipAddress = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === [] || count($ids) > 100) {
            throw ValidationException::withMessages(['ids' => 'Select between 1 and 100 users.']);
        }
        sort($ids);
        $locks = [];

        try {
            foreach ($ids as $id) {
                $lock = Cache::lock('person-access:'.$id, 600);
                if (! $lock->get()) {
                    throw ValidationException::withMessages(['ids' => 'A selected user is being synchronized or edited. Nothing was deleted. Please retry.']);
                }
                $locks[] = $lock;
            }

            return DB::transaction(function () use ($ids, $actor, $ipAddress): array {
                // One read per relation for the whole selection instead of four per person.
                $people = Personnel::with(['terminals', 'fingerprintEnrollments', 'doors', 'pendingSyncs'])
                    ->whereIn('id', $ids)->orderBy('id')->get();
                if ($people->count() !== count($ids)) {
                    throw ValidationException::withMessages(['ids' => 'The selection has changed. Refresh the list and select users again.']);
                }

                $targets = [];
                $terminalIds = [];
                foreach ($people as $person) {
                    $this->assertNotRemoved($person);
                    $targets[$person->id] = $this->removalTargetIds($person);
                    $terminalIds = array_merge($terminalIds, $targets[$person->id]);
                }
                // Verify every controller before writing, so an unmanaged reader
                // rejects the selection instead of a partially applied removal.
                $this->removalTerminals($terminalIds);

                $pairs = [];
                foreach ($targets as $personId => $personTerminals) {
                    foreach ($personTerminals as $terminalId) {
                        $pairs[] = ['personnel_id' => $personId, 'terminal_id' => $terminalId];
                    }
                }

                $now = now();
                Personnel::whereIn('id', $ids)->update([
                    'valid_enabled' => false, 'status' => Personnel::STATUS_SUSPENDED, 'deleted_at' => $now, 'updated_at' => $now,
                ]);

                if ($pairs !== []) {
                    DB::table('personnel_terminals')->upsert(array_map(fn (array $pair): array => $pair + [
                        'access_allowed' => false, 'sync_status' => 'pending', 'synced_at' => null,
                        'created_at' => $now, 'updated_at' => $now,
                    ], $pairs), ['personnel_id', 'terminal_id'], ['access_allowed', 'sync_status', 'updated_at']);

                    DB::table('pending_terminal_syncs')->upsert(array_map(fn (array $pair): array => $pair + [
                        'operation' => 'delete', 'attempts' => 0, 'last_error' => 'Removal awaiting device confirmation.',
                        'last_attempt_at' => $now, 'next_attempt_at' => $now->copy()->addMinute(),
                        'created_at' => $now, 'updated_at' => $now,
                    ], $pairs), ['terminal_id', 'personnel_id'],
                        ['operation', 'attempts', 'last_error', 'last_attempt_at', 'next_attempt_at', 'updated_at']);
                }

                app(AccessAuditLogger::class)->logBulkPersonnelRemoval(
                    $people, count($ids), array_map('count', $targets), $actor, $ipAddress);

                return ['removed' => $people->count(), 'pending' => count($pairs)];
            });
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }
    }

    /** Caller holds the person's access lock. */
    private function archiveForRemoval(Personnel $person): array
    {
        $person->refresh();
        $this->assertNotRemoved($person);
        $terminals = $this->removalTerminals($this->removalTargetIds($person));
        DB::transaction(function () use ($person, $terminals): void {
            $person->update(['valid_enabled' => false, 'status' => Personnel::STATUS_SUSPENDED]);
            $person->delete();
            foreach ($terminals as $terminal) {
                $person->terminals()->syncWithoutDetaching([$terminal->id => ['access_allowed' => false, 'sync_status' => 'pending']]);
                $this->queue($person, $terminal, 'Removal awaiting device confirmation.');
            }
        });

        // Hardware is handled by the scheduler so an offline device cannot hold the request open.
        return ['synced' => 0, 'pending' => $terminals->count()];
    }

    /** Terminal IDs whose controller must be told about this person's removal. */
    private function removalTargetIds(Personnel $person): array
    {
        $person->loadMissing(['terminals', 'fingerprintEnrollments', 'doors', 'pendingSyncs']);

        return $person->terminals->pluck('id')
            ->merge($person->fingerprintEnrollments->pluck('terminal_id'))
            ->merge($person->doors->pluck('terminal_id'))
            ->merge($person->pendingSyncs->pluck('terminal_id'))
            ->filter()->unique()->values()->all();
    }

    /** @param  array<int, int>  $terminalIds */
    private function removalTerminals(array $terminalIds): Collection
    {
        $terminals = Terminal::whereIn('id', array_values(array_unique($terminalIds)))->get();
        foreach ($terminals as $terminal) {
            if (! $terminal->supports('personnel_sync')) {
                throw new \RuntimeException('Remove this person from the unmanaged device before deleting their local record.');
            }
        }

        return $terminals;
    }

    public function queue(Personnel $personnel, Terminal $terminal, string $error): void
    {
        if (! $terminal->supports('personnel_sync')) {
            return;
        }
        // Only the assignment editor grants access. A retry never creates an assignment.
        $assignment = $personnel->terminals()->whereKey($terminal->id)->first();
        if (! $assignment) {
            throw new \RuntimeException('Assign this device through Edit Person before synchronizing.');
        }
        $personnel->terminals()->updateExistingPivot($terminal->id, ['sync_status' => 'pending']);
        PendingTerminalSync::updateOrCreate(
            ['terminal_id' => $terminal->id, 'personnel_id' => $personnel->id],
            ['operation' => $personnel->trashed() ? 'delete' : ($assignment->pivot->access_allowed ? 'upsert' : 'revoke'), 'attempts' => 0,
                'last_error' => $error, 'last_attempt_at' => now(), 'next_attempt_at' => now()->addMinute()],
        );
    }

    public function processDue(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $deadline = microtime(true) + 45;
        $result = ['synced' => 0, 'pending' => 0];
        $ids = PendingTerminalSync::where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit($limit)->pluck('personnel_id')->unique();
        foreach ($ids as $id) {
            if (microtime(true) >= $deadline || $limit <= 0) {
                break;
            }
            $lock = Cache::lock('person-access:'.$id, 600);
            if (! $lock->get()) {
                continue;
            }
            try {
                if ($person = Personnel::withTrashed()->find($id)) {
                    $counts = $this->attemptPerson($person, true, null, $limit);
                    $result['synced'] += $counts['synced'];
                    $result['pending'] += $counts['pending'];
                    $limit -= $counts['synced'] + $counts['pending'];
                }
            } finally {
                $lock->release();
            }
        }

        return $result;
    }

    /**
     * Attempt every queued controller for one person immediately.
     *
     * Access-module actions queue the desired state first, then call this so the
     * administrator sees per-controller confirmation in the same request.
     *
     * @return array{synced: int, pending: int}
     */
    public function syncNow(Personnel $person, ?User $actor = null): array
    {
        return Cache::lock('person-access:'.$person->id, 600)->block(3, function () use ($person, $actor): array {
            $person->refresh();

            return $this->attemptPerson($person, false, $actor);
        });
    }

    private function attemptPerson(Personnel $person, bool $dueOnly = false, ?User $actor = null, ?int $limit = null): array
    {
        $result = ['synced' => 0, 'pending' => 0];
        $pending = PendingTerminalSync::with('terminal')->where('personnel_id', $person->id)
            ->when($dueOnly, fn ($query) => $query->where('next_attempt_at', '<=', now()))
            ->orderBy('next_attempt_at')->when($limit !== null, fn ($query) => $query->limit($limit))->get();
        foreach ($pending as $sync) {
            $operation = $person->trashed() ? 'delete' : ($sync->operation === 'revoke' ? 'revoke' : ($sync->operation === 'profile' ? 'profile' : 'grant'));
            try {
                if (! $sync->terminal) {
                    throw new \RuntimeException('Terminal no longer exists.');
                }
                if ($person->trashed()) {
                    $this->removeFromDevice($person, $sync->terminal);
                } elseif ($sync->operation === 'delete') {
                    throw new \RuntimeException('Removal requires an archived local identity.');
                } elseif ($sync->operation === 'profile') {
                    $this->writeName($person, $sync->terminal);
                } else {
                    $this->writeCurrentAssignment($person, $sync->terminal);
                }
                if ($operation !== 'profile') {
                    $person->terminals()->updateExistingPivot($sync->terminal_id, ['sync_status' => 'confirmed', 'synced_at' => now()]);
                }
                $sync->delete();
                $this->syncLogger()->record($person, $sync->terminal, $operation, DeviceSyncEvent::STATUS_SUCCESS,
                    'Controller confirmed the requested access.', $actor);
                $result['synced']++;
            } catch (\Throwable $error) {
                $attempts = $sync->attempts + 1;
                $sync->update(['attempts' => min(65535, $attempts), 'last_error' => $error->getMessage(), 'last_attempt_at' => now(),
                    'next_attempt_at' => now()->addMinutes(min(30, 2 ** min(5, $attempts - 1)))]);
                if ($operation !== 'profile') {
                    $person->terminals()->updateExistingPivot($sync->terminal_id, ['sync_status' => 'pending']);
                }
                $this->syncLogger()->record($person, $sync->terminal, $operation, DeviceSyncEvent::STATUS_FAILED,
                    $error->getMessage(), $actor);
                $result['pending']++;
            }
        }

        return $result;
    }

    /** Write and read back the current assignment, never a stale queued payload. */
    public function upsert(Personnel $personnel, Terminal $terminal): void
    {
        Cache::lock('person-access:'.$personnel->id, 600)->block(3, function () use ($personnel, $terminal): void {
            $personnel->refresh();
            $this->writeCurrentAssignment($personnel, $terminal);
        });
    }

    private function writeCurrentAssignment(Personnel $personnel, Terminal $terminal): void
    {
        $this->assertNotRemoved($personnel);
        if (! $terminal->supports('personnel_sync')) {
            throw new \RuntimeException('This terminal does not support personnel synchronization.');
        }
        $assignment = $personnel->terminals()->whereKey($terminal->id)->first();
        if (! $assignment) {
            throw new \RuntimeException('No device assignment exists.');
        }
        $client = $this->deviceManager->device($terminal->name);
        $existing = $this->readPerson($client, $personnel->employee_no);
        $revoked = ! $assignment->pivot->access_allowed;
        $suspended = $personnel->status === Personnel::STATUS_SUSPENDED;
        $deny = $revoked || $suspended || ! $personnel->valid_enabled || $personnel->user_type === 'blocklist';
        if ($deny && $existing === null) {
            return; // Verified absence is sufficient to confirm revoked access.
        }
        if (! $revoked && (! $personnel->begin_time || ! $personnel->end_time)) {
            throw new \RuntimeException('Set an access start and expiry date in Edit Person before synchronizing this profile.');
        }
        $zone = config('attendance.device_timezone');

        // Door grants are the authority once the person has any. People who
        // have never used the access module keep their legacy channel fields.
        $doorRight = $personnel->doorRightFor($terminal);
        $rightPlan = $personnel->rightPlanFor($terminal);

        if (! $revoked && $rightPlan === []) {
            throw new \RuntimeException('No door access is granted on '.$terminal->name.'; the controller was not changed.');
        }

        $beginCarbon = $personnel->begin_time?->copy()->timezone($zone);
        // Build bounds explicitly in the device timezone to avoid UTC parse ambiguity.
        $minBegin = Carbon::create(2000, 1, 1, 0, 0, 0, $zone);
        if ($beginCarbon && $beginCarbon->lessThan($minBegin)) {
            $beginCarbon = $minBegin;
        }

        $endCarbon = $personnel->end_time?->copy()->timezone($zone);
        // Hikvision devices reject dates beyond 2037-12-31 in device-local time.
        // Clamp AFTER timezone conversion: a UTC end of 2037-12-31T23:59:59 becomes
        // 2038-01-01T01:59:59 in Africa/Harare (UTC+2) and would cause timeFormatError.
        $maxEnd = Carbon::create(2037, 12, 31, 23, 59, 59, $zone);
        if ($endCarbon && $endCarbon->greaterThan($maxEnd)) {
            $endCarbon = $maxEnd;
        }

        $payload = ['employeeNo' => $personnel->employee_no, 'name' => $personnel->name,
            'userType' => $deny ? 'blackList' : $personnel->user_type,
            'Valid' => ['enable' => true, 'timeType' => 'local',
                'beginTime' => $beginCarbon?->format('Y-m-d\TH:i:s'),
                'endTime' => $endCarbon?->format('Y-m-d\TH:i:s')],
            'doorRight' => $doorRight,
            'RightPlan' => $rightPlan,
            'phoneNumber' => $personnel->phone_number ?? '',
        ];
        // Revocation preserves templates; only the terminal's user type changes.
        if ($revoked) {
            $payload = ['employeeNo' => $personnel->employee_no, 'userType' => 'blackList'];
        }
        $response = $existing === null
            ? $client->post('/ISAPI/AccessControl/UserInfo/Record', ['UserInfo' => $payload])
            : $client->put('/ISAPI/AccessControl/UserInfo/Modify', ['UserInfo' => $payload]);
        $status = $response['ResponseStatus'] ?? $response;
        if (isset($status['statusCode']) && ! in_array((int) $status['statusCode'], [0, 1], true)) {
            throw new \RuntimeException('Device rejected the update: '.($status['subStatusCode'] ?? $status['statusString'] ?? 'unknown error'));
        }
        $actual = $this->readPerson($client, $personnel->employee_no);
        if ($actual === null || ($actual['userType'] ?? '') !== $payload['userType']) {
            throw new \RuntimeException('Device has not confirmed the requested access type.');
        }
        if ($revoked) {
            return;
        }
        $plans = static function (array $plans): array {
            $normalized = [];
            foreach ($plans as $plan) {
                $normalized[(int) $plan['doorNo']] = (string) $plan['planTemplateNo'];
            }
            ksort($normalized);

            return $normalized;
        };
        if ($plans($actual['RightPlan'] ?? []) !== $plans($payload['RightPlan'])) {
            throw new \RuntimeException('Device has not confirmed the updated door access plan.');
        }
        foreach (['name', 'doorRight', 'phoneNumber'] as $field) {
            if ((string) ($actual[$field] ?? '') !== (string) $payload[$field]) {
                throw new \RuntimeException('Device has not confirmed the updated '.$field.'.');
            }
        }
        if (! filter_var($actual['Valid']['enable'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw new \RuntimeException('Device has not enabled the requested access validity dates.');
        }
        foreach (['beginTime', 'endTime'] as $field) {
            if (empty($actual['Valid'][$field]) || empty($payload['Valid'][$field])
                || ! Carbon::parse($actual['Valid'][$field], ($actual['Valid']['timeType'] ?? 'local') === 'UTC' ? 'UTC' : $zone)
                    ->equalTo(Carbon::parse($payload['Valid'][$field], $zone))) {
                throw new \RuntimeException('Device has not confirmed the updated '.$field.'.');
            }
        }
    }

    private function syncLogger(): DeviceSyncLogger
    {
        return $this->syncLogger ??= app(DeviceSyncLogger::class);
    }

    private function assertNotRemoved(Personnel $person): void
    {
        if ($person->trashed()) {
            throw ValidationException::withMessages(['personnel' => 'This person has been removed. Device removal is retried automatically.']);
        }
    }

    private function removeFromDevice(Personnel $person, Terminal $terminal): void
    {
        $client = $this->deviceManager->device($terminal->name);
        if ($this->readPerson($client, $person->employee_no) === null) {
            return;
        }
        $client->put('/ISAPI/AccessControl/UserInfo/Delete', ['UserInfoDelCond' => [
            'EmployeeNoList' => [['employeeNo' => $person->employee_no]],
        ]]);
        if ($this->readPerson($client, $person->employee_no) !== null) {
            throw new \RuntimeException('Device removal has not been confirmed on '.$terminal->name.'.');
        }
    }

    private function writeName(Personnel $person, Terminal $terminal): void
    {
        $client = $this->deviceManager->device($terminal->name);
        if ($this->readPerson($client, $person->employee_no) === null) {
            return; // A name correction must never create device access.
        }
        $client->put('/ISAPI/AccessControl/UserInfo/Modify', ['UserInfo' => [
            'employeeNo' => $person->employee_no, 'name' => $person->name,
        ]]);
        $actual = $this->readPerson($client, $person->employee_no);
        if (($actual['name'] ?? null) !== $person->name) {
            throw new \RuntimeException('Device has not confirmed the updated name.');
        }
    }

    private function readPerson(HikvisionClient $client, string $employeeNo): ?array
    {
        $response = $client->post('/ISAPI/AccessControl/UserInfo/Search', ['UserInfoSearchCond' => [
            'searchID' => bin2hex(random_bytes(8)), 'searchResultPosition' => 0, 'maxResults' => 1,
            'EmployeeNoList' => [['employeeNo' => $employeeNo]],
        ]]);
        $result = $response['UserInfoSearch'] ?? null;
        if (! is_array($result) || ! in_array($result['responseStatusStrg'] ?? '', ['OK', 'NO MATCH'], true)) {
            throw new \RuntimeException('The terminal returned an unrecognized personnel search response.');
        }
        $records = $result['UserInfo'] ?? [];
        if (! is_array($records)) {
            throw new \RuntimeException('The terminal returned invalid personnel data.');
        }
        if ($records === []) {
            if ($result['responseStatusStrg'] !== 'NO MATCH' && (int) ($result['totalMatches'] ?? -1) !== 0) {
                throw new \RuntimeException('The terminal did not confirm whether the person exists.');
            }

            return null;
        }
        $record = array_is_list($records) ? ($records[0] ?? []) : $records;
        if ((string) ($record['employeeNo'] ?? '') !== $employeeNo) {
            throw new \RuntimeException('The terminal returned a different employee ID.');
        }

        return $record;
    }
}
