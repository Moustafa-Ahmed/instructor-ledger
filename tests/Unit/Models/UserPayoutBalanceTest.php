<?php

declare(strict_types=1);

use App\Enums\LedgerEntryType;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('payoutBalance returns zeros when the relation is loaded but empty', function () {
    $instructor = User::factory()->instructor()->create();
    $instructor->load('payoutLedgerEntries');

    expect($instructor->payoutBalance())->toBe(['earned_cents' => 0, 'paid_cents' => 0, 'outstanding_cents' => 0]);
});

it('payoutBalance aggregates the eager-loaded relation correctly', function () {
    $instructor = User::factory()->instructor()->create();

    LedgerEntry::factory()->instructorPayout(2026, 6, 1000, $instructor)
        ->create(['meta' => ['status' => 'sent']]);
    LedgerEntry::factory()->instructorPayout(2026, 7, 500, $instructor)
        ->create(['meta' => ['status' => 'reconciling']]);
    LedgerEntry::factory()->instructorPayout(2026, 8, 200, $instructor)
        ->create(['meta' => ['status' => 'failed']]);

    $instructor->load('payoutLedgerEntries');

    expect($instructor->payoutBalance())->toBe(['earned_cents' => 1700, 'paid_cents' => 1000, 'outstanding_cents' => 1400]);
});

it('payoutBalance is a no-op on the DB when the relation is already loaded (no per-row query)', function () {
    // The eager-load shape: the resource calls ->with(['payoutLedgerEntries'])
    // on the user query. Asserts that calling payoutBalance() on a loaded
    // user does NOT trigger any additional query.
    $instructor = User::factory()->instructor()->create();
    LedgerEntry::factory()->instructorPayout(2026, 6, 1000, $instructor)
        ->create(['meta' => ['status' => 'sent']]);

    $instructor->load('payoutLedgerEntries');

    DB::enableQueryLog();
    DB::flushQueryLog();

    $balance = $instructor->payoutBalance();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($balance)->toBe(['earned_cents' => 1000, 'paid_cents' => 1000, 'outstanding_cents' => 0])
        ->and($queries)->toBe([]);
});

it('payoutBalance falls back to the service when the relation is NOT loaded (defensive)', function () {
    // If a future caller forgets the eager-load, the fallback routes through
    // InstructorBalanceService. Behavior is correct, just less efficient —
    // we assert correctness, not query count.
    $instructor = User::factory()->instructor()->create();
    LedgerEntry::factory()->instructorPayout(2026, 6, 1000, $instructor)
        ->create(['meta' => ['status' => 'sent']]);

    expect($instructor->payoutBalance())->toBe(['earned_cents' => 1000, 'paid_cents' => 1000, 'outstanding_cents' => 0]);
});

it('payoutLedgerEntries only returns instructor_payout rows, not other types', function () {
    $instructor = User::factory()->instructor()->create();

    LedgerEntry::factory()->instructorPayout(2026, 6, 1000, $instructor)
        ->create(['meta' => ['status' => 'sent']]);

    LedgerEntry::factory()->create([
        'user_id' => $instructor->id,
        'type' => LedgerEntryType::SubscriptionPayment,
        'amount_cents' => 500,
    ]);

    $instructor->load('payoutLedgerEntries');

    expect($instructor->payoutLedgerEntries)->toHaveCount(1)
        ->and($instructor->payoutLedgerEntries->first()->type)->toBe(LedgerEntryType::InstructorPayout);
});
