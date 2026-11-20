<?php

use App\Models\Generation;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Services\Credits\CreditStatement;
use App\Support\Money;

function heldFor(User $user, int $maximumNanos): Generation
{
    $generation = Generation::factory()->for($user)->create(['maximum_charge' => Money::fromNanos($maximumNanos)]);
    app(CreditLedger::class)->hold($generation);

    return $generation;
}

it('folds each generation into a single statement entry', function () {
    $user = User::factory()->create();
    $ledger = app(CreditLedger::class);
    $ledger->purchase($user, Money::fromDollars(1));

    $charged = heldFor($user, 21_800_000);
    Generation::query()->whereKey($charged->id)->update(['charged_amount' => 18_000_000]);
    $ledger->settle($charged->refresh());

    $refunded = heldFor($user, 50_000_000);
    $ledger->release($refunded);

    $pending = heldFor($user, 89_700_000);

    $entries = app(CreditStatement::class)->forUser($user)->items();

    expect($entries)->toHaveCount(4)
        ->and($entries[0])->toMatchArray([
            'kind' => 'generation', 'generation_id' => $pending->id, 'status' => 'pending',
            'amount' => '-0.089700000', 'reserved' => '0.089700000', 'charged' => null,
            'balance_after' => '0.892300000',
        ])
        ->and($entries[1])->toMatchArray([
            'generation_id' => $refunded->id, 'status' => 'refunded', 'amount' => '0.000000000',
            'returned' => '0.050000000', 'balance_after' => '0.982000000',
        ])
        ->and($entries[2])->toMatchArray([
            'generation_id' => $charged->id, 'status' => 'charged', 'amount' => '-0.018000000',
            'reserved' => '0.021800000', 'charged' => '0.018000000', 'returned' => '0.021800000',
            'balance_after' => '0.982000000',
        ])
        ->and($entries[3])->toMatchArray(['kind' => 'purchase', 'amount' => '1.000000000', 'balance_after' => '1.000000000']);
});

it('orders entries by their latest activity', function () {
    $user = User::factory()->create();
    $ledger = app(CreditLedger::class);
    $ledger->purchase($user, Money::fromDollars(1));
    $first = heldFor($user, 10_000_000);
    $second = heldFor($user, 10_000_000);

    Generation::query()->whereKey($first->id)->update(['charged_amount' => 8_000_000]);
    $ledger->settle($first->refresh());

    $entries = app(CreditStatement::class)->forUser($user)->items();

    expect(array_column($entries, 'generation_id'))->toBe([$first->id, $second->id, null])
        ->and($entries[0]['balance_after'])->toBe('0.982000000');
});
