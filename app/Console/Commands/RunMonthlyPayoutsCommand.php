<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Payouts\CloseMonthlyPayoutService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Closes the payout cycle for one calendar month.
 *
 * Usage:
 *   php artisan ledger:run-payouts                # previous month
 *   php artisan ledger:run-payouts --year=2026 --month=6
 *
 * The close transaction is the source of truth for the month's
 * platform_cut + instructor_payout rows. After the transaction
 * commits, the command dispatches one PayInstructorJob per
 * instructor_payout row id. Phase 6 owns the PayInstructorJob class;
 * we reference it by FQCN string so this command compiles today
 * (with a Bus::fake() in the test that asserts the dispatch shape).
 */
class RunMonthlyPayoutsCommand extends Command
{
    protected $signature = 'ledger:run-payouts
                            {--year= : The calendar year to close (defaults to the previous month)}
                            {--month= : The calendar month (1-12) to close (defaults to the previous month)}';

    protected $description = 'Close a calendar month: compute the platform cut and per-instructor payouts, then dispatch pay-instructor jobs.';

    public function handle(CloseMonthlyPayoutService $service): int
    {
        [$year, $month] = $this->resolvePeriod();

        if ($year === null || $month === null) {
            return self::INVALID;
        }

        try {
            $draft = $service->close($year, $month);
        } catch (Throwable $e) {
            $this->error("Close failed for {$year}-{$month}: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($draft->isEmpty()) {
            $this->info("Month {$year}-{$month} had no activity. Nothing to close.");

            return self::SUCCESS;
        }

        $jobClass = 'App\\Jobs\\PayInstructorJob';
        $dispatched = 0;
        foreach ($draft->instructorLedgerEntryIds as $userId => $ledgerEntryId) {
            Bus::dispatch(new $jobClass($ledgerEntryId));
            $dispatched++;
        }

        $instructorCount = count($draft->instructorPayouts);
        $this->info(sprintf(
            'Month %d-%02d closed: 1 platform_cut (%d cents) + %d instructor_payout(s) → %d job(s) dispatched.',
            $year,
            $month,
            $draft->platformCutCents,
            $instructorCount,
            $dispatched,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function resolvePeriod(): array
    {
        $yearOpt = $this->option('year');
        $monthOpt = $this->option('month');

        if ($yearOpt !== null || $monthOpt !== null) {
            if (! is_numeric($yearOpt) || ! is_numeric($monthOpt)) {
                $this->error('Both --year and --month are required when either is provided.');

                return [null, null];
            }

            $year = (int) $yearOpt;
            $month = (int) $monthOpt;

            if ($month < 1 || $month > 12) {
                $this->error("Invalid month: {$month}. Expected 1-12.");

                return [null, null];
            }

            return [$year, $month];
        }

        $previous = CarbonImmutable::now()->subMonthNoOverflow();

        return [(int) $previous->format('Y'), (int) $previous->format('n')];
    }
}
