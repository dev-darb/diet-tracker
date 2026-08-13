<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Promote a user to admin (BUILD_PLAN §11; brief §21 Q39). This is the ONLY way
 * to grant admin access — there is deliberately no user-facing self-promotion.
 */
class MakeAdminCommand extends Command
{
    protected $signature = 'app:make-admin {email : The email of the user to promote}';

    protected $description = 'Grant a user access to the admin console';

    public function handle(): int
    {
        $email = $this->argument('email');

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user found with email [{$email}].");

            return self::FAILURE;
        }

        if ($user->isAdmin()) {
            $this->info("{$email} is already an admin.");

            return self::SUCCESS;
        }

        $user->forceFill(['is_admin' => true])->save();

        $this->info("{$email} is now an admin.");

        return self::SUCCESS;
    }
}
