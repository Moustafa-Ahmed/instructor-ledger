<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\Enums\LedgerEntryType;
use App\Enums\RefundStatus;
use App\Enums\SubscriptionStatus;
use App\Models\LedgerEntry;
use App\Models\Refund;
use App\Models\Subscription;
use App\Services\Payments\MockPaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class RefundSubscriptionService
{
    public function __construct(private readonly MockPaymentProvider $provider) {}

    public function refund(int $subscriptionId, ?CarbonImmutable $cancelDate = null): Refund
    {
        $subscription = Subscription::query()->findOrFail($subscriptionId);

        $cancelDate ??= CarbonImmutable::now();
        $startedAt = $subscription->started_at;
        $endsAt = $subscription->ends_at;

        if ($cancelDate->lt($startedAt) || $cancelDate->gte($endsAt)) {
            throw new \InvalidArgumentException("Cancel date {$cancelDate->toDateString()} is outside the subscription period {$startedAt->toDateString()}..{$endsAt->toDateString()}.");
        }

        $payment = LedgerEntry::query()
            ->where('subscription_id', $subscription->id)
            ->where('type', LedgerEntryType::SubscriptionPayment->value)
            ->firstOrFail();

        $existingRefund = Refund::query()->where('subscription_id', $subscription->id)->first();
        if ($existingRefund) {
            return $existingRefund;
        }

        $refundAmount = $this->computeRefundAmount(
            $subscription->charged_amount_cents,
            $startedAt,
            $endsAt,
            $cancelDate
        );

        $idempotencyKey = "refund:subscription:{$subscription->id}";

        // Skip the provider call when there's nothing to refund (the
        // student used the whole month). The refund row and the ledger
        // correction are still written so the subscription is marked
        // refunded and the period's net is correct.
        if ($refundAmount > 0) {
            // Provider call happens outside the service's transaction so
            // the provider's mock_payment_operations row survives a
            // timeout (see ChargeSubscriptionService for the full
            // rationale). The retry finds the prior result by
            // idempotency_key.
            $this->provider->refundMoney($idempotencyKey, $refundAmount, $subscription->currency);
        }

        try {
            return DB::transaction(function () use ($subscription, $refundAmount, $cancelDate, $payment) {
                $refund = Refund::query()->create([
                    'subscription_id' => $subscription->id,
                    'amount_cents' => $refundAmount,
                    'status' => RefundStatus::Completed,
                    'provider_refund_reference' => "re:subscription:{$subscription->id}",
                ]);

                if ($refundAmount > 0) {
                    LedgerEntry::query()->create([
                        'subscription_id' => $subscription->id,
                        'user_id' => $subscription->user_id,
                        'type' => LedgerEntryType::SubscriptionRefund,
                        'amount_cents' => -$refundAmount,
                        'idempotency_key' => "refund:subscription:{$subscription->id}:ledger",
                        'subscription_entry_id' => $payment->id,
                        'meta' => null,
                    ]);
                }

                $subscription->update([
                    'status' => SubscriptionStatus::Refunded,
                    'cancel_date' => $cancelDate->toDateString(),
                ]);

                return $refund;
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                $existing = Refund::query()->where('subscription_id', $subscription->id)->first();
                if ($existing) {
                    return $existing;
                }
            }
            throw $e;
        }
    }

    /**
     * Partial refund: the student used the first $cancelDate->day days
     * of the period, so the refund covers the remaining days. The cancel
     * day itself is treated as "used" (the student had access through
     * that day). Cancel on the last day of the period → refund 0
     * (the student used the whole month). Cancel on the first day of
     * the period → refund the rest.
     */
    private function computeRefundAmount(
        int $chargedAmountCents,
        CarbonImmutable $startedAt,
        CarbonImmutable $endsAt,
        CarbonImmutable $cancelDate
    ): int {
        $daysInMonth = (int) $startedAt->daysInMonth;
        $daysUsed = (int) $cancelDate->day;
        $daysRemaining = max(0, $daysInMonth - $daysUsed);

        return intdiv($chargedAmountCents * $daysRemaining, $daysInMonth);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE constraint failed') || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
