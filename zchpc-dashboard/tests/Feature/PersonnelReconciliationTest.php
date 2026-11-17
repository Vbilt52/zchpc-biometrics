<?php

namespace Tests\Feature;

use App\Models\FingerprintEnrollment;
use App\Models\PendingTerminalSync;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Models\User;
use App\Rules\PersonnelRules;
use App\Services\PendingTerminalSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Mockery;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;
use Shaykhnazar\HikvisionIsapi\Client\HikvisionClient;
use Tests\TestCase;

class PersonnelReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $id = '001', string $name = 'Prince'): Personnel
    {
        return Personnel::create(['employee_no' => $id, 'name' => $name, 'valid_enabled' => true, 'user_type' => 'normal']);
    }

    private function terminal(): Terminal
    {
        return Terminal::create(['name' => 'Entrance', 'ip' => '192.0.2.1', 'phase' => 'Phase 1', 'username' => 'admin', 'password' => 'test']);
    }

    private function withCsv(array $rows, callable $callback): void
    {
        $path = tempnam(sys_get_temp_dir(), 'names-');
        $handle = fopen($path, 'w');
        fputcsv($handle, ['employee_no', 'expected_name', 'name']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
        try {
            $callback($path);
        } finally {
            unlink($path);
        }
    }

    public function test_preview_apply_and_repeat_preserve_users_ids_permissions_and_existing_pending_revocation(): void
    {
        $person = $this->person();
        $other = $this->person('002', 'Other Person');
        $terminal = $this->terminal();
        $person->terminals()->attach($terminal->id, ['access_allowed' => false, 'sync_status' => 'pending']);
        PendingTerminalSync::create(['personnel_id' => $person->id, 'terminal_id' => $terminal->id, 'operation' => 'revoke', 'next_attempt_at' => now()]);
        $this->withCsv([['001', 'Prince', 'Prince Sikwehle']], function ($path) use ($person, $other): void {
            $this->artisan('personnel:reconcile-names', ['file' => $path])->assertSuccessful();
            $this->assertSame('Prince', $person->fresh()->name);
            $this->artisan('personnel:reconcile-names', ['file' => $path, '--apply' => true])->assertSuccessful();
            $this->artisan('personnel:reconcile-names', ['file' => $path, '--apply' => true])->assertSuccessful();
            $this->assertSame('Prince Sikwehle', $person->fresh()->name);
            $this->assertSame('001', $person->fresh()->employee_no);
            $this->assertSame('Other Person', $other->fresh()->name);
            $this->assertDatabaseCount('personnel', 2);
            $this->assertDatabaseCount('access_audit_logs', 1);
            $this->assertDatabaseHas('pending_terminal_syncs', ['operation' => 'revoke']);
            $this->assertFalse((bool) $person->terminals()->first()->pivot->access_allowed);
        });
    }

    public function test_a_conflicting_name_rolls_back_the_entire_batch(): void
    {
        $person = $this->person();
        $this->person('002', 'Changed elsewhere');
        $this->withCsv([['001', 'Prince', 'Prince Sikwehle'], ['002', 'Old name', 'Correct Name']], function ($path) use ($person): void {
            $this->artisan('personnel:reconcile-names', ['file' => $path, '--apply' => true])->assertFailed();
            $this->assertSame('Prince', $person->fresh()->name);
            $this->assertDatabaseCount('access_audit_logs', 0);
            $this->assertDatabaseCount('pending_terminal_syncs', 0);
        });
    }

    public function test_unknown_duplicate_and_removed_ids_cannot_be_reconciled(): void
    {
        $person = $this->person();
        foreach ([[['999', 'Unknown', 'New Name']], [['001', 'Prince', 'New Name'], ['001', 'Prince', 'Another Name']]] as $rows) {
            $this->withCsv($rows, fn ($path) => $this->artisan('personnel:reconcile-names', ['file' => $path, '--apply' => true])->assertFailed());
        }
        $person->delete();
        $this->withCsv([['001', 'Prince', 'New Name']], fn ($path) => $this->artisan('personnel:reconcile-names', ['file' => $path, '--apply' => true])->assertFailed());
        $this->assertSame('Prince', Personnel::withTrashed()->first()->name);
        $this->assertDatabaseCount('personnel', 1);
    }

    public function test_name_sync_writes_only_name_and_does_not_claim_permissions_are_confirmed(): void
    {
        $person = $this->person();
        $terminal = $this->terminal();
        $person->terminals()->attach($terminal->id, ['access_allowed' => true, 'sync_status' => 'imported']);
        $this->withCsv([['001', 'Prince', 'Prince Sikwehle']], fn ($path) => $this->artisan('personnel:reconcile-names', ['file' => $path, '--apply' => true])->assertSuccessful());
        $client = Mockery::mock(HikvisionClient::class);
        $client->shouldReceive('post')->twice()->andReturn(
            ['UserInfoSearch' => ['responseStatusStrg' => 'OK', 'UserInfo' => [['employeeNo' => '001', 'name' => 'Prince']]]],
            ['UserInfoSearch' => ['responseStatusStrg' => 'OK', 'UserInfo' => [['employeeNo' => '001', 'name' => 'Prince Sikwehle']]]],
        );
        $client->shouldReceive('put')->with('/ISAPI/AccessControl/UserInfo/Modify', ['UserInfo' => ['employeeNo' => '001', 'name' => 'Prince Sikwehle']])->once()->andReturn(['statusCode' => 1]);
        $manager = Mockery::mock(DeviceManager::class);
        $manager->shouldReceive('device')->andReturn($client);
        $this->assertSame(['synced' => 1, 'pending' => 0], (new PendingTerminalSyncService($manager))->processDue());
        $this->assertSame('imported', $person->terminals()->first()->pivot->sync_status);
    }

    public function test_removed_identity_cannot_be_reimported_or_reassigned_and_id_stays_reserved(): void
    {
        $person = $this->person();
        $terminal = $this->terminal();
        $manager = Mockery::mock(DeviceManager::class);
        $client = Mockery::mock(HikvisionClient::class);
        $client->shouldReceive('post')->once()->andReturn(['UserInfoSearch' => ['responseStatusStrg' => 'OK', 'UserInfo' => [['employeeNo' => '001', 'name' => 'Old name', 'userType' => 'normal']]]]);
        $manager->shouldReceive('device')->andReturn($client);
        $this->app->instance(DeviceManager::class, $manager);
        $service = new PendingTerminalSyncService($manager);
        $service->remove($person);
        $this->actingAs(User::factory()->create())->post(route('personnel.import', $terminal))->assertSessionHas('success');
        $this->assertSoftDeleted('personnel', ['id' => $person->id]);
        $this->assertDatabaseHas('pending_terminal_syncs', ['operation' => 'delete']);
        $this->assertTrue(Validator::make(['employee_no' => '001'], ['employee_no' => PersonnelRules::employeeNo()])->fails());
        $this->expectException(ValidationException::class);
        $service->save($person, ['name' => 'Recreated'], [$terminal->id]);
    }

    public function test_terminal_with_pending_removal_cannot_be_deleted(): void
    {
        $person = $this->person();
        $terminal = $this->terminal();
        PendingTerminalSync::create(['personnel_id' => $person->id, 'terminal_id' => $terminal->id, 'operation' => 'delete', 'next_attempt_at' => now()]);
        $this->actingAs(User::factory()->create())->delete(route('terminals.destroy', $terminal))->assertSessionHas('error');
        $this->assertDatabaseHas('terminals', ['id' => $terminal->id]);
    }

    public function test_removal_includes_enrollment_only_devices_and_remains_visible_in_sync_queue(): void
    {
        $person = $this->person();
        $terminal = $this->terminal();
        FingerprintEnrollment::create(['personnel_id' => $person->id, 'employee_no' => '001',
            'person_name' => 'Prince', 'terminal_id' => $terminal->id, 'terminal_name' => 'Entrance',
            'finger_id' => 1, 'status' => 'confirmed']);
        $service = new PendingTerminalSyncService(Mockery::mock(DeviceManager::class));
        $service->remove($person);
        $this->assertDatabaseHas('pending_terminal_syncs', ['personnel_id' => $person->id, 'operation' => 'delete']);
        $this->actingAs(User::factory()->create())->get(route('access.sync.index'))->assertOk()->assertSee('Removed locally')->assertSee('Prince');
    }

    public function test_shared_rules_reject_invalid_names_phone_ids_and_unsupported_device_dates(): void
    {
        $rules = ['name' => PersonnelRules::name(), 'employee_no' => PersonnelRules::employeeNo(), 'phone' => PersonnelRules::phone(), 'begin_time' => PersonnelRules::begin(), 'end_time' => PersonnelRules::end()];
        $valid = ['name' => "Anne-Marie O'Neill", 'employee_no' => '001', 'phone' => '+263 771 234 567', 'begin_time' => '2026-01-01', 'end_time' => '2027-01-01'];
        $this->assertTrue(Validator::make($valid, $rules)->passes());
        foreach (['name' => '<script>alert(1)</script>', 'employee_no' => '../001', 'phone' => 'call me', 'begin_time' => '1999-01-01', 'end_time' => '2038-01-01'] as $field => $value) {
            $this->assertTrue(Validator::make(array_replace($valid, [$field => $value]), $rules)->fails(), $field);
        }
    }
}
