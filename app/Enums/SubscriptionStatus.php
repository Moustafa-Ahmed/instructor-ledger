<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle states for a subscription (one calendar-month access row).
 *
 * Active   — student paid for this month and is in their access period.
 *            The matching ledger_entries row of type=subscription_payment
 *            exists.
 * Refunded — student cancelled mid-month and was partially refunded.
 *            A matching ledger_entries row of type=subscription_refund
 *            exists with subscription_entry_id pointing at the original
 *            subscription_payment row.
 */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Refunded = 'refunded';
}
