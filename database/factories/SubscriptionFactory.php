<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        $startedAt = $this->faker->dateTimeBetween('-6 months', '-1 day');
        $intervalDays = 30;
        $priceCents = 1999;
        $currency = 'USD';

        return [
            'user_id' => User::factory(),
            'plan_id' => Plan::factory(),
            'status' => SubscriptionStatus::Active,
            'started_at' => $startedAt,
            'ends_at' => (clone $startedAt)->modify('+'.$intervalDays.' days'),
            'charged_amount_cents' => $priceCents,
            'currency' => $currency,
            'provider_charge_reference' => 'ch_'.Str::random(24),
            'charged_at' => $startedAt,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Subscription $subscription): void {
            if ($subscription->plan_id instanceof Plan) {
                $plan = $subscription->plan_id;
                $subscription->plan_id = $plan->id;
                $subscription->ends_at = $subscription->started_at->modify('+'.$plan->interval_days.' days');
                $subscription->charged_amount_cents = $plan->price_cents;
                $subscription->currency = $plan->currency;
            }
        });
    }

    public function refunded(): static
    {
        return $this->state(fn () => ['status' => SubscriptionStatus::Refunded]);
    }
}
