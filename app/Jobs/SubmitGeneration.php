<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Generations\GenerationLifecycle;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

/**
 * Hands a pending generation to Pixelforge. Safe to retry: the generation uuid is
 * the Idempotency-Key, so a repeated POST returns the same generation.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** Unlimited releases, bounded by retryUntil(). */
    public int $tries = 0;

    public int $maxExceptions = 3;

    private const int MAX_TRANSIENT_ATTEMPTS = 5;

    private const int MAX_BACKOFF_SECONDS = 60;

    private const int SLOT_WAIT_SECONDS = 5;

    public function __construct(public Generation $generation) {}

    public function retryUntil(): DateTimeInterface
    {
        return $this->generation->created_at->addMinutes(30);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('pixelforge')];
    }

    public function handle(PixelforgeClient $client, GenerationLifecycle $lifecycle): void
    {
        $generation = $this->generation->fresh();

        if ($generation === null || $generation->status !== GenerationStatus::Pending) {
            return;
        }

        if ($this->providerSlotsAreFull()) {
            $this->release(self::SLOT_WAIT_SECONDS);

            return;
        }

        try {
            $snapshot = $client->createGeneration($generation->toProviderPayload(), $generation->uuid);
        } catch (PixelforgeException $exception) {
            $this->handleRejection($generation, $exception, $lifecycle);

            return;
        }

        Generation::query()
            ->whereKey($generation->id)
            ->whereNull('provider_id')
            ->update(['provider_id' => $snapshot['id'], 'submitted_at' => now()]);

        $lifecycle->apply($generation, $snapshot);
    }

    public function failed(?Throwable $exception): void
    {
        app(GenerationLifecycle::class)->fail(
            $this->generation,
            'submission_timeout',
            'Pixelforge stayed busy for too long, so we stopped trying. You were not charged.',
        );
    }

    private function providerSlotsAreFull(): bool
    {
        return Generation::query()->occupyingProviderSlot()->count()
            >= (int) config('services.pixelforge.max_concurrent_generations');
    }

    private function handleRejection(Generation $generation, PixelforgeException $exception, GenerationLifecycle $lifecycle): void
    {
        if ($exception->isThrottled()) {
            $this->release($exception->retryAfter ?? self::SLOT_WAIT_SECONDS);

            return;
        }

        if (! $exception->isTransient()) {
            $lifecycle->fail($generation, $exception->errorCode, $exception->userMessage());

            return;
        }

        $attempts = $generation->submission_attempts + 1;
        $generation->update(['submission_attempts' => $attempts]);

        if ($attempts >= self::MAX_TRANSIENT_ATTEMPTS) {
            $lifecycle->fail($generation, 'submission_failed', 'Pixelforge is unavailable right now. You were not charged.');

            return;
        }

        $this->release(min(self::MAX_BACKOFF_SECONDS, max($exception->retryAfter ?? 0, 2 ** $attempts)));
    }
}
