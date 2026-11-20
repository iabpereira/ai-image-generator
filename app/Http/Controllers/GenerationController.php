<?php

namespace App\Http\Controllers;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Jobs\SubmitGeneration;
use App\Services\Pixelforge\CostCalculator;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Pixelforge\Pricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    /**
     * The dashboard: the generate form with its live estimate, and the user's generations.
     *
     * Pricing is lazy so the status polling, which reloads only the generations, skips it.
     * It is null when Pixelforge's price list can't be loaded; the form then explains why.
     */
    public function index(Request $request, PixelforgeClient $client): Response
    {
        return Inertia::render('Dashboard', [
            'generations' => GenerationResource::collection(
                $request->user()->generations()->latest('id')->paginate(12),
            ),
            'pricing' => fn (): ?array => $this->pricingOrNull($client)?->toArray(),
            'markupPercent' => (int) config('services.pixelforge.markup_percent'),
        ]);
    }

    /**
     * Record the generation with its server-side estimate and queue the submission.
     */
    public function store(StoreGenerationRequest $request, PixelforgeClient $client, CostCalculator $costs): RedirectResponse
    {
        $prompt = $request->validated('prompt');
        $size = GenerationSize::from($request->validated('size'));
        $quality = GenerationQuality::from($request->validated('quality'));
        $referenceImages = $request->referenceImages();

        $pricing = $this->pricingOrNull($client) ?? throw ValidationException::withMessages([
            'pricing' => __('Pricing is temporarily unavailable, so we can\'t quote this generation. Please try again in a moment.'),
        ]);

        $estimate = $costs->estimate($pricing, $prompt, $size, $quality, count($referenceImages));

        $generation = $request->user()->generations()->create([
            'uuid' => (string) Str::uuid7(),
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
            'reference_images' => $referenceImages,
            'estimated_charge' => $estimate->typicalCharge,
            'maximum_charge' => $estimate->maximumCharge,
        ]);

        SubmitGeneration::dispatch($generation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Generation queued.')]);

        return to_route('dashboard');
    }

    private function pricingOrNull(PixelforgeClient $client): ?Pricing
    {
        try {
            return $client->pricing();
        } catch (PixelforgeException $exception) {
            report($exception);

            return null;
        }
    }
}
