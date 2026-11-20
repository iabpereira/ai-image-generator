<?php

use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Services\Generations\GenerationLifecycle;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

it('records the exact cost with markup when a generation succeeds', function () {
    $generation = Generation::factory()->processing()->create();

    $changed = app(GenerationLifecycle::class)->apply($generation, providerSnapshot($generation, 'succeeded'));

    $generation->refresh();
    expect($changed)->toBeTrue()
        ->and($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost->toDecimalString())->toBe('0.305510000')
        ->and($generation->charged_amount->toDecimalString())->toBe('0.381887500')
        ->and($generation->usage['reasoning_tokens'])->toBe(3184)
        ->and($generation->image_url)->not->toBeNull()
        ->and($generation->completed_at)->not->toBeNull();
    Queue::assertPushed(StoreGenerationImage::class);
});

it('charges nothing for failed generations', function () {
    $generation = Generation::factory()->processing()->create();

    app(GenerationLifecycle::class)->apply($generation, providerSnapshot($generation, 'failed'));

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->charged_amount->nanos)->toBe(0)
        ->and($generation->provider_cost->nanos)->toBe(0)
        ->and($generation->error_code)->toBe('generation_failed');
});

it('ignores a duplicate succeeded event', function () {
    $generation = Generation::factory()->processing()->create();
    $lifecycle = app(GenerationLifecycle::class);

    expect($lifecycle->apply($generation, providerSnapshot($generation, 'succeeded')))->toBeTrue()
        ->and($lifecycle->apply($generation, providerSnapshot($generation, 'succeeded')))->toBeFalse();
    Queue::assertPushed(StoreGenerationImage::class, 1);
});

it('never moves backwards when events arrive out of order', function () {
    $generation = Generation::factory()->processing()->create();
    $lifecycle = app(GenerationLifecycle::class);

    $lifecycle->apply($generation, providerSnapshot($generation, 'succeeded'));
    $changed = $lifecycle->apply($generation, providerSnapshot($generation, 'processing'));

    expect($changed)->toBeFalse()
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('never flips a final status', function () {
    $generation = Generation::factory()->failed()->create();

    app(GenerationLifecycle::class)->apply($generation, providerSnapshot($generation, 'succeeded'));

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->charged_amount->nanos)->toBe(0);
});

it('accepts a webhook that arrives before the submit response stored the provider id', function () {
    $generation = Generation::factory()->create();

    app(GenerationLifecycle::class)->apply($generation, providerSnapshot($generation, 'processing', ['id' => 'gen_early']));

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Processing)
        ->provider_id->toBe('gen_early');
});

it('fails locally without cost', function () {
    $generation = Generation::factory()->create();

    expect(app(GenerationLifecycle::class)->fail($generation, 'content_policy_violation', 'Rejected'))->toBeTrue();

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_message->toBe('Rejected')
        ->and($generation->charged_amount->nanos)->toBe(0);
});

it('ignores snapshots with an unknown status', function () {
    $generation = Generation::factory()->processing()->create();

    expect(app(GenerationLifecycle::class)->apply($generation, providerSnapshot($generation, 'canceled')))->toBeFalse()
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Processing);
});

it('ignores a succeeded snapshot without usage', function () {
    $generation = Generation::factory()->processing()->create();

    expect(app(GenerationLifecycle::class)->apply($generation, providerSnapshot($generation, 'succeeded', ['usage' => null])))->toBeFalse()
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Processing);
});

it('recovers a generation we failed locally when pixelforge reports it after all', function () {
    $generation = Generation::factory()->create();
    app(GenerationLifecycle::class)->failSubmission($generation, 'submission_failed', 'Pixelforge is unavailable.');

    $changed = app(GenerationLifecycle::class)->apply($generation, providerSnapshot($generation, 'succeeded', ['id' => 'gen_late']));

    expect($changed)->toBeTrue()
        ->and($generation->refresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->provider_id->toBe('gen_late')
        ->error_code->toBeNull()
        ->and($generation->charged_amount->toDecimalString())->toBe('0.381887500');
});

it('does not recover generations pixelforge itself rejected', function () {
    $generation = Generation::factory()->create();
    app(GenerationLifecycle::class)->failSubmission($generation, 'content_policy_violation', 'Rejected.');

    expect(app(GenerationLifecycle::class)->apply($generation, providerSnapshot($generation, 'succeeded')))->toBeFalse();
});

it('only fails a submission that pixelforge has not accepted', function () {
    $generation = Generation::factory()->queued()->create();

    expect(app(GenerationLifecycle::class)->failSubmission($generation, 'submission_timeout', 'Timed out.'))->toBeFalse()
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});
