<?php

namespace App\Services\Generations;

use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Services\Credits\CreditLedger;
use App\Services\Pixelforge\CostCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single writer of generation status and money.
 *
 * Every transition is an atomic compare-and-set: the UPDATE only matches rows whose
 * current status may legally move to the target. Duplicate or stale events match
 * zero rows and change nothing, so a generation is charged at most once.
 */
final readonly class GenerationLifecycle
{
    /**
     * Local failures where Pixelforge may still have accepted the request (it timed
     * out or kept failing). If Pixelforge later reports the generation, its word wins.
     */
    private const array UNCERTAIN_SUBMISSION_FAILURES = ['submission_failed', 'submission_timeout'];

    public function __construct(private CostCalculator $costs, private CreditLedger $credits) {}

    /**
     * Move a generation to the state described by a Pixelforge generation object.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function apply(Generation $generation, array $snapshot): bool
    {
        $target = GenerationStatus::tryFrom((string) ($snapshot['status'] ?? ''));

        if ($target === null || $target === GenerationStatus::Pending || ! $this->isUsable($snapshot, $target)) {
            Log::warning('Ignoring an unusable Pixelforge generation snapshot.', [
                'generation_id' => $generation->id,
                'status' => $snapshot['status'] ?? null,
            ]);

            return false;
        }

        return $this->transition(
            $generation,
            $target,
            $this->attributesFor($snapshot, $target),
            allowRecovery: true,
        );
    }

    /**
     * Terminal failure decided on our side for a generation Pixelforge is tracking.
     */
    public function fail(Generation $generation, string $errorCode, string $message): bool
    {
        return $this->transition($generation, GenerationStatus::Failed, $this->localFailure($errorCode, $message));
    }

    /**
     * Terminal failure of the submission itself. Only applies while Pixelforge has not
     * accepted the generation, so a late failure never overrides an accepted one.
     */
    public function failSubmission(Generation $generation, string $errorCode, string $message): bool
    {
        return DB::transaction(function () use ($generation, $errorCode, $message): bool {
            $updated = Generation::query()
                ->whereKey($generation->getKey())
                ->where('status', GenerationStatus::Pending->value)
                ->whereNull('provider_id')
                ->update(['status' => GenerationStatus::Failed->value, ...$this->localFailure($errorCode, $message)]);

            $generation->refresh();

            if ($updated === 1) {
                $this->afterTransition($generation, GenerationStatus::Failed);
            }

            return $updated === 1;
        });
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function isUsable(array $snapshot, GenerationStatus $target): bool
    {
        if (! is_string($snapshot['id'] ?? null)) {
            return false;
        }

        return $target !== GenerationStatus::Succeeded
            || (is_array($snapshot['usage'] ?? null) && is_array($snapshot['rates'] ?? null));
    }

    /**
     * Columns to write for the target status. In-flight statuses clear cost and error
     * columns that a recovered local submission failure may have left behind.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function attributesFor(array $snapshot, GenerationStatus $target): array
    {
        $attributes = array_filter([
            'provider_id' => $snapshot['id'],
            'rates' => isset($snapshot['rates']) ? json_encode($snapshot['rates']) : null,
            'started_at' => $this->timestamp($snapshot['started_at'] ?? null),
            'completed_at' => $this->timestamp($snapshot['completed_at'] ?? null),
        ], fn (mixed $value): bool => $value !== null);

        return match ($target) {
            GenerationStatus::Succeeded => [...$attributes, ...$this->successAttributes($snapshot)],
            GenerationStatus::Failed => [
                ...$attributes,
                'provider_cost' => 0,
                'charged_amount' => 0,
                'error_code' => $snapshot['error']['code'] ?? 'generation_failed',
                'error_message' => $snapshot['error']['message'] ?? 'The generation failed. You were not charged.',
            ],
            default => [...$attributes, 'provider_cost' => null, 'charged_amount' => null, 'error_code' => null, 'error_message' => null],
        };
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function successAttributes(array $snapshot): array
    {
        $providerCost = $this->costs->providerCost($snapshot['usage'], $snapshot['rates']);

        return [
            'usage' => json_encode($snapshot['usage']),
            'provider_cost' => $providerCost->nanos,
            'charged_amount' => $this->costs->charge($providerCost)->nanos,
            'image_url' => $snapshot['image_url'] ?? null,
            'image_url_expires_at' => $this->timestamp($snapshot['image_url_expires_at'] ?? null),
            'error_code' => null,
            'error_message' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function localFailure(string $errorCode, string $message): array
    {
        return [
            'provider_cost' => 0,
            'charged_amount' => 0,
            'error_code' => $errorCode,
            'error_message' => $message,
            'completed_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(Generation $generation, GenerationStatus $target, array $attributes, bool $allowRecovery = false): bool
    {
        return DB::transaction(function () use ($generation, $target, $attributes, $allowRecovery): bool {
            $updated = Generation::query()
                ->whereKey($generation->getKey())
                ->where(function (Builder $query) use ($target, $allowRecovery): void {
                    $query->whereIn('status', array_map(
                        fn (GenerationStatus $status): string => $status->value,
                        GenerationStatus::predecessorsOf($target),
                    ));

                    if ($allowRecovery) {
                        $query->orWhere(fn (Builder $recoverable) => $recoverable
                            ->where('status', GenerationStatus::Failed->value)
                            ->whereNull('provider_id')
                            ->whereIn('error_code', self::UNCERTAIN_SUBMISSION_FAILURES));
                    }
                })
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
            $this->credits->settle($generation);
            StoreGenerationImage::dispatch($generation)->afterCommit();
        }

        if ($target === GenerationStatus::Failed) {
            $this->credits->release($generation);
        }
    }

    private function timestamp(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value);
    }
}
