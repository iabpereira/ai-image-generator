<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseCreditsRequest;
use App\Services\Credits\CreditLedger;
use App\Services\Credits\CreditStatement;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    /** @var list<int> Fake purchase packs, in whole US dollars. */
    public const array PACKS = [1, 5, 20];

    /**
     * The balance, the packs to buy and the statement (one entry per purchase or image).
     */
    public function index(Request $request, CreditStatement $statement): Response
    {
        return Inertia::render('credits/Index', [
            'packs' => self::PACKS,
            'statement' => JsonResource::collection($statement->forUser($request->user())),
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
