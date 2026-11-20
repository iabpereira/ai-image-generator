<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseCreditsRequest;
use App\Models\CreditTransaction;
use App\Services\Credits\CreditLedger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    /** @var list<int> Fake purchase packs, in whole US dollars. */
    public const array PACKS = [1, 5, 20];

    /**
     * The balance, the packs to buy and the ledger statement.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('credits/Index', [
            'packs' => self::PACKS,
            'transactions' => $request->user()->creditTransactions()->latest('id')->paginate(20)->through(
                fn (CreditTransaction $transaction): array => [
                    'id' => $transaction->id,
                    'type' => $transaction->type->value,
                    'amount' => $transaction->amount->toDecimalString(),
                    'balance_after' => $transaction->balance_after->toDecimalString(),
                    'generation_id' => $transaction->generation_id,
                    'created_at' => $transaction->created_at->toIso8601String(),
                ],
            ),
        ]);
    }

    /**
     * A fake purchase: no payment is taken, the pack is credited immediately.
     */
    public function store(PurchaseCreditsRequest $request, CreditLedger $ledger): RedirectResponse
    {
        $ledger->purchase($request->user(), Money::fromDollars((int) $request->validated('pack')));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Credits added.')]);

        return to_route('credits.index');
    }
}
