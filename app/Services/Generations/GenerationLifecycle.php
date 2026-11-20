<?php

namespace App\Services\Generations;

use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Services\Pixelforge\CostCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of generation status and money.
 *
 * Every transition is an atomic compare-and-set: the UPDATE only matches rows whose
 * current status may legally move to the target. Duplicate or stale events match
 * zero rows and change nothing, so a generation is charged at most once.
 */
final readonly class GenerationLifecycle
{
    public function __construct(private CostCalculator $costs) {}

    /**
     * Move a generation to the state described by a Pixelforge generation object.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function apply(Generation $generation, array $snapshot): bool
    {
        $target = GenerationStatus::from($snapshot['status']);

        $attributes = [
            'provider_id' => $snapshot['id'],
            'rates' => isset($snapshot['rates']) ? json_encode($snapshot['rates']) : null,
            'started_at' => $this->timestamp($snapshot['started_at'] ?? null),
            'completed_at' => $this->timestamp($snapshot['completed_at'] ?? null),
        ];

        if ($target === GenerationStatus::Succeeded) {
            $providerCost = $this->costs->providerCost($snapshot['usage'], $snapshot['rates']);

            $attributes += [
                'usage' => json_encode($snapshot['usage']),
                'provider_cost' => $providerCost->nanos,
                'charged_amount' => $this->costs->charge($providerCost)->nanos,
                'image_url' => $snapshot['image_url'] ?? null,
                'image_url_expires_at' => $this->timestamp($snapshot['image_url_expires_at'] ?? null),
            ];
        }

        if ($target === GenerationStatus::Failed) {
            $attributes += [
                'provider_cost' => 0,
                'charged_amount' => 0,
                'error_code' => $snapshot['error']['code'] ?? 'generation_failed',
                'error_message' => $snapshot['error']['message'] ?? 'The generation failed. You were not charged.',
            ];
        }

        return $this->transition(
            $generation,
            $target,
            array_filter($attributes, fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * Terminal failure decided on our side (e.g. Pixelforge rejected the submission).
     */
    public function fail(Generation $generation, string $errorCode, string $message): bool
    {
        return $this->transition($generation, GenerationStatus::Failed, [
            'provider_cost' => 0,
            'charged_amount' => 0,
            'error_code' => $errorCode,
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(Generation $generation, GenerationStatus $target, array $attributes): bool
    {
        return DB::transaction(function () use ($generation, $target, $attributes): bool {
            $updated = Generation::query()
                ->whereKey($generation->getKey())
                ->whereIn('status', array_map(
                    fn (GenerationStatus $status): string => $status->value,
                    GenerationStatus::predecessorsOf($target),
                ))
                ->update(['status' => $target->value, ...$attributes]);

            if ($updated === 0) {
                return false;
            }

            $generation->refresh();
            $this->afterTransition($generation, $target);

            return true;
        });
    }

    /**
     * Side effects of a transition that won the compare-and-set. Runs inside the
     * same transaction, so it happens exactly once per transition.
     */
    private function afterTransition(Generation $generation, GenerationStatus $target): void
    {
        if ($target === GenerationStatus::Succeeded) {
            StoreGenerationImage::dispatch($generation)->afterCommit();
        }
    }

    private function timestamp(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value);
    }
}
