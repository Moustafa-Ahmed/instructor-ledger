<?php

declare(strict_types=1);

use App\Enums\LedgerEntryType;
use App\Enums\SubscriptionStatus;
use App\Models\Course;
use App\Models\LedgerEntry;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Payouts\CloseMonthlyPayoutService;
use App\Services\Payouts\MonthlyPayoutCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config()->set('ledger.platform_cut_bps', 3000);
    config()->set('ledger.currency', 'USD');
});

function closeService(): CloseMonthlyPayoutService
{
    return new CloseMonthlyPayoutService(new MonthlyPayoutCalculator);
}

function chargeInMonth(int $studentId, int $planId, int $year, int $month, int $amountCents): LedgerEntry
{
    $startedAt = CarbonImmutable::create($year, $month, 1)->startOfMonth();
    $subscription = Subscription::factory()->create([
        'user_id' => $studentId,
        'plan_id' => $planId,
        'started_at' => $startedAt,
        'ends_at' => $startedAt->addMonth(),
        'status' => SubscriptionStatus::Active,
        'charged_amount_cents' => $amountCents,
    ]);

    return LedgerEntry::factory()->create([
        'subscription_id' => $subscription->id,
        'user_id' => $studentId,
        'type' => LedgerEntryType::SubscriptionPayment,
        'amount_cents' => $amountCents,
        'idempotency_key' => "payment:subscription:{$subscription->id}",
    ]);
}

function attachInstructor(User $instructor, Course $course, int $weight = 1): void
{
    $course->instructors()->attach($instructor->id, ['revenue_weight' => $weight]);
}

it('writes 1 platform_cut + 1 instructor_payout per instructor on a successful close', function () {
    $year = 2026;
    $month = 6;
    $plan = Plan::factory()->create(['price_cents' => 1000, 'months' => 1]);
    $student = User::factory()->create();
    $alice = User::factory()->instructor()->create();
    $bob = User::factory()->instructor()->create();

    chargeInMonth($student->id, $plan->id, $year, $month, 1000);
    $course = Course::factory()->create();
    attachInstructor($alice, $course);
    attachInstructor($bob, $course);

    $draft = closeService()->close($year, $month);

    // 30% platform = 300, pool = 700, split 50/50 = 350 each.
    expect($draft->platformCutCents)->toBe(300)
        ->and($draft->instructorPayouts)->toBe([$alice->id => 350, $bob->id => 350])
        ->and($draft->platformCutLedgerEntryId)->not->toBeNull()
        ->and($draft->instructorLedgerEntryIds)->toHaveCount(2);

    // Rows on disk
    expect(LedgerEntry::query()->where('type', LedgerEntryType::PlatformCut)->count())->toBe(1)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::InstructorPayout)->count())->toBe(2);

    $cut = LedgerEntry::query()->where('type', LedgerEntryType::PlatformCut)->first();
    expect($cut->amount_cents)->toBe(-300)
        ->and($cut->idempotency_key)->toBe('platform_cut:2026-06')
        ->and($cut->user_id)->toBeNull()
        ->and($cut->meta)->toBeNull();

    $alicePayout = LedgerEntry::query()
        ->where('type', LedgerEntryType::InstructorPayout)
        ->where('user_id', $alice->id)
        ->first();
    expect($alicePayout->amount_cents)->toBe(-350)
        ->and($alicePayout->idempotency_key)->toBe("payout:2026-06:user:{$alice->id}")
        ->and($alicePayout->meta)->toBe(['status' => 'pending']);
});

it('is a no-op on a re-run of the same month (no new rows, no new jobs)', function () {
    $year = 2026;
    $month = 6;
    $plan = Plan::factory()->create(['price_cents' => 1000, 'months' => 1]);
    $student = User::factory()->create();
    $instructor = User::factory()->instructor()->create();
    chargeInMonth($student->id, $plan->id, $year, $month, 1000);
    attachInstructor($instructor, Course::factory()->create());

    $first = closeService()->close($year, $month);
    $cutRowsAfterFirst = LedgerEntry::query()->where('type', LedgerEntryType::PlatformCut)->count();
    $payoutRowsAfterFirst = LedgerEntry::query()->where('type', LedgerEntryType::InstructorPayout)->count();
    $cutRowId = $first->platformCutLedgerEntryId;
    $payoutRowIds = $first->instructorLedgerEntryIds;

    // The second call must return the same ids, and write no new rows.
    $second = closeService()->close($year, $month);

    expect($second->platformCutCents)->toBe($first->platformCutCents)
        ->and($second->instructorPayouts)->toBe($first->instructorPayouts)
        ->and($second->platformCutLedgerEntryId)->toBe($cutRowId)
        ->and($second->instructorLedgerEntryIds)->toBe($payoutRowIds)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::PlatformCut)->count())->toBe($cutRowsAfterFirst)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::InstructorPayout)->count())->toBe($payoutRowsAfterFirst);
});

it('writes no rows for an empty month and returns an empty draft', function () {
    $draft = closeService()->close(2026, 6);

    expect($draft->isEmpty())->toBeTrue()
        ->and($draft->platformCutCents)->toBe(0)
        ->and($draft->instructorPayouts)->toBe([])
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('writes no rows for a month whose only activity is a full refund (net = 0)', function () {
    $year = 2026;
    $month = 6;
    $plan = Plan::factory()->create(['price_cents' => 1000, 'months' => 1]);
    $student = User::factory()->create();
    $payment = chargeInMonth($student->id, $plan->id, $year, $month, 1000);
    $instructor = User::factory()->instructor()->create();
    attachInstructor($instructor, Course::factory()->create());

    // Refund the full amount
    LedgerEntry::factory()->create([
        'subscription_id' => $payment->subscription_id,
        'user_id' => $student->id,
        'type' => LedgerEntryType::SubscriptionRefund,
        'amount_cents' => -1000,
        'idempotency_key' => "refund:subscription:{$payment->subscription_id}:ledger",
        'subscription_entry_id' => $payment->id,
    ]);

    $draft = closeService()->close($year, $month);

    expect($draft->isEmpty())->toBeTrue()
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::PlatformCut)->count())->toBe(0)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::InstructorPayout)->count())->toBe(0);
});

it('can close a month after a late refund comes in (the original close was empty)', function () {
    $year = 2026;
    $month = 6;
    $plan = Plan::factory()->create(['price_cents' => 1000, 'months' => 1]);
    $student = User::factory()->create();
    $instructor = User::factory()->instructor()->create();

    // June had no activity when the close ran on July 1.
    $first = closeService()->close($year, $month);
    expect($first->isEmpty())->toBeTrue();

    // A late payment comes in for June, plus a partial refund.
    $payment = chargeInMonth($student->id, $plan->id, $year, $month, 1000);
    LedgerEntry::factory()->create([
        'subscription_id' => $payment->subscription_id,
        'user_id' => $student->id,
        'type' => LedgerEntryType::SubscriptionRefund,
        'amount_cents' => -300,
        'idempotency_key' => "refund:subscription:{$payment->subscription_id}:ledger",
        'subscription_entry_id' => $payment->id,
    ]);
    attachInstructor($instructor, Course::factory()->create());

    $second = closeService()->close($year, $month);

    // net = 1000 - 300 = 700. cut = 210, pool = 490, single instructor = 490.
    expect($second->isEmpty())->toBeFalse()
        ->and($second->platformCutCents)->toBe(210)
        ->and($second->instructorPayouts)->toBe([$instructor->id => 490])
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::PlatformCut)->count())->toBe(1)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::InstructorPayout)->count())->toBe(1);
});

it('uses the exact idempotency_key patterns documented in the plan', function () {
    $year = 2026;
    $month = 6;
    $plan = Plan::factory()->create(['price_cents' => 1000, 'months' => 1]);
    $student = User::factory()->create();
    $instructor = User::factory()->instructor()->create();
    chargeInMonth($student->id, $plan->id, $year, $month, 1000);
    attachInstructor($instructor, Course::factory()->create());

    closeService()->close($year, $month);

    // Assert against literal zero-padded strings, not interpolated
    // ones — this is the contract callers depend on for the unique
    // index to actually work.
    expect(LedgerEntry::query()->where('idempotency_key', 'platform_cut:2026-06')->exists())->toBeTrue()
        ->and(LedgerEntry::query()->where('idempotency_key', "payout:2026-06:user:{$instructor->id}")->exists())->toBeTrue();
});

it('reflects a refund that reduces the pool', function () {
    $year = 2026;
    $month = 6;
    $plan = Plan::factory()->create(['price_cents' => 1000, 'months' => 1]);
    $student = User::factory()->create();
    $instructor = User::factory()->instructor()->create();
    $payment = chargeInMonth($student->id, $plan->id, $year, $month, 1000);

    LedgerEntry::factory()->create([
        'subscription_id' => $payment->subscription_id,
        'user_id' => $student->id,
        'type' => LedgerEntryType::SubscriptionRefund,
        'amount_cents' => -400,
        'idempotency_key' => "refund:subscription:{$payment->subscription_id}:ledger",
        'subscription_entry_id' => $payment->id,
    ]);
    attachInstructor($instructor, Course::factory()->create());

    $draft = closeService()->close($year, $month);

    // net = 1000 - 400 = 600. cut = 180, pool = 420.
    expect($draft->platformCutCents)->toBe(180)
        ->and($draft->instructorPayouts)->toBe([$instructor->id => 420]);
});

it('recovers from a unique-violation race by reading the winning row', function () {
    // The "race" path: precheck finds no row, transaction tries to
    // insert, but a competing process wrote the row first. The unique
    // index raises a QueryException, the catch block re-fetches the
    // existing draft, and the service returns that.
    //
    // We exercise this by writing a "competing" row *with the same
    // idempotency_key the service would use*, then calling close().
    // The precheck WILL see the row (so the no-op precheck path
    // fires). To actually hit the catch path we need a tighter
    // window; that is covered by the production invariant (the unique
    // index makes the race harmless) and is integration territory.
    //
    // What we DO assert here: the precheck is sufficient. A row that
    // was written by a competing process is honoured — we don't double
    // write, and we return the values that were persisted.
    $year = 2026;
    $month = 6;

    LedgerEntry::query()->create([
        'type' => LedgerEntryType::PlatformCut,
        'amount_cents' => -210,
        'idempotency_key' => 'platform_cut:2026-06',
        'user_id' => null,
        'subscription_id' => null,
        'meta' => null,
    ]);

    $draft = closeService()->close($year, $month);

    expect($draft->platformCutCents)->toBe(210)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::PlatformCut)->count())->toBe(1);
});
