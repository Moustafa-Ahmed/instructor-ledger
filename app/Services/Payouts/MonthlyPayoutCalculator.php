<?php

declare(strict_types=1);

namespace App\Services\Payouts;

use App\Enums\LedgerEntryType;
use App\Models\LedgerEntry;
use App\Services\Payouts\DTO\PayoutDraft;
use Illuminate\Support\Facades\DB;

class MonthlyPayoutCalculator
{
    public function calculate(int $year, int $month): PayoutDraft
    {
        $net = $this->netForPeriod($year, $month);

        if ($net <= 0) {
            return new PayoutDraft(platformCutCents: 0, instructorPayouts: []);
        }

        $cutBps = (int) config('ledger.platform_cut_bps');
        $platformCut = intdiv($net * $cutBps, 10000);
        $instructorPool = $net - $platformCut;

        $weights = $this->loadInstructorWeights();
        $totalWeight = array_sum($weights);

        if ($totalWeight === 0) {
            return new PayoutDraft(platformCutCents: $net, instructorPayouts: []);
        }

        return new PayoutDraft(
            platformCutCents: $platformCut,
            instructorPayouts: $this->allocateLargestRemainder($instructorPool, $weights),
        );
    }

    private function netForPeriod(int $year, int $month): int
    {
        return (int) LedgerEntry::query()
            ->whereIn('type', [
                LedgerEntryType::SubscriptionPayment->value,
                LedgerEntryType::SubscriptionRefund->value,
            ])
            ->whereHas('subscription', fn($q) => $q->forPeriod($year, $month))
            ->sum('amount_cents');
    }

    /**
     * @return array<int, int> user_id => summed revenue_weight
     */
    private function loadInstructorWeights(): array
    {
        $rows = DB::table('course_instructor')
            ->groupBy('user_id')
            ->select('user_id', DB::raw('SUM(revenue_weight) AS w'))
            ->get();

        $weights = [];
        foreach ($rows as $row) {
            $weights[(int) $row->user_id] = (int) $row->w;
        }

        return $weights;
    }

    /**
     * Sort user_ids directly so the tie-break by user_id is unambiguous
     * (PHP's uasort loses key context in its comparator and is not stable).
     *
     * @param  array<int, int>  $weights  user_id => weight
     * @return array<int, int> user_id => cents (positive, sum = $pool)
     */
    private function allocateLargestRemainder(int $pool, array $weights): array
    {
        $totalWeight = array_sum($weights);
        $allocations = [];
        $remainders = [];

        $distributed = 0;
        foreach ($weights as $userId => $weight) {
            $numerator = $pool * $weight;
            $allocations[$userId] = intdiv($numerator, $totalWeight);
            $remainders[$userId] = $numerator % $totalWeight;
            $distributed += $allocations[$userId];
        }

        $leftover = $pool - $distributed;
        if ($leftover > 0) {
            $orderedUserIds = array_keys($weights);
            usort($orderedUserIds, function (int $a, int $b) use ($remainders) {
                $cmp = $remainders[$b] <=> $remainders[$a];
                if ($cmp !== 0) {
                    return $cmp;
                }

                return $a <=> $b;
            });

            foreach (array_slice($orderedUserIds, 0, $leftover) as $userId) {
                $allocations[$userId]++;
            }
        }

        return $allocations;
    }
}
