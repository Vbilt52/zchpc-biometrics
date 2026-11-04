<?php

declare(strict_types=1);

namespace Shaykhnazar\HikvisionIsapi\Tests\Unit\Services;

use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Shaykhnazar\HikvisionIsapi\Client\HikvisionClient;
use Shaykhnazar\HikvisionIsapi\Services\AccessControlService;

class AccessControlServiceTest extends TestCase
{
    /** @var HikvisionClient&MockInterface */
    private HikvisionClient $client;

    private AccessControlService $service;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var HikvisionClient&MockInterface $client */
        $client = Mockery::mock(HikvisionClient::class);

        $this->client = $client;
        $this->service = new AccessControlService($this->client);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_open_door_sends_the_xml_remote_control_command(): void
    {
        $this->client
            ->shouldReceive('putXml')
            ->once()
            ->with('/ISAPI/AccessControl/RemoteControl/door/1', [
                'RemoteControlDoor' => [
                    'cmd' => 'open',
                ],
            ])
            ->andReturn(['statusCode' => 1]);

        $response = $this->service->openDoor(1);

        $this->assertSame(['statusCode' => 1], $response);
    }

    public function test_close_door_sends_the_xml_remote_control_command(): void
    {
        $this->client
            ->shouldReceive('putXml')
            ->once()
            ->with('/ISAPI/AccessControl/RemoteControl/door/1', [
                'RemoteControlDoor' => [
                    'cmd' => 'close',
                ],
            ])
            ->andReturn(['statusCode' => 1]);

        $response = $this->service->closeDoor(1);

        $this->assertSame(['statusCode' => 1], $response);
    }
}
