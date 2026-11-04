<?php

declare(strict_types=1);

namespace Shaykhnazar\HikvisionIsapi\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Shaykhnazar\HikvisionIsapi\HikvisionIsapiServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            HikvisionIsapiServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        config()->set('zchpc.default', 'primary');
        config()->set('zchpc.devices.primary', [
            'ip' => env('ZCHPC_DEVICE_IP', '192.168.1.100'),
            'port' => env('ZCHPC_DEVICE_PORT', 80),
            'username' => env('ZCHPC_DEVICE_USERNAME', 'admin'),
            'password' => env('ZCHPC_DEVICE_PASSWORD', 'password'),
            'protocol' => env('ZCHPC_DEVICE_PROTOCOL', 'http'),
            'timeout' => env('ZCHPC_DEVICE_TIMEOUT', 30),
            'verify_ssl' => env('ZCHPC_DEVICE_VERIFY_SSL', false),
        ]);
        config()->set('zchpc.format', 'json');
        config()->set('zchpc.logging.enabled', true);
        config()->set('zchpc.logging.channel', 'stack');
    }
}
