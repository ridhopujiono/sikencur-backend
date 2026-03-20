<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransactionSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'top_categories' => ['nullable', 'integer', 'min:1', 'max:10'],
            'scan_status' => ['nullable', 'string', 'in:all,pending,processing,completed,failed'],
        ];
    }
}
