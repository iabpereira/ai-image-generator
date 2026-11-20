<?php

namespace App\Services\Pixelforge;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Support\Money;

final readonly class CostCalculator
{
    private const int TOKENS_PER_RATE_UNIT = 1_000_000;

    private const int BASIS_POINTS = 10_000;

    /**
     * Typical uses the nominal text token count and mid-range reasoning; maximum uses
     * the upper text variance and the maximum reasoning for the quality.
     */
    public function estimate(
        Pricing $pricing,
        string $prompt,
        GenerationSize $size,
        GenerationQuality $quality,
        int $referenceImageCount,
        int $markupBasisPoints,
    ): CostEstimate {
        $nominalTextTokens = (int) ceil(mb_strlen($prompt) / $pricing->charactersPerTextToken);
        $maximumTextTokens = intdiv($nominalTextTokens * (100 + $pricing->textTokenVariancePercent) + 99, 100);
        $reasoning = $pricing->reasoningTokens[$quality->value];

        $fixedTokens = [
            'input_image_tokens' => $referenceImageCount * $pricing->tokensPerReferenceImage,
            'output_tokens' => $pricing->outputTokens[$quality->value][$size->value],
        ];

        $typicalProviderCost = $this->providerCost($fixedTokens + [
            'input_text_tokens' => $nominalTextTokens,
            'reasoning_tokens' => intdiv($reasoning['min'] + $reasoning['max'], 2),
        ], $pricing->rates);

        $maximumProviderCost = $this->providerCost($fixedTokens + [
            'input_text_tokens' => $maximumTextTokens,
            'reasoning_tokens' => $reasoning['max'],
        ], $pricing->rates);

        return new CostEstimate(
            typicalCharge: $this->charge($typicalProviderCost, $markupBasisPoints),
            maximumCharge: $this->charge($maximumProviderCost, $markupBasisPoints),
            maximumProviderCost: $maximumProviderCost,
        );
    }

    /**
     * Exact Pixelforge cost: Σ tokens × rate / 1M. Usage keys without a rate
     * (e.g. total_tokens) are ignored.
     *
     * @param  array<string, int>  $usage
     * @param  array<string, string>  $rates  USD per 1M tokens
     */
    public function providerCost(array $usage, array $rates): Money
    {
        $nanoTokenRate = 0;

        foreach ($rates as $tokenKind => $rate) {
            $nanoTokenRate += ($usage[$tokenKind] ?? 0) * Money::fromDecimalString($rate)->nanos;
        }

        return Money::fromNanos($nanoTokenRate)->multiplyRoundingUp(1, self::TOKENS_PER_RATE_UNIT);
    }

    /**
     * What the user pays: provider cost plus markup (2500 basis points = 25%),
     * rounded up to the next nanodollar.
     */
    public function charge(Money $providerCost, int $markupBasisPoints): Money
    {
        return $providerCost->multiplyRoundingUp(self::BASIS_POINTS + $markupBasisPoints, self::BASIS_POINTS);
    }
}
