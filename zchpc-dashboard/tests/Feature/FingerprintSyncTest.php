<?php

namespace Tests\Feature;

use App\Models\Door;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Services\DeviceClientFactory;
use App\Services\FingerprintSyncService;
use App\Services\PersonnelDeviceIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Shaykhnazar\HikvisionIsapi\Client\HikvisionClient;
use Tests\TestCase;

class FingerprintSyncTest extends TestCase
{
    use RefreshDatabase;

    private function setupTransfer(): array
    {
        $person = Personnel::create(['employee_no' => 'HPC100', 'name' => 'Test Person', 'valid_enabled' => true, 'user_type' => 'normal', 'begin_time' => now()->subDay(), 'end_time' => now()->addYear()]);
        $terminals = [];
        foreach (['Source', 'Target'] as $name) {
            $terminals[] = Terminal::create(['name' => $name, 'ip' => '192.0.2.'.(count($terminals) + 1), 'port' => 80, 'username' => 'test', 'password' => 'test', 'status' => 'online', 'last_checked_at' => now()]);
        }
        [$source, $target] = $terminals;
        $door = Door::create(['name' => 'Target Door', 'code' => 'TEST', 'terminal_id' => $target->id, 'door_no' => 1, 'is_active' => true]);
        $person->doors()->attach($door->id, ['plan_template_no' => '1']);
        $person->terminals()->attach($target->id, ['access_allowed' => true, 'sync_status' => 'confirmed']);
        PersonnelDeviceIdentity::link($person, $source, '190');
        $task = app(FingerprintSyncService::class)->queue($person, $source, '190', $target);

        return [$person, $source, $target, $task];
    }

    private function mockClients(Personnel $person): array
    {
        $source = \Mockery::mock(HikvisionClient::class);
        $target = \Mockery::mock(HikvisionClient::class);
        foreach ([[$source, '190'], [$target, $person->employee_no]] as [$client, $number]) {
            $client->shouldReceive('post')->with('/ISAPI/AccessControl/UserInfo/Search', \Mockery::any())->andReturn(['UserInfoSearch' => ['responseStatusStrg' => 'OK', 'UserInfo' => [['employeeNo' => $number, 'name' => $person->name, 'userType' => 'normal']]]]);
        }
        $factory = \Mockery::mock(DeviceClientFactory::class);
        $factory->shouldReceive('forTerminal')->with(\Mockery::on(fn ($t) => $t->name === 'Source'), 8)->andReturn($source);
        $factory->shouldReceive('forTerminal')->with(\Mockery::on(fn ($t) => $t->name === 'Target'), 8)->andReturn($target);
        $this->app->instance(DeviceClientFactory::class, $factory);

        return [$source, $target];
    }

    private function templates(string $data = 'dGVzdC10ZW1wbGF0ZQ=='): array
    {
        return ['FingerPrintInfo' => ['FingerPrintList' => [['fingerPrintID' => 5, 'fingerType' => 'normalFP', 'fingerData' => $data]]]];
    }

    public function test_copy_requires_exact_readback_and_never_persists_template_material(): void
    {
        [$person, , , $task] = $this->setupTransfer();
        [$source, $target] = $this->mockClients($person);
        $source->shouldReceive('post')->with('/ISAPI/AccessControl/FingerPrintUpload', \Mockery::any())->once()->andReturn($this->templates());
        $target->shouldReceive('post')->with('/ISAPI/AccessControl/FingerPrintUpload', \Mockery::any())->twice()->andReturn(['FingerPrintInfo' => ['FingerPrintList' => []]], $this->templates());
        $target->shouldReceive('post')->with('/ISAPI/AccessControl/FingerPrint/SetUp', \Mockery::on(fn ($payload) => $payload['FingerPrintCfg']['employeeNo'] === $person->employee_no && $payload['FingerPrintCfg']['fingerPrintID'] === 5))->once()->andReturn(['FingerPrintStatus' => ['StatusList' => [['cardReaderRecvStatus' => 1]]]]);
        $this->assertSame(['confirmed' => 1, 'pending' => 0, 'cancelled' => 0], app(FingerprintSyncService::class)->processDue());
        $this->assertSame([5], $task->fresh()->confirmed_slots);
        $this->assertStringNotContainsString('dGVzdC10ZW1wbGF0ZQ==', $task->fresh()->toJson());
        $this->assertSame(['confirmed' => 0, 'pending' => 0, 'cancelled' => 0], app(FingerprintSyncService::class)->processDue());
    }

    public function test_conflicting_destination_template_is_never_overwritten(): void
    {
        [$person, , , $task] = $this->setupTransfer();
        [$source, $target] = $this->mockClients($person);
        $source->shouldReceive('post')->with('/ISAPI/AccessControl/FingerPrintUpload', \Mockery::any())->andReturn($this->templates());
        $target->shouldReceive('post')->with('/ISAPI/AccessControl/FingerPrintUpload', \Mockery::any())->andReturn($this->templates('ZGlmZmVyZW50'));
        $target->shouldNotReceive('post')->with('/ISAPI/AccessControl/FingerPrint/SetUp', \Mockery::any());
        $this->assertSame(1, app(FingerprintSyncService::class)->processDue()['pending']);
        $this->assertStringContainsString('Existing fingerprints were retained', $task->fresh()->message);
    }

    public function test_success_acknowledgement_without_template_readback_stays_pending(): void
    {
        [$person, , , $task] = $this->setupTransfer();
        [$source, $target] = $this->mockClients($person);
        $source->shouldReceive('post')->with('/ISAPI/AccessControl/FingerPrintUpload', \Mockery::any())->andReturn($this->templates());
        $target->shouldReceive('post')->with('/ISAPI/AccessControl/FingerPrintUpload', \Mockery::any())->andReturn(['FingerPrintInfo' => ['FingerPrintList' => []]]);
        $target->shouldReceive('post')->with('/ISAPI/AccessControl/FingerPrint/SetUp', \Mockery::any())->andReturn(['statusCode' => 1]);
        $this->assertSame(1, app(FingerprintSyncService::class)->processDue()['pending']);
        $this->assertNull($task->fresh()->confirmed_at);
    }

    public function test_offline_waits_and_revoked_access_cancels_without_device_calls(): void
    {
        [$person, , $target, $task] = $this->setupTransfer();
        $factory = \Mockery::mock(DeviceClientFactory::class);
        $factory->shouldNotReceive('forTerminal');
        $this->app->instance(DeviceClientFactory::class, $factory);
        $target->update(['status' => 'offline']);
        $this->assertSame(1, app(FingerprintSyncService::class)->processDue()['pending']);
        $person->doors()->detach();
        $task->refresh()->update(['next_attempt_at' => now()]);
        $this->assertSame(1, app(FingerprintSyncService::class)->processDue()['cancelled']);
    }

    public function test_transport_response_data_is_not_written_to_the_queue(): void
    {
        [$person, , , $task] = $this->setupTransfer();
        [$source] = $this->mockClients($person);
        $source->shouldReceive('post')->with('/ISAPI/AccessControl/FingerPrintUpload', \Mockery::any())->andThrow(new \RuntimeException('sensitive-template-response'));
        $this->assertSame(1, app(FingerprintSyncService::class)->processDue()['pending']);
        $this->assertStringNotContainsString('sensitive-template-response', $task->fresh()->toJson());
    }
}
