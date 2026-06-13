<?php

declare(strict_types=1);

use App\Support\Money;

it('creates a money value object', function () {
    $money = new Money(100);

    expect($money->cents)->toBe(100)
        ->and($money->currency)->toBe('USD');
});

it('supports custom currency', function () {
    $money = new Money(100, 'EUR');

    expect($money->currency)->toBe('EUR');
});

it('adds two money values of same currency', function () {
    $a = new Money(100);
    $b = new Money(40);

    $result = $a->add($b);

    expect($result->cents)->toBe(140)
        ->and($result->currency)->toBe('USD');
});

it('subtracts two money values of same currency', function () {
    $a = new Money(100);
    $b = new Money(40);

    $result = $a->subtract($b);

    expect($result->cents)->toBe(60);
});

it('is immutable (operations do not mutate original)', function () {
    $a = new Money(100);
    $b = new Money(40);

    $a->add($b);
    $a->subtract($b);

    expect($a->cents)->toBe(100);
});

it('rejects currency mismatch on addition', function () {
    $a = new Money(100, 'USD');
    $b = new Money(100, 'EUR');

    $a->add($b);
})->throws(InvalidArgumentException::class);

it('rejects currency mismatch on subtraction', function () {
    $a = new Money(100, 'USD');
    $b = new Money(100, 'EUR');

    $a->subtract($b);
})->throws(InvalidArgumentException::class);

it('handles zero correctly', function () {
    $money = new Money(0);

    expect($money->cents)->toBe(0);
});

it('supports negative values if allowed by domain', function () {
    $money = new Money(-50);

    expect($money->cents)->toBe(-50);
});
