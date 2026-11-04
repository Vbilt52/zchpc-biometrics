<?php

namespace App\Services;

/**
 * TCP reachability to a terminal's ISAPI port.
 *
 * This is deliberately separate from device online status. A reachable port
 * proves only that something accepted a connection; a device is online only
 * after an authenticated ISAPI read succeeds. Reachability is used to fail
 * unreachable devices quickly during diagnostics and bulk refreshes, and to
 * report networks problems distinctly from credential problems.
 */
class NetworkReachability
{
    /**
     * @return array{reachable: bool, latency_ms: int|null, error: string|null}
     */
    public function probe(string $host, int $port, ?float $timeout = null): array
    {
        $timeout ??= (float) config('devices.reachability_timeout', 2.0);
        $address = $this->address($host, $port);
        $started = microtime(true);
        $errorNumber = 0;
        $errorMessage = '';

        $socket = @stream_socket_client(
            $address,
            $errorNumber,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT,
        );

        if ($socket === false) {
            return [
                'reachable' => false,
                'latency_ms' => $this->milliseconds($started),
                'error' => $this->describeFailure($errorNumber, $errorMessage, $timeout),
            ];
        }

        fclose($socket);

        return [
            'reachable' => true,
            'latency_ms' => $this->milliseconds($started),
            'error' => null,
        ];
    }

    /**
     * Probe several hosts with a bounded number of simultaneous connections.
     *
     * Terminals are cheap embedded servers: probing four ports at once is a
     * reasonable ceiling and keeps a large site's bulk refresh from opening
     * dozens of sockets. Results keep the caller's keys.
     *
     * @param  array<int|string, array{host: string, port: int}>  $targets
     * @return array<int|string, array{reachable: bool, latency_ms: int|null, error: string|null}>
     */
    public function probeMany(array $targets, int $concurrency = 4, ?float $timeout = null): array
    {
        $timeout ??= (float) config('devices.reachability_timeout', 2.0);
        $concurrency = max(1, $concurrency);
        $results = [];
        $active = [];
        $queue = array_filter($targets, static fn (array $target): bool => isset($target['host'], $target['port']));

        while ($queue !== [] || $active !== []) {
            while (count($active) < $concurrency && $queue !== []) {
                $key = array_key_first($queue);
                $target = $queue[$key];
                unset($queue[$key]);

                $errorNumber = 0;
                $errorMessage = '';
                $socket = @stream_socket_client(
                    $this->address((string) $target['host'], (int) $target['port']),
                    $errorNumber,
                    $errorMessage,
                    (float) $timeout,
                    STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
                );

                if ($socket === false) {
                    $results[$key] = [
                        'reachable' => false,
                        'latency_ms' => null,
                        'error' => $this->describeFailure($errorNumber, $errorMessage, $timeout),
                    ];

                    continue;
                }

                stream_set_blocking($socket, false);
                $active[$key] = ['socket' => $socket, 'started' => microtime(true)];
            }

            if ($active === []) {
                break;
            }

            $read = null;
            $write = [];
            $except = [];

            foreach ($active as $entry) {
                $write[] = $entry['socket'];
                $except[] = $entry['socket'];
            }

            $wait = 0;
            $microseconds = 100000;
            $ready = @stream_select($read, $write, $except, $wait, $microseconds);

            foreach ($active as $key => $entry) {
                $socket = $entry['socket'];
                $connected = in_array($socket, $write, true);
                $failed = in_array($socket, $except, true) || ($ready === false);
                $expired = (microtime(true) - $entry['started']) >= (float) $timeout;

                if ($connected) {
                    // Writable alone can also mean a refused connection; the
                    // peer name is only reported for a completed handshake.
                    $peer = @stream_socket_get_name($socket, true);

                    if ($peer !== false) {
                        $results[$key] = [
                            'reachable' => true,
                            'latency_ms' => $this->milliseconds($entry['started']),
                            'error' => null,
                        ];
                    } else {
                        $results[$key] = [
                            'reachable' => false,
                            'latency_ms' => $this->milliseconds($entry['started']),
                            'error' => 'The connection was closed before it was established.',
                        ];
                    }
                } elseif ($failed || $expired) {
                    $results[$key] = [
                        'reachable' => false,
                        'latency_ms' => $expired ? (int) round($timeout * 1000) : $this->milliseconds($entry['started']),
                        'error' => $expired ? "The connection timed out after {$timeout} seconds." : 'The connection was refused or failed.',
                    ];
                } else {
                    continue;
                }

                @fclose($socket);
                unset($active[$key]);
            }
        }

        foreach ($targets as $key => $target) {
            $results[$key] ??= [
                'reachable' => false,
                'latency_ms' => null,
                'error' => 'The host or port was not valid for a reachability check.',
            ];
        }

        return $results;
    }

    private function address(string $host, int $port): string
    {
        $host = trim($host);

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return "tcp://[{$host}]:{$port}";
        }

        return "tcp://{$host}:{$port}";
    }

    private function milliseconds(float $started): int
    {
        return max(0, (int) round((microtime(true) - $started) * 1000));
    }

    private function describeFailure(int $number, string $message, float $timeout): string
    {
        $message = trim($message);

        if ($message !== '') {
            // Operating-system messages can be long and locale-specific; keep
            // the actionable start of the sentence.
            return mb_strlen($message) > 160 ? rtrim(mb_substr($message, 0, 157)).'…' : $message;
        }

        return match ($number) {
            110, 10060 => "The connection timed out after {$timeout} seconds.",
            111, 10061 => 'The connection was refused. Check the device address and port.',
            default => 'The connection could not be established.',
        };
    }
}
