<?php

namespace App\Services;

use Shaykhnazar\HikvisionIsapi\Exceptions\AuthenticationException;
use Shaykhnazar\HikvisionIsapi\Exceptions\DeviceBusyException;
use Shaykhnazar\HikvisionIsapi\Exceptions\DeviceUnreachableException;
use Shaykhnazar\HikvisionIsapi\Exceptions\HikvisionException;

/**
 * Turns a device failure into a category an administrator can act on, without
 * echoing credentials, request bodies or raw device payloads.
 */
class DeviceErrorInterpreter
{
    /**
     * @return array{category: string, message: string, retryable: bool, http_status: int|null}
     */
    public function interpret(\Throwable $error): array
    {
        if ($error instanceof AuthenticationException) {
            return [
                'category' => 'auth',
                'message' => 'The device answered but rejected its saved credentials. Update the device username and password in Terminals; retrying will not help until they are corrected.',
                'retryable' => false,
                'http_status' => 401,
            ];
        }

        if ($error instanceof DeviceUnreachableException) {
            return [
                'category' => 'network',
                'message' => 'No connection could be established with the device. It may be powered off, disconnected from the network, or the saved address and port may be wrong.',
                'retryable' => true,
                'http_status' => null,
            ];
        }

        if ($error instanceof DeviceBusyException) {
            return [
                'category' => 'busy',
                'message' => 'The device answered but could not serve the request (busy or temporary device error). Retry in a moment.',
                'retryable' => true,
                'http_status' => $error->statusCode(),
            ];
        }

        if ($error instanceof HikvisionException) {
            $status = $error->statusCode();

            return [
                'category' => 'protocol',
                'message' => $status === null
                    ? 'The device response could not be verified. Check its protocol, certificate and configuration.'
                    : "The device returned HTTP {$status} for a read-only status request. Check the device model, firmware and ISAPI access settings.",
                'retryable' => $error->isRetryable(),
                'http_status' => $status,
            ];
        }

        return [
            'category' => 'internal',
            'message' => 'The application could not verify the device response. Check its model, configuration and connectivity.',
            'retryable' => false,
            'http_status' => null,
        ];
    }
}
