<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpsertUserBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'limit' => ['required', 'numeric', 'min:0'],
            'target_remaining' => ['nullable', 'numeric', 'min:0', 'lte:limit'],
        ];
    }
}
