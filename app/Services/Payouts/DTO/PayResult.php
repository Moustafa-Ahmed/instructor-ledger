<?php

declare(strict_types=1);

namespace App\Services\Payouts\DTO;

/**
 * The result of one attempt to pay an instructor: where the row's
 * `meta.status` ended up, and whether the job should dispatch a
 * `ReconcileInstructorPayoutJob` to check back with the provider.
 *
 * `status` is one of the four values that can live in `meta.status`:
 *   - 'pending'      : the row hasn't been processed yet (initial state from close)
 *   - 'reconciling'  : the provider call timed out after a real success;
 *                      the reconcile worker will resolve the row
 *   - 'sent'         : the provider acknowledged the money movement
 *   - 'failed'       : the provider permanently declined (no retry)
 *
 * `needsReconciliation` is true only when `status === 'reconciling'`;
 * the job reads it to decide whether to dispatch the reconcile worker.
 */
final readonly class PayResult
{
    public function __construct(
        public string $status,
        public bool $needsReconciliation = false,
    ) {}
}
