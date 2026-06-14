<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\MockPaymentOperation;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class MockPaymentProviderTimeoutException extends RuntimeException
{
    public function __construct(
        public readonly MockPaymentOperation $operation,
        public readonly int $statusCode = Response::HTTP_GATEWAY_TIMEOUT,
        string $message = 'The provider request timed out. Retry with the same idempotency key or check status later.',
    ) {
        parent::__construct($message);
    }
}
