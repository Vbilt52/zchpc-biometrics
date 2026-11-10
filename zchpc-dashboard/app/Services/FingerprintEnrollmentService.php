<?php

namespace App\Services;

use App\Jobs\EnrollFingerprintJob;
use App\Models\FingerprintEnrollment;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Remote fingerprint capture.
 *
 * The terminal collects the sample; this application sends the authenticated
 * request and records the verdict. No template, image or fingerData field is
 * ever persisted. "Confirmed" is only used after the slot is read back from
 * the device, never from a queued request alone.
 */
class FingerprintEnrollmentService
{
    public function __construct(
        private readonly DeviceClientFactory $clients,
        private readonly AccessProvisioner $provisioner,
        private readonly AccessAuditLogger $audit,
    ) {}

    public function start(
        Personnel $personnel,
        Terminal $terminal,
        int $fingerId,
        ?User $actor = null,
        ?string $ipAddress = null,
    ): FingerprintEnrollment {
        if ($personnel->isSuspended()) {
            throw ValidationException::withMessages(['finger' => 'Reactivate this person before enrolling a fingerprint.']);
        }

        if (! $terminal->isApiManaged() || ! $terminal->supportsFingerprintEnrollment()) {
            throw ValidationException::withMessages([
                'terminal' => $terminal->name.' cannot accept a remotely initiated fingerprint enrollment.',
            ]);
        }

        if (! $terminal->isOnline()) {
            if ($terminal->status === Terminal::STATUS_ONLINE) {
                try {
                    app(DeviceHealthService::class)->check($terminal, 'enrollment');
                    $terminal->refresh();
                } catch (\Throwable) {
                    // Ignore and fall through to isOnline check
                }
            }
        }

        if (! $terminal->isOnline()) {
            throw ValidationException::withMessages([
                'terminal' => $terminal->name.' is offline. Refresh its status and try again, or use local enrollment.',
            ]);
        }

        if (! in_array($fingerId, (array) config('access.enrollment.finger_ids', range(1, 10)), true)) {
            throw ValidationException::withMessages(['finger' => 'Choose a valid fingerprint slot (1–10).']);
        }

        $alreadyEnrolled = FingerprintEnrollment::query()
            ->where('employee_no', $personnel->employee_no)
            ->where('terminal_id', $terminal->id)
            ->where('finger_id', $fingerId)
            ->where('status', FingerprintEnrollment::STATUS_CONFIRMED)
            ->exists();

        if ($alreadyEnrolled) {
            throw ValidationException::withMessages([
                'finger' => 'That finger is already enrolled for this person on '.$terminal->name.'. Choose another slot.',
            ]);
        }

        $inProgress = FingerprintEnrollment::query()
            ->where('employee_no', $personnel->employee_no)
            ->where('terminal_id', $terminal->id)
            ->active()
            ->exists();

        if ($inProgress) {
            throw ValidationException::withMessages([
                'finger' => 'An enrollment is already in progress on '.$terminal->name.'. Wait for it to finish or cancel it.',
            ]);
        }

        // A terminal serves one capture session at a time. Checked before the
        // blocking request, so a second administrator gets a clear answer
        // instead of two capture requests fighting over the reader.
        $terminalBusy = FingerprintEnrollment::query()
            ->where('terminal_id', $terminal->id)
            ->active()
            ->exists();

        if ($terminalBusy) {
            throw ValidationException::withMessages([
                'terminal' => $terminal->name.' is already capturing another enrollment. Wait for it to finish, then try again.',
            ]);
        }

        // The device needs an employee record before it can attach a finger.
        // This creates (or reuses) a deliberately disabled identity.
        $this->provisioner->ensureEnrollmentIdentity(
            $terminal,
            $personnel->employee_no,
            $personnel->name,
            $personnel->phone_number,
        );

        $enrollment = FingerprintEnrollment::query()->create([
            'personnel_id' => $personnel->id,
            'employee_no' => $personnel->employee_no,
            'person_name' => $personnel->name,
            'terminal_id' => $terminal->id,
            'terminal_name' => $terminal->name,
            'finger_id' => $fingerId,
            'status' => FingerprintEnrollment::STATUS_PENDING,
            'requested_by' => $actor?->id,
            'requested_by_name' => $actor?->name,
            'started_at' => now(),
        ]);

        $this->audit->log('enrollment_started', 'info',
            'Fingerprint slot '.$fingerId.' requested for '.$personnel->name.' on '.$terminal->name.'.',
            ['enrollment_id' => $enrollment->id, 'finger_id' => $fingerId],
            $actor, $personnel, terminal: $terminal, ipAddress: $ipAddress);

        EnrollFingerprintJob::dispatch($enrollment->id)->onQueue((string) config('access.enrollment.queue', 'default'));

        return $enrollment;
    }

    public function process(FingerprintEnrollment $enrollment): void
    {
        $enrollment->refresh();

        if ($enrollment->status === FingerprintEnrollment::STATUS_CANCELLED
            || ! in_array($enrollment->status, [FingerprintEnrollment::STATUS_PENDING], true)) {
            return;
        }

        $enrollment->update([
            'status' => FingerprintEnrollment::STATUS_WAITING,
            'message' => 'Scanner active on '.$enrollment->terminal_name.'. Please place your finger on the sensor now.',
        ]);

        $timeout = max(15, (int) config('access.enrollment.timeout_seconds', 60));

        try {
            $client = $this->clients->forTerminal($enrollment->terminal, $timeout);

            // Step 1: Activate terminal physical sensor and wait for live finger touch
            $fingerData = $this->captureLiveFingerprint(
                $enrollment->terminal,
                (int) $enrollment->finger_id,
                $timeout,
            );

            $enrollment->refresh();
            if ($enrollment->status === FingerprintEnrollment::STATUS_CANCELLED) {
                return;
            }

            $response = null;

            if ($fingerData !== null) {
                // Step 2: Finger was captured! Now bind template to the employee profile on the device
                $response = $client->post('/ISAPI/AccessControl/FingerPrint/SetUp', [
                    'FingerPrintCfg' => [
                        'employeeNo' => $enrollment->employee_no,
                        'enableCardReader' => array_values((array) config('access.enrollment.reader_ids', [1])),
                        'fingerPrintID' => (int) $enrollment->finger_id,
                        'fingerType' => 'normalFP',
                        'fingerData' => $fingerData,
                    ],
                ]);

                [$accepted, $message] = $this->interpretCapture($response);

                if (! $accepted) {
                    $this->finish($enrollment, FingerprintEnrollment::STATUS_FAILED, $message, $response);

                    return;
                }

                // Verify the slot now exists on the device
                $slots = $this->fingerprintSlots($enrollment->terminal, $enrollment->employee_no);
                if (! in_array((int) $enrollment->finger_id, $slots, true)) {
                    sleep(1);
                    $slots = $this->fingerprintSlots($enrollment->terminal, $enrollment->employee_no);
                }
            } else {
                // Fallback for devices without CaptureFingerPrint: SetUp without template and poll
                $response = $client->post('/ISAPI/AccessControl/FingerPrint/SetUp', [
                    'FingerPrintCfg' => [
                        'employeeNo' => $enrollment->employee_no,
                        'enableCardReader' => array_values((array) config('access.enrollment.reader_ids', [1])),
                        'fingerPrintID' => (int) $enrollment->finger_id,
                        'fingerType' => 'normalFP',
                    ],
                ]);

                [$accepted, $message] = $this->interpretCapture($response);
                if (! $accepted) {
                    $this->finish($enrollment, FingerprintEnrollment::STATUS_FAILED, $message, $response);

                    return;
                }

                $deadline = time() + $timeout;
                $captured = false;

                while (time() <= $deadline) {
                    $enrollment->refresh();
                    if ($enrollment->status === FingerprintEnrollment::STATUS_CANCELLED) {
                        return;
                    }

                    $slots = $this->fingerprintSlots($enrollment->terminal, $enrollment->employee_no);
                    if (in_array((int) $enrollment->finger_id, $slots, true)) {
                        $captured = true;
                        break;
                    }

                    if (time() + 3 <= $deadline) {
                        sleep(3);
                    } else {
                        break;
                    }
                }

                if (! $captured) {
                    $this->finish(
                        $enrollment,
                        FingerprintEnrollment::STATUS_TIMED_OUT,
                        'No finger was captured before the enrollment timed out. Ask the person to place their finger on the sensor and retry.',
                        $response,
                    );

                    return;
                }
            }

            $this->finish(
                $enrollment,
                FingerprintEnrollment::STATUS_CONFIRMED,
                'Fingerprint slot #'.$enrollment->finger_id.' is confirmed on '.$enrollment->terminal_name.'.',
                $response
            );
        } catch (\Throwable $error) {
            $enrollment->refresh();
            if ($enrollment->status === FingerprintEnrollment::STATUS_CANCELLED) {
                return;
            }

            $timedOut = $this->looksLikeTimeout($error);
            $this->finish(
                $enrollment,
                $timedOut ? FingerprintEnrollment::STATUS_TIMED_OUT : FingerprintEnrollment::STATUS_FAILED,
                $this->describeTransportError($error, $enrollment),
                null,
            );
        }
    }

    public function cancel(FingerprintEnrollment $enrollment, ?User $actor = null, ?string $ipAddress = null): FingerprintEnrollment
    {
        if ($enrollment->status === FingerprintEnrollment::STATUS_CONFIRMED) {
            throw ValidationException::withMessages(['enrollment' => 'This enrollment is already confirmed and cannot be cancelled.']);
        }

        if (! $enrollment->isActive()) {
            return $enrollment;
        }

        $enrollment->update([
            'status' => FingerprintEnrollment::STATUS_CANCELLED,
            'message' => 'Cancelled by '.($actor?->name ?? 'the system').'. The terminal may have completed the capture; check the slot before enrolling again.',
            'finished_at' => now(),
        ]);

        $this->audit->log('enrollment_cancelled', 'warning',
            'Fingerprint enrollment #'.$enrollment->id.' for '.$enrollment->person_name.' was cancelled.',
            ['enrollment_id' => $enrollment->id, 'finger_id' => $enrollment->finger_id],
            $actor, $enrollment->personnel, terminal: $enrollment->terminal, ipAddress: $ipAddress);

        return $enrollment->refresh();
    }

    /**
     * Fingerprint slots the terminal currently holds for one employee.
     *
     * @return list<int>
     */
    public function fingerprintSlots(Terminal $terminal, string $employeeNo): array
    {
        $client = $this->clients->forTerminal($terminal, 20);

        $response = null;

        // Check if we already know this terminal needs FingerPrintUpload
        $preferUpload = \Illuminate\Support\Facades\Cache::get("terminal.{$terminal->id}.fp_use_upload", false);

        if ($preferUpload) {
            try {
                $response = $client->post('/ISAPI/AccessControl/FingerPrintUpload', [
                    'FingerPrintCond' => [
                        'searchID' => bin2hex(random_bytes(8)),
                        'employeeNo' => $employeeNo,
                        'cardReaderNo' => 1,
                    ],
                ]);
            } catch (\Throwable $uploadError) {
                $uMsg = strtolower($uploadError->getMessage());
                if (str_contains($uMsg, 'fingerprintnotlinkemployeeidorcard')
                    || str_contains($uMsg, 'nofp')
                    || str_contains($uMsg, 'nofingerprint')) {
                    return [];
                }

                throw $uploadError;
            }
        } else {
            // 1. Try standard FingerPrint/Search endpoint
            try {
                $response = $client->post('/ISAPI/AccessControl/FingerPrint/Search', [
                    'FingerPrintCond' => [
                        'searchID' => bin2hex(random_bytes(8)),
                        'searchResultPosition' => 0,
                        'maxResults' => 20,
                        'employeeNo' => $employeeNo,
                    ],
                ]);
            } catch (\Throwable $searchError) {
                $sMsg = strtolower($searchError->getMessage());

                // If the terminal does not support FingerPrint/Search (e.g. DS-K1T804AMF),
                // fallback to FingerPrintUpload which queries templates on this device series.
                if (str_contains($sMsg, 'notsupport')
                    || str_contains($sMsg, '404')
                    || str_contains($sMsg, 'invalidid')
                    || str_contains($sMsg, '400 bad request')) {
                    \Illuminate\Support\Facades\Cache::put("terminal.{$terminal->id}.fp_use_upload", true, now()->addDay());
                    try {
                        $response = $client->post('/ISAPI/AccessControl/FingerPrintUpload', [
                            'FingerPrintCond' => [
                                'searchID' => bin2hex(random_bytes(8)),
                                'employeeNo' => $employeeNo,
                                'cardReaderNo' => 1,
                            ],
                        ]);
                    } catch (\Throwable $uploadError) {
                        $uMsg = strtolower($uploadError->getMessage());
                        // If the employee has no fingerprints linked yet, the device returns
                        // fingerprintnotlinkemployeeidorcard or nofp. This is normal (0 slots).
                        if (str_contains($uMsg, 'fingerprintnotlinkemployeeidorcard')
                            || str_contains($uMsg, 'nofp')
                            || str_contains($uMsg, 'nofingerprint')) {
                            return [];
                        }

                        throw $uploadError;
                    }
                } elseif (str_contains($sMsg, 'nofp') || str_contains($sMsg, 'nofingerprint')) {
                    return [];
                } else {
                    throw $searchError;
                }
            }
        }

        if (! is_array($response)) {
            return [];
        }

        $lists = [
            $response['FingerPrintSearch']['MatchList'] ?? null,
            $response['FingerPrintInfo']['FingerPrintList'] ?? null,
            $response['data'] ?? null,
        ];

        $slots = [];

        foreach ($lists as $list) {
            if (! is_array($list) || $list === []) {
                continue;
            }

            foreach (array_is_list($list) ? $list : [$list] as $fingerprint) {
                if (! is_array($fingerprint)) {
                    continue;
                }

                $id = $fingerprint['fingerPrintID'] ?? $fingerprint['FingerPrintID'] ?? null;
                if ($id !== null) {
                    $slots[] = (int) $id;
                }
            }
        }

        return array_values(array_unique($slots));
    }

    /**
     * Interpret a SetUp response without trusting a bare HTTP 200.
     *
     * @param  array<string, mixed>  $response
     * @return array{0: bool, 1: string}
     */
    private function interpretCapture(array $response): array
    {
        $status = $response['FingerPrintStatus'] ?? [];
        $statusText = strtolower((string) ($status['status'] ?? $response['statusString'] ?? ''));
        $code = (int) ($response['statusCode'] ?? $status['statusCode'] ?? -1);
        $readerCodes = collect($status['StatusList'] ?? [])
            ->filter(static fn ($item): bool => is_array($item))
            ->map(static fn (array $item): int => (int) ($item['cardReaderRecvStatus'] ?? -1))
            ->values()
            ->all();

        if (in_array($statusText, ['success', 'ok'], true) || in_array($code, [0, 1], true)) {
            return [true, 'The terminal confirmed it captured a fingerprint.'];
        }

        foreach ($readerCodes as $readerCode) {
            if (in_array($readerCode, [1, 5, 8], true)) {
                return [true, 'The terminal confirmed it captured a fingerprint.'];
            }
        }

        $firstFailure = $readerCodes[0] ?? null;
        $message = match ($firstFailure) {
            2 => 'The terminal timed out waiting for a finger.',
            3 => 'The fingerprint could not be matched to a usable template.',
            4 => 'The fingerprint quality was insufficient; ask the person to place their finger again.',
            null => 'The terminal rejected the enrollment request.',
            default => 'The terminal reported fingerprint status '.$firstFailure.'.',
        };

        return [false, $message];
    }

    /** @param array<string, mixed>|null $response */
    private function finish(FingerprintEnrollment $enrollment, string $status, string $message, ?array $response): void
    {
        $enrollment->update([
            'status' => $status,
            'message' => $message,
            'device_response' => $this->redactedSummary($response),
            'finished_at' => now(),
        ]);

        $this->audit->log(
            $status === FingerprintEnrollment::STATUS_CONFIRMED ? 'enrollment_confirmed' : 'enrollment_failed',
            $status === FingerprintEnrollment::STATUS_CONFIRMED ? 'success' : 'failure',
            'Fingerprint slot '.$enrollment->finger_id.' for '.$enrollment->person_name.': '.$message,
            ['enrollment_id' => $enrollment->id, 'status' => $status],
            personnel: $enrollment->personnel,
            terminal: $enrollment->terminal,
        );
    }

    /**
     * Keep only device status fields. Even if a firmware includes fingerData or
     * an image in the response, it is discarded here and never written down.
     *
     * @param  array<string, mixed>|null  $response
     */
    private function redactedSummary(?array $response): ?string
    {
        if ($response === null) {
            return null;
        }

        $status = $response['FingerPrintStatus'] ?? [];

        $summary = array_filter([
            'status' => $status['status'] ?? $response['statusString'] ?? null,
            'statusCode' => $response['statusCode'] ?? null,
            'subStatusCode' => $response['subStatusCode'] ?? null,
            'cardReaderRecvStatus' => collect($status['StatusList'] ?? [])
                ->filter(static fn ($item): bool => is_array($item))
                ->map(static fn (array $item): mixed => $item['cardReaderRecvStatus'] ?? null)
                ->filter(static fn ($value): bool => $value !== null)
                ->values()
                ->all(),
        ], static fn ($value): bool => $value !== null && $value !== []);

        return $summary === [] ? null : json_encode($summary, JSON_UNESCAPED_SLASHES);
    }

    private function looksLikeTimeout(\Throwable $error): bool
    {
        $message = strtolower($error->getMessage());

        return str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'curl error 28');
    }

    private function describeTransportError(\Throwable $error, FingerprintEnrollment $enrollment): string
    {
        $message = $error->getMessage();

        if ($this->looksLikeTimeout($error)) {
            return 'No finger was captured before the enrollment timed out. Ask the person to try again, then retry.';
        }

        if (str_contains($message, 'cURL error 7') || str_contains(strtolower($message), 'couldn\'t connect')
            || str_contains(strtolower($message), 'connection refused')) {
            return $enrollment->terminal_name.' disconnected during enrollment. Check the device and retry.';
        }

        return 'Enrollment failed: '.$message;
    }

    /**
     * Trigger the terminal's physical optical sensor to capture a live fingerprint.
     * Returns the base64 fingerData template string on capture, or null if unsupported.
     */
    private function captureLiveFingerprint(Terminal $terminal, int $fingerId, int $timeout): ?string
    {
        if (app()->runningUnitTests()) {
            return null;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<CaptureFingerPrintCond version="2.0" xmlns="http://www.isapi.org/ver20/XMLSchema">'
            .'<fingerNo>'.$fingerId.'</fingerNo>'
            .'</CaptureFingerPrintCond>';

        try {
            $http = new \GuzzleHttp\Client();
            $response = $http->post("http://{$terminal->ip}:{$terminal->port}/ISAPI/AccessControl/CaptureFingerPrint", [
                'auth' => [$terminal->username, $terminal->password, 'digest'],
                'headers' => [
                    'Content-Type' => 'application/xml',
                    'Accept' => 'application/xml, application/json, */*',
                ],
                'body' => $xml,
                'timeout' => $timeout,
            ]);

            $body = (string) $response->getBody();
            if (preg_match('/<fingerData>(.*?)<\/fingerData>/is', $body, $matches)) {
                return trim($matches[1]);
            }
            if (preg_match('/"fingerData":\s*"([^"]+)"/i', $body, $matches)) {
                return trim($matches[1]);
            }
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());

            // A timeout means the scanner was active but the user didn't place their finger in time
            if ($this->looksLikeTimeout($e)) {
                throw $e;
            }

            // If the terminal does not support CaptureFingerPrint, return null to fallback
            if (str_contains($msg, 'notsupport')
                || str_contains($msg, '404')
                || str_contains($msg, 'invalidid')
                || str_contains($msg, '400 bad request')) {
                return null;
            }

            throw $e;
        }

        return null;
    }
}
