<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LedgerEntryType;
use Database\Factories\LedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single line in the unified money ledger.
 *
 * Three meanings, all encoded by the `type` column and the sign of
 * amount_cents:
 *
 *   type=allocation, amount_cents > 0, subscription_entry_id = null
 *     — an instructor's share of a charged subscription.
 *
 *   type=allocation, amount_cents < 0, subscription_entry_id = <id>
 *     — the reversal of a previous allocation (e.g. mid-term refund).
 *       The unique index on subscription_entry_id guarantees a given
 *       allocation can be reversed at most once.
 *
 *   type=payout, amount_cents < 0, subscription_id = null
 *     — cash actually sent to the instructor. The provider-side call
 *       log lives in mock_payment_operations, linked via the
 *       idempotency_key namespace (e.g. "payout:{user_id}...").
 *
 * This table is append-only. Code must never UPDATE or DELETE a row.
 *
 * The `user` relation is the instructor who earned / was paid. For
 * type=allocation rows this is the earning instructor (role=instructor);
 * for type=payout rows it is the same instructor being paid. The FK is
 * non-nullable: every ledger row has a human on one side of it.
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

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'subscription_entry_id');
    }
}
