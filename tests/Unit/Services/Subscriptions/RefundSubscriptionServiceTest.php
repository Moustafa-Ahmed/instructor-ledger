<?php

declare(strict_types=1);

use App\Enums\LedgerEntryType;
use App\Enums\SubscriptionStatus;
use App\Exceptions\MockPaymentProviderFailedException;
use App\Enums\RefundStatus;
use App\Models\LedgerEntry;
use App\Models\Plan;
use App\Models\Refund;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Payments\MockPaymentProvider;
use App\Services\Subscriptions\RefundSubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->app->instance(MockPaymentProvider::class, new MockPaymentProvider());
});

function refundService(): RefundSubscriptionService
{
    return app(RefundSubscriptionService::class);
}

function chargedSubscription(int $priceCents = 1000, ?int $year = null, ?int $month = null): Subscription
{
    $year ??= 2026;
    $month ??= 6;
    $student = User::factory()->create();
    $plan = Plan::factory()->create(['price_cents' => $priceCents, 'months' => 1]);
    $subscription = Subscription::factory()->create([
        'user_id' => $student->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'started_at' => CarbonImmutable::create($year, $month, 1)->startOfMonth(),
        'ends_at' => CarbonImmutable::create($year, $month, 1)->startOfMonth()->addMonth(),
        'charged_amount_cents' => $priceCents,
        'currency' => 'USD',
        'charged_at' => CarbonImmutable::create($year, $month, 1)->startOfMonth(),
    ]);
    LedgerEntry::factory()->create([
        'subscription_id' => $subscription->id,
        'user_id' => $student->id,
        'type' => LedgerEntryType::SubscriptionPayment,
        'amount_cents' => $priceCents,
    ]);

    return $subscription;
}

it('refunds a partial amount when cancelled mid-month, with cancel_date stored on the subscription', function () {
    app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);
    $subscription = chargedSubscription(priceCents: 3000, year: 2026, month: 6);

    $cancelDate = CarbonImmutable::create(2026, 6, 16);
    $refund = refundService()->refund($subscription->id, $cancelDate);

    // 30-day month, cancel on day 16. Student used 16 days, 14 remaining.
    // refund = 3000 * 14 / 30 = 1400.
    expect($refund->amount_cents)->toBe(1400)
        ->and($refund->status)->toBe(RefundStatus::Completed)
        ->and($refund->subscription_id)->toBe($subscription->id);

    expect(Refund::count())->toBe(1);

    $subscription->refresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Refunded)
        ->and($subscription->cancel_date->format('Y-m-d'))->toBe('2026-06-16');

    $ledger = LedgerEntry::query()
        ->where('subscription_id', $subscription->id)
        ->where('type', LedgerEntryType::SubscriptionRefund)
        ->first();
    expect($ledger)->not->toBeNull()
        ->and($ledger->amount_cents)->toBe(-1400)
        ->and($ledger->subscription_entry_id)->not->toBeNull();
});

it('refunds the most when cancelled on the first day of the period', function () {
    app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);
    $subscription = chargedSubscription(priceCents: 1000);

    $refund = refundService()->refund($subscription->id, CarbonImmutable::create(2026, 6, 1));

    // 30-day month, cancel on day 1, used 1 day, 29 remaining.
    // refund = 1000 * 29 / 30 = 966.
    expect($refund->amount_cents)->toBe(966);
});

it('refunds nothing when cancelled on the last day of the period', function () {
    app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);
    $subscription = chargedSubscription(priceCents: 1000);

    $refund = refundService()->refund($subscription->id, CarbonImmutable::create(2026, 6, 30));

    expect($refund->amount_cents)->toBe(0);
});

it('returns the existing refund on a re-run and does not call the provider again', function () {
    app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);
    $subscription = chargedSubscription(priceCents: 1000);

    $first = refundService()->refund($subscription->id, CarbonImmutable::create(2026, 6, 16));
    $second = refundService()->refund($subscription->id, CarbonImmutable::create(2026, 6, 16));

    expect($second->id)->toBe($first->id)
        ->and(Refund::count())->toBe(1)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::SubscriptionRefund)->count())->toBe(1);
});

it('throws when the provider returns failed and writes no rows', function () {
    app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_FAILED);
    $subscription = chargedSubscription(priceCents: 1000);

    $thrown = false;
    try {
        refundService()->refund($subscription->id, CarbonImmutable::create(2026, 6, 16));
    } catch (MockPaymentProviderFailedException) {
        $thrown = true;
    }

    expect($thrown)->toBeTrue()
        ->and(Refund::count())->toBe(0)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::SubscriptionRefund)->count())->toBe(0);

    $subscription->refresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Active);
});

it('throws when the cancel date is before the period start', function () {
    app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);
    $subscription = chargedSubscription(priceCents: 1000);

    refundService()->refund($subscription->id, CarbonImmutable::create(2026, 5, 31));
})->throws(InvalidArgumentException::class);

it('throws when the cancel date is after the period end', function () {
    app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);
    $subscription = chargedSubscription(priceCents: 1000);

    refundService()->refund($subscription->id, CarbonImmutable::create(2026, 7, 1));
})->throws(InvalidArgumentException::class);

it('throws ModelNotFoundException when the subscription does not exist', function () {
    $thrown = false;
    try {
        refundService()->refund(999, CarbonImmutable::create(2026, 6, 16));
    } catch (ModelNotFoundException) {
        $thrown = true;
    }

    expect($thrown)->toBeTrue();
});

it('stores the cancel_date on the subscription, not on the refund row', function () {
    app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);
    $subscription = chargedSubscription(priceCents: 1000);

    $refund = refundService()->refund($subscription->id, CarbonImmutable::create(2026, 6, 20));

    $subscription->refresh();
    expect($subscription->cancel_date->format('Y-m-d'))->toBe('2026-06-20')
        ->and($refund->getAttributes())->not->toHaveKey('cancel_date');
});
