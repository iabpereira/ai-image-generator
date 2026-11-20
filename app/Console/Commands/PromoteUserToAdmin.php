<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('users:promote {email : The email of the user who becomes an operator}')]
#[Description('Grant a user access to the admin report')]
class PromoteUserToAdmin extends Command
{
    public function handle(): int
    {
        $updated = User::query()->where('email', $this->argument('email'))->update(['is_admin' => true]);

        if ($updated === 0) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        $this->info('User promoted to admin.');

        return self::SUCCESS;
    }
}
