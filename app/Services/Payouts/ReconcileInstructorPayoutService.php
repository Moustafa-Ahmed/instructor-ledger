<?php

declare(strict_types=1);

namespace App\Services\Payouts;

use App\Exceptions\StillReconcilingException;
use App\Models\LedgerEntry;
use App\Services\Payments\MockPaymentProvider;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a payout row the provider timed out on, by checking the
 * real provider status for the operation.
 *
 * State machine on `meta.status`:
 *   reconciling ──reconcile()──► sent        (provider says succeeded)
 *                       ├──► failed      (provider says failed — terminal)
 *                       └──► (no transition)  (no provider record yet —
 *                                             throw StillReconcilingException;
 *                                             the job releases with backoff)
 *
 * The job passes `$attempts` so the service can mark the row exhausted
 * on the last attempt. `markExhausted()` is the out-of-band safety
 * net for unexpected exceptions on the final attempt.
 */
class ReconcileInstructorPayoutService
{
    public function __construct(private readonly MockPaymentProvider $provider) {}

    /**
     * Resolve a reconciling row. Throws StillReconcilingException
     * when the provider has no final status yet, so the job
     * releases with backoff.
     */
    public function reconcile(int $ledgerEntryId, int $attempts, int $maxAttempts): void
    {
        DB::transaction(function () use ($ledgerEntryId, $attempts, $maxAttempts) {
            $entry = LedgerEntry::query()
                ->payoutRow($ledgerEntryId)
                ->lockForUpdate()
                ->firstOrFail();

            $current = (string) (($entry->meta ?? [])['status'] ?? 'pending');

            if ($current === 'sent' || $current === 'failed' || $current !== 'reconciling') {
                return;
            }

            if ($attempts >= $maxAttempts) {
                $this->markExhaustedRow($entry);

                return;
            }

            $providerIdempotencyKey = config('ledger.idempotency.send', 'send:') . $entry->idempotency_key;

            try {
                $status = $this->provider->statusByIdempotencyKey(
                    MockPaymentProvider::TYPE_SEND,
                    $providerIdempotencyKey,
                );
            } catch (ModelNotFoundException) {
                throw new StillReconcilingException;
            }

            $providerStatus = (string) $status['status'];

            if ($providerStatus === MockPaymentProvider::STATUS_SUCCEEDED) {
                $entry->update([
                    'meta' => array_merge(($entry->meta ?? []), [
                        'status' => 'sent',
                        'provider_reference' => (string) $status['provider_reference'],
                        'sent_at' => now()->toIso8601String(),
                        'reconciled_at' => now()->toIso8601String(),
                    ]),
                ]);

                return;
            }

            if ($providerStatus === MockPaymentProvider::STATUS_FAILED) {
                $entry->update([
                    'meta' => array_merge(($entry->meta ?? []), [
                        'status' => 'failed',
                        'error' => 'Provider reported failed on reconciliation.',
                        'failed_at' => now()->toIso8601String(),
                        'reconciled_at' => now()->toIso8601String(),
                    ]),
                ]);

                return;
            }

            throw new StillReconcilingException;
        });
    }

    /**
     * Safety-net entry point called from the job's `failed()` hook
     * when an unexpected exception escapes `reconcile()` on the
     * final attempt. The normal exhaustion path is handled
     * in-band inside `reconcile()`.
     */
    public function markExhausted(int $ledgerEntryId): void
    {
        DB::transaction(function () use ($ledgerEntryId) {
            $entry = LedgerEntry::query()
                ->payoutRow($ledgerEntryId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->markExhaustedOnEntry($entry);
        });
    }

    private function markExhaustedRow(LedgerEntry $entry): void
    {
        $this->markExhaustedOnEntry($entry);
    }

    private function markExhaustedOnEntry(LedgerEntry $entry): void
    {
        $meta = $entry->meta ?? [];

        // Don't clobber a row already in a terminal state.
        $current = (string) ($meta['status'] ?? 'pending');
        if ($current === 'sent' || $current === 'failed') {
            return;
        }

        $entry->update([
            'meta' => array_merge($meta, [
                'status' => 'failed',
                'error' => 'Reconciliation exhausted without a final provider status.',
                'reconciliation_exhausted' => true,
                'failed_at' => now()->toIso8601String(),
            ]),
        ]);
    }
}
