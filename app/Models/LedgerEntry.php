<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LedgerEntryType;
use Database\Factories\LedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single line in the unified money ledger. The ledger is the only
 * source of truth for "how much the student paid," "how much the
 * platform kept," and "how much each instructor was paid." Every
 * question about money should be answerable with one SUM() against
 * this table.
 *
 * Four row types, distinguished by `type` and the columns they use:
 *
 *   subscription_payment — student paid the platform.
 *                         user_id=student, subscription_id=set,
 *                         amount_cents=+charged_amount,
 *                         subscription_entry_id=null.
 *
 *   subscription_refund  — student was partially refunded for a
 *                         mid-month cancel. Reverses a specific
 *                         subscription_payment row.
 *                         user_id=student, subscription_id=set,
 *                         amount_cents=-refund_amount,
 *                         subscription_entry_id=<that payment row>.
 *
 *   platform_cut         — the platform's retained share for one
 *                         closed month. Written by the monthly payout
 *                         run (in month N+1, covering month N). One row
 *                         per closed month.
 *                         user_id=null, subscription_id=null,
 *                         amount_cents=-cut,
 *                         subscription_entry_id=null.
 *                         Period (year, month) lives on the matching
 *                         subscriptions; query via join.
 *
 *   instructor_payout    — money sent to an instructor for one closed
 *                         month. One row per instructor per closed
 *                         month, written by the monthly payout run.
 *                         user_id=instructor, subscription_id=null,
 *                         amount_cents=-share,
 *                         subscription_entry_id=null.
 *                         Period (year, month) is the same month the
 *                         platform_cut row was written for.
 *
 * This table is append-only. Code must never UPDATE or DELETE a row.
 * Idempotency is enforced by the unique index on idempotency_key plus
 * the unique index on subscription_entry_id (one refund per payment).
 */
class LedgerEntry extends Model
{
    /** @use HasFactory<LedgerEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'subscription_id',
        'user_id',
        'type',
        'amount_cents',
        'idempotency_key',
        'subscription_entry_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount_cents' => 'integer',
            'meta' => 'array',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscriptionEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'subscription_entry_id');
    }
}
