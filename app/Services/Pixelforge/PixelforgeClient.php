<?php

namespace App\Services\Pixelforge;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final readonly class PixelforgeClient
{
    public function __construct(private string $baseUrl, private string $apiKey) {}

    /**
     * @param  array{prompt: string, size?: string, quality?: string, reference_images?: list<string>, metadata?: array<string, string>}  $payload
     * @return array<string, mixed>
     */
    public function createGeneration(array $payload, string $idempotencyKey): array
    {
        return $this->send(fn (): Response => $this->authenticated()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/generations', $payload));
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieveGeneration(string $providerId): array
    {
        return $this->send(fn (): Response => $this->authenticated()->get("/generations/{$providerId}"));
    }

    /**
     * Fresh for an hour, served stale for up to a day if Pixelforge is unreachable.
     */
    public function pricing(): Pricing
    {
        /** @var array{rates: array<string, string>, tokens: array{characters_per_text_token: int, text_token_variance: float, per_reference_image: int, output: array<string, array<string, int>>, reasoning: array<string, array{min: int, max: int}>}} $payload */
        $payload = Cache::flexible('pixelforge.pricing', [3600, 86400], fn (): array => $this->send(
            fn (): Response => $this->baseRequest()->get('/pricing'),
        ));

        return Pricing::fromApi($payload);
    }

    /**
     * The price list, or null when Pixelforge can't be reached and nothing is cached.
     * The failure is reported so it shows up in the logs.
     */
    public function pricingOrNull(): ?Pricing
    {
        try {
            return $this->pricing();
        } catch (PixelforgeException $exception) {
            report($exception);

            return null;
        }
    }

    private function baseRequest(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)->acceptJson()->asJson()->connectTimeout(5)->timeout(15);
    }

    private function authenticated(): PendingRequest
    {
        return $this->baseRequest()->withToken($this->apiKey);
    }

    /**
     * @param  Closure(): Response  $request
     * @return array<string, mixed>
     */
    private function send(Closure $request): array
    {
        try {
            $response = $request();
        } catch (ConnectionException $exception) {
            throw PixelforgeException::connectionFailed($exception);
        }

        if ($response->failed()) {
            throw PixelforgeException::fromResponse($response);
        }

        return $response->json();
    }
}
