<?php

use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Support\Money;
use Inertia\Testing\AssertableInertia as Assert;

it('requires a verified user', function () {
    $this->get(route('credits.index'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create())
        ->post(route('credits.store'), ['pack' => 5])
        ->assertRedirect(route('verification.notice'));
});

it('buys a credit pack', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('credits.store'), ['pack' => 5])->assertRedirect(route('credits.index'));

    expect($user->refresh()->credit_balance->toDecimalString())->toBe('5.000000000');
});

it('rejects unknown packs', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('credits.store'), ['pack' => 1000])->assertSessionHasErrors('pack');

    expect($user->refresh()->credit_balance->nanos)->toBe(0);
});

it('shows the balance, packs and statement', function () {
    $user = User::factory()->create();
    app(CreditLedger::class)->purchase($user, Money::fromDollars(1));

    $this->actingAs($user->refresh())->get(route('credits.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('credits/Index')
            ->where('auth.user.credit_balance', '1.000000000')
            ->where('packs', [1, 5, 20])
            ->where('statement.data.0.kind', 'purchase')
            ->where('statement.data.0.amount', '1.000000000')
            ->where('statement.meta.last_page', 1)
            ->where('statement.links.prev', null));
});
