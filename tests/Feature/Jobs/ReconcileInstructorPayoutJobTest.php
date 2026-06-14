<?php

declare(strict_types=1);

use App\Exceptions\StillReconcilingException;
use App\Jobs\ReconcileInstructorPayoutJob;
use App\Models\LedgerEntry;
use App\Models\MockPaymentOperation;
use App\Services\Payments\MockPaymentProvider;
use App\Services\Payouts\ReconcileInstructorPayoutService;

beforeEach(function () {
    $this->provider = new MockPaymentProvider;
    $this->app->instance(MockPaymentProvider::class, $this->provider);
    config()->set('ledger.currency', 'USD');
    config()->set('ledger.idempotency.send', 'send:');

    $instructor = \App\Models\User::factory()->instructor()->create();
    $this->payout = LedgerEntry::factory()->instructorPayout(2026, 6, 1000, $instructor)->create();
    $this->payout->update(['meta' => ['status' => 'reconciling', 'reconciling_at' => '2026-07-01T00:00:00Z']]);
});

it('declares 5 attempts and the configured backoff schedule', function () {
    $job = new ReconcileInstructorPayoutJob($this->payout->id);

    expect($job->tries)->toBe(5)
        ->and($job->backoff)->toBe([10, 30, 120, 300, 1800]);
});

it('settles a reconciling row to sent when the provider reports succeeded', function () {
    MockPaymentOperation::query()->create([
        'provider_reference' => 'reconciled-ref',
        'operation_type' => MockPaymentProvider::TYPE_SEND,
        'idempotency_key' => 'send:' . $this->payout->idempotency_key,
        'amount_cents' => 1000,
        'currency' => 'USD',
        'status' => MockPaymentProvider::STATUS_SUCCEEDED,
        'metadata' => [],
    ]);

    (new ReconcileInstructorPayoutJob($this->payout->id))->handle(app(ReconcileInstructorPayoutService::class));

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('sent')
        ->and($this->payout->meta['provider_reference'])->toBe('reconciled-ref');
});

it('settles a reconciling row to failed when the provider reports failed', function () {
    MockPaymentOperation::query()->create([
        'provider_reference' => 'failed-ref',
        'operation_type' => MockPaymentProvider::TYPE_SEND,
        'idempotency_key' => 'send:' . $this->payout->idempotency_key,
        'amount_cents' => 1000,
        'currency' => 'USD',
        'status' => MockPaymentProvider::STATUS_FAILED,
        'metadata' => [],
    ]);

    (new ReconcileInstructorPayoutJob($this->payout->id))->handle(app(ReconcileInstructorPayoutService::class));

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('failed')
        ->and($this->payout->meta)->not->toHaveKey('reconciliation_exhausted');
});

it('re-queues (throws StillReconcilingException) when the provider has no record yet', function () {
    $thrown = false;
    try {
        (new ReconcileInstructorPayoutJob($this->payout->id))->handle(app(ReconcileInstructorPayoutService::class));
    } catch (StillReconcilingException) {
        $thrown = true;
    }

    expect($thrown)->toBeTrue();

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('reconciling');
});

it('marks the row reconciliation_exhausted when the job is past its last attempt', function () {
    // The job's $tries is 5; the service is called with the same
    // value (in real life, the job's $this->attempts() at the final
    // attempt). No provider record exists, so on a normal attempt
    // the service throws StillReconcilingException and the job
    // releases with backoff. On the LAST attempt we want the row
    // to be marked failed + exhausted so ops can see the row needs
    // manual intervention — not just thrown and re-queued.
    $service = app(ReconcileInstructorPayoutService::class);
    $service->reconcile($this->payout->id, attempts: 5, maxAttempts: 5);

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('failed')
        ->and($this->payout->meta['reconciliation_exhausted'])->toBeTrue()
        ->and($this->payout->meta['error'])->toContain('exhausted');
});

it('keeps throwing StillReconcilingException on attempts BEFORE the last', function () {
    // attempts < maxAttempts with no provider record → the job
    // releases with backoff and tries again. The row stays
    // 'reconciling'.
    $thrown = false;
    try {
        app(ReconcileInstructorPayoutService::class)
            ->reconcile($this->payout->id, attempts: 2, maxAttempts: 5);
    } catch (StillReconcilingException) {
        $thrown = true;
    }

    expect($thrown)->toBeTrue();
    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('reconciling');
});

it('the failed() hook calls markExhausted on the row', function () {
    // Simulate the job's failed() hook — it would normally be called
    // by Laravel after all retries are exhausted. We call it directly
    // here to assert the side effect.
    (new ReconcileInstructorPayoutJob($this->payout->id))->failed(new StillReconcilingException);

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('failed')
        ->and($this->payout->meta['reconciliation_exhausted'])->toBeTrue();
});
