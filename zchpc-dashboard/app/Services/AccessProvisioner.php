<?php

namespace App\Services;

use App\Models\Personnel;
use App\Models\Terminal;
use Carbon\Carbon;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;
use Shaykhnazar\HikvisionIsapi\Client\HikvisionClient;
use Shaykhnazar\HikvisionIsapi\Exceptions\HikvisionException;

/**
 * Minimal, least-privilege device writes used before enrollment.
 *
 * A fingerprint can only be attached to an employee number the terminal knows,
 * but granting door access before the administrator has chosen doors would
 * violate least privilege. This service therefore creates the identity in a
 * disabled state (no usable access) and verifies the device actually applied
 * that state. The blocked user type prevents this identity from granting access.
 */
class AccessProvisioner
{
    public function __construct(
        private readonly DeviceManager $deviceManager,
        private readonly AccessAuditLogger $audit,
    ) {}

    /**
     * Ensure the employee exists on the terminal without granting access.
     *
     * @throws \RuntimeException when the device will not hold a disabled identity
     */
    public function ensureEnrollmentIdentity(Terminal $terminal, string $employeeNo, string $name, ?string $phone = null): void
    {
        $client = $this->deviceManager->device($terminal->name);
        $existing = $this->readPerson($client, $employeeNo);

        if ($existing !== null) {
            $person = Personnel::where('employee_no', $employeeNo)->first();
            $approved = $person && ! $person->isDraft() && $person->isActive() && $person->terminals()->whereKey($terminal->id)->wherePivot('access_allowed', true)->exists();
            if (($existing['userType'] ?? null) !== 'blackList' && ! $approved) {
                throw new \RuntimeException('Existing identity is not confirmed blocked or locally authorized. Review access before enrollment.');
            }

            return;
        }

        $zone = config('attendance.device_timezone');
        $begin = Carbon::now($zone);
        $end = $begin->copy()->addDay();

        $response = $client->post('/ISAPI/AccessControl/UserInfo/Record', [
            'UserInfo' => [
                'employeeNo' => $employeeNo,
                'name' => $name,
                'userType' => 'blackList',
                // Explicitly invalid: the person exists for credential capture
                // but cannot open anything until doors are granted and applied.
                'Valid' => [
                    'enable' => true,
                    'timeType' => 'local',
                    'beginTime' => $begin->format('Y-m-d\TH:i:s'),
                    'endTime' => $end->format('Y-m-d\TH:i:s'),
                ],
                'doorRight' => '1',
                'RightPlan' => [['doorNo' => 1, 'planTemplateNo' => '1']],
                'phoneNumber' => $phone ?? '',
            ],
        ]);

        $status = $response['ResponseStatus'] ?? $response;
        $code = isset($status['statusCode']) ? (int) $status['statusCode'] : 1;
        if (! in_array($code, [0, 1], true)) {
            throw new \RuntimeException('The terminal refused to create the identity: '
                .($status['subStatusCode'] ?? $status['statusString'] ?? 'unknown error'));
        }

        $actual = $this->readPerson($client, $employeeNo);
        if ($actual === null) {
            throw new \RuntimeException('The terminal did not confirm that the identity was created.');
        }

        if (($actual['userType'] ?? null) !== 'blackList') {
            throw new \RuntimeException(
                'The terminal accepted the identity but did not keep it disabled, so no-access provisioning '
                .'cannot be guaranteed. Use local enrollment on the terminal and import the person instead.'
            );
        }
    }

    /** Remove an identity created for an abandoned draft, and confirm its absence. */
    public function removeIdentity(Terminal $terminal, string $employeeNo): void
    {
        $client = $this->deviceManager->device($terminal->name);

        if ($this->readPerson($client, $employeeNo) === null) {
            return;
        }

        $client->put('/ISAPI/AccessControl/UserInfo/Delete', [
            'UserInfoDelCond' => ['EmployeeNoList' => [['employeeNo' => $employeeNo]]],
        ]);

        if ($this->readPerson($client, $employeeNo) !== null) {
            throw new \RuntimeException('The terminal has not confirmed removal of the draft identity.');
        }
    }

    /**
     * Ask the device whether it exposes the fingerprint capture API.
     *
     * The answer is stored rather than inferred from the model: the same model
     * family ships with and without a fingerprint module.
     */
    public function probeFingerprintCapability(Terminal $terminal, ?string $ipAddress = null): string
    {
        $client = $this->deviceManager->device($terminal->name);
        $endpoints = [
            '/ISAPI/AccessControl/FingerPrintCfg/capabilities',
            '/ISAPI/AccessControl/FingerPrint/capabilities',
        ];

        $lastFailure = null;

        foreach ($endpoints as $endpoint) {
            try {
                $response = $client->get($endpoint);
                $terminal->forceFill([
                    'fp_capability' => Terminal::FP_SUPPORTED,
                    'fp_checked_at' => now(),
                    'fp_detail' => 'The device answered '.$endpoint.' ('.substr(json_encode($response) ?: '', 0, 400).')',
                ])->save();

                $this->audit->log('capability_probe', 'success',
                    'Fingerprint capture API confirmed by '.$terminal->name.'.',
                    ['endpoint' => $endpoint], terminal: $terminal, ipAddress: $ipAddress);

                return Terminal::FP_SUPPORTED;
            } catch (\Throwable $error) {
                $lastFailure = $error;
            }
        }

        $statusCode = $lastFailure instanceof HikvisionException ? $lastFailure->statusCode() : null;
        $failureMessage = strtolower((string) $lastFailure?->getMessage());
        $unsupported = in_array($statusCode, [404, 405], true)
            || str_contains($failureMessage, 'notsupport')
            || str_contains($failureMessage, 'not support');

        $terminal->forceFill([
            'fp_capability' => $unsupported ? Terminal::FP_UNSUPPORTED : Terminal::FP_UNKNOWN,
            'fp_checked_at' => now(),
            'fp_detail' => $unsupported
                ? 'The device does not expose the fingerprint capture capability endpoints.'
                : 'Capability could not be determined: '.($lastFailure?->getMessage() ?? 'no response'),
        ])->save();

        $this->audit->log('capability_probe', $unsupported ? 'warning' : 'failure',
            'Fingerprint capability check on '.$terminal->name.': '.$terminal->fp_capability.'.',
            ['status' => $terminal->fp_capability], terminal: $terminal, ipAddress: $ipAddress);

        return $terminal->fp_capability;
    }

    /**
     * Read one employee record, distinguishing "absent" from "unknown".
     *
     * @return array<string, mixed>|null
     */
    public function readPerson(HikvisionClient $client, string $employeeNo): ?array
    {
        $response = $client->post('/ISAPI/AccessControl/UserInfo/Search', [
            'UserInfoSearchCond' => [
                'searchID' => bin2hex(random_bytes(8)),
                'searchResultPosition' => 0,
                'maxResults' => 1,
                'EmployeeNoList' => [['employeeNo' => $employeeNo]],
            ],
        ]);

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
