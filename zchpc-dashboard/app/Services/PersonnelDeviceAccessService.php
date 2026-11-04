<?php

namespace App\Services;

use App\Models\DeviceSyncEvent;
use App\Models\PendingTerminalSync;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Models\User;
use Carbon\Carbon;
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
            foreach ($person->terminals()->get() as $terminal) {
                if ($terminal->supports('personnel_sync')) {
                    $this->queue($person, $terminal, 'Waiting for device confirmation.');
                }
            }

            return $this->attemptPerson($person);
        });
    }

    /** Keep the local identity and retry state until physical removal is verified. */
    public function remove(Personnel $person): void
    {
        Cache::lock('person-access:'.$person->id, 600)->block(3, function () use ($person): void {
            foreach ($person->terminals()->get() as $terminal) {
                if (! $terminal->supports('personnel_sync')) {
                    throw new \RuntimeException('Remove this person from the unmanaged device before deleting their local record.');
                }
                $client = $this->deviceManager->device($terminal->name);
                if ($this->readPerson($client, $person->employee_no) !== null) {
                    $client->put('/ISAPI/AccessControl/UserInfo/Delete', ['UserInfoDelCond' => [
                        'EmployeeNoList' => [['employeeNo' => $person->employee_no]],
                    ]]);
                    if ($this->readPerson($client, $person->employee_no) !== null) {
                        throw new \RuntimeException('Device removal has not been confirmed on '.$terminal->name.'.');
                    }
                }
                // Prevent old pending upserts from recreating a profile already removed.
                $person->terminals()->updateExistingPivot($terminal->id, ['access_allowed' => false, 'sync_status' => 'confirmed', 'synced_at' => now()]);
                PendingTerminalSync::where('personnel_id', $person->id)->where('terminal_id', $terminal->id)->delete();
            }
            $person->delete();
        });
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
            ['operation' => $assignment->pivot->access_allowed ? 'upsert' : 'revoke', 'attempts' => 0,
                'last_error' => $error, 'last_attempt_at' => now(), 'next_attempt_at' => now()->addMinute()],
        );
    }

    public function processDue(int $limit = 100): array
    {
        $result = ['synced' => 0, 'pending' => 0];
        $ids = PendingTerminalSync::where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit($limit)->pluck('personnel_id')->unique();
        foreach ($ids as $id) {
            $lock = Cache::lock('person-access:'.$id, 600);
            if (! $lock->get()) {
                continue;
            }
            try {
                if ($person = Personnel::find($id)) {
                    $counts = $this->attemptPerson($person, true);
                    $result['synced'] += $counts['synced'];
                    $result['pending'] += $counts['pending'];
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

    private function attemptPerson(Personnel $person, bool $dueOnly = false, ?User $actor = null): array
    {
        $result = ['synced' => 0, 'pending' => 0];
        $pending = PendingTerminalSync::with('terminal')->where('personnel_id', $person->id)
            ->when($dueOnly, fn ($query) => $query->where('next_attempt_at', '<=', now()))->get();
        foreach ($pending as $sync) {
            $operation = $sync->operation === 'revoke' ? 'revoke' : 'grant';
            try {
                if (! $sync->terminal) {
                    throw new \RuntimeException('Terminal no longer exists.');
                }
                $this->writeCurrentAssignment($person, $sync->terminal);
                $person->terminals()->updateExistingPivot($sync->terminal_id, ['sync_status' => 'confirmed', 'synced_at' => now()]);
                $sync->delete();
                $this->syncLogger()->record($person, $sync->terminal, $operation, DeviceSyncEvent::STATUS_SUCCESS,
                    'Controller confirmed the requested access.', $actor);
                $result['synced']++;
            } catch (\Throwable $error) {
                $attempts = $sync->attempts + 1;
                $sync->update(['attempts' => $attempts, 'last_error' => $error->getMessage(), 'last_attempt_at' => now(),
                    'next_attempt_at' => now()->addMinutes(min(30, 2 ** min(5, $attempts - 1)))]);
                $person->terminals()->updateExistingPivot($sync->terminal_id, ['sync_status' => 'pending']);
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
