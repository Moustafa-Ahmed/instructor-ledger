<?php

declare(strict_types=1);

namespace App\Services\Payouts;

use App\Exceptions\MockPaymentProviderFailedException;
use App\Exceptions\MockPaymentProviderTimeoutException;
use App\Models\LedgerEntry;
use App\Services\Payments\MockPaymentProvider;
use App\Services\Payouts\DTO\PayResult;
use Illuminate\Support\Facades\DB;

/**
 * Sends one `instructor_payout` row's money to the provider.
 *
 * State machine on `meta.status`:
 *   pending ──pay()──► sent          (provider succeeded)
 *                 ├──► failed        (provider failed — permanent)
 *                 └──► reconciling   (provider timed out after a real
 *                                     success; the reconcile worker
 *                                     owns the resolution)
 *
 * Idempotency: a row already in `sent`, `failed`, or `reconciling`
 * is returned as-is without calling the provider. The provider row
 * commits inside its own transaction before any timeout exception
 * is thrown, so a retry with the same idempotency key finds the
 * prior `mock_payment_operations` row and returns the prior result.
 */
class PayInstructorService
{
    public function __construct(private readonly MockPaymentProvider $provider) {}

    public function pay(int $ledgerEntryId): PayResult
    {
        return DB::transaction(function () use ($ledgerEntryId) {
            $entry = LedgerEntry::query()
                ->payoutRow($ledgerEntryId)
                ->lockForUpdate()
                ->firstOrFail();

            $current = $this->metaStatus($entry);

            if ($current === 'sent' || $current === 'failed') {
                return new PayResult(status: $current, needsReconciliation: false);
            }

            if ($current === 'reconciling') {
                return new PayResult(status: 'reconciling', needsReconciliation: false);
            }

            $amountCents = (int) abs($entry->amount_cents);
            $currency = (string) ($entry->currency ?? config('ledger.currency', 'USD'));
            $providerIdempotencyKey = config('ledger.idempotency.send', 'send:') . $entry->idempotency_key;

            try {
                $result = $this->provider->sendMoney(
                    $providerIdempotencyKey,
                    $amountCents,
                    $currency,
                    ['instructor_id' => (int) $entry->user_id, 'ledger_entry_id' => (int) $entry->id],
                );
            } catch (MockPaymentProviderTimeoutException) {
                $entry->update([
                    'meta' => array_merge($this->metaOrEmpty($entry), [
                        'status' => 'reconciling',
                        'reconciling_at' => now()->toIso8601String(),
                    ]),
                ]);

                return new PayResult(status: 'reconciling', needsReconciliation: true);
            } catch (MockPaymentProviderFailedException $e) {
                $entry->update([
                    'meta' => array_merge($this->metaOrEmpty($entry), [
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                        'failed_at' => now()->toIso8601String(),
                    ]),
                ]);

                return new PayResult(status: 'failed', needsReconciliation: false);
            }

            $entry->update([
                'meta' => array_merge($this->metaOrEmpty($entry), [
                    'status' => 'sent',
                    'provider_reference' => (string) $result['provider_reference'],
                    'sent_at' => now()->toIso8601String(),
                ]),
            ]);

            return new PayResult(status: 'sent', needsReconciliation: false);
        });
    }

    private function metaStatus(LedgerEntry $entry): string
    {
        $meta = $this->metaOrEmpty($entry);

        return (string) ($meta['status'] ?? 'pending');
    }

    /** @return array<string, mixed> */
    private function metaOrEmpty(LedgerEntry $entry): array
    {
        return $entry->meta ?? [];
    }
}
