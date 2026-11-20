<?php

use App\Jobs\StoreGenerationImage;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Services\Generations\GenerationLifecycle;
use App\Support\Money;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    seedPixelforgePricing();
});

function userWithCredits(int $nanos): User
{
    $user = User::factory()->create();
    app(CreditLedger::class)->purchase($user, Money::fromNanos($nanos));

    return $user;
}

function heldGeneration(User $user): Generation
{
    $generation = Generation::factory()->for($user)->processing()->create(['maximum_charge' => Money::fromNanos(467_343_750)]);
    app(CreditLedger::class)->hold($generation);

    return $generation;
}

it('blocks a generation the user cannot afford', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('generations.store'), ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'high'])
        ->assertSessionHasErrors('credits');

    expect($user->generations()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('holds the maximum charge on submit', function () {
    $user = userWithCredits(1_000_000_000);

    $this->actingAs($user)->post(route('generations.store'), ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'high']);

    $generation = $user->generations()->sole();
    expect($user->refresh()->credit_balance->nanos)->toBe(1_000_000_000 - $generation->maximum_charge->nanos);
    Queue::assertPushed(SubmitGeneration::class);
});

it('charges the actual amount once when the generation succeeds', function () {
    $user = userWithCredits(1_000_000_000);
    $generation = heldGeneration($user);
    $lifecycle = app(GenerationLifecycle::class);

    $lifecycle->apply($generation, providerSnapshot($generation, 'succeeded'));
    $lifecycle->apply($generation, providerSnapshot($generation, 'succeeded'));

    expect($user->refresh()->credit_balance->nanos)->toBe(1_000_000_000 - 381_887_500);
    Queue::assertPushed(StoreGenerationImage::class, 1);
});

it('returns the hold when pixelforge fails the generation', function () {
    $user = userWithCredits(1_000_000_000);
    $generation = heldGeneration($user);

    app(GenerationLifecycle::class)->apply($generation, providerSnapshot($generation, 'failed'));

    expect($user->refresh()->credit_balance->nanos)->toBe(1_000_000_000);
});

it('returns the hold when the submission is rejected', function () {
    $user = userWithCredits(1_000_000_000);
    $generation = Generation::factory()->for($user)->create(['maximum_charge' => Money::fromNanos(467_343_750)]);
    app(CreditLedger::class)->hold($generation);

    app(GenerationLifecycle::class)->failSubmission($generation, 'content_policy_violation', 'Rejected.');

    expect($user->refresh()->credit_balance->nanos)->toBe(1_000_000_000);
});

it('charges a recovered submission exactly once', function () {
    $user = userWithCredits(1_000_000_000);
    $generation = Generation::factory()->for($user)->create(['maximum_charge' => Money::fromNanos(467_343_750)]);
    app(CreditLedger::class)->hold($generation);
    $lifecycle = app(GenerationLifecycle::class);

    $lifecycle->failSubmission($generation, 'submission_timeout', 'Timed out.');
    $lifecycle->apply($generation, providerSnapshot($generation, 'succeeded', ['id' => 'gen_late']));

    expect($user->refresh()->credit_balance->nanos)->toBe(1_000_000_000 - 381_887_500);
});
