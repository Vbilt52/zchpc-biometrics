<?php

namespace Tests\Feature;

use App\Jobs\ProcessAccessSyncJob;
use App\Models\AccessGroup;
use App\Models\Door;
use App\Models\PendingTerminalSync;
use App\Models\Personnel;
use App\Models\Terminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExpiredDoorGrantTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_grants_are_removed_and_the_revocation_is_queued(): void
    {
        Queue::fake();

        $terminal = Terminal::create([
            'name' => 'Main Entrance', 'phase' => 'Phase 1', 'ip' => '192.0.2.90', 'port' => 80,
            'username' => 'admin', 'password' => 'test', 'protocol' => 'http', 'timeout' => 5,
            'verify_ssl' => false, 'status' => 'online',
        ]);
        $door = Door::create([
            'name' => 'Server Room', 'code' => 'D-SRV', 'building' => 'HQ', 'floor' => 'First',
            'terminal_id' => $terminal->id, 'door_no' => 2, 'plan_template_no' => '1',
            'is_active' => true, 'sort_order' => 1,
        ]);
        $person = Personnel::create([
            'employee_no' => 'EMP-910', 'name' => 'Expired Person', 'user_type' => 'normal',
            'valid_enabled' => true, 'begin_time' => now()->subMonth(), 'end_time' => now()->addYear(),
            'access_group_id' => AccessGroup::first()->id,
        ]);

        $person->doors()->attach($door->id, ['granted_at' => now()->subWeek(), 'expires_at' => now()->subMinute()]);
        $person->terminals()->attach($terminal->id, ['access_allowed' => true, 'sync_status' => 'confirmed']);

        $this->artisan('access:expire-grants')->assertSuccessful();

        $this->assertDatabaseMissing('personnel_door', ['personnel_id' => $person->id, 'door_id' => $door->id]);
        $this->assertDatabaseHas('pending_terminal_syncs', [
            'personnel_id' => $person->id,
            'terminal_id' => $terminal->id,
            'operation' => 'revoke',
        ]);
        $this->assertFalse((bool) $person->terminals()->whereKey($terminal->id)->first()->pivot->access_allowed);
        $this->assertDatabaseHas('access_audit_logs', [
            'action' => 'permission_expired',
            'subject_id' => $person->id,
        ]);

        Queue::assertPushed(ProcessAccessSyncJob::class);

        // A second run has nothing left to do and does not queue another write.
        $this->artisan('access:expire-grants')->assertSuccessful();
        $this->assertSame(1, PendingTerminalSync::count());
    }
}
