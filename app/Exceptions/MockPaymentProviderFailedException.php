<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\MockPaymentOperation;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class MockPaymentProviderFailedException extends RuntimeException
{
    public function __construct(
        public readonly MockPaymentOperation $operation,
        public readonly int $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY,
        string $message = 'The provider permanently failed the payment operation.',
    ) {
        parent::__construct($message);
    }
}
