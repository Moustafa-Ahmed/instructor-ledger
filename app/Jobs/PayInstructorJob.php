<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Payouts\PayInstructorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Thin orchestrator: load the row via the service, dispatch a
 * reconciliation worker if the provider timed out after a real
 * success. No DB or provider code in this class.
 *
 * Why `tries = 1`? A single pay attempt either succeeds (the row is
 * `sent` / `failed`) or times out (the row is `reconciling`). A retry
 * of `pay()` on a `sent` / `failed` / `reconciling` row is a no-op by
 * the service's terminal-state short-circuit. The job is `ShouldBeUnique`
 * so two workers can't both grab the same `ledgerEntryId` — the unique
 * lock is the cross-process gate that complements the row-level
 * `lockForUpdate()` inside the service.
 */
class PayInstructorJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $uniqueFor = 600;

    public function __construct(public int $ledgerEntryId) {}

    public function uniqueId(): string
    {
        return "pay-instructor:{$this->ledgerEntryId}";
    }

    public function handle(PayInstructorService $service): void
    {
        $result = $service->pay($this->ledgerEntryId);

        if ($result->needsReconciliation) {
            ReconcileInstructorPayoutJob::dispatch($this->ledgerEntryId);
        }
    }
}
