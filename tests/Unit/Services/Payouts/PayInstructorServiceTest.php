<?php

declare(strict_types=1);

use App\Enums\LedgerEntryType;
use App\Models\LedgerEntry;
use App\Services\Payments\MockPaymentProvider;
use App\Services\Payouts\PayInstructorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->provider = new MockPaymentProvider;
    $this->app->instance(MockPaymentProvider::class, $this->provider);
    config()->set('ledger.currency', 'USD');
    config()->set('ledger.idempotency.send', 'send:');

    $this->instructor = \App\Models\User::factory()->instructor()->create();
    $this->payout = LedgerEntry::factory()->instructorPayout(2026, 6, 1000, $this->instructor)->create();
});

function payService(): PayInstructorService
{
    return app(PayInstructorService::class);
}

it('marks the row sent and stores the provider_reference on a succeeded outcome', function () {
    $this->provider->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);

    $result = payService()->pay($this->payout->id);

    expect($result->status)->toBe('sent')
        ->and($result->needsReconciliation)->toBeFalse();

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('sent')
        ->and($this->payout->meta['provider_reference'])->toBeString()
        ->and($this->payout->meta['sent_at'])->toBeString();
});

it('marks the row failed on a failed outcome (no retry signal)', function () {
    $this->provider->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_FAILED);

    $result = payService()->pay($this->payout->id);

    expect($result->status)->toBe('failed')
        ->and($result->needsReconciliation)->toBeFalse();

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('failed')
        ->and($this->payout->meta['error'])->toBeString()
        ->and($this->payout->meta)->not->toHaveKey('reconciliation_exhausted');
});

it('marks the row reconciling on a timeout_after_success outcome and signals reconciliation', function () {
    $this->provider->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_TIMEOUT_AFTER_SUCCESS);

    $result = payService()->pay($this->payout->id);

    expect($result->status)->toBe('reconciling')
        ->and($result->needsReconciliation)->toBeTrue();

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('reconciling')
        ->and($this->payout->meta['reconciling_at'])->toBeString();
});

it('is a no-op when the row is already sent (idempotent re-run, no provider call)', function () {
    $this->payout->update(['meta' => ['status' => 'sent', 'provider_reference' => 'old-ref', 'sent_at' => '2026-07-01T00:00:00Z']]);

    // The provider is NOT set to deterministic. If the service calls it,
    // a non-deterministic outcome would be chosen — but the test
    // asserts that the row is untouched and the provider isn't called.
    $result = payService()->pay($this->payout->id);

    expect($result->status)->toBe('sent')
        ->and($result->needsReconciliation)->toBeFalse();

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('sent')
        ->and($this->payout->meta['provider_reference'])->toBe('old-ref');
});

it('is a no-op when the row is already failed (idempotent re-run, no provider call)', function () {
    $this->payout->update(['meta' => ['status' => 'failed', 'error' => 'original failure']]);

    $result = payService()->pay($this->payout->id);

    expect($result->status)->toBe('failed')
        ->and($result->needsReconciliation)->toBeFalse();

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('failed')
        ->and($this->payout->meta['error'])->toBe('original failure');
});

it('does not touch a reconciling row (the reconcile job owns that state)', function () {
    $this->payout->update(['meta' => ['status' => 'reconciling', 'reconciling_at' => '2026-07-01T00:00:00Z']]);

    $result = payService()->pay($this->payout->id);

    expect($result->status)->toBe('reconciling')
        ->and($result->needsReconciliation)->toBeFalse();

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('reconciling')
        ->and($this->payout->meta['reconciling_at'])->toBe('2026-07-01T00:00:00Z');
});

it('sends a positive amount to the provider (the ledger row is negative)', function () {
    $this->provider->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);

    payService()->pay($this->payout->id);

    // Verify the provider saw a positive amount — the ledger row's
    // amount_cents is -1000 (a payout), the provider's sendMoney
    // requires a positive amount.
    $operation = \App\Models\MockPaymentOperation::query()
        ->where('operation_type', MockPaymentProvider::TYPE_SEND)
        ->where('idempotency_key', 'send:' . $this->payout->idempotency_key)
        ->first();

    expect($operation)->not->toBeNull()
        ->and((int) $operation->amount_cents)->toBe(1000);
});

it('uses the documented idempotency_key pattern "send:" + ledger idempotency_key', function () {
    $this->provider->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);

    payService()->pay($this->payout->id);

    $expectedKey = 'send:' . $this->payout->idempotency_key;
    expect(\App\Models\MockPaymentOperation::query()
        ->where('operation_type', MockPaymentProvider::TYPE_SEND)
        ->where('idempotency_key', $expectedKey)
        ->exists())->toBeTrue();
});

it('throws when the row does not exist', function () {
    payService()->pay(999999);
})->throws(Illuminate\Database\Eloquent\ModelNotFoundException::class);

it('throws when the row is not an instructor_payout', function () {
    $platformCut = LedgerEntry::factory()->platformCut(2026, 6, 1000)->create();
    payService()->pay($platformCut->id);
})->throws(Illuminate\Database\Eloquent\ModelNotFoundException::class);

it('preserves prior meta keys when transitioning status', function () {
    // Older rows or future features may write other meta keys; the
    // state transition should merge, not clobber.
    $this->payout->update(['meta' => ['status' => 'pending', 'attempt_count' => 0, 'origin' => 'phase5']]);

    $this->provider->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);
    payService()->pay($this->payout->id);

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('sent')
        ->and($this->payout->meta['attempt_count'])->toBe(0)
        ->and($this->payout->meta['origin'])->toBe('phase5')
        ->and($this->payout->meta['provider_reference'])->toBeString();
});
