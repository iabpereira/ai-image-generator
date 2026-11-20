<?php

use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.pixelforge.api_key' => 'pk_test',
        'services.pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
    ]);
    Http::preventStrayRequests();
});

it('creates a generation with bearer auth and idempotency key', function () {
    Http::fake(['pixelforge.promptedit.dev/v1/generations' => Http::response(['id' => 'gen_1', 'status' => 'queued'], 202)]);

    $generation = app(PixelforgeClient::class)->createGeneration(['prompt' => 'a cat'], 'uuid-1');

    expect($generation['id'])->toBe('gen_1');
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer pk_test')
        && $request->hasHeader('Idempotency-Key', 'uuid-1')
        && $request['prompt'] === 'a cat');
});

it('maps api errors to typed exceptions', function (int $status, string $code, array $headers, bool $throttled, bool $transient) {
    Http::fake(['*' => Http::response(['error' => ['type' => 'x', 'code' => $code, 'message' => 'nope']], $status, $headers)]);

    try {
        app(PixelforgeClient::class)->createGeneration(['prompt' => 'a'], 'k');
        $this->fail('Expected a PixelforgeException.');
    } catch (PixelforgeException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->status)->toBe($status)
            ->and($exception->isThrottled())->toBe($throttled)
            ->and($exception->isTransient())->toBe($transient)
            ->and($exception->retryAfter)->toBe(isset($headers['Retry-After']) ? (int) $headers['Retry-After'] : null);
    }
})->with([
    'rate limit' => [429, 'rate_limit_exceeded', ['Retry-After' => '12'], true, false],
    'concurrency' => [429, 'concurrency_limit_exceeded', ['Retry-After' => '5'], true, false],
    'daily' => [429, 'daily_limit_exceeded', [], false, false],
    'overloaded' => [503, 'overloaded', ['Retry-After' => '2'], false, true],
    'content policy' => [400, 'content_policy_violation', [], false, false],
    'validation' => [422, 'validation_failed', [], false, false],
]);

it('treats connection failures as transient', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    try {
        app(PixelforgeClient::class)->retrieveGeneration('gen_1');
        $this->fail('Expected a PixelforgeException.');
    } catch (PixelforgeException $exception) {
        expect($exception->isTransient())->toBeTrue()
            ->and($exception->errorCode)->toBe('connection_failed');
    }
});

it('caches pricing', function () {
    Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response([
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => ['low' => ['1024x1024' => 280]],
            'reasoning' => ['low' => ['min' => 0, 'max' => 500]],
        ],
    ])]);

    app(PixelforgeClient::class)->pricing();
    $pricing = app(PixelforgeClient::class)->pricing();

    expect($pricing->textTokenVariancePercent)->toBe(15);
    Http::assertSentCount(1);
});

it('returns null pricing when pixelforge is unreachable and nothing is cached', function () {
    Http::fake(['*/pricing' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'busy']], 503)]);

    expect(app(PixelforgeClient::class)->pricingOrNull())->toBeNull();
});

it('returns the provider cost per day from the usage endpoint', function () {
    Http::fake(['pixelforge.promptedit.dev/v1/usage*' => Http::response([
        'object' => 'usage',
        'data' => [
            ['date' => '2026-11-01', 'generations' => 3, 'cost_usd' => '0.169080000'],
            ['date' => '2026-11-02', 'generations' => 0, 'cost_usd' => '0.000000000'],
        ],
    ])]);

    $usage = app(PixelforgeClient::class)->usage(
        CarbonImmutable::parse('2026-11-01'),
        CarbonImmutable::parse('2026-11-02'),
    );

    expect($usage['2026-11-01']->nanos)->toBe(169_080_000)
        ->and($usage['2026-11-02']->nanos)->toBe(0);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'start_date=2026-11-01')
        && str_contains($request->url(), 'end_date=2026-11-02')
        && $request->hasHeader('Authorization', 'Bearer pk_test'));
});
