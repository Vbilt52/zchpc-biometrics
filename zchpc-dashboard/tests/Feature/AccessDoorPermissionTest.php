<?php

namespace Tests\Feature;

use App\Models\AccessGroup;
use App\Models\DeviceSyncEvent;
use App\Models\Door;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Models\User;
use App\Services\DoorAccessService;
use App\Services\PendingTerminalSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;
use Shaykhnazar\HikvisionIsapi\Client\HikvisionClient;
use Tests\TestCase;

class AccessDoorPermissionTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<string, array<string, mixed>>> */
    private array $deviceRecords = [];

    /** @var list<array{terminal: int, data: array<string, mixed>}> */
    private array $writes = [];

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function terminal(string $name, string $status = 'online'): Terminal
    {
        return Terminal::create([
            'name' => $name, 'phase' => 'Phase 1', 'ip' => '192.0.2.'.random_int(10, 250),
            'port' => 80, 'username' => 'admin', 'password' => 'test', 'protocol' => 'http',
            'timeout' => 5, 'verify_ssl' => false, 'status' => $status,
        ]);
    }

    private function person(array $overrides = []): Personnel
    {
        return Personnel::create(array_merge([
            'employee_no' => 'EMP-01', 'name' => 'Prince', 'user_type' => 'normal',
            'valid_enabled' => true, 'begin_time' => now()->subDay(), 'end_time' => now()->addYear(),
            'door_right' => '1', 'right_plan' => [['doorNo' => 1, 'planTemplateNo' => '1']],
            'access_group_id' => AccessGroup::first()->id, 'status' => Personnel::STATUS_ACTIVE,
        ], $overrides));
    }

    private function door(Terminal $terminal, int $channel, string $name): Door
    {
        return Door::create([
            'name' => $name, 'code' => 'D-'.strtoupper(substr(md5($name.$terminal->id.$channel), 0, 6)),
            'building' => 'HQ', 'floor' => 'Ground floor', 'zone' => 'Test zone',
            'terminal_id' => $terminal->id, 'door_no' => $channel, 'plan_template_no' => '1',
            'is_active' => true, 'sort_order' => 1,
        ]);
    }

    /** @param list<Terminal> $online @param list<Terminal> $offline */
    private function connect(array $online, array $offline = []): void
    {
        $manager = Mockery::mock(DeviceManager::class);

        foreach ($offline as $terminal) {
            $manager->shouldReceive('device')->with($terminal->name)->andThrow(new \RuntimeException('Connection refused'));
        }

        foreach ($online as $terminal) {
            $client = Mockery::mock(HikvisionClient::class);

            $client->shouldReceive('post')
                ->with('/ISAPI/AccessControl/UserInfo/Search', Mockery::type('array'))
                ->andReturnUsing(function (string $path, array $payload) use ($terminal): array {
                    $id = $payload['UserInfoSearchCond']['EmployeeNoList'][0]['employeeNo'];
                    $record = $this->deviceRecords[$terminal->id][$id] ?? null;

                    return ['UserInfoSearch' => [
                        'responseStatusStrg' => $record ? 'OK' : 'NO MATCH',
                        'totalMatches' => $record ? 1 : 0,
                        'UserInfo' => $record ? [$record] : [],
                    ]];
                });

            $client->shouldReceive('post')
                ->with('/ISAPI/AccessControl/UserInfo/Record', Mockery::type('array'))
                ->andReturnUsing(fn (string $path, array $payload): array => $this->write($terminal, $payload));

            $client->shouldReceive('put')
                ->with('/ISAPI/AccessControl/UserInfo/Modify', Mockery::type('array'))
                ->andReturnUsing(fn (string $path, array $payload): array => $this->write($terminal, $payload));

            $manager->shouldReceive('device')->with($terminal->name)->andReturn($client);
        }

        $this->app->instance(DeviceManager::class, $manager);
        $this->app->forgetInstance(PendingTerminalSyncService::class);
        $this->app->forgetInstance(DoorAccessService::class);
    }

    private function write(Terminal $terminal, array $payload): array
    {
        $data = $payload['UserInfo'];
        $this->writes[] = ['terminal' => $terminal->id, 'data' => $data];
        $existing = $this->deviceRecords[$terminal->id][$data['employeeNo']] ?? [];
        $this->deviceRecords[$terminal->id][$data['employeeNo']] = array_merge($existing, $data);

        return ['statusCode' => 1, 'statusString' => 'OK'];
    }

    /** @return list<array<string, mixed>> */
    private function writesFor(Terminal $terminal): array
    {
        return array_values(array_map(
            fn (array $write): array => $write['data'],
            array_filter($this->writes, fn (array $write): bool => $write['terminal'] === $terminal->id),
        ));
    }

    public function test_granting_a_door_writes_only_its_controller_and_updates_its_plan(): void
    {
        $admin = User::factory()->create();
        $main = $this->terminal('Main Entrance');
        $server = $this->terminal('Server Room');
        $person = $this->person();
        $mainDoor = $this->door($main, 1, 'Main Entrance');
        $serverDoorA = $this->door($server, 1, 'Server Room');
        $serverDoorB = $this->door($server, 2, 'Server Cage');

        $this->connect([$main, $server]);
        $access = $this->app->make(DoorAccessService::class);

        $result = $access->grant($person, [$mainDoor->id], $admin);
        $this->assertSame(1, $result['synced']);
        $this->assertSame(0, $result['pending']);
        $mainWrites = $this->writesFor($main);
        $this->assertCount(1, $mainWrites);
        $this->assertSame('1', $mainWrites[0]['doorRight']);
        $this->assertSame([['doorNo' => 1, 'planTemplateNo' => '1']], $mainWrites[0]['RightPlan']);

        // A door on another controller must not cause a write to the first one.
        $access->grant($person, [$serverDoorA->id], $admin);
        $this->assertCount(1, $this->writesFor($main));
        $this->assertCount(1, $this->writesFor($server));

        $access->grant($person, [$serverDoorB->id], $admin);
        $this->assertCount(1, $this->writesFor($main));
        $serverWrites = $this->writesFor($server);
        $this->assertCount(2, $serverWrites);
        $this->assertSame('1,2', $serverWrites[1]['doorRight']);
        $this->assertSame([1, 2], array_column($serverWrites[1]['RightPlan'], 'doorNo'));
        $this->assertSame(3, $person->doors()->count());
        $this->assertTrue((bool) $person->terminals()->whereKey($server->id)->first()->pivot->access_allowed);
    }

    public function test_revoking_one_door_keeps_the_other_and_the_last_door_revokes_the_controller(): void
    {
        $admin = User::factory()->create();
        $terminal = $this->terminal('Server Room');
        $person = $this->person();
        $doorA = $this->door($terminal, 1, 'Server Room');
        $doorB = $this->door($terminal, 2, 'Server Cage');

        $this->connect([$terminal]);
        $access = $this->app->make(DoorAccessService::class);
        $access->grant($person, [$doorA->id, $doorB->id], $admin);

        $access->revoke($person, [$doorA->id], $admin);
        $writes = $this->writesFor($terminal);
        $afterFirstRevoke = $writes[count($writes) - 1];
        $this->assertSame('2', $afterFirstRevoke['doorRight']);
        $this->assertSame([2], array_column($afterFirstRevoke['RightPlan'], 'doorNo'));
        $this->assertTrue((bool) $person->terminals()->whereKey($terminal->id)->first()->pivot->access_allowed);
        $this->assertSame(1, $person->doors()->count());

        $access->revoke($person, [$doorB->id], $admin);
        $writes = $this->writesFor($terminal);
        $afterLastRevoke = $writes[count($writes) - 1];
        $this->assertSame('blackList', $afterLastRevoke['userType']);
        $this->assertArrayNotHasKey('RightPlan', $afterLastRevoke);
        $this->assertFalse((bool) $person->terminals()->whereKey($terminal->id)->first()->pivot->access_allowed);
        $this->assertSame(0, $person->doors()->count());
    }

    public function test_an_offline_controller_stays_pending_and_is_confirmed_after_retry(): void
    {
        $admin = User::factory()->create();
        $terminal = $this->terminal('Server Room', 'offline');
        $person = $this->person();
        $door = $this->door($terminal, 1, 'Server Room');

        $this->connect([], [$terminal]);
        $result = $this->app->make(DoorAccessService::class)->grant($person, [$door->id], $admin);

        $this->assertSame(0, $result['synced']);
        $this->assertSame(1, $result['pending']);
        $this->assertDatabaseHas('pending_terminal_syncs', ['personnel_id' => $person->id, 'terminal_id' => $terminal->id]);
        $this->assertSame('pending', $person->terminals()->whereKey($terminal->id)->first()->pivot->sync_status);
        $this->assertSame(1, $person->doors()->count());

        $states = $this->app->make(DoorAccessService::class)->controllerStates($person);
        $this->assertSame('Pending synchronization', $states[0]['label']);
        $this->assertDatabaseHas('device_sync_events', [
            'personnel_id' => $person->id, 'terminal_id' => $terminal->id, 'status' => DeviceSyncEvent::STATUS_FAILED,
        ]);

        // The controller comes back: a manual sync confirms the queued grant.
        $this->connect([$terminal]);
        $retry = $this->app->make(DoorAccessService::class)->syncPersonnel($person, $admin);

        $this->assertSame(1, $retry['synced']);
        $this->assertSame(0, $retry['pending']);
        $this->assertDatabaseMissing('pending_terminal_syncs', ['personnel_id' => $person->id, 'terminal_id' => $terminal->id]);
        $this->assertSame('confirmed', $person->terminals()->whereKey($terminal->id)->first()->pivot->sync_status);
        $this->assertSame('normal', $this->deviceRecords[$terminal->id]['EMP-01']['userType']);
        $this->assertDatabaseHas('device_sync_events', [
            'personnel_id' => $person->id, 'terminal_id' => $terminal->id, 'status' => DeviceSyncEvent::STATUS_SUCCESS,
        ]);
    }

    public function test_suspending_and_reactivating_preserves_door_grants(): void
    {
        $admin = User::factory()->create();
        $terminal = $this->terminal('Main Entrance');
        $person = $this->person();
        $door = $this->door($terminal, 1, 'Main Entrance');

        $this->connect([$terminal]);
        $access = $this->app->make(DoorAccessService::class);
        $access->grant($person, [$door->id], $admin);

        $access->suspend($person, $admin);
        $writes = $this->writesFor($terminal);
        $this->assertSame('blackList', $writes[count($writes) - 1]['userType']);
        $this->assertSame(Personnel::STATUS_SUSPENDED, $person->fresh()->status);
        $this->assertSame(1, $person->doors()->count(), 'Suspension must retain the door grant for reactivation.');
        $this->assertFalse((bool) $person->terminals()->whereKey($terminal->id)->first()->pivot->access_allowed);

        $access->reactivate($person, $admin);
        $writes = $this->writesFor($terminal);
        $last = $writes[count($writes) - 1];
        $this->assertSame('normal', $last['userType']);
        $this->assertSame('1', $last['doorRight']);
        $this->assertSame(Personnel::STATUS_ACTIVE, $person->fresh()->status);
        $this->assertSame(1, $person->doors()->count());
        $this->assertTrue((bool) $person->terminals()->whereKey($terminal->id)->first()->pivot->access_allowed);
    }

    public function test_operators_can_view_but_cannot_change_permissions(): void
    {
        $operator = User::factory()->create(['role' => User::ROLE_OPERATOR]);
        $terminal = $this->terminal('Main Entrance');
        $person = $this->person();
        $door = $this->door($terminal, 1, 'Main Entrance');

        $this->actingAs($operator)->get(route('access.index'))->assertOk();
        $this->actingAs($operator)->get(route('access.matrix.index'))->assertOk();
        $this->actingAs($operator)->get(route('access.users.show', $person))->assertOk();
        $this->actingAs($operator)->get(route('access.audit.index'))->assertForbidden();

        $this->actingAs($operator)->post(route('access.users.doors.grant', $person), [
            'door_ids' => [$door->id], 'confirm' => 1,
        ])->assertForbidden();

        $this->actingAs($operator)->post(route('access.users.suspend', $person), ['confirm' => 1])->assertForbidden();
        $this->assertSame(0, $person->doors()->count());

        auth()->logout();
        $this->get(route('access.index'))->assertRedirect(route('login'));
    }
}
