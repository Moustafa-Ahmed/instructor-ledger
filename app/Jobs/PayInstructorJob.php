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

// `tries = 1` because the service's terminal-state short-circuit makes a retry
// a no-op. `ShouldBeUnique` is the cross-process gate that complements the
// row-level `lockForUpdate()` inside the service.
class PayInstructorJob implements ShouldBeUnique, ShouldQueue
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
