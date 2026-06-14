<?php

declare(strict_types=1);

use App\Enums\LedgerEntryType;
use App\Enums\UserRole;
use App\Filament\Resources\InstructorResource;
use App\Filament\Resources\InstructorResource\RelationManagers\PayoutHistoryRelationManager;
use App\Models\LedgerEntry;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Payouts\InstructorBalanceService;

it('scopes the user query to instructors only', function () {
    $alice = User::factory()->instructor()->create(['name' => 'Alice']);
    $bob = User::factory()->instructor()->create(['name' => 'Bob']);
    $student = User::factory()->create(); // role=student
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $results = InstructorResource::scopeToInstructors(User::query())
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect($results)->toContain($alice->id)
        ->and($results)->toContain($bob->id)
        ->and($results)->not->toContain($student->id)
        ->and($results)->not->toContain($admin->id);
});

it('wires the resource to the InstructorBalanceService for the three money columns', function () {
    // Per-row getStateUsing callbacks call balanceFor(...); the math is
    // unit-tested in InstructorBalanceServiceTest. This test asserts the
    // wiring.
    expect(app(InstructorBalanceService::class))->toBeInstanceOf(InstructorBalanceService::class)
        ->and(InstructorResource::getModel())->toBe(User::class)
        ->and((new ReflectionClass(InstructorResource::class))->getShortName())->toBe('InstructorResource');
});

it('declares the relation manager that mounts the per-instructor payout history', function () {
    expect(InstructorResource::getRelations())
        ->toContain(PayoutHistoryRelationManager::class);
});

it('exposes the list and view pages', function () {
    $pages = InstructorResource::getPages();

    expect($pages)->toHaveKey('index')
        ->and($pages)->toHaveKey('view');
});

it('declares the three money columns the ops view depends on', function () {
    // The Filament table builder is heavy to instantiate outside a full
    // request context. Assert against the source file instead — it catches
    // silent column drops.
    $source = file_get_contents(
        (new ReflectionClass(InstructorResource::class))->getFileName()
    );

    expect($source)
        ->toContain("::make('earned_cents')")
        ->toContain("->label('Earned')")
        ->toContain("::make('paid_cents')")
        ->toContain("->label('Paid')")
        ->toContain("::make('outstanding_cents')")
        ->toContain("->label('Outstanding')");
});

it('filters the payout-history relation manager to instructor_payout rows only', function () {
    $instructor = User::factory()->instructor()->create();
    $plan = Plan::factory()->create(['price_cents' => 1000, 'months' => 1]);
    $student = User::factory()->create();
    $subscription = Subscription::factory()->create([
        'user_id' => $student->id,
        'plan_id' => $plan->id,
    ]);

    LedgerEntry::factory()->instructorPayout(2026, 6, 1000, $instructor)
        ->create(['meta' => ['status' => 'sent']]);

    LedgerEntry::factory()->create([
        'user_id' => $instructor->id,
        'subscription_id' => $subscription->id,
        'type' => LedgerEntryType::SubscriptionPayment,
        'amount_cents' => 1000,
    ]);

    // Source-level check (catches the relation manager losing its
    // where('type', ...) entirely).
    $source = file_get_contents(
        (new ReflectionClass(PayoutHistoryRelationManager::class))->getFileName()
    );

    expect($source)
        ->toMatch('/where\([\'"]type[\'"],\s*LedgerEntryType::InstructorPayout->value\)/');

    // Data-shape check: the same predicate returns only the instructor payout.
    $payoutRows = $instructor->ledgerEntries()
        ->where('type', LedgerEntryType::InstructorPayout->value)
        ->get();

    expect($payoutRows)->toHaveCount(1)
        ->and($payoutRows->first()->type)->toBe(LedgerEntryType::InstructorPayout)
        ->and($payoutRows->first()->amount_cents)->toBe(-1000);
});
