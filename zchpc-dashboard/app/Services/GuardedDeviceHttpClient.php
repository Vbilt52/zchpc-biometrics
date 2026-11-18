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
        return $this->guard($uri, fn () => $this->inner->get($uri, $options));
    }

    public function post(string $uri, #[\SensitiveParameter] array $data = [], #[\SensitiveParameter] array $options = []): array
    {
        return $this->guard($uri, fn () => $this->inner->post($uri, $data, $options));
    }

    public function put(string $uri, #[\SensitiveParameter] array $data = [], #[\SensitiveParameter] array $options = []): array
    {
        return $this->guard($uri, fn () => $this->inner->put($uri, $data, $options));
    }

    public function delete(string $uri, #[\SensitiveParameter] array $options = []): array
    {
        return $this->guard($uri, fn () => $this->inner->delete($uri, $options));
    }

    public function postMultipart(string $uri, #[\SensitiveParameter] array $multipart = [], #[\SensitiveParameter] array $options = []): array
    {
        return $this->guard($uri, fn () => $this->inner->postMultipart($uri, $multipart, $options));
    }

    public function putMultipart(string $uri, #[\SensitiveParameter] array $multipart = [], #[\SensitiveParameter] array $options = []): array
    {
        return $this->guard($uri, fn () => $this->inner->putMultipart($uri, $multipart, $options));
    }

    private function guard(string $uri, #[\SensitiveParameter] \Closure $request): array
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
            if ($xml && strtolower((string) $xml->lockStatus) === 'lock') {
                $seconds = max(30, min(86400, (int) ($xml->unlockTime ?? 1800))) + 5;
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
