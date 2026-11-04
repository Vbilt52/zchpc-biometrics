<?php

namespace Tests\Feature;

use App\Models\AccessGroup;
use App\Models\Door;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Models\User;
use App\Services\DoorAccessService;
use App\Services\PendingTerminalSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;
use Tests\TestCase;

class AccessOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function terminal(string $name, string $status = 'offline'): Terminal
    {
        return Terminal::create([
            'name' => $name, 'phase' => 'Phase 1', 'ip' => '192.0.2.20', 'port' => 80,
            'username' => 'admin', 'password' => 'test', 'protocol' => 'http', 'timeout' => 5,
            'verify_ssl' => false, 'status' => $status,
        ]);
    }

    private function door(Terminal $terminal, int $channel, string $name): Door
    {
        return Door::create([
            'name' => $name, 'code' => 'DOOR-'.$channel.'-'.strtoupper(substr(md5($name), 0, 5)),
            'building' => 'HQ', 'floor' => 'Ground', 'zone' => 'Reception',
            'terminal_id' => $terminal->id, 'door_no' => $channel, 'plan_template_no' => '1',
            'is_active' => true, 'sort_order' => 1,
        ]);
    }

    private function offlineDevice(): void
    {
        $manager = Mockery::mock(DeviceManager::class);
        $manager->shouldReceive('device')->andThrow(new \RuntimeException('Connection refused'));
        $this->app->instance(DeviceManager::class, $manager);
        $this->app->forgetInstance(PendingTerminalSyncService::class);
        $this->app->forgetInstance(DoorAccessService::class);
    }

    private function details(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Tarisai Ncube',
            'employee_no' => 'EMP-500',
            'department' => AccessGroup::first()->id,
            'user_type' => 'normal',
            'begin_time' => now()->subDay()->format('Y-m-d H:i'),
            'end_time' => now()->addYear()->format('Y-m-d H:i'),
            'phone_number' => '0772000111',
            'email' => 'tarisai@example.test',
            'notes' => 'Onboarding test',
        ], $overrides);
    }

    public function test_wizard_creates_a_draft_and_applies_only_the_selected_doors(): void
    {
        $admin = User::factory()->create();
        $terminal = $this->terminal('Main Entrance');
        $door = $this->door($terminal, 1, 'Main Entrance');
        $otherDoor = $this->door($terminal, 2, 'Server Room');
        $this->offlineDevice();

        $this->actingAs($admin)
            ->post(route('access.users.create.store'), $this->details())
            ->assertRedirect(route('access.wizard.device'));

        $person = Personnel::where('employee_no', 'EMP-500')->firstOrFail();
        $this->assertSame(Personnel::STATUS_DRAFT, $person->status);
        $this->assertSame(0, $person->doors()->count());

        $this->actingAs($admin)
            ->post(route('access.wizard.device.store'), ['terminal_id' => $terminal->id])
            ->assertRedirect(route('access.wizard.capture'));

        $this->actingAs($admin)
            ->post(route('access.wizard.capture.skip'))
            ->assertRedirect(route('access.wizard.doors'));

        $this->actingAs($admin)
            ->post(route('access.wizard.doors.store'), ['door_ids' => [$door->id]])
            ->assertRedirect(route('access.wizard.review'));

        $this->actingAs($admin)
            ->post(route('access.wizard.deploy'), ['confirm' => 1])
            ->assertRedirect(route('access.users.deployed', $person));

        $person->refresh();
        $this->assertSame(Personnel::STATUS_ACTIVE, $person->status);
        $this->assertSame(1, $person->doors()->count());
        $this->assertSame($door->id, $person->doors()->first()->id);
        $this->assertSame(0, $person->doors()->where('doors.id', $otherDoor->id)->count());

        // The controller is offline, so the write is pending, never reported confirmed.
        $this->assertDatabaseHas('pending_terminal_syncs', [
            'personnel_id' => $person->id, 'terminal_id' => $terminal->id, 'operation' => 'upsert',
        ]);
        $this->assertSame('pending', $person->terminals()->whereKey($terminal->id)->first()->pivot->sync_status);

        $this->actingAs($admin)->get(route('access.users.deployed', $person))
            ->assertOk()
            ->assertSee('Tarisai Ncube')
            ->assertSee('Pending synchronization');

        $this->actingAs($admin)->get(route('access.users.show', $person))->assertOk()->assertSee('Main Entrance');
        $this->assertDatabaseHas('access_audit_logs', ['action' => 'permission_granted', 'subject_id' => $person->id]);
        $this->assertDatabaseHas('access_audit_logs', ['action' => 'user_onboarded', 'subject_id' => $person->id]);
    }

    public function test_a_similar_name_requires_confirmation_and_an_existing_employee_id_is_rejected(): void
    {
        $admin = User::factory()->create();
        Personnel::create([
            'employee_no' => 'EMP-100', 'name' => 'Tarisai Ncube', 'user_type' => 'normal',
            'valid_enabled' => true, 'begin_time' => now()->subDay(), 'end_time' => now()->addYear(),
            'access_group_id' => AccessGroup::first()->id,
        ]);

        $this->actingAs($admin)
            ->post(route('access.users.create.store'), $this->details())
            ->assertSessionHasErrors('confirm_duplicate');
        $this->assertSame(1, Personnel::count());

        $this->actingAs($admin)
            ->post(route('access.users.create.store'), $this->details(['confirm_duplicate' => 1]))
            ->assertRedirect(route('access.wizard.device'));
        $this->assertSame(2, Personnel::count());

        $this->actingAs($admin)
            ->post(route('access.users.create.store'), $this->details([
                'employee_no' => 'EMP-100', 'confirm_duplicate' => 1,
            ]))
            ->assertSessionHasErrors('employee_no');
        $this->assertSame(2, Personnel::count());
    }

    public function test_the_access_matrix_distinguishes_confirmed_and_pending_permissions_and_exports(): void
    {
        $admin = User::factory()->create();
        $terminal = $this->terminal('Main Entrance', 'online');
        $door = $this->door($terminal, 1, 'Main Entrance');

        $confirmed = Personnel::create([
            'employee_no' => 'EMP-201', 'name' => 'Confirmed Person', 'user_type' => 'normal',
            'valid_enabled' => true, 'begin_time' => now()->subDay(), 'end_time' => now()->addYear(),
            'access_group_id' => AccessGroup::first()->id,
        ]);
        $confirmed->doors()->attach($door->id, ['granted_at' => now()]);
        $confirmed->terminals()->attach($terminal->id, ['access_allowed' => true, 'sync_status' => 'confirmed']);

        $pending = Personnel::create([
            'employee_no' => 'EMP-202', 'name' => 'Pending Person', 'user_type' => 'normal',
            'valid_enabled' => true, 'begin_time' => now()->subDay(), 'end_time' => now()->addYear(),
            'access_group_id' => AccessGroup::first()->id,
        ]);
        $pending->doors()->attach($door->id, ['granted_at' => now()]);
        $pending->terminals()->attach($terminal->id, ['access_allowed' => true, 'sync_status' => 'pending']);
        $pending->pendingSyncs()->create([
            'terminal_id' => $terminal->id, 'operation' => 'upsert', 'attempts' => 1,
            'last_error' => 'Connection refused', 'last_attempt_at' => now(), 'next_attempt_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('access.matrix.index'))
            ->assertOk()
            ->assertSee('Confirmed Person')
            ->assertSee('Pending Person')
            ->assertSee('Main Entrance')
            ->assertSee('Allowed')
            ->assertSee('Pending');

        $response = $this->actingAs($admin)->get(route('access.matrix.export'));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Confirmed Person', $csv);
        $this->assertStringContainsString('Allowed', $csv);
        $this->assertStringContainsString('Main Entrance', $csv);

        $filtered = $this->actingAs($admin)->get(route('access.matrix.index', ['door' => $door->id]));
        $filtered->assertOk()->assertSee('Confirmed Person');
    }

    public function test_a_granted_door_cannot_be_deleted_until_access_is_revoked(): void
    {
        $admin = User::factory()->create();
        $terminal = $this->terminal('Main Entrance');
        $door = $this->door($terminal, 1, 'Main Entrance');
        $person = Personnel::create([
            'employee_no' => 'EMP-300', 'name' => 'Door Test', 'user_type' => 'normal',
            'valid_enabled' => true, 'begin_time' => now()->subDay(), 'end_time' => now()->addYear(),
            'access_group_id' => AccessGroup::first()->id,
        ]);
        $person->doors()->attach($door->id, ['granted_at' => now()]);

        $this->actingAs($admin)
            ->delete(route('access.doors.destroy', $door))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('doors', ['id' => $door->id]);

        $person->doors()->detach($door->id);

        $this->actingAs($admin)
            ->delete(route('access.doors.destroy', $door))
            ->assertRedirect(route('access.doors.index'));
        $this->assertDatabaseMissing('doors', ['id' => $door->id]);
        $this->assertDatabaseHas('access_audit_logs', ['action' => 'door_removed']);
    }
}
