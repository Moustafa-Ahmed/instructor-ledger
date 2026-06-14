<?php

declare(strict_types=1);

use App\Models\MockPaymentOperation;
use App\Services\Payments\MockPaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('charges money through the api', function () {
    $this->app->instance(
        MockPaymentProvider::class,
        app(MockPaymentProvider::class)->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED),
    );

    $response = $this->postJson('/api/mock-provider/charges', [
        'idempotency_key' => 'api-charge-1',
        'amount_cents' => 1500,
        'currency' => 'usd',
        'metadata' => [
            'student_id' => 1,
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.type', MockPaymentProvider::TYPE_CHARGE)
        ->assertJsonPath('data.amount_cents', 1500)
        ->assertJsonPath('data.currency', 'USD')
        ->assertJsonPath('data.metadata.student_id', 1);

    expect(MockPaymentOperation::count())->toBe(1);
});

it('sends money through the api', function () {
    $this->app->instance(
        MockPaymentProvider::class,
        app(MockPaymentProvider::class)->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED),
    );

    $response = $this->postJson('/api/mock-provider/sends', [
        'idempotency_key' => 'api-send-1',
        'amount_cents' => 2500,
        'currency' => 'USD',
        'metadata' => [
            'instructor_id' => 10,
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.type', MockPaymentProvider::TYPE_SEND)
        ->assertJsonPath('data.amount_cents', 2500)
        ->assertJsonPath('data.metadata.instructor_id', 10);

    expect(MockPaymentOperation::count())->toBe(1);
});

it('keeps api operations idempotent', function () {
    $this->app->instance(
        MockPaymentProvider::class,
        app(MockPaymentProvider::class)->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED),
    );

    $payload = [
        'idempotency_key' => 'api-idempotent-charge',
        'amount_cents' => 1800,
        'currency' => 'USD',
    ];

    $first = $this->postJson('/api/mock-provider/charges', $payload);
    $second = $this->postJson('/api/mock-provider/charges', $payload);

    $first->assertCreated();
    $second->assertCreated();

    expect($second->json('data.provider_reference'))->toBe($first->json('data.provider_reference'))
        ->and(MockPaymentOperation::count())->toBe(1);
});

it('returns unprocessable entity when the provider fails an api operation', function () {
    $this->app->instance(
        MockPaymentProvider::class,
        app(MockPaymentProvider::class)->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_FAILED),
    );

    $response = $this->postJson('/api/mock-provider/charges', [
        'idempotency_key' => 'api-failed-charge',
        'amount_cents' => 1800,
        'currency' => 'USD',
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('message', 'The provider permanently failed the payment operation.')
        ->assertJsonPath('data.status', MockPaymentProvider::STATUS_FAILED);
});

it('returns gateway timeout when the provider times out after success', function () {
    $this->app->instance(
        MockPaymentProvider::class,
        app(MockPaymentProvider::class)->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_TIMEOUT_AFTER_SUCCESS),
    );

    $response = $this->postJson('/api/mock-provider/sends', [
        'idempotency_key' => 'api-timeout-send',
        'amount_cents' => 1800,
        'currency' => 'USD',
    ]);

    $response->assertStatus(504)
        ->assertJsonPath('message', 'The provider request timed out. Retry with the same idempotency key or check status later.');

    expect(MockPaymentOperation::first()->status)->toBe(MockPaymentProvider::STATUS_SUCCEEDED);
});

it('reconciles a timed out operation by idempotency key', function () {
    $this->app->instance(
        MockPaymentProvider::class,
        app(MockPaymentProvider::class)->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_TIMEOUT_AFTER_SUCCESS),
    );

    $this->postJson('/api/mock-provider/sends', [
        'idempotency_key' => 'api-timeout-status-send',
        'amount_cents' => 1800,
        'currency' => 'USD',
    ])->assertStatus(504);

    $response = $this->getJson('/api/mock-provider/operations/send/api-timeout-status-send/status');

    $response->assertOk()
        ->assertJsonPath('data.type', MockPaymentProvider::TYPE_SEND)
        ->assertJsonPath('data.status', MockPaymentProvider::STATUS_SUCCEEDED);
});

it('returns not found when an idempotency key has no operation yet', function () {
    $response = $this->getJson('/api/mock-provider/operations/send/missing-idempotency-key/status');

    $response->assertNotFound();
});

it('returns the real result when retrying a timed out api operation with the same idempotency key', function () {
    $this->app->instance(
        MockPaymentProvider::class,
        app(MockPaymentProvider::class)->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_TIMEOUT_AFTER_SUCCESS),
    );

    $payload = [
        'idempotency_key' => 'api-timeout-retry-send',
        'amount_cents' => 1800,
        'currency' => 'USD',
    ];

    $this->postJson('/api/mock-provider/sends', $payload)->assertStatus(504);
    $response = $this->postJson('/api/mock-provider/sends', $payload);

    $response->assertCreated()
        ->assertJsonPath('data.status', MockPaymentProvider::STATUS_SUCCEEDED);

    expect(MockPaymentOperation::count())->toBe(1);
});

it('checks operation status through the api', function () {
    $provider = app(MockPaymentProvider::class)
        ->useDeterministicOutcomes(MockPaymentProvider::OUTCOME_SUCCEEDED);

    $operation = $provider->sendMoney('api-status-send', 3000, 'USD');

    $response = $this->getJson("/api/mock-provider/operations/{$operation['provider_reference']}/status");

    $response->assertOk()
        ->assertJsonPath('data.status', MockPaymentProvider::STATUS_SUCCEEDED)
        ->assertJsonPath('data.provider_reference', $operation['provider_reference']);
});

it('returns not found when checking an unknown operation status', function () {
    $response = $this->getJson('/api/mock-provider/operations/missing-reference/status');

    $response->assertNotFound();
});

it('validates operation requests at the api boundary', function () {
    $response = $this->postJson('/api/mock-provider/charges', [
        'idempotency_key' => '',
        'amount_cents' => 0,
        'currency' => 'US',
        'metadata' => 'not-an-array',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'idempotency_key',
            'amount_cents',
            'currency',
            'metadata',
        ]);

    expect(MockPaymentOperation::count())->toBe(0);
});
