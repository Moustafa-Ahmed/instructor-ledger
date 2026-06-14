<?php

declare(strict_types=1);

use App\Exceptions\StillReconcilingException;
use App\Models\LedgerEntry;
use App\Models\MockPaymentOperation;
use App\Models\User;
use App\Services\Payments\MockPaymentProvider;
use App\Services\Payouts\PayInstructorService;
use App\Services\Payouts\ReconcileInstructorPayoutService;

beforeEach(function () {
    $this->provider = new MockPaymentProvider;
    $this->app->instance(MockPaymentProvider::class, $this->provider);
    config()->set('ledger.currency', 'USD');
    config()->set('ledger.idempotency.send', 'send:');

    $instructor = User::factory()->instructor()->create();
    $this->payout = LedgerEntry::factory()->instructorPayout(2026, 6, 1000, $instructor)->create();
});

it('resolves a timeout via reconcile() using only the operation row the provider left behind', function () {
    $this->provider->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_TIMEOUT_AFTER_SUCCESS);

    $result = app(PayInstructorService::class)->pay($this->payout->id);

    expect($result->status)->toBe('reconciling')
        ->and($result->needsReconciliation)->toBeTrue();

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('reconciling')
        ->and($this->payout->meta['reconciling_at'])->toBeString();

    $expectedKey = 'send:'.$this->payout->idempotency_key;
    $operation = MockPaymentOperation::query()
        ->where('operation_type', MockPaymentProvider::TYPE_SEND)
        ->where('idempotency_key', $expectedKey)
        ->first();

    expect($operation)->not->toBeNull()
        ->and($operation->status)->toBe(MockPaymentProvider::STATUS_SUCCEEDED);

    app(ReconcileInstructorPayoutService::class)->reconcile(
        $this->payout->id,
        attempts: 1,
        maxAttempts: 5,
    );

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('sent')
        ->and($this->payout->meta['provider_reference'])->toBe($operation->provider_reference)
        ->and($this->payout->meta['sent_at'])->toBeString()
        ->and($this->payout->meta['reconciled_at'])->toBeString();
});

it('resolves a timeout via reconcile() to failed when the provider row says so', function () {
    $this->payout->update(['meta' => ['status' => 'reconciling', 'reconciling_at' => '2026-07-01T00:00:00Z']]);

    MockPaymentOperation::query()->create([
        'provider_reference' => 'late-failed-ref',
        'operation_type' => MockPaymentProvider::TYPE_SEND,
        'idempotency_key' => 'send:'.$this->payout->idempotency_key,
        'amount_cents' => 1000,
        'currency' => 'USD',
        'status' => MockPaymentProvider::STATUS_FAILED,
        'metadata' => [],
    ]);

    app(ReconcileInstructorPayoutService::class)->reconcile(
        $this->payout->id,
        attempts: 1,
        maxAttempts: 5,
    );

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('failed')
        ->and($this->payout->meta)->not->toHaveKey('reconciliation_exhausted')
        ->and($this->payout->meta['reconciled_at'])->toBeString();
});

it('keeps retrying (reconciling) when the provider has no record yet, then resolves on the next attempt', function () {
    $this->payout->update(['meta' => ['status' => 'reconciling', 'reconciling_at' => '2026-07-01T00:00:00Z']]);

    expect(fn () => app(ReconcileInstructorPayoutService::class)
        ->reconcile($this->payout->id, attempts: 1, maxAttempts: 5))
        ->toThrow(StillReconcilingException::class);

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('reconciling');

    MockPaymentOperation::query()->create([
        'provider_reference' => 'arrived-late',
        'operation_type' => MockPaymentProvider::TYPE_SEND,
        'idempotency_key' => 'send:'.$this->payout->idempotency_key,
        'amount_cents' => 1000,
        'currency' => 'USD',
        'status' => MockPaymentProvider::STATUS_SUCCEEDED,
        'metadata' => [],
    ]);

    app(ReconcileInstructorPayoutService::class)
        ->reconcile($this->payout->id, attempts: 2, maxAttempts: 5);

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('sent')
        ->and($this->payout->meta['provider_reference'])->toBe('arrived-late');
});
