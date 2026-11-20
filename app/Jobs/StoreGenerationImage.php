<?php

namespace App\Jobs;

use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Pixelforge image URLs expire 24 h after completion, so keep our own copy.
 */
class StoreGenerationImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public Generation $generation) {}

    public function handle(): void
    {
        $generation = $this->generation->fresh();

        if ($generation === null || $generation->image_path !== null || $generation->image_url === null) {
            return;
        }

        $image = Http::timeout(30)->get($generation->image_url)->throw()->body();
        $path = "generations/{$generation->uuid}.png";

        Storage::disk('public')->put($path, $image);
        $generation->update(['image_path' => $path]);
    }
}
