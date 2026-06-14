<?php

declare(strict_types=1);

use App\Enums\LedgerEntryType;
use App\Jobs\PayInstructorJob;
use App\Jobs\ReconcileInstructorPayoutJob;
use App\Models\LedgerEntry;
use App\Services\Payments\MockPaymentProvider;
use App\Services\Payouts\PayInstructorService;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    $this->provider = new MockPaymentProvider;
    $this->app->instance(MockPaymentProvider::class, $this->provider);
    config()->set('ledger.currency', 'USD');
    config()->set('ledger.idempotency.send', 'send:');

    $instructor = \App\Models\User::factory()->instructor()->create();
    $this->payout = LedgerEntry::factory()->instructorPayout(2026, 6, 1000, $instructor)->create();
});

it('declares 1 attempt and the ShouldBeUnique contract', function () {
    $job = new PayInstructorJob($this->payout->id);

    expect($job->tries)->toBe(1)
        ->and($job->uniqueFor)->toBe(600)
        ->and($job->uniqueId())->toBe("pay-instructor:{$this->payout->id}");
});

it('can be dispatched via Bus::dispatch() with the right ledgerEntryId', function () {
    Bus::fake();

    PayInstructorJob::dispatch($this->payout->id);

    Bus::assertDispatched(PayInstructorJob::class, function (PayInstructorJob $job) {
        return $job->ledgerEntryId === $this->payout->id;
    });
});

it('marks the row sent and dispatches NO reconcile job on a succeeded outcome', function () {
    $this->provider->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);
    Bus::fake([PayInstructorJob::class, ReconcileInstructorPayoutJob::class]);

    (new PayInstructorJob($this->payout->id))->handle(app(PayInstructorService::class));

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('sent');
    Bus::assertNotDispatched(ReconcileInstructorPayoutJob::class);
});

it('marks the row failed and dispatches NO reconcile job on a failed outcome', function () {
    $this->provider->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_FAILED);
    Bus::fake([PayInstructorJob::class, ReconcileInstructorPayoutJob::class]);

    (new PayInstructorJob($this->payout->id))->handle(app(PayInstructorService::class));

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('failed');
    Bus::assertNotDispatched(ReconcileInstructorPayoutJob::class);
});

it('marks the row reconciling AND dispatches a reconcile job on a timeout outcome', function () {
    $this->provider->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_TIMEOUT_AFTER_SUCCESS);
    Bus::fake([PayInstructorJob::class, ReconcileInstructorPayoutJob::class]);

    (new PayInstructorJob($this->payout->id))->handle(app(PayInstructorService::class));

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('reconciling');
    Bus::assertDispatched(ReconcileInstructorPayoutJob::class, function (ReconcileInstructorPayoutJob $job) {
        return $job->ledgerEntryId === $this->payout->id;
    });
});

it('is a no-op for a row that is already sent (idempotent re-run)', function () {
    $this->payout->update(['meta' => ['status' => 'sent', 'provider_reference' => 'old-ref', 'sent_at' => '2026-07-01T00:00:00Z']]);
    $this->provider->useRandomOutcomes(); // would pick a random outcome if the service called it
    Bus::fake([PayInstructorJob::class, ReconcileInstructorPayoutJob::class]);

    (new PayInstructorJob($this->payout->id))->handle(app(PayInstructorService::class));

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('sent')
        ->and($this->payout->meta['provider_reference'])->toBe('old-ref');
    Bus::assertNotDispatched(ReconcileInstructorPayoutJob::class);
});

it('is a no-op for a row that is already failed (idempotent re-run)', function () {
    $this->payout->update(['meta' => ['status' => 'failed', 'error' => 'original failure']]);
    $this->provider->useRandomOutcomes();
    Bus::fake([PayInstructorJob::class, ReconcileInstructorPayoutJob::class]);

    (new PayInstructorJob($this->payout->id))->handle(app(PayInstructorService::class));

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('failed')
        ->and($this->payout->meta['error'])->toBe('original failure');
    Bus::assertNotDispatched(ReconcileInstructorPayoutJob::class);
});

it('does not double-dispatch a reconcile job for a row that is already reconciling', function () {
    $this->payout->update(['meta' => ['status' => 'reconciling', 'reconciling_at' => '2026-07-01T00:00:00Z']]);
    $this->provider->useRandomOutcomes();
    Bus::fake([PayInstructorJob::class, ReconcileInstructorPayoutJob::class]);

    (new PayInstructorJob($this->payout->id))->handle(app(PayInstructorService::class));

    $this->payout->refresh();
    expect($this->payout->meta['status'])->toBe('reconciling');
    // The reconcile job that was already in flight when the row hit
    // 'reconciling' owns the next step. A second dispatch from pay()
    // would create a race for who marks the row sent first. The
    // service's reconciling short-circuit prevents that.
    Bus::assertNotDispatched(ReconcileInstructorPayoutJob::class);
});
