<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A subscription plan a student can buy.
 *
 * interval_days is the number of days one term lasts. A plan with
 * interval_days=30 is a monthly plan, 90 a quarterly plan, 365 an annual
 * one. The challenge is single-currency and uses a single interval per
 * plan, so we don't model (interval, interval_count) as a pair.
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'price_cents',
        'currency',
        'interval_days',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'interval_days' => 'integer',
        ];
    }
}
