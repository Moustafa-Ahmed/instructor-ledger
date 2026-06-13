<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
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
        $startedAt = CarbonImmutable::now()->startOfMonth();
        $endsAt = $startedAt->addMonth();

        return [
            'user_id' => User::factory(),
            'plan_id' => Plan::factory(),
            'status' => SubscriptionStatus::Active,
            'started_at' => $startedAt,
            'ends_at' => $endsAt,
            'charged_amount_cents' => 1999,
            'currency' => 'USD',
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
                $subscription->charged_amount_cents = $plan->price_cents;
                $subscription->currency = $plan->currency;
            }

            $subscription->started_at = $subscription->started_at->startOfMonth();
            $subscription->ends_at = $subscription->started_at->addMonth();
        });
    }

    public function forMonth(int $year, int $month): static
    {
        return $this->state(fn () => [
            'started_at' => CarbonImmutable::create($year, $month, 1)->startOfMonth(),
        ]);
    }

    public function refunded(): static
    {
        return $this->state(fn () => ['status' => SubscriptionStatus::Refunded]);
    }
}
