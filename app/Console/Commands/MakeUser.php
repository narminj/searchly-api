<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Provisions a user for the protected docs/admin area. There is deliberately no
 * public sign-up — accounts are created here by an operator.
 */
class MakeUser extends Command
{
    protected $signature = 'auth:make-user
                            {--name= : Display name}
                            {--email= : Login email}
                            {--password= : Password (prompted securely if omitted)}
                            {--admin : Create an administrator (otherwise: viewer)}';

    protected $description = 'Create a user (admin or viewer) for the protected docs/admin area.';

    public function handle(): int
    {
        $name     = $this->option('name') ?: $this->ask('Name');
        $email    = $this->option('email') ?: $this->ask('Email');
        $password = $this->option('password') ?: $this->secret('Password');
        $role     = $this->option('admin') ? 'admin' : 'viewer';

        $validator = Validator::make(compact('name', 'email', 'password'), [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        // The User model's 'hashed' cast bcrypts the password on assignment
        $user = User::create([
            'name'     => $name,
            'email'    => $email,
            'password' => $password,
            'role'     => $role,
        ]);

        $this->info("Created {$role} '{$user->email}' (id {$user->id}).");

        return self::SUCCESS;
    }
}
