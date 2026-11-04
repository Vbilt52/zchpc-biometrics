<?php

namespace Tests\Feature;

use App\Models\PendingTerminalSync;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Services\PendingTerminalSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;
use Shaykhnazar\HikvisionIsapi\Client\HikvisionClient;
use Tests\TestCase;

class PendingTerminalSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_an_offline_terminal_sync_is_queued_and_retried_when_it_comes_online(): void
    {
        $terminal = Terminal::create([
            'name' => 'Offline Terminal', 'phase' => 'Phase 1', 'ip' => '192.168.60.99',
            'port' => 80, 'username' => 'admin', 'password' => 'secret', 'protocol' => 'http',
            'timeout' => 5, 'verify_ssl' => false,
        ]);
        $person = Personnel::create([
            'employee_no' => 'EMP-101', 'name' => 'Retry Person', 'user_type' => 'normal',
            'valid_enabled' => true, 'begin_time' => now()->subDay(), 'end_time' => now()->addYear(),
            'door_right' => '1', 'right_plan' => [['doorNo' => 1, 'planTemplateNo' => '1']],
        ]);

        $offlineManager = Mockery::mock(DeviceManager::class);
        $person->terminals()->attach($terminal->id);
        $offlineManager->shouldReceive('device')->once()->with($terminal->name)->andThrow(new \RuntimeException('Connection refused'));
        $offline = new PendingTerminalSyncService($offlineManager);

        try {
            $offline->upsert($person, $terminal);
            $this->fail('Expected offline terminal sync to fail.');
        } catch (\Throwable $error) {
            $offline->queue($person, $terminal, $error->getMessage());
        }

        $this->assertDatabaseHas('pending_terminal_syncs', [
            'terminal_id' => $terminal->id,
            'personnel_id' => $person->id,
            'operation' => 'upsert',
        ]);

        PendingTerminalSync::query()->update(['next_attempt_at' => now()->subSecond()]);

        $client = Mockery::mock(HikvisionClient::class);
        $client->shouldReceive('post')->twice()->with('/ISAPI/AccessControl/UserInfo/Search', Mockery::type('array'))
            ->andReturn(['UserInfoSearch' => ['responseStatusStrg' => 'OK', 'UserInfo' => [[
                'employeeNo' => $person->employee_no, 'name' => $person->name, 'userType' => 'normal',
                'phoneNumber' => '', 'doorRight' => '1', 'RightPlan' => [['doorNo' => 1, 'planTemplateNo' => '1']],
                'Valid' => ['enable' => true, 'timeType' => 'local',
                    'beginTime' => $person->begin_time->copy()->timezone(config('attendance.device_timezone'))->format('Y-m-d\TH:i:s'),
                    'endTime' => $person->end_time->copy()->timezone(config('attendance.device_timezone'))->format('Y-m-d\TH:i:s')],
            ]]]]);
        $client->shouldReceive('put')->once()->with('/ISAPI/AccessControl/UserInfo/Modify', Mockery::type('array'))->andReturn([]);
        $onlineManager = Mockery::mock(DeviceManager::class);
        $onlineManager->shouldReceive('device')->once()->with($terminal->name)->andReturn($client);

        $result = (new PendingTerminalSyncService($onlineManager))->processDue();

        $this->assertSame(1, $result['synced']);
        $this->assertSame(0, PendingTerminalSync::count());
        $this->assertTrue($person->terminals()->whereKey($terminal->id)->exists());
    }
}
