<?php

namespace App\Services;

use App\Models\Terminal;
use Shaykhnazar\HikvisionIsapi\Authentication\Contracts\AuthenticatorInterface;
use Shaykhnazar\HikvisionIsapi\Client\Contracts\HttpClientInterface;
use Shaykhnazar\HikvisionIsapi\Client\HikvisionClient;

/**
 * Builds a client for one terminal with an explicit timeout.
 *
 * The shared client uses the terminal's stored timeout, which is deliberately
 * short so an unreachable device cannot stall a page. A fingerprint capture
 * waits for a human to touch the scanner, so it needs its own longer deadline
 * without changing the stored device setting.
 */
class DeviceClientFactory
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AuthenticatorInterface $authenticator,
    ) {}

    public function forTerminal(Terminal $terminal, ?int $timeoutSeconds = null): HikvisionClient
    {
        $config = [
            'format' => 'json',
            'devices' => [
                $terminal->name => [
                    'ip' => $terminal->ip,
                    'port' => (int) $terminal->port,
                    'username' => $terminal->username,
                    'password' => $terminal->password,
                    'protocol' => $terminal->protocol,
                    'timeout' => max(5, $timeoutSeconds ?? (int) $terminal->timeout),
                    'verify_ssl' => (bool) $terminal->verify_ssl,
                ],
            ],
            'default' => $terminal->name,
        ];

        return new HikvisionClient($this->httpClient, $this->authenticator, $config);
    }
}
