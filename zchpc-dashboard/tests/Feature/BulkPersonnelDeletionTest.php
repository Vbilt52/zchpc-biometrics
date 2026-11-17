<?php

namespace Tests\Feature;

use App\Models\PendingTerminalSync;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;
use Tests\TestCase;

class BulkPersonnelDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $id): Personnel
    {
        return Personnel::create(['employee_no' => $id, 'name' => 'Person '.$id, 'user_type' => 'normal', 'valid_enabled' => true]);
    }

    private function terminal(bool $managed = true): Terminal
    {
        return Terminal::create(['name' => $managed ? 'Offline Entrance' : 'Standalone Reader', 'phase' => 'Phase 1',
            'ip' => '192.0.2.1', 'username' => 'admin', 'password' => 'test', 'status' => 'offline',
            'integration' => $managed ? Terminal::INTEGRATION_HIKVISION_ISAPI : Terminal::INTEGRATION_STANDALONE_WIEGAND]);
    }

    public function test_only_selected_people_are_archived_without_contacting_offline_devices(): void
    {
        $first = $this->person('001');
        $second = $this->person('002');
        $untouched = $this->person('003');
        $terminal = $this->terminal();
        foreach ([$first, $second, $untouched] as $person) {
            $person->terminals()->attach($terminal->id, ['access_allowed' => true]);
        }
        PendingTerminalSync::create(['personnel_id' => $first->id, 'terminal_id' => $terminal->id, 'operation' => 'upsert', 'next_attempt_at' => now()]);
        $manager = Mockery::mock(DeviceManager::class);
        $manager->shouldNotReceive('device');
        $this->app->instance(DeviceManager::class, $manager);
        $actor = User::factory()->superAdmin()->create();

        $this->actingAs($actor)->delete(route('access.users.bulk-destroy'), ['ids' => [$first->id, $second->id], 'confirm' => '1'])
            ->assertRedirect(route('access.index'))->assertSessionHas('success');

        $this->assertSoftDeleted($first);
        $this->assertSoftDeleted($second);
        $this->assertNotSoftDeleted($untouched);
        $this->assertDatabaseCount('personnel', 3);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame(2, PendingTerminalSync::where('operation', 'delete')->count());
        $this->assertDatabaseCount('access_audit_logs', 2);
        $this->assertDatabaseHas('access_audit_logs', ['action' => 'personnel_deleted', 'user_id' => $actor->id, 'subject_id' => $first->id]);
        $this->assertTrue((bool) $untouched->terminals()->first()->pivot->access_allowed);
        $this->assertFalse((bool) $first->terminals()->first()->pivot->access_allowed);
    }

    public function test_invalid_or_unconfirmed_selection_never_deletes_anyone(): void
    {
        $person = $this->person('001');
        $this->actingAs(User::factory()->superAdmin()->create());
        $payloads = [
            ['ids' => [], 'confirm' => 1],
            ['ids' => [$person->id]],
            ['ids' => [$person->id], 'confirm' => 0],
            ['ids' => [$person->id, $person->id], 'confirm' => 1],
            ['ids' => [$person->id, 999999], 'confirm' => 1],
            ['ids' => [$person->id, 'bad-id'], 'confirm' => 1],
            ['ids' => range(1, 101), 'confirm' => 1],
            ['ids' => 'all', 'confirm' => 1],
        ];
        foreach ($payloads as $payload) {
            $this->delete(route('access.users.bulk-destroy'), $payload)->assertSessionHasErrors();
            $this->assertNotSoftDeleted($person);
        }
        $this->assertDatabaseCount('access_audit_logs', 0);
        $this->assertDatabaseCount('pending_terminal_syncs', 0);
    }

    public function test_stale_selection_is_rejected_including_an_already_removed_user(): void
    {
        $first = $this->person('001');
        $second = $this->person('002');
        $first->delete();
        $this->actingAs(User::factory()->superAdmin()->create())->delete(route('access.users.bulk-destroy'),
            ['ids' => [$first->id, $second->id], 'confirm' => 1])->assertSessionHasErrors('ids.0');
        $this->assertNotSoftDeleted($second);
    }

    public function test_a_failure_rolls_back_every_person_pending_write_permission_and_audit(): void
    {
        $first = $this->person('001');
        $second = $this->person('002');
        $managed = $this->terminal();
        $unmanaged = $this->terminal(false);
        $first->terminals()->attach($managed->id, ['access_allowed' => true]);
        $second->terminals()->attach($unmanaged->id, ['access_allowed' => true]);
        $this->actingAs(User::factory()->superAdmin()->create())->delete(route('access.users.bulk-destroy'),
            ['ids' => [$first->id, $second->id], 'confirm' => 1])->assertSessionHas('error');
        $this->assertNotSoftDeleted($first);
        $this->assertNotSoftDeleted($second);
        $this->assertTrue((bool) $first->terminals()->first()->pivot->access_allowed);
        $this->assertDatabaseCount('pending_terminal_syncs', 0);
        $this->assertDatabaseCount('access_audit_logs', 0);
    }

    public function test_busy_selection_is_unchanged_and_released_locks_allow_a_later_retry(): void
    {
        $first = $this->person('001');
        $second = $this->person('002');
        $lock = Cache::lock('person-access:'.$second->id, 600);
        $this->assertTrue($lock->get());
        $this->actingAs(User::factory()->superAdmin()->create());
        $payload = ['ids' => [$first->id, $second->id], 'confirm' => 1];
        $this->delete(route('access.users.bulk-destroy'), $payload)->assertSessionHasErrors('ids');
        $this->assertNotSoftDeleted($first);
        $this->assertNotSoftDeleted($second);
        $lock->release();
        $this->delete(route('access.users.bulk-destroy'), $payload)->assertSessionHas('success');
        $this->assertSoftDeleted($first);
        $this->assertSoftDeleted($second);
    }

    public function test_bulk_routes_preserve_existing_permissions_and_hide_selection_from_viewers(): void
    {
        $person = $this->person('001');
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);
        $this->actingAs($viewer);
        foreach (['access.users.bulk-destroy', 'personnel.bulk-destroy'] as $route) {
            $this->delete(route($route), ['ids' => [$person->id], 'confirm' => 1])->assertForbidden();
        }
        foreach (['access.index', 'personnel.index'] as $route) {
            $this->get(route($route))->assertOk()->assertDontSee('id="bulk-delete-form"', false);
        }
        $this->assertNotSoftDeleted($person);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_HR_OFFICER]));
        $this->delete(route('access.users.bulk-destroy'), ['ids' => [$person->id], 'confirm' => 1])->assertForbidden();
        $this->get(route('personnel.index'))->assertSee('Delete selected');
        $this->delete(route('personnel.bulk-destroy'), ['ids' => [$person->id], 'confirm' => 1])->assertRedirect(route('personnel.index'));
        $this->assertSoftDeleted($person);
    }

    public function test_lists_render_only_current_page_selection_and_the_correct_bulk_form(): void
    {
        foreach (range(1, 21) as $number) {
            $this->person(str_pad((string) $number, 3, '0', STR_PAD_LEFT));
        }
        $this->actingAs(User::factory()->superAdmin()->create());
        foreach (['access.index' => 'access.users.bulk-destroy', 'personnel.index' => 'personnel.bulk-destroy'] as $index => $delete) {
            $response = $this->get(route($index))->assertOk()->assertSee('Select all users on this page')
                ->assertSee(route($delete), false)->assertSee('js/personnel-selection.js');
            $this->assertSame(20, substr_count($response->getContent(), 'data-person-selection '));
            $this->assertSame(1, substr_count($response->getContent(), 'id="bulk-delete-form"'));
        }
    }
}
