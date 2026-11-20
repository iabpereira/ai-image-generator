<?php

use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Services\Generations\GenerationLifecycle;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([StoreGenerationImage::class]);
    Http::preventStrayRequests();
});

function runSubmit(Generation $generation): SubmitGeneration
{
    $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
    $job->handle(app(PixelforgeClient::class), app(GenerationLifecycle::class));

    return $job;
}

function pixelforgeError(int $status, string $code, array $headers = []): array
{
    return ['*' => Http::response(['error' => ['code' => $code, 'message' => 'nope']], $status, $headers)];
}

it('submits with the generation uuid as idempotency key and metadata', function () {
    $generation = Generation::factory()->create();
    Http::fake(['*/generations' => Http::response(providerSnapshot($generation, 'queued', ['id' => 'gen_1']), 202)]);

    runSubmit($generation)->assertNotReleased();

    expect($generation->refresh())->status->toBe(GenerationStatus::Queued)->provider_id->toBe('gen_1');
    Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', $generation->uuid)
        && $request['metadata'] === ['generation_uuid' => $generation->uuid]
        && array_keys($request->data()) === ['prompt', 'size', 'quality', 'reference_images', 'metadata']);
});

it('waits without calling the api when the concurrency limit is reached locally', function () {
    Generation::factory()->count(3)->processing()->create();
    $generation = Generation::factory()->create();

    runSubmit($generation)->assertReleased(delay: 5);
    Http::assertNothingSent();
});

it('honours retry-after when throttled', function (string $code) {
    $generation = Generation::factory()->create();
    Http::fake(pixelforgeError(429, $code, ['Retry-After' => '7']));

    runSubmit($generation)->assertReleased(delay: 7);
    expect($generation->refresh()->status)->toBe(GenerationStatus::Pending);
})->with(['rate_limit_exceeded', 'concurrency_limit_exceeded']);

it('releases with exponential backoff on overload', function () {
    $generation = Generation::factory()->create(['submission_attempts' => 2]);
    Http::fake(pixelforgeError(503, 'overloaded', ['Retry-After' => '2']));

    runSubmit($generation)->assertReleased(delay: 8);
    expect($generation->refresh()->submission_attempts)->toBe(3);
});

it('gives up after five transient failures without charging', function () {
    $generation = Generation::factory()->create(['submission_attempts' => 4]);
    Http::fake(pixelforgeError(503, 'overloaded', ['Retry-After' => '2']));

    runSubmit($generation)->assertNotReleased();

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('submission_failed')
        ->and($generation->charged_amount->nanos)->toBe(0);
});

it('fails immediately on permanent rejections', function (int $status, string $code) {
    $generation = Generation::factory()->create();
    Http::fake(pixelforgeError($status, $code));

    runSubmit($generation)->assertNotReleased();
    expect($generation->refresh())->status->toBe(GenerationStatus::Failed)->error_code->toBe($code);
})->with([
    [400, 'content_policy_violation'],
    [429, 'daily_limit_exceeded'],
    [422, 'validation_failed'],
]);

it('does nothing if a webhook already advanced the generation', function () {
    $generation = Generation::factory()->processing()->create();

    runSubmit($generation)->assertNotReleased();
    Http::assertNothingSent();
});

it('marks the generation failed when the job finally fails', function () {
    $generation = Generation::factory()->create();

    (new SubmitGeneration($generation))->failed(new RuntimeException('deadline'));

    expect($generation->refresh())->status->toBe(GenerationStatus::Failed)->error_code->toBe('submission_timeout');
});
