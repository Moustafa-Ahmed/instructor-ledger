<?php

declare(strict_types=1);

namespace App\Services\Payouts\DTO;

/**
 * The result of MonthlyPayoutCalculator::calculate(): how much the
 * platform keeps for a closed month, and how much each instructor is
 * owed.
 *
 * Values are positive integer cents. The platform_cut_cents is the
 * amount retained (positive); each instructor_payouts value is the
 * amount to be sent to that instructor (positive; the close service
 * writes a negative ledger row to record the payout).
 *
 * The DTO is a value object — readonly, no behavior. The close
 * service is the only thing that decides how to persist it.
 */
final readonly class PayoutDraft
{
    /**
     * @param  array<int, int>  $instructorPayouts  user_id => cents (positive integer)
     * @param  array<int, int>  $instructorLedgerEntryIds  user_id => ledger_entries.id (populated by the close service after insert; empty on a calculator-only draft)
     * @param  int|null  $platformCutLedgerEntryId  ledger_entries.id of the platform_cut row, if written
     */
    public function __construct(
        public int $platformCutCents,
        public array $instructorPayouts,
        public array $instructorLedgerEntryIds = [],
        public ?int $platformCutLedgerEntryId = null,
    ) {}

    /**
     * True when there is nothing to write: no platform cut, no
     * instructor payouts. The close service uses this to short-circuit
     * an empty / zero-net month.
     */
    public function isEmpty(): bool
    {
        return $this->platformCutCents === 0 && $this->instructorPayouts === [];
    }

    /**
     * Return a new draft with the ledger row ids populated. Used by
     * the close service after a successful insert so the command can
     * dispatch one PayInstructorJob per row.
     *
     * @param  array<int, int>  $instructorLedgerEntryIds
     */
    public function withLedgerEntryIds(int $platformCutLedgerEntryId, array $instructorLedgerEntryIds): self
    {
        return new self(
            platformCutCents: $this->platformCutCents,
            instructorPayouts: $this->instructorPayouts,
            instructorLedgerEntryIds: $instructorLedgerEntryIds,
            platformCutLedgerEntryId: $platformCutLedgerEntryId,
        );
    }
}
