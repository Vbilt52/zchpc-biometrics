<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountPasswordService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class RecoverAccountCommand extends Command
{
    protected $signature = 'account:recover {username : Exact username of the existing account}';

    protected $description = 'Reset an existing account password without deleting project records';

    public function handle(AccountPasswordService $passwords): int
    {
        $user = User::where('username', $this->argument('username'))->first();
        if (! $user) {
            $this->error('Account not found. No changes made.');

            return self::FAILURE;
        }
        $password = $this->secret('New password (12–128 characters)');
        $confirmation = $this->secret('Confirm new password');
        $validator = Validator::make([
            'password' => $password, 'password_confirmation' => $confirmation,
        ], ['password' => ['required', 'string', 'min:12', 'max:128', 'confirmed']]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        $passwords->change($user, $password, 'server-recovery');
        $this->info('Password reset and remembered logins revoked. Project records preserved.');

        return self::SUCCESS;
    }
}
