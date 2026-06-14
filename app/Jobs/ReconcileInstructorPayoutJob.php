<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Payouts\ReconcileInstructorPayoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Resolves a `reconciling` row. Delegates to
 * `ReconcileInstructorPayoutService::reconcile()`. The service
 * throws `StillReconcilingException` when the provider has no
 * final status — the job doesn't catch it; Laravel re-queues with
 * the configured backoff.
 *
 * The normal "ran out of retries" path is handled in-band by the
 * service. `failed()` is a safety net for unexpected exceptions
 * on the final attempt.
 */
class ReconcileInstructorPayoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $maxExceptions = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 300, 1800];

    public function __construct(public int $ledgerEntryId) {}

    public function handle(ReconcileInstructorPayoutService $service): void
    {
        $service->reconcile(
            $this->ledgerEntryId,
            $this->attempts(),
            $this->tries,
        );
    }

    public function failed(Throwable $e): void
    {
        app(ReconcileInstructorPayoutService::class)->markExhausted($this->ledgerEntryId);
    }
}
