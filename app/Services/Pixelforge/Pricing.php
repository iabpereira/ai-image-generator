<?php

namespace App\Services\Pixelforge;

/**
 * Pixelforge's published price list (GET /v1/pricing).
 */
final readonly class Pricing
{
    /**
     * @param  array<string, string>  $rates  USD per 1M tokens, keyed by token kind
     * @param  array<string, array<string, int>>  $outputTokens  quality => size => tokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  quality => range
     */
    public function __construct(
        public array $rates,
        public int $charactersPerTextToken,
        public int $textTokenVariancePercent,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /**
     * @param  array{rates: array<string, string>, tokens: array{characters_per_text_token: int, text_token_variance: float|int, per_reference_image: int, output: array<string, array<string, int>>, reasoning: array<string, array{min: int, max: int}>}}  $payload
     */
    public static function fromApi(array $payload): self
    {
        $tokens = $payload['tokens'];

        return new self(
            rates: $payload['rates'],
            charactersPerTextToken: (int) $tokens['characters_per_text_token'],
            textTokenVariancePercent: (int) round($tokens['text_token_variance'] * 100),
            tokensPerReferenceImage: (int) $tokens['per_reference_image'],
            outputTokens: $tokens['output'],
            reasoningTokens: $tokens['reasoning'],
        );
    }

    /**
     * @return array{rates: array<string, string>, characters_per_text_token: int, text_token_variance_percent: int, tokens_per_reference_image: int, output_tokens: array<string, array<string, int>>, reasoning_tokens: array<string, array{min: int, max: int}>}
     */
    public function toArray(): array
    {
        return [
            'rates' => $this->rates,
            'characters_per_text_token' => $this->charactersPerTextToken,
            'text_token_variance_percent' => $this->textTokenVariancePercent,
            'tokens_per_reference_image' => $this->tokensPerReferenceImage,
            'output_tokens' => $this->outputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
        ];
    }
}
