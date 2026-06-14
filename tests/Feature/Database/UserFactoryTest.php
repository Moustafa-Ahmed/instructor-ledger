<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;

it('produces a student by default', function () {
    $user = User::factory()->create();

    expect($user->role)->toBe(UserRole::Student)
        ->and($user->payout_destination)->toBeNull();
});

it('produces an instructor via the instructor() state', function () {
    $user = User::factory()->instructor()->create();

    expect($user->role)->toBe(UserRole::Instructor)
        ->and($user->payout_destination)->toStartWith('acct_');
});

it('produces an admin via the admin() state with a null payout_destination', function () {
    $user = User::factory()->admin()->create();

    expect($user->role)->toBe(UserRole::Admin)
        ->and($user->payout_destination)->toBeNull();
});

it('admin() sets no email or password (those come from the definition)', function () {
    $user = User::factory()->admin()->create();

    expect($user->email)->toContain('@')
        ->and($user->password)->not->toBeNull();
});
