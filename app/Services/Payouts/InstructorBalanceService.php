<?php

declare(strict_types=1);

namespace App\Services\Payouts;

use App\Enums\LedgerEntryType;
use App\Models\LedgerEntry;

// Computes an instructor's three money columns for the Filament read-only ops
// view. "Outstanding" is conservative: a `reconciling` or `failed` row's amount
// is added to outstanding (pending liability / owed-but-didn't-land) on top of
// the earned-minus-paid base. Aggregated in PHP so the meta-status filter is
// portable across MySQL / SQLite / Postgres without dialect-specific JSON.
class InstructorBalanceService
{
    /**
     * @return array{earned_cents: int, paid_cents: int, outstanding_cents: int}
     */
    public function balanceFor(int $userId): array
    {
        return $this->balancesForMany([$userId])[$userId]
            ?? ['earned_cents' => 0, 'paid_cents' => 0, 'outstanding_cents' => 0];
    }

    /**
     * Batched lookup: one SQL query for the whole batch, then per-user
     * aggregation. Users with no payout rows get a zero entry so callers
     * can do a plain array lookup.
     *
     * @param  list<int>  $userIds
     * @return array<int, array{earned_cents: int, paid_cents: int, outstanding_cents: int}>
     */
    public function balancesForMany(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $result = [];
        foreach ($userIds as $id) {
            $result[(int) $id] = ['earned_cents' => 0, 'paid_cents' => 0, 'outstanding_cents' => 0];
        }

        $rows = LedgerEntry::query()
            ->whereIn('user_id', $userIds)
            ->where('type', LedgerEntryType::InstructorPayout->value)
            ->get(['user_id', 'amount_cents', 'meta']);

        $byUser = [];
        foreach ($rows as $row) {
            $uid = (int) $row->user_id;
            $byUser[$uid] ??= ['earned' => 0, 'paid' => 0, 'in_flight' => 0];
            $byUser[$uid]['earned'] += (int) abs($row->amount_cents);

            $status = (string) (($row->meta ?? [])['status'] ?? 'pending');

            if ($status === 'sent') {
                $byUser[$uid]['paid'] += (int) abs($row->amount_cents);
            } elseif ($status === 'reconciling' || $status === 'failed') {
                $byUser[$uid]['in_flight'] += (int) abs($row->amount_cents);
            }
        }

        foreach ($byUser as $uid => $agg) {
            $result[$uid] = [
                'earned_cents' => $agg['earned'],
                'paid_cents' => $agg['paid'],
                'outstanding_cents' => $agg['earned'] - $agg['paid'] + $agg['in_flight'],
            ];
        }

        return $result;
    }
}
