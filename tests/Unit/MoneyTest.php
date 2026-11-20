<?php

use App\Support\Money;

it('parses pixelforge decimal strings exactly', function (string $input, int $nanos) {
    expect(Money::fromDecimalString($input)->nanos)->toBe($nanos);
})->with([
    ['0.046125000', 46_125_000],
    ['0.373875000', 373_875_000],
    ['12.5', 12_500_000_000],
    ['0', 0],
    ['-0.000000001', -1],
]);

it('formats as a 9-decimal string', function () {
    expect(Money::fromNanos(305_510_000)->toDecimalString())->toBe('0.305510000')
        ->and(Money::fromNanos(-1)->toDecimalString())->toBe('-0.000000001')
        ->and(Money::fromDollars(5)->toDecimalString())->toBe('5.000000000');
});

it('rejects malformed amounts', function (string $input) {
    Money::fromDecimalString($input);
})->throws(InvalidArgumentException::class)->with(['abc', '1.0000000001', '1e-9', '']);

it('multiplies rounding up to the next nanodollar', function () {
    expect(Money::fromNanos(305_510_000)->multiplyRoundingUp(125, 100)->nanos)->toBe(381_887_500)
        ->and(Money::fromNanos(1)->multiplyRoundingUp(125, 100)->nanos)->toBe(2)
        ->and(Money::fromNanos(4)->multiplyRoundingUp(125, 100)->nanos)->toBe(5);
});

it('adds, subtracts and compares', function () {
    $a = Money::fromNanos(10);
    $b = Money::fromNanos(3);

    expect($a->plus($b)->nanos)->toBe(13)
        ->and($b->minus($a)->isNegative())->toBeTrue()
        ->and($a->isGreaterThan($b))->toBeTrue();
});
