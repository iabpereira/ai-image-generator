<?php

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Services\Pixelforge\CostCalculator;
use App\Services\Pixelforge\Pricing;
use App\Support\Money;

function pixelforgePricing(): Pricing
{
    return Pricing::fromApi([
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ]);
}

it('matches the documented maximum-cost worked example', function () {
    $estimate = (new CostCalculator)->estimate(
        pixelforgePricing(),
        str_repeat('a', 400),
        GenerationSize::Landscape,
        GenerationQuality::High,
        2, 2500,
    );

    expect($estimate->maximumProviderCost->toDecimalString())->toBe('0.373875000')
        ->and($estimate->maximumCharge->toDecimalString())->toBe('0.467343750');
});

it('estimates the typical charge with mid-range reasoning and nominal text tokens', function () {
    // provider: text 100*5000 + output 1100*40000 + reasoning 1100*12500 = 58_250_000; +25% = 72_812_500
    $estimate = (new CostCalculator)->estimate(
        pixelforgePricing(), str_repeat('a', 400), GenerationSize::Square, GenerationQuality::Medium, 0, 2500,
    );

    expect($estimate->typicalCharge->nanos)->toBe(72_812_500);
});

it('counts unicode characters, not bytes', function () {
    $calculator = new CostCalculator;

    $ascii = $calculator->estimate(pixelforgePricing(), 'aaaa', GenerationSize::Square, GenerationQuality::Low, 0, 2500);
    $emoji = $calculator->estimate(pixelforgePricing(), '🎨🎨🎨🎨', GenerationSize::Square, GenerationQuality::Low, 0, 2500);

    expect($emoji->typicalCharge)->toEqual($ascii->typicalCharge);
});

it('computes the actual provider cost from usage and the generation rates', function () {
    $cost = (new CostCalculator)->providerCost(
        ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411],
        ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
    );

    expect($cost->toDecimalString())->toBe('0.305510000');
});

it('applies the markup rounding up', function () {
    $calculator = new CostCalculator;

    expect($calculator->charge(Money::fromNanos(305_510_000), 2500)->nanos)->toBe(381_887_500)
        ->and($calculator->charge(Money::fromNanos(1), 2500)->nanos)->toBe(2);
});

it('supports fractional markups in basis points', function () {
    $calculator = new CostCalculator;

    expect($calculator->charge(Money::fromNanos(1_000_000_000), 1250)->toDecimalString())->toBe('1.125000000')
        ->and($calculator->charge(Money::fromNanos(1_000_000_000), 0)->toDecimalString())->toBe('1.000000000');
});
