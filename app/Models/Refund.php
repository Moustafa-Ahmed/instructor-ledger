<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RefundStatus;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Workflow tracking for a refund against the payment provider.
 *
 * Holds no financial logic of its own — the actual earnings correction
 * is a negative ledger_entries row whose subscription_entry_id points to
 * the original allocation. This row only tracks whether the provider
 * acknowledged the refund.
 */
class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory;

    protected $fillable = [
        'subscription_id',
        'amount_cents',
        'status',
        'provider_refund_reference',
    ];

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'amount_cents' => 'integer',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
