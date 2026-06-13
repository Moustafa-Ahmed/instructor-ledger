<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A student's access to the platform for one calendar month.
 *
 * `started_at` is always the first day of the month at 00:00 and
 * `ends_at` is the first day of the next month at 00:00. The period
 * is therefore derivable from `started_at` with no separate columns:
 *
 *   $year  = $subscription->started_at->year;
 *   $month = $subscription->started_at->month;
 *
 * `platform_cut_bps` is the snapshot of the platform's cut at the
 * time the subscription was charged. It controls the per-payment
 * platform_cut row written at the end-of-month payout run.
 *
 * Idempotency at the charge boundary is enforced by the unique index
 * on `provider_charge_reference`.
 */
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'plan_id',
        'status',
        'started_at',
        'ends_at',
        'charged_amount_cents',
        'currency',
        'platform_cut_bps',
        'provider_charge_reference',
        'charged_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'started_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'charged_at' => 'immutable_datetime',
            'charged_amount_cents' => 'integer',
            'platform_cut_bps' => 'integer',
        ];
    }

    public function scopeForPeriod(Builder $query, int $year, int $month): Builder
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->addMonth();

        return $query->where('started_at', '>=', $start)->where('started_at', '<', $end);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
