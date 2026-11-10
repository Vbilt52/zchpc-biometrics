<?php

namespace Tests\Feature;

use App\Models\AccessAuditLog;
use App\Models\AccessGroup;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleBasedAccessControlTest extends TestCase
{
    use RefreshDatabase;

    private AccessGroup $dept;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dept = AccessGroup::create([
            'name' => 'High Performance Computing',
            'description' => 'Core scientific computing infrastructure',
        ]);
    }

    public function test_super_admin_has_full_access(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get(route('dashboard'))
            ->assertOk();

        $this->actingAs($superAdmin)
            ->get(route('admin.users.index'))
            ->assertOk();

        $this->actingAs($superAdmin)
            ->get(route('dashboard.executive'))
            ->assertOk();

        $this->actingAs($superAdmin)
            ->get(route('dashboard.hr'))
            ->assertOk();

        $this->actingAs($superAdmin)
            ->get(route('dashboard.audit'))
            ->assertOk();
    }

    public function test_systems_engineer_has_full_access_to_every_module(): void
    {
        $engineer = User::factory()->systemsEngineer()->create();

        // 1. Dashboards (Main, Executive, HR, Audit)
        $this->actingAs($engineer)->get(route('dashboard'))->assertOk();
        $this->actingAs($engineer)->get(route('dashboard.executive'))->assertOk();
        $this->actingAs($engineer)->get(route('dashboard.hr'))->assertOk();
        $this->actingAs($engineer)->get(route('dashboard.audit'))->assertOk();

        // 2. Hardware & Terminal Management
        $this->actingAs($engineer)->get(route('devices.index'))->assertOk();
        $this->actingAs($engineer)->get(route('terminals.index'))->assertOk();

        // 3. User Accounts Administration
        $this->actingAs($engineer)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($engineer)->get(route('admin.users.create'))->assertOk();

        // 4. Access Control Matrix & Sync
        $this->actingAs($engineer)->get(route('access.matrix.index'))->assertOk();
        $this->actingAs($engineer)->get(route('access.sync.index'))->assertOk();
        $this->actingAs($engineer)->get(route('access.audit.index'))->assertOk();
    }

    public function test_systems_engineer_can_manage_user_accounts_and_permissions(): void
    {
        $engineer = User::factory()->systemsEngineer()->create();

        // Create new account
        $response = $this->actingAs($engineer)->post(route('admin.users.store'), [
            'name' => 'Kudakwashe Shumba',
            'username' => 'kshumba',
            'email' => 'kshumba@hpc.ac.zw',
            'role' => User::ROLE_HR_OFFICER,
            'office' => 'Human Resources',
            'password_type' => 'manual',
            'password' => 'temporaryPassword123!',
            'must_change_password' => 1,
        ]);

        $response->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', [
            'username' => 'kshumba',
            'role' => User::ROLE_HR_OFFICER,
            'is_active' => true,
            'must_change_password' => true,
        ]);

        $newUser = User::where('username', 'kshumba')->firstOrFail();

        // Toggle status (deactivate)
        $this->actingAs($engineer)
            ->post(route('admin.users.toggle-status', $newUser))
            ->assertRedirect();

        $this->assertFalse($newUser->fresh()->is_active);

        // Verify audit log created
        $this->assertDatabaseHas('access_audit_logs', [
            'action' => 'account_created',
            'user_id' => $engineer->id,
        ]);
    }

    public function test_director_cannot_access_unauthorized_technical_settings(): void
    {
        $director = User::factory()->director()->create();

        // Allowed: Executive Dashboard, Attendance, Events, Personnel
        $this->actingAs($director)->get(route('dashboard.executive'))->assertOk();
        $this->actingAs($director)->get(route('attendance.index'))->assertOk();
        $this->actingAs($director)->get(route('events.index'))->assertOk();
        $this->actingAs($director)->get(route('personnel.index'))->assertOk();

        // Forbidden: User Accounts Management
        $this->actingAs($director)
            ->get(route('admin.users.index'))
            ->assertForbidden();

        // Forbidden: Physical Terminal Creation
        $this->actingAs($director)
            ->get(route('terminals.create'))
            ->assertForbidden();
    }

    public function test_deputy_director_receives_assigned_executive_permissions(): void
    {
        $deputy = User::factory()->deputyDirector()->create();

        $this->actingAs($deputy)->get(route('dashboard.executive'))->assertOk();
        $this->actingAs($deputy)->get(route('attendance.index'))->assertOk();

        // Forbidden from User Account Management
        $this->actingAs($deputy)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($deputy)->get(route('admin.users.create'))->assertForbidden();
    }

    public function test_hr_officer_can_add_and_edit_personnel(): void
    {
        $hr = User::factory()->hrOfficer()->create();

        // HR Dashboard access
        $this->actingAs($hr)->get(route('dashboard.hr'))->assertOk();

        // Can access create personnel page
        $this->actingAs($hr)->get(route('personnel.create'))->assertOk();

        // Can store personnel
        $response = $this->actingAs($hr)->post(route('personnel.store'), [
            'employee_no' => 'EMP-HR-001',
            'name' => 'Tatenda Chidziwa',
            'user_type' => 'normal',
            'valid_enabled' => 1,
            'begin_time' => now()->format('Y-m-d H:i:s'),
            'end_time' => now()->addYear()->format('Y-m-d H:i:s'),
            'access_group_id' => $this->dept->id,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('personnel', [
            'employee_no' => 'EMP-HR-001',
            'name' => 'Tatenda Chidziwa',
        ]);

        // Creating personnel by HR does NOT grant terminal access
        $person = Personnel::where('employee_no', 'EMP-HR-001')->firstOrFail();
        $this->assertNull($person->door_right);
        $this->assertEmpty($person->terminals);

        // HR cannot access technical user administration
        $this->actingAs($hr)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($hr)->get(route('terminals.create'))->assertForbidden();
    }

    public function test_audit_officer_has_read_only_access_and_cannot_modify_records(): void
    {
        $audit = User::factory()->auditOfficer()->create();

        // Audit Dashboard and Logs
        $this->actingAs($audit)->get(route('dashboard.audit'))->assertOk();
        $this->actingAs($audit)->get(route('access.audit.index'))->assertOk();
        $this->actingAs($audit)->get(route('events.index'))->assertOk();
        $this->actingAs($audit)->get(route('attendance.index'))->assertOk();

        // Cannot add personnel
        $this->actingAs($audit)->get(route('personnel.create'))->assertForbidden();

        // Cannot manage user accounts
        $this->actingAs($audit)->get(route('admin.users.index'))->assertForbidden();

        // Cannot manage terminals
        $this->actingAs($audit)->get(route('terminals.create'))->assertForbidden();
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'username' => 'inactive_user',
            'password' => 'secretPassword123!',
            'is_active' => false,
        ]);

        $response = $this->post(route('login.store'), [
            'login' => 'inactive_user',
            'password' => 'secretPassword123!',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_cannot_deactivate_last_active_super_admin(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $engineer = User::factory()->systemsEngineer()->create();

        // Deactivating the only Super Admin must be rejected
        $response = $this->actingAs($engineer)
            ->post(route('admin.users.toggle-status', $superAdmin));

        $response->assertSessionHas('error');
        $this->assertTrue($superAdmin->fresh()->is_active);
    }

    public function test_cannot_deactivate_own_session(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)
            ->post(route('admin.users.toggle-status', $superAdmin));

        $response->assertSessionHas('error');
        $this->assertTrue($superAdmin->fresh()->is_active);
    }
}

