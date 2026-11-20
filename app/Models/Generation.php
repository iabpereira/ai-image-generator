<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Status, provider id, provider cost and charge are deliberately not fillable: only
 * GenerationLifecycle writes them, through compare-and-set updates. New generations
 * start as pending. The estimates are fillable because they are set once, at creation.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string|null $provider_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property GenerationSize $size
 * @property GenerationQuality $quality
 * @property list<string> $reference_images
 * @property Money $estimated_charge
 * @property Money $maximum_charge
 * @property Money|null $provider_cost
 * @property Money|null $charged_amount
 * @property array<string, string>|null $rates
 * @property array<string, int>|null $usage
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_url_expires_at
 * @property string|null $image_path
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int $submission_attempts
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read string|null $image_src
 */
#[Fillable([
    'uuid', 'prompt', 'size', 'quality', 'reference_images',
    'estimated_charge', 'maximum_charge', 'image_path', 'submission_attempts',
])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    /**
     * Rows with no status change for this long are not really in flight at Pixelforge
     * (it finishes within minutes); counting them would block new submissions forever.
     */
    public const int STALLED_AFTER_MINUTES = 10;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'size' => GenerationSize::class,
            'quality' => GenerationQuality::class,
            'reference_images' => 'array',
            'estimated_charge' => MoneyCast::class,
            'maximum_charge' => MoneyCast::class,
            'provider_cost' => MoneyCast::class,
            'charged_amount' => MoneyCast::class,
            'rates' => 'array',
            'usage' => 'array',
            'image_url_expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Generations Pixelforge counts against its concurrency limit.
     *
     * @param  Builder<Generation>  $query
     */
    #[Scope]
    protected function occupyingProviderSlot(Builder $query): void
    {
        $query->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->where('updated_at', '>=', now()->subMinutes(self::STALLED_AFTER_MINUTES));
    }

    /**
     * The exact body for POST /v1/generations (unknown keys are rejected there).
     *
     * @return array{prompt: string, size: string, quality: string, reference_images: list<string>, metadata: array{generation_uuid: string}}
     */
    public function toProviderPayload(): array
    {
        return [
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'reference_images' => $this->reference_images,
            'metadata' => ['generation_uuid' => $this->uuid],
        ];
    }

    /**
     * Our stored copy (served to the owner by GenerationImageController) wins;
     * otherwise Pixelforge's signed URL while it is still valid.
     *
     * @return Attribute<string|null, never>
     */
    protected function imageSrc(): Attribute
    {
        return Attribute::get(function (): ?string {
            if ($this->image_path !== null) {
                return route('generations.image', $this);
            }

            if ($this->image_url !== null && $this->image_url_expires_at?->isFuture()) {
                return $this->image_url;
            }

            return null;
        });
    }
}
