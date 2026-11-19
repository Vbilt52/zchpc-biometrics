<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Shaykhnazar\HikvisionIsapi\Client\Contracts\HttpClientInterface;
use Shaykhnazar\HikvisionIsapi\Exceptions\AuthenticationException;

/** Respect a controller's reported account lock without repeatedly logging in. */
class GuardedDeviceHttpClient implements HttpClientInterface
{
    public function __construct(private readonly HttpClientInterface $inner) {}

    public function get(string $uri, #[\SensitiveParameter] array $options = []): array
    {
        return $this->guard($uri, fn () => $this->inner->get($uri, $options), $options);
    }

    public function post(string $uri, #[\SensitiveParameter] array $data = [], #[\SensitiveParameter] array $options = []): array
    {
        return $this->guard($uri, fn () => $this->inner->post($uri, $data, $options), $options);
    }

    public function put(string $uri, #[\SensitiveParameter] array $data = [], #[\SensitiveParameter] array $options = []): array
    {
        return $this->guard($uri, fn () => $this->inner->put($uri, $data, $options), $options);
    }

    public function delete(string $uri, #[\SensitiveParameter] array $options = []): array
    {
        return $this->guard($uri, fn () => $this->inner->delete($uri, $options), $options);
    }

    public function postMultipart(string $uri, #[\SensitiveParameter] array $multipart = [], #[\SensitiveParameter] array $options = []): array
    {
        return $this->guard($uri, fn () => $this->inner->postMultipart($uri, $multipart, $options), $options);
    }

    public function putMultipart(string $uri, #[\SensitiveParameter] array $multipart = [], #[\SensitiveParameter] array $options = []): array
    {
        return $this->guard($uri, fn () => $this->inner->putMultipart($uri, $multipart, $options), $options);
    }

    private function guard(string $uri, #[\SensitiveParameter] \Closure $request, #[\SensitiveParameter] array $options): array
    {
        $host = parse_url($uri, PHP_URL_HOST);
        $port = parse_url($uri, PHP_URL_PORT) ?? (parse_url($uri, PHP_URL_SCHEME) === 'https' ? 443 : 80);
        $key = 'device-auth-cooldown:'.hash('sha256', $host.':'.$port);
        $until = (int) Cache::get($key, 0);
        if ($until > now()->timestamp) {
            throw $this->locked($until - now()->timestamp);
        }
        try {
            return $request();
        } catch (AuthenticationException $error) {
            $body = $error->responseBody() ?? '';
            $xml = strlen($body) <= 16384 ? @simplexml_load_string($body, options: LIBXML_NONET) : false;
            $status = $xml ? ['lockStatus' => (string) $xml->lockStatus, 'unlockTime' => (string) $xml->unlockTime] : [];
            // Some firmware returns an empty JSON 401 but reports the lock
            // duration on its XML device-info endpoint. This is one read-only
            // diagnostic, never a replay of the failed write.
            if ($body === '') {
                try {
                    $probe = $this->inner->get(parse_url($uri, PHP_URL_SCHEME).'://'.$host.':'.$port.'/ISAPI/System/deviceInfo',
                        array_replace($options, ['http_errors' => false, 'timeout' => 5, 'headers' => ['Accept' => 'application/xml']]));
                    $status = $probe['ResponseStatus'] ?? $probe;
                } catch (\Throwable) {
                    // Preserve the original failure if the diagnostic is unavailable.
                }
            }
            if (strtolower((string) ($status['lockStatus'] ?? '')) === 'lock') {
                $seconds = max(30, min(86400, (int) ($status['unlockTime'] ?? 1800))) + 5;
                Cache::put($key, now()->timestamp + $seconds, $seconds);
                throw $this->locked($seconds);
            }

            throw $error;
        }
    }

    private function locked(int $seconds): AuthenticationException
    {
        return new AuthenticationException('The terminal API account is temporarily locked. Automatic requests are paused for approximately '.(int) ceil($seconds / 60).' minute(s).', statusCode: 401);
    }
}
