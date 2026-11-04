<?php

namespace App\Services;

class DeviceInfoValidator
{
    public static function validate(array $response): array
    {
        $info = $response['DeviceInfo'] ?? $response;
        if (! is_array($info) || isset($response['ResponseStatus']) || isset($info['statusCode'])
            || ! is_string($info['model'] ?? null) || trim($info['model']) === '') {
            throw new \RuntimeException('Unrecognized device information response.');
        }

        return array_intersect_key($info, array_flip(['model', 'deviceName', 'deviceType', 'firmwareVersion', 'serialNumber']));
    }
}
