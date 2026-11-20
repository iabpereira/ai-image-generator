<?php

namespace App\Http\Controllers;

use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GenerationImageController extends Controller
{
    /**
     * Serve a stored image to its owner only. Anyone else gets a 404, so we don't
     * even confirm the image exists. With ?download=1 it is sent as an attachment.
     */
    public function __invoke(Request $request, Generation $generation): StreamedResponse
    {
        abort_unless($request->user()->can('view', $generation), 404);

        $disk = Storage::disk(StoreGenerationImage::DISK);

        abort_if($generation->image_path === null || ! $disk->exists($generation->image_path), 404);

        $headers = ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=86400'];

        return $request->boolean('download')
            ? $disk->download($generation->image_path, "pixelforge-{$generation->id}.png", $headers)
            : $disk->response($generation->image_path, null, $headers);
    }
}
