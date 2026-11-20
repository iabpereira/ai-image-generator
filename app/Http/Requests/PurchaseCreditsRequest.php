<?php

namespace App\Http\Requests;

use App\Http\Controllers\CreditController;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseCreditsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'pack' => ['required', 'integer', Rule::in(CreditController::PACKS)],
        ];
    }
}
