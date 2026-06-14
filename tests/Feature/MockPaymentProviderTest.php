<?php

declare(strict_types=1);

use App\Exceptions\MockPaymentProviderFailedException;
use App\Exceptions\MockPaymentProviderTimeoutException;
use App\Models\MockPaymentOperation;
use App\Services\Payments\MockPaymentProvider;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns the same charge for the same idempotency key', function () {
    $provider = app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);

    $first = $provider->chargeMoney('subscription-payment-1', 1500, 'usd', [
        'student_id' => 1,
    ]);
    $second = $provider->chargeMoney('subscription-payment-1', 1500, 'USD', [
        'student_id' => 1,
    ]);

    expect($second)->toBe($first)
        ->and(MockPaymentOperation::count())->toBe(1);
});

it('keeps charge and send idempotency keys separate', function () {
    $provider = app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);

    $charge = $provider->chargeMoney('same-key', 2000, 'USD');
    $send = $provider->sendMoney('same-key', 2000, 'USD');

    expect($charge['type'])->toBe(MockPaymentProvider::TYPE_CHARGE)
        ->and($send['type'])->toBe(MockPaymentProvider::TYPE_SEND)
        ->and($charge['provider_reference'])->not->toBe($send['provider_reference'])
        ->and(MockPaymentOperation::count())->toBe(2);
});

it('creates a deterministic successful operation', function () {
    $provider = app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);

    $result = $provider->chargeMoney('successful-charge', 2500, 'USD');

    expect($result['status'])->toBe(MockPaymentProvider::STATUS_SUCCEEDED)
        ->and(MockPaymentOperation::first()->status)->toBe(MockPaymentProvider::STATUS_SUCCEEDED);
});

it('creates a deterministic failed operation', function () {
    $provider = app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_FAILED);

    try {
        $provider->sendMoney('failed-send', 2500, 'USD');
    } catch (MockPaymentProviderFailedException) {
        //
    }

    expect(MockPaymentOperation::first()->status)->toBe(MockPaymentProvider::STATUS_FAILED);
});

it('throws a payment failed exception when retrying a failed operation', function () {
    $provider = app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_FAILED);

    try {
        $provider->sendMoney('retry-failed-send', 2500, 'USD');
    } catch (MockPaymentProviderFailedException) {
        //
    }

    $provider->sendMoney('retry-failed-send', 2500, 'USD');
})->throws(MockPaymentProviderFailedException::class);

it('resolves a timeout after success through status checks', function () {
    $provider = app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_TIMEOUT_AFTER_SUCCESS);

    try {
        $provider->sendMoney('timeout-send', 3000, 'USD');
    } catch (MockPaymentProviderTimeoutException) {
        //
    }

    $operation = MockPaymentOperation::first();
    $status = $provider->status($operation->provider_reference);

    expect($operation->status)->toBe(MockPaymentProvider::STATUS_SUCCEEDED)
        ->and($status['status'])->toBe(MockPaymentProvider::STATUS_SUCCEEDED);
});

it('returns the real status when retrying after a timeout with the same idempotency key', function () {
    $provider = app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_TIMEOUT_AFTER_SUCCESS);

    try {
        $provider->sendMoney('retry-timeout-send', 3000, 'USD');
    } catch (MockPaymentProviderTimeoutException) {
        //
    }

    $retry = $provider->sendMoney('retry-timeout-send', 3000, 'USD');

    expect($retry['status'])->toBe(MockPaymentProvider::STATUS_SUCCEEDED)
        ->and(MockPaymentOperation::count())->toBe(1);
});

it('does not change an existing operation when provider mode changes', function () {
    $provider = app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);

    $first = $provider->chargeMoney('stable-operation', 1200, 'USD');

    $provider->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_FAILED);

    $second = $provider->chargeMoney('stable-operation', 1200, 'USD');

    expect($second)->toBe($first)
        ->and($second['status'])->toBe(MockPaymentProvider::STATUS_SUCCEEDED)
        ->and(MockPaymentOperation::count())->toBe(1);
});

it('consumes deterministic outcome sequences and reuses the final outcome', function () {
    $provider = app(MockPaymentProvider::class)
        ->useDeterministicOutcomes([
            MockPaymentProvider::OUTCOME_SUCCEEDED,
            MockPaymentProvider::OUTCOME_FAILED,
        ]);

    $first = $provider->chargeMoney('sequence-1', 1000, 'USD');

    try {
        $provider->chargeMoney('sequence-2', 1000, 'USD');
    } catch (MockPaymentProviderFailedException) {
        //
    }

    try {
        $provider->chargeMoney('sequence-3', 1000, 'USD');
    } catch (MockPaymentProviderFailedException) {
        //
    }

    expect($first['status'])->toBe(MockPaymentProvider::STATUS_SUCCEEDED)
        ->and(MockPaymentOperation::query()->where('idempotency_key', 'sequence-2')->value('status'))->toBe(MockPaymentProvider::STATUS_FAILED)
        ->and(MockPaymentOperation::query()->where('idempotency_key', 'sequence-3')->value('status'))->toBe(MockPaymentProvider::STATUS_FAILED);
});

it('throws a model not found exception for an unknown provider reference', function () {
    $provider = app(MockPaymentProvider::class);

    $provider->status('missing-reference');
})->throws(ModelNotFoundException::class);

it('rejects invalid operation inputs', function (string $idempotencyKey, int $amountCents, string $currency) {
    $provider = app(MockPaymentProvider::class);

    $provider->chargeMoney($idempotencyKey, $amountCents, $currency);
})->with([
    'empty idempotency key' => ['', 1000, 'USD'],
    'zero amount' => ['bad-amount', 0, 'USD'],
    'negative amount' => ['bad-amount', -1, 'USD'],
    'empty currency' => ['bad-currency', 1000, ''],
    'invalid currency' => ['bad-currency', 1000, 'US'],
])->throws(InvalidArgumentException::class);

it('enforces database uniqueness for operation type and idempotency key', function () {
    MockPaymentOperation::query()->create([
        'provider_reference' => 'provider-reference-1',
        'operation_type' => MockPaymentProvider::TYPE_CHARGE,
        'idempotency_key' => 'duplicate-key',
        'amount_cents' => 1000,
        'currency' => 'USD',
        'status' => MockPaymentProvider::STATUS_SUCCEEDED,
        'metadata' => [],
    ]);

    MockPaymentOperation::query()->create([
        'provider_reference' => 'provider-reference-2',
        'operation_type' => MockPaymentProvider::TYPE_CHARGE,
        'idempotency_key' => 'duplicate-key',
        'amount_cents' => 1000,
        'currency' => 'USD',
        'status' => MockPaymentProvider::STATUS_SUCCEEDED,
        'metadata' => [],
    ]);
})->throws(QueryException::class);
