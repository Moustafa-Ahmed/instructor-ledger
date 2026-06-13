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
            'user_id' => User::factory()->instructor(),
            'type' => LedgerEntryType::Allocation,
            'amount_cents' => $this->faker->numberBetween(100, 100000),
            'idempotency_key' => 'ledger:'.Str::random(20),
            'subscription_entry_id' => null,
            'meta' => null,
        ];
    }

    public function allocation(): static
    {
        return $this->state(fn () => [
            'type' => LedgerEntryType::Allocation,
            'subscription_entry_id' => null,
        ]);
    }

    public function reversalOf(LedgerEntry $source): static
    {
        return $this->state(fn () => [
            'type' => LedgerEntryType::Allocation,
            'amount_cents' => -$source->amount_cents,
            'subscription_id' => $source->subscription_id,
            'user_id' => $source->user_id,
            'subscription_entry_id' => $source->id,
            'idempotency_key' => 'reversal:'.Str::random(20),
        ]);
    }

    public function payout(): static
    {
        return $this->state(fn () => [
            'type' => LedgerEntryType::Payout,
            'amount_cents' => -$this->faker->numberBetween(1000, 100000),
            'subscription_id' => null,
            'user_id' => User::factory()->instructor(),
            'idempotency_key' => 'payout:'.Str::random(20),
        ]);
    }
}
