<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Jobs\ChargeSubscriptionJob;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class ChargeSubscriptionCommand extends Command
{
    protected $signature = 'ledger:charge-subscription
                            {student_id : The student user id}
                            {plan_id : The plan id}
                            {date : Charge date in YYYY-MM-DD format}';

    protected $description = 'Charge a student for one calendar-month subscription; retries on transient provider timeouts.';

    public function handle(): int
    {
        $studentId = (int) $this->argument('student_id');
        $planId = (int) $this->argument('plan_id');
        $dateStr = (string) $this->argument('date');

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $dateStr);
        } catch (Throwable) {
            $date = null;
        }

        if ($date === null) {
            $this->error("Invalid date: {$dateStr}. Expected YYYY-MM-DD.");

            return self::INVALID;
        }

        $student = User::query()
            ->where('role', UserRole::Student->value)
            ->find($studentId);
        if (! $student) {
            $this->error("Student #{$studentId} not found or is not a student.");

            return self::FAILURE;
        }

        $plan = Plan::query()->find($planId);
        if (! $plan) {
            $this->error("Plan #{$planId} not found.");

            return self::FAILURE;
        }

        ChargeSubscriptionJob::dispatch($studentId, $planId, $date);

        $maxAttempts = config('ledger.charge_max_attempts', 5);
        $this->info("Charge job dispatched. It will be retried up to {$maxAttempts} times with exponential backoff on provider timeout.");

        return self::SUCCESS;
    }
}
