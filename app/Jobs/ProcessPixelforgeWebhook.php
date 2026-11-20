<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Models\PixelforgeWebhookEvent;
use App\Services\Generations\GenerationLifecycle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Applies the generation snapshot carried by a webhook. The lifecycle decides by
 * status, so duplicate and out-of-order events are harmless.
 */
class ProcessPixelforgeWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    private const array GENERATION_EVENTS = ['generation.processing', 'generation.succeeded', 'generation.failed'];

    public function __construct(public PixelforgeWebhookEvent $event) {}

    public function handle(GenerationLifecycle $lifecycle): void
    {
        $snapshot = $this->event->payload['data']['object'] ?? null;

        if (in_array($this->event->type, self::GENERATION_EVENTS, true) && is_array($snapshot)) {
            $generation = $this->findGeneration($snapshot);

            if ($generation === null) {
                Log::warning('Pixelforge webhook for an unknown generation.', ['event_id' => $this->event->event_id]);
            } else {
                $lifecycle->apply($generation, $snapshot);
            }
        }

        $this->event->update(['processed_at' => now()]);
    }

    /**
     * By provider id, or by our uuid in metadata when the event beat the POST response.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function findGeneration(array $snapshot): ?Generation
    {
        return Generation::query()->where('provider_id', $snapshot['id'] ?? '')->first()
            ?? Generation::query()->where('uuid', $snapshot['metadata']['generation_uuid'] ?? '')->first();
    }
}
