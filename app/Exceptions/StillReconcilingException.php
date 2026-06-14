<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by `ReconcileInstructorPayoutService::reconcile()` when the
 * provider's `checkStatusByIdempotencyKey()` does not yet have a final
 * status for the operation. The job catches this, releases the slot,
 * and is retried later with the configured backoff.
 *
 * The state on disk is unchanged: the row's `meta.status` stays
 * 'reconciling' until a real outcome is found.
 */
class StillReconcilingException extends RuntimeException
{
    public function __construct(string $message = 'Provider has no final status yet for this operation.')
    {
        parent::__construct($message);
    }
}
