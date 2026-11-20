<?php

use App\Enums\CreditTransactionType;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Services\Credits\InsufficientCredits;
use App\Support\Money;

function ledger(): CreditLedger
{
    return app(CreditLedger::class);
}

function chargedAs(Generation $generation, int $nanos): Generation
{
    Generation::query()->whereKey($generation->id)->update(['charged_amount' => $nanos]);

    return $generation->refresh();
}

it('adds purchased credits', function () {
    $user = User::factory()->create();

    ledger()->purchase($user, Money::fromDollars(5));

    expect($user->refresh()->credit_balance->toDecimalString())->toBe('5.000000000')
        ->and($user->creditTransactions()->sole())
        ->type->toBe(CreditTransactionType::Purchase)
        ->balance_after->nanos->toBe(5_000_000_000);
});

it('holds the maximum charge and refuses when the balance is too low', function () {
    $user = User::factory()->create();
    ledger()->purchase($user, Money::fromNanos(100_000_000));
    $generation = Generation::factory()->for($user)->create(['maximum_charge' => Money::fromNanos(130_000_000)]);

    expect(fn () => ledger()->hold($generation))->toThrow(InsufficientCredits::class);
    expect($user->refresh()->credit_balance->nanos)->toBe(100_000_000);
});

it('settles to the actual charge exactly once', function () {
    $user = User::factory()->create();
    ledger()->purchase($user, Money::fromDollars(1));
    $generation = Generation::factory()->for($user)->create(['maximum_charge' => Money::fromNanos(467_343_750)]);
    ledger()->hold($generation);

    $generation = chargedAs($generation, 381_887_500);
    ledger()->settle($generation);
    ledger()->settle($generation);

    expect($user->refresh()->credit_balance->nanos)->toBe(1_000_000_000 - 381_887_500)
        ->and($user->creditTransactions()->pluck('type')->map->value->all())
        ->toBe(['purchase', 'hold', 'release', 'charge']);
});

it('releases the hold once on failure', function () {
    $user = User::factory()->create();
    ledger()->purchase($user, Money::fromDollars(1));
    $generation = Generation::factory()->for($user)->create(['maximum_charge' => Money::fromNanos(467_343_750)]);
    ledger()->hold($generation);

    ledger()->release($generation);
    ledger()->release($generation);

    expect($user->refresh()->credit_balance->nanos)->toBe(1_000_000_000);
});

it('does not debit generations created before balances existed', function () {
    $user = User::factory()->create();
    $generation = chargedAs(Generation::factory()->for($user)->create(), 381_887_500);

    ledger()->settle($generation);

    expect($user->refresh()->credit_balance->nanos)->toBe(0)
        ->and($user->creditTransactions()->count())->toBe(0);
});

it('keeps the cached balance equal to the ledger sum', function () {
    $user = User::factory()->create();
    ledger()->purchase($user, Money::fromDollars(2));
    $generation = Generation::factory()->for($user)->create(['maximum_charge' => Money::fromNanos(300_000_000)]);
    ledger()->hold($generation);
    ledger()->settle(chargedAs($generation, 250_000_000));

    expect($user->refresh()->credit_balance->nanos)->toBe((int) $user->creditTransactions()->sum('amount'));
});
