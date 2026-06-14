<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LedgerEntryType;
use App\Models\LedgerEntry;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LedgerEntry>
 */
class LedgerEntryFactory extends Factory
{
    protected $model = LedgerEntry::class;

    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'user_id' => User::factory(),
            'type' => LedgerEntryType::SubscriptionPayment,
            'amount_cents' => $this->faker->numberBetween(100, 100000),
            'idempotency_key' => 'ledger:' . Str::random(20),
            'subscription_entry_id' => null,
            'meta' => null,
        ];
    }

    public function subscriptionPayment(): static
    {
        return $this->state(fn() => [
            'type' => LedgerEntryType::SubscriptionPayment,
            'subscription_id' => Subscription::factory(),
            'user_id' => User::factory(),
            'subscription_entry_id' => null,
            'idempotency_key' => 'payment:' . Str::random(20),
        ]);
    }

    public function subscriptionRefundOf(LedgerEntry $source): static
    {
        return $this->state(fn() => [
            'type' => LedgerEntryType::SubscriptionRefund,
            'amount_cents' => -$source->amount_cents,
            'subscription_id' => $source->subscription_id,
            'user_id' => $source->user_id,
            'subscription_entry_id' => $source->id,
            'idempotency_key' => 'refund:' . Str::random(20),
        ]);
    }

    public function platformCut(int $year, int $month, int $amountCents): static
    {
        $period = sprintf('%04d-%02d', $year, $month);

        return $this->state(fn() => [
            'type' => LedgerEntryType::PlatformCut,
            'amount_cents' => -$amountCents,
            'subscription_id' => null,
            'user_id' => null,
            'subscription_entry_id' => null,
            'idempotency_key' => "platform_cut:{$period}",
        ]);
    }

    public function instructorPayout(int $year, int $month, int $amountCents, ?User $instructor = null): static
    {
        return $this->state(function () use ($year, $month, $amountCents, $instructor) {
            $userId = $instructor?->id ?? User::factory()->instructor()->create()->id;
            $period = sprintf('%04d-%02d', $year, $month);

            return [
                'type' => LedgerEntryType::InstructorPayout,
                'amount_cents' => -$amountCents,
                'subscription_id' => null,
                'user_id' => $userId,
                'subscription_entry_id' => null,
                'idempotency_key' => "payout:{$period}:user:{$userId}",
            ];
        });
    }
}
