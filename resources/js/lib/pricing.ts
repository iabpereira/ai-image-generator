import { decimalToNanos } from '@/lib/money';
import type {
    GenerationQuality,
    GenerationSize,
    Pricing,
    TokenKind,
} from '@/types/generation';

type Tokens = Record<TokenKind, number>;

/** Unicode code points, the unit Pixelforge (and PHP's mb_strlen) counts characters in. */
export function codePointLength(text: string): number {
    return Array.from(text).length;
}

function providerCostNanos(tokens: Tokens, pricing: Pricing): number {
    const nanoTokenRate = (Object.keys(pricing.rates) as TokenKind[]).reduce(
        (sum, kind) => sum + tokens[kind] * decimalToNanos(pricing.rates[kind]),
        0,
    );

    return Math.ceil(nanoTokenRate / 1_000_000);
}

function charge(providerNanos: number, markupPercent: number): number {
    return Math.ceil((providerNanos * (100 + markupPercent)) / 100);
}

/**
 * Live preview of the price, mirroring App\Services\Pixelforge\CostCalculator.
 * The server recomputes the estimate on submit; that is the one stored.
 */
export function estimateCharge(
    pricing: Pricing,
    markupPercent: number,
    prompt: string,
    size: GenerationSize,
    quality: GenerationQuality,
    referenceImageCount: number,
): { typical: number; maximum: number } {
    const nominalText = Math.ceil(
        codePointLength(prompt) / pricing.characters_per_text_token,
    );
    const maximumText = Math.ceil(
        (nominalText * (100 + pricing.text_token_variance_percent)) / 100,
    );
    const reasoning = pricing.reasoning_tokens[quality];
    const fixed = {
        input_image_tokens:
            referenceImageCount * pricing.tokens_per_reference_image,
        output_tokens: pricing.output_tokens[quality][size],
    };

    return {
        typical: charge(
            providerCostNanos(
                {
                    ...fixed,
                    input_text_tokens: nominalText,
                    reasoning_tokens: Math.floor(
                        (reasoning.min + reasoning.max) / 2,
                    ),
                },
                pricing,
            ),
            markupPercent,
        ),
        maximum: charge(
            providerCostNanos(
                {
                    ...fixed,
                    input_text_tokens: maximumText,
                    reasoning_tokens: reasoning.max,
                },
                pricing,
            ),
            markupPercent,
        ),
    };
}
