<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Exceptions\MockPaymentProviderFailedException;
use App\Exceptions\MockPaymentProviderTimeoutException;
use App\Models\MockPaymentOperation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MockPaymentProvider
{
    public const TYPE_CHARGE = 'charge';

    public const TYPE_SEND = 'send';

    public const TYPE_REFUND = 'refund';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNKNOWN = 'unknown';

    public const OUTCOME_SUCCEEDED = 'succeeded';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_TIMEOUT_AFTER_SUCCESS = 'timeout_after_success';

    /**
     * @var list<string>|null
     */
    private ?array $deterministicOutcomes = null;

    private int $deterministicOutcomeIndex = 0;

    /**
     * Charge money from a student.
     */
    public function chargeMoney(string $idempotencyKey, int $amountCents, string $currency, array $metadata = []): array
    {
        return $this->createOrReturnOperation(
            self::TYPE_CHARGE,
            $idempotencyKey,
            $amountCents,
            $currency,
            $metadata,
        );
    }

    /** Send money to an instructor. */
    public function sendMoney(string $idempotencyKey, int $amountCents, string $currency, array $metadata = []): array
    {
        return $this->createOrReturnOperation(
            self::TYPE_SEND,
            $idempotencyKey,
            $amountCents,
            $currency,
            $metadata,
        );
    }

    /** Refund money to a student. */
    public function refundMoney(string $idempotencyKey, int $amountCents, string $currency, array $metadata = []): array
    {
        return $this->createOrReturnOperation(
            self::TYPE_REFUND,
            $idempotencyKey,
            $amountCents,
            $currency,
            $metadata,
        );
    }

    /** Check the real provider status for an operation reference. */
    public function status(string $providerReference): array
    {
        return $this->formatOperation(
            MockPaymentOperation::query()
                ->where('provider_reference', $providerReference)
                ->firstOrFail(),
        );
    }

    /**
     * Look up an existing operation by reference, throwing if missing.
     * Used for status endpoints where a missing record is a real error.
     */
    private function findOperationOrFail(string $operationType, string $idempotencyKey): MockPaymentOperation
    {
        return MockPaymentOperation::query()
            ->where('operation_type', $operationType)
            ->where('idempotency_key', $idempotencyKey)
            ->firstOrFail();
    }

    public function useRandomOutcomes(): self
    {
        $this->deterministicOutcomes = null;
        $this->deterministicOutcomeIndex = 0;

        return $this;
    }

    /**
     * @param  array<int, string>|string  $outcomes
     */
    public function useDeterministicOutcomes(array|string $outcomes): self
    {
        $outcomes = is_array($outcomes) ? array_values($outcomes) : [$outcomes];

        if ($outcomes === []) {
            throw new InvalidArgumentException('At least one deterministic outcome is required.');
        }

        foreach ($outcomes as $outcome) {
            $this->validateOutcome($outcome);
        }

        $this->deterministicOutcomes = $outcomes;
        $this->deterministicOutcomeIndex = 0;

        return $this;
    }

    private function createOrReturnOperation(
        string $operationType,
        string $idempotencyKey,
        int $amountCents,
        string $currency,
        array $metadata,
    ): array {
        $idempotencyKey = trim($idempotencyKey);
        $currency = strtoupper(trim($currency));

        $this->validateOperationInput($idempotencyKey, $amountCents, $currency);

        $existingOperation = $this->findOperationOrNull($operationType, $idempotencyKey);

        if ($existingOperation) {
            return $this->returnOrThrowOperationResult($existingOperation);
        }

        $outcome = $this->chooseOutcome();
        $status = $this->statusForOutcome($outcome);

        try {
            $operation = DB::transaction(function () use ($operationType, $idempotencyKey, $amountCents, $currency, $metadata, $status) {
                $existingOperation = $this->findOperationOrNull($operationType, $idempotencyKey);

                if ($existingOperation) {
                    return $existingOperation;
                }

                return MockPaymentOperation::query()->create([
                    'provider_reference' => (string) Str::uuid(),
                    'operation_type' => $operationType,
                    'idempotency_key' => $idempotencyKey,
                    'amount_cents' => $amountCents,
                    'currency' => $currency,
                    'status' => $status,
                    'metadata' => $metadata,
                ]);
            });
        } catch (QueryException $exception) {
            $operation = $this->findOperationOrNull($operationType, $idempotencyKey);

            if (! $operation) {
                throw $exception;
            }

            return $this->returnOrThrowOperationResult($operation);
        }

        if ($outcome === self::OUTCOME_TIMEOUT_AFTER_SUCCESS) {
            throw new MockPaymentProviderTimeoutException($operation);
        }

        if ($status === self::STATUS_FAILED) {
            throw new MockPaymentProviderFailedException($operation);
        }

        return $this->formatOperation($operation);
    }

    public function statusByIdempotencyKey(string $operationType, string $idempotencyKey): array
    {

        if (! in_array($operationType, [self::TYPE_CHARGE, self::TYPE_SEND, self::TYPE_REFUND], true)) {
            throw new InvalidArgumentException('Operation type must be charge, send, or refund.');
        }

        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Idempotency key is required.');
        }

        return $this->formatOperation(
            $this->findOperationOrFail($operationType, $idempotencyKey),
        );
    }

    // Idempotency-path lookup: returns null when the record doesn't exist yet
    // (e.g. on the first charge/send for a given key). Must NOT throw.
    private function findOperationOrNull(string $operationType, string $idempotencyKey): ?MockPaymentOperation
    {
        return MockPaymentOperation::query()
            ->where('operation_type', $operationType)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function validateOperationInput(string $idempotencyKey, int $amountCents, string $currency): void
    {
        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Idempotency key is required.');
        }

        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Amount must be a positive number of cents.');
        }

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Currency must be a three-letter code.');
        }
    }

    private function chooseOutcome(): string
    {
        if ($this->deterministicOutcomes !== null) {
            $index = min($this->deterministicOutcomeIndex, count($this->deterministicOutcomes) - 1);
            $this->deterministicOutcomeIndex++;

            return $this->deterministicOutcomes[$index];
        }

        $outcomes = [
            self::OUTCOME_SUCCEEDED,
            self::OUTCOME_FAILED,
            self::OUTCOME_TIMEOUT_AFTER_SUCCESS,
        ];

        return $outcomes[random_int(0, count($outcomes) - 1)];
    }

    private function statusForOutcome(string $outcome): string
    {
        return match ($outcome) {
            self::OUTCOME_SUCCEEDED => self::STATUS_SUCCEEDED,
            self::OUTCOME_FAILED => self::STATUS_FAILED,
            self::OUTCOME_TIMEOUT_AFTER_SUCCESS => self::STATUS_SUCCEEDED,
        };
    }

    private function validateOutcome(string $outcome): void
    {
        if (! in_array($outcome, [
            self::OUTCOME_SUCCEEDED,
            self::OUTCOME_FAILED,
            self::OUTCOME_TIMEOUT_AFTER_SUCCESS,
        ], true)) {
            throw new InvalidArgumentException("Unsupported provider outcome [{$outcome}].");
        }
    }

    private function formatOperation(MockPaymentOperation $operation): array
    {
        return [
            'provider_reference' => $operation->provider_reference,
            'type' => $operation->operation_type,
            'status' => $operation->status,
            'amount_cents' => $operation->amount_cents,
            'currency' => $operation->currency,
            'metadata' => $operation->metadata ?? [],
        ];
    }

    private function returnOrThrowOperationResult(MockPaymentOperation $operation): array
    {
        if ($operation->status === self::STATUS_FAILED) {
            throw new MockPaymentProviderFailedException($operation);
        }

        return $this->formatOperation($operation);
    }
}
