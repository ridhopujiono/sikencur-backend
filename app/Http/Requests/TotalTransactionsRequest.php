<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TotalTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'category' => ['nullable', 'string'],
            'transaction_type' => ['nullable', 'string', 'in:income,expense'],
            'input_method' => ['nullable', 'string'],
        ];
    }
}
