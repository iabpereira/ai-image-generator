<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Generations\GenerationLifecycle;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('generations:sync {--limit=10 : Maximum generations to poll per run}')]
#[Description('Poll Pixelforge for in-flight generations whose webhooks have not arrived')]
class SyncPendingGenerations extends Command
{
    private const int STALE_AFTER_SECONDS = 90;

    /**
     * Each row is isolated: one unusable snapshot or error must not stop the rest.
     */
    public function handle(PixelforgeClient $client, GenerationLifecycle $lifecycle): int
    {
        Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_id')
            ->where('updated_at', '<', now()->subSeconds(self::STALE_AFTER_SECONDS))
            ->oldest('updated_at')
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(function (Generation $generation) use ($client, $lifecycle): void {
                try {
                    $lifecycle->apply($generation, $client->retrieveGeneration((string) $generation->provider_id));
                } catch (PixelforgeException $exception) {
                    $this->handleProviderError($generation, $exception, $lifecycle);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

        return self::SUCCESS;
    }

    private function handleProviderError(Generation $generation, PixelforgeException $exception, GenerationLifecycle $lifecycle): void
    {
        if ($exception->errorCode === 'not_found') {
            $lifecycle->fail($generation, 'not_found', 'Pixelforge no longer has a record of this generation. You were not charged.');

            return;
        }

        $this->warn("{$generation->provider_id}: {$exception->errorCode}");
    }
}
