<?php

declare(strict_types=1);

use App\Enums\LedgerEntryType;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Payouts\InstructorBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function instructorWithPayouts(): User
{
    return User::factory()->instructor()->create();
}

function payout(User $instructor, int $amountCents, array $meta = ['status' => 'pending']): LedgerEntry
{
    return LedgerEntry::factory()->instructorPayout(2026, 6, $amountCents, $instructor)
        ->create(['meta' => $meta]);
}

it('returns zero across the board for an instructor with no payouts', function () {
    $instructor = instructorWithPayouts();

    $balance = app(InstructorBalanceService::class)->balanceFor($instructor->id);

    expect($balance)->toBe(['earned_cents' => 0, 'paid_cents' => 0, 'outstanding_cents' => 0]);
});

it('counts a sent payout as earned AND paid', function () {
    $instructor = instructorWithPayouts();
    payout($instructor, 1000, ['status' => 'sent', 'sent_at' => '2026-07-01T00:00:00Z']);

    $balance = app(InstructorBalanceService::class)->balanceFor($instructor->id);

    expect($balance)->toBe(['earned_cents' => 1000, 'paid_cents' => 1000, 'outstanding_cents' => 0]);
});

it('counts a pending payout as earned but not paid (outstanding = earned)', function () {
    $instructor = instructorWithPayouts();
    payout($instructor, 1000, ['status' => 'pending']);

    $balance = app(InstructorBalanceService::class)->balanceFor($instructor->id);

    expect($balance)->toBe(['earned_cents' => 1000, 'paid_cents' => 0, 'outstanding_cents' => 1000]);
});

it('counts a reconciling payout as earned, NOT paid, AND in outstanding (conservative)', function () {
    $instructor = instructorWithPayouts();
    payout($instructor, 1000, ['status' => 'reconciling', 'reconciling_at' => '2026-07-01T00:00:00Z']);

    $balance = app(InstructorBalanceService::class)->balanceFor($instructor->id);

    // earned=1000, paid=0, outstanding = 1000 - 0 + 1000 = 2000
    // (reconciling amount is added to outstanding as a pending liability)
    expect($balance)->toBe(['earned_cents' => 1000, 'paid_cents' => 0, 'outstanding_cents' => 2000]);
});

it('counts a failed payout as earned, NOT paid, AND in outstanding (conservative)', function () {
    $instructor = instructorWithPayouts();
    payout($instructor, 1000, ['status' => 'failed', 'error' => 'declined']);

    $balance = app(InstructorBalanceService::class)->balanceFor($instructor->id);

    expect($balance)->toBe(['earned_cents' => 1000, 'paid_cents' => 0, 'outstanding_cents' => 2000]);
});

it('sums across multiple months correctly', function () {
    $instructor = instructorWithPayouts();

    payout($instructor, 1000, ['status' => 'sent']);
    LedgerEntry::factory()->instructorPayout(2026, 7, 1500, $instructor)
        ->create(['meta' => ['status' => 'pending']]);
    LedgerEntry::factory()->instructorPayout(2026, 8, 2000, $instructor)
        ->create(['meta' => ['status' => 'failed']]);

    $balance = app(InstructorBalanceService::class)->balanceFor($instructor->id);

    // earned = 4500, paid = 1000 (the sent one), outstanding = 4500 - 1000 + 2000
    // (failed is in_flight). The 1500 pending is already counted via earned - paid.
    expect($balance['earned_cents'])->toBe(4500)
        ->and($balance['paid_cents'])->toBe(1000)
        ->and($balance['outstanding_cents'])->toBe(5500);
});

it('ignores non-instructor_payout ledger rows', function () {
    $instructor = instructorWithPayouts();
    payout($instructor, 1000, ['status' => 'sent']);

    LedgerEntry::factory()->create([
        'user_id' => $instructor->id,
        'type' => LedgerEntryType::SubscriptionPayment,
        'amount_cents' => 999999,
    ]);

    LedgerEntry::factory()->platformCut(2026, 6, 1000)->create();

    $balance = app(InstructorBalanceService::class)->balanceFor($instructor->id);

    expect($balance['earned_cents'])->toBe(1000)
        ->and($balance['paid_cents'])->toBe(1000)
        ->and($balance['outstanding_cents'])->toBe(0);
});

it('isolates balance per instructor', function () {
    $alice = instructorWithPayouts();
    $bob = instructorWithPayouts();
    payout($alice, 1000, ['status' => 'sent']);
    payout($bob, 5000, ['status' => 'sent']);

    LedgerEntry::factory()->instructorPayout(2026, 7, 2000, $bob)
        ->create(['meta' => ['status' => 'reconciling']]);

    $aliceBalance = app(InstructorBalanceService::class)->balanceFor($alice->id);
    $bobBalance = app(InstructorBalanceService::class)->balanceFor($bob->id);

    expect($aliceBalance)->toBe(['earned_cents' => 1000, 'paid_cents' => 1000, 'outstanding_cents' => 0])
        ->and($bobBalance)->toBe(['earned_cents' => 7000, 'paid_cents' => 5000, 'outstanding_cents' => 4000]);
});

it('treats a row with null meta as pending (legacy rows)', function () {
    $instructor = instructorWithPayouts();
    payout($instructor, 1000, []);

    $balance = app(InstructorBalanceService::class)->balanceFor($instructor->id);

    // null/empty meta → defaults to pending → earned, not paid.
    expect($balance)->toBe(['earned_cents' => 1000, 'paid_cents' => 0, 'outstanding_cents' => 1000]);
});

it('treats meta with no status key as pending (defensive default)', function () {
    $instructor = instructorWithPayouts();
    payout($instructor, 1000, ['reconciling_at' => '2026-07-01T00:00:00Z']); // no 'status' key

    $balance = app(InstructorBalanceService::class)->balanceFor($instructor->id);

    expect($balance)->toBe(['earned_cents' => 1000, 'paid_cents' => 0, 'outstanding_cents' => 1000]);
});

it('never returns negative paid_cents', function () {
    // Defensive: even with a weird DB state, the math should not produce
    // nonsensical results.
    $instructor = instructorWithPayouts();
    payout($instructor, 1000, ['status' => 'sent']);

    $balance = app(InstructorBalanceService::class)->balanceFor($instructor->id);

    expect($balance['paid_cents'])->toBeGreaterThanOrEqual(0)
        ->and($balance['earned_cents'])->toBeGreaterThanOrEqual(0);
});

it('balancesForMany returns an empty map for an empty input', function () {
    expect(app(InstructorBalanceService::class)->balancesForMany([]))->toBe([]);
});

it('balancesForMany seeds a zero entry for users with no payouts', function () {
    $alice = instructorWithPayouts();
    $bob = instructorWithPayouts();

    $result = app(InstructorBalanceService::class)->balancesForMany([$alice->id, $bob->id]);

    expect($result)->toHaveCount(2)
        ->and($result[$alice->id])->toBe(['earned_cents' => 0, 'paid_cents' => 0, 'outstanding_cents' => 0])
        ->and($result[$bob->id])->toBe(['earned_cents' => 0, 'paid_cents' => 0, 'outstanding_cents' => 0]);
});

it('balancesForMany aggregates correctly across multiple users and multiple states', function () {
    $alice = instructorWithPayouts();
    $bob = instructorWithPayouts();

    payout($alice, 1000, ['status' => 'sent']);
    LedgerEntry::factory()->instructorPayout(2026, 7, 500, $alice)
        ->create(['meta' => ['status' => 'reconciling']]);

    payout($bob, 2000, ['status' => 'sent']);
    LedgerEntry::factory()->instructorPayout(2026, 7, 800, $bob)
        ->create(['meta' => ['status' => 'failed']]);

    $result = app(InstructorBalanceService::class)->balancesForMany([$alice->id, $bob->id]);

    expect($result[$alice->id])->toBe(['earned_cents' => 1500, 'paid_cents' => 1000, 'outstanding_cents' => 1000])
        ->and($result[$bob->id])->toBe(['earned_cents' => 2800, 'paid_cents' => 2000, 'outstanding_cents' => 1600]);
});

it('balancesForMany uses a single SQL query regardless of input size (no N+1)', function () {
    // N+1 regression net: if a future refactor accidentally reintroduces the
    // per-user pattern, this test fails.
    DB::enableQueryLog();

    $users = [];
    for ($i = 0; $i < 5; $i++) {
        $users[] = instructorWithPayouts();
    }

    DB::flushQueryLog();
    app(InstructorBalanceService::class)->balancesForMany(array_map(fn($u) => $u->id, $users));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $ledgerSelects = array_filter(
        $queries,
        fn($q) => str_contains($q['query'], 'from "ledger_entries"')
            && str_starts_with(trim($q['query']), 'select'),
    );

    expect(count($ledgerSelects))->toBe(1)
        ->and(count($queries))->toBe(1);
});

it('balancesForMany ignores users not in the input list', function () {
    $alice = instructorWithPayouts();
    $bob = instructorWithPayouts();
    payout($alice, 1000, ['status' => 'sent']);
    payout($bob, 5000, ['status' => 'sent']);

    // Query only for Alice; Bob's row should not be in the result.
    $result = app(InstructorBalanceService::class)->balancesForMany([$alice->id]);

    expect($result)->toHaveCount(1)
        ->and($result[$alice->id]['earned_cents'])->toBe(1000);
});
it('balanceFor delegates to balancesForMany and is consistent with the batched result', function () {
    $alice = instructorWithPayouts();
    payout($alice, 1000, ['status' => 'sent']);
    LedgerEntry::factory()->instructorPayout(2026, 7, 500, $alice)
        ->create(['meta' => ['status' => 'reconciling']]);

    $single = app(InstructorBalanceService::class)->balanceFor($alice->id);
    $batched = app(InstructorBalanceService::class)->balancesForMany([$alice->id]);

    expect($single)->toBe($batched[$alice->id]);
});
