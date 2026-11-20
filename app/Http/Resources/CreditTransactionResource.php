<?php

namespace App\Http\Resources;

use App\Models\CreditTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CreditTransaction
 */
class CreditTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'amount' => $this->amount->toDecimalString(),
            'balance_after' => $this->balance_after->toDecimalString(),
            'generation_id' => $this->generation_id,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
