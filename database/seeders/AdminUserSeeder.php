<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Provisions the administrator account. Idempotent (firstOrCreate by email).
 *
 * Credentials come from the environment (ADMIN_EMAIL / ADMIN_NAME /
 * ADMIN_PASSWORD). If ADMIN_PASSWORD is unset, a strong password is generated
 * and printed once — no password is ever hard-coded in source.
 *
 * Run: php artisan db:seed --class=AdminUserSeeder
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@searchly.narmin.dev');
        $name  = env('ADMIN_NAME', 'Administrator');

        $password  = env('ADMIN_PASSWORD');
        $generated = false;

        if (! $password) {
            $password  = Str::password(16);
            $generated = true;
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => $password, 'role' => 'admin'],
        );

        if ($user->wasRecentlyCreated) {
            $this->command->info("Admin created: {$email} (role: admin)");
            if ($generated) {
                $this->command->warn("Generated password: {$password}");
                $this->command->warn('Store it now. To control it, set ADMIN_PASSWORD in .env and re-run after deleting the user.');
            }
        } else {
            $this->command->info("Admin already exists: {$email} (role: {$user->role}) — no change.");
        }
    }
}
