export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';
export type GenerationSize = '1024x1024' | '1536x1024' | '1024x1536';
export type GenerationQuality = 'low' | 'medium' | 'high';
export type TokenKind =
    | 'input_text_tokens'
    | 'input_image_tokens'
    | 'output_tokens'
    | 'reasoning_tokens';

export type Pricing = {
    rates: Record<TokenKind, string>;
    characters_per_text_token: number;
    text_token_variance_percent: number;
    tokens_per_reference_image: number;
    output_tokens: Record<GenerationQuality, Record<GenerationSize, number>>;
    reasoning_tokens: Record<GenerationQuality, { min: number; max: number }>;
};

export type Generation = {
    id: number;
    status: GenerationStatus;
    prompt: string;
    size: GenerationSize;
    quality: GenerationQuality;
    reference_images: string[];
    estimated_charge: string;
    maximum_charge: string;
    charged_amount: string | null;
    image_src: string | null;
    image_download_url: string | null;
    image_expired: boolean;
    error_message: string | null;
    created_at: string;
    completed_at: string | null;
};

export type Paginated<T> = {
    data: T[];
    links: { prev: string | null; next: string | null };
    meta: { current_page: number; last_page: number; total: number };
};
