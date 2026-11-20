<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change of the markup charged on top of Pixelforge's cost. The latest row is
 * the markup in force; the table is its audit history.
 *
 * @property int $id
 * @property int $markup_basis_points
 * @property int $previous_basis_points
 * @property int|null $changed_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['markup_basis_points', 'previous_basis_points', 'changed_by'])]
class MarkupChange extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'markup_basis_points' => 'integer',
            'previous_basis_points' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
