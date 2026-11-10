<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => User::ROLE_ADMIN,
            'office' => 'ZCHPC Operations',
            'is_active' => true,
            'must_change_password' => false,
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_SUPER_ADMIN,
            'office' => 'Super Administrator / ICT',
        ]);
    }

    public function systemsEngineer(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_SYSTEMS_ENGINEER,
            'office' => 'Systems Engineering & Infrastructure',
        ]);
    }

    public function director(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_DIRECTOR,
            'office' => 'Executive Directorate',
        ]);
    }

    public function deputyDirector(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_DEPUTY_DIRECTOR,
            'office' => 'Executive Directorate',
        ]);
    }

    public function hrOfficer(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_HR_OFFICER,
            'office' => 'Human Resources Department',
        ]);
    }

    public function auditOfficer(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_AUDIT_OFFICER,
            'office' => 'Internal Audit & Compliance',
        ]);
    }

    public function viewer(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_VIEWER,
            'office' => 'General Operations',
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
