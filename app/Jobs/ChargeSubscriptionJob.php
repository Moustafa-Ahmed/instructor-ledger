<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Subscriptions\ChargeSubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChargeSubscriptionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    /**
     * @var array<int, int>
     */
    public array $backoff = [1, 2, 4, 8, 16];

    public function __construct(
        public int $studentId,
        public int $planId,
        public CarbonImmutable $date,
    ) {
        $this->tries = config('ledger.charge_max_attempts', 5);
        $this->backoff = config('ledger.charge_backoff_seconds', [1, 2, 4, 8, 16]);
    }

    public function handle(ChargeSubscriptionService $service): void
    {
        $service->charge($this->studentId, $this->planId, $this->date);
    }

    /**
     * Fired by Laravel after the queue worker has exhausted the
     * configured $tries. Records the failure in the application log
     * so an operator can discover a stuck charge without watching
     * failed_jobs.
     */
    public function failed(Throwable $e): void
    {
        Log::error('ChargeSubscriptionJob exhausted retries', [
            'student_id' => $this->studentId,
            'plan_id' => $this->planId,
            'date' => $this->date->toDateString(),
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
