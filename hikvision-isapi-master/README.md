# ZCHPC Access Control Suite

<p align="center">
  <img src="https://hpc.ac.zw/hpc.png" alt="ZCHPC Logo" width="150" height="150">
</p>

<p align="center">
  <strong>Zimbabwe Centre for High Performance Computing</strong><br>
  <em>Accelerating innovation through Supercomputing</em>
</p>

<p align="center">
  <a href="https://hpc.ac.zw"><img src="https://img.shields.io/badge/Website-hpc.ac.zw-2563EB?style=for-the-badge" alt="ZCHPC Website"></a>
  <a href="https://github.com/shaykhnazar/hikvision-isapi/releases"><img src="https://img.shields.io/badge/version-v1.5.4-2563EB.svg?style=for-the-badge" alt="Latest Version"></a>
  <a href="https://opensource.org/licenses/MIT"><img src="https://img.shields.io/badge/License-MIT-yellow.svg?style=for-the-badge" alt="License: MIT"></a>
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-%5E8.2-1D4ED8.svg?style=for-the-badge" alt="PHP Version"></a>
  <a href="https://laravel.com/"><img src="https://img.shields.io/badge/Laravel-12%20%7C%2013-red.svg?style=for-the-badge" alt="Laravel Version"></a>
</p>

A clean, modern Laravel package for integrating with **Hikvision ISAPI Face Recognition Terminals** and access control devices, customised for the Zimbabwe Centre for High Performance Computing (ZCHPC). Supports multi-device management, universal device providers (config, database, API), multi-tenant architectures, and real-time event webhooks.

## Features

- **Real-Time Event Webhooks**: Configure devices to push events to your API endpoints
- **Multi-Device Support**: Manage unlimited access control terminals simultaneously
- **Universal Device Providers**: Load devices from config, database, API, or custom sources
- **Multi-Tenant Ready**: Tenant-scoped device loading with runtime registration
- **Device Management**: Get device info, status, and capabilities
- **Person Management**: Add, update, search, and delete persons
- **Card Management**: Handle access cards with full CRUD operations
- **Face Recognition**: Upload and manage face images with advanced search
- **Fingerprint Support**: Register and manage fingerprints
- **Access Control**: Control doors remotely across ZCHPC facilities
- **Event Handling**: Search and subscribe to access events
- **Clean Architecture**: SOLID principles, dependency injection, contracts
- **Type Safety**: PHP 8.2+ features (enums, readonly properties)
- **Performance**: Built-in caching with configurable TTL
- **XML/JSON Support**: Automatic format detection and conversion
- **Laravel 12/13**: Supports Laravel 12.x and 13.x with service provider and facade

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Quick Start](#quick-start)
- [Probing a device](#probing-a-device)
- [Event Webhooks & Notifications](#event-webhooks--notifications)
- [Multi-Device Support](#multi-device-support)
- [Services Overview](#services-overview)
- [Security](#security)
- [Changelog](#changelog)

## Requirements

- PHP ^8.2 (PHP ^8.3 is required for Laravel 13)
- Laravel / illuminate/support ^12.0 || ^13.0
- Guzzle HTTP ^7.8
- ext-curl
- ext-json
- ext-simplexml
- ext-libxml

## Installation

Install the package via Composer:

```bash
composer require zchpc/access-control
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=zchpc-config
```

## Configuration

Add these environment variables to your `.env` file:

```env
ZCHPC_DEVICE_IP=192.168.1.100
ZCHPC_DEVICE_PORT=80
ZCHPC_DEVICE_USERNAME=admin
ZCHPC_DEVICE_PASSWORD=your_password
ZCHPC_DEVICE_PROTOCOL=http
ZCHPC_DEVICE_TIMEOUT=30
ZCHPC_DEVICE_VERIFY_SSL=false
ZCHPC_DEVICE_FORMAT=json
```

### Configuration File

The published `config/zchpc.php` supports multiple devices:

```php
return [
    'default' => env('ZCHPC_DEFAULT_DEVICE', 'primary'),

    'devices' => [
        'primary' => [
            'ip' => env('ZCHPC_DEVICE_IP', '192.168.1.100'),
            'port' => env('ZCHPC_DEVICE_PORT', 80),
            'username' => env('ZCHPC_DEVICE_USERNAME', 'admin'),
            'password' => env('ZCHPC_DEVICE_PASSWORD'),
            'protocol' => env('ZCHPC_DEVICE_PROTOCOL', 'http'),
            'timeout' => env('ZCHPC_DEVICE_TIMEOUT', 30),
            'verify_ssl' => env('ZCHPC_DEVICE_VERIFY_SSL', false),
        ],
    ],

    'format' => env('ZCHPC_DEVICE_FORMAT', 'json'),

    'logging' => [
        'enabled' => env('ZCHPC_LOGGING', true),
        'channel' => env('ZCHPC_LOG_CHANNEL', 'stack'),
    ],
];
```

## Probing a device

Terminals differ. Capacity limits, which endpoints exist, and the exact
`subStatusCode` behind each refusal all vary by model and firmware, and the
documentation does not say. `bin/zchpc-probe` asks a device directly and
writes the answers to a JSON file:

```bash
ZCHPC_DEVICE_PASSWORD='...' php vendor/bin/zchpc-probe --host=192.168.1.100
```

Two things it guarantees:

**It never writes.** Every request is a read, including the ones that provoke refusals. It is safe to run against a terminal ZCHPC depends on.

**It never carries anybody out.** The person list is read for its *shape* — row counts, paging fields, what a row's keys are called. Field names are kept, field values are not. The report contains the device's own identity and behaviour.

## Quick Start

### Check Device Status

```php
use Shaykhnazar\HikvisionIsapi\Services\DeviceService;

$deviceService = app(DeviceService::class);

if ($deviceService->isOnline()) {
    $info = $deviceService->getInfo();
    echo "Device Model: " . $info['DeviceInfo']['model'];
}
```

### Add a Person

```php
use Shaykhnazar\HikvisionIsapi\Services\PersonService;
use Shaykhnazar\HikvisionIsapi\DTOs\Person;
use Shaykhnazar\HikvisionIsapi\Enums\UserType;

$personService = app(PersonService::class);

$person = new Person(
    employeeNo: 'EMP001',
    name: 'John Doe',
    userType: UserType::NORMAL,
    validEnabled: true,
    beginTime: now()->toISOString(),
    endTime: now()->addYear()->toISOString(),
    doorRight: '1',
    rightPlan: [
        ['doorNo' => 1, 'planTemplateNo' => '1']
    ]
);

$response = $personService->add($person);
```

### Upload Face Image

```php
use Shaykhnazar\HikvisionIsapi\Services\FaceService;

$faceService = app(FaceService::class);

// Read image file
$imageData = file_get_contents('path/to/photo.jpg');
$imageBase64 = base64_encode($imageData);

// Upload face (person must exist first)
$response = $faceService->uploadFace('EMP001', $imageBase64, 1);
```

### Search Persons

```php
$personService = app(PersonService::class);

// Walk the whole list
foreach ($personService->all() as $person) {
    echo "{$person->employeeNo}: {$person->name}\n";
}
```

### Control Doors

```php
use Shaykhnazar\HikvisionIsapi\Services\AccessControlService;

$accessControl = app(AccessControlService::class);

// Open door
$accessControl->openDoor(1);
```

## Multi-Device Support

### Loading Devices from Database

For complex environments like ZCHPC where devices might be added dynamically:

```php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Shaykhnazar\HikvisionIsapi\Client\Contracts\DeviceProviderInterface;
use Shaykhnazar\HikvisionIsapi\Client\Providers\CallbackDeviceProvider;
use App\Models\Device;

class ZchpcAccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register custom device provider
        $this->app->singleton('hikvision.device.provider', function ($app) {
            return tap(new CallbackDeviceProvider(), function ($provider) {
                // Register a callback to fetch device configuration from the database
                $provider->resolveUsing(function (string $name) {
                    $device = Device::where('name', $name)->first();
                    
                    if (! $device) {
                        return null; // Return null if device not found
                    }
                    
                    return [
                        'ip' => $device->ip_address,
                        'port' => $device->port ?? 80,
                        'username' => $device->username,
                        'password' => $device->password,
                        'protocol' => $device->protocol ?? 'http',
                        'timeout' => 30,
                        'verify_ssl' => false,
                    ];
                });
                
                // Optional: Provide a list of all available device names
                $provider->listUsing(function () {
                    return Device::pluck('name')->toArray();
                });
                
                // Optional: Provide the default device name
                $provider->defaultUsing(function () {
                    return Device::where('is_default', true)->value('name') ?? 'primary';
                });
            });
        });
    }
}
```

## Security

Please see the [SECURITY.md](SECURITY.md) file for our security policy and contact information.

---
**ZCHPC** | [hpc.ac.zw](https://hpc.ac.zw)
