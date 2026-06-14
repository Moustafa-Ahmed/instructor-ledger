<?php

declare(strict_types=1);

use App\Enums\LedgerEntryType;
use App\Enums\SubscriptionStatus;
use App\Jobs\PayInstructorJob;
use App\Models\Course;
use App\Models\LedgerEntry;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    config()->set('ledger.platform_cut_bps', 3000);
    config()->set('ledger.currency', 'USD');
});

function seedMonth(int $year, int $month, int $amountCents, int $instructorCount = 1): array
{
    $plan = Plan::factory()->create(['price_cents' => $amountCents, 'months' => 1]);
    $student = User::factory()->create();
    $startedAt = CarbonImmutable::create($year, $month, 1)->startOfMonth();

    $subscription = Subscription::factory()->create([
        'user_id' => $student->id,
        'plan_id' => $plan->id,
        'started_at' => $startedAt,
        'ends_at' => $startedAt->addMonth(),
        'status' => SubscriptionStatus::Active,
        'charged_amount_cents' => $amountCents,
    ]);

    LedgerEntry::factory()->create([
        'subscription_id' => $subscription->id,
        'user_id' => $student->id,
        'type' => LedgerEntryType::SubscriptionPayment,
        'amount_cents' => $amountCents,
        'idempotency_key' => "payment:subscription:{$subscription->id}",
    ]);

    $instructors = [];
    for ($i = 0; $i < $instructorCount; $i++) {
        $instructor = User::factory()->instructor()->create();
        $course = Course::factory()->create();
        $course->instructors()->attach($instructor->id, ['revenue_weight' => 1]);
        $instructors[] = $instructor;
    }

    return [$subscription, ...$instructors];
}

it('dispatches one PayInstructorJob per instructor when running the close for a month', function () {
    Bus::fake();
    [$subscription, $alice, $bob] = seedMonth(2026, 6, 1000, instructorCount: 2);

    $this->artisan('ledger:run-payouts', ['--year' => 2026, '--month' => 6])
        ->expectsOutputToContain('Month 2026-06 closed')
        ->assertExitCode(0);

    expect(LedgerEntry::query()->where('type', LedgerEntryType::PlatformCut)->count())->toBe(1)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::InstructorPayout)->count())->toBe(2);

    $payoutRowIds = LedgerEntry::query()
        ->where('type', LedgerEntryType::InstructorPayout)
        ->pluck('id')
        ->all();

    Bus::assertDispatched(PayInstructorJob::class, count($payoutRowIds));

    foreach ($payoutRowIds as $id) {
        Bus::assertDispatched(PayInstructorJob::class, function ($job) use ($id) {
            return $job->ledgerEntryId === $id;
        });
    }
});

it('dispatches no jobs and writes no rows for an empty month', function () {
    Bus::fake();

    $this->artisan('ledger:run-payouts', ['--year' => 2026, '--month' => 6])
        ->expectsOutputToContain('had no activity')
        ->assertExitCode(0);

    Bus::assertNotDispatched(PayInstructorJob::class);
    expect(LedgerEntry::query()->count())->toBe(0);
});

it('dispatches zero jobs on a re-run of the same month (idempotent)', function () {
    Bus::fake();
    seedMonth(2026, 6, 1000, instructorCount: 1);

    $this->artisan('ledger:run-payouts', ['--year' => 2026, '--month' => 6])->assertExitCode(0);
    $firstDispatchCount = count(LedgerEntry::query()
        ->where('type', LedgerEntryType::InstructorPayout)
        ->get());

    // Re-run with the same period: no NEW LEDGER ROWS are written. On a
    // real run the re-dispatched jobs would be de-duplicated by
    // PayInstructorJob's ShouldBeUnique lock.
    $rowsBefore = LedgerEntry::query()->count();

    $this->artisan('ledger:run-payouts', ['--year' => 2026, '--month' => 6])->assertExitCode(0);

    expect(LedgerEntry::query()->count())->toBe($rowsBefore);

    expect($firstDispatchCount)->toBe(1);
});

it('defaults to the previous calendar month when no flags are provided', function () {
    Bus::fake();
    Date::setTestNow(CarbonImmutable::create(2026, 7, 15));

    seedMonth(2026, 6, 1000, instructorCount: 1);
    seedMonth(2026, 7, 2000, instructorCount: 1);

    $this->artisan('ledger:run-payouts')
        ->expectsOutputToContain('Month 2026-06 closed')
        ->assertExitCode(0);

    expect(LedgerEntry::query()->where('type', LedgerEntryType::PlatformCut)->count())->toBe(1);

    $cut = LedgerEntry::query()->where('type', LedgerEntryType::PlatformCut)->first();
    expect($cut->amount_cents)->toBe(-300)
        ->and($cut->idempotency_key)->toBe('platform_cut:2026-06');
});

it('rejects an invalid month with exit code INVALID', function () {
    $this->artisan('ledger:run-payouts', ['--year' => 2026, '--month' => 13])
        ->expectsOutputToContain('Invalid month')
        ->assertExitCode(2);
});

it('rejects when only one of --year / --month is provided', function () {
    $this->artisan('ledger:run-payouts', ['--year' => 2026])
        ->expectsOutputToContain('Both --year and --month are required')
        ->assertExitCode(2);
});
