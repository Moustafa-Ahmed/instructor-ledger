<?php

declare(strict_types=1);

namespace App\Services\Payouts;

use App\Enums\LedgerEntryType;
use App\Models\LedgerEntry;
use App\Services\Payouts\DTO\PayoutDraft;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Closes a calendar month: computes the platform cut and the
 * per-instructor payouts, then writes the corresponding rows into
 * `ledger_entries`.
 *
 * Idempotency model — two layers, no application-level row locks:
 *
 *   1. Precheck: `LedgerEntry::where('idempotency_key', "platform_cut:YYYY-MM")->exists()`
 *      catches the common case fast. If the row exists, the month is
 *      already closed and the existing rows are rebuilt into a draft.
 *
 *   2. Race backstop: the unique index on `ledger_entries.idempotency_key`
 *      catches the rare case where two close calls race past the
 *      precheck at the same time. The losing process catches a
 *      `QueryException` (SQLSTATE 23000) and rebuilds the draft from
 *      the rows that the winning process wrote.
 *
 * Empty / zero-net month policy: write nothing. The calculator returns
 * `PayoutDraft(0, [])` for `net <= 0`, and the close service returns
 * that empty draft as-is. The "month not closed" state is the *correct*
 * state for a month that hasn't actually settled, and lets a late
 * refund in month N still be reflected in a re-run of the close for N.
 *
 * Meta initial value: every `instructor_payout` row is written with
 * `meta = ['status' => 'pending']`. The platform_cut row is written
 * with `meta = null` (no state machine on a one-shot settlement row).
 */
class CloseMonthlyPayoutService
{
    public function __construct(private readonly MonthlyPayoutCalculator $calculator) {}

    public function close(int $year, int $month): PayoutDraft
    {
        if ($existing = $this->loadExisting($year, $month)) {
            return $existing;
        }

        $draft = $this->calculator->calculate($year, $month);

        if ($draft->isEmpty()) {
            return $draft;
        }

        try {
            return DB::transaction(function () use ($draft, $year, $month) {
                return $this->writeRows($draft, $year, $month);
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                $existing = $this->loadExisting($year, $month);
                if ($existing) {
                    return $existing;
                }
            }
            throw $e;
        }
    }

    /**
     * The period key is `YYYY-MM` with both fields zero-padded. This
     * keeps the idempotency key shape stable across callers (a
     * command defaulting to month=6 must produce `2026-06`, not
     * `2026-6`).
     */
    private function periodKey(int $year, int $month): string
    {
        return sprintf('%04d-%02d', $year, $month);
    }

    /**
     * If a `platform_cut:YYYY-MM` row already exists, rebuild a draft
     * from the rows that are on disk and return it. The rebuilt draft
     * includes the ledger row ids, so the command can dispatch
     * `PayInstructorJob` for each instructor even on a no-op re-run.
     */
    private function loadExisting(int $year, int $month): ?PayoutDraft
    {
        $period = $this->periodKey($year, $month);
        $platformCutKey = "platform_cut:{$period}";

        $cutRow = LedgerEntry::query()->where('idempotency_key', $platformCutKey)->first();
        if (! $cutRow) {
            return null;
        }

        $payoutRows = LedgerEntry::query()
            ->where('type', LedgerEntryType::InstructorPayout->value)
            ->where('idempotency_key', 'like', "payout:{$period}:user:%")
            ->get();

        $instructorPayouts = [];
        $instructorLedgerEntryIds = [];
        foreach ($payoutRows as $row) {
            $instructorPayouts[(int) $row->user_id] = (int) -$row->amount_cents;
            $instructorLedgerEntryIds[(int) $row->user_id] = (int) $row->id;
        }

        return new PayoutDraft(
            platformCutCents: (int) -$cutRow->amount_cents,
            instructorPayouts: $instructorPayouts,
            instructorLedgerEntryIds: $instructorLedgerEntryIds,
            platformCutLedgerEntryId: (int) $cutRow->id,
        );
    }

    /**
     * Insert the platform_cut row and one instructor_payout row per
     * instructor. Returns the draft with the row ids populated.
     */
    private function writeRows(PayoutDraft $draft, int $year, int $month): PayoutDraft
    {
        $period = $this->periodKey($year, $month);

        $cutRow = LedgerEntry::query()->create([
            'subscription_id' => null,
            'user_id' => null,
            'type' => LedgerEntryType::PlatformCut,
            'amount_cents' => -$draft->platformCutCents,
            'currency' => config('ledger.currency', 'USD'),
            'idempotency_key' => "platform_cut:{$period}",
            'subscription_entry_id' => null,
            'meta' => null,
        ]);

        $instructorLedgerEntryIds = [];
        foreach ($draft->instructorPayouts as $userId => $cents) {
            $row = LedgerEntry::query()->create([
                'subscription_id' => null,
                'user_id' => $userId,
                'type' => LedgerEntryType::InstructorPayout,
                'amount_cents' => -$cents,
                'currency' => config('ledger.currency', 'USD'),
                'idempotency_key' => "payout:{$period}:user:{$userId}",
                'subscription_entry_id' => null,
                'meta' => ['status' => 'pending'],
            ]);
            $instructorLedgerEntryIds[$userId] = (int) $row->id;
        }

        return $draft->withLedgerEntryIds((int) $cutRow->id, $instructorLedgerEntryIds);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE constraint failed') || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
