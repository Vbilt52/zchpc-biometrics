<?php

namespace App\Services;

use App\Models\FingerprintEnrollment;
use App\Models\FingerprintSync;
use App\Models\Personnel;
use App\Models\Terminal;
use Illuminate\Support\Facades\Cache;
use Shaykhnazar\HikvisionIsapi\Client\HikvisionClient;

class FingerprintSyncService
{
    public function __construct(private readonly DeviceClientFactory $clients, private readonly AccessProvisioner $profiles) {}

    /** Source identity must already be reviewed and linked to this person. */
    public function queue(Personnel $person, Terminal $source, string $sourceNumber, Terminal $target): FingerprintSync
    {
        if ($source->id === $target->id || PersonnelDeviceIdentity::resolve($source, $sourceNumber)?->id !== $person->id) {
            throw new \RuntimeException('Select a confirmed source identity on another terminal.');
        }
        if (! $source->supports('fingerprint_enrollment') || ! $target->supports('fingerprint_enrollment')) {
            throw new \RuntimeException('Both terminals must support fingerprint enrollment.');
        }

        return FingerprintSync::updateOrCreate(['personnel_id' => $person->id, 'terminal_id' => $target->id], [
            'source_terminal_id' => $source->id, 'source_employee_no' => $sourceNumber,
            'target_employee_no' => $person->employee_no, 'status' => 'pending', 'attempts' => 0,
            'message' => 'Waiting for profile and fingerprint confirmation.', 'confirmed_slots' => null,
            'confirmed_at' => null, 'next_attempt_at' => now(),
        ]);
    }

    public function processDue(int $limit = 20): array
    {
        $counts = ['confirmed' => 0, 'pending' => 0, 'cancelled' => 0];
        $deadline = microtime(true) + 45;
        $tasks = FingerprintSync::where('status', 'pending')->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')->limit(max(1, min(100, $limit)))->get();
        foreach ($tasks as $task) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $lock = Cache::lock('person-access:'.$task->personnel_id, 600);
            if (! $lock->get()) {
                continue;
            }
            try {
                $task->refresh();
                if ($task->status === 'pending') {
                    $this->process($task);
                    $counts[$task->fresh()->status]++;
                }
            } finally {
                $lock->release();
            }
        }

        return $counts;
    }

    /** Caller holds the personnel lock shared with permission edits and removal. */
    private function process(FingerprintSync $task): void
    {
        $person = $task->personnel;
        $target = $task->terminal;
        $source = $task->sourceTerminal;
        if (! $person || ! $person->isActive() || ! $target || ! $source
            || ! $person->terminals()->whereKey($target->id)->wherePivot('access_allowed', true)->exists()
            || ! $person->effectiveDoors()->contains('terminal_id', $target->id)) {
            $task->update(['status' => 'cancelled', 'message' => 'Access is no longer authorized. No fingerprint was copied.', 'next_attempt_at' => null]);

            return;
        }
        try {
            PersonnelDeviceIdentity::assertWritable($source, $task->source_employee_no);
            PersonnelDeviceIdentity::assertWritable($target, $task->target_employee_no);
            if (PersonnelDeviceIdentity::resolve($source, $task->source_employee_no)?->id !== $person->id
                || PersonnelDeviceIdentity::resolve($target, $task->target_employee_no)?->id !== $person->id) {
                throw new \DomainException('The source or destination identity needs review.');
            }
            if (! $source->isOnline() || ! $target->isOnline()) {
                throw new \DomainException('Waiting for both terminals to be online.');
            }
            if (FingerprintEnrollment::whereIn('terminal_id', [$source->id, $target->id])->active()->exists()) {
                throw new \DomainException('A terminal is capturing a fingerprint. Synchronization will retry.');
            }
            $assignment = $person->terminals()->whereKey($target->id)->first();
            if ($assignment->pivot->sync_status !== 'confirmed') {
                throw new \DomainException('Waiting for the destination profile to be confirmed.');
            }
            $sourceClient = $this->clients->forTerminal($source, 8);
            $targetClient = $this->clients->forTerminal($target, 8);
            $sourceProfile = $this->profiles->readPerson($sourceClient, $task->source_employee_no);
            $targetProfile = $this->profiles->readPerson($targetClient, $task->target_employee_no);
            if (($sourceProfile['name'] ?? null) !== $person->name || ($targetProfile['name'] ?? null) !== $person->name
                || ! in_array($targetProfile['userType'] ?? '', ['normal', 'visitor'], true)) {
                throw new \DomainException('Profile identity or destination access has changed. Review before copying fingerprints.');
            }
            $templates = $this->templates($sourceClient, $task->source_employee_no);
            if ($templates === []) {
                throw new \DomainException('No exportable fingerprint is enrolled on the source identity.');
            }
            $existing = $this->templates($targetClient, $task->target_employee_no);
            $confirmedSlots = [];
            foreach ($templates as $slot => $data) {
                $person->refresh();
                if (! $person->isActive() || ! $person->effectiveDoors()->contains('terminal_id', $target->id)
                    || ! $person->terminals()->whereKey($target->id)->wherePivot('access_allowed', true)->exists()) {
                    $task->update(['status' => 'cancelled', 'message' => 'Access changed during synchronization. Further copies were cancelled.', 'next_attempt_at' => null]);

                    return;
                }
                PersonnelDeviceIdentity::assertWritable($target, $task->target_employee_no);
                $matchingSlot = null;
                foreach ($existing as $storedSlot => $storedData) {
                    if (hash_equals($storedData, $data)) {
                        $matchingSlot = $storedSlot;
                        break;
                    }
                }
                if ($matchingSlot !== null) {
                    $confirmedSlots[] = $matchingSlot;

                    continue;
                }
                // Keep existing destination fingers when slot numbering differs.
                $targetSlot = isset($existing[$slot]) ? (array_values(array_diff(range(1, 10), array_keys($existing)))[0] ?? null) : $slot;
                if ($targetSlot === null) {
                    throw new \DomainException('All destination fingerprint slots are occupied. Existing fingerprints were retained.');
                }
                $response = $targetClient->post('/ISAPI/AccessControl/FingerPrint/SetUp', ['FingerPrintCfg' => [
                    'employeeNo' => $task->target_employee_no, 'enableCardReader' => [1],
                    // Copy the biometric for ordinary door access. Do not
                    // propagate alarm-dismissal, duress or super-user rights.
                    'fingerPrintID' => $targetSlot, 'fingerType' => 'normalFP', 'fingerData' => $data,
                ]]);
                $statuses = $response['FingerPrintStatus']['StatusList'] ?? [];
                foreach ($statuses as $status) {
                    if (($status['cardReaderRecvStatus'] ?? null) != 1) {
                        throw new \DomainException(match ((int) ($status['cardReaderRecvStatus'] ?? -1)) {
                            2 => 'The destination fingerprint reader is offline. Synchronization will retry.',
                            4 => 'The destination fingerprint memory is full. Review its stored enrollments.',
                            5 => 'This fingerprint already belongs to a device identity. Review the existing enrollment; no identity was removed.',
                            default => 'The destination rejected the fingerprint. Existing identities and fingerprints were retained.',
                        });
                    }
                }
                $readback = $this->templates($targetClient, $task->target_employee_no);
                if (! isset($readback[$targetSlot]) || ! hash_equals($data, $readback[$targetSlot])) {
                    throw new \DomainException('Destination fingerprint storage has not been confirmed.');
                }
                foreach ($existing as $storedSlot => $storedData) {
                    if (! isset($readback[$storedSlot]) || ! hash_equals($storedData, $readback[$storedSlot])) {
                        throw new \DomainException('An existing destination fingerprint changed during synchronization. Review the terminal.');
                    }
                }
                $existing = $readback;
                $confirmedSlots[] = $targetSlot;
            }
            $slots = array_values(array_unique($confirmedSlots));
            $task->update(['status' => 'confirmed', 'confirmed_slots' => $slots, 'confirmed_at' => now(),
                'next_attempt_at' => null, 'message' => count($slots).' fingerprint slot(s) verified on the destination.']);
            app(AccessAuditLogger::class)->log('fingerprints_synchronized', 'success',
                'Existing fingerprints copied and read back on the authorized destination.',
                ['source_terminal_id' => $source->id, 'slots' => $slots], personnel: $person, terminal: $target);
        } catch (\Throwable $error) {
            $attempts = min(65535, $task->attempts + 1);
            // Transport exceptions can include response bodies. Never persist them.
            $message = $error instanceof \DomainException ? $error->getMessage()
                : 'The device could not confirm fingerprint synchronization. Check connectivity, authentication and identity holds.';
            $task->update(['attempts' => $attempts, 'message' => $message,
                'next_attempt_at' => now()->addMinutes(min(30, 2 ** min(5, $attempts - 1)))]);
        }
    }

    /** Sensitive template material is held only in this worker's memory. */
    private function templates(HikvisionClient $client, string $number): array
    {
        try {
            $response = $client->post('/ISAPI/AccessControl/FingerPrintUpload', ['FingerPrintCond' => [
                'searchID' => bin2hex(random_bytes(8)), 'employeeNo' => $number, 'cardReaderNo' => 1,
            ]]);
        } catch (\Throwable $error) {
            $message = strtolower($error->getMessage());
            if (str_contains($message, 'fingerprintnotlinkemployeeidorcard') || str_contains($message, 'nofp') || str_contains($message, 'nofingerprint')) {
                return [];
            }
            throw new \DomainException('The terminal could not provide fingerprint data. Check connectivity and API access.');
        }
        $info = $response['FingerPrintInfo'] ?? [];
        if (($info['status'] ?? null) === 'NoFP' && empty($info['FingerPrintList'])) {
            return [];
        }
        $list = $info['FingerPrintList'] ?? null;
        if (! is_array($list)) {
            throw new \DomainException('This terminal did not return a supported fingerprint export.');
        }
        $templates = [];
        foreach (array_is_list($list) ? $list : [$list] as $item) {
            $slot = (int) ($item['fingerPrintID'] ?? 0);
            $data = $item['fingerData'] ?? null;
            if ($slot < 1 || $slot > 10 || ! is_string($data) || $data === '' || strlen($data) > 65536 || base64_decode($data, true) === false
                || ! in_array($item['fingerType'] ?? 'normalFP', ['normalFP', 'hijackFP', 'patrolFP', 'superFP', 'dismissingFP'], true)
                || isset($templates[$slot])) {
                throw new \DomainException('The terminal returned an unsupported fingerprint template.');
            }
            $templates[$slot] = $data;
        }

        return $templates;
    }
}
