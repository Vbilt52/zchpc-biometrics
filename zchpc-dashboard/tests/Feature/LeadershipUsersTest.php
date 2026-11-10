<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class LeadershipUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_leadership_users_command_creates_director_and_deputy_director(): void
    {
        $this->artisan('users:create-leadership')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'username' => 'director',
            'role' => User::ROLE_ADMIN,
        ]);

        $this->assertDatabaseHas('users', [
            'username' => 'deputy director',
            'role' => User::ROLE_ADMIN,
        ]);

        $this->assertTrue(Auth::attempt([
            'username' => 'director',
            'password' => 'access@hpc123',
        ]));

        Auth::logout();

        $this->assertTrue(Auth::attempt([
            'username' => 'deputy director',
            'password' => 'access@hpc123',
        ]));

        Auth::logout();

        $this->assertTrue(Auth::attempt([
            'username' => 'deputy_director',
            'password' => 'access@hpc123',
        ]));
    }
}
