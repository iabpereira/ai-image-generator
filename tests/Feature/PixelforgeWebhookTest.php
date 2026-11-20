<?php

use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Models\PixelforgeWebhookEvent;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['services.pixelforge.webhook_secret' => 'whsec_test']);
    Queue::fake([StoreGenerationImage::class]);
});

/**
 * @param  array<string, mixed>  $event
 */
function sendWebhook(array $event, ?int $timestamp = null, string $secret = 'whsec_test'): TestResponse
{
    $body = json_encode($event);
    $timestamp ??= time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

    return test()->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
    ], $body);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function generationEvent(Generation $generation, string $status, string $eventId = 'evt_1', array $overrides = []): array
{
    return [
        'id' => $eventId,
        'object' => 'event',
        'type' => "generation.{$status}",
        'created_at' => now()->toIso8601String(),
        'data' => ['object' => providerSnapshot($generation, $status, $overrides)],
    ];
}

it('rejects invalid signatures', function () {
    $generation = Generation::factory()->processing()->create();

    sendWebhook(generationEvent($generation, 'succeeded'), secret: 'whsec_wrong')->assertStatus(400);

    expect(PixelforgeWebhookEvent::count())->toBe(0)
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Processing);
});

it('rejects stale timestamps', function () {
    $generation = Generation::factory()->processing()->create();

    sendWebhook(generationEvent($generation, 'succeeded'), timestamp: time() - 301)->assertStatus(400);
});

it('rejects a missing signature header', function () {
    $this->postJson(route('webhooks.pixelforge'), ['id' => 'evt_1'])->assertStatus(400);
});

it('applies a valid event and charges once', function () {
    $generation = Generation::factory()->processing()->create();

    sendWebhook(generationEvent($generation, 'succeeded'))->assertOk();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charged_amount->toDecimalString())->toBe('0.381887500');
});

it('records a duplicate delivery once and acknowledges it', function () {
    $generation = Generation::factory()->processing()->create();
    $event = generationEvent($generation, 'succeeded');

    sendWebhook($event)->assertOk();
    sendWebhook($event)->assertOk();

    expect(PixelforgeWebhookEvent::count())->toBe(1)
        ->and(PixelforgeWebhookEvent::sole()->deliveries)->toBe(2)
        ->and($generation->refresh()->charged_amount->toDecimalString())->toBe('0.381887500');
    Queue::assertPushed(StoreGenerationImage::class, 1);
});

it('ignores an out-of-order processing event after success', function () {
    $generation = Generation::factory()->processing()->create();

    sendWebhook(generationEvent($generation, 'succeeded', 'evt_2'))->assertOk();
    sendWebhook(generationEvent($generation, 'processing', 'evt_1'))->assertOk();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('matches by metadata uuid when the provider id is not stored yet', function () {
    $generation = Generation::factory()->create();

    sendWebhook(generationEvent($generation, 'processing', overrides: ['id' => 'gen_early']))->assertOk();

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Processing)
        ->provider_id->toBe('gen_early');
});

it('acknowledges ping events', function () {
    sendWebhook(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'created_at' => now()->toIso8601String(), 'data' => []])
        ->assertOk();
});
