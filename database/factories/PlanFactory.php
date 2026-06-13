<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlanInterval;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        $interval = $this->faker->randomElement(PlanInterval::cases());

        $duration = match ($interval) {
            PlanInterval::Monthly => 30,
            PlanInterval::Quarterly => 90,
            PlanInterval::Annual => 365,
        };

        return [
            'name' => ucfirst($interval->value).' Plan',
            'interval' => $interval,
            'interval_count' => 1,
            'amount_cents' => $this->faker->numberBetween(1000, 50000),
            'currency' => 'USD',
            'duration_days' => $duration,
        ];
    }

    public function monthly(): static
    {
        return $this->state(fn () => [
            'interval' => PlanInterval::Monthly,
            'interval_count' => 1,
            'duration_days' => 30,
        ]);
    }

    public function quarterly(): static
    {
        return $this->state(fn () => [
            'interval' => PlanInterval::Quarterly,
            'interval_count' => 1,
            'duration_days' => 90,
        ]);
    }

    public function annual(): static
    {
        return $this->state(fn () => [
            'interval' => PlanInterval::Annual,
            'interval_count' => 1,
            'duration_days' => 365,
        ]);
    }
}
