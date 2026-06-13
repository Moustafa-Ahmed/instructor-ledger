<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A subscription plan a student can buy.
 *
 * `months` is how many sequential monthly subscriptions a single
 * purchase creates. A 1-month plan is one subscription, a 3-month
 * plan is three sequential subscriptions (one per calendar month),
 * a 12-month plan is twelve. All subscriptions are aligned to
 * calendar-month boundaries; `started_at` is the first day of the
 * month, `ends_at` the last.
 *
 * price_cents is the per-month price; the student pays price_cents
 * per month of access, and the platform_cut_bps snapshot on each
 * subscription is what the platform retains from that payment.
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'price_cents',
        'currency',
        'months',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'months' => 'integer',
        ];
    }
}
