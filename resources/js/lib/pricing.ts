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

/** Formats basis points as a percentage, e.g. 2500 -> "25%" and 1250 -> "12.5%". */
export function formatMarkup(basisPoints: number): string {
    const whole = Math.trunc(basisPoints / 100);
    const hundredths = Math.abs(basisPoints % 100);

    if (hundredths === 0) {
        return `${whole}%`;
    }

    const fraction = String(hundredths).padStart(2, '0').replace(/0$/, '');
    const sign = basisPoints < 0 && whole === 0 ? '-' : '';

    return `${sign}${whole}.${fraction}%`;
}

function providerCostNanos(tokens: Tokens, pricing: Pricing): number {
    const nanoTokenRate = (Object.keys(pricing.rates) as TokenKind[]).reduce(
        (sum, kind) => sum + tokens[kind] * decimalToNanos(pricing.rates[kind]),
        0,
    );

    return Math.ceil(nanoTokenRate / 1_000_000);
}

function charge(providerNanos: number, markupBasisPoints: number): number {
    return Math.ceil((providerNanos * (10_000 + markupBasisPoints)) / 10_000);
}

/**
 * Live preview of the price, mirroring App\Services\Pixelforge\CostCalculator.
 * The server recomputes the estimate on submit; that is the one stored.
 */
export function estimateCharge(
    pricing: Pricing,
    markupBasisPoints: number,
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
            markupBasisPoints,
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
            markupBasisPoints,
        ),
    };
}
