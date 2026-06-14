<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

it('lets an admin user pass through', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $request = Request::create('/admin');
    $request->setUserResolver(fn() => $admin);

    $response = (new EnsureUserIsAdmin)->handle($request, fn() => response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('throws an AuthenticationException for an instructor', function () {
    $instructor = User::factory()->instructor()->create();

    $request = Request::create('/admin');
    $request->setUserResolver(fn() => $instructor);

    (new EnsureUserIsAdmin)->handle($request, fn() => response('ok'));
})->throws(AuthenticationException::class, 'Admin access required');

it('throws an AuthenticationException for a student', function () {
    $student = User::factory()->create(); // role=student

    $request = Request::create('/admin');
    $request->setUserResolver(fn() => $student);

    (new EnsureUserIsAdmin)->handle($request, fn() => response('ok'));
})->throws(AuthenticationException::class, 'Admin access required');

it('throws when there is no user on the request (defensive)', function () {
    $request = Request::create('/admin');
    $request->setUserResolver(fn() => null);

    (new EnsureUserIsAdmin)->handle($request, fn() => response('ok'));
})->throws(AuthenticationException::class, 'Admin access required');

it('includes the actual role in the exception message for a signed-in non-admin', function () {
    $instructor = User::factory()->instructor()->create();

    $request = Request::create('/admin');
    $request->setUserResolver(fn() => $instructor);

    try {
        (new EnsureUserIsAdmin)->handle($request, fn() => response('ok'));
    } catch (AuthenticationException $e) {
        expect($e->getMessage())
            ->toContain('Admin access required')
            ->toContain('instructor')
            ->toContain('grant admin access');

        return;
    }

    test()->fail('Expected AuthenticationException was not thrown.');
});

it('is registered on the admin panel after Filament::Authenticate in the authMiddleware chain', function () {
    $reflection = new ReflectionClass(AdminPanelProvider::class);
    $source = file_get_contents($reflection->getFileName());

    // Authenticate must come first so EnsureUserIsAdmin can rely on a non-null user.
    $authPos = strpos($source, 'Authenticate::class');
    $ensurePos = strpos($source, 'EnsureUserIsAdmin::class');

    expect($authPos)->not->toBeFalse()
        ->and($ensurePos)->not->toBeFalse()
        ->and($authPos)->toBeLessThan($ensurePos);
});
