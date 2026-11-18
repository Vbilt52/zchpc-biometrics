<?php

namespace Tests\Feature;

use App\Services\DeviceErrorInterpreter;
use App\Services\GuardedDeviceHttpClient;
use Illuminate\Support\Facades\Cache;
use Shaykhnazar\HikvisionIsapi\Client\Contracts\HttpClientInterface;
use Shaykhnazar\HikvisionIsapi\Exceptions\AuthenticationException;
use Tests\TestCase;

class DeviceAuthenticationCooldownTest extends TestCase
{
    public function test_reported_lock_pauses_all_calls_to_that_device_until_unlock(): void
    {
        Cache::flush();
        $inner = \Mockery::mock(HttpClientInterface::class);
        $inner->shouldReceive('get')->once()->with('http://192.0.2.1:80/info', [])->andThrow(
            new AuthenticationException('Unauthorized', statusCode: 401, responseBody: '<ResponseStatus><lockStatus>lock</lockStatus><unlockTime>120</unlockTime></ResponseStatus>'));
        $inner->shouldReceive('get')->once()->with('http://192.0.2.2/info', [])->andReturn(['ok' => true]);
        $inner->shouldReceive('post')->once()->with('http://192.0.2.1/write', [], [])->andReturn(['ok' => true]);
        $client = new GuardedDeviceHttpClient($inner);
        foreach ([fn () => $client->get('http://192.0.2.1:80/info'), fn () => $client->post('http://192.0.2.1/write')] as $call) {
            try {
                $call();
                $this->fail('Expected cooldown');
            } catch (AuthenticationException $error) {
                $this->assertStringContainsString('temporarily locked', app(DeviceErrorInterpreter::class)->interpret($error)['message']);
            }
        }
        $this->assertSame(['ok' => true], $client->get('http://192.0.2.2/info'));
        $this->travel(126)->seconds();
        $this->assertSame(['ok' => true], $client->post('http://192.0.2.1/write'));
    }

    public function test_transport_binding_uses_the_guard(): void
    {
        $this->assertInstanceOf(GuardedDeviceHttpClient::class, app(HttpClientInterface::class));
    }
}
