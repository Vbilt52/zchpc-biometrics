<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsernameAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_administrator_can_sign_in_with_a_username(): void
    {
        $user = User::factory()->create([
            'username' => 'admin',
            'password' => 'a-long-test-password',
        ]);

        $this->post(route('login.store'), [
            'login' => 'admin',
            'password' => 'a-long-test-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }
}
