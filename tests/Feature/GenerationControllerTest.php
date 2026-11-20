<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    seedPixelforgePricing();
});

it('requires authentication', function (string $routeName) {
    $this->get(route($routeName))->assertRedirect(route('login'));
})->with(['dashboard', 'generations.create', 'generations.gallery']);

it('requires authentication to generate', function () {
    $this->post(route('generations.store'))->assertRedirect(route('login'));
});

it('requires a verified email to generate', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->post(route('generations.store'), ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertRedirect(route('verification.notice'));

    Queue::assertNothingPushed();
});

it('shows the generate form with pricing', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('generations.create'))
        ->assertInertia(fn (Assert $page) => $page->component('generations/Create')
            ->where('pricing.rates.output_tokens', '40.00')
            ->where('markupPercent', 25));
});

it('stores a pending generation with its estimate and queues submission', function () {
    $user = User::factory()->create();
    app(CreditLedger::class)->purchase($user, Money::fromDollars(1));

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

it('still shows the generate page when pricing is unavailable', function () {
    Cache::forget('pixelforge.pricing');
    Http::fake(['*/pricing' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'busy']], 503)]);

    $this->actingAs(User::factory()->create())
        ->get(route('generations.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('generations/Create')->where('pricing', null));
});

it('explains that generating is unavailable when pricing cannot be loaded', function () {
    Cache::forget('pixelforge.pricing');
    Http::fake(['*/pricing' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'busy']], 503)]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('generations.store'), ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertSessionHasErrors('pricing');

    expect($user->generations()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('shows only the current user succeeded images in the gallery', function () {
    $user = User::factory()->create();
    $image = Generation::factory()->for($user)->succeeded()->create();
    Generation::factory()->for($user)->failed()->create();
    Generation::factory()->for($user)->processing()->create();
    Generation::factory()->succeeded()->create();

    $this->actingAs($user)->get(route('generations.gallery'))
        ->assertInertia(fn (Assert $page) => $page->component('generations/Gallery')
            ->has('generations.data', 1)
            ->where('generations.data.0.id', $image->id));
});

it('redirects the old generation URLs', function (string $uri, string $destination) {
    $this->actingAs(User::factory()->create())
        ->get($uri)
        ->assertRedirect($destination);
})->with([['/generations', '/dashboard'], ['/generations/create', '/generate']]);
