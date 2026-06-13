<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Models\Course;
use App\Models\LedgerEntry;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Payouts\MonthlyPayoutCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config()->set('ledger.platform_cut_bps', 3000);
});

/**
 * @return LedgerEntry
 */
function charge(int $userId, int $planId, int $year, int $month, int $amountCents, string $type = 'subscription_payment'): LedgerEntry
{
    $subscription = Subscription::factory()->create([
        'user_id' => $userId,
        'plan_id' => $planId,
        'started_at' => CarbonImmutable::create($year, $month, 1)->startOfMonth(),
        'ends_at' => CarbonImmutable::create($year, $month, 1)->startOfMonth()->addMonth(),
        'status' => $type === 'subscription_refund' ? SubscriptionStatus::Refunded : SubscriptionStatus::Active,
        'charged_amount_cents' => abs($amountCents),
    ]);

    return LedgerEntry::factory()->create([
        'subscription_id' => $subscription->id,
        'user_id' => $userId,
        'type' => $type,
        'amount_cents' => $amountCents,
    ]);
}

function teach(User $instructor, Course $course, int $weight = 1): int
{
    $course->instructors()->attach($instructor->id, ['revenue_weight' => $weight]);

    return $instructor->id;
}

it('allocates the full pool to a single instructor when one teaches one course', function () {
    $year = 2026;
    $month = 6;
    $plan = Plan::factory()->create(['price_cents' => 1000, 'months' => 1]);
    $student = User::factory()->create();
    $instructor = User::factory()->instructor()->create();

    charge($student->id, $plan->id, $year, $month, 1000);
    $course = Course::factory()->create();
    teach($instructor, $course);

    $draft = (new MonthlyPayoutCalculator)->calculate($year, $month);

    expect($draft->platformCutCents)->toBe(300)
        ->and($draft->instructorPayouts)->toBe([$instructor->id => 700]);
});

it('splits 50/50 between two equal-weight instructors', function () {
    $year = 2026;
    $month = 6;
    $plan = Plan::factory()->create(['price_cents' => 1000, 'months' => 1]);
    $student = User::factory()->create();
    $alice = User::factory()->instructor()->create();
    $bob = User::factory()->instructor()->create();

    charge($student->id, $plan->id, $year, $month, 1000);
    $course = Course::factory()->create();
    teach($alice, $course);
    teach($bob, $course);

    $draft = (new MonthlyPayoutCalculator)->calculate($year, $month);

    expect($draft->platformCutCents)->toBe(300)
        ->and($draft->instructorPayouts)->toBe([$alice->id => 350, $bob->id => 350]);
});

it('splits proportionally by weight (1:2 ratio)', function () {
    $year = 2026;
    $month = 6;
    $plan = Plan::factory()->create(['price_cents' => 1000, 'months' => 1]);
    $student = User::factory()->create();
    $alice = User::factory()->instructor()->create();
    $bob = User::factory()->instructor()->create();

    charge($student->id, $plan->id, $year, $month, 1000);
    $course = Course::factory()->create();
    teach($alice, $course, 1);
    teach($bob, $course, 2);

    $draft = (new MonthlyPayoutCalculator)->calculate($year, $month);

    expect($draft->platformCutCents)->toBe(300)
        ->and($draft->instructorPayouts)->toBe([$alice->id => 233, $bob->id => 467]);
});

it('sums to instructor_pool exactly for any combination of N and pool', function () {
    $year = 2026;
    $month = 6;
    $plan = Plan::factory()->create(['price_cents' => 1000, 'months' => 1]);
    $student = User::factory()->create();
    $instructors = User::factory()->instructor()->count(5)->create();

    charge($student->id, $plan->id, $year, $month, 1000);
    $course = Course::factory()->create();
    foreach ($instructors as $i) {
        teach($i, $course);
    }

    $draft = (new MonthlyPayoutCalculator)->calculate($year, $month);

    expect($draft->platformCutCents)->toBe(300)
        ->and(array_sum($draft->instructorPayouts))->toBe(700)
        ->and($draft->instructorPayouts)->toBe([
            $instructors[0]->id => 140,
            $instructors[1]->id => 140,
            $instructors[2]->id => 140,
            $instructors[3]->id => 140,
            $instructors[4]->id => 140,
        ]);
});

it('applies the largest-remainder rule with deterministic tie-break by user_id', function () {
    $year = 2026;
    $month = 6;
    $plan = Plan::factory()->create(['price_cents' => 100, 'months' => 1]);
    $student = User::factory()->create();
    $alice = User::factory()->instructor()->create();
    $bob = User::factory()->instructor()->create();
    $carol = User::factory()->instructor()->create();

    charge($student->id, $plan->id, $year, $month, 100);
    $course = Course::factory()->create();
    teach($alice, $course);
    teach($bob, $course);
    teach($carol, $course);

    $draft = (new MonthlyPayoutCalculator)->calculate($year, $month);

    expect($draft->instructorPayouts)->toBe([
        $alice->id => 24,
        $bob->id => 23,
        $carol->id => 23,
    ]);
});

it('distributes multiple leftover cents by remainder ranking', function () {
    $year = 2026;
    $month = 6;
    $plan = Plan::factory()->create(['price_cents' => 1000, 'months' => 1]);
    $student = User::factory()->create();
    $alice = User::factory()->instructor()->create();
    $bob = User::factory()->instructor()->create();
    $carol = User::factory()->instructor()->create();

    charge($student->id, $plan->id, $year, $month, 1000);
    $course = Course::factory()->create();
    teach($alice, $course, 1);
    teach($bob, $course, 2);
    teach($carol, $course, 1);

    $draft = (new MonthlyPayoutCalculator)->calculate($year, $month);

    expect($draft->instructorPayouts)->toBe([
        $alice->id => 175,
        $bob->id => 350,
        $carol->id => 175,
    ]);
});

it('breaks the tie on the extra cent by lowest user_id', function () {
    $year = 2026;
    $month = 6;
    $plan = Plan::factory()->create(['price_cents' => 101, 'months' => 1]);
    $student = User::factory()->create();
    $alice = User::factory()->instructor()->create();
    $bob = User::factory()->instructor()->create();

    charge($student->id, $plan->id, $year, $month, 101);
    $course = Course::factory()->create();
    teach($alice, $course);
    teach($bob, $course);

    $draft = (new MonthlyPayoutCalculator)->calculate($year, $month);

    expect($draft->platformCutCents)->toBe(30)
        ->and(array_sum($draft->instructorPayouts))->toBe(71)
        ->and($draft->instructorPayouts[$alice->id])->toBe(36)
        ->and($draft->instructorPayouts[$bob->id])->toBe(35);
});

it('reduces the pool when a refund is in the period', function () {
    $year = 2026;
    $month = 6;
    $plan = Plan::factory()->create(['price_cents' => 1000, 'months' => 1]);
    $student = User::factory()->create();
    $alice = User::factory()->instructor()->create();
    $bob = User::factory()->instructor()->create();

    charge($student->id, $plan->id, $year, $month, 1000);
    charge($student->id, $plan->id, $year, $month, -400, type: 'subscription_refund');
    $course = Course::factory()->create();
    teach($alice, $course);
    teach($bob, $course);

    $draft = (new MonthlyPayoutCalculator)->calculate($year, $month);

    expect($draft->platformCutCents)->toBe(180)
        ->and($draft->instructorPayouts)->toBe([$alice->id => 210, $bob->id => 210]);
});

it('returns 0 platform cut and no payouts when net is non-positive (full refund)', function () {
    $year = 2026;
    $month = 6;
    $plan = Plan::factory()->create(['price_cents' => 1000, 'months' => 1]);
    $student = User::factory()->create();
    $alice = User::factory()->instructor()->create();

    charge($student->id, $plan->id, $year, $month, 1000);
    charge($student->id, $plan->id, $year, $month, -1500, type: 'subscription_refund');
    $course = Course::factory()->create();
    teach($alice, $course);

    $draft = (new MonthlyPayoutCalculator)->calculate($year, $month);

    expect($draft->platformCutCents)->toBe(0)
        ->and($draft->instructorPayouts)->toBe([]);
});

it('uses config("ledger.platform_cut_bps") for the platform cut', function () {
    config()->set('ledger.platform_cut_bps', 5000);

    $year = 2026;
    $month = 6;
    $plan = Plan::factory()->create(['price_cents' => 1000, 'months' => 1]);
    $student = User::factory()->create();
    $instructor = User::factory()->instructor()->create();

    charge($student->id, $plan->id, $year, $month, 1000);
    $course = Course::factory()->create();
    teach($instructor, $course);

    $draft = (new MonthlyPayoutCalculator)->calculate($year, $month);

    expect($draft->platformCutCents)->toBe(500)
        ->and($draft->instructorPayouts)->toBe([$instructor->id => 500]);
});

it('ensures platform_cut + instructor_pool sum equals net exactly', function () {
    $year = 2026;
    $month = 6;
    $plan = Plan::factory()->create(['price_cents' => 777, 'months' => 1]);
    $student = User::factory()->create();
    $alice = User::factory()->instructor()->create();
    $bob = User::factory()->instructor()->create();
    $carol = User::factory()->instructor()->create();

    charge($student->id, $plan->id, $year, $month, 777);
    $course = Course::factory()->create();
    teach($alice, $course);
    teach($bob, $course);
    teach($carol, $course);

    $draft = (new MonthlyPayoutCalculator)->calculate($year, $month);

    expect($draft->platformCutCents + array_sum($draft->instructorPayouts))->toBe(777);
});

it('returns 0 platform cut and empty payouts for an empty month', function () {
    $draft = (new MonthlyPayoutCalculator)->calculate(2026, 6);

    expect($draft->platformCutCents)->toBe(0)
        ->and($draft->instructorPayouts)->toBe([]);
});

it('gives the full net to the platform when no instructors are attached to any course', function () {
    $year = 2026;
    $month = 6;
    $plan = Plan::factory()->create(['price_cents' => 1000, 'months' => 1]);
    $student = User::factory()->create();

    charge($student->id, $plan->id, $year, $month, 1000);

    $draft = (new MonthlyPayoutCalculator)->calculate($year, $month);

    expect($draft->platformCutCents)->toBe(1000)
        ->and($draft->instructorPayouts)->toBe([]);
});

it('only reads ledger rows whose subscription is in the target month', function () {
    $plan = Plan::factory()->create(['price_cents' => 1000, 'months' => 1]);
    $student = User::factory()->create();
    $instructor = User::factory()->instructor()->create();

    charge($student->id, $plan->id, 2026, 6, 1000);
    charge($student->id, $plan->id, 2026, 7, 2000);

    $course = Course::factory()->create();
    teach($instructor, $course);

    $june = (new MonthlyPayoutCalculator)->calculate(2026, 6);
    $july = (new MonthlyPayoutCalculator)->calculate(2026, 7);

    expect($june->platformCutCents)->toBe(300)
        ->and($june->instructorPayouts)->toBe([$instructor->id => 700])
        ->and($july->platformCutCents)->toBe(600)
        ->and($july->instructorPayouts)->toBe([$instructor->id => 1400]);
});

it('sums an instructor weights across multiple courses', function () {
    $year = 2026;
    $month = 6;
    $plan = Plan::factory()->create(['price_cents' => 1000, 'months' => 1]);
    $student = User::factory()->create();
    $alice = User::factory()->instructor()->create();
    $bob = User::factory()->instructor()->create();

    charge($student->id, $plan->id, $year, $month, 1000);
    $laravel = Course::factory()->create();
    $design = Course::factory()->create();
    $vue = Course::factory()->create();

    teach($alice, $laravel);
    teach($alice, $design);
    teach($bob, $vue);

    $draft = (new MonthlyPayoutCalculator)->calculate($year, $month);

    expect($draft->instructorPayouts)->toBe([
        $alice->id => 467,
        $bob->id => 233,
    ]);
});
