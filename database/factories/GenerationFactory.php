<?php

namespace Database\Factories;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'user_id' => User::factory(),
            'status' => GenerationStatus::Pending,
            'prompt' => fake()->sentence(),
            'size' => GenerationSize::Square,
            'quality' => GenerationQuality::Medium,
            'reference_images' => [],
            'estimated_charge' => Money::fromNanos(72_812_500),
            'maximum_charge' => Money::fromNanos(130_000_000),
        ];
    }

    public function queued(): static
    {
        return $this->state(fn (): array => [
            'status' => GenerationStatus::Queued,
            'provider_id' => 'gen_'.Str::ulid(),
        ]);
    }

    public function processing(): static
    {
        return $this->queued()->state(fn (): array => ['status' => GenerationStatus::Processing]);
    }

    public function succeeded(): static
    {
        return $this->queued()->state(fn (): array => [
            'status' => GenerationStatus::Succeeded,
            'provider_cost' => Money::fromNanos(305_510_000),
            'charged_amount' => Money::fromNanos(381_887_500),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->queued()->state(fn (): array => [
            'status' => GenerationStatus::Failed,
            'provider_cost' => Money::zero(),
            'charged_amount' => Money::zero(),
            'error_code' => 'generation_failed',
            'error_message' => 'The model could not produce an image.',
            'completed_at' => now(),
        ]);
    }
}
