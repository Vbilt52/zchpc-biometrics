<?php

namespace Tests\Feature;

use App\Models\AccessGroup;
use App\Models\PendingTerminalSync;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Models\User;
use App\Services\PendingTerminalSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;
use Shaykhnazar\HikvisionIsapi\Client\HikvisionClient;
use Tests\TestCase;

class PersonnelDeviceAccessTest extends TestCase
{
    use RefreshDatabase;

    private array $deviceRecords = [];

    private array $writes = [];

    private function person(): Personnel
    {
        return Personnel::create(['employee_no' => '0007', 'name' => 'Prince', 'user_type' => 'normal',
            'valid_enabled' => true, 'begin_time' => now()->subDay(), 'end_time' => now()->addYear(),
            'door_right' => '1', 'right_plan' => [['doorNo' => 1, 'planTemplateNo' => '1']],
            'access_group_id' => AccessGroup::first()->id]);
    }

    private function terminal(string $name): Terminal
    {
        return Terminal::create(['name' => $name, 'phase' => 'Phase 1', 'ip' => '192.0.2.1', 'username' => 'admin', 'password' => 'test']);
    }

    private function connect(array $terminals, bool $reject = false, bool $stale = false): void
    {
        $manager = Mockery::mock(DeviceManager::class);
        foreach ($terminals as $terminal) {
            $client = Mockery::mock(HikvisionClient::class);
            $manager->shouldReceive('device')->with($terminal->name)->andReturn($client);
            $client->shouldReceive('post')->with('/ISAPI/AccessControl/UserInfo/Search', Mockery::type('array'))
                ->andReturnUsing(function ($path, $payload) use ($terminal) {
                    $id = $payload['UserInfoSearchCond']['EmployeeNoList'][0]['employeeNo'];
                    $record = $this->deviceRecords[$terminal->id][$id] ?? null;

                    return ['UserInfoSearch' => ['responseStatusStrg' => $record ? 'OK' : 'NO MATCH',
                        'totalMatches' => $record ? 1 : 0, 'UserInfo' => $record ? [$record] : []]];
                });
            foreach (['put' => '/ISAPI/AccessControl/UserInfo/Modify', 'post' => '/ISAPI/AccessControl/UserInfo/Record'] as $method => $path) {
                $client->shouldReceive($method)->with($path, Mockery::type('array'))->andReturnUsing(function ($path, $payload) use ($terminal, $reject, $stale) {
                    $this->writes[] = ['terminal' => $terminal->id, 'data' => $payload['UserInfo']];
                    if ($reject) {
                        return ['statusCode' => 6, 'subStatusCode' => 'Invalid Content'];
                    }
                    if (! $stale) {
                        $id = $payload['UserInfo']['employeeNo'];
                        $this->deviceRecords[$terminal->id][$id] = array_replace_recursive($this->deviceRecords[$terminal->id][$id] ?? [], $payload['UserInfo']);
                    }

                    return ['statusCode' => 1, 'statusString' => 'OK'];
                });
            }
        }
        $this->app->instance(DeviceManager::class, $manager);
    }

    private function data(Personnel $person, array $ids): array
    {
        return ['employee_no' => $person->employee_no, 'name' => 'Prince Updated', 'user_type' => 'normal',
            'valid_enabled' => '1', 'begin_time' => '2026-10-01T08:00', 'end_time' => '2027-10-01T18:00',
            'added_at' => '2026-09-01T09:00', 'access_group_id' => $person->access_group_id,
            'phone_number' => '0771234567', 'door_right' => '1', 'terminal_ids' => $ids];
    }

    public function test_editing_four_device_assignments_updates_fields_and_revokes_unselected_devices(): void
    {
        $person = $this->person();
        $devices = array_map(fn ($name) => $this->terminal($name), ['Main Entrance', 'Back Entrance', 'Server Room', 'UPS Room']);
        foreach ($devices as $terminal) {
            $person->terminals()->attach($terminal->id);
            $this->deviceRecords[$terminal->id]['0007'] = ['employeeNo' => '0007', 'name' => 'Prince', 'userType' => 'normal'];
        }
        $this->connect($devices);
        $this->actingAs(User::factory()->create())->put(route('personnel.update', $person), $this->data($person, [$devices[0]->id]))
            ->assertRedirect(route('personnel.show', $person))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('pending_terminal_syncs', 0);
        $this->assertSame(1, $person->terminals()->wherePivot('access_allowed', true)->count());
        $this->assertSame('2026-10-01 06:00:00', $person->fresh()->begin_time->toDateTimeString());
        $this->assertSame('2026-09-01 07:00:00', $person->fresh()->added_at->toDateTimeString());
        $this->assertSame('Prince Updated', $this->deviceRecords[$devices[0]->id]['0007']['name']);
        $this->assertSame('2027-10-01T18:00:00', $this->deviceRecords[$devices[0]->id]['0007']['Valid']['endTime']);
        $this->assertSame('0771234567', $this->deviceRecords[$devices[0]->id]['0007']['phoneNumber']);
        foreach (array_slice($devices, 1) as $terminal) {
            $this->assertSame('blackList', $this->deviceRecords[$terminal->id]['0007']['userType']);
        }
        $this->get(route('personnel.show', $person))->assertOk()->assertSee('Assigned to 1 of 4 devices')->assertSee('Server Room')->assertSee('Not assigned');
        $this->get(route('personnel.edit', $person))->assertOk()->assertSee('2027-10-01T18:00')->assertSee('Date added');
        $this->get(route('personnel.index'))->assertOk()->assertSee('1 / 4 assigned');
        $this->get(route('personnel.create'))->assertOk()->assertSee('Date added');
    }

    public function test_partial_deletion_keeps_local_record_and_cannot_recreate_removed_device_profile(): void
    {
        $person = $this->person();
        $entrance = $this->terminal('Entrance');
        $server = $this->terminal('Server Room');
        $person->terminals()->attach([$entrance->id, $server->id]);
        $client = Mockery::mock(HikvisionClient::class);
        $client->shouldReceive('post')->with('/ISAPI/AccessControl/UserInfo/Search', Mockery::type('array'))->twice()->andReturn(
            ['UserInfoSearch' => ['responseStatusStrg' => 'OK', 'UserInfo' => [['employeeNo' => '0007']]]],
            ['UserInfoSearch' => ['responseStatusStrg' => 'NO MATCH', 'UserInfo' => []]],
        );
        $client->shouldReceive('put')->with('/ISAPI/AccessControl/UserInfo/Delete', Mockery::type('array'))->once()->andReturn(['statusCode' => 1]);
        $manager = Mockery::mock(DeviceManager::class);
        $manager->shouldReceive('device')->with('Entrance')->andReturn($client);
        $manager->shouldReceive('device')->with('Server Room')->andThrow(new \RuntimeException('Terminal offline'));
        $service = new PendingTerminalSyncService($manager);
        $service->queue($person, $entrance, 'Waiting');
        try {
            $service->remove($person);
            $this->fail('Offline device must prevent local deletion.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Terminal offline', $error->getMessage());
        }
        $this->assertDatabaseHas('personnel', ['id' => $person->id]);
        $this->assertDatabaseMissing('pending_terminal_syncs', ['personnel_id' => $person->id, 'terminal_id' => $entrance->id]);
        $this->assertFalse((bool) $person->terminals()->whereKey($entrance->id)->first()->pivot->access_allowed);
    }

    public function test_deletion_is_not_confirmed_when_the_device_retains_the_person(): void
    {
        $person = $this->person();
        $terminal = $this->terminal('Entrance');
        $person->terminals()->attach($terminal->id);
        $client = Mockery::mock(HikvisionClient::class);
        $client->shouldReceive('post')->twice()->andReturn(['UserInfoSearch' => ['responseStatusStrg' => 'OK', 'UserInfo' => [['employeeNo' => '0007']]]]);
        $client->shouldReceive('put')->once()->andReturn(['statusCode' => 1]);
        $manager = Mockery::mock(DeviceManager::class);
        $manager->shouldReceive('device')->andReturn($client);
        $this->app->instance(DeviceManager::class, $manager);
        $this->actingAs(User::factory()->create())->delete(route('personnel.destroy', $person))->assertSessionHas('error');
        $this->assertDatabaseHas('personnel', ['id' => $person->id]);
    }

    public function test_offline_edits_persist_and_later_retry_cannot_restore_a_revoked_assignment(): void
    {
        $person = $this->person();
        $terminal = $this->terminal('Server Room');
        $person->terminals()->attach($terminal->id);
        $manager = Mockery::mock(DeviceManager::class);
        $manager->shouldReceive('device')->andThrow(new \RuntimeException('Terminal offline'));
        $service = new PendingTerminalSyncService($manager);
        $service->save($person, ['name' => 'Prince Pending'], [$terminal->id]);
        $service->save($person, ['name' => 'Prince Latest'], []);
        $this->assertDatabaseHas('pending_terminal_syncs', ['operation' => 'revoke']);
        $this->assertSame('Prince Latest', $person->fresh()->name);
        $this->deviceRecords[$terminal->id]['0007'] = ['employeeNo' => '0007', 'userType' => 'normal'];
        $this->connect([$terminal]);
        PendingTerminalSync::query()->update(['next_attempt_at' => now()->subMinute()]);
        $result = $this->app->make(PendingTerminalSyncService::class)->processDue();
        $this->assertSame(1, $result['synced']);
        $this->assertSame('blackList', $this->deviceRecords[$terminal->id]['0007']['userType']);
        $this->assertDatabaseCount('pending_terminal_syncs', 0);
    }

    public function test_bulk_sync_preserves_device_restrictions(): void
    {
        $person = $this->person();
        $entrance = $this->terminal('Main Entrance');
        $server = $this->terminal('Server Room');
        $person->terminals()->attach($entrance->id);
        $this->connect([$entrance]); // Any attempt to contact Server Room fails the test.
        $this->actingAs(User::factory()->create())->post(route('personnel.sync.all'))->assertRedirect();
        $this->assertCount(1, $this->writes);
        $this->assertFalse($person->terminals()->whereKey($server->id)->exists());
    }

    public function test_device_rejection_and_stale_readback_are_not_reported_as_confirmed(): void
    {
        $person = $this->person();
        $terminal = $this->terminal('Main Entrance');
        $this->connect([$terminal], reject: true);
        $result = $this->app->make(PendingTerminalSyncService::class)->save($person, [], [$terminal->id]);
        $this->assertSame(1, $result['pending']);
        $this->assertSame('pending', $person->terminals()->first()->pivot->sync_status);
        $this->deviceRecords[$terminal->id]['0007'] = ['employeeNo' => '0007', 'name' => 'Old name', 'userType' => 'normal'];
        $this->connect([$terminal], stale: true);
        $result = $this->app->make(PendingTerminalSyncService::class)->retry($person);
        $this->assertSame(0, $result['synced']);
        $this->assertSame(1, $result['pending']);
    }

    public function test_disabling_access_sets_blacklist_instead_of_disabling_expiry_checks(): void
    {
        $person = $this->person();
        $terminal = $this->terminal('Main Entrance');
        $this->deviceRecords[$terminal->id]['0007'] = ['employeeNo' => '0007', 'userType' => 'normal'];
        $this->connect([$terminal]);
        $result = $this->app->make(PendingTerminalSyncService::class)->save($person, ['valid_enabled' => false], [$terminal->id]);
        $this->assertSame(1, $result['synced']);
        $this->assertSame('blackList', $this->deviceRecords[$terminal->id]['0007']['userType']);
        $this->assertTrue($this->deviceRecords[$terminal->id]['0007']['Valid']['enable']);
    }

    public function test_invalid_dates_and_linked_employee_id_changes_do_not_modify_records(): void
    {
        $person = $this->person();
        $terminal = $this->terminal('Entrance');
        $person->terminals()->attach($terminal->id);
        $this->actingAs(User::factory()->create());
        $this->put(route('personnel.update', $person), array_replace($this->data($person, []), ['end_time' => '2025-01-01T00:00']))->assertSessionHasErrors('end_time');
        $this->put(route('personnel.update', $person), array_replace($this->data($person, []), ['employee_no' => '0008']))->assertSessionHasErrors('employee_no');
        $this->assertSame('0007', $person->fresh()->employee_no);
        $this->assertSame('Prince', $person->fresh()->name);
        $this->assertDatabaseCount('pending_terminal_syncs', 0);
    }

    public function test_enrollment_endpoints_cannot_grant_access_to_an_unassigned_device(): void
    {
        $person = $this->person();
        $terminal = $this->terminal('Server Room');
        $this->app->instance(DeviceManager::class, Mockery::mock(DeviceManager::class));
        $this->actingAs(User::factory()->create());
        $this->postJson(route('personnel.prime.enrollment'), ['employee_no' => '0007', 'terminal_id' => $terminal->id])->assertStatus(422);
        $this->post(route('personnel.enroll.fingerprint', $person), ['terminal_id' => $terminal->id])->assertSessionHas('error');
        $this->post(route('super-fingerprints.enroll'), ['personnel_id' => $person->id, 'terminal_id' => $terminal->id, 'finger_id' => 1])->assertSessionHas('error');
        $this->assertFalse($person->terminals()->exists());
    }

    public function test_direct_sync_refreshes_stale_profile_before_writing_to_a_device(): void
    {
        $person = $this->person();
        $terminal = $this->terminal('Entrance');
        $person->terminals()->attach($terminal->id);
        $this->deviceRecords[$terminal->id]['0007'] = ['employeeNo' => '0007', 'userType' => 'normal'];
        $this->connect([$terminal]);
        Personnel::whereKey($person->id)->update(['valid_enabled' => false]);
        $this->app->make(PendingTerminalSyncService::class)->upsert($person, $terminal);
        $this->assertSame('blackList', $this->deviceRecords[$terminal->id]['0007']['userType']);
    }

    public function test_missing_imported_dates_remain_pending_without_writing_invalid_device_dates(): void
    {
        $person = $this->person();
        $person->update(['begin_time' => null, 'end_time' => null]);
        $terminal = $this->terminal('Entrance');
        $person->terminals()->attach($terminal->id);
        $this->connect([$terminal]);
        $result = $this->app->make(PendingTerminalSyncService::class)->retry($person);
        $this->assertSame(1, $result['pending']);
        $this->assertSame([], $this->writes);
    }

    public function test_import_cannot_restore_access_or_overwrite_unsent_local_edits(): void
    {
        $person = $this->person();
        $terminal = $this->terminal('Server Room');
        $person->terminals()->attach($terminal->id, ['access_allowed' => false, 'sync_status' => 'pending']);
        $client = Mockery::mock(HikvisionClient::class);
        $client->shouldReceive('post')->once()->andReturn(['UserInfoSearch' => ['responseStatusStrg' => 'OK', 'UserInfo' => [[
            'employeeNo' => '0007', 'name' => 'Stale name', 'userType' => 'blackList', 'Valid' => ['enable' => true],
        ]]]]);
        $manager = Mockery::mock(DeviceManager::class);
        $manager->shouldReceive('device')->andReturn($client);
        $this->app->instance(DeviceManager::class, $manager);
        $this->actingAs(User::factory()->create())->post(route('personnel.import', $terminal))->assertSessionHas('success');
        $this->assertSame('Prince', $person->fresh()->name);
        $this->assertFalse((bool) $person->terminals()->first()->pivot->access_allowed);
        $this->assertSame('pending', $person->terminals()->first()->pivot->sync_status);
    }
}
