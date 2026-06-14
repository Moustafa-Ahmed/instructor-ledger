<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\Enums\LedgerEntryType;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\LedgerEntry;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Payments\MockPaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ChargeSubscriptionService
{
    public function __construct(private readonly MockPaymentProvider $provider) {}

    public function charge(int $studentId, int $planId, CarbonImmutable $date): Subscription
    {
        $student = User::query()
            ->where('role', UserRole::Student->value)
            ->findOrFail($studentId);

        $plan = Plan::query()->findOrFail($planId);

        $startedAt = $date->startOfMonth();
        $endsAt = $startedAt->addMonth();
        $year = (int) $startedAt->format('Y');
        $month = (int) $startedAt->format('n');

        if ($existing = $this->findExisting($studentId, $startedAt)) {
            return $existing;
        }

        $providerRef = "ch:{$studentId}:{$year}-{$month}";
        $idempotencyKey = "charge:{$providerRef}";

        // Provider call happens outside the service's transaction so that the
        // provider's mock_payment_operations row survives a timeout (which
        // fires AFTER the provider's inner transaction commits). If the
        // provider call were inside the service's transaction, a timeout
        // would roll back the provider's row along with the service's
        // writes, defeating the retry recovery. The retry would then call
        // the provider fresh, instead of finding the prior result by
        // idempotency_key.
        $this->provider->chargeMoney($idempotencyKey, $plan->price_cents, $plan->currency);

        try {
            return DB::transaction(function () use ($student, $plan, $startedAt, $endsAt, $studentId, $providerRef) {
                if ($existing = $this->findExistingForUpdate($studentId, $startedAt)) {
                    return $existing;
                }

                $subscription = Subscription::query()->create([
                    'user_id' => $student->id,
                    'plan_id' => $plan->id,
                    'status' => SubscriptionStatus::Active,
                    'started_at' => $startedAt,
                    'ends_at' => $endsAt,
                    'charged_amount_cents' => $plan->price_cents,
                    'currency' => $plan->currency,
                    'provider_charge_reference' => $providerRef,
                    'charged_at' => $startedAt,
                ]);

                LedgerEntry::query()->create([
                    'subscription_id' => $subscription->id,
                    'user_id' => $student->id,
                    'type' => LedgerEntryType::SubscriptionPayment,
                    'amount_cents' => $plan->price_cents,
                    'idempotency_key' => "payment:subscription:{$subscription->id}",
                    'subscription_entry_id' => null,
                    'meta' => null,
                ]);

                return $subscription;
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                $existing = $this->findExisting($studentId, $startedAt);
                if ($existing) {
                    return $existing;
                }
            }
            throw $e;
        }
    }

    protected function findExisting(int $studentId, CarbonImmutable $startedAt): ?Subscription
    {
        return Subscription::query()
            ->where('user_id', $studentId)
            ->where('started_at', $startedAt)
            ->first();
    }

    protected function findExistingForUpdate(int $studentId, CarbonImmutable $startedAt): ?Subscription
    {
        return Subscription::query()
            ->where('user_id', $studentId)
            ->where('started_at', $startedAt)
            ->lockForUpdate()
            ->first();
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE constraint failed') || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
