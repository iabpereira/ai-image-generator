<?php

namespace App\Services\Pixelforge;

use App\Support\Money;

/**
 * Up-front price for a generation, before Pixelforge reports the real token usage.
 */
final readonly class CostEstimate
{
    public function __construct(
        public Money $typicalCharge,
        public Money $maximumCharge,
        public Money $maximumProviderCost,
    ) {}
}
