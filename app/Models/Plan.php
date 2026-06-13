<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanInterval;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Subscription plan a student can buy.
 *
 * The (interval, interval_count) pair defines how often a subscription on
 * this plan renews and how long one term lasts. duration_days is denormalized
 * from that pair for cheap date math (subscription.ends_at = started_at +
 * duration_days) without recomputing the interval every time.
 */
class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'interval',
        'interval_count',
        'amount_cents',
        'currency',
        'duration_days',
    ];

    protected $casts = [
        'interval' => PlanInterval::class,
        'interval_count' => 'integer',
        'amount_cents' => 'integer',
        'duration_days' => 'integer',
    ];
}
