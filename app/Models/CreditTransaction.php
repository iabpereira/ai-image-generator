<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\CreditTransactionType;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only line of a user's credit ledger. Written only by CreditLedger.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $generation_id
 * @property CreditTransactionType $type
 * @property Money $amount
 * @property Money $balance_after
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['user_id', 'generation_id', 'type', 'amount', 'balance_after'])]
class CreditTransaction extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CreditTransactionType::class,
            'amount' => MoneyCast::class,
            'balance_after' => MoneyCast::class,
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
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
