<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RefundStatus;
use App\Models\Refund;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    protected $model = Refund::class;

    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'amount_cents' => $this->faker->numberBetween(100, 100000),
            'status' => RefundStatus::Pending,
            'provider_refund_reference' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => RefundStatus::Completed,
            'provider_refund_reference' => 're_'.Str::random(24),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => RefundStatus::Failed,
        ]);
    }
}
