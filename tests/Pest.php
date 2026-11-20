<?php

use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A Pixelforge generation object as returned by the API and embedded in webhooks.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function providerSnapshot(Generation $generation, string $status, array $overrides = []): array
{
    $isFinal = in_array($status, ['succeeded', 'failed'], true);

    return array_replace([
        'id' => $generation->provider_id ?? 'gen_abc',
        'object' => 'generation',
        'status' => $status,
        'metadata' => ['generation_uuid' => $generation->uuid],
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'usage' => $status === 'succeeded'
            ? ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411]
            : null,
        'image_url' => $status === 'succeeded' ? 'https://pixelforge.promptedit.dev/images/gen_abc.png?signature=1' : null,
        'image_url_expires_at' => $status === 'succeeded' ? now()->addDay()->toIso8601String() : null,
        'error' => $status === 'failed' ? ['code' => 'generation_failed', 'message' => 'No image'] : null,
        'started_at' => $status === 'queued' ? null : now()->subSeconds(10)->toIso8601String(),
        'completed_at' => $isFinal ? now()->toIso8601String() : null,
    ], $overrides);
}

/**
 * Seed the cached GET /v1/pricing payload so controllers never hit the network.
 */
function seedPixelforgePricing(): void
{
    Cache::put('pixelforge.pricing', [
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
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
