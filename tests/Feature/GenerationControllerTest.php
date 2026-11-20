<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    seedPixelforgePricing();
});

it('requires authentication', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->post(route('generations.store'))->assertRedirect(route('login'));
});

it('requires a verified email to generate', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->post(route('generations.store'), ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertRedirect(route('verification.notice'));

    Queue::assertNothingPushed();
});

it('shows the generate form with pricing on the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard')
            ->where('pricing.rates.output_tokens', '40.00')
            ->where('markupPercent', 25));
});

it('redirects the old generation pages to the dashboard', function (string $uri) {
    $this->actingAs(User::factory()->create())
        ->get($uri)
        ->assertRedirect('/dashboard');
})->with(['/generations', '/generations/create']);

it('stores a pending generation with its estimate and queues submission', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('generations.store'), [
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
    ])->assertRedirect(route('dashboard'));

    $generation = $user->generations()->sole();
    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->maximum_charge->toDecimalString())->toBe('0.467343750')
        ->and($generation->reference_images)->toHaveCount(2);
    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($generation));
});

it('validates input', function (array $input, string $errorKey) {
    $this->actingAs(User::factory()->create())
        ->post(route('generations.store'), array_merge(['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low'], $input))
        ->assertSessionHasErrors($errorKey);

    Queue::assertNothingPushed();
})->with([
    'missing prompt' => [['prompt' => ''], 'prompt'],
    'long prompt' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
    'bad size' => [['size' => '512x512'], 'size'],
    'bad quality' => [['quality' => 'ultra'], 'quality'],
    'too many refs' => [['reference_images' => array_fill(0, 5, 'https://e.com/a.png')], 'reference_images'],
    'non-http ref' => [['reference_images' => ['ftp://e.com/a.png']], 'reference_images.0'],
]);

it('lists only the current user generations, newest first', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->succeeded()->create();
    $newer = Generation::factory()->for($user)->create();
    Generation::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard')
            ->has('generations.data', 2)
            ->where('generations.data.0.id', $newer->id)
            ->where('generations.data.1.charged_amount', '0.381887500'));
});
