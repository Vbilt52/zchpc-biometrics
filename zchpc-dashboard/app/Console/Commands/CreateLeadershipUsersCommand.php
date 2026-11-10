<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateLeadershipUsersCommand extends Command
{
    protected $signature = 'users:create-leadership';

    protected $description = 'Create or update login accounts for Director and Deputy Director';

    public function handle(): int
    {
        $password = 'access@hpc123';
        $hashed = Hash::make($password);

        $accounts = [
            [
                'name' => 'Director',
                'username' => 'director',
                'email' => 'director@hpc.ac.zw',
                'role' => User::ROLE_DIRECTOR,
                'office' => 'Executive Directorate',
                'password' => $hashed,
            ],
            [
                'name' => 'Deputy Director',
                'username' => 'deputy director',
                'email' => 'deputydirector@hpc.ac.zw',
                'role' => User::ROLE_DEPUTY_DIRECTOR,
                'office' => 'Executive Directorate',
                'password' => $hashed,
            ],
            [
                'name' => 'Deputy Director',
                'username' => 'deputy_director',
                'email' => 'deputy_director@hpc.ac.zw',
                'role' => User::ROLE_DEPUTY_DIRECTOR,
                'office' => 'Executive Directorate',
                'password' => $hashed,
            ],
        ];

        foreach ($accounts as $data) {
            $user = User::updateOrCreate(
                ['username' => $data['username']],
                $data
            );
            $this->info("User '{$user->username}' ({$user->name}) created/updated with role '{$user->role}'.");
        }

        $this->newLine();
        $this->info('Login credentials:');
        $this->line('  - Username: director | Password: '.$password);
        $this->line('  - Username: deputy director (or deputy_director) | Password: '.$password);

        return self::SUCCESS;
    }
}

