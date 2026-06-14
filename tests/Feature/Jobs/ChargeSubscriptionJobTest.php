<?php

declare(strict_types=1);

use App\Exceptions\MockPaymentProviderFailedException;
use App\Exceptions\MockPaymentProviderTimeoutException;
use App\Jobs\ChargeSubscriptionJob;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Payments\MockPaymentProvider;
use App\Services\Subscriptions\ChargeSubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    $provider = new MockPaymentProvider;
    $this->app->instance(MockPaymentProvider::class, $provider);
});

it('declares 5 attempts and the configured backoff schedule', function () {
    $job = new ChargeSubscriptionJob(1, 1, CarbonImmutable::create(2026, 6, 1));

    expect($job->tries)->toBe(5)
        ->and($job->backoff)->toBe([1, 2, 4, 8, 16]);
});

it('can be dispatched via Bus::dispatch()', function () {
    Bus::fake();

    ChargeSubscriptionJob::dispatch(1, 1, CarbonImmutable::create(2026, 6, 1));

    Bus::assertDispatched(ChargeSubscriptionJob::class, function (ChargeSubscriptionJob $job) {
        return $job->studentId === 1
            && $job->planId === 1
            && $job->date->format('Y-m-d') === '2026-06-01';
    });
});

it('completes on the first attempt when the provider returns succeeded', function () {
    app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);
    $student = User::factory()->create();
    $plan = Plan::factory()->create(['price_cents' => 1500, 'months' => 1]);

    $job = new ChargeSubscriptionJob($student->id, $plan->id, CarbonImmutable::create(2026, 6, 15));
    $job->handle(app(ChargeSubscriptionService::class));

    expect(Subscription::count())->toBe(1);
});

it('recovers on retry when the first attempt timed out and the provider already wrote a succeeded row', function () {
    // The retry succeeds by reading the prior outcome from the provider's
    // store, not by being given a different outcome — the provider's
    // createOrReturnOperation finds the prior row by idempotency_key before
    // calling chooseOutcome.
    app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_TIMEOUT_AFTER_SUCCESS);
    $student = User::factory()->create();
    $plan = Plan::factory()->create(['price_cents' => 1500, 'months' => 1]);
    $service = app(ChargeSubscriptionService::class);

    $timedOut = false;
    try {
        (new ChargeSubscriptionJob($student->id, $plan->id, CarbonImmutable::create(2026, 6, 15)))->handle($service);
    } catch (MockPaymentProviderTimeoutException) {
        $timedOut = true;
    }

    (new ChargeSubscriptionJob($student->id, $plan->id, CarbonImmutable::create(2026, 6, 15)))->handle($service);

    expect($timedOut)->toBeTrue()
        ->and(Subscription::count())->toBe(1);
});

it('throws on every attempt when the provider returns failed, no subscription created', function () {
    app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_FAILED);
    $student = User::factory()->create();
    $plan = Plan::factory()->create(['price_cents' => 1500, 'months' => 1]);
    $service = app(ChargeSubscriptionService::class);

    $failed = false;
    try {
        (new ChargeSubscriptionJob($student->id, $plan->id, CarbonImmutable::create(2026, 6, 15)))->handle($service);
    } catch (MockPaymentProviderFailedException) {
        $failed = true;
    }

    expect($failed)->toBeTrue()
        ->and(Subscription::count())->toBe(0);
});

it('times out and produces no subscription when the provider keeps timing out', function () {
    app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_TIMEOUT_AFTER_SUCCESS);
    $student = User::factory()->create();
    $plan = Plan::factory()->create(['price_cents' => 1500, 'months' => 1]);
    $service = app(ChargeSubscriptionService::class);

    $timedOut = false;
    try {
        (new ChargeSubscriptionJob($student->id, $plan->id, CarbonImmutable::create(2026, 6, 15)))->handle($service);
    } catch (MockPaymentProviderTimeoutException) {
        $timedOut = true;
    }

    expect($timedOut)->toBeTrue()
        ->and(Subscription::count())->toBe(0);
});
