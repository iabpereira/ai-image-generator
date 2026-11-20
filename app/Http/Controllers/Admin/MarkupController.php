<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateMarkupRequest;
use App\Models\MarkupChange;
use App\Services\Pricing\Markup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MarkupController extends Controller
{
    /**
     * The markup in force and who changed it, when.
     */
    public function index(Markup $markup): Response
    {
        Gate::authorize('managePricing');

        return Inertia::render('admin/Markup', [
            'markupBasisPoints' => $markup->currentBasisPoints(),
            'history' => MarkupChange::query()->with('changedBy:id,name,email')->latest('id')->paginate(20)->through(
                fn (MarkupChange $change): array => [
                    'id' => $change->id,
                    'markup_basis_points' => $change->markup_basis_points,
                    'previous_basis_points' => $change->previous_basis_points,
                    'changed_by' => $change->changedBy === null ? null : [
                        'name' => $change->changedBy->name,
                        'email' => $change->changedBy->email,
                    ],
                    'created_at' => $change->created_at->toIso8601String(),
                ],
            ),
        ]);
    }

    /**
     * Change the markup for generations created from now on. Generations already
     * created keep the markup they were quoted with.
     */
    public function store(UpdateMarkupRequest $request, Markup $markup): RedirectResponse
    {
        $markup->change($request->user(), $request->basisPoints());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Markup updated.')]);

        return to_route('admin.markup.index');
    }
}
