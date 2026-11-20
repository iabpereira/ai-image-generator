<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;

/**
 * An exact USD amount held as integer nanodollars (1 USD = 1_000_000_000),
 * the precision Pixelforge reports costs in.
 */
final readonly class Money implements JsonSerializable
{
    public const int NANOS_PER_DOLLAR = 1_000_000_000;

    private function __construct(public int $nanos) {}

    public static function fromNanos(int $nanos): self
    {
        return new self($nanos);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromDollars(int $dollars): self
    {
        return new self($dollars * self::NANOS_PER_DOLLAR);
    }

    public static function fromDecimalString(string $amount): self
    {
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,9}))?$/', $amount, $matches)) {
            throw new InvalidArgumentException("Invalid money amount [{$amount}].");
        }

        $nanos = (int) $matches[2] * self::NANOS_PER_DOLLAR
            + (int) str_pad($matches[3] ?? '', 9, '0');

        return new self($matches[1] === '-' ? -$nanos : $nanos);
    }

    public function toDecimalString(): string
    {
        $absolute = abs($this->nanos);

        return ($this->nanos < 0 ? '-' : '')
            .intdiv($absolute, self::NANOS_PER_DOLLAR)
            .'.'
            .str_pad((string) ($absolute % self::NANOS_PER_DOLLAR), 9, '0', STR_PAD_LEFT);
    }

    public function plus(self $other): self
    {
        return new self($this->nanos + $other->nanos);
    }

    public function minus(self $other): self
    {
        return new self($this->nanos - $other->nanos);
    }

    public function isNegative(): bool
    {
        return $this->nanos < 0;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->nanos > $other->nanos;
    }

    /**
     * Multiply by a ratio, rounding any fraction of a nanodollar up.
     */
    public function multiplyRoundingUp(int $numerator, int $denominator): self
    {
        $product = $this->nanos * $numerator;
        $quotient = intdiv($product, $denominator);

        if ($product % $denominator > 0) {
            $quotient++;
        }

        return new self($quotient);
    }

    public function jsonSerialize(): string
    {
        return $this->toDecimalString();
    }
}
