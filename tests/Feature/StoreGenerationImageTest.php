<?php

use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Http::preventStrayRequests());

it('downloads the image to the private disk', function () {
    Storage::fake('local');
    Http::fake(['pixelforge.promptedit.dev/images/*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png'])]);
    $generation = Generation::factory()->succeeded()->create(['image_url' => 'https://pixelforge.promptedit.dev/images/x.png']);

    (new StoreGenerationImage($generation))->handle();

    $path = "generations/{$generation->uuid}.png";
    Storage::disk('local')->assertExists($path);
    expect($generation->refresh()->image_path)->toBe($path)
        ->and($generation->image_src)->toBe(route('generations.image', $generation));
});

it('skips generations that already have a stored image', function () {
    $generation = Generation::factory()->succeeded()->create(['image_path' => 'generations/x.png']);

    (new StoreGenerationImage($generation))->handle();

    Http::assertNothingSent();
});
