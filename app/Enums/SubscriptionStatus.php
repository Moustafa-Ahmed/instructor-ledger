<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle states for a subscription.
 *
 * Active   — student paid, term running.
 * Refunded — student got money back (possibly partial, mid-term). The
 *            earnings correction is encoded as a negative
 *            ledger_entries row whose subscription_entry_id points at
 *            the original allocation.
 */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Refunded = 'refunded';
}
