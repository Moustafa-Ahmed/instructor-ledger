<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kind of ledger_entries row.
 *
 * allocation — earnings on a subscription, or the negative correction
 *              thereof when a refund reverses it. amount_cents is signed;
 *              positives are accruals, negatives are refunds. A refund
 *              row carries subscription_entry_id pointing at the accrual
 *              it reverses.
 * payout     — money actually sent to the instructor via the provider.
 *              amount_cents is negative. subscription_id is null; this
 *              row is about cash, not earnings.
 *
 * Sign convention lets a per-instructor balance be a single
 * SUM(amount_cents) WHERE type='allocation' and a separate
 * SUM(amount_cents) WHERE type='payout' — no joining, no branching.
 */
enum LedgerEntryType: string
{
    case Allocation = 'allocation';
    case Payout = 'payout';
}
