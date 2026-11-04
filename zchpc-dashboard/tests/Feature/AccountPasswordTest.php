<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_settings_require_login_and_current_password(): void
    {
        $this->get('/account/password')->assertRedirect('/login');
        $user = User::factory()->create(['name' => 'Prince', 'password' => 'Old-password-123']);
        $this->actingAs($user)->get('/account/password')->assertOk()->assertSee('Prince');
        $this->put('/account/password', [
            'current_password' => 'wrong', 'password' => 'New-password-456', 'password_confirmation' => 'New-password-456',
        ])->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('Old-password-123', $user->fresh()->password));
    }

    public function test_changing_password_requires_confirmation_and_signs_out(): void
    {
        $user = User::factory()->create(['password' => 'Old-password-123', 'remember_token' => 'previous-token']);
        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'Old-password-123', 'password' => 'New-password-456', 'password_confirmation' => 'mismatch',
        ])->assertSessionHasErrors('password');
        $this->put('/account/password', [
            'current_password' => 'Old-password-123', 'password' => 'New-password-456', 'password_confirmation' => 'New-password-456',
        ])->assertRedirect('/login')->assertSessionHas('success');
        $this->assertGuest();
        $this->assertTrue(Hash::check('New-password-456', $user->fresh()->password));
        $this->assertNotSame('previous-token', $user->fresh()->remember_token);
    }

    public function test_recovery_resets_only_selected_account_and_revokes_its_database_sessions(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['name' => 'Prince', 'username' => 'prince']);
        $other = User::factory()->create();
        $otherHash = $other->password;
        foreach ([$user, $other] as $account) {
            DB::table('sessions')->insert(['id' => 'session-'.$account->id, 'user_id' => $account->id, 'payload' => '', 'last_activity' => time()]);
        }
        $this->artisan('account:recover', ['username' => 'prince'])
            ->expectsQuestion('New password (12–128 characters)', 'Recovered-password-123')
            ->expectsQuestion('Confirm new password', 'Recovered-password-123')
            ->assertSuccessful();
        $this->assertTrue(Hash::check('Recovered-password-123', $user->fresh()->password));
        $this->assertSame($otherHash, $other->fresh()->password);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['user_id' => $other->id]);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_recovery_rejects_short_password_without_changing_account(): void
    {
        $user = User::factory()->create(['username' => 'prince']);
        $hash = $user->password;
        $this->artisan('account:recover', ['username' => 'prince'])
            ->expectsQuestion('New password (12–128 characters)', 'short')
            ->expectsQuestion('Confirm new password', 'short')->assertFailed();
        $this->assertSame($hash, $user->fresh()->password);
    }
}
