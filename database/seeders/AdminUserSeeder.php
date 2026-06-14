<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

// The password is intentionally nullable in config so a non-dev environment
// that forgot to set ADMIN_PASSWORD fails loudly here instead of silently
// seeding a known-credential admin.
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('app.admin.email');
        $password = $this->resolvePassword();

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Admin',
                'password' => Hash::make($password),
                'role' => UserRole::Admin,
                'email_verified_at' => now(),
            ],
        );
    }

    // In dev/test, fall back to a dev-only literal so a fresh clone can sign in
    // without setting an env var. In other environments, a missing config value
    // is a deploy bug.
    private function resolvePassword(): string
    {
        $password = config('app.admin.password');

        if ($password !== null && $password !== '') {
            return $password;
        }

        if (app()->environment(['local', 'testing'])) {
            return 'password';
        }

        throw new RuntimeException(
            'ADMIN_PASSWORD must be set in non-dev environments. '
                . 'Add ADMIN_PASSWORD=<secret> to your .env and re-run `php artisan db:seed --class=AdminUserSeeder`.'
        );
    }
}
