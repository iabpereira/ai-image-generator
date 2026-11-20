<?php

namespace App\Services\Pricing;

use App\Models\MarkupChange;
use App\Models\User;

/**
 * The markup users pay on top of Pixelforge's cost, in basis points (2500 = 25%).
 * The latest MarkupChange wins; before any change, the configured default applies.
 */
final class Markup
{
    public function currentBasisPoints(): int
    {
        return MarkupChange::query()->latest('id')->value('markup_basis_points')
            ?? (int) config('services.pixelforge.markup_percent') * 100;
    }

    public function change(User $admin, int $basisPoints): MarkupChange
    {
        return MarkupChange::query()->create([
            'markup_basis_points' => $basisPoints,
            'previous_basis_points' => $this->currentBasisPoints(),
            'changed_by' => $admin->id,
        ]);
    }
}
