<?php

namespace App\Services\Credits;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Append-only credit ledger. users.credit_balance caches SUM(amount) and is always
 * written in the same transaction as the ledger line.
 *
 * Lifecycle of a generation: hold the worst-case price on submit; when it is final,
 * release the hold and, only if it succeeded, charge the actual price. The unique
 * (generation_id, type) index makes each of those happen at most once.
 */
final class CreditLedger
{
    public function purchase(User $user, Money $amount): void
    {
        DB::transaction(fn () => $this->record($user->id, null, CreditTransactionType::Purchase, $amount));
    }

    /**
     * Reserve the generation's maximum possible charge.
     *
     * @throws InsufficientCredits
     */
    public function hold(Generation $generation): void
    {
        DB::transaction(function () use ($generation): void {
            $required = $generation->maximum_charge;
            $available = $this->balanceOf($generation->user_id);

            if ($required->isGreaterThan($available)) {
                throw new InsufficientCredits($required, $available);
            }

            $this->record($generation->user_id, $generation->id, CreditTransactionType::Hold, Money::zero()->minus($required));
        });
    }

    /**
     * Succeeded: give the hold back and charge the actual price. Generations created
     * before balances existed have no hold and are not debited retroactively.
     */
    public function settle(Generation $generation): void
    {
        DB::transaction(function () use ($generation): void {
            if ($this->holdFor($generation) === null) {
                return;
            }

            $this->release($generation);
            $this->record(
                $generation->user_id,
                $generation->id,
                CreditTransactionType::Charge,
                Money::zero()->minus($generation->charged_amount ?? Money::zero()),
            );
        });
    }

    /**
     * Failed: give the hold back. Nothing is charged.
     */
    public function release(Generation $generation): void
    {
        DB::transaction(function () use ($generation): void {
            $hold = $this->holdFor($generation);

            if ($hold !== null) {
                $this->record($generation->user_id, $generation->id, CreditTransactionType::Release, Money::zero()->minus($hold->amount));
            }
        });
    }

    private function holdFor(Generation $generation): ?CreditTransaction
    {
        return CreditTransaction::query()
            ->where('generation_id', $generation->id)
            ->where('type', CreditTransactionType::Hold)
            ->first();
    }

    /**
     * Insert the ledger line first: for generation lines, the unique index turns a
     * repeated call into a no-op before the balance is touched. SQLite keeps the
     * surrounding transaction usable after the unique violation; on Postgres this
     * would need insertOrIgnore instead.
     */
    private function record(int $userId, ?int $generationId, CreditTransactionType $type, Money $amount): void
    {
        $attributes = ['user_id' => $userId, 'amount' => $amount, 'balance_after' => Money::zero()];

        $transaction = $generationId === null
            ? CreditTransaction::query()->create(['type' => $type, ...$attributes])
            : CreditTransaction::query()->createOrFirst(['generation_id' => $generationId, 'type' => $type], $attributes);

        if (! $transaction->wasRecentlyCreated) {
            return;
        }

        User::query()->whereKey($userId)->increment('credit_balance', $amount->nanos);

        $transaction->update(['balance_after' => $this->balanceOf($userId)]);
    }

    private function balanceOf(int $userId): Money
    {
        return Money::fromNanos((int) User::query()->toBase()->where('id', $userId)->value('credit_balance'));
    }
}
