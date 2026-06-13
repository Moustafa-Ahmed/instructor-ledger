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
     */
    public function __construct(
        public int $platformCutCents,
        public array $instructorPayouts,
    ) {}
}
