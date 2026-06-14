<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\ChargeSubscriptionJob;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

it('dispatches a ChargeSubscriptionJob with the right args on a valid invocation', function () {
    Bus::fake();
    $student = User::factory()->create(['role' => UserRole::Student]);
    $plan = Plan::factory()->create(['months' => 1]);

    $this->artisan('ledger:charge-subscription', [
        'student_id' => $student->id,
        'plan_id' => $plan->id,
        'date' => '2026-06-15',
    ])->assertExitCode(0);

    Bus::assertDispatched(ChargeSubscriptionJob::class, function (ChargeSubscriptionJob $job) use ($student, $plan) {
        return $job->studentId === $student->id
            && $job->planId === $plan->id
            && $job->date->format('Y-m-d') === '2026-06-15';
    });
});

it('rejects an invalid date format with exit code INVALID', function () {
    $student = User::factory()->create(['role' => UserRole::Student]);
    $plan = Plan::factory()->create(['months' => 1]);

    $this->artisan('ledger:charge-subscription', [
        'student_id' => $student->id,
        'plan_id' => $plan->id,
        'date' => '15-06-2026',
    ])->expectsOutputToContain('Invalid date')
        ->assertExitCode(2);
});

it('rejects an unknown student id with exit code FAILURE', function () {
    $plan = Plan::factory()->create(['months' => 1]);

    $this->artisan('ledger:charge-subscription', [
        'student_id' => 9999,
        'plan_id' => $plan->id,
        'date' => '2026-06-15',
    ])->expectsOutputToContain('Student #9999 not found')
        ->assertExitCode(1);
});

it('rejects a non-student user with exit code FAILURE', function () {
    $instructor = User::factory()->create(['role' => UserRole::Instructor]);
    $plan = Plan::factory()->create(['months' => 1]);

    $this->artisan('ledger:charge-subscription', [
        'student_id' => $instructor->id,
        'plan_id' => $plan->id,
        'date' => '2026-06-15',
    ])->expectsOutputToContain("Student #{$instructor->id} not found")
        ->assertExitCode(1);
});
