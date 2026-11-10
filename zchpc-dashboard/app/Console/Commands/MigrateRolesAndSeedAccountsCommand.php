<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class MigrateRolesAndSeedAccountsCommand extends Command
{
    protected $signature = 'users:migrate-roles {--password=access@hpc123 : Default password for new system role accounts}';

    protected $description = 'Migrate existing user roles and provision designated ZCHPC system role accounts';

    public function handle(): int
    {
        $password = (string) $this->option('password');
        $hashed = Hash::make($password);

        $this->info('Starting ZCHPC role migration and provisioning...');

        // 1. Update existing director accounts to official role
        $directors = User::whereIn('username', ['director'])->get();
        foreach ($directors as $dir) {
            $dir->update([
                'role' => User::ROLE_DIRECTOR,
                'office' => 'Executive Directorate',
                'is_active' => true,
            ]);
            $this->line("  ✓ Set user '{$dir->username}' to role: Director (Executive Directorate)");
        }

        // 2. Update existing deputy director accounts
        $deputies = User::whereIn('username', ['deputy director', 'deputy_director'])->get();
        foreach ($deputies as $dep) {
            $dep->update([
                'role' => User::ROLE_DEPUTY_DIRECTOR,
                'office' => 'Executive Directorate',
                'is_active' => true,
            ]);
            $this->line("  ✓ Set user '{$dep->username}' to role: Deputy Director (Executive Directorate)");
        }

        // 3. Ensure primary Super Admin account
        $admin = User::where('username', 'admin')->first();
        if ($admin) {
            $admin->update([
                'role' => User::ROLE_SUPER_ADMIN,
                'office' => 'Super Administrator / ICT',
                'is_active' => true,
            ]);
            $this->line("  ✓ Preserved 'admin' as Super Admin");
        }

        // 4. Provision or update Systems Engineer account (Full Access)
        $engineer = User::firstOrCreate(
            ['username' => 'systems_engineer'],
            [
                'name' => 'Systems Engineer',
                'email' => 'engineer@hpc.ac.zw',
                'role' => User::ROLE_SYSTEMS_ENGINEER,
                'office' => 'Systems Engineering & Infrastructure',
                'password' => $hashed,
                'is_active' => true,
                'must_change_password' => false,
            ]
        );
        $this->line("  ✓ Provisioned Systems Engineer account: '{$engineer->username}' (Full System Access)");

        // 5. Provision HR Officer
        $hr = User::firstOrCreate(
            ['username' => 'hr_officer'],
            [
                'name' => 'HR Officer',
                'email' => 'hr@hpc.ac.zw',
                'role' => User::ROLE_HR_OFFICER,
                'office' => 'Human Resources Department',
                'password' => $hashed,
                'is_active' => true,
                'must_change_password' => false,
            ]
        );
        $this->line("  ✓ Provisioned HR Officer account: '{$hr->username}'");

        // 6. Provision Audit Officer
        $audit = User::firstOrCreate(
            ['username' => 'audit_officer'],
            [
                'name' => 'Audit Officer',
                'email' => 'audit@hpc.ac.zw',
                'role' => User::ROLE_AUDIT_OFFICER,
                'office' => 'Internal Audit & Compliance',
                'password' => $hashed,
                'is_active' => true,
                'must_change_password' => false,
            ]
        );
        $this->line("  ✓ Provisioned Audit Officer account: '{$audit->username}'");

        $this->newLine();
        $this->table(
            ['Username', 'Name', 'Role', 'Office', 'Active'],
            User::all()->map(fn (User $u) => [
                $u->username,
                $u->name,
                $u->roleLabel(),
                $u->office ?? 'General',
                $u->is_active ? 'Yes' : 'No',
            ])
        );

        return self::SUCCESS;
    }
}

