<?php

declare(strict_types=1);

use App\Exceptions\StillReconcilingException;
use App\Models\LedgerEntry;
use App\Models\MockPaymentOperation;
use App\Models\User;
use App\Services\Payments\MockPaymentProvider;
use App\Services\Payouts\ReconcileInstructorPayoutService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->provider = new MockPaymentProvider;
    $this->app->instance(MockPaymentProvider::class, $this->provider);
    config()->set('ledger.currency', 'USD');
    config()->set('ledger.idempotency.send', 'send:');

    $this->instructor = User::factory()->instructor()->create();
    $this->payout = LedgerEntry::factory()->instructorPayout(2026, 6, 1000, $this->instructor)->create();
    $this->payout->update(['meta' => ['status' => 'reconciling', 'reconciling_at' => '2026-07-01T00:00:00Z']]);
});

function reconcileService(): ReconcileInstructorPayoutService
{
    return app(ReconcileInstructorPayoutService::class);
}

it('resolves a reconciling row to sent when the provider reports succeeded', function () {
    MockPaymentOperation::query()->create([
        'provider_reference' => 'pre-existing-ref',
        'operation_type' => MockPaymentProvider::TYPE_SEND,
        'idempotency_key' => 'send:' . $this->payout->idempotency_key,
        'amount_cents' => 1000,
        'currency' => 'USD',
        'status' => MockPaymentProvider::STATUS_SUCCEEDED,
        'metadata' => [],
    ]);

    reconcileService()->reconcile($this->payout->id, attempts: 1, maxAttempts: 5);

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('sent')
        ->and($this->payout->meta['provider_reference'])->toBe('pre-existing-ref')
        ->and($this->payout->meta['reconciled_at'])->toBeString();
});

it('resolves a reconciling row to failed when the provider reports failed', function () {
    MockPaymentOperation::query()->create([
        'provider_reference' => 'failed-ref',
        'operation_type' => MockPaymentProvider::TYPE_SEND,
        'idempotency_key' => 'send:' . $this->payout->idempotency_key,
        'amount_cents' => 1000,
        'currency' => 'USD',
        'status' => MockPaymentProvider::STATUS_FAILED,
        'metadata' => [],
    ]);

    reconcileService()->reconcile($this->payout->id, attempts: 1, maxAttempts: 5);

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('failed')
        ->and($this->payout->meta['error'])->toBeString()
        ->and($this->payout->meta)->not->toHaveKey('reconciliation_exhausted');
});

it('throws StillReconcilingException when the provider has no record yet', function () {
    reconcileService()->reconcile($this->payout->id, attempts: 1, maxAttempts: 5);
})->throws(StillReconcilingException::class);

it('marks the row as failed with reconciliation_exhausted when attempts >= maxAttempts', function () {
    reconcileService()->reconcile($this->payout->id, attempts: 5, maxAttempts: 5);

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('failed')
        ->and($this->payout->meta['reconciliation_exhausted'])->toBeTrue()
        ->and($this->payout->meta['error'])->toContain('exhausted');
});

it('does not clobber a sent row (the no-op path)', function () {
    $this->payout->update([
        'meta' => ['status' => 'sent', 'provider_reference' => 'do-not-clobber', 'sent_at' => '2026-07-01T00:00:00Z'],
    ]);

    reconcileService()->reconcile($this->payout->id, attempts: 1, maxAttempts: 5);

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('sent')
        ->and($this->payout->meta['provider_reference'])->toBe('do-not-clobber');
});

it('does not clobber a failed row (the no-op path)', function () {
    $this->payout->update([
        'meta' => ['status' => 'failed', 'error' => 'original failure'],
    ]);

    reconcileService()->reconcile($this->payout->id, attempts: 1, maxAttempts: 5);

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('failed')
        ->and($this->payout->meta['error'])->toBe('original failure');
});

it('does not touch a pending row (only reconciling is its concern)', function () {
    $this->payout->update(['meta' => ['status' => 'pending']]);

    reconcileService()->reconcile($this->payout->id, attempts: 1, maxAttempts: 5);

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('pending');
});

it('markExhausted writes the exhaustion flag without clobbering a sent row', function () {
    $this->payout->update([
        'meta' => ['status' => 'sent', 'provider_reference' => 'race-condition'],
    ]);

    reconcileService()->markExhausted($this->payout->id);

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('sent')
        ->and($this->payout->meta['provider_reference'])->toBe('race-condition')
        ->and($this->payout->meta)->not->toHaveKey('reconciliation_exhausted');
});

it('markExhausted writes failed + reconciliation_exhausted on a reconciling row', function () {
    reconcileService()->markExhausted($this->payout->id);

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('failed')
        ->and($this->payout->meta['reconciliation_exhausted'])->toBeTrue();
});

it('markExhausted does not clobber a row that is already failed', function () {
    $this->payout->update([
        'meta' => [
            'status' => 'failed',
            'error' => 'original failure',
            'failed_at' => '2026-07-01T00:00:00Z',
        ],
    ]);

    reconcileService()->markExhausted($this->payout->id);

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('failed')
        ->and($this->payout->meta['error'])->toBe('original failure')
        ->and($this->payout->meta['failed_at'])->toBe('2026-07-01T00:00:00Z');
});

it('throws when the row does not exist', function () {
    reconcileService()->reconcile(999999, attempts: 1, maxAttempts: 5);
})->throws(ModelNotFoundException::class);
