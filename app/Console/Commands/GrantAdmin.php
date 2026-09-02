<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class GrantAdmin extends Command
{
    protected $signature = 'admin:grant {email : Email of the registered user to make a platform administrator} {--revoke : Remove administrator access instead}';

    protected $description = 'Grant (or revoke) platform administrator access to a registered user';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("No user found with email {$this->argument('email')}. They need to register first.");

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();

        $this->info($user->is_admin
            ? "{$user->email} is now a platform administrator."
            : "{$user->email} is no longer a platform administrator.");

        return self::SUCCESS;
    }
}
