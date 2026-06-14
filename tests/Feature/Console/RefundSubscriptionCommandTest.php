<?php

declare(strict_types=1);

use App\Enums\LedgerEntryType;
use App\Models\LedgerEntry;
use App\Models\Plan;
use App\Models\Refund;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Payments\MockPaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->instance(MockPaymentProvider::class, new MockPaymentProvider);
    app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);
});

it('refunds a subscription on a valid invocation', function () {
    $provider = app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);
    $student = User::factory()->create();
    $plan = Plan::factory()->create(['price_cents' => 1000, 'months' => 1]);
    $subscription = Subscription::factory()->create([
        'user_id' => $student->id,
        'plan_id' => $plan->id,
        'started_at' => CarbonImmutable::create(2026, 6, 1)->startOfMonth(),
        'ends_at' => CarbonImmutable::create(2026, 6, 1)->startOfMonth()->addMonth(),
        'charged_amount_cents' => 1000,
        'currency' => 'USD',
        'charged_at' => CarbonImmutable::create(2026, 6, 1)->startOfMonth(),
    ]);
    LedgerEntry::factory()->create([
        'subscription_id' => $subscription->id,
        'user_id' => $student->id,
        'type' => LedgerEntryType::SubscriptionPayment,
        'amount_cents' => 1000,
    ]);

    $this->artisan('ledger:refund-subscription', [
        'subscription_id' => $subscription->id,
        '--on' => '2026-06-16',
    ])->expectsOutputToContain('Refund #1 recorded')
        ->assertExitCode(0);

    expect(Refund::count())->toBe(1);
});

it('rejects an invalid date format with exit code INVALID', function () {
    $student = User::factory()->create();
    $plan = Plan::factory()->create(['price_cents' => 1000, 'months' => 1]);
    $subscription = Subscription::factory()->create([
        'user_id' => $student->id,
        'plan_id' => $plan->id,
        'started_at' => CarbonImmutable::create(2026, 6, 1)->startOfMonth(),
        'ends_at' => CarbonImmutable::create(2026, 6, 1)->startOfMonth()->addMonth(),
        'charged_amount_cents' => 1000,
        'currency' => 'USD',
        'charged_at' => CarbonImmutable::create(2026, 6, 1)->startOfMonth(),
    ]);

    $this->artisan('ledger:refund-subscription', [
        'subscription_id' => $subscription->id,
        '--on' => '16-06-2026',
    ])->expectsOutputToContain('Invalid date')
        ->assertExitCode(2);
});

it('rejects an unknown subscription id with exit code FAILURE', function () {
    $this->artisan('ledger:refund-subscription', [
        'subscription_id' => 9999,
        '--on' => '2026-06-16',
    ])->expectsOutputToContain('Subscription #9999 not found')
        ->assertExitCode(1);
});
