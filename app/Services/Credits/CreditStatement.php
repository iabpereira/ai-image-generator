<?php

namespace App\Services\Credits;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The user-facing statement: one entry per purchase and one per generation, built
 * from the append-only ledger. A generation's hold, release and charge lines are
 * folded into a single entry; the ledger itself keeps every line for auditing.
 *
 * kind is 'purchase' or 'generation'; status is 'pending', 'charged', 'refunded' or null.
 *
 * @phpstan-type StatementEntry array{key: string, kind: string, generation_id: int|null, status: string|null, amount: string, reserved: string|null, charged: string|null, returned: string|null, balance_after: string, created_at: string}
 */
final class CreditStatement
{
    /**
     * @return LengthAwarePaginator<int, StatementEntry>
     */
    public function forUser(User $user, int $perPage = 20): LengthAwarePaginator
    {
        $anchors = $user->creditTransactions()
            ->whereIn('type', [CreditTransactionType::Purchase, CreditTransactionType::Hold])
            ->latest('id')
            ->paginate($perPage);

        $followUps = CreditTransaction::query()
            ->whereIn('generation_id', $anchors->getCollection()->pluck('generation_id')->filter())
            ->whereIn('type', [CreditTransactionType::Release, CreditTransactionType::Charge])
            ->get();

        return $anchors->through(fn (CreditTransaction $anchor) => $this->entryFor($anchor, $followUps));
    }

    /**
     * @param  EloquentCollection<int, CreditTransaction>  $followUps
     * @return StatementEntry
     */
    private function entryFor(CreditTransaction $anchor, EloquentCollection $followUps): array
    {
        return $anchor->type === CreditTransactionType::Purchase
            ? $this->purchaseEntry($anchor)
            : $this->generationEntry($anchor, $followUps->where('generation_id', $anchor->generation_id));
    }

    /**
     * @return StatementEntry
     */
    private function purchaseEntry(CreditTransaction $purchase): array
    {
        return [
            'key' => "purchase-{$purchase->id}",
            'kind' => 'purchase',
            'generation_id' => null,
            'status' => null,
            'amount' => $purchase->amount->toDecimalString(),
            'reserved' => null,
            'charged' => null,
            'returned' => null,
            'balance_after' => $purchase->balance_after->toDecimalString(),
            'created_at' => $purchase->created_at->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, CreditTransaction>  $followUps
     * @return StatementEntry
     */
    private function generationEntry(CreditTransaction $hold, Collection $followUps): array
    {
        $charge = $followUps->firstWhere('type', CreditTransactionType::Charge);
        $release = $followUps->firstWhere('type', CreditTransactionType::Release);
        $latest = $followUps->push($hold)->sortByDesc('id')->first() ?? $hold;
        $reserved = Money::zero()->minus($hold->amount);
        $charged = $charge === null ? null : Money::zero()->minus($charge->amount);

        $status = match (true) {
            $charge !== null => 'charged',
            $release !== null => 'refunded',
            default => 'pending',
        };

        $net = match ($status) {
            'charged' => $charge->amount,
            'refunded' => Money::zero(),
            'pending' => $hold->amount,
        };

        return [
            'key' => "generation-{$hold->generation_id}",
            'kind' => 'generation',
            'generation_id' => $hold->generation_id,
            'status' => $status,
            'amount' => $net->toDecimalString(),
            'reserved' => $reserved->toDecimalString(),
            'charged' => $charged?->toDecimalString(),
            'returned' => $release === null ? null : $release->amount->toDecimalString(),
            'balance_after' => $latest->balance_after->toDecimalString(),
            'created_at' => $latest->created_at->toIso8601String(),
        ];
    }
}
