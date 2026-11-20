<?php

use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
});

function storedImage(): Generation
{
    $generation = Generation::factory()->succeeded()->create(['image_path' => 'generations/x.png']);
    Storage::disk('local')->put('generations/x.png', 'PNGDATA');

    return $generation;
}

it('serves the image to its owner', function () {
    $generation = storedImage();

    $response = $this->actingAs($generation->user)->get(route('generations.image', $generation));

    $response->assertOk()->assertHeader('Content-Type', 'image/png');
    expect($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->streamedContent())->toBe('PNGDATA');
});

it('hides the image from other users', function () {
    $generation = storedImage();

    $this->actingAs(User::factory()->create())->get(route('generations.image', $generation))->assertNotFound();
});

it('requires sign in', function () {
    $this->get(route('generations.image', storedImage()))->assertRedirect(route('login'));
});

it('points image_src at the protected route', function () {
    $generation = storedImage();

    expect($generation->image_src)->toBe(route('generations.image', $generation));
});

it('stores downloaded images on the private disk', function () {
    Http::fake(['*' => Http::response('PNGDATA')]);
    $generation = Generation::factory()->succeeded()->create(['image_url' => 'https://pixelforge.promptedit.dev/images/x.png']);

    (new StoreGenerationImage($generation))->handle();

    Storage::disk('local')->assertExists("generations/{$generation->uuid}.png");
    Storage::disk('public')->assertMissing("generations/{$generation->uuid}.png");
});

it('moves existing public images to the private disk', function () {
    $generation = Generation::factory()->succeeded()->create(['image_path' => 'generations/old.png']);
    Storage::disk('public')->put('generations/old.png', 'PNGDATA');

    $this->artisan('generations:privatize-images')->assertSuccessful();
    $this->artisan('generations:privatize-images')->assertSuccessful();

    Storage::disk('public')->assertMissing('generations/old.png');
    expect(Storage::disk('local')->get('generations/old.png'))->toBe('PNGDATA');
});

it('sends the image as an attachment when downloading', function () {
    $generation = storedImage();

    $this->actingAs($generation->user)
        ->get(route('generations.image', ['generation' => $generation, 'download' => 1]))
        ->assertOk()
        ->assertDownload("pixelforge-{$generation->id}.png");
});

it('exposes a download url only once the image is stored', function () {
    $stored = storedImage();
    $pending = Generation::factory()->for($stored->user)->processing()->create();

    $this->actingAs($stored->user)->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('generations.data.0.id', $pending->id)
            ->where('generations.data.0.image_download_url', null)
            ->where('generations.data.1.image_download_url', route('generations.image', ['generation' => $stored, 'download' => 1])));
});
