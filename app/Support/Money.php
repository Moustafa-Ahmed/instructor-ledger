<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Immutable value object representing a money amount in integer cents.
 *
 * Why integer cents?
 *  - Floats are lossy. 0.1 + 0.2 = 0.30000000000000004. Money cannot
 *    tolerate that — a single rounding error of $0.0000000001 over a
 *    million transactions is real money.
 *  - Every downstream system (DB columns, JSON payloads, provider
 *    requests) speaks integer cents. One representation, one truth.
 */
final class Money
{
    public function __construct(
        public readonly int $cents,
        public readonly string $currency = 'USD',
    ) {}

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents + $other->cents, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents - $other->cents, $this->currency);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Currency mismatch');
        }
    }
}
