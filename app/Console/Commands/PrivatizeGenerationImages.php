<?php

namespace App\Console\Commands;

use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('generations:privatize-images')]
#[Description('Move stored generation images from the public disk to the private disk')]
class PrivatizeGenerationImages extends Command
{
    /**
     * Idempotent: images already on the private disk are skipped.
     */
    public function handle(): int
    {
        $public = Storage::disk('public');
        $private = Storage::disk(StoreGenerationImage::DISK);
        $moved = 0;

        Generation::query()->whereNotNull('image_path')->each(function (Generation $generation) use ($public, $private, &$moved): void {
            $path = (string) $generation->image_path;

            if (! $public->exists($path)) {
                return;
            }

            if (! $private->exists($path)) {
                $private->put($path, (string) $public->get($path));
            }

            $public->delete($path);
            $moved++;
        });

        $this->info("Moved {$moved} image(s) to the private disk.");

        return self::SUCCESS;
    }
}
