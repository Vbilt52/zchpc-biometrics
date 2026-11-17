<?php

namespace Tests\Feature;

use App\Models\AccessAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountLifecycleSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_account_loses_existing_session_access(): void
    {
        $user = User::factory()->superAdmin()->create(['is_active' => false]);
        $this->actingAs($user)->get(route('admin.users.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_temporary_password_cannot_be_bypassed_with_direct_urls(): void
    {
        $user = User::factory()->superAdmin()->create(['must_change_password' => true]);
        $this->actingAs($user)->get(route('admin.users.index'))->assertRedirect(route('account.password.edit'));
        $this->post(route('admin.users.store'), [])->assertRedirect(route('account.password.edit'));
        $this->getJson(route('dashboard.live-data'))->assertForbidden();
        $this->get(route('account.password.edit'))->assertOk();
    }

    public function test_account_creation_rejects_unlisted_permissions_and_case_duplicate_logins(): void
    {
        $admin = User::factory()->superAdmin()->create();
        User::factory()->create(['username' => 'existing']);
        $this->actingAs($admin);
        $data = ['name' => 'Test Operator', 'username' => 'newoperator', 'email' => 'operator@example.test', 'role' => 'viewer', 'password_type' => 'manual', 'password' => 'TemporaryPass123!', 'permissions' => ['users.manage']];
        $this->post(route('admin.users.store'), $data)->assertSessionHasErrors('permissions.0');
        $data['permissions'] = [];
        $data['username'] = 'EXISTING';
        $this->post(route('admin.users.store'), $data)->assertSessionHasErrors('username');
        $this->assertDatabaseCount('users', 2);
    }

    public function test_read_only_roles_cannot_import_devices_or_create_accounts(): void
    {
        foreach (['viewer', 'audit_officer', 'director'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->post(route('events.import.all'), [])->assertForbidden();
            $this->post(route('admin.users.store'), [])->assertForbidden();
        }
    }

    public function test_unknown_roles_never_inherit_administrator_permissions(): void
    {
        $user = User::factory()->create(['role' => 'unknown']);
        $this->assertFalse($user->isFullAdmin());
        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_account_activity_matches_stable_ids_not_same_names_or_substrings(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $user = User::factory()->create(['name' => 'Shared Name']);
        AccessAuditLog::create(['user_name' => 'System', 'action' => 'account_updated', 'outcome' => 'info', 'subject_label' => 'Shared Name', 'description' => 'Correct target entry', 'context' => ['target_user_id' => $user->id], 'created_at' => now()]);
        AccessAuditLog::create(['user_name' => 'System', 'action' => 'account_updated', 'outcome' => 'info', 'subject_label' => 'Shared Name', 'description' => 'Different account entry', 'context' => ['target_user_id' => 999], 'created_at' => now()]);
        $this->actingAs($admin)->get(route('admin.users.activity', $user))->assertOk()->assertSee('Correct target entry')->assertDontSee('Different account entry');
    }
}
