<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

class MakeAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:make-admin {email : The email of the user to grant admin rights}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Grant administrator rights (admin role) to a user by email';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("User with email [{$email}] not found.");
            return Command::FAILURE;
        }

        $user->assignRole(Role::ADMIN);
        $this->info("User [{$user->name} ({$user->email})] has been granted [admin] role successfully.");

        return Command::SUCCESS;
    }
}
