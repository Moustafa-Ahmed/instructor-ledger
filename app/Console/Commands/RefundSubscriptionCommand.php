<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\Subscriptions\RefundSubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class RefundSubscriptionCommand extends Command
{
    protected $signature = 'ledger:refund-subscription
                            {subscription_id : The subscription id}
                            {--on= : Cancel date in YYYY-MM-DD format (defaults to today)}';

    protected $description = 'Refund a subscription; partial refund by calendar-day proration.';

    public function handle(RefundSubscriptionService $service): int
    {
        $subscriptionId = (int) $this->argument('subscription_id');
        $dateStr = $this->option('on');

        if ($dateStr !== null) {
            try {
                $cancelDate = CarbonImmutable::createFromFormat('!Y-m-d', $dateStr);
            } catch (Throwable) {
                $this->error("Invalid date: {$dateStr}. Expected YYYY-MM-DD.");

                return self::INVALID;
            }
        } else {
            $cancelDate = CarbonImmutable::now();
        }

        try {
            $refund = $service->refund($subscriptionId, $cancelDate);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->error("Subscription #{$subscriptionId} not found.");

            return self::FAILURE;
        } catch (\App\Exceptions\MockPaymentProviderFailedException) {
            $this->error('Provider declined the refund.');

            return self::FAILURE;
        } catch (\App\Exceptions\MockPaymentProviderTimeoutException) {
            $this->error('Provider timed out. No refund recorded. Retry later.');

            return 75;
        }

        $this->info("Refund #{$refund->id} recorded. Amount: {$refund->amount_cents} cents.");

        $subscription = Subscription::query()->find($subscriptionId);
        if ($subscription !== null) {
            $this->info("Cancel date: {$subscription->cancel_date?->format('Y-m-d')}.");
        }

        return self::SUCCESS;
    }
}
