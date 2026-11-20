<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
});

it('polls stale in-flight generations and applies their state', function () {
    $stale = Generation::factory()->processing()->create(['updated_at' => now()->subMinutes(5)]);
    $fresh = Generation::factory()->processing()->create();

    Http::fake(["*/generations/{$stale->provider_id}" => Http::response(providerSnapshot($stale, 'failed'))]);

    $this->artisan('generations:sync')->assertSuccessful();

    expect($stale->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($fresh->refresh()->status)->toBe(GenerationStatus::Processing);
});

it('keeps going when pixelforge errors', function () {
    Generation::factory()->processing()->create(['updated_at' => now()->subMinutes(5)]);
    Http::fake(['*' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'busy']], 503)]);

    $this->artisan('generations:sync')->assertSuccessful();
});

it('is scheduled every minute', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('generations:sync')->assertSuccessful();
});

it('fails generations pixelforge no longer knows about', function () {
    $generation = Generation::factory()->processing()->create(['updated_at' => now()->subMinutes(5)]);
    Http::fake(['*' => Http::response(['error' => ['code' => 'not_found', 'message' => 'No such generation']], 404)]);

    $this->artisan('generations:sync')->assertSuccessful();

    expect($generation->refresh())->status->toBe(GenerationStatus::Failed)->error_code->toBe('not_found')
        ->and($generation->charged_amount->nanos)->toBe(0);
});

it('keeps syncing other generations when one snapshot is unusable', function () {
    $broken = Generation::factory()->processing()->create(['updated_at' => now()->subMinutes(10)]);
    $healthy = Generation::factory()->processing()->create(['updated_at' => now()->subMinutes(5)]);

    Http::fake([
        "*/generations/{$broken->provider_id}" => Http::response(['id' => $broken->provider_id]),
        "*/generations/{$healthy->provider_id}" => Http::response(providerSnapshot($healthy, 'failed')),
    ]);

    $this->artisan('generations:sync')->assertSuccessful();

    expect($healthy->refresh()->status)->toBe(GenerationStatus::Failed);
});
