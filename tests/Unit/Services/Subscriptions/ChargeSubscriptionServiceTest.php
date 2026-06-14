<?php

declare(strict_types=1);

use App\Enums\LedgerEntryType;
use App\Enums\UserRole;
use App\Exceptions\MockPaymentProviderFailedException;
use App\Exceptions\MockPaymentProviderTimeoutException;
use App\Models\LedgerEntry;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Payments\MockPaymentProvider;
use App\Services\Subscriptions\ChargeSubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->app->instance(MockPaymentProvider::class, new MockPaymentProvider);
});

function chargeService(): ChargeSubscriptionService
{
    return app(ChargeSubscriptionService::class);
}

function makeStudent(string $role = 'student'): User
{
    return User::factory()->create(['role' => $role === 'instructor' ? UserRole::Instructor : UserRole::Student]);
}

function makePlan(int $priceCents = 1000): Plan
{
    return Plan::factory()->create(['price_cents' => $priceCents, 'months' => 1]);
}

it('writes a subscription and a ledger entry on a successful charge', function () {
    app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);
    $student = makeStudent();
    $plan = makePlan(1500);

    $subscription = chargeService()->charge($student->id, $plan->id, CarbonImmutable::create(2026, 6, 15));

    expect($subscription->user_id)->toBe($student->id)
        ->and($subscription->plan_id)->toBe($plan->id)
        ->and($subscription->charged_amount_cents)->toBe(1500)
        ->and($subscription->provider_charge_reference)->toBe("ch:{$student->id}:2026-6")
        ->and($subscription->started_at->format('Y-m-d'))->toBe('2026-06-01')
        ->and($subscription->ends_at->format('Y-m-d'))->toBe('2026-07-01');

    expect(Subscription::count())->toBe(1);

    $ledger = LedgerEntry::query()->where('subscription_id', $subscription->id)->first();
    expect($ledger)->not->toBeNull()
        ->and($ledger->type)->toBe(LedgerEntryType::SubscriptionPayment)
        ->and($ledger->amount_cents)->toBe(1500)
        ->and($ledger->idempotency_key)->toBe("payment:subscription:{$subscription->id}")
        ->and($ledger->user_id)->toBe($student->id);
});

it('throws failed and writes no rows', function () {
    app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_FAILED);
    $student = makeStudent();
    $plan = makePlan();

    try {
        chargeService()->charge($student->id, $plan->id, CarbonImmutable::create(2026, 6, 15));
        $this->fail('Expected MockPaymentProviderFailedException');
    } catch (MockPaymentProviderFailedException) {
    }

    expect(Subscription::count())->toBe(0)
        ->and(LedgerEntry::count())->toBe(0);
});

it('throws timeout and writes no rows', function () {
    app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_TIMEOUT_AFTER_SUCCESS);
    $student = makeStudent();
    $plan = makePlan();

    try {
        chargeService()->charge($student->id, $plan->id, CarbonImmutable::create(2026, 6, 15));
        $this->fail('Expected MockPaymentProviderTimeoutException');
    } catch (MockPaymentProviderTimeoutException) {
    }

    expect(Subscription::count())->toBe(0)
        ->and(LedgerEntry::count())->toBe(0);
});

it('returns the existing subscription on a re-run with the same month, no new provider call', function () {
    app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);
    $student = makeStudent();
    $plan = makePlan();

    $first = chargeService()->charge($student->id, $plan->id, CarbonImmutable::create(2026, 6, 1));
    $second = chargeService()->charge($student->id, $plan->id, CarbonImmutable::create(2026, 6, 28));

    expect($second->id)->toBe($first->id)
        ->and(Subscription::count())->toBe(1)
        ->and(LedgerEntry::count())->toBe(1);
});

it('returns the existing subscription on a re-run with a different date in the same month', function () {
    app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);
    $student = makeStudent();
    $plan = makePlan();

    $first = chargeService()->charge($student->id, $plan->id, CarbonImmutable::create(2026, 6, 1));
    $second = chargeService()->charge($student->id, $plan->id, CarbonImmutable::create(2026, 6, 30));

    expect($second->id)->toBe($first->id)
        ->and(Subscription::count())->toBe(1);
});

it('creates a new subscription for a different month', function () {
    app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);
    $student = makeStudent();
    $plan = makePlan();

    $june = chargeService()->charge($student->id, $plan->id, CarbonImmutable::create(2026, 6, 15));
    $july = chargeService()->charge($student->id, $plan->id, CarbonImmutable::create(2026, 7, 1));

    expect($june->id)->not->toBe($july->id)
        ->and(Subscription::count())->toBe(2)
        ->and(LedgerEntry::count())->toBe(2)
        ->and($june->started_at->format('Y-m'))->toBe('2026-06')
        ->and($july->started_at->format('Y-m'))->toBe('2026-07');
});

it('throws ModelNotFoundException when the student does not exist', function () {
    $plan = makePlan();

    $thrown = false;
    try {
        chargeService()->charge(999, $plan->id, CarbonImmutable::create(2026, 6, 15));
    } catch (ModelNotFoundException) {
        $thrown = true;
    }

    expect($thrown)->toBeTrue();
});

it('throws ModelNotFoundException when the plan does not exist', function () {
    $student = makeStudent();

    $thrown = false;
    try {
        chargeService()->charge($student->id, 999, CarbonImmutable::create(2026, 6, 15));
    } catch (ModelNotFoundException) {
        $thrown = true;
    }

    expect($thrown)->toBeTrue();
});

it('throws when the user exists but is not a student', function () {
    $instructor = makeStudent('instructor');
    $plan = makePlan();

    $thrown = false;
    try {
        chargeService()->charge($instructor->id, $plan->id, CarbonImmutable::create(2026, 6, 15));
    } catch (ModelNotFoundException) {
        $thrown = true;
    }

    expect($thrown)->toBeTrue();
});

it('returns an existing subscription instead of re-charging the same month', function () {
    app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);
    $student = makeStudent();
    $plan = makePlan();

    Subscription::query()->create([
        'user_id' => $student->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'started_at' => CarbonImmutable::create(2026, 6, 1)->startOfMonth(),
        'ends_at' => CarbonImmutable::create(2026, 6, 1)->startOfMonth()->addMonth(),
        'charged_amount_cents' => $plan->price_cents,
        'currency' => $plan->currency,
        'provider_charge_reference' => "ch:{$student->id}:2026-6",
        'charged_at' => CarbonImmutable::create(2026, 6, 1)->startOfMonth(),
    ]);

    $result = chargeService()->charge($student->id, $plan->id, CarbonImmutable::create(2026, 6, 15));

    expect(Subscription::count())->toBe(1)
        ->and($result->provider_charge_reference)->toBe("ch:{$student->id}:2026-6");
});

it('recovers from a unique-violation race by returning the existing row', function () {
    app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);
    $student = makeStudent();
    $plan = makePlan();

    Subscription::query()->create([
        'user_id' => $student->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'started_at' => CarbonImmutable::create(2026, 6, 1)->startOfMonth(),
        'ends_at' => CarbonImmutable::create(2026, 6, 1)->startOfMonth()->addMonth(),
        'charged_amount_cents' => $plan->price_cents,
        'currency' => $plan->currency,
        'provider_charge_reference' => "ch:{$student->id}:2026-6",
        'charged_at' => CarbonImmutable::create(2026, 6, 1)->startOfMonth(),
    ]);

    $service = new class(app(MockPaymentProvider::class)) extends ChargeSubscriptionService
    {
        private int $findExistingCalls = 0;

        protected function findExisting(int $studentId, CarbonImmutable $startedAt): ?Subscription
        {
            $this->findExistingCalls++;

            if ($this->findExistingCalls === 1) {
                return null;
            }

            return parent::findExisting($studentId, $startedAt);
        }

        protected function findExistingForUpdate(int $studentId, CarbonImmutable $startedAt): ?Subscription
        {
            return null;
        }
    };

    $result = $service->charge($student->id, $plan->id, CarbonImmutable::create(2026, 6, 15));

    expect(Subscription::count())->toBe(1)
        ->and($result->provider_charge_reference)->toBe("ch:{$student->id}:2026-6");
});

it('uses the deterministic provider_charge_reference pattern', function () {
    app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);
    $student = makeStudent();
    $plan = makePlan();

    $subscription = chargeService()->charge($student->id, $plan->id, CarbonImmutable::create(2026, 6, 15));

    expect($subscription->provider_charge_reference)->toBe("ch:{$student->id}:2026-6");
});

it('uses the deterministic ledger idempotency_key pattern', function () {
    app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);
    $student = makeStudent();
    $plan = makePlan();

    $subscription = chargeService()->charge($student->id, $plan->id, CarbonImmutable::create(2026, 6, 15));

    $ledger = LedgerEntry::query()->where('subscription_id', $subscription->id)->first();
    expect($ledger->idempotency_key)->toBe("payment:subscription:{$subscription->id}");
});
