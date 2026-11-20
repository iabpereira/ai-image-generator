<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Http\Resources\GenerationResource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GalleryController extends Controller
{
    /**
     * The user's finished images.
     */
    public function __invoke(Request $request): Response
    {
        return Inertia::render('generations/Gallery', [
            'generations' => GenerationResource::collection(
                $request->user()->generations()
                    ->where('status', GenerationStatus::Succeeded)
                    ->latest('completed_at')
                    ->paginate(24),
            ),
        ]);
    }
}
