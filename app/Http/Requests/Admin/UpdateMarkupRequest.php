<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMarkupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('managePricing') ?? false;
    }

    /**
     * A percentage with up to two decimals, between 0 and 500.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'markup_percent' => ['required', 'regex:/^\d{1,3}(\.\d{1,2})?$/', 'numeric', 'min:0', 'max:500'],
        ];
    }

    /**
     * The percentage as integer basis points, parsed from the string (no floats).
     */
    public function basisPoints(): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $this->validated('markup_percent'), 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }
}
