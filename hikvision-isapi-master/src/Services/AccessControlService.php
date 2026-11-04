<?php

declare(strict_types=1);

namespace Shaykhnazar\HikvisionIsapi\Services;

use Shaykhnazar\HikvisionIsapi\Client\HikvisionClient;

class AccessControlService
{
    private const ENDPOINT_DOOR_CONTROL = '/ISAPI/AccessControl/RemoteControl/door';

    private const ENDPOINT_DOOR_STATUS = '/ISAPI/AccessControl/DoorStatus';

    public function __construct(
        private readonly HikvisionClient $client
    ) {}

    public function openDoor(int $doorNo): array
    {
        $data = [
            'RemoteControlDoor' => [
                'cmd' => 'open',
            ],
        ];

        // This ISAPI resource is XML-only on access-control terminals. Sending
        // the otherwise valid JSON envelope makes affected firmware report
        // `MessageParametersLack`, because it cannot read the `cmd` node.
        return $this->client->putXml(self::ENDPOINT_DOOR_CONTROL."/{$doorNo}", $data);
    }

    public function closeDoor(int $doorNo): array
    {
        $data = [
            'RemoteControlDoor' => [
                'cmd' => 'close',
            ],
        ];

        return $this->client->putXml(self::ENDPOINT_DOOR_CONTROL."/{$doorNo}", $data);
    }

    public function getDoorStatus(int $doorNo): array
    {
        return $this->client->get(self::ENDPOINT_DOOR_STATUS."/{$doorNo}");
    }
}
