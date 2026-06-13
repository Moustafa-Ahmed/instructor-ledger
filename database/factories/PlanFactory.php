<?php

declare(strict_types=1);

namespace Database\Factories;

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
        return [
            'name' => $this->faker->words(2, true),
            'price_cents' => $this->faker->numberBetween(1000, 50000),
            'currency' => 'USD',
            'months' => 1,
        ];
    }

    public function monthly(): static
    {
        return $this->state(fn () => [
            'name' => 'Monthly',
            'months' => 1,
        ]);
    }

    public function quarterly(): static
    {
        return $this->state(fn () => [
            'name' => 'Quarterly',
            'months' => 3,
        ]);
    }

    public function annual(): static
    {
        return $this->state(fn () => [
            'name' => 'Annual',
            'months' => 12,
        ]);
    }
}
