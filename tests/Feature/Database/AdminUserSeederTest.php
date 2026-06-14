<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;

// `detectEnvironment` mutates the singleton Application and doesn't auto-reset,
// so tests 5 and 6 that switch to 'production' must restore 'testing' for
// any later test that runs.
afterEach(function () {
    app()->detectEnvironment(fn() => 'testing');
});

it('seeds an admin with the default dev email and the dev password fallback', function () {
    // 'testing' environment allows the dev password fallback.
    config()->set('app.admin.email', 'admin@example.test');
    config()->set('app.admin.password', null);

    (new AdminUserSeeder)->run();

    $admin = User::query()->where('role', UserRole::Admin)->first();
    expect($admin)->not->toBeNull()
        ->and($admin->email)->toBe('admin@example.test')
        ->and($admin->name)->toBe('Admin')
        ->and($admin->role)->toBe(UserRole::Admin)
        ->and(Hash::check('password', $admin->password))->toBeTrue();
});

it('seeds an admin with the password from config when set', function () {
    config()->set('app.admin.email', 'admin@example.test');
    config()->set('app.admin.password', 'a-real-secret-from-config');

    (new AdminUserSeeder)->run();

    $admin = User::query()->where('role', UserRole::Admin)->first();
    expect($admin)->not->toBeNull()
        ->and(Hash::check('a-real-secret-from-config', $admin->password))->toBeTrue()
        ->and(Hash::check('password', $admin->password))->toBeFalse();
});

it('treats an empty string the same as a missing password in dev', function () {
    config()->set('app.admin.email', 'admin@example.test');
    config()->set('app.admin.password', '');

    (new AdminUserSeeder)->run();

    $admin = User::query()->where('role', UserRole::Admin)->first();
    expect($admin)->not->toBeNull()
        ->and(Hash::check('password', $admin->password))->toBeTrue();
});

it('is idempotent — re-running does not create a duplicate admin', function () {
    config()->set('app.admin.email', 'admin@example.test');
    config()->set('app.admin.password', null);

    (new AdminUserSeeder)->run();
    (new AdminUserSeeder)->run();

    expect(User::query()->where('role', UserRole::Admin)->count())->toBe(1);
});

it('throws loudly with a clear message in a non-dev environment when ADMIN_PASSWORD is unset', function () {
    app()->detectEnvironment(fn() => 'production');

    config()->set('app.admin.email', 'admin@example.test');
    config()->set('app.admin.password', null);

    (new AdminUserSeeder)->run();
})->throws(RuntimeException::class, 'ADMIN_PASSWORD must be set in non-dev environments');

it('the thrown message tells the operator exactly what to set', function () {
    app()->detectEnvironment(fn() => 'production');
    config()->set('app.admin.email', 'admin@example.test');
    config()->set('app.admin.password', null);

    $thrown = false;
    try {
        (new AdminUserSeeder)->run();
    } catch (RuntimeException $e) {
        $thrown = true;
        expect($e->getMessage())
            ->toContain('ADMIN_PASSWORD')
            ->toContain('.env')
            ->toContain('db:seed');
    }

    expect($thrown)->toBeTrue();
});
