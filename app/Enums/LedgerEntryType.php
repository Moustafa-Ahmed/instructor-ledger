<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kind of ledger_entries row.
 *
 * The ledger answers the money journey for the student, the platform,
 * and each instructor, in one table. Each row is a single cash event
 * (or, in the case of platform_cut / instructor_payout, the settlement
 * of a whole month's events).
 *
 * subscription_payment — the student paid the platform for a month's
 *                       access. amount_cents is positive.
 *                       user_id is the student, subscription_id is set.
 *
 * subscription_refund  — a partial refund against an earlier
 *                       subscription_payment (a mid-month cancel).
 *                       amount_cents is negative.
 *                       user_id is the student, subscription_id is set,
 *                       subscription_entry_id points at the payment row
 *                       being partially reversed.
 *
 * platform_cut         — the platform's cut for a closed month. Written
 *                       monthly by the payout run, not at charge time.
 *                       amount_cents is negative (money retained, in
 *                       the platform's "favor"). user_id is null
 *                       (the platform itself has no user row),
 *                       subscription_id is null. The month this row
 *                       covers is the same month the matching
 *                       instructor_payout rows were written for; join
 *                       on the underlying subscriptions to filter by
 *                       period.
 *
 * instructor_payout    — money sent to an instructor for a closed
 *                       month. Written monthly by the payout run.
 *                       amount_cents is negative. user_id is the
 *                       instructor, subscription_id is null. The
 *                       month is encoded in the idempotency_key
 *                       (e.g. "payout:2026-06:user:{id}").
 */
enum LedgerEntryType: string
{
    case SubscriptionPayment = 'subscription_payment';
    case SubscriptionRefund = 'subscription_refund';
    case PlatformCut = 'platform_cut';
    case InstructorPayout = 'instructor_payout';
}
